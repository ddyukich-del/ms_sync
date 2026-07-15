<?php
namespace Opencart\Admin\Model\Extension\MoyskladSync\Module;

/**
 * @author d_dyuk
 */

class MoyskladSync extends \Opencart\System\Engine\Model {
    public function install(): void {
        // install() вызывается установщиком OpenCart/ocStore.
        // Важно: CREATE TABLE IF NOT EXISTS не добавляет новые колонки в уже существующие
        // таблицы, поэтому после создания таблиц всегда запускаем мягкие миграции.
        $this->ensureSchema();
    }

    public function uninstall(): void {
        // В коммерческом режиме данные синхронизации не удаляем автоматически.
        // Это защищает связи товаров/категорий и историю задач при случайном uninstall.
        // Физическое удаление таблиц лучше делать отдельной сервисной кнопкой с подтверждением.
    }


    /**
     * Создает и обновляет служебные таблицы модуля.
     *
     * Этот метод нужен не только при первой установке, но и при обновлении архива
     * поверх предыдущей версии. На живом сайте таблицы уже могут существовать,
     * и обычный CREATE TABLE IF NOT EXISTS их не изменит. Поэтому ниже есть
     * аккуратные ALTER TABLE, которые добавляют только отсутствующие колонки/индексы.
     */
    public function ensureSchema(): void {
        $this->createProductLinkTable();
        $this->createCategoryLinkTable();
        $this->createImageLinkTable();
        $this->createTaskTable();
        $this->createLogTable();
        $this->createErrorTable();

        $this->migrateProductLinkTable();
        $this->migrateCategoryLinkTable();
        $this->migrateImageLinkTable();
        $this->migrateTaskTable();
        $this->migrateLogTable();
        $this->migrateErrorTable();
    }

