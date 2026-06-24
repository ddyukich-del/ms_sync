<?php
namespace MoyskladSync;

/**
 * Сервис синхронизации категорий.
 *
 * В этом классе нет прямых SQL-запросов. Он управляет сценарием:
 * взять страницу категорий из МойСклад, передать их модели ocStore,
 * обновить прогресс задачи и решить, когда переходить к следующему шагу.
 * Такой слой проще тестировать и расширять: позже сюда можно добавить dry-run,
 * cron или CLI без переписывания SQL-моделей.
 */
class CategorySyncService {
    private MoyskladClient $client;
    private object $categoryModel;
    private object $taskModel;

    /**
     * Кэш категорий, которые уже были проверены в рамках текущего PHP-запроса.
     * Это снижает количество одинаковых API-запросов, когда несколько товаров
     * лежат в одной категории.
     */
    private array $ensuredCategoryIds = [];

    public function __construct(MoyskladClient $client, object $categoryModel, object $taskModel) {
        $this->client = $client;
        $this->categoryModel = $categoryModel;
        $this->taskModel = $taskModel;
    }


    /**
     * Гарантирует наличие категории, если карточка товара уже пришла с expand=productFolder.
     *
     * Это более надежный и легкий путь для слабого сервера: когда МойСклад уже
     * отдал объект категории вместе с товаром, мы не делаем лишний запрос
     * /entity/productfolder/{id}. Если в объекте есть только ID без имени,
     * падаем обратно на ensureCategoryChainById().
     */
    public function ensureCategoryChainFromFolder(array $folder, int $taskId, array $settings, int $depth = 0): void {
        $moyskladCategoryId = trim((string)($folder['id'] ?? ''));

        if ($moyskladCategoryId === '') {
            return;
        }

        if (trim((string)($folder['name'] ?? '')) === '') {
            $this->ensureCategoryChainById($moyskladCategoryId, $taskId, $settings, $depth);
            return;
        }

        if ($depth > 20) {
            throw new \RuntimeException('Слишком глубокая или циклическая вложенность категорий МойСклад: ' . $moyskladCategoryId);
        }

        if (!empty($this->ensuredCategoryIds[$moyskladCategoryId])) {
            return;
        }

        $this->ensuredCategoryIds[$moyskladCategoryId] = true;
        $parentId = trim((string)($folder['parent_id'] ?? ''));

        if ($parentId !== '' && $parentId !== $moyskladCategoryId) {
            $this->ensureCategoryChainById($parentId, $taskId, $settings, $depth + 1);
        }

        $this->categoryModel->upsertFromMoysklad($folder, $taskId, $settings);

        if (method_exists($this->categoryModel, 'rebuildOneByMoyskladId')) {
            $this->categoryModel->rebuildOneByMoyskladId($moyskladCategoryId, $settings);
        }
    }

    /**
     * Гарантирует наличие категории товара и всех ее родителей в ocStore.
     *
     * Новый основной импорт товаров идет от остатков выбранного склада. Поэтому
     * нам больше нельзя сначала импортировать все категории МойСклад: так на сайт
     * попадали бы категории для товаров других складов. Вместо этого для каждого
     * импортируемого товара мы точечно создаем только его категорию и цепочку
     * родителей. Если категорию ранее удалили вручную, модель пересоздаст ее и
     * перепривяжет старую запись moysklad_category_link к новому category_id.
     */
    public function ensureCategoryChainById(string $moyskladCategoryId, int $taskId, array $settings, int $depth = 0): void {
        $moyskladCategoryId = trim($moyskladCategoryId);

        if ($moyskladCategoryId === '') {
            return;
        }

        if ($depth > 20) {
            throw new \RuntimeException('Слишком глубокая или циклическая вложенность категорий МойСклад: ' . $moyskladCategoryId);
        }

        if (!empty($this->ensuredCategoryIds[$moyskladCategoryId])) {
            return;
        }

        // Помечаем заранее, чтобы случайный цикл parent -> child -> parent не
        // увел PHP в бесконечную рекурсию на слабом сервере.
        $this->ensuredCategoryIds[$moyskladCategoryId] = true;

        $folder = $this->client->getProductFolderById($moyskladCategoryId);
        $parentId = trim((string)($folder['parent_id'] ?? ''));

        if ($parentId !== '' && $parentId !== $moyskladCategoryId) {
            $this->ensureCategoryChainById($parentId, $taskId, $settings, $depth + 1);
        }

        $this->categoryModel->upsertFromMoysklad($folder, $taskId, $settings);

        // upsert создает/обновляет саму запись. После того как родитель уже
        // гарантированно создан, можно безопасно перестроить parent_id и path.
        if (method_exists($this->categoryModel, 'rebuildOneByMoyskladId')) {
            $this->categoryModel->rebuildOneByMoyskladId($moyskladCategoryId, $settings);
        }
    }


