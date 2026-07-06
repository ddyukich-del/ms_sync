<?php
namespace MoyskladSync;

/**
 * TaskRunner отвечает за порядок выполнения задачи.
 *
 * Он не пишет SQL сам и не знает деталей таблиц ocStore. Его задача — выбрать
 * правильный сервис для текущего шага и переключить задачу дальше. Такой подход
 * позволяет добавлять новые шаги без разрастания контроллера админки.
 */
class TaskRunner {
    /**
     * Время блокировки одного шага в секундах.
     *
     * Мы не держим задачу заблокированной надолго: слабый сервер может оборвать
     * PHP-процесс, поэтому следующий AJAX-запрос должен иметь шанс продолжить
     * задачу после истечения lock TTL.
     */
    private int $lockTtlSeconds;

    public function __construct(int $lockTtlSeconds = 120) {
        $this->lockTtlSeconds = max(30, $lockTtlSeconds);
    }

    /**
     * Выполняет один короткий шаг задачи.
     *
     * dependencies — это набор уже подготовленных объектов: модели, API-клиент,
     * сервисы. Так TaskRunner остается независимым от OpenCart Registry и его
     * можно будет переиспользовать для cron/CLI.
     */
    public function runOneStep(array $task, object $taskModel, array $settings, array $dependencies = []): array {
        $taskId = (int)$task['task_id'];
        $type = (string)$task['task_type'];
        $step = (string)$task['current_step'];

        $flow = $this->getFlow($type, $settings);

        if (!$flow) {
            throw new \RuntimeException('Неизвестный тип задачи: ' . $type);
        }

        if (!in_array($step, $flow, true)) {
            throw new \RuntimeException('Некорректный шаг задачи: ' . $step);
        }

        switch ($step) {
            case 'init':
                $taskModel->addLog($taskId, 'info', 'task', (string)$taskId, 'Задача инициализирована.');
                if ($type === 'import' && empty($settings['module_moysklad_sync_import_categories_enabled'])) {
                    $taskModel->addLog($taskId, 'info', 'category', null, 'Синхронизация категорий отключена для этого запуска импорта.');
                }
                $taskModel->moveToStep($taskId, $this->getNextStep($flow, $step));
                break;

            case 'sync_categories':
                $categoryService = $this->requireDependency($dependencies, 'category_service');
                return $categoryService->syncPage($task, $settings);

            case 'rebuild_category_tree':
                $categoryService = $this->requireDependency($dependencies, 'category_service');
                return $categoryService->rebuildTreePage($task, $settings);

            case 'process_missing_categories':
                // В текущей простой логике категории — это справочник групп товаров МойСклад.
                // Мы их только создаем/обновляем по /entity/productfolder и больше не
                // отключаем в конце импорта. Этот шаг оставлен только для совместимости
                // со старыми задачами, которые могли сохраниться в БД на предыдущих версиях.
                $taskModel->addLog($taskId, 'info', 'category', null, 'Шаг process_missing_categories пропущен: категории не удаляются и не отключаются автоматически.');
                $taskModel->moveToStep($taskId, 'finish');
                break;

            case 'sync_products':
                $productService = $this->requireDependency($dependencies, 'product_service');
                return $productService->syncPage($task, $settings);

            case 'sync_incoming_products':
                $purchaseOrderService = $this->requireDependency($dependencies, 'purchase_order_service');
                return $purchaseOrderService->syncPage($task, $settings);

            case 'process_missing_products':
                $productService = $this->requireDependency($dependencies, 'product_service');
                return $productService->processMissingPage($task, $settings);

            case 'sync_stock':
                $stockService = $this->requireDependency($dependencies, 'stock_service');
                return $stockService->syncPage($task, $settings);

            case 'sync_images':
                $imageService = $this->requireDependency($dependencies, 'image_service');
                return $imageService->syncPage($task, $settings);

            case 'finish':
                $taskModel->finishTask($taskId);
                $taskModel->addLog($taskId, 'info', 'task', (string)$taskId, 'Задача завершена.');
                break;
        }

        return $taskModel->getTask($taskId) ?: [];
    }

    public function getLockTtlSeconds(): int {
        return $this->lockTtlSeconds;
    }

    /** Возвращает маршрут шагов для каждого типа задачи. */
    private function getFlow(string $type, array $settings = []): array {
        if ($type === 'import') {
            $flow = ['init'];

            // Категории — отдельный справочник productfolder. На живом магазине их
            // не обязательно синхронизировать при каждом импорте товаров, поэтому
            // маршрут задачи зависит от флажка конкретного запуска.
            if (!empty($settings['module_moysklad_sync_import_categories_enabled'])) {
                $flow[] = 'sync_categories';
                $flow[] = 'rebuild_category_tree';
            }

            $flow[] = 'sync_products';
            $flow[] = 'sync_incoming_products';
            $flow[] = 'process_missing_products';
            $flow[] = 'finish';

            return $flow;
        }

        return match ($type) {
            'stock' => ['init', 'sync_stock', 'finish'],
            'images' => ['init', 'sync_images', 'finish'],
            default => []
        };
    }

    private function getNextStep(array $flow, string $currentStep): string {
        $index = array_search($currentStep, $flow, true);

        if ($index === false || !isset($flow[$index + 1])) {
            return 'finish';
        }

        return $flow[$index + 1];
    }

    private function requireDependency(array $dependencies, string $key): object {
        if (empty($dependencies[$key]) || !is_object($dependencies[$key])) {
            throw new \RuntimeException('Не передана зависимость TaskRunner: ' . $key);
        }

        return $dependencies[$key];
    }
}