    private function migrateProductLinkTable(): void {
        $table = DB_PREFIX . 'moysklad_product_link';

        $this->addColumnIfMissing($table, 'moysklad_href', "`moysklad_href` VARCHAR(255) DEFAULT NULL AFTER `moysklad_id`");
        $this->addColumnIfMissing($table, 'external_code', "`external_code` VARCHAR(128) DEFAULT NULL AFTER `moysklad_href`");
        $this->addColumnIfMissing($table, 'article', "`article` VARCHAR(128) DEFAULT NULL AFTER `external_code`");
        $this->addColumnIfMissing($table, 'name_key', "`name_key` VARCHAR(191) DEFAULT NULL AFTER `article`");
        $this->addColumnIfMissing($table, 'sync_source', "`sync_source` VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER `name_key`");
        $this->addColumnIfMissing($table, 'incoming_quantity', "`incoming_quantity` DECIMAL(15,4) DEFAULT NULL AFTER `sync_source`");
        $this->addColumnIfMissing($table, 'purchase_order_id', "`purchase_order_id` VARCHAR(64) DEFAULT NULL AFTER `incoming_quantity`");
        $this->addColumnIfMissing($table, 'purchase_order_name', "`purchase_order_name` VARCHAR(128) DEFAULT NULL AFTER `purchase_order_id`");
        $this->addColumnIfMissing($table, 'purchase_order_state_id', "`purchase_order_state_id` VARCHAR(128) DEFAULT NULL AFTER `purchase_order_name`");
        $this->addColumnIfMissing($table, 'purchase_order_state_name', "`purchase_order_state_name` VARCHAR(128) DEFAULT NULL AFTER `purchase_order_state_id`");
        $this->addColumnIfMissing($table, 'last_stock_quantity', "`last_stock_quantity` DECIMAL(15,4) DEFAULT NULL AFTER `purchase_order_state_name`");
        $this->addColumnIfMissing($table, 'last_hash', "`last_hash` CHAR(64) DEFAULT NULL AFTER `last_stock_quantity`");
        $this->addColumnIfMissing($table, 'last_seen_task_id', "`last_seen_task_id` INT UNSIGNED DEFAULT NULL AFTER `last_hash`");
        $this->addColumnIfMissing($table, 'last_seen_at', "`last_seen_at` DATETIME DEFAULT NULL AFTER `last_seen_task_id`");
        $this->addColumnIfMissing($table, 'last_synced_at', "`last_synced_at` DATETIME DEFAULT NULL AFTER `last_seen_at`");
        $this->addColumnIfMissing($table, 'created_at', "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `last_synced_at`");
        $this->addColumnIfMissing($table, 'updated_at', "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

        $this->addIndexIfMissing($table, 'idx_last_seen_task', "KEY `idx_last_seen_task` (`last_seen_task_id`)");
        $this->addIndexIfMissing($table, 'idx_last_seen_at', "KEY `idx_last_seen_at` (`last_seen_at`)");
        $this->addIndexIfMissing($table, 'idx_article', "KEY `idx_article` (`article`)");
        $this->addIndexIfMissing($table, 'idx_external_code', "KEY `idx_external_code` (`external_code`)");
        $this->addIndexIfMissing($table, 'idx_name_key', "KEY `idx_name_key` (`name_key`)");
        $this->addIndexIfMissing($table, 'idx_sync_source', "KEY `idx_sync_source` (`sync_source`)");
        $this->addIndexIfMissing($table, 'idx_purchase_order_state', "KEY `idx_purchase_order_state` (`purchase_order_state_id`)");
    }

    private function migrateCategoryLinkTable(): void {
        $table = DB_PREFIX . 'moysklad_category_link';

        $this->addColumnIfMissing($table, 'moysklad_parent_id', "`moysklad_parent_id` VARCHAR(64) DEFAULT NULL AFTER `moysklad_id`");
        $this->addColumnIfMissing($table, 'last_hash', "`last_hash` CHAR(64) DEFAULT NULL AFTER `moysklad_parent_id`");
        $this->addColumnIfMissing($table, 'last_seen_task_id', "`last_seen_task_id` INT UNSIGNED DEFAULT NULL AFTER `last_hash`");
        $this->addColumnIfMissing($table, 'last_seen_at', "`last_seen_at` DATETIME DEFAULT NULL AFTER `last_seen_task_id`");
        $this->addColumnIfMissing($table, 'last_synced_at', "`last_synced_at` DATETIME DEFAULT NULL AFTER `last_seen_at`");
        $this->addColumnIfMissing($table, 'created_at', "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `last_synced_at`");
        $this->addColumnIfMissing($table, 'updated_at', "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

        // Именно отсутствие этой колонки вызвало ошибку Unknown column last_seen_task_id.
        // Миграция выше добавляет ее без удаления уже сохраненных связей категорий.
        $this->addIndexIfMissing($table, 'idx_last_seen_task', "KEY `idx_last_seen_task` (`last_seen_task_id`)");
        $this->addIndexIfMissing($table, 'idx_last_seen_at', "KEY `idx_last_seen_at` (`last_seen_at`)");
        $this->addIndexIfMissing($table, 'idx_parent_id', "KEY `idx_parent_id` (`moysklad_parent_id`)");
    }

    private function migrateImageLinkTable(): void {
        $table = DB_PREFIX . 'moysklad_image_link';

        $this->addColumnIfMissing($table, 'file_hash', "`file_hash` CHAR(64) DEFAULT NULL AFTER `local_path`");
        $this->addColumnIfMissing($table, 'file_size', "`file_size` INT UNSIGNED DEFAULT NULL AFTER `file_hash`");
        $this->addColumnIfMissing($table, 'is_main', "`is_main` TINYINT(1) NOT NULL DEFAULT 0 AFTER `file_size`");
        $this->addColumnIfMissing($table, 'last_seen_task_id', "`last_seen_task_id` INT UNSIGNED DEFAULT NULL AFTER `is_main`");
        $this->addColumnIfMissing($table, 'created_at', "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `last_seen_task_id`");
        $this->addColumnIfMissing($table, 'updated_at', "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

        $this->addIndexIfMissing($table, 'idx_product_id', "KEY `idx_product_id` (`product_id`)");
        $this->addIndexIfMissing($table, 'idx_moysklad_product_id', "KEY `idx_moysklad_product_id` (`moysklad_product_id`)");
        $this->addIndexIfMissing($table, 'idx_last_seen_task', "KEY `idx_last_seen_task` (`last_seen_task_id`)");
    }

    private function migrateTaskTable(): void {
        $table = DB_PREFIX . 'moysklad_sync_task';

        $this->addColumnIfMissing($table, 'attempts', "`attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `error_items`");
        $this->addColumnIfMissing($table, 'created_at', "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `finished_at`");
        $this->addColumnIfMissing($table, 'updated_at', "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

        $this->addIndexIfMissing($table, 'idx_type_status', "KEY `idx_type_status` (`task_type`, `status`)");
        $this->addIndexIfMissing($table, 'idx_status_updated', "KEY `idx_status_updated` (`status`, `updated_at`)");
        $this->addIndexIfMissing($table, 'idx_locked_until', "KEY `idx_locked_until` (`locked_until`)");
        $this->addIndexIfMissing($table, 'idx_created_at', "KEY `idx_created_at` (`created_at`)");
    }

    private function migrateLogTable(): void {
        $table = DB_PREFIX . 'moysklad_sync_log';

        $this->addColumnIfMissing($table, 'context', "`context` MEDIUMTEXT DEFAULT NULL AFTER `message`");
        $this->addColumnIfMissing($table, 'created_at', "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `context`");

        $this->addIndexIfMissing($table, 'idx_task_id', "KEY `idx_task_id` (`task_id`)");
        $this->addIndexIfMissing($table, 'idx_level', "KEY `idx_level` (`level`)");
        $this->addIndexIfMissing($table, 'idx_entity', "KEY `idx_entity` (`entity_type`, `entity_id`)");
        $this->addIndexIfMissing($table, 'idx_created_at', "KEY `idx_created_at` (`created_at`)");
    }

    private function migrateErrorTable(): void {
        $table = DB_PREFIX . 'moysklad_sync_error';

        $this->addColumnIfMissing($table, 'payload', "`payload` MEDIUMTEXT DEFAULT NULL AFTER `error_message`");
        $this->addColumnIfMissing($table, 'created_at', "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `payload`");

        $this->addIndexIfMissing($table, 'idx_task_id', "KEY `idx_task_id` (`task_id`)");
        $this->addIndexIfMissing($table, 'idx_entity', "KEY `idx_entity` (`entity_type`, `entity_id`)");
        $this->addIndexIfMissing($table, 'idx_created_at', "KEY `idx_created_at` (`created_at`)");
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void {
        if (!$this->tableExists($table) || $this->columnExists($table, $column)) {
            return;
        }

        $this->db->query("ALTER TABLE `" . $this->db->escape($table) . "` ADD COLUMN " . $definition);
    }

    private function addIndexIfMissing(string $table, string $index, string $definition): void {
        if (!$this->tableExists($table) || $this->indexExists($table, $index)) {
            return;
        }

        $this->db->query("ALTER TABLE `" . $this->db->escape($table) . "` ADD " . $definition);
    }

    private function tableExists(string $table): bool {
        $query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($table) . "'");

        return (bool)$query->num_rows;
    }

    private function columnExists(string $table, string $column): bool {
        $query = $this->db->query("SHOW COLUMNS FROM `" . $this->db->escape($table) . "` LIKE '" . $this->db->escape($column) . "'");

        return (bool)$query->num_rows;
    }

    private function indexExists(string $table, string $index): bool {
        $query = $this->db->query("SHOW INDEX FROM `" . $this->db->escape($table) . "` WHERE `Key_name` = '" . $this->db->escape($index) . "'");

        return (bool)$query->num_rows;
    }

    private function createProductLinkTable(): void {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "moysklad_product_link` (
            `link_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` INT UNSIGNED NOT NULL,
            `moysklad_id` VARCHAR(64) NOT NULL,
            `moysklad_href` VARCHAR(255) DEFAULT NULL,
            `external_code` VARCHAR(128) DEFAULT NULL,
            `article` VARCHAR(128) DEFAULT NULL,
            `name_key` VARCHAR(191) DEFAULT NULL,
            `sync_source` VARCHAR(32) NOT NULL DEFAULT 'unknown',
            `incoming_quantity` DECIMAL(15,4) DEFAULT NULL,
            `purchase_order_id` VARCHAR(64) DEFAULT NULL,
            `purchase_order_name` VARCHAR(128) DEFAULT NULL,
            `purchase_order_state_id` VARCHAR(128) DEFAULT NULL,
            `purchase_order_state_name` VARCHAR(128) DEFAULT NULL,
            `last_stock_quantity` DECIMAL(15,4) DEFAULT NULL,
            `last_hash` CHAR(64) DEFAULT NULL,
            `last_seen_task_id` INT UNSIGNED DEFAULT NULL,
            `last_seen_at` DATETIME DEFAULT NULL,
            `last_synced_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`link_id`),
            UNIQUE KEY `uq_moysklad_product_id` (`moysklad_id`),
            UNIQUE KEY `uq_product_id` (`product_id`),
            KEY `idx_article` (`article`),
            KEY `idx_external_code` (`external_code`),
            KEY `idx_name_key` (`name_key`),
            KEY `idx_sync_source` (`sync_source`),
            KEY `idx_purchase_order_state` (`purchase_order_state_id`),
            KEY `idx_last_seen_task` (`last_seen_task_id`),
            KEY `idx_last_seen_at` (`last_seen_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createCategoryLinkTable(): void {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "moysklad_category_link` (
            `link_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_id` INT UNSIGNED NOT NULL,
            `moysklad_id` VARCHAR(64) NOT NULL,
            `moysklad_parent_id` VARCHAR(64) DEFAULT NULL,
            `last_hash` CHAR(64) DEFAULT NULL,
            `last_seen_task_id` INT UNSIGNED DEFAULT NULL,
            `last_seen_at` DATETIME DEFAULT NULL,
            `last_synced_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`link_id`),
            UNIQUE KEY `uq_moysklad_category_id` (`moysklad_id`),
            UNIQUE KEY `uq_category_id` (`category_id`),
            KEY `idx_parent_id` (`moysklad_parent_id`),
            KEY `idx_last_seen_task` (`last_seen_task_id`),
            KEY `idx_last_seen_at` (`last_seen_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createImageLinkTable(): void {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "moysklad_image_link` (
            `image_link_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` INT UNSIGNED NOT NULL,
            `moysklad_product_id` VARCHAR(64) NOT NULL,
            `moysklad_image_id` VARCHAR(128) NOT NULL,
            `moysklad_image_url` TEXT DEFAULT NULL,
            `local_path` VARCHAR(255) NOT NULL,
            `file_hash` CHAR(64) DEFAULT NULL,
            `file_size` INT UNSIGNED DEFAULT NULL,
            `is_main` TINYINT(1) NOT NULL DEFAULT 0,
            `last_seen_task_id` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`image_link_id`),
            UNIQUE KEY `uq_moysklad_product_image` (`moysklad_product_id`, `moysklad_image_id`),
            KEY `idx_product_id` (`product_id`),
            KEY `idx_moysklad_product_id` (`moysklad_product_id`),
            KEY `idx_last_seen_task` (`last_seen_task_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createTaskTable(): void {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "moysklad_sync_task` (
            `task_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `task_type` VARCHAR(32) NOT NULL,
            `status` VARCHAR(32) NOT NULL,
            `current_step` VARCHAR(64) NOT NULL DEFAULT 'init',
            `offset_value` INT UNSIGNED NOT NULL DEFAULT 0,
            `limit_value` INT UNSIGNED NOT NULL DEFAULT 20,
            `total_items` INT UNSIGNED NOT NULL DEFAULT 0,
            `processed_items` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_items` INT UNSIGNED NOT NULL DEFAULT 0,
            `updated_items` INT UNSIGNED NOT NULL DEFAULT 0,
            `skipped_items` INT UNSIGNED NOT NULL DEFAULT 0,
            `deleted_items` INT UNSIGNED NOT NULL DEFAULT 0,
            `disabled_items` INT UNSIGNED NOT NULL DEFAULT 0,
            `error_items` INT UNSIGNED NOT NULL DEFAULT 0,
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `payload` MEDIUMTEXT DEFAULT NULL,
            `last_error` TEXT DEFAULT NULL,
            `locked_until` DATETIME DEFAULT NULL,
            `started_at` DATETIME DEFAULT NULL,
            `finished_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`task_id`),
            KEY `idx_type_status` (`task_type`, `status`),
            KEY `idx_status_updated` (`status`, `updated_at`),
            KEY `idx_locked_until` (`locked_until`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createLogTable(): void {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "moysklad_sync_log` (
            `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `task_id` INT UNSIGNED DEFAULT NULL,
            `level` VARCHAR(16) NOT NULL,
            `entity_type` VARCHAR(32) DEFAULT NULL,
            `entity_id` VARCHAR(128) DEFAULT NULL,
            `message` TEXT NOT NULL,
            `context` MEDIUMTEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`log_id`),
            KEY `idx_task_id` (`task_id`),
            KEY `idx_level` (`level`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createErrorTable(): void {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "moysklad_sync_error` (
            `error_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `task_id` INT UNSIGNED DEFAULT NULL,
            `entity_type` VARCHAR(32) DEFAULT NULL,
            `entity_id` VARCHAR(128) DEFAULT NULL,
            `error_code` VARCHAR(64) DEFAULT NULL,
            `error_message` TEXT NOT NULL,
            `payload` MEDIUMTEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`error_id`),
            KEY `idx_task_id` (`task_id`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