    /**
     * Создает цепочку категорий по строковому пути pathName.
     *
     * Это запасной сценарий для аккаунтов МойСклад, где карточка товара или отчет
     * остатков не возвращают productFolder/meta, но возвращают путь группы товара.
     * Мы генерируем стабильные виртуальные ID вида path:<sha1>, поэтому повторный
     * импорт не создаст дубли, а товар сможет получить категорию.
     *
     * Возвращает ID листовой категории, который затем записывается в товар как
     * category_moysklad_id.
     */
    public function ensureCategoryChainFromPath(string $pathName, int $taskId, array $settings): string {
        $pathName = trim($pathName);

        if ($pathName === '') {
            return '';
        }

        // Не используем preg_split: в PHP легко ошибиться с экранированием
        // слэша/обратного слэша внутри regex-delimiter, и тогда warning попадает
        // в AJAX-ответ вместо JSON. Для пути категорий достаточно безопасной
        // нормализации строки и обычного explode().
        $normalizedPath = str_replace(['\\', ' > ', ' >', '> ', '>'], '/', $pathName);
        $normalizedPath = trim($normalizedPath, " \t\n\r\0\x0B/");
        $rawParts = explode('/', $normalizedPath);
        $segments = [];

        foreach ($rawParts as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $segments[] = $part;
            }
        }

        if (!$segments) {
            return '';
        }

        $parentVirtualId = '';
        $currentPath = [];
        $leafVirtualId = '';

        foreach ($segments as $segment) {
            $currentPath[] = $segment;
            $canonicalPath = mb_strtolower(implode('/', $currentPath), 'UTF-8');
            $virtualId = 'path:' . sha1($canonicalPath);

            if (empty($this->ensuredCategoryIds[$virtualId])) {
                $folder = [
                    'id' => $virtualId,
                    'href' => '',
                    'name' => $segment,
                    'description' => '',
                    'external_code' => '',
                    'archived' => false,
                    'parent_id' => $parentVirtualId,
                    'parent_href' => '',
                    'path_name' => implode('/', $currentPath),
                    'updated' => ''
                ];

                $this->categoryModel->upsertFromMoysklad($folder, $taskId, $settings);

                if (method_exists($this->categoryModel, 'rebuildOneByMoyskladId')) {
                    $this->categoryModel->rebuildOneByMoyskladId($virtualId, $settings);
                }

                $this->ensuredCategoryIds[$virtualId] = true;
            }

            $parentVirtualId = $virtualId;
            $leafVirtualId = $virtualId;
        }

