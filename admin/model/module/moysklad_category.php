<?php
namespace Opencart\Admin\Model\Extension\MoyskladSync\Module;

/**
 * @author d_dyuk
 */

/**
 * Модель работы с категориями ocStore.
 *
 * Здесь находится вся запись в базу: создание категории, обновление описания,
 * сохранение связи с ID МойСклад, перестроение category_path и безопасная
 * обработка категорий, которых больше нет в МойСклад.
 */
class MoyskladCategory extends \Opencart\System\Engine\Model {
    /**
     * Создает или обновляет категорию по данным группы товаров МойСклад.
     *
     * Главный идентификатор — moysklad_id. Название не используется для поиска,
     * потому что название можно поменять, а ID МойСклад остается стабильным.
     */
    public function upsertFromMoysklad(array $folder, int $taskId, array $settings): string {
        $moyskladId = trim((string)($folder['id'] ?? ''));
        $name = trim((string)($folder['name'] ?? ''));

        if ($moyskladId === '') {
            throw new \InvalidArgumentException('У категории МойСклад нет ID.');
        }

        if ($name === '') {
            throw new \InvalidArgumentException('У категории МойСклад ' . $moyskladId . ' пустое название.');
        }

        $description = (string)($folder['description'] ?? '');
        $status = ($folder['archived'] ?? false) === true ? 0 : 1;
        $parentMoyskladId = (string)($folder['parent_id'] ?? '');
        $hash = $this->makeHash([
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'parent_moysklad_id' => $parentMoyskladId,
        ]);

        $link = $this->getLinkByMoyskladId($moyskladId);

        if (!$link) {
            $categoryId = $this->createCategory($name, $description, $status);
            $this->saveLink($categoryId, $moyskladId, $parentMoyskladId, $hash, $taskId);

            return 'created';
        }

        $categoryId = (int)$link['category_id'];

        // Важная защита для живого магазина и тестовых прогонов:
        // связь с МойСклад может остаться в moysklad_category_link, но саму
        // категорию администратор мог удалить вручную из стандартного каталога.
        // В таком случае обычный UPDATE не даст SQL-ошибку, а просто обновит 0 строк,
        // и категория больше никогда не появится. Поэтому перед обновлением всегда
        // проверяем физическое наличие строки в oc_category.
        if (!$this->categoryExists($categoryId)) {
            $categoryId = $this->createCategory($name, $description, $status);
            $this->rebindLinkToCategory($moyskladId, $categoryId, $parentMoyskladId, $hash, $taskId);

            return 'created';
        }

        // last_seen обновляем всегда: даже если данные не изменились, эта категория
        // присутствует в текущей выгрузке МойСклад и не должна попасть в missing-step.
        $this->touchLink($moyskladId, $parentMoyskladId, $taskId);

        if ((string)$link['last_hash'] === $hash) {
            // Связь и хэш могут быть актуальными, но статус в oc_category мог
            // остаться неправильным после старой версии модуля или ручной правки.
            // Поэтому проверяем статус даже при skipped.
            $this->ensureCategoryStatus($categoryId, $status);
            return 'skipped';
        }

        $this->updateCategory($categoryId, $name, $description, $status);
        $this->updateLinkHash($moyskladId, $parentMoyskladId, $hash, $taskId);

        return 'updated';
    }

