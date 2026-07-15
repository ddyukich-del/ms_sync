<?php
namespace MoyskladSync;

/**
 * @author d_dyuk
 */

/**
 * Сервис точного обновления остатков по выбранному складу.
 *
 * В отличие от ProductSyncService, этот сервис не создает карточки товара и не
 * меняет контент. Он получает отчет остатков МойСклад и обновляет только
 * quantity/status у уже связанных товаров ocStore. Такой режим можно запускать
 * чаще и безопаснее, чем полный импорт каталога.
 */
class StockSyncService {
    private MoyskladClient $client;
    private object $productModel;
    private object $taskModel;

    public function __construct(MoyskladClient $client, object $productModel, object $taskModel) {
        $this->client = $client;
        $this->productModel = $productModel;
        $this->taskModel = $taskModel;
    }

    private function normalizeWarehouseIds(array $settings): array {
        $ids = [];
        $raw = $settings['module_moysklad_sync_warehouse_ids'] ?? [];

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [$raw];
        }

        if (is_array($raw)) {
            foreach ($raw as $id) {
                $id = trim((string)$id);
                if ($id !== '') {
                    $ids[$id] = $id;
                }
            }
        }

        if (!$ids && !empty($settings['module_moysklad_sync_warehouse_id'])) {
            $id = trim((string)$settings['module_moysklad_sync_warehouse_id']);
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Обрабатывает одну страницу отчета остатков.
     *
     * Один вызов = один батч. Мы специально не делаем здесь while по всем
     * страницам, чтобы слабый сервер не упирался в timeout и память. Следующий
     * батч запустит браузер отдельным AJAX-запросом.
     */
    public function syncPage(array $task, array $settings): array {
        $taskId = (int)$task['task_id'];
        $limit = max(1, (int)$task['limit_value']);
        $offset = max(0, (int)$task['offset_value']);
        $warehouseIds = $this->normalizeWarehouseIds($settings);

        if (!$warehouseIds) {
            throw new \RuntimeException('Не выбраны склады МойСклад для обновления остатков.');
        }

        $page = $this->client->getStockPageForWarehouses($warehouseIds, $limit, $offset, 'all');
        $rows = $page['rows'];
        $total = (int)$page['total'];

        $stats = [
            'processed_items' => 0,
            'created_items' => 0,
            'updated_items' => 0,
            'skipped_items' => 0,
            'deleted_items' => 0,
            'disabled_items' => 0,
            'error_items' => 0,
        ];

        foreach ($rows as $stockRow) {
            try {
                // Если товар уже был обновлен в этой задаче по другому складу,
                // прибавляем остаток текущего склада, а не перезаписываем quantity.
                if (method_exists($this->productModel, 'wasSeenInTask') && $this->productModel->wasSeenInTask((string)($stockRow['id'] ?? ''), $taskId)) {
                    $result = method_exists($this->productModel, 'addStockQuantityFromMoysklad')
                        ? $this->productModel->addStockQuantityFromMoysklad($stockRow, $taskId, $settings)
                        : 'skipped';
                } else {
                    // Обновляем только связанные товары. Неизвестные товары пропускаем:
                    // их должен создать полный импорт, иначе получим неполные карточки.
                    $result = $this->productModel->updateStockFromMoysklad($stockRow, $taskId, $settings);
                }
                $stats['processed_items']++;

                if ($result === 'updated') {
                    $stats['updated_items']++;
                } elseif ($result === 'deleted') {
                    $stats['deleted_items']++;
                } elseif ($result === 'disabled') {
                    $stats['disabled_items']++;
                } else {
                    $stats['skipped_items']++;
                }
            } catch (\Throwable $e) {
                // Ошибка одной строки остатков не должна валить всю задачу. Это
                // особенно важно при больших каталогах: один битый товар не должен
                // блокировать обновление остальных.
                $stats['error_items']++;
                $this->taskModel->addError($taskId, 'stock', (string)($stockRow['id'] ?? ''), 'STOCK_SYNC_ERROR', $e->getMessage(), $stockRow);
            }
        }

        $newOffset = $offset + count($rows);
        $hasMore = count($rows) === $limit && ($total === 0 || $newOffset < $total);

        $this->taskModel->updateTaskProgress($taskId, $newOffset, $stats, $total);

        if (!$hasMore) {
            $this->taskModel->addLog($taskId, 'info', 'stock', null, 'Обновление остатков по выбранному складу завершено.');
            $this->taskModel->moveToStep($taskId, 'finish');
        }

        return $this->taskModel->getTask($taskId) ?: [];
    }
}