        return $leafVirtualId;
    }

    /**
     * Обрабатывает одну страницу групп товаров из МойСклад.
     *
     * Важно для слабого сервера: за один вызов мы берем только limit элементов,
     * не держим весь список категорий в памяти и сразу сохраняем прогресс в задачу.
     */
    public function syncPage(array $task, array $settings): array {
        $taskId = (int)$task['task_id'];
        $limit = max(1, (int)$task['limit_value']);
        $offset = max(0, (int)$task['offset_value']);

        $page = $this->client->getProductFoldersPage($limit, $offset);
        $rows = $page['rows'];
        $total = (int)$page['total'];

        $this->taskModel->addLog($taskId, 'info', 'category', null, 'Получена страница групп товаров МойСклад productfolder: offset=' . $offset . ', limit=' . $limit . ', rows=' . count($rows) . ', total=' . $total . '. Сырой ответ API записан в storage/logs/moysklad_sync_api_debug.log.');

        $stats = [
            'processed_items' => 0,
            'created_items' => 0,
            'updated_items' => 0,
            'skipped_items' => 0,
            'error_items' => 0,
        ];

        foreach ($rows as $folder) {
            try {
                $result = $this->categoryModel->upsertFromMoysklad($folder, $taskId, $settings);
                $stats['processed_items']++;

                if ($result === 'created') {
                    $stats['created_items']++;
                } elseif ($result === 'updated') {
                    $stats['updated_items']++;
                } else {
                    $stats['skipped_items']++;
                }
            } catch (\Throwable $e) {
                // Ошибка по одной категории не должна ронять весь импорт.
                // Фиксируем проблему и идем дальше по текущему пакету.
                $stats['error_items']++;
                $this->taskModel->addError($taskId, 'category', (string)($folder['id'] ?? ''), 'CATEGORY_SYNC_ERROR', $e->getMessage(), $folder);
            }
        }

        $newOffset = $offset + count($rows);
        $hasMore = count($rows) === $limit && ($total === 0 || $newOffset < $total);

        $this->taskModel->updateTaskProgress($taskId, $newOffset, $stats, $total);

        if (!$hasMore) {
            if ($total === 0 && count($rows) === 0) {
                $this->taskModel->addLog($taskId, 'warning', 'category', null, 'МойСклад вернул 0 групп товаров productfolder. Проверьте storage/logs/moysklad_sync_api_debug.log: возможно, в этом аккаунте группы приходят в другом endpoint или токен не имеет доступа к справочнику.');
            }
            $this->taskModel->addLog($taskId, 'info', 'category', null, 'Синхронизация групп товаров МойСклад завершена. Переходим к построению дерева категорий.');
            $this->taskModel->moveToStep($taskId, 'rebuild_category_tree');
        }

        return $this->taskModel->getTask($taskId) ?: [];
    }

    /**
     * Перестраивает parent_id и category_path небольшими пакетами.
     *
     * Мы отделяем этот шаг от создания категорий, потому что МойСклад может отдать
     * дочернюю категорию раньше родительской. Сначала создаем все категории,
     * затем связываем их в дерево по moysklad_parent_id.
     */
    public function rebuildTreePage(array $task, array $settings): array {
        $taskId = (int)$task['task_id'];
        $limit = max(1, (int)$task['limit_value']);
        $lastLinkId = max(0, (int)$task['offset_value']);

        $result = $this->categoryModel->rebuildTreeForTask($taskId, $lastLinkId, $limit, $settings);

        $this->taskModel->updateTaskProgress($taskId, (int)$result['last_cursor'], [
            'processed_items' => (int)$result['processed'],
            'updated_items' => (int)$result['updated'],
            'skipped_items' => (int)$result['skipped'],
            'error_items' => (int)$result['errors'],
        ]);

        if (!$result['has_more']) {
            // После восстановления дерева категорий переходим к товарам выбранного склада.
            // Missing-категории обрабатываем уже после товаров: так мы не удалим/не отключим
            // категорию, которая была создана fallback-логикой по pathName товара.
            $this->taskModel->addLog($taskId, 'info', 'category', null, 'Дерево категорий перестроено. Переходим к импорту товаров выбранного склада.');
            $this->taskModel->moveToStep($taskId, 'sync_products', (int)($settings['module_moysklad_sync_product_batch_size'] ?? $limit));
        }

        return $this->taskModel->getTask($taskId) ?: [];
    }

    /**
     * Обрабатывает категории сайта, которые были связаны с МойСклад ранее,
     * но в текущем запуске импорта не встретились.
     */
    public function processMissingPage(array $task, array $settings): array {
        $taskId = (int)$task['task_id'];
        $limit = max(1, (int)$task['limit_value']);
        $lastLinkId = max(0, (int)$task['offset_value']);
        $action = (string)($settings['module_moysklad_sync_missing_category_action'] ?? 'disable');

        $result = $this->categoryModel->processMissingCategories($taskId, $lastLinkId, $limit, $action);

        $this->taskModel->updateTaskProgress($taskId, (int)$result['last_cursor'], [
            'processed_items' => (int)$result['processed'],
            'disabled_items' => (int)$result['disabled'],
            'deleted_items' => (int)$result['deleted'],
            'skipped_items' => (int)$result['skipped'],
            'error_items' => (int)$result['errors'],
        ]);

        if (!$result['has_more']) {
            $this->taskModel->addLog($taskId, 'info', 'category', null, 'Обработка категорий вне выбранного склада завершена.');
            $this->taskModel->moveToStep($taskId, 'finish');
        }

        return $this->taskModel->getTask($taskId) ?: [];
    }
}
