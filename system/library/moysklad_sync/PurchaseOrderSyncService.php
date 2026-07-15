<?php
namespace MoyskladSync;

/**
 * @author d_dyuk
 */

/**
 * Сервис учета ожидаемых поступлений по заказам поставщикам.
 *
 * Фактический остаток и заказ поставщику — разные вещи. Фактический остаток
 * берется из отчета выбранного склада, а заказ поставщику используется только
 * для того, чтобы оставить товар включенным как «ожидаемый», если физически
 * на складе его пока нет.
 */
class PurchaseOrderSyncService {
    private MoyskladClient $client;
    private object $productModel;
    private object $taskModel;
    private ?CategorySyncService $categoryService;

    public function __construct(MoyskladClient $client, object $productModel, object $taskModel, ?CategorySyncService $categoryService = null) {
        $this->client = $client;
        $this->productModel = $productModel;
        $this->taskModel = $taskModel;
        $this->categoryService = $categoryService;
    }

    /**
     * Обрабатывает одну страницу заказов поставщикам.
     *
     * В этом шаге много диагностических логов. Они намеренно оставлены на уровне
     * info/warning, потому что у разных аккаунтов МойСклад статусы и позиции
     * заказа могут приходить немного по-разному. По логам сразу видно: статусы
     * выбраны, документы найдены, позиции прочитаны, товары импортированы или
     * пропущены по конкретной причине.
     */
    public function syncPage(array $task, array $settings): array {
        $taskId = (int)$task['task_id'];
        $limit = max(1, min(100, (int)$task['limit_value']));
        $offset = max(0, (int)$task['offset_value']);

        $stats = [
            'processed_items' => 0,
            'created_items' => 0,
            'updated_items' => 0,
            'skipped_items' => 0,
            'deleted_items' => 0,
            'disabled_items' => 0,
            'error_items' => 0,
        ];

        if (empty($settings['module_moysklad_sync_purchase_orders_enabled'])) {
            $this->taskModel->addLog($taskId, 'info', 'purchaseorder', null, 'Учет заказов поставщикам выключен. Переходим к обработке отсутствующих товаров.');
            $this->taskModel->moveToStep($taskId, 'process_missing_products', (int)($settings['module_moysklad_sync_product_batch_size'] ?? 20));
            return $this->taskModel->getTask($taskId) ?: [];
        }

        $selectedStates = $this->normalizeSelectedStates($settings['module_moysklad_sync_purchase_order_state_ids'] ?? []);

        if (!$selectedStates['values']) {
            $this->taskModel->addLog($taskId, 'warning', 'purchaseorder', null, 'Учет заказов поставщикам включен, но статусы заказов не выбраны. Ожидаемые товары не импортируются.');
            $this->taskModel->moveToStep($taskId, 'process_missing_products', (int)($settings['module_moysklad_sync_product_batch_size'] ?? 20));
            return $this->taskModel->getTask($taskId) ?: [];
        }

        $page = $this->client->getPurchaseOrdersPage($limit, $offset);
        $orders = $page['rows'];
        $total = (int)$page['total'];
        $incomingQuantityMode = (string)($settings['module_moysklad_sync_incoming_quantity_mode'] ?? 'zero');
        $incomingStockStatusId = (int)($settings['module_moysklad_sync_incoming_stock_status_id'] ?? 0);
        $matchMode = (string)($settings['module_moysklad_sync_incoming_product_match_mode'] ?? 'separate');

        // В режиме объединения по наименованию ожидаемое поступление всегда
        // хранится отдельно от фактического остатка. Это принципиально: менеджер
        // должен видеть, сколько можно отгрузить сейчас, а сколько еще едет.
        // Поэтому настройка "ставить quantity из заказа поставщику" применяется
        // только к режиму отдельных ожидаемых товаров. При объединении quantity
        // сайта остается фактическим остатком выбранных складов.
        if ($matchMode === 'merge_by_name') {
            $incomingQuantityMode = 'zero';
        }

        $this->taskModel->addLog(
            $taskId,
            'info',
            'purchaseorder',
            null,
            'Проверяем заказы поставщикам: offset=' . $offset . ', limit=' . $limit . ', rows=' . count($orders) . ', total=' . $total . ', выбранные статусы=' . implode(', ', $selectedStates['values']) . ', режим товаров=' . $matchMode . ', режим количества=' . $incomingQuantityMode . '.'
        );

        $matchedOrders = 0;
        $positionsLoaded = 0;

        foreach ($orders as $order) {
            $orderId = (string)($order['id'] ?? '');
            $orderName = (string)($order['name'] ?? '');

            if ($orderId === '') {
                $stats['skipped_items']++;
                continue;
            }

            if (!$this->orderMatchesSelectedStates($order, $selectedStates)) {
                $stats['skipped_items']++;

                // Логируем первые страницы достаточно подробно. Если статусы не
                // совпали из-за ID/name/href, это сразу видно во вкладке «Логи».
                $this->taskModel->addLog($taskId, 'info', 'purchaseorder', $orderId, 'Заказ поставщику пропущен по статусу: №' . $orderName . ', state_id=' . (string)($order['state_id'] ?? '') . ', state_name=' . (string)($order['state_name'] ?? '') . '.');
                continue;
            }

            $matchedOrders++;

            try {
                $positions = $this->client->getPurchaseOrderPositions($orderId);
                $positionsLoaded += count($positions);
                $this->taskModel->addLog($taskId, 'info', 'purchaseorder', $orderId, 'Заказ поставщику подходит по статусу: №' . $orderName . '. Позиции: ' . count($positions) . '.');
            } catch (\Throwable $e) {
                $stats['error_items']++;
                $this->taskModel->addError($taskId, 'purchaseorder', $orderId, 'PURCHASE_ORDER_POSITIONS_ERROR', $e->getMessage(), $order);
                continue;
            }

            foreach ($positions as $position) {
                $moyskladProductId = (string)($position['product_id'] ?? '');
                $type = (string)($position['type'] ?? '');
                $incomingQuantity = (float)($position['quantity'] ?? 0);

                try {
                    if ($moyskladProductId === '') {
                        $stats['processed_items']++;
                        $stats['skipped_items']++;
                        $this->taskModel->addLog($taskId, 'warning', 'purchaseorder_product', $orderId, 'Позиция заказа поставщику пропущена: не удалось определить ID товара. Тип assortment=' . $type . ', наименование=' . (string)($position['name'] ?? '') . '.');
                        continue;
                    }

                    // Пока модификации как отдельные опции не синхронизируем, но
                    // variant, если удалось определить родительский product_id,
                    // импортируем как родительский товар. Остальные типы пропускаем.
                    if ($type !== '' && !in_array($type, ['product', 'variant'], true)) {
                        $stats['processed_items']++;
                        $stats['skipped_items']++;
                        $this->taskModel->addLog($taskId, 'info', 'purchaseorder_product', $moyskladProductId, 'Позиция заказа поставщику пропущена: тип assortment=' . $type . ', поддерживаются product/variant.');
                        continue;
                    }

                    if ($incomingQuantity <= 0) {
                        $stats['processed_items']++;
                        $stats['skipped_items']++;
                        continue;
                    }

                    $incomingMeta = [
                        'id' => $moyskladProductId,
                        'name' => (string)($position['name'] ?? ''),
                        'is_incoming' => true,
                        'incoming_quantity' => $incomingQuantity,
                        'purchase_order_id' => $orderId,
                        'purchase_order_name' => $orderName,
                        'purchase_order_state_id' => (string)($order['state_id'] ?? ''),
                        'purchase_order_state_name' => (string)($order['state_name'] ?? ''),
                    ];

                    // Если товар уже пришел из фактических остатков под тем же ID,
                    // не создаем дубль и не меняем реальный quantity. Просто
                    // добавляем к этой карточке ожидаемое количество из заказа.
                    if (method_exists($this->productModel, 'wasSeenInTask') && $this->productModel->wasSeenInTask($moyskladProductId, $taskId)) {
                        $result = method_exists($this->productModel, 'attachIncomingToProductByMoyskladId')
                            ? $this->productModel->attachIncomingToProductByMoyskladId($moyskladProductId, $incomingMeta, $taskId)
                            : 'skipped';

                        $stats['processed_items']++;
                        $this->accumulateResult($stats, $result);
                        continue;
                    }

                    $product = $this->client->getProductById($moyskladProductId);
                    $product['quantity'] = $incomingQuantityMode === 'expected' ? $incomingQuantity : 0;
                    $product['quantity_known'] = true;
                    $product['is_incoming'] = true;
                    $product['incoming_quantity'] = $incomingQuantity;
                    $product['purchase_order_id'] = $orderId;
                    $product['purchase_order_name'] = $orderName;
                    $product['purchase_order_state_id'] = (string)($order['state_id'] ?? '');
                    $product['purchase_order_state_name'] = (string)($order['state_name'] ?? '');

                    // Товар ожидается к поступлению, поэтому он должен быть включен,
                    // даже если физический остаток 0. stock_status_id показывает
                    // покупателю «Предзаказ/Ожидается», если такой статус выбран.
                    $product['archived'] = false;

                    if ($incomingStockStatusId > 0) {
                        $product['stock_status_id'] = $incomingStockStatusId;
                    }

                    // Настраиваемый режим: если заказ поставщику содержит позицию с
                    // тем же наименованием, что и товар в фактических остатках,
                    // можно не создавать отдельную карточку, а добавить ожидаемое
                    // количество к уже существующему товару. Сравнение идет по
                    // нормализованному названию и только с товарами, встреченными
                    // в текущей задаче как складские.
                    if ($matchMode === 'merge_by_name' && method_exists($this->productModel, 'findMergeTargetProductIdByName') && method_exists($this->productModel, 'attachIncomingToProductId')) {
                        $targetProductId = $this->productModel->findMergeTargetProductIdByName((string)($product['name'] ?? ''), $taskId);

                        if ($targetProductId > 0) {
                            $product['merge_target_name'] = (string)($product['name'] ?? '');
                            $result = $this->productModel->attachIncomingToProductId($targetProductId, $product, $taskId);

                            $stats['processed_items']++;
                            $this->accumulateResult($stats, $result);
                            $this->taskModel->addLog($taskId, 'info', 'purchaseorder_product', $moyskladProductId, 'Позиция заказа поставщику объединена с товаром в наличии по наименованию: ' . (string)($product['name'] ?? '') . '.');
                            continue;
                        }
                    }

                    $this->ensureCategoryForProduct($product, $taskId, $settings, $moyskladProductId);

                    $result = $this->productModel->upsertFromMoysklad($product, $taskId, $settings);
                    $stats['processed_items']++;
                    $this->accumulateResult($stats, $result);
                } catch (\Throwable $e) {
                    $stats['error_items']++;
                    $this->taskModel->addError($taskId, 'purchaseorder_product', $moyskladProductId, 'PURCHASE_ORDER_PRODUCT_ERROR', $e->getMessage(), [
                        'order' => $order,
                        'position' => $position,
                    ]);
                }
            }
        }

        $newOffset = $offset + count($orders);
        $hasMore = count($orders) === $limit && ($total === 0 || $newOffset < $total);

        $this->taskModel->updateTaskProgress($taskId, $newOffset, $stats, $total);

        if (!$hasMore) {
            $this->taskModel->addLog($taskId, 'info', 'purchaseorder', null, 'Учет заказов поставщикам завершен. Подходящих заказов: ' . $matchedOrders . ', позиций прочитано: ' . $positionsLoaded . '. Переходим к обработке товаров, которых нет на выбранном складе и в выбранных заказах поставщикам.');
            $this->taskModel->moveToStep($taskId, 'process_missing_products', (int)($settings['module_moysklad_sync_product_batch_size'] ?? $limit));
        }

        return $this->taskModel->getTask($taskId) ?: [];
    }

