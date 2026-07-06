<?php
namespace Opencart\Catalog\Controller\Extension\MoyskladSync\Cron;

use MoyskladSync\ApiException;
use MoyskladSync\HttpClient;
use MoyskladSync\MoyskladClient;
use MoyskladSync\CategorySyncService;
use MoyskladSync\ProductSyncService;
use MoyskladSync\StockSyncService;
use MoyskladSync\ImageSyncService;
use MoyskladSync\PurchaseOrderSyncService;
use MoyskladSync\TaskRunner;

/**
 * Public cron endpoint for Moysklad Sync.
 *
 * Route: index.php?route=extension/moysklad_sync/cron/moysklad_sync&token=SECRET
 *
 * This controller intentionally lives on the catalog side, because hosting cron
 * usually calls the public site URL without an admin session/user_token. Access is
 * protected by a separate random cron token stored in module settings.
 */
class MoyskladSync extends \Opencart\System\Engine\Controller {
    private const SETTING_CODE = 'module_moysklad_sync';

    public function index(): void {
        $result = [
            'ok' => false,
            'created_task' => false,
            'ran_steps' => 0,
            'messages' => []
        ];

        try {
            $settings = $this->getSettings();

            $token = trim((string)($this->request->get['token'] ?? ''));
            $expectedToken = trim((string)($settings['module_moysklad_sync_cron_token'] ?? ''));

            if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
                $this->sendJson(['ok' => false, 'error' => 'Invalid cron token'], 403);
                return;
            }

            if (empty($settings['module_moysklad_sync_status'])) {
                $this->sendJson(['ok' => false, 'error' => 'Module disabled']);
                return;
            }

            if (empty($settings['module_moysklad_sync_auto_sync_enabled'])) {
                $this->sendJson(['ok' => true, 'message' => 'Auto sync disabled']);
                return;
            }

            $this->loadAllDependencies();
            $models = $this->createModels();

            // Сначала продолжаем активную задачу. Если активной задачи нет — создаем
            // одну новую задачу, срок которой наступил. Одновременно выполняется только
            // одна задача: это защищает слабый хостинг и каталог от гонок.
            $activeTask = $models['task']->getActiveTask();

            if (!$activeTask) {
                $dueType = $this->getDueTaskType($settings);

                if ($dueType !== null) {
                    $this->assertTaskCanRun($dueType, $settings);
                    $snapshot = $settings;

                    // Категории в автоматическом импорте не синхронизируются: только
                    // ручной запуск с флажком на вкладке «Синхронизация».
                    if ($dueType === 'import') {
                        $snapshot['module_moysklad_sync_import_categories_enabled'] = 0;
                    }

                    $limit = $this->getLimitForTaskType($dueType, $settings);
                    $activeTask = $models['task']->createTask($dueType, $limit, [
                        'auto_sync' => true,
                        'settings_snapshot' => $this->getSafeSettingsSnapshot($snapshot)
                    ]);

                    $now = date('Y-m-d H:i:s');
                    $nextRunAt = $this->calculateNextRunAt($now, (int)$settings['module_moysklad_sync_auto_' . $dueType . '_interval_value'], (string)$settings['module_moysklad_sync_auto_' . $dueType . '_interval_unit']);
                    $this->setSettingValue('module_moysklad_sync_auto_' . $dueType . '_last_run_at', $now);
                    $this->setSettingValue('module_moysklad_sync_auto_' . $dueType . '_next_run_at', $nextRunAt);

                    $settings['module_moysklad_sync_auto_' . $dueType . '_last_run_at'] = $now;
                    $settings['module_moysklad_sync_auto_' . $dueType . '_next_run_at'] = $nextRunAt;

                    $result['created_task'] = true;
                    $result['messages'][] = 'Created task: ' . $dueType;
                }
            }

            if ($activeTask) {
                $maxSteps = max(1, min(20, (int)($settings['module_moysklad_sync_auto_max_steps_per_run'] ?? 3)));
                $maxRuntime = max(10, min(120, (int)($settings['module_moysklad_sync_auto_max_runtime_seconds'] ?? 25)));
                $startedAt = time();

                for ($i = 0; $i < $maxSteps; $i++) {
                    if ((time() - $startedAt) >= $maxRuntime) {
                        $result['messages'][] = 'Runtime limit reached';
                        break;
                    }

                    $task = $models['task']->getActiveTask();
                    if (!$task) {
                        break;
                    }

                    $ran = $this->runOneTaskStep($task, $settings, $models);
                    if (!$ran) {
                        $result['messages'][] = 'Task locked by another process';
                        break;
                    }

                    $result['ran_steps']++;
                }
            } else {
                $result['messages'][] = 'No due tasks';
            }

            $latest = $models['task']->getActiveTask() ?: $models['task']->getLatestTask();
            $result['ok'] = true;
            $result['task'] = $latest ? [
                'task_id' => (int)$latest['task_id'],
                'task_type' => (string)$latest['task_type'],
                'status' => (string)$latest['status'],
                'current_step' => (string)$latest['current_step'],
                'processed_items' => (int)$latest['processed_items'],
                'error_items' => (int)$latest['error_items']
            ] : null;

            $this->sendJson($result);
        } catch (ApiException $e) {
            $this->sendJson(['ok' => false, 'error' => $e->getMessage(), 'status_code' => $e->getStatusCode()]);
        } catch (\Throwable $e) {
            $this->log->write('Moysklad Sync cron error: ' . $e->getMessage());
            $this->sendJson(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function runOneTaskStep(array $task, array $settings, array $models): bool {
        $runner = new TaskRunner();
        $taskId = (int)$task['task_id'];

        if (!$models['task']->acquireTask($taskId, $runner->getLockTtlSeconds())) {
            return false;
        }

        try {
            $lockedTask = $models['task']->getTask($taskId) ?: $task;
            $effectiveSettings = $this->applyTaskSettingsSnapshot($settings, $lockedTask);
            $client = $this->createMoyskladClient($effectiveSettings);

            $categoryService = new CategorySyncService($client, $models['category'], $models['task']);
            $productService = new ProductSyncService($client, $models['product'], $models['task'], $categoryService);
            $stockService = new StockSyncService($client, $models['product'], $models['task']);
            $imageService = new ImageSyncService($client, $models['image'], $models['task']);
            $purchaseOrderService = new PurchaseOrderSyncService($client, $models['product'], $models['task'], $categoryService);

            $updatedTask = $runner->runOneStep($lockedTask, $models['task'], $effectiveSettings, [
                'category_service' => $categoryService,
                'product_service' => $productService,
                'stock_service' => $stockService,
                'image_service' => $imageService,
                'purchase_order_service' => $purchaseOrderService
            ]);

            if (($updatedTask['status'] ?? '') === 'running') {
                $models['task']->releaseTask($taskId);
            }

            return true;
        } catch (\Throwable $e) {
            $models['task']->failTask($taskId, $e->getMessage());
            return true;
        }
    }

    private function getDueTaskType(array $settings): ?string {
        // Приоритет: остатки чаще и легче, затем товары, затем картинки.
        foreach (['stock', 'import', 'images'] as $type) {
            if (empty($settings['module_moysklad_sync_auto_' . $type . '_enabled'])) {
                continue;
            }

            $nextRunAt = trim((string)($settings['module_moysklad_sync_auto_' . $type . '_next_run_at'] ?? ''));

            if ($nextRunAt === '' || strtotime($nextRunAt) <= time()) {
                return $type;
            }
        }

        return null;
    }

    private function assertTaskCanRun(string $type, array $settings): void {
        if (trim((string)($settings['module_moysklad_sync_api_token'] ?? '')) === '') {
            throw new \RuntimeException('API token is required');
        }

        if (in_array($type, ['import', 'stock'], true) && !$this->getConfiguredWarehouseIds($settings)) {
            throw new \RuntimeException('Warehouse is required');
        }

        if ($type === 'import' && trim((string)($settings['module_moysklad_sync_price_type_id'] ?? '')) === '') {
            throw new \RuntimeException('Price type is required');
        }
    }

    private function getLimitForTaskType(string $type, array $settings): int {
        return match ($type) {
            'import' => (int)($settings['module_moysklad_sync_product_batch_size'] ?? 20),
            'stock' => (int)($settings['module_moysklad_sync_stock_batch_size'] ?? 50),
            'images' => (int)($settings['module_moysklad_sync_image_batch_size'] ?? 3),
            default => 20
        };
    }

    private function getSettings(): array {
        $defaults = $this->getDefaultSettings();
        $settings = $defaults;

        $query = $this->db->query("SELECT `key`, `value`, `serialized` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = '" . self::SETTING_CODE . "'");

        foreach ($query->rows as $row) {
            $value = (string)$row['value'];
            if (!empty($row['serialized'])) {
                $decoded = json_decode($value, true);
                $settings[(string)$row['key']] = is_array($decoded) ? $decoded : $value;
            } else {
                $settings[(string)$row['key']] = $value;
            }
        }

        $settings['module_moysklad_sync_warehouse_ids'] = $this->normaliseStringArray($settings['module_moysklad_sync_warehouse_ids'] ?? []);
        if (!$settings['module_moysklad_sync_warehouse_ids'] && !empty($settings['module_moysklad_sync_warehouse_id'])) {
            $settings['module_moysklad_sync_warehouse_ids'] = [(string)$settings['module_moysklad_sync_warehouse_id']];
        }

        return $settings;
    }

    private function getDefaultSettings(): array {
        return [
            'module_moysklad_sync_status' => 0,
            'module_moysklad_sync_api_token' => '',
            'module_moysklad_sync_warehouse_id' => '',
            'module_moysklad_sync_warehouse_ids' => [],
            'module_moysklad_sync_price_type_id' => '',
            'module_moysklad_sync_purchase_orders_enabled' => 0,
            'module_moysklad_sync_purchase_order_state_ids' => [],
            'module_moysklad_sync_import_categories_enabled' => 0,
            'module_moysklad_sync_include_incoming_in_site_quantity' => 1,
            'module_moysklad_sync_incoming_stock_status_id' => 0,
            'module_moysklad_sync_missing_product_action' => 'disable',
            'module_moysklad_sync_zero_stock_action' => 'disable',
            'module_moysklad_sync_product_batch_size' => 20,
            'module_moysklad_sync_stock_batch_size' => 50,
            'module_moysklad_sync_image_batch_size' => 3,
            'module_moysklad_sync_max_images_per_product' => 5,
            'module_moysklad_sync_max_image_bytes' => 10485760,
            'module_moysklad_sync_api_debug_enabled' => 0,
            'module_moysklad_sync_cron_token' => '',
            'module_moysklad_sync_auto_sync_enabled' => 0,
            'module_moysklad_sync_auto_stock_enabled' => 1,
            'module_moysklad_sync_auto_stock_interval_value' => 5,
            'module_moysklad_sync_auto_stock_interval_unit' => 'minutes',
            'module_moysklad_sync_auto_stock_last_run_at' => '',
            'module_moysklad_sync_auto_stock_next_run_at' => '',
            'module_moysklad_sync_auto_import_enabled' => 1,
            'module_moysklad_sync_auto_import_interval_value' => 1,
            'module_moysklad_sync_auto_import_interval_unit' => 'hours',
            'module_moysklad_sync_auto_import_last_run_at' => '',
            'module_moysklad_sync_auto_import_next_run_at' => '',
            'module_moysklad_sync_auto_images_enabled' => 0,
            'module_moysklad_sync_auto_images_interval_value' => 1,
            'module_moysklad_sync_auto_images_interval_unit' => 'days',
            'module_moysklad_sync_auto_images_last_run_at' => '',
            'module_moysklad_sync_auto_images_next_run_at' => '',
            'module_moysklad_sync_auto_max_steps_per_run' => 3,
            'module_moysklad_sync_auto_max_runtime_seconds' => 25
        ];
    }

    private function createModels(): array {
        return [
            'task' => $this->newAdminModel('MoyskladTask'),
            'category' => $this->newAdminModel('MoyskladCategory'),
            'product' => $this->newAdminModel('MoyskladProduct'),
            'image' => $this->newAdminModel('MoyskladImage')
        ];
    }

    private function newAdminModel(string $classShortName): object {
        $class = '\\Opencart\\Admin\\Model\\Extension\\MoyskladSync\\Module\\' . $classShortName;
        return new $class($this->registry);
    }

    private function loadAllDependencies(): void {
        $extensionBase = defined('DIR_EXTENSION') ? rtrim(DIR_EXTENSION, '/\\') . '/moysklad_sync/' : DIR_OPENCART . 'extension/moysklad_sync/';

        foreach (['moysklad_task', 'moysklad_category', 'moysklad_product', 'moysklad_image'] as $model) {
            $file = $extensionBase . 'admin/model/module/' . $model . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }

        foreach (['ApiException', 'HttpClient', 'MoyskladClient', 'TaskRunner', 'CategorySyncService', 'ProductSyncService', 'StockSyncService', 'ImageSyncService', 'PurchaseOrderSyncService'] as $library) {
            $file = DIR_SYSTEM . 'library/moysklad_sync/' . $library . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    private function createMoyskladClient(array $settings): MoyskladClient {
        $http = new HttpClient((string)($settings['module_moysklad_sync_api_token'] ?? ''), 'https://api.moysklad.ru/api/remap/1.2', 20, 5, 2, !empty($settings['module_moysklad_sync_api_debug_enabled']));
        return new MoyskladClient($http);
    }

    private function applyTaskSettingsSnapshot(array $settings, array $task): array {
        $payload = json_decode((string)($task['payload'] ?? ''), true);
        if (!is_array($payload) || empty($payload['settings_snapshot']) || !is_array($payload['settings_snapshot'])) {
            return $settings;
        }
        foreach ($payload['settings_snapshot'] as $key => $value) {
            if ($key !== 'module_moysklad_sync_api_token') {
                $settings[$key] = $value;
            }
        }
        return $settings;
    }

    private function getSafeSettingsSnapshot(array $settings): array {
        unset($settings['module_moysklad_sync_api_token']);
        return $settings;
    }

    private function getConfiguredWarehouseIds(array $settings): array {
        $ids = $this->normaliseStringArray($settings['module_moysklad_sync_warehouse_ids'] ?? []);
        if (!$ids && !empty($settings['module_moysklad_sync_warehouse_id'])) {
            $ids[] = (string)$settings['module_moysklad_sync_warehouse_id'];
        }
        return $ids;
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

    private function calculateNextRunAt(string $from, int $value, string $unit): string {
        $value = max(1, $value);
        $date = new \DateTimeImmutable($from ?: 'now');

        $modifier = match ($unit) {
            'seconds' => '+' . max(30, $value) . ' seconds',
            'minutes' => '+' . $value . ' minutes',
            'hours' => '+' . $value . ' hours',
            'days' => '+' . $value . ' days',
            'months' => '+' . min(12, $value) . ' months',
            default => '+1 hour'
        };

        return $date->modify($modifier)->format('Y-m-d H:i:s');
    }

    private function setSettingValue(string $key, string $value): void {
        $query = $this->db->query("SELECT `setting_id` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = '" . $this->db->escape($key) . "' LIMIT 1");
        if ($query->num_rows) {
            $this->db->query("UPDATE `" . DB_PREFIX . "setting` SET `code` = '" . self::SETTING_CODE . "', `value` = '" . $this->db->escape($value) . "', `serialized` = '0' WHERE `setting_id` = '" . (int)$query->row['setting_id'] . "'");
        } else {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = '" . self::SETTING_CODE . "', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($value) . "', `serialized` = '0'");
        }
    }

    private function sendJson(array $json, int $statusCode = 200): void {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        if ($statusCode !== 200) {
            $this->response->addHeader('Status: ' . $statusCode);
        }
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE));
    }
}