    /**
     * Перестраивает дерево категорий для связей, встреченных в текущей задаче.
     *
     * В качестве cursor используем category_id, а не служебный link_id. Это важная
     * совместимость с ранними установками модуля: у них таблица связей могла быть
     * создана без колонки link_id. category_id у нас уникален и подходит для
     * пакетной обработки без тяжелого OFFSET.
     */
    public function rebuildTreeForTask(int $taskId, int $lastLinkId, int $limit, array $settings): array {
        $limit = max(1, $limit);
        $stats = ['processed' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'last_cursor' => $lastLinkId, 'has_more' => false];

        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "moysklad_category_link`
            WHERE `last_seen_task_id` = '" . (int)$taskId . "'
              AND `category_id` > '" . (int)$lastLinkId . "'
            ORDER BY `category_id` ASC
            LIMIT " . (int)$limit);

        foreach ($query->rows as $link) {
            $stats['processed']++;
            $stats['last_cursor'] = (int)$link['category_id'];

            try {
                $categoryId = (int)$link['category_id'];
                $parentId = 0;

                if (!empty($link['moysklad_parent_id'])) {
                    $parentLink = $this->getLinkByMoyskladId((string)$link['moysklad_parent_id']);
                    $parentId = $parentLink ? (int)$parentLink['category_id'] : 0;
                }

                // Защита от некорректных данных: категория не может быть родителем самой себе.
                if ($parentId === $categoryId) {
                    $parentId = 0;
                }

                $currentParentId = $this->getCategoryParentId($categoryId);

                if ($currentParentId !== $parentId) {
                    $this->db->query("UPDATE `" . DB_PREFIX . "category` SET
                        `parent_id` = '" . (int)$parentId . "',
                        `date_modified` = NOW()
                        WHERE `category_id` = '" . (int)$categoryId . "'");
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }

                $this->rebuildCategoryPath($categoryId, $parentId);

                // SEO URL обновляем после построения дерева, потому что для категории
                // ocStore 4 использует полный path вида parent_child.
                if (($settings['module_moysklad_sync_seo_mode'] ?? 'new_only') === 'new_only') {
                    $this->ensureSeoUrl($categoryId);
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
            }
        }

        $stats['has_more'] = $query->num_rows === $limit;

        return $stats;
    }

    /**
     * Перестраивает одну категорию по связи с МойСклад.
     *
     * Используется новым импортом по выбранному складу: товар гарантирует свою
     * категорию и родителей, а затем мы сразу выставляем правильный parent_id,
     * category_path и SEO URL. Это позволяет не импортировать весь справочник
     * категорий МойСклад, а создавать только нужную ветку каталога.
     */
    public function rebuildOneByMoyskladId(string $moyskladId, array $settings): void {
        $link = $this->getLinkByMoyskladId($moyskladId);

        if (!$link) {
            return;
        }

        $categoryId = (int)$link['category_id'];

        if (!$this->categoryExists($categoryId)) {
            return;
        }

        $parentId = 0;
        $parentMoyskladId = trim((string)($link['moysklad_parent_id'] ?? ''));

        if ($parentMoyskladId !== '') {
            $parentLink = $this->getLinkByMoyskladId($parentMoyskladId);
            $candidateParentId = $parentLink ? (int)$parentLink['category_id'] : 0;

            if ($candidateParentId > 0 && $candidateParentId !== $categoryId && $this->categoryExists($candidateParentId)) {
                $parentId = $candidateParentId;
            }
        }

        $currentParentId = $this->getCategoryParentId($categoryId);

        if ($currentParentId !== $parentId) {
            $this->db->query("UPDATE `" . DB_PREFIX . "category` SET
                `parent_id` = '" . (int)$parentId . "',
                `date_modified` = NOW()
                WHERE `category_id` = '" . (int)$categoryId . "'");
        }

        $this->ensureStoreLink($categoryId);
        $this->rebuildCategoryPath($categoryId, $parentId);

        if (($settings['module_moysklad_sync_seo_mode'] ?? 'new_only') === 'new_only') {
            $this->ensureSeoUrl($categoryId);
        }
    }

    /**
     * Обрабатывает категории, которые не были встречены в текущей выгрузке.
     *
     * Важно: трогаем только категории, у которых есть связь с МойСклад. Ручные
     * категории магазина без связи модуль не отключает и не удаляет.
     */
    public function processMissingCategories(int $taskId, int $lastLinkId, int $limit, string $action): array {
        $limit = max(1, $limit);
        $stats = ['processed' => 0, 'disabled' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0, 'last_cursor' => $lastLinkId, 'has_more' => false];

        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "moysklad_category_link`
            WHERE (`last_seen_task_id` IS NULL OR `last_seen_task_id` <> '" . (int)$taskId . "')
              AND `category_id` > '" . (int)$lastLinkId . "'
            ORDER BY `category_id` ASC
            LIMIT " . (int)$limit);

        foreach ($query->rows as $link) {
            $stats['processed']++;
            $stats['last_cursor'] = (int)$link['category_id'];
            $categoryId = (int)$link['category_id'];

            try {
                // Если категорию удалили вручную из стандартного каталога, а связь
                // осталась, не пытаемся отключать несуществующую строку. Удаляем
                // осиротевшую связь: если такая категория снова понадобится товару
                // выбранного склада, она будет создана заново по ID МойСклад.
                if (!$this->categoryExists($categoryId)) {
                    $this->db->query("DELETE FROM `" . DB_PREFIX . "moysklad_category_link` WHERE `moysklad_id` = '" . $this->db->escape((string)$link['moysklad_id']) . "'");
                    $stats['skipped']++;
                    continue;
                }

                if ($action === 'delete') {
                    // Удаляем безопасно: если есть дочерние категории, сначала отключаем.
                    // Иначе можно оставить сиротские категории с parent_id на удаленный объект.
                    if ($this->hasChildCategories($categoryId)) {
                        $this->disableCategory($categoryId);
                        $stats['disabled']++;
                    } else {
                        $this->deleteCategory($categoryId);
                        $stats['deleted']++;
                    }
                } elseif ($action === 'disable') {
                    $this->disableCategory($categoryId);
                    $stats['disabled']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
            }
        }

        $stats['has_more'] = $query->num_rows === $limit;

        return $stats;
    }

    private function ensureCategoryStatus(int $categoryId, int $status): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "category` SET
            `status` = '" . (int)$status . "',
            `date_modified` = NOW()
            WHERE `category_id` = '" . (int)$categoryId . "'
              AND `status` <> '" . (int)$status . "'");
    }

    private function createCategory(string $name, string $description, int $status): int {
        // Создаем категорию максимально просто: одноименная группа товаров МойСклад
        // становится одноименной категорией ocStore. Важно: не пишем поля `top` и
        // `column` вслепую — в твоей сборке ocStore 4.1 этих колонок нет, из-за
        // чего импорт категорий падал с Unknown column 'top'.
        $fields = [];

        if ($this->columnExists('category', 'image')) {
            $fields[] = "`image` = ''";
        }

        if ($this->columnExists('category', 'parent_id')) {
            $fields[] = "`parent_id` = 0";
        }

        if ($this->columnExists('category', 'sort_order')) {
            $fields[] = "`sort_order` = 0";
        }

        if ($this->columnExists('category', 'status')) {
            $fields[] = "`status` = '" . (int)$status . "'";
        }

        if ($this->columnExists('category', 'date_added')) {
            $fields[] = "`date_added` = NOW()";
        }

        if ($this->columnExists('category', 'date_modified')) {
            $fields[] = "`date_modified` = NOW()";
        }

        if (!$fields) {
            throw new \RuntimeException('Не удалось создать категорию: таблица category не содержит ожидаемых колонок.');
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "category` SET " . implode(', ', $fields));

        $categoryId = (int)$this->db->getLastId();
        $languageId = $this->getRussianLanguageId();

        $this->db->query("INSERT INTO `" . DB_PREFIX . "category_description` SET
            `category_id` = '" . (int)$categoryId . "',
            `language_id` = '" . (int)$languageId . "',
            `name` = '" . $this->db->escape($name) . "',
            `description` = '" . $this->db->escape($description) . "',
            `meta_title` = '" . $this->db->escape($name) . "',
            `meta_description` = '',
            `meta_keyword` = ''");

        $this->ensureStoreLink($categoryId);
        $this->rebuildCategoryPath($categoryId, 0);

        return $categoryId;
    }

    private function updateCategory(int $categoryId, string $name, string $description, int $status): void {
        $languageId = $this->getRussianLanguageId();

        $this->db->query("UPDATE `" . DB_PREFIX . "category` SET
            `status` = '" . (int)$status . "',
            `date_modified` = NOW()
            WHERE `category_id` = '" . (int)$categoryId . "'");

        $exists = $this->db->query("SELECT `category_id` FROM `" . DB_PREFIX . "category_description`
            WHERE `category_id` = '" . (int)$categoryId . "'
              AND `language_id` = '" . (int)$languageId . "'
            LIMIT 1");

        if ($exists->num_rows) {
            $this->db->query("UPDATE `" . DB_PREFIX . "category_description` SET
                `name` = '" . $this->db->escape($name) . "',
                `description` = '" . $this->db->escape($description) . "',
                `meta_title` = '" . $this->db->escape($name) . "',
                `meta_description` = '',
                `meta_keyword` = ''
                WHERE `category_id` = '" . (int)$categoryId . "'
                  AND `language_id` = '" . (int)$languageId . "'");
        } else {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "category_description` SET
                `category_id` = '" . (int)$categoryId . "',
                `language_id` = '" . (int)$languageId . "',
                `name` = '" . $this->db->escape($name) . "',
                `description` = '" . $this->db->escape($description) . "',
                `meta_title` = '" . $this->db->escape($name) . "',
                `meta_description` = '',
                `meta_keyword` = ''");
        }

        $this->ensureStoreLink($categoryId);
    }

    private function getLinkByMoyskladId(string $moyskladId): ?array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "moysklad_category_link`
            WHERE `moysklad_id` = '" . $this->db->escape($moyskladId) . "'
            LIMIT 1");

        return $query->num_rows ? $query->row : null;
    }

    private function saveLink(int $categoryId, string $moyskladId, string $parentMoyskladId, string $hash, int $taskId): void {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "moysklad_category_link` SET
            `category_id` = '" . (int)$categoryId . "',
            `moysklad_id` = '" . $this->db->escape($moyskladId) . "',
            `moysklad_parent_id` = " . ($parentMoyskladId !== '' ? "'" . $this->db->escape($parentMoyskladId) . "'" : 'NULL') . ",
            `last_hash` = '" . $this->db->escape($hash) . "',
            `last_seen_task_id` = '" . (int)$taskId . "',
            `last_seen_at` = NOW(),
            `last_synced_at` = NOW(),
            `created_at` = NOW(),
            `updated_at` = NOW()");
    }

    /** Проверяет, существует ли категория в стандартной таблице oc_category. */
    private function categoryExists(int $categoryId): bool {
        if ($categoryId <= 0) {
            return false;
        }

        $query = $this->db->query("SELECT `category_id` FROM `" . DB_PREFIX . "category`
            WHERE `category_id` = '" . (int)$categoryId . "'
            LIMIT 1");

        return (bool)$query->num_rows;
    }

    /**
     * Перепривязывает существующую связь МойСклад к новой категории ocStore.
     *
     * Используется после ручного удаления категории из админки: связь в
     * moysklad_category_link еще есть, но старый category_id больше не существует.
     */
    private function rebindLinkToCategory(string $moyskladId, int $categoryId, string $parentMoyskladId, string $hash, int $taskId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_category_link` SET
            `category_id` = '" . (int)$categoryId . "',
            `moysklad_parent_id` = " . ($parentMoyskladId !== '' ? "'" . $this->db->escape($parentMoyskladId) . "'" : 'NULL') . ",
            `last_hash` = '" . $this->db->escape($hash) . "',
            `last_seen_task_id` = '" . (int)$taskId . "',
            `last_seen_at` = NOW(),
            `last_synced_at` = NOW(),
            `updated_at` = NOW()
            WHERE `moysklad_id` = '" . $this->db->escape((string)$moyskladId) . "'");
    }

    private function touchLink(string $moyskladId, string $parentMoyskladId, int $taskId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_category_link` SET
            `moysklad_parent_id` = " . ($parentMoyskladId !== '' ? "'" . $this->db->escape($parentMoyskladId) . "'" : 'NULL') . ",
            `last_seen_task_id` = '" . (int)$taskId . "',
            `last_seen_at` = NOW(),
            `updated_at` = NOW()
            WHERE `moysklad_id` = '" . $this->db->escape($moyskladId) . "'");
    }

    private function updateLinkHash(string $moyskladId, string $parentMoyskladId, string $hash, int $taskId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_category_link` SET
            `moysklad_parent_id` = " . ($parentMoyskladId !== '' ? "'" . $this->db->escape($parentMoyskladId) . "'" : 'NULL') . ",
            `last_hash` = '" . $this->db->escape($hash) . "',
            `last_seen_task_id` = '" . (int)$taskId . "',
            `last_seen_at` = NOW(),
            `last_synced_at` = NOW(),
            `updated_at` = NOW()
            WHERE `moysklad_id` = '" . $this->db->escape($moyskladId) . "'");
    }

    private function ensureStoreLink(int $categoryId): void {
        $exists = $this->db->query("SELECT `category_id` FROM `" . DB_PREFIX . "category_to_store`
            WHERE `category_id` = '" . (int)$categoryId . "' AND `store_id` = 0 LIMIT 1");

        if (!$exists->num_rows) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "category_to_store` SET `category_id` = '" . (int)$categoryId . "', `store_id` = 0");
        }
    }

    private function rebuildCategoryPath(int $categoryId, int $parentId): void {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "category_path` WHERE `category_id` = '" . (int)$categoryId . "'");

        $level = 0;

        if ($parentId > 0) {
            $query = $this->db->query("SELECT `path_id`, `level` FROM `" . DB_PREFIX . "category_path`
                WHERE `category_id` = '" . (int)$parentId . "'
                ORDER BY `level` ASC");

            foreach ($query->rows as $path) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET
                    `category_id` = '" . (int)$categoryId . "',
                    `path_id` = '" . (int)$path['path_id'] . "',
                    `level` = '" . (int)$level . "'");
                $level++;
            }
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET
            `category_id` = '" . (int)$categoryId . "',
            `path_id` = '" . (int)$categoryId . "',
            `level` = '" . (int)$level . "'");
    }

    private function getCategoryParentId(int $categoryId): int {
        $query = $this->db->query("SELECT `parent_id` FROM `" . DB_PREFIX . "category` WHERE `category_id` = '" . (int)$categoryId . "' LIMIT 1");

        return $query->num_rows ? (int)$query->row['parent_id'] : 0;
    }

    private function disableCategory(int $categoryId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "category` SET `status` = 0, `date_modified` = NOW() WHERE `category_id` = '" . (int)$categoryId . "'");
    }

    private function deleteCategory(int $categoryId): void {
        $tables = [
            'category',
            'category_description',
            'category_to_store',
            'category_to_layout',
            'category_path',
            'product_to_category',
        ];

        foreach ($tables as $table) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . $table . "` WHERE `category_id` = '" . (int)$categoryId . "'");
        }

        $this->db->query("DELETE FROM `" . DB_PREFIX . "moysklad_category_link` WHERE `category_id` = '" . (int)$categoryId . "'");

        if ($this->tableExists('seo_url')) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE `key` = 'path' AND (`value` = '" . (int)$categoryId . "' OR `value` LIKE '%\\_" . (int)$categoryId . "')");
        }
    }

    private function hasChildCategories(int $categoryId): bool {
        $query = $this->db->query("SELECT `category_id` FROM `" . DB_PREFIX . "category` WHERE `parent_id` = '" . (int)$categoryId . "' LIMIT 1");

        return (bool)$query->num_rows;
    }

    private function ensureSeoUrl(int $categoryId): void {
        if (!$this->tableExists('seo_url') || !$this->seoUrlHasKeyValueColumns()) {
            return;
        }

        $languageId = $this->getRussianLanguageId();
        $path = $this->getCategoryPathValue($categoryId);

        if ($path === '') {
            return;
        }

        $keyword = $this->buildCategoryKeywordPath($categoryId);

        if ($keyword === '') {
            return;
        }

        // Убираем старые SEO-записи этой категории и добавляем актуальный path.
        // Для категорий OC4 хранит key='path', value='parent_child'.
        $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url`
            WHERE `store_id` = 0
              AND `language_id` = '" . (int)$languageId . "'
              AND `key` = 'path'
              AND (`value` = '" . (int)$categoryId . "' OR `value` LIKE '%\\_" . (int)$categoryId . "')");

        $keyword = $this->makeKeywordUnique($keyword, $languageId);

        $this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET
            `store_id` = 0,
            `language_id` = '" . (int)$languageId . "',
            `key` = 'path',
            `value` = '" . $this->db->escape($path) . "',
            `keyword` = '" . $this->db->escape($keyword) . "',
            `sort_order` = 0");
    }

    private function getCategoryPathValue(int $categoryId): string {
        $query = $this->db->query("SELECT `path_id` FROM `" . DB_PREFIX . "category_path`
            WHERE `category_id` = '" . (int)$categoryId . "'
            ORDER BY `level` ASC");

        $parts = [];

        foreach ($query->rows as $row) {
            $parts[] = (int)$row['path_id'];
        }

        return implode('_', $parts);
    }

    private function buildCategoryKeywordPath(int $categoryId): string {
        $languageId = $this->getRussianLanguageId();
        $query = $this->db->query("SELECT cd.`name` FROM `" . DB_PREFIX . "category_path` cp
            INNER JOIN `" . DB_PREFIX . "category_description` cd ON (cd.`category_id` = cp.`path_id` AND cd.`language_id` = '" . (int)$languageId . "')
            WHERE cp.`category_id` = '" . (int)$categoryId . "'
            ORDER BY cp.`level` ASC");

        $parts = [];

        foreach ($query->rows as $row) {
            $slug = $this->slugify((string)$row['name']);
            if ($slug !== '') {
                $parts[] = $slug;
            }
        }

        return implode('/', $parts);
    }

    private function makeKeywordUnique(string $keyword, int $languageId): string {
        $base = trim($keyword, '/');
        $candidate = $base;
        $i = 2;

        while ($this->seoKeywordExists($candidate, $languageId)) {
            $candidate = $base . '-' . $i;
            $i++;
        }

        return $candidate;
    }

    private function seoKeywordExists(string $keyword, int $languageId): bool {
        $query = $this->db->query("SELECT `seo_url_id` FROM `" . DB_PREFIX . "seo_url`
            WHERE `store_id` = 0
              AND `language_id` = '" . (int)$languageId . "'
              AND `keyword` = '" . $this->db->escape($keyword) . "'
            LIMIT 1");

        return (bool)$query->num_rows;
    }

    private function slugify(string $text): string {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'
        ];
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?: '';
        $text = trim($text, '-');

        return mb_substr($text, 0, 180, 'UTF-8');
    }

    private function getRussianLanguageId(): int {
        $query = $this->db->query("SELECT `language_id` FROM `" . DB_PREFIX . "language` WHERE `code` IN ('ru-ru', 'ru') ORDER BY `language_id` ASC LIMIT 1");

        if ($query->num_rows) {
            return (int)$query->row['language_id'];
        }

        return (int)$this->config->get('config_language_id') ?: 1;
    }

    private function tableExists(string $table): bool {
        $query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $table) . "'");

        return (bool)$query->num_rows;
    }

    private function seoUrlHasKeyValueColumns(): bool {
        $key = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "seo_url` LIKE 'key'");
        $value = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "seo_url` LIKE 'value'");

        return $key->num_rows && $value->num_rows;
    }

    /**
     * Проверяет наличие колонки в таблице.
     *
     * В разных сборках OpenCart/ocStore структура таблицы category отличается:
     * например, в твоей ocStore 4.1 нет колонок `top` и `column`, которые были
     * в ряде старых схем OpenCart. Поэтому при создании категории мы вставляем
     * только реально существующие поля, а не предполагаем одну универсальную схему.
     */
    private function columnExists(string $table, string $column): bool {
        $query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . $table . "` LIKE '" . $this->db->escape($column) . "'");

        return (bool)$query->num_rows;
    }

    private function makeHash(array $data): string {
        ksort($data);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
