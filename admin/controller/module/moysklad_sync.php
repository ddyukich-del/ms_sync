<?php
namespace Opencart\Admin\Controller\Extension\MoyskladSync\Module;

use MoyskladSync\ApiException;
use MoyskladSync\HttpClient;
use MoyskladSync\MoyskladClient;
use MoyskladSync\CategorySyncService;
use MoyskladSync\ProductSyncService;
use MoyskladSync\StockSyncService;
use MoyskladSync\ImageSyncService;
use MoyskladSync\PurchaseOrderSyncService;
use MoyskladSync\TaskRunner;

class MoyskladSync extends \Opencart\System\Engine\Controller {
    private const SETTING_CODE = 'module_moysklad_sync';
    private const VERSION = '1.0.2';

    private array $error = [];

    public function index(): void {
        // Все AJAX-действия в админке ведем через основной маршрут модуля + ajax_action.
        // В ocStore/OpenCart 4 права доступа часто проверяются по полному route.
        // Если дергать методы как route=...moysklad_sync.getTaskStatus, система может
        // вернуть HTML-страницу отказа/редиректа вместо JSON, и браузер покажет ошибку
        // Unexpected token '<'. Один основной маршрут надежнее и проще для прав.
        $ajax_action = (string)($this->request->get['ajax_action'] ?? '');

        // При обновлении архива поверх старой версии таблицы уже существуют,
        // поэтому install() может не добавить новые колонки. На обычной загрузке
        // страницы запускаем мягкую проверку схемы. На каждом AJAX-шаге этого не
        // делаем, чтобы не добавлять лишние SHOW/ALTER-запросы во время импорта.
        if ($ajax_action === '') {
            $this->ensureModuleSchemaOnPageLoad();

            // При обновлении архива поверх рабочей версии метод install() может не
            // вызываться повторно. Поэтому при открытии страницы модуля мягко
            // убеждаемся, что права на основной маршрут и dashboard-виджет есть
            // у текущей группы администратора.
            $this->grantCurrentAdminPermissions();
        }

        if ($ajax_action !== '') {
            $this->dispatchAjaxAction($ajax_action);
            return;
        }

        $data = $this->load->language('extension/moysklad_sync/module/moysklad_sync');

        $this->document->setTitle($this->language->get('heading_title'));

        $data = array_merge($data, $this->buildPageData());

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/moysklad_sync/module/moysklad_sync', $data));
    }

    /**
     * Диспетчер внутренних AJAX-действий модуля.
     *
     * Мы не используем отдельные route вида .save/.runTaskStep, чтобы не зависеть
     * от нюансов проверки прав в конкретной сборке ocStore 4.1. Все запросы идут
     * на route=extension/moysklad_sync/module/moysklad_sync&ajax_action=...
     */
    private function dispatchAjaxAction(string $action): void {
        switch ($action) {
            case 'save':
                $this->save();
                break;
            case 'test_connection':
                $this->testConnection();
                break;
            case 'load_dictionaries':
                $this->loadDictionaries();
                break;
            case 'start_import':
                $this->startImport();
                break;
            case 'start_stock_update':
                $this->startStockUpdate();
                break;
            case 'start_image_sync':
                $this->startImageSync();
                break;
            case 'run_task_step':
                $this->runTaskStep();
                break;
            case 'get_task_status':
                $this->getTaskStatus();
                break;
            case 'get_product_statuses':
                $this->getProductStatuses();
                break;
            case 'export_product_statuses':
                $this->exportProductStatuses();
                break;
            case 'get_diagnostics':
                $this->getDiagnostics();
                break;
            case 'clear_api_debug_log':
                $this->clearApiDebugLog();
                break;
            case 'download_api_debug_log':
                $this->downloadApiDebugLog();
                break;
            case 'stop_task':
                $this->stopTask();
                break;
            default:
                $this->load->language('extension/moysklad_sync/module/moysklad_sync');
                $this->sendJson(['error' => $this->language->get('error_unknown_ajax_action') . ' ' . $action]);
        }
    }

    /**
     * Сохраняет настройки модуля.
     *
     * API-токен намеренно не подставляется обратно в форму. Если поле токена пустое,
     * сохраняем уже существующий токен, чтобы пользователь не вводил его каждый раз.
     */
    public function save(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/moysklad_sync/module/moysklad_sync')) {
            $json['error'] = $this->language->get('error_permission');
        }

        $settings = $this->normaliseSettings($this->request->post ?? []);

        if (!$this->validateSettings($settings)) {
            $json['error'] = reset($this->error);
        }

        if (!$json) {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting(self::SETTING_CODE, $settings);

            $json['success'] = $this->language->get('text_success');
        }

