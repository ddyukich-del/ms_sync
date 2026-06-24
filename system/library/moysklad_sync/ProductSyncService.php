<?php
namespace MoyskladSync;

/**
 * Сервис синхронизации товаров.
 *
 * Важное правило проекта: в сайт должны попадать только товары выбранного
 * склада. Поэтому полный импорт товаров идет не от /entity/product, где лежит
 * весь каталог МойСклад, а от отчета остатков выбранного склада. По строкам
 * отчета мы берем ID товара, остаток, а затем точечно загружаем карточку товара.
 */
class ProductSyncService {
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
     * Обрабатывает одну страницу товаров выбранного склада.
     *
     * Один вызов = один небольшой пакет отчета остатков. Для каждого товара с
     * положительным остатком отдельно запрашиваем карточку товара. Да, это больше
     * API-запросов, зато мы не создаем на сайте товары с других складов.
     */
    public function syncPage(array $task, array $settings): array {
        $taskId = (int)$task['task_id'];
        $limit = max(1, (int)$task['limit_value']);
        $offset = max(0, (int)$task['offset_value']);
        $warehouseId = trim((string)($settings['module_moysklad_sync_warehouse_id'] ?? ''));

        if ($warehouseId === '') {
            throw new \RuntimeException('Не выбран склад МойСклад для импорта товаров.');
        }

        $page = $this->client->getStockPage($warehouseId, $limit, $offset);
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
            $moyskladId = (string)($stockRow['id'] ?? '');
            $type = (string)($stockRow['type'] ?? '');
            $stock = (float)($stockRow['stock'] ?? 0);

            try {
                // На первой версии мы не импортируем модификации/услуги/комплекты.
                // Если отчет вернул не product, пропускаем строку без ошибки.
                if ($moyskladId === '' || ($type !== '' && $type !== 'product')) {
                    $stats['processed_items']++;
                    $stats['skipped_items']++;
                    continue;
                }

                // Товары с нулевым или отрицательным остатком не создаем. Если такой
                // товар уже был связан ранее, применяем политику нулевого остатка.
                if ($stock <= 0) {
                    $result = $this->productModel->updateStockFromMoysklad($stockRow, $taskId, $settings);
                    $stats['processed_items']++;
                    $this->accumulateResult($stats, $result);
                    continue;
                }

                // Карточку товара загружаем только после того, как убедились, что на
                // выбранном складе есть положительный остаток. Это главный фильтр,
                // который отсекает товары с других складов.
                $product = $this->client->getProductById($moyskladId);
                $product['quantity'] = $stock;
                $product['quantity_known'] = true;

                // Если в карточке товара почему-то не пришла категория, но отчет
                // остатков содержит productFolder, используем этот запасной вариант.
                if (empty($product['category_id']) && !empty($stockRow['category_id'])) {
                    $product['category_id'] = (string)$stockRow['category_id'];
                }

                // pathName — дополнительный запасной источник категорий. В некоторых
                // аккаунтах МойСклад productFolder в карточке не приходит, но путь
                // группы товара есть в карточке или строке складского отчета.
                if (empty($product['path_name']) && !empty($stockRow['path_name'])) {
                    $product['path_name'] = (string)$stockRow['path_name'];
                }

                // Категории создаются только по товарам выбранного склада с
                // положительным остатком. Приоритет: реальный productFolder ID,
                // затем fallback по pathName. Если fallback сработал, записываем
                // виртуальный ID категории обратно в товар, чтобы product_to_category
                // смог привязать товар к созданной категории.
                if ($this->categoryService) {
                    try {
                        $categoryReady = false;

                        if (!empty($product['category_id'])) {
                            if (!empty($product['category']) && is_array($product['category']) && !empty($product['category']['name']) && method_exists($this->categoryService, 'ensureCategoryChainFromFolder')) {
                                $this->categoryService->ensureCategoryChainFromFolder($product['category'], $taskId, $settings);
                            } else {
                                $this->categoryService->ensureCategoryChainById((string)$product['category_id'], $taskId, $settings);
                            }

                            $categoryReady = true;
                        }

                        if (!$categoryReady && !empty($product['path_name']) && method_exists($this->categoryService, 'ensureCategoryChainFromPath')) {
                            $virtualCategoryId = $this->categoryService->ensureCategoryChainFromPath((string)$product['path_name'], $taskId, $settings);

                            if ($virtualCategoryId !== '') {
                                $product['category_id'] = $virtualCategoryId;
                                $categoryReady = true;
                            }
                        }

                        if (!$categoryReady) {
                            $this->taskModel->addLog($taskId, 'warning', 'category', $moyskladId, 'У товара нет productFolder/pathName в МойСклад, категория не создана.');
                        }
                    } catch (\Throwable $categoryError) {
                        // Если создание категории по ID не удалось, пробуем последний
                        // шанс — создать цепочку по pathName. Это защищает импорт от
                        // различий API-ответов между аккаунтами МойСклад.
                        if (!empty($product['path_name']) && method_exists($this->categoryService, 'ensureCategoryChainFromPath')) {
                            try {
                                $virtualCategoryId = $this->categoryService->ensureCategoryChainFromPath((string)$product['path_name'], $taskId, $settings);

                                if ($virtualCategoryId !== '') {
                                    $product['category_id'] = $virtualCategoryId;
                                }
                            } catch (\Throwable $fallbackError) {
                                $this->taskModel->addError($taskId, 'category', (string)($product['category_id'] ?? ''), 'CATEGORY_ENSURE_ERROR', $categoryError->getMessage() . ' | pathName fallback: ' . $fallbackError->getMessage(), $product);
                            }
                        } else {
                            // Ошибка категории не должна полностью останавливать импорт
                            // товара. Товар попадет в магазин без категории, а точная
                            // причина будет видна во вкладке логов/ошибок.
                            $this->taskModel->addError($taskId, 'category', (string)($product['category_id'] ?? ''), 'CATEGORY_ENSURE_ERROR', $categoryError->getMessage(), $product);
                        }
                    }
                }

                $result = $this->productModel->upsertFromMoysklad($product, $taskId, $settings);
                $stats['processed_items']++;
                $this->accumulateResult($stats, $result);
            } catch (\Throwable $e) {
                // Ошибка одного товара не должна останавливать весь импорт.
                $stats['error_items']++;
                $this->taskModel->addError($taskId, 'product', $moyskladId, 'PRODUCT_SYNC_ERROR', $e->getMessage(), $stockRow);
            }
        }

