<?php
namespace Opencart\Admin\Controller\Extension\MoyskladSync\Dashboard;

/**
 * @author d_dyuk
 */

/**
 * Штатный dashboard-виджет МойСклад на главной странице админки.
 *
 * Важно: виджет регистрируется как расширение типа dashboard и выводится
 * стандартным common/dashboard без OCMOD и без правки файлов ядра CMS.
 */
class MoyskladSync extends \Opencart\System\Engine\Controller {
    /**
     * Страница настроек dashboard-расширения.
     *
     * У виджета нет отдельной формы: все рабочие настройки находятся на странице
     * основного модуля. Поэтому штатную кнопку "Изменить" в списке dashboard-
     * расширений мягко перенаправляем туда.
     */
    public function index(): void {
        $this->response->redirect($this->url->link('extension/moysklad_sync/module/moysklad_sync', 'user_token=' . ($this->session->data['user_token'] ?? '')));
    }

    /** Вызывается, если администратор устанавливает виджет через тип Dashboard. */
    public function install(): void {
        $this->ensureDashboardSettings(true);
        $this->grantCurrentAdminPermissions();
    }

    /** Вызывается, если администратор удаляет виджет через тип Dashboard. */
    public function uninstall(): void {
        try {
            $this->load->model('setting/setting');
            $this->model_setting_setting->deleteSetting('dashboard_moysklad_sync');
        } catch (\Throwable $e) {
            $this->log->write('Moysklad Sync dashboard uninstall error: ' . $e->getMessage());
        }
    }

    public function dashboard(): string {
        $data = $this->load->language('extension/moysklad_sync/dashboard/moysklad_sync');

        $summary = [
            'total_products' => 0,
            'stock_products' => 0,
            'incoming_products' => 0,
            'missing_products' => 0,
            'expected_quantity' => 0,
            'last_synced_at' => ''
        ];

        try {
            $this->load->model('extension/moysklad_sync/module/moysklad_product');
            $summary = array_merge($summary, $this->model_extension_moysklad_sync_module_moysklad_product->getDashboardSummary());
        } catch (\Throwable $e) {
            // Виджет на главной не должен ломать всю админку. Если таблицы еще не
            // созданы или идет обновление модуля, просто покажем нулевые счетчики.
            $this->log->write('Moysklad Sync dashboard summary error: ' . $e->getMessage());
        }

        $userToken = $this->session->data['user_token'] ?? '';

        $data = array_merge($data, $summary);
        $data['products_url'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', 'user_token=' . $userToken . '&tab=products');
        $data['sync_url'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', 'user_token=' . $userToken . '&tab=sync');
        $data['diagnostics_url'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', 'user_token=' . $userToken . '&tab=diagnostics');
        $data['settings_url'] = $this->url->link('extension/moysklad_sync/module/moysklad_sync', 'user_token=' . $userToken);

        return $this->load->view('extension/moysklad_sync/dashboard/moysklad_sync_info', $data);
    }

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

    private function grantCurrentAdminPermissions(): void {
        if (!isset($this->user) || !method_exists($this->user, 'getGroupId')) {
            return;
        }

        $userGroupId = (int)$this->user->getGroupId();
        $routes = [
            'extension/moysklad_sync/module/moysklad_sync',
            'extension/moysklad_sync/dashboard/moysklad_sync'
        ];

        foreach (['access', 'modify'] as $type) {
            foreach ($routes as $route) {
                $this->ensureUserGroupPermission($userGroupId, $type, $route);
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
}