        $this->sendJson($json);
    }

    /**
     * AJAX: проверка подключения к МойСклад.
     *
     * Метод использует токен из формы, если пользователь его ввел, иначе берет
     * сохраненный токен из настроек. Это удобно: можно проверить новый токен до сохранения.
     */
    public function testConnection(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/moysklad_sync/module/moysklad_sync')) {
            $json['error'] = $this->language->get('error_permission');
            $this->sendJson($json);
            return;
        }

        try {
            $client = $this->createMoyskladClientFromRequest();
            $result = $client->testConnection();

            $json['success'] = $this->language->get('text_connection_success');
            $json['company_name'] = $result['company_name'];
        } catch (ApiException $e) {
            $json['error'] = $this->formatApiError($e);
        } catch (\Throwable $e) {
            $json['error'] = $this->language->get('error_unexpected') . ' ' . $e->getMessage();
        }

        $this->sendJson($json);
    }

    /**
     * AJAX: загрузка справочников для настроек.
     *
     * Сейчас получаем склады и типы цен. Важно: это легкий ручной запрос админки,
     * он не участвует в тяжелой синхронизации и не грузит каталог товаров.
     */
    public function loadDictionaries(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/moysklad_sync/module/moysklad_sync')) {
            $json['error'] = $this->language->get('error_permission');
            $this->sendJson($json);
            return;
        }

        try {
            $client = $this->createMoyskladClientFromRequest();

            $json['success'] = $this->language->get('text_dictionaries_loaded');
            $json['warehouses'] = $client->getWarehouses(100);
            $json['price_types'] = $client->getPriceTypes();

            try {
                $json['purchase_order_states'] = $client->getPurchaseOrderStates();
            } catch (\Throwable $stateError) {
                // Не ломаем загрузку складов и цен, если в конкретном аккаунте или
                // тарифе МойСклад статусы заказов поставщикам недоступны. Администратор
                // увидит пустой список и сможет оставить учет закупок выключенным.
                $json['purchase_order_states'] = [];
                $json['purchase_order_states_error'] = $stateError->getMessage();
            }
        } catch (ApiException $e) {
            $json['error'] = $this->formatApiError($e);
        } catch (\Throwable $e) {
            $json['error'] = $this->language->get('error_unexpected') . ' ' . $e->getMessage();
        }

        $this->sendJson($json);
    }


    /**
     * AJAX: старт полного импорта.
     *
     * На этапе 3 метод только создает задачу. Реальная синхронизация каталога
     * будет подключена в следующих этапах через TaskRunner и отдельные сервисы.
     */
    public function startImport(): void {
        $this->startTask('import');
    }

    /** AJAX: старт задачи обновления остатков. */
    public function startStockUpdate(): void {
        $this->startTask('stock');
    }

    /** AJAX: старт задачи загрузки изображений. */
    public function startImageSync(): void {
        $this->startTask('images');
    }

    /**
     * AJAX: выполняет один короткий шаг активной задачи.
     *
     * Важно: один запрос = один шаг. Мы не запускаем цикл до победного конца,
     * потому что на слабом сервере это быстро приводит к таймаутам и расходу памяти.
     */
    public function runTaskStep(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');
        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/moysklad_sync/module/moysklad_sync')) {
            $json['error'] = $this->language->get('error_permission');
            $this->sendJson($json);
            return;
        }

        $this->loadTaskModel();
        $this->loadCategoryModel();
        $this->loadProductModel();
        $this->loadImageModel();
        $this->loadMoyskladLibraries();

        $task = $this->model_extension_moysklad_sync_module_moysklad_task->getActiveTask();

        if (!$task) {
            $json['error'] = $this->language->get('error_no_active_task');
            $this->sendJson($json);
            return;
        }

        $runner = new TaskRunner();
        $taskId = (int)$task['task_id'];

        if (!$this->model_extension_moysklad_sync_module_moysklad_task->acquireTask($taskId, $runner->getLockTtlSeconds())) {
            $json['error'] = $this->language->get('error_task_locked');
            $json['task'] = $this->formatTask($task);
            $this->sendJson($json);
            return;
        }

        try {
            $lockedTask = $this->model_extension_moysklad_sync_module_moysklad_task->getTask($taskId);
            $settings = $this->getCurrentSettings();
            $client = $this->createMoyskladClientFromSettings($settings);
            $categoryService = new CategorySyncService(
                $client,
                $this->model_extension_moysklad_sync_module_moysklad_category,
                $this->model_extension_moysklad_sync_module_moysklad_task
            );
            $productService = new ProductSyncService(
                $client,
                $this->model_extension_moysklad_sync_module_moysklad_product,
                $this->model_extension_moysklad_sync_module_moysklad_task,
                $categoryService
            );
            $stockService = new StockSyncService(
                $client,
                $this->model_extension_moysklad_sync_module_moysklad_product,
                $this->model_extension_moysklad_sync_module_moysklad_task
            );
            $imageService = new ImageSyncService(
                $client,
                $this->model_extension_moysklad_sync_module_moysklad_image,
                $this->model_extension_moysklad_sync_module_moysklad_task
            );
            $purchaseOrderService = new PurchaseOrderSyncService(
                $client,
                $this->model_extension_moysklad_sync_module_moysklad_product,
                $this->model_extension_moysklad_sync_module_moysklad_task,
                $categoryService
            );

            $updatedTask = $runner->runOneStep($lockedTask, $this->model_extension_moysklad_sync_module_moysklad_task, $settings, [
                'category_service' => $categoryService,
                'product_service' => $productService,
                'stock_service' => $stockService,
                'image_service' => $imageService,
                'purchase_order_service' => $purchaseOrderService
            ]);

            if (($updatedTask['status'] ?? '') === 'running') {
                $this->model_extension_moysklad_sync_module_moysklad_task->releaseTask($taskId);
                $updatedTask = $this->model_extension_moysklad_sync_module_moysklad_task->getTask($taskId) ?: $updatedTask;
            }

            $json['success'] = $this->language->get('text_task_step_done');
            $json['task'] = $this->formatTask($updatedTask);
            $json['logs'] = $this->formatLogs($this->model_extension_moysklad_sync_module_moysklad_task->getRecentLogs(20));
            $json['product_statuses'] = $this->getFormattedProductStatuses();
        } catch (\Throwable $e) {
            $this->model_extension_moysklad_sync_module_moysklad_task->failTask($taskId, $e->getMessage());

            $json['error'] = $this->language->get('error_task_failed') . ' ' . $e->getMessage();
            $json['task'] = $this->formatTask($this->model_extension_moysklad_sync_module_moysklad_task->getTask($taskId) ?: []);
        }

        $this->sendJson($json);
    }

    /** AJAX: возвращает последнюю или активную задачу и свежие логи. */
    public function getTaskStatus(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');
        $json = [];

        if (!$this->user->hasPermission('access', 'extension/moysklad_sync/module/moysklad_sync')) {
            $json['error'] = $this->language->get('error_permission');
            $this->sendJson($json);
            return;
        }

        $this->loadTaskModel();
        $this->loadProductModel();

        $task = $this->model_extension_moysklad_sync_module_moysklad_task->getActiveTask()
            ?: $this->model_extension_moysklad_sync_module_moysklad_task->getLatestTask();

        $json['task'] = $this->formatTask($task ?: []);
        $json['logs'] = $this->formatLogs($this->model_extension_moysklad_sync_module_moysklad_task->getRecentLogs(20));
        $json['product_statuses'] = $this->getFormattedProductStatuses();

        $this->sendJson($json);
    }

    /** AJAX: возвращает список синхронизированных товаров с источником статуса. */
    public function getProductStatuses(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');

        if (!$this->user->hasPermission('access', 'extension/moysklad_sync/module/moysklad_sync')) {
            $this->sendJson(['error' => $this->language->get('error_permission')]);
            return;
        }

        $this->loadProductModel();
        $this->sendJson(['product_statuses' => $this->getFormattedProductStatusesFromRequest()]);
    }

    /**
     * AJAX/GET: выгружает текущий список товаров из вкладки «Товары» в CSV.
     *
     * Используем те же фильтры и сортировку, что и на экране, чтобы менеджер
     * скачивал именно тот срез данных, который он сейчас анализирует в админке.
     * CSV сделан с BOM и разделителем ';' — так файл нормально открывается
     * в русской версии Excel без ручного выбора кодировки.
     */
    public function exportProductStatuses(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');

        if (!$this->user->hasPermission('access', 'extension/moysklad_sync/module/moysklad_sync')) {
            $this->response->addHeader('Content-Type: text/plain; charset=UTF-8');
            $this->response->setOutput($this->language->get('error_permission'));
            return;
        }

        $this->loadProductModel();

        // Для экспорта берем больше строк, чем выводим в таблицу, но оставляем
        // разумный верхний предел, чтобы случайный клик не положил слабый хостинг.
        $limit = (int)($this->request->get['export_limit'] ?? 10000);
        $limit = max(1, min(10000, $limit));

        $rows = $this->getFormattedProductStatusesFromRequest($limit);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            $this->response->addHeader('Content-Type: text/plain; charset=UTF-8');
            $this->response->setOutput($this->language->get('error_unexpected') . ' Не удалось создать временный файл экспорта.');
            return;
        }

        // UTF-8 BOM для Excel.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            $this->language->get('column_product_id'),
            $this->language->get('column_product'),
            $this->language->get('column_article'),
            $this->language->get('column_sync_source'),
            $this->language->get('column_quantity'),
            $this->language->get('column_expected_quantity'),
            $this->language->get('column_site_quantity'),
            $this->language->get('column_stock_status'),
            $this->language->get('column_purchase_order'),
            $this->language->get('column_purchase_order_state'),
            $this->language->get('column_updated')
        ], ';');

        foreach ($rows as $row) {
            fputcsv($handle, [
                (string)($row['product_id'] ?? ''),
                (string)($row['name'] ?? ''),
                (string)($row['article'] ?? ''),
                (string)($row['sync_source_title'] ?? ''),
                (string)($row['quantity'] ?? ''),
                (string)($row['expected_quantity'] ?? $row['incoming_quantity'] ?? ''),
                (string)($row['site_quantity'] ?? ''),
                (string)($row['stock_status_name'] ?? ''),
                (string)($row['purchase_order_name'] ?? ''),
                (string)($row['purchase_order_state_name'] ?? ''),
                (string)($row['last_synced_at'] ?? '')
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'moysklad_products_' . date('Y-m-d_H-i-s') . '.csv';

        $this->response->addHeader('Content-Type: text/csv; charset=UTF-8');
        $this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
        $this->response->addHeader('Pragma: no-cache');
        $this->response->addHeader('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $this->response->setOutput($csv ?: '');
    }


    /** AJAX: собирает диагностический отчет перед релизом/поддержкой. */
    public function getDiagnostics(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');

        if (!$this->user->hasPermission('access', 'extension/moysklad_sync/module/moysklad_sync')) {
            $this->sendJson(['error' => $this->language->get('error_permission')]);
            return;
        }

        $diagnostics = $this->buildDiagnostics();
        $this->sendJson(['diagnostics' => $diagnostics]);
    }

    /** AJAX: очищает сырой API debug log. */
    public function clearApiDebugLog(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');

        if (!$this->user->hasPermission('modify', 'extension/moysklad_sync/module/moysklad_sync')) {
            $this->sendJson(['error' => $this->language->get('error_permission')]);
            return;
        }

        $files = [$this->getApiDebugLogPath(), $this->getApiDebugLogPath() . '.old'];
        $deleted = 0;

        foreach ($files as $file) {
            if (is_file($file) && @unlink($file)) {
                $deleted++;
            }
        }

        $this->sendJson([
            'success' => $deleted > 0 ? $this->language->get('text_api_debug_log_cleared') : $this->language->get('text_api_debug_log_already_empty'),
            'diagnostics' => $this->buildDiagnostics()
        ]);
    }

    /** GET: скачивает сырой API debug log без токена авторизации. */
    public function downloadApiDebugLog(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');

        if (!$this->user->hasPermission('access', 'extension/moysklad_sync/module/moysklad_sync')) {
            $this->response->addHeader('Content-Type: text/plain; charset=UTF-8');
            $this->response->setOutput($this->language->get('error_permission'));
            return;
        }

        $file = $this->getApiDebugLogPath();

        if (!is_file($file) || !is_readable($file)) {
            $this->response->addHeader('Content-Type: text/plain; charset=UTF-8');
            $this->response->setOutput($this->language->get('text_api_debug_log_missing'));
            return;
        }

        $filename = 'moysklad_api_debug_' . date('Y-m-d_H-i-s') . '.log';
        $this->response->addHeader('Content-Type: text/plain; charset=UTF-8');
        $this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
        $this->response->addHeader('Pragma: no-cache');
        $this->response->addHeader('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $this->response->setOutput((string)file_get_contents($file));
    }

    /** AJAX: останавливает активную задачу. */
    public function stopTask(): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');
        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/moysklad_sync/module/moysklad_sync')) {
            $json['error'] = $this->language->get('error_permission');
            $this->sendJson($json);
            return;
        }

        $this->loadTaskModel();
        $task = $this->model_extension_moysklad_sync_module_moysklad_task->stopActiveTask();

        if (!$task) {
            $json['error'] = $this->language->get('error_no_active_task');
        } else {
            $json['success'] = $this->language->get('text_task_stopped');
            $json['task'] = $this->formatTask($task);
            $json['logs'] = $this->formatLogs($this->model_extension_moysklad_sync_module_moysklad_task->getRecentLogs(20));
            $json['product_statuses'] = $this->getFormattedProductStatuses();
        }

        $this->sendJson($json);
    }


    /**
     * Мягко обновляет служебные таблицы при открытии страницы модуля.
     *
     * Это исправляет сценарий, когда модуль был установлен ранней сборкой,
     * а новая сборка добавила служебные колонки вроде last_seen_task_id.
     * Ошибку миграции не скрываем полностью: записываем ее в лог OpenCart,
     * но страницу все равно показываем, чтобы администратор мог увидеть настройки.
     */
    private function ensureModuleSchemaOnPageLoad(): void {
        try {
            $this->load->model('extension/moysklad_sync/module/moysklad_sync');
            $this->model_extension_moysklad_sync_module_moysklad_sync->ensureSchema();
            $this->cleanupBrokenShortcutModification();
            // Если архив обновили поверх предыдущей версии, install() мог не вызваться.
            // Поэтому мягко регистрируем dashboard-виджет при открытии страницы.
            $this->installDashboardWidget();
        } catch (\Throwable $e) {
            $this->log->write('Moysklad Sync schema migration error: ' . $e->getMessage());
        }
    }

    public function install(): void {
        $this->load->model('extension/moysklad_sync/module/moysklad_sync');
        $this->model_extension_moysklad_sync_module_moysklad_sync->install();
        $this->cleanupBrokenShortcutModification();

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting(self::SETTING_CODE, $this->getDefaultSettings());

        // Сразу выдаем текущей группе администратора права на основной маршрут.
        // Пользователь все равно может вручную проверить права в User Groups,
        // но после установки модуль не должен ломать AJAX из-за отсутствия access/modify.
        $this->grantCurrentAdminPermissions();

        // Регистрируем штатный dashboard-виджет OpenCart. Это безопаснее, чем
        // вмешиваться в левое меню через OCMOD: главная админки сама загружает
        // расширения типа dashboard через таблицу extension и настройки dashboard_*.
        $this->installDashboardWidget(true);
    }

    public function uninstall(): void {
        $this->load->model('extension/moysklad_sync/module/moysklad_sync');
        $this->model_extension_moysklad_sync_module_moysklad_sync->uninstall();
        $this->cleanupBrokenShortcutModification();
        $this->uninstallDashboardWidget();

        // Устаревшие быстрые ссылки/OCMOD чистим вручную: в некоторых сборках ocStore
        // неудачная модификация может мешать штатному удалению/установке.
        // Служебные таблицы модуля не удаляем: они содержат связи товаров/категорий.

        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting(self::SETTING_CODE);
    }


    /**
     * Удаляет старую экспериментальную OCMOD-модификацию быстрых ссылок.
     *
     * В версиях 0.11.1–0.11.3 мы пробовали добавлять пункт меню и виджет через OCMOD.
     * На некоторых сборках ocStore это не применялось, а иногда мешало штатной установке
     * и удалению. Поэтому с 0.11.4 модуль возвращен в безопасный режим: без правки
     * меню/главной страницы. Старую запись модификатора удаляем мягко, если таблица есть.
     */
    private function cleanupBrokenShortcutModification(): void {
        try {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "modification` WHERE `code` = 'moysklad_sync_admin_shortcuts' OR `name` LIKE '%Moysklad Sync Admin Shortcuts%' OR `xml` LIKE '%moysklad_sync_admin_shortcuts%'");
            $this->db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` = 'moysklad_sync_admin_menu' OR `action` LIKE '%moysklad_sync|addAdminMenu%'");
        } catch (\Throwable $e) {
            // Не прерываем установку/открытие страницы: в разных сборках таблица
            // modification может называться или вести себя иначе.
            $this->log->write('Moysklad Sync shortcut modification cleanup error: ' . $e->getMessage());
        }
    }

    private function grantCurrentAdminPermissions(): void {
        try {
            if (!isset($this->user) || !method_exists($this->user, 'getGroupId')) {
                return;
            }

            $this->grantDashboardPermissions();
        } catch (\Throwable $e) {
            // Не прерываем установку, если конкретная сборка ocStore отличается
            // моделью прав. В этом случае права можно выдать вручную.
        }
    }

    /**
     * Регистрирует быстрый пункт «МойСклад» в левом меню админки.
     *
     * Используем Event, а не правку файлов ядра. Это работает и при переименованной
     * папке admin в maestro: ссылка строится через текущий admin URL и user_token.
     */
    private function registerAdminShortcuts(): void {
        try {
            $this->load->model('setting/event');
            $this->model_setting_event->deleteEventByCode('moysklad_sync_admin_menu');
            $this->model_setting_event->addEvent([
                'code' => 'moysklad_sync_admin_menu',
                'description' => 'Moysklad Sync admin menu shortcut',
                'trigger' => 'admin/view/common/column_left/before',
                'action' => 'extension/moysklad_sync/module/moysklad_sync|addAdminMenu',
                'status' => true,
                'sort_order' => 10
            ]);
        } catch (\Throwable $e) {
            $this->log->write('Moysklad Sync menu event install error: ' . $e->getMessage());
        }
    }

    private function unregisterAdminShortcuts(): void {
        try {
            $this->load->model('setting/event');
            $this->model_setting_event->deleteEventByCode('moysklad_sync_admin_menu');
        } catch (\Throwable $e) {
            // Не мешаем uninstall, если таблица event или модель отличается.
        }
    }

    /**
     * Регистрирует виджет на главной странице админки штатным механизмом dashboard.
     *
     * OpenCart/ocStore 4 выводит dashboard-блоки из таблицы extension с type=dashboard
     * и проверяет настройки dashboard_{code}_status/width/sort_order. Повторный вызов
     * безопасен: model_setting_extension->install() не создает дубликаты по code.
     */
    private function installDashboardWidget(bool $forceDefaults = false): void {
        try {
            $this->load->model('setting/extension');
            $this->model_setting_extension->install('dashboard', 'moysklad_sync', 'moysklad_sync');

            // Важно: в OpenCart 4 config->get() возвращает пустую строку для
            // отсутствующей настройки, а не null. Поэтому настройки dashboard
            // проверяем напрямую в oc_setting и добавляем недостающие ключи точечно.
            // Если настройка уже есть, не перетираем ее без forceDefaults.
            $this->ensureDashboardSettings($forceDefaults);
            $this->grantDashboardPermissions();
        } catch (\Throwable $e) {
            // Не блокируем установку модуля: если конкретная сборка ocStore отличается
            // таблицами dashboard/extension, основная синхронизация должна остаться рабочей.
            $this->log->write('Moysklad Sync dashboard install error: ' . $e->getMessage());
        }
    }

    /** Гарантирует наличие настроек штатного dashboard-виджета. */
    private function ensureDashboardSettings(bool $forceDefaults = false): void {
        $defaults = [
            'dashboard_moysklad_sync_status' => 1,
            'dashboard_moysklad_sync_width' => 12,
            'dashboard_moysklad_sync_sort_order' => 1
        ];

        foreach ($defaults as $key => $value) {
            $query = $this->db->query("SELECT `setting_id` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = '" . $this->db->escape($key) . "' LIMIT 1");

            if ($query->num_rows && !$forceDefaults) {
                continue;
            }

            if ($query->num_rows) {
                $this->db->query("UPDATE `" . DB_PREFIX . "setting` SET `code` = 'dashboard_moysklad_sync', `value` = '" . $this->db->escape((string)$value) . "', `serialized` = '0' WHERE `setting_id` = '" . (int)$query->row['setting_id'] . "'");
            } else {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'dashboard_moysklad_sync', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape((string)$value) . "', `serialized` = '0'");
            }
        }
    }

    /**
     * Выдает права на основной модуль и dashboard-виджет.
     *
     * common/dashboard молча пропускает виджет, если у текущей группы нет access
     * на route extension/moysklad_sync/dashboard/moysklad_sync. Поэтому права
     * добавляем напрямую и без дублей. Дополнительно выдаем dashboard тем группам,
     * у которых уже есть доступ к основному модулю.
     */
    private function grantDashboardPermissions(): void {
        $routes = [
            'extension/moysklad_sync/module/moysklad_sync',
            'extension/moysklad_sync/dashboard/moysklad_sync'
        ];

        $groupIds = [];

        if (isset($this->user) && method_exists($this->user, 'getGroupId')) {
            $groupIds[] = (int)$this->user->getGroupId();
        }

        try {
            $query = $this->db->query("SELECT `user_group_id`, `permission` FROM `" . DB_PREFIX . "user_group` WHERE `permission` LIKE '%moysklad_sync%'");
            foreach ($query->rows as $row) {
                $groupIds[] = (int)$row['user_group_id'];
            }
        } catch (\Throwable $e) {
            // Если таблица прав отличается, оставим хотя бы текущую группу.
        }

        $groupIds = array_values(array_unique(array_filter($groupIds)));

        foreach ($groupIds as $userGroupId) {
            foreach (['access', 'modify'] as $type) {
                foreach ($routes as $route) {
                    $this->ensureUserGroupPermission($userGroupId, $type, $route);
                }
            }
        }
    }

    private function ensureUserGroupPermission(int $userGroupId, string $type, string $route): void {
        $query = $this->db->query("SELECT `permission` FROM `" . DB_PREFIX . "user_group` WHERE `user_group_id` = '" . (int)$userGroupId . "' LIMIT 1");

        if (!$query->num_rows) {
            return;
        }

        $permissions = json_decode((string)$query->row['permission'], true);

        if (!is_array($permissions)) {
            $permissions = [];
        }

        if (!isset($permissions[$type]) || !is_array($permissions[$type])) {
            $permissions[$type] = [];
        }

        if (!in_array($route, $permissions[$type], true)) {
            $permissions[$type][] = $route;
            $this->db->query("UPDATE `" . DB_PREFIX . "user_group` SET `permission` = '" . $this->db->escape(json_encode($permissions)) . "' WHERE `user_group_id` = '" . (int)$userGroupId . "'");
        }
    }

    private function uninstallDashboardWidget(): void {
        try {
            $this->load->model('setting/extension');
            $this->model_setting_extension->uninstall('dashboard', 'moysklad_sync');
        } catch (\Throwable $e) {
            // Не мешаем uninstall.
        }

        try {
            $this->load->model('setting/setting');
            $this->model_setting_setting->deleteSetting('dashboard_moysklad_sync');
        } catch (\Throwable $e) {
            // Не мешаем uninstall.
        }

        try {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "extension` WHERE `type` = 'dashboard' AND `code` = 'moysklad_sync'");
            $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `key` LIKE 'dashboard_moysklad_sync_%'");
        } catch (\Throwable $e) {
            // Не мешаем uninstall.
        }
    }

    /**
     * Event callback: добавляет пункт «МойСклад» в левое меню админки.
     *
     * Сигнатура соответствует view/common/column_left/before: route, data, code, output.
     * Меняем только $data['menus']; стандартный шаблон меню отрисует пункт сам.
     */
    public function addAdminMenu(string &$route, array &$data, string &$code = '', string &$output = ''): void {
        if (empty($this->session->data['user_token'])) {
            return;
        }

        if (!$this->user->hasPermission('access', 'extension/moysklad_sync/module/moysklad_sync')) {
            return;
        }

        if (!isset($data['menus']) || !is_array($data['menus'])) {
            return;
        }

        foreach ($data['menus'] as $menu) {
            if (($menu['id'] ?? '') === 'menu-moysklad-sync' || ($menu['code'] ?? '') === 'moysklad_sync') {
                return;
            }
        }

        $this->load->language('extension/moysklad_sync/module/moysklad_sync');

        $menu = [
            'id' => 'menu-moysklad-sync',
            'icon' => 'fa-solid fa-warehouse',
            'name' => $this->language->get('text_admin_menu_moysklad'),
            'href' => $this->url->link('extension/moysklad_sync/module/moysklad_sync', 'user_token=' . $this->session->data['user_token'] . '&tab=products'),
            'children' => []
        ];

        // Ставим сразу после Dashboard, чтобы менеджер видел пункт без прокрутки.
        array_splice($data['menus'], 1, 0, [$menu]);
    }

    private function buildPageData(): array {
        $data = [];

        $data['breadcrumbs'] = [];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/moysklad_sync/module/moysklad_sync', 'user_token=' . $this->session->data['user_token'])
        ];

        // AJAX-ссылки ведут на тот же основной route, но с внутренним action.
        // Это защищает от HTML-ответов permission/login page там, где JS ждет JSON.
        $base_query = 'user_token=' . $this->session->data['user_token'];
        $data['save'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', $base_query . '&ajax_action=save');
        $data['test_connection'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', $base_query . '&ajax_action=test_connection');
        $data['load_dictionaries'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', $base_query . '&ajax_action=load_dictionaries');
        $data['start_import'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', $base_query . '&ajax_action=start_import');
        $data['start_stock_update'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', $base_query . '&ajax_action=start_stock_update');
        $data['start_image_sync'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', $base_query . '&ajax_action=start_image_sync');
        $data['run_task_step'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', $base_query . '&ajax_action=run_task_step');
        $data['get_task_status'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', $base_query . '&ajax_action=get_task_status');
        $data['stop_task'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', $base_query . '&ajax_action=stop_task');
        $data['download_api_debug_log'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', $base_query . '&ajax_action=download_api_debug_log');
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

        $defaults = $this->getDefaultSettings();

        foreach ($defaults as $key => $default) {
            if ($key === 'module_moysklad_sync_api_token') {
                $data[$key] = '';
                $data['module_moysklad_sync_api_token_set'] = (bool)$this->config->get($key);
                continue;
            }

            $value = $this->config->get($key);
            $data[$key] = ($value !== null && $value !== '') ? $value : $default;
        }

        $data['module_moysklad_sync_warehouse_ids'] = $this->normaliseStringArray($data['module_moysklad_sync_warehouse_ids'] ?? []);
        if (!$data['module_moysklad_sync_warehouse_ids'] && !empty($data['module_moysklad_sync_warehouse_id'])) {
            $data['module_moysklad_sync_warehouse_ids'] = [(string)$data['module_moysklad_sync_warehouse_id']];
        }
        $data['module_moysklad_sync_warehouse_names'] = $this->normaliseStringArray($data['module_moysklad_sync_warehouse_names'] ?? []);
        $data['module_moysklad_sync_incoming_product_match_mode'] = 'by_moysklad_id';
        $data['module_moysklad_sync_incoming_quantity_mode'] = 'zero';

        $data['action_options'] = [
            'none' => $this->language->get('text_action_none'),
            'disable' => $this->language->get('text_action_disable'),
            'delete' => $this->language->get('text_action_delete')
        ];

        $data['log_level_options'] = [
            'warning' => $this->language->get('text_log_error_warning'),
            'info' => $this->language->get('text_log_info'),
            'debug' => $this->language->get('text_log_debug')
        ];

        $data['seo_mode_options'] = [
            'new_only' => $this->language->get('text_seo_new_only')
        ];

        $data['incoming_quantity_mode_options'] = [
            'zero' => $this->language->get('text_incoming_quantity_zero'),
            'expected' => $this->language->get('text_incoming_quantity_expected')
        ];

        $data['incoming_product_match_mode_options'] = [
            'by_moysklad_id' => $this->language->get('text_incoming_match_by_moysklad_id'),
            'separate' => $this->language->get('text_incoming_match_separate'),
            'merge_by_name' => $this->language->get('text_incoming_match_merge_by_name')
        ];

        $data['stock_status_options'] = $this->getStockStatusOptions();
        $data['product_statuses'] = $this->getFormattedProductStatuses();

        return $data;
    }


    private function getStockStatusOptions(): array {
        $languageId = (int)$this->config->get('config_language_id') ?: 1;
        $result = [];

        try {
            $query = $this->db->query("SELECT `stock_status_id`, `name` FROM `" . DB_PREFIX . "stock_status`
                WHERE `language_id` = '" . (int)$languageId . "'
                ORDER BY `name` ASC");

            foreach ($query->rows as $row) {
                $result[(int)$row['stock_status_id']] = (string)$row['name'];
            }
        } catch (\Throwable $e) {
            // На нестандартной сборке таблица может отличаться. Тогда просто
            // оставим select пустым, а модуль использует config_stock_status_id.
        }

        return $result;
    }


    private function startTask(string $type): void {
        $this->load->language('extension/moysklad_sync/module/moysklad_sync');
        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/moysklad_sync/module/moysklad_sync')) {
            $json['error'] = $this->language->get('error_permission');
            $this->sendJson($json);
            return;
        }

        try {
            $this->assertTaskCanStart($type);
            $this->loadTaskModel();

            $settings = $this->getCurrentSettings();
            $limit = $this->getLimitForTaskType($type, $settings);

            $task = $this->model_extension_moysklad_sync_module_moysklad_task->createTask($type, $limit, [
                'settings_snapshot' => $this->getSafeSettingsSnapshot($settings)
            ]);

            $json['success'] = $this->language->get('text_task_created');
            $json['task'] = $this->formatTask($task);
            $json['logs'] = $this->formatLogs($this->model_extension_moysklad_sync_module_moysklad_task->getRecentLogs(20));
        } catch (\Throwable $e) {
            $json['error'] = $e->getMessage();
        }

        $this->sendJson($json);
    }

    private function getConfiguredWarehouseIds(): array {
        $ids = $this->normaliseStringArray($this->config->get('module_moysklad_sync_warehouse_ids') ?? []);

        if (!$ids) {
            $legacyId = trim((string)$this->config->get('module_moysklad_sync_warehouse_id'));
            if ($legacyId !== '') {
                $ids[] = $legacyId;
            }
        }

        return $ids;
    }

    private function assertTaskCanStart(string $type): void {
        if (!(int)$this->config->get('module_moysklad_sync_status')) {
            throw new \RuntimeException($this->language->get('error_module_disabled'));
        }

        if (!trim((string)$this->config->get('module_moysklad_sync_api_token'))) {
            throw new \RuntimeException($this->language->get('error_api_token_required'));
        }

        // Для импорта и остатков уже нужен выбранный склад: импорт тоже будет
        // обновлять остаток/статус, а значит должен знать источник остатков.
        if (in_array($type, ['import', 'stock'], true) && !$this->getConfiguredWarehouseIds()) {
            throw new \RuntimeException($this->language->get('error_warehouse_required'));
        }

        if ($type === 'import' && !trim((string)$this->config->get('module_moysklad_sync_price_type_id'))) {
            throw new \RuntimeException($this->language->get('error_price_type_required'));
        }
    }

    private function getLimitForTaskType(string $type, array $settings): int {
        return match ($type) {
            // Импорт теперь идет от отчета остатков выбранного склада, поэтому
            // размер пакета берем из настроек товаров, а не категорий.
            'import' => (int)$settings['module_moysklad_sync_product_batch_size'],
            'stock' => (int)$settings['module_moysklad_sync_stock_batch_size'],
            'images' => (int)$settings['module_moysklad_sync_image_batch_size'],
            default => 20
        };
    }

    private function getCurrentSettings(): array {
        $defaults = $this->getDefaultSettings();
        $settings = [];

        foreach ($defaults as $key => $default) {
            $value = $this->config->get($key);
            $settings[$key] = ($value !== null && $value !== '') ? $value : $default;
        }

        // Совместимость со старыми установками: раньше выбирался один склад и
        // сохранялся в warehouse_id. Теперь храним массив warehouse_ids, но если
        // массив еще пустой, используем старое значение как первый выбранный склад.
        $settings['module_moysklad_sync_warehouse_ids'] = $this->normaliseStringArray($settings['module_moysklad_sync_warehouse_ids'] ?? []);
        if (!$settings['module_moysklad_sync_warehouse_ids'] && !empty($settings['module_moysklad_sync_warehouse_id'])) {
            $settings['module_moysklad_sync_warehouse_ids'] = [(string)$settings['module_moysklad_sync_warehouse_id']];
        }
        $settings['module_moysklad_sync_warehouse_names'] = $this->normaliseStringArray($settings['module_moysklad_sync_warehouse_names'] ?? []);

        return $settings;
    }

    private function getSafeSettingsSnapshot(array $settings): array {
        unset($settings['module_moysklad_sync_api_token']);

        return $settings;
    }

    private function loadTaskModel(): void {
        $this->load->model('extension/moysklad_sync/module/moysklad_task');
    }

    private function loadCategoryModel(): void {
        $this->load->model('extension/moysklad_sync/module/moysklad_category');
    }

    private function loadProductModel(): void {
        $this->load->model('extension/moysklad_sync/module/moysklad_product');
    }

    private function loadImageModel(): void {
        $this->load->model('extension/moysklad_sync/module/moysklad_image');
    }

    private function formatTask(array $task): array {
        if (!$task) {
            return [];
        }

        return [
            'task_id' => (int)$task['task_id'],
            'task_type' => (string)$task['task_type'],
            'task_type_title' => $this->getTaskTypeTitle((string)$task['task_type']),
            'status' => (string)$task['status'],
            'status_title' => $this->getTaskStatusTitle((string)$task['status']),
            'current_step' => (string)$task['current_step'],
            'current_step_title' => $this->getTaskStepTitle((string)$task['current_step']),
            'processed_items' => (int)$task['processed_items'],
            'created_items' => (int)$task['created_items'],
            'updated_items' => (int)$task['updated_items'],
            'skipped_items' => (int)$task['skipped_items'],
            'deleted_items' => (int)$task['deleted_items'],
            'disabled_items' => (int)$task['disabled_items'],
            'error_items' => (int)$task['error_items'],
            'started_at' => (string)($task['started_at'] ?? ''),
            'finished_at' => (string)($task['finished_at'] ?? ''),
            'last_error' => (string)($task['last_error'] ?? ''),
            'is_active' => in_array((string)$task['status'], ['new', 'running', 'paused'], true)
        ];
    }

    private function getFormattedProductStatuses(): array {
        try {
            if (!isset($this->model_extension_moysklad_sync_module_moysklad_product)) {
                $this->loadProductModel();
            }

            return $this->formatProductStatuses($this->model_extension_moysklad_sync_module_moysklad_product->getSyncProductsForAdmin());
        } catch (\Throwable $e) {
            $this->log->write('Moysklad Sync product status list error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Возвращает список товаров с учетом фильтров и сортировки из вкладки «Товары».
     *
     * Фильтры передаются через query string AJAX-запроса, а не через основную форму
     * настроек. Так пользователь может сортировать список, не меняя настройки модуля.
     */
    private function getFormattedProductStatusesFromRequest(?int $forcedLimit = null): array {
        try {
            if (!isset($this->model_extension_moysklad_sync_module_moysklad_product)) {
                $this->loadProductModel();
            }

            $filter = [
                'search' => trim((string)($this->request->get['filter_search'] ?? '')),
                'source' => trim((string)($this->request->get['filter_source'] ?? '')),
                'status' => trim((string)($this->request->get['filter_status'] ?? '')),
                'quantity' => trim((string)($this->request->get['filter_quantity'] ?? '')),
                'expected' => trim((string)($this->request->get['filter_expected'] ?? '')),
                'order_state' => trim((string)($this->request->get['filter_order_state'] ?? '')),
            ];

            $sort = trim((string)($this->request->get['sort'] ?? 'product'));
            $order = trim((string)($this->request->get['order'] ?? 'ASC'));
            $limit = $forcedLimit !== null ? $forcedLimit : (int)($this->request->get['limit'] ?? 150);

            return $this->formatProductStatuses(
                $this->model_extension_moysklad_sync_module_moysklad_product->getSyncProductsForAdmin($filter, $sort, $order, $limit)
            );
        } catch (\Throwable $e) {
            $this->log->write('Moysklad Sync product status filtered list error: ' . $e->getMessage());
            return [];
        }
    }

    private function formatProductStatuses(array $rows): array {
        $result = [];

        foreach ($rows as $row) {
            $source = (string)($row['sync_source'] ?? 'unknown');
            $badge = 'secondary';
            $sourceTitle = $this->language->get('text_product_source_unknown');

            if ($source === 'stock') {
                $badge = 'success';
                $sourceTitle = $this->language->get('text_product_source_stock');
            } elseif ($source === 'incoming') {
                $badge = 'warning';
                $sourceTitle = $this->language->get('text_product_source_incoming');
            } elseif ($source === 'stock_incoming') {
                $badge = 'info';
                $sourceTitle = $this->language->get('text_product_source_stock_incoming');
            } elseif ($source === 'missing') {
                $badge = 'danger';
                $sourceTitle = $this->language->get('text_product_source_missing');
            }

            $result[] = [
                'product_id' => (int)($row['product_id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'article' => (string)($row['article'] ?? ''),
                'quantity' => $row['quantity'] === null ? '' : (string)(float)$row['quantity'],
                'site_quantity' => $row['site_quantity'] === null ? '' : (string)(int)$row['site_quantity'],
                'status' => (int)($row['status'] ?? 0),
                'stock_status_name' => (string)($row['stock_status_name'] ?? ''),
                'sync_source' => $source,
                'sync_source_title' => $sourceTitle,
                'sync_source_badge' => $badge,
                'incoming_quantity' => $row['incoming_quantity'] === null ? '' : (string)(float)$row['incoming_quantity'],
                'expected_quantity' => $row['incoming_quantity'] === null ? '' : (string)(float)$row['incoming_quantity'],
                'purchase_order_name' => (string)($row['purchase_order_name'] ?? ''),
                'purchase_order_state_name' => (string)($row['purchase_order_state_name'] ?? ''),
                'last_stock_quantity' => $row['last_stock_quantity'] === null ? '' : (string)(float)$row['last_stock_quantity'],
                'last_synced_at' => (string)($row['last_synced_at'] ?? ''),
            ];
        }

        return $result;
    }

    private function formatLogs(array $logs): array {
        $result = [];

        foreach ($logs as $log) {
            $result[] = [
                'created_at' => (string)$log['created_at'],
                'level' => (string)$log['level'],
                'entity_type' => (string)($log['entity_type'] ?? ''),
                'message' => (string)$log['message']
            ];
        }

        return $result;
    }

    private function getTaskTypeTitle(string $type): string {
        return match ($type) {
            'import' => $this->language->get('text_task_type_import'),
            'stock' => $this->language->get('text_task_type_stock'),
            'images' => $this->language->get('text_task_type_images'),
            default => $type
        };
    }

    private function getTaskStatusTitle(string $status): string {
        return match ($status) {
            'new' => $this->language->get('text_task_status_new'),
            'running' => $this->language->get('text_task_status_running'),
            'paused' => $this->language->get('text_task_status_paused'),
            'finished' => $this->language->get('text_task_status_finished'),
            'finished_with_errors' => $this->language->get('text_task_status_finished_with_errors'),
            'failed' => $this->language->get('text_task_status_failed'),
            'stopped' => $this->language->get('text_task_status_stopped'),
            default => $status
        };
    }

    private function getTaskStepTitle(string $step): string {
        return match ($step) {
            'init' => $this->language->get('text_step_init'),
            'sync_categories' => $this->language->get('text_step_sync_categories'),
            'sync_products' => $this->language->get('text_step_sync_products'),
            'rebuild_category_tree' => $this->language->get('text_step_rebuild_category_tree'),
            'process_missing_categories' => $this->language->get('text_step_missing_categories'),
            'process_missing_products' => $this->language->get('text_step_missing_products'),
            'sync_incoming_products' => $this->language->get('text_step_sync_incoming_products'),
            'sync_stock' => $this->language->get('text_step_sync_stock'),
            'sync_images' => $this->language->get('text_step_sync_images'),
            'finish' => $this->language->get('text_step_finish'),
            default => $step
        };
    }

    private function normaliseSettings(array $post): array {
        $settings = $this->getDefaultSettings();

        $existingToken = (string)$this->config->get('module_moysklad_sync_api_token');
        $postedToken = trim((string)($post['module_moysklad_sync_api_token'] ?? ''));

        $settings['module_moysklad_sync_status'] = !empty($post['module_moysklad_sync_status']) ? 1 : 0;
        $settings['module_moysklad_sync_api_token'] = $postedToken !== '' ? $postedToken : $existingToken;
        // Складов может быть несколько. Для обратной совместимости сохраняем
        // также первый выбранный склад в старые поля warehouse_id/name.
        $settings['module_moysklad_sync_warehouse_ids'] = $this->normaliseStringArray($post['module_moysklad_sync_warehouse_ids'] ?? ($post['module_moysklad_sync_warehouse_id'] ?? []));
        $settings['module_moysklad_sync_warehouse_names'] = $this->normaliseStringArray($post['module_moysklad_sync_warehouse_names'] ?? []);
        $settings['module_moysklad_sync_warehouse_id'] = (string)($settings['module_moysklad_sync_warehouse_ids'][0] ?? '');
        $settings['module_moysklad_sync_warehouse_name'] = (string)($settings['module_moysklad_sync_warehouse_names'][0] ?? trim((string)($post['module_moysklad_sync_warehouse_name'] ?? '')));
        $settings['module_moysklad_sync_price_type_id'] = trim((string)($post['module_moysklad_sync_price_type_id'] ?? ''));
        $settings['module_moysklad_sync_price_type_name'] = trim((string)($post['module_moysklad_sync_price_type_name'] ?? ''));
        $settings['module_moysklad_sync_purchase_orders_enabled'] = !empty($post['module_moysklad_sync_purchase_orders_enabled']) ? 1 : 0;
        $settings['module_moysklad_sync_purchase_order_state_ids'] = $this->normaliseStringArray($post['module_moysklad_sync_purchase_order_state_ids'] ?? []);
        $settings['module_moysklad_sync_incoming_quantity_mode'] = $this->normaliseEnum(
            $post['module_moysklad_sync_incoming_quantity_mode'] ?? 'zero',
            ['zero', 'expected'],
            'zero'
        );
        $settings['module_moysklad_sync_incoming_stock_status_id'] = max(0, (int)($post['module_moysklad_sync_incoming_stock_status_id'] ?? 0));
        $settings['module_moysklad_sync_include_incoming_in_site_quantity'] = !empty($post['module_moysklad_sync_include_incoming_in_site_quantity']) ? 1 : 0;
        // Старые режимы separate/merge_by_name оставлены в коде как legacy, но в
        // активной логике заказы поставщикам сопоставляются только по ID МойСклад.
        $settings['module_moysklad_sync_incoming_product_match_mode'] = 'by_moysklad_id';

        $settings['module_moysklad_sync_missing_product_action'] = $this->normaliseEnum(
            $post['module_moysklad_sync_missing_product_action'] ?? 'disable',
            ['none', 'disable', 'delete'],
            'disable'
        );

        $settings['module_moysklad_sync_missing_category_action'] = $this->normaliseEnum(
            $post['module_moysklad_sync_missing_category_action'] ?? 'disable',
            ['none', 'disable', 'delete'],
            'disable'
        );

        $settings['module_moysklad_sync_zero_stock_action'] = $this->normaliseEnum(
            $post['module_moysklad_sync_zero_stock_action'] ?? 'disable',
            ['none', 'disable', 'delete'],
            'disable'
        );

        $settings['module_moysklad_sync_seo_mode'] = $this->normaliseEnum(
            $post['module_moysklad_sync_seo_mode'] ?? 'new_only',
            ['new_only'],
            'new_only'
        );

        $settings['module_moysklad_sync_log_level'] = $this->normaliseEnum(
            $post['module_moysklad_sync_log_level'] ?? 'warning',
            ['warning', 'info', 'debug'],
            'warning'
        );

        $settings['module_moysklad_sync_clear_empty_description'] = !empty($post['module_moysklad_sync_clear_empty_description']) ? 1 : 0;
        $settings['module_moysklad_sync_api_debug_enabled'] = !empty($post['module_moysklad_sync_api_debug_enabled']) ? 1 : 0;

        $settings['module_moysklad_sync_category_batch_size'] = max(1, (int)($post['module_moysklad_sync_category_batch_size'] ?? 30));
        $settings['module_moysklad_sync_product_batch_size'] = max(1, (int)($post['module_moysklad_sync_product_batch_size'] ?? 20));
        $settings['module_moysklad_sync_stock_batch_size'] = max(1, (int)($post['module_moysklad_sync_stock_batch_size'] ?? 50));
        $settings['module_moysklad_sync_image_batch_size'] = max(1, (int)($post['module_moysklad_sync_image_batch_size'] ?? 3));
        $settings['module_moysklad_sync_max_images_per_product'] = max(1, (int)($post['module_moysklad_sync_max_images_per_product'] ?? 5));
        $settings['module_moysklad_sync_max_image_bytes'] = max(1048576, (int)($post['module_moysklad_sync_max_image_bytes'] ?? 10485760));

        return $settings;
    }

    private function validateSettings(array $settings): bool {
        if ($settings['module_moysklad_sync_category_batch_size'] < 1 || $settings['module_moysklad_sync_category_batch_size'] > 500) {
            $this->error['category_batch'] = $this->language->get('error_batch_category');
        }

        if ($settings['module_moysklad_sync_product_batch_size'] < 1 || $settings['module_moysklad_sync_product_batch_size'] > 200) {
            $this->error['product_batch'] = $this->language->get('error_batch_product');
        }

        if ($settings['module_moysklad_sync_stock_batch_size'] < 1 || $settings['module_moysklad_sync_stock_batch_size'] > 500) {
            $this->error['stock_batch'] = $this->language->get('error_batch_stock');
        }

        if ($settings['module_moysklad_sync_image_batch_size'] < 1 || $settings['module_moysklad_sync_image_batch_size'] > 50) {
            $this->error['image_batch'] = $this->language->get('error_batch_image');
        }

        if ($settings['module_moysklad_sync_max_images_per_product'] < 1 || $settings['module_moysklad_sync_max_images_per_product'] > 20) {
            $this->error['max_images_per_product'] = $this->language->get('error_max_images_per_product');
        }

        if ($settings['module_moysklad_sync_max_image_bytes'] < 1048576 || $settings['module_moysklad_sync_max_image_bytes'] > 31457280) {
            $this->error['max_image_bytes'] = $this->language->get('error_max_image_bytes');
        }

        return !$this->error;
    }


    private function normaliseStringArray(mixed $value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            $string = trim((string)$item);

            if ($string !== '') {
                $result[$string] = $string;
            }
        }

        return array_values($result);
    }

    private function normaliseEnum(mixed $value, array $allowed, string $default): string {
        $value = (string)$value;

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function getDefaultSettings(): array {
        return [
            'module_moysklad_sync_status' => 0,
            'module_moysklad_sync_api_token' => '',
            'module_moysklad_sync_warehouse_id' => '',
            'module_moysklad_sync_warehouse_name' => '',
            'module_moysklad_sync_warehouse_ids' => [],
            'module_moysklad_sync_warehouse_names' => [],
            'module_moysklad_sync_price_type_id' => '',
            'module_moysklad_sync_price_type_name' => '',
            'module_moysklad_sync_purchase_orders_enabled' => 0,
            'module_moysklad_sync_purchase_order_state_ids' => [],
            'module_moysklad_sync_incoming_quantity_mode' => 'zero',
            'module_moysklad_sync_incoming_stock_status_id' => (int)$this->config->get('config_stock_status_id'),
            'module_moysklad_sync_include_incoming_in_site_quantity' => 1,
            'module_moysklad_sync_incoming_product_match_mode' => 'by_moysklad_id',
            'module_moysklad_sync_missing_product_action' => 'disable',
            'module_moysklad_sync_missing_category_action' => 'disable',
            'module_moysklad_sync_zero_stock_action' => 'disable',
            'module_moysklad_sync_seo_mode' => 'new_only',
            'module_moysklad_sync_clear_empty_description' => 1,
            'module_moysklad_sync_category_batch_size' => 30,
            'module_moysklad_sync_product_batch_size' => 20,
            'module_moysklad_sync_stock_batch_size' => 50,
            'module_moysklad_sync_image_batch_size' => 3,
            'module_moysklad_sync_max_images_per_product' => 5,
            'module_moysklad_sync_max_image_bytes' => 10485760,
            'module_moysklad_sync_log_level' => 'warning',
            'module_moysklad_sync_api_debug_enabled' => 0
        ];
    }

    private function createMoyskladClientFromRequest(): MoyskladClient {
        $this->loadMoyskladLibraries();

        $postedToken = trim((string)($this->request->post['module_moysklad_sync_api_token'] ?? ''));
        $token = $postedToken !== '' ? $postedToken : (string)$this->config->get('module_moysklad_sync_api_token');

        $http = new HttpClient($token, 'https://api.moysklad.ru/api/remap/1.2', 20, 5, 2, (bool)$this->config->get('module_moysklad_sync_api_debug_enabled'));

        return new MoyskladClient($http);
    }

    private function createMoyskladClientFromSettings(array $settings): MoyskladClient {
        $this->loadMoyskladLibraries();

        $http = new HttpClient((string)($settings['module_moysklad_sync_api_token'] ?? ''), 'https://api.moysklad.ru/api/remap/1.2', 20, 5, 2, !empty($settings['module_moysklad_sync_api_debug_enabled']));

        return new MoyskladClient($http);
    }

    private function loadMoyskladLibraries(): void {
        // В OpenCart/ocStore 4 расширение распаковывается не в корень сайта,
        // а в каталог extension/moysklad_sync/. Поэтому свои сервисные классы
        // подключаем относительно DIR_EXTENSION, а не DIR_SYSTEM.
        // Это ключевой момент: иначе модуль может отображаться, но падать при
        // проверке подключения или запуске синхронизации.
        $extension_dir = defined('DIR_EXTENSION') ? DIR_EXTENSION : dirname(DIR_APPLICATION) . '/extension/';
        $library_path = $extension_dir . 'moysklad_sync/system/library/moysklad_sync/';

        require_once $library_path . 'ApiException.php';
        require_once $library_path . 'HttpClient.php';
        require_once $library_path . 'MoyskladClient.php';
        require_once $library_path . 'CategorySyncService.php';
        require_once $library_path . 'ProductSyncService.php';
        require_once $library_path . 'StockSyncService.php';
        require_once $library_path . 'ImageSyncService.php';
        require_once $library_path . 'PurchaseOrderSyncService.php';
        require_once $library_path . 'TaskRunner.php';
    }

    private function formatApiError(ApiException $e): string {
        $parts = [];

        if ($e->getHttpStatus() > 0) {
            $parts[] = 'HTTP ' . $e->getHttpStatus();
        }

        if ($e->getApiCode()) {
            $parts[] = 'код ' . $e->getApiCode();
        }

        $prefix = $parts ? '[' . implode(', ', $parts) . '] ' : '';

        return $prefix . $e->getMessage();
    }


    private function buildDiagnostics(): array {
        $userGroupId = isset($this->user) && method_exists($this->user, 'getGroupId') ? (int)$this->user->getGroupId() : 0;
        $warehouseIds = $this->getConfiguredWarehouseIds();
        $apiLog = $this->getApiDebugLogPath();
        $storageLogsDir = dirname($apiLog);
        $imageDir = defined('DIR_IMAGE') ? rtrim((string)DIR_IMAGE, '/\\') . '/' : '';
        $extensionDir = defined('DIR_EXTENSION') ? rtrim((string)DIR_EXTENSION, '/\\') . '/moysklad_sync/' : '';

        $latestTask = [];
        $recentLogs = [];

        try {
            $this->loadTaskModel();
            $latestTask = $this->model_extension_moysklad_sync_module_moysklad_task->getLatestTask() ?: [];
            $recentLogs = $this->model_extension_moysklad_sync_module_moysklad_task->getRecentLogs(1);
        } catch (\Throwable $e) {
            $latestTask = [];
            $recentLogs = [[
                'level' => 'error',
                'entity_type' => 'diagnostics',
                'message' => $e->getMessage(),
                'created_at' => date('Y-m-d H:i:s')
            ]];
        }

        return [
            'version' => self::VERSION,
            'module_enabled' => (bool)$this->config->get('module_moysklad_sync_status'),
            'api_token_set' => trim((string)$this->config->get('module_moysklad_sync_api_token')) !== '',
            'price_type_set' => trim((string)$this->config->get('module_moysklad_sync_price_type_id')) !== '',
            'warehouses_count' => count($warehouseIds),
            'purchase_orders_enabled' => (bool)$this->config->get('module_moysklad_sync_purchase_orders_enabled'),
            'purchase_order_states_count' => count($this->normaliseStringArray($this->config->get('module_moysklad_sync_purchase_order_state_ids') ?? [])),
            'api_debug_enabled' => (bool)$this->config->get('module_moysklad_sync_api_debug_enabled'),
            'dashboard_extension' => $this->extensionInstalled('dashboard', 'moysklad_sync'),
            'dashboard_status' => (int)$this->getSettingValue('dashboard_moysklad_sync_status', 0),
            'dashboard_width' => (int)$this->getSettingValue('dashboard_moysklad_sync_width', 0),
            'dashboard_sort_order' => (int)$this->getSettingValue('dashboard_moysklad_sync_sort_order', 0),
            'permission_module_access' => $this->user->hasPermission('access', 'extension/moysklad_sync/module/moysklad_sync'),
            'permission_module_modify' => $this->user->hasPermission('modify', 'extension/moysklad_sync/module/moysklad_sync'),
            'permission_dashboard_access' => $this->user->hasPermission('access', 'extension/moysklad_sync/dashboard/moysklad_sync'),
            'current_user_group_id' => $userGroupId,
            'curl_available' => function_exists('curl_init'),
            'json_available' => function_exists('json_decode'),
            'image_dir_writable' => $imageDir !== '' && is_dir($imageDir) && is_writable($imageDir),
            'storage_logs_writable' => is_dir($storageLogsDir) && is_writable($storageLogsDir),
            'extension_dir_found' => $extensionDir !== '' && is_dir($extensionDir),
            'api_debug_log_exists' => is_file($apiLog),
            'api_debug_log_size' => is_file($apiLog) ? (int)filesize($apiLog) : 0,
            'latest_task' => isset($latestTask['task_id']) ? $this->formatTask($latestTask) : [],
            'latest_log' => $recentLogs[0] ?? []
        ];
    }

    private function extensionInstalled(string $type, string $code): bool {
        try {
            $query = $this->db->query("SELECT `extension_id` FROM `" . DB_PREFIX . "extension` WHERE `type` = '" . $this->db->escape($type) . "' AND `code` = '" . $this->db->escape($code) . "' LIMIT 1");
            return (bool)$query->num_rows;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getSettingValue(string $key, mixed $default = ''): mixed {
        try {
            $query = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = '" . $this->db->escape($key) . "' LIMIT 1");
            return $query->num_rows ? $query->row['value'] : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function getApiDebugLogPath(): string {
        $directory = defined('DIR_STORAGE') ? rtrim((string)DIR_STORAGE, '/\\') . '/logs/' : rtrim(sys_get_temp_dir(), '/\\') . '/';
        return $directory . 'moysklad_sync_api_debug.log';
    }

    private function sendJson(array $json): void {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE));
    }
}