        $newOffset = $offset + count($rows);
        $hasMore = count($rows) === $limit && ($total === 0 || $newOffset < $total);

        $this->taskModel->updateTaskProgress($taskId, $newOffset, $stats, $total);

        if (!$hasMore) {
            $this->taskModel->addLog($taskId, 'info', 'product', null, 'Импорт товаров выбранного склада завершен. Переходим к обработке товаров, которых нет на выбранном складе.');
            $this->taskModel->moveToStep($taskId, 'process_missing_products', (int)($settings['module_moysklad_sync_product_batch_size'] ?? $limit));
        }

        return $this->taskModel->getTask($taskId) ?: [];
    }

    /**
     * Обрабатывает товары сайта, связанные с МойСклад, но не встреченные в текущем
     * складском отчете. Для выбранного склада такие товары считаются отсутствующими.
     */
    public function processMissingPage(array $task, array $settings): array {
        $taskId = (int)$task['task_id'];
        $limit = max(1, (int)$task['limit_value']);
        $lastLinkId = max(0, (int)$task['offset_value']);
        // В полном импорте мы работаем только с товарами выбранного склада с положительным остатком.
        // Всё, что не встретилось в nonEmpty-отчете, для сайта считается отсутствующим
        // на выбранном складе. Поэтому применяем именно политику нулевого остатка.
        $action = (string)($settings['module_moysklad_sync_zero_stock_action'] ?? ($settings['module_moysklad_sync_missing_product_action'] ?? 'disable'));

        $result = $this->productModel->processMissingProducts($taskId, $lastLinkId, $limit, $action);

        $this->taskModel->updateTaskProgress($taskId, (int)$result['last_cursor'], [
            'processed_items' => (int)$result['processed'],
            'disabled_items' => (int)$result['disabled'],
            'deleted_items' => (int)$result['deleted'],
            'skipped_items' => (int)$result['skipped'],
            'error_items' => (int)$result['errors'],
        ]);

        if (!$result['has_more']) {
            $this->taskModel->addLog($taskId, 'info', 'product', null, 'Обработка товаров вне выбранного склада завершена. Категории не отключаем и не удаляем автоматически.');
            $this->taskModel->moveToStep($taskId, 'finish');
        }

        return $this->taskModel->getTask($taskId) ?: [];
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
