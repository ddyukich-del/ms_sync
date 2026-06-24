<?php
namespace MoyskladSync;

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
        $warehouseId = trim((string)($settings['module_moysklad_sync_warehouse_id'] ?? ''));

        if ($warehouseId === '') {
            throw new \RuntimeException('Не выбран склад МойСклад для обновления остатков.');
        }

        $page = $this->client->getStockPage($warehouseId, $limit, $offset, 'all');
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
                // Обновляем только связанные товары. Неизвестные товары пропускаем:
                // их должен создать полный импорт, иначе получим неполные карточки.
                $result = $this->productModel->updateStockFromMoysklad($stockRow, $taskId, $settings);
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