    private function ensureCategoryForProduct(array &$product, int $taskId, array $settings, string $moyskladProductId): void {
        if (!$this->categoryService) {
            return;
        }

        try {
            if (!empty($product['category_id'])) {
                if (!empty($product['category']) && is_array($product['category']) && !empty($product['category']['name']) && method_exists($this->categoryService, 'ensureCategoryChainFromFolder')) {
                    $this->categoryService->ensureCategoryChainFromFolder($product['category'], $taskId, $settings);
                } else {
                    $this->categoryService->ensureCategoryChainById((string)$product['category_id'], $taskId, $settings);
                }

                return;
            }

            if (!empty($product['path_name']) && method_exists($this->categoryService, 'ensureCategoryChainFromPath')) {
                $virtualCategoryId = $this->categoryService->ensureCategoryChainFromPath((string)$product['path_name'], $taskId, $settings);

                if ($virtualCategoryId !== '') {
                    $product['category_id'] = $virtualCategoryId;
                }
            }
        } catch (\Throwable $e) {
            $this->taskModel->addError($taskId, 'category', (string)($product['category_id'] ?? ''), 'PURCHASE_ORDER_CATEGORY_ERROR', $e->getMessage(), [
                'product_id' => $moyskladProductId,
                'path_name' => (string)($product['path_name'] ?? ''),
            ]);
        }
    }

    private function normalizeSelectedStates(mixed $value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        if (!is_array($value)) {
            return ['values' => [], 'lookup' => []];
        }

        $values = [];
        $lookup = [];

        foreach ($value as $item) {
            $string = trim((string)$item);

            if ($string === '') {
                continue;
            }

            $values[$string] = $string;
            $lookup[$this->normalizeComparable($string)] = true;
        }

        return ['values' => array_values($values), 'lookup' => $lookup];
    }

    private function orderMatchesSelectedStates(array $order, array $selectedStates): bool {
        $candidates = [
            (string)($order['state_id'] ?? ''),
            (string)($order['state_name'] ?? ''),
            (string)($order['state_href'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $key = $this->normalizeComparable($candidate);

            if ($key !== '' && !empty($selectedStates['lookup'][$key])) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparable(string $value): string {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return mb_strtolower($value, 'UTF-8');
    }

    private function accumulateResult(array &$stats, string $result): void {
        if ($result === 'created') {
            $stats['created_items']++;
        } elseif ($result === 'updated') {
            $stats['updated_items']++;
        } elseif ($result === 'deleted') {
            $stats['deleted_items']++;
        } elseif ($result === 'disabled') {
            $stats['disabled_items']++;
        } else {
            $stats['skipped_items']++;
        }
    }
}
