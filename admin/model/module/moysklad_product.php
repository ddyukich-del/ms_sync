<?php
namespace Opencart\Admin\Model\Extension\MoyskladSync\Module;

/**
 * Модель работы с товарами ocStore.
 *
 * Здесь собраны все SQL-операции по товарам: создание, обновление, отключение,
 * удаление, привязка к категории, атрибуты и связь с ID МойСклад. Сервисный слой
 * не лезет в таблицы напрямую — это упрощает поддержку и снижает риск ошибок.
 */
class MoyskladProduct extends \Opencart\System\Engine\Model {
    /**
     * Создает или обновляет товар по данным МойСклад.
     *
     * Главный идентификатор — moysklad_id. Артикул и название сохраняем, но не
     * используем для поиска: артикулы часто меняют, а ID МойСклад стабилен.
     */
    public function upsertFromMoysklad(array $product, int $taskId, array $settings): string {
        $moyskladId = trim((string)($product['id'] ?? ''));
        $name = trim((string)($product['name'] ?? ''));

        if ($moyskladId === '') {
            throw new \InvalidArgumentException('У товара МойСклад нет ID.');
        }

        if ($name === '') {
            throw new \InvalidArgumentException('У товара МойСклад ' . $moyskladId . ' пустое название.');
        }

        $link = $this->getLinkByMoyskladId($moyskladId);
        $data = $this->mapProductData($product, $settings);

        // Важно: на этапе импорта карточек НЕ применяем политику нулевого
        // остатка. В МойСклад поле quantity в карточке товара может отсутствовать
        // или быть не тем остатком, который нужен по выбранному складу. Раньше это
        // приводило к ситуации, когда новые товары с quantity=0 и настройкой
        // "удалять" вообще не создавались, а задача завершалась без ошибок.
        // Точное отключение/удаление по остатку выполняется только отдельным
        // stock-шагом после создания карточек.

        $hash = $this->makeHash([
            'name' => $data['name'],
            'description' => $data['description'],
            'price' => $data['price'],
            'quantity' => $data['quantity_known'] ? $data['quantity'] : null,
            'status' => $data['status'],
            'model' => $data['model'],
            'sku' => $data['sku'],
            'category_moysklad_id' => $data['category_moysklad_id'],
            'manufacturer_name' => $data['manufacturer_name'],
            'weight' => $data['weight'],
            'attributes' => $data['attributes'],
        ]);

        if (!$link) {
            $productId = $this->createProduct($data, $settings);
            $this->saveLink($productId, $product, $hash, $taskId);
            return 'created';
        }

        $productId = (int)$link['product_id'];

        // Важно для реальных обновлений модуля: в ранних тестовых версиях могла
        // остаться связь moysklad_product_link, но сама строка в oc_product была
        // удалена вручную или старой логикой удаления при нулевом остатке.
        // Если просто выполнить UPDATE по несуществующему product_id, MySQL не
        // выдаст ошибку, задача покажет "обновлено", но товара в каталоге не будет.
        // Поэтому перед обновлением проверяем существование карточки. Если карточки
        // нет — создаем ее заново и перепривязываем существующую связь к новому ID.
        if (!$this->productExists($productId)) {
            $newProductId = $this->createProduct($data, $settings);
            $this->relinkExistingMoyskladLink($moyskladId, $newProductId, $product, $hash, $taskId);
            return 'created';
        }

        // last_seen обновляем всегда: товар пришел в текущей выгрузке и не должен
        // попасть в шаг обработки отсутствующих товаров.
        $this->touchLink($moyskladId, $product, $taskId);

        if ((string)$link['last_hash'] === $hash) {
            // Даже если данные товара не изменились, связь товара с категорией
            // может отсутствовать: например, категории были удалены вручную и
            // затем пересозданы, либо ранняя версия модуля импортировала товары
            // до исправления категорий. Поэтому на skipped-товарах аккуратно
            // восстанавливаем product_to_category, но только если у товара есть
            // ID группы МойСклад. Если категории нет — ничего не удаляем.
            if ($data['category_moysklad_id'] !== '') {
                $this->replaceProductCategory($productId, $data['category_moysklad_id']);
            }

            return 'skipped';
        }

        $this->updateProduct($productId, $data, $settings);
        $this->updateLinkHash($moyskladId, $product, $hash, $taskId);

        return 'updated';
    }

    /**
     * Обрабатывает товары, которые были связаны с МойСклад, но не пришли в текущей выгрузке.
     * Ручные товары без связи с МойСклад не трогаем вообще.
     */
    public function processMissingProducts(int $taskId, int $lastLinkId, int $limit, string $action): array {
        $limit = max(1, $limit);
        $stats = ['processed' => 0, 'disabled' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0, 'last_cursor' => $lastLinkId, 'has_more' => false];

        // Используем product_id как cursor, а не служебный link_id. Так код
        // совместим с ранними установками модуля, где таблица связей могла быть
        // создана без link_id. product_id уникален в таблице связей и хорошо
        // подходит для пакетной обработки без OFFSET.
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "moysklad_product_link`
            WHERE (`last_seen_task_id` IS NULL OR `last_seen_task_id` <> '" . (int)$taskId . "')
              AND `product_id` > '" . (int)$lastLinkId . "'
            ORDER BY `product_id` ASC
            LIMIT " . (int)$limit);

        foreach ($query->rows as $link) {
            $stats['processed']++;
            $stats['last_cursor'] = (int)$link['product_id'];
            $productId = (int)$link['product_id'];

            try {
                if ($action === 'delete') {
                    $this->deleteProduct($productId);
                    $stats['deleted']++;
                } elseif ($action === 'disable') {
                    $this->disableProduct($productId);
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



    /**
     * Обновляет только остаток товара по строке отчета МойСклад.
     *
     * Метод намеренно не меняет название, описание, цену, категории, атрибуты
     * и изображения. Это нужно для отдельной кнопки «Обновить остатки»: она
     * должна быть максимально легкой и безопасной для слабого сервера.
     */
    public function updateStockFromMoysklad(array $stockRow, int $taskId, array $settings): string {
        $moyskladId = trim((string)($stockRow['id'] ?? ''));

        if ($moyskladId === '') {
            throw new \InvalidArgumentException('В строке остатков МойСклад нет ID товара.');
        }

        $link = $this->getLinkByMoyskladId($moyskladId);

        if (!$link) {
            // Задача остатков не создает новые товары. Если товара еще нет на сайте,
            // сначала его должен создать полный импорт. Так мы не получим пустые
            // карточки без описаний/категорий/цены.
            return 'skipped';
        }

        $productId = (int)$link['product_id'];
        $quantity = max(0, (int)floor((float)($stockRow['stock'] ?? 0)));
        $zeroAction = (string)($settings['module_moysklad_sync_zero_stock_action'] ?? 'disable');

        if ($quantity <= 0 && $zeroAction === 'delete') {
            $this->deleteProduct($productId);
            return 'deleted';
        }

        $current = $this->db->query("SELECT `quantity`, `status` FROM `" . DB_PREFIX . "product`
            WHERE `product_id` = '" . (int)$productId . "'
            LIMIT 1");

        if (!$current->num_rows) {
            return 'skipped';
        }

        $fields = [
            'quantity' => $quantity,
            'date_modified' => date('Y-m-d H:i:s')
        ];

        // Если товар появился на складе снова — включаем его обратно. Если остаток 0
        // и политика "disable" — отключаем. Если политика "none" — меняем только
        // quantity и не вмешиваемся в статус карточки.
        if ($quantity > 0) {
            $fields['status'] = 1;
        } elseif ($zeroAction === 'disable') {
            $fields['status'] = 0;
            $fields['stock_status_id'] = $this->getOutOfStockStatusId();
        }

        $fields = $this->filterExistingColumns('product', $fields);

        $statusWillBeChanged = array_key_exists('status', $fields) && (int)$current->row['status'] !== (int)$fields['status'];
        $quantityWillBeChanged = (int)$current->row['quantity'] !== $quantity;

        if (!$quantityWillBeChanged && !$statusWillBeChanged) {
            $this->touchStockSync($moyskladId, $stockRow, $taskId);
            return 'skipped';
        }

        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . $this->buildSqlSet($fields) . "
            WHERE `product_id` = '" . (int)$productId . "'");

        $this->touchStockSync($moyskladId, $stockRow, $taskId);

        if ($quantity <= 0 && $zeroAction === 'disable') {
            return 'disabled';
        }

        return 'updated';
    }

    /**
     * Отмечает, что по товару прошла синхронизация остатков.
     *
     * last_seen_task_id здесь не трогаем: это поле используется полным импортом
     * для определения товаров, которых больше нет в каталоге МойСклад. Остатки —
     * отдельный отчет, поэтому смешивать эти маркеры нельзя.
     */
    private function touchStockSync(string $moyskladId, array $stockRow, int $taskId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_product_link` SET
            `article` = COALESCE(NULLIF('" . $this->db->escape((string)($stockRow['article'] ?? '')) . "', ''), `article`),
            `last_synced_at` = NOW(),
            `updated_at` = NOW()
            WHERE `moysklad_id` = '" . $this->db->escape($moyskladId) . "'");
    }

    private function mapProductData(array $product, array $settings): array {
        $price = $this->extractSelectedPrice($product, (string)($settings['module_moysklad_sync_price_type_id'] ?? ''));
        $description = (string)($product['description'] ?? '');

        // По решению проекта: если в МойСклад описания нет, на сайте его тоже нет.
        if ($description === '' && empty($settings['module_moysklad_sync_clear_empty_description'])) {
            $description = '';
        }

        $status = ($product['archived'] ?? false) === true ? 0 : 1;

        return [
            'moysklad_id' => trim((string)($product['id'] ?? '')),
            'moysklad_href' => (string)($product['href'] ?? ''),
            'name' => trim((string)($product['name'] ?? '')),
            'description' => $description,
            'external_code' => (string)($product['external_code'] ?? ''),
            'article' => (string)($product['article'] ?? ''),
            'model' => $this->buildModel($product),
            'sku' => (string)($product['article'] ?? ''),
            'price' => $price,
            'quantity' => !empty($product['quantity_known']) ? max(0, (float)$product['quantity']) : 0,
            'quantity_known' => !empty($product['quantity_known']),
            'status' => $status,
            'category_moysklad_id' => (string)($product['category_id'] ?? ''),
            'manufacturer_name' => trim((string)($product['manufacturer_name'] ?? '')),
            'weight' => isset($product['weight']) && is_numeric($product['weight']) ? (float)$product['weight'] : 0.0,
            'attributes' => is_array($product['attributes'] ?? null) ? $product['attributes'] : [],
        ];
    }

    private function buildModel(array $product): string {
        $article = trim((string)($product['article'] ?? ''));

        if ($article !== '') {
            return $article;
        }

        $code = trim((string)($product['code'] ?? ''));

        if ($code !== '') {
            return $code;
        }

        // В OpenCart/ocStore model часто обязательное поле. Если артикула нет,
        // используем короткий стабильный вариант ID МойСклад, а не название товара.
        return mb_substr(trim((string)($product['id'] ?? '')), 0, 64, 'UTF-8');
    }

    private function extractSelectedPrice(array $product, string $priceTypeId): float {
        foreach (($product['sale_prices'] ?? []) as $price) {
            if (!is_array($price)) {
                continue;
            }

            if ((string)($price['price_type_id'] ?? '') === $priceTypeId && isset($price['value']) && is_numeric($price['value'])) {
                return max(0, (float)$price['value']);
            }
        }

        return 0.0;
    }

    private function createProduct(array $data, array $settings): int {
        $manufacturerId = $this->ensureManufacturer($data['manufacturer_name']);
        $productFields = $this->buildProductFields($data, $manufacturerId, true);

        $this->db->query("INSERT INTO `" . DB_PREFIX . "product` SET " . $this->buildSqlSet($productFields));
        $productId = (int)$this->db->getLastId();

        $this->upsertProductDescription($productId, $data['name'], $data['description']);
        $this->ensureStoreLink($productId);
        $this->replaceProductCategory($productId, $data['category_moysklad_id']);
        $this->replaceProductAttributes($productId, $data['attributes']);

        if (($settings['module_moysklad_sync_seo_mode'] ?? 'new_only') === 'new_only') {
            $this->ensureSeoUrl($productId, $data['name']);
        }

        return $productId;
    }

    private function updateProduct(int $productId, array $data, array $settings): void {
        $manufacturerId = $this->ensureManufacturer($data['manufacturer_name']);
        $productFields = $this->buildProductFields($data, $manufacturerId, false);

        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . $this->buildSqlSet($productFields) . " WHERE `product_id` = '" . (int)$productId . "'");

        $this->upsertProductDescription($productId, $data['name'], $data['description']);
        $this->ensureStoreLink($productId);
        $this->replaceProductCategory($productId, $data['category_moysklad_id']);
        $this->replaceProductAttributes($productId, $data['attributes']);
    }

    private function buildProductFields(array $data, int $manufacturerId, bool $isCreate): array {
        $fields = [
            'model' => $data['model'],
            'sku' => $data['sku'],
            'manufacturer_id' => $manufacturerId,
            'shipping' => 1,
            'price' => $data['price'],
            'points' => 0,
            'tax_class_id' => 0,
            'date_available' => date('Y-m-d'),
            'weight' => (float)$data['weight'],
            'weight_class_id' => $this->getDefaultWeightClassId(),
            'length' => 0,
            'width' => 0,
            'height' => 0,
            'length_class_id' => $this->getDefaultLengthClassId(),
            'subtract' => 1,
            'minimum' => 1,
            'sort_order' => 0,
            'status' => (int)$data['status'],
            'date_modified' => date('Y-m-d H:i:s'),
        ];

        // Остаток в карточке товара МойСклад может не приходить. В таком случае
        // при обновлении существующего товара количество не трогаем, чтобы случайно
        // не обнулить каталог. Для нового товара без известного остатка ставим 0.
        if ($isCreate || !empty($data['quantity_known'])) {
            $fields['quantity'] = !empty($data['quantity_known']) ? (int)floor((float)$data['quantity']) : 0;
            $fields['stock_status_id'] = $this->getOutOfStockStatusId();
        }

        if ($isCreate) {
            $fields['date_added'] = date('Y-m-d H:i:s');
        }

        // OpenCart 4 в некоторых сборках имеет дополнительные поля master_id,
        // variant, override. Добавляем их только если колонка реально есть.
        if ($isCreate) {
            $fields['image'] = '';
            $fields['viewed'] = 0;
            $fields['master_id'] = 0;
            $fields['variant'] = '';
            $fields['override'] = '';
            $fields['location'] = '';
            $fields['upc'] = '';
            $fields['ean'] = '';
            $fields['jan'] = '';
            $fields['isbn'] = '';
            $fields['mpn'] = '';
        }

        return $this->filterExistingColumns('product', $fields);
    }

    private function upsertProductDescription(int $productId, string $name, string $description): void {
        $languageId = $this->getRussianLanguageId();
        $fields = [
            'product_id' => $productId,
            'language_id' => $languageId,
            'name' => $name,
            'description' => $description,
            'tag' => '',
            'meta_title' => $name,
            'meta_description' => '',
            'meta_keyword' => '',
        ];

        $fields = $this->filterExistingColumns('product_description', $fields);

        $exists = $this->db->query("SELECT `product_id` FROM `" . DB_PREFIX . "product_description`
            WHERE `product_id` = '" . (int)$productId . "'
              AND `language_id` = '" . (int)$languageId . "'
            LIMIT 1");

        if ($exists->num_rows) {
            unset($fields['product_id'], $fields['language_id']);
            $this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET " . $this->buildSqlSet($fields) . "
                WHERE `product_id` = '" . (int)$productId . "'
                  AND `language_id` = '" . (int)$languageId . "'");
        } else {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET " . $this->buildSqlSet($fields));
        }
    }

    private function ensureStoreLink(int $productId): void {
        if (!$this->tableExists('product_to_store')) {
            return;
        }

        $exists = $this->db->query("SELECT `product_id` FROM `" . DB_PREFIX . "product_to_store`
            WHERE `product_id` = '" . (int)$productId . "' AND `store_id` = 0 LIMIT 1");

        if (!$exists->num_rows) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_store` SET `product_id` = '" . (int)$productId . "', `store_id` = 0");
        }
    }

    private function replaceProductCategory(int $productId, string $categoryMoyskladId): void {
        if (!$this->tableExists('product_to_category')) {
            return;
        }

        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_to_category` WHERE `product_id` = '" . (int)$productId . "'");

        if ($categoryMoyskladId === '') {
            return;
        }

        $categoryId = $this->getCategoryIdByMoyskladId($categoryMoyskladId);

        if ($categoryId <= 0) {
            return;
        }

        // Если категория была отключена старым запуском или из-за нулевого остатка
        // других товаров, но сейчас в нее импортируется товар выбранного склада,
        // категорию и ее родителей нужно включить обратно.
        $this->enableCategoryWithParents($categoryId);

        $this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET
            `product_id` = '" . (int)$productId . "',
            `category_id` = '" . (int)$categoryId . "'");
    }

    /** Включает категорию товара и всех ее родителей. */
    private function enableCategoryWithParents(int $categoryId): void {
        $guard = 0;

        while ($categoryId > 0 && $guard < 50) {
            $guard++;

            $query = $this->db->query("SELECT `parent_id` FROM `" . DB_PREFIX . "category`
                WHERE `category_id` = '" . (int)$categoryId . "'
                LIMIT 1");

            if (!$query->num_rows) {
                return;
            }

            $this->db->query("UPDATE `" . DB_PREFIX . "category` SET
                `status` = 1,
                `date_modified` = NOW()
                WHERE `category_id` = '" . (int)$categoryId . "'");

            $parentId = (int)$query->row['parent_id'];
            if ($parentId === $categoryId) {
                return;
            }

            $categoryId = $parentId;
        }
    }

    private function replaceProductAttributes(int $productId, array $attributes): void {
        if (!$this->tableExists('product_attribute') || !$this->tableExists('attribute') || !$this->tableExists('attribute_description')) {
            return;
        }

        $languageId = $this->getRussianLanguageId();
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_attribute` WHERE `product_id` = '" . (int)$productId . "'");

        if (!$attributes) {
            return;
        }

        $attributeGroupId = $this->ensureAttributeGroup('МойСклад');

        foreach ($attributes as $attribute) {
            $name = trim((string)($attribute['name'] ?? ''));
            $value = trim((string)($attribute['value'] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $attributeId = $this->ensureAttribute($attributeGroupId, $name);

            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_attribute` SET
                `product_id` = '" . (int)$productId . "',
                `attribute_id` = '" . (int)$attributeId . "',
                `language_id` = '" . (int)$languageId . "',
                `text` = '" . $this->db->escape($value) . "'");
        }
    }

    private function ensureAttributeGroup(string $name): int {
        $languageId = $this->getRussianLanguageId();

        $query = $this->db->query("SELECT ag.`attribute_group_id` FROM `" . DB_PREFIX . "attribute_group` ag
            INNER JOIN `" . DB_PREFIX . "attribute_group_description` agd ON (agd.`attribute_group_id` = ag.`attribute_group_id`)
            WHERE agd.`language_id` = '" . (int)$languageId . "'
              AND agd.`name` = '" . $this->db->escape($name) . "'
            LIMIT 1");

        if ($query->num_rows) {
            return (int)$query->row['attribute_group_id'];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group` SET `sort_order` = 0");
        $attributeGroupId = (int)$this->db->getLastId();

        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group_description` SET
            `attribute_group_id` = '" . (int)$attributeGroupId . "',
            `language_id` = '" . (int)$languageId . "',
            `name` = '" . $this->db->escape($name) . "'");

        return $attributeGroupId;
    }

    private function ensureAttribute(int $attributeGroupId, string $name): int {
        $languageId = $this->getRussianLanguageId();

        $query = $this->db->query("SELECT a.`attribute_id` FROM `" . DB_PREFIX . "attribute` a
            INNER JOIN `" . DB_PREFIX . "attribute_description` ad ON (ad.`attribute_id` = a.`attribute_id`)
            WHERE a.`attribute_group_id` = '" . (int)$attributeGroupId . "'
              AND ad.`language_id` = '" . (int)$languageId . "'
              AND ad.`name` = '" . $this->db->escape($name) . "'
            LIMIT 1");

        if ($query->num_rows) {
            return (int)$query->row['attribute_id'];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute` SET
            `attribute_group_id` = '" . (int)$attributeGroupId . "',
            `sort_order` = 0");
        $attributeId = (int)$this->db->getLastId();

        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_description` SET
            `attribute_id` = '" . (int)$attributeId . "',
            `language_id` = '" . (int)$languageId . "',
            `name` = '" . $this->db->escape($name) . "'");

        return $attributeId;
    }

    private function ensureManufacturer(string $name): int {
        $name = trim($name);

        if ($name === '' || !$this->tableExists('manufacturer')) {
            return 0;
        }

        $query = $this->db->query("SELECT `manufacturer_id` FROM `" . DB_PREFIX . "manufacturer`
            WHERE `name` = '" . $this->db->escape($name) . "'
            LIMIT 1");

        if ($query->num_rows) {
            return (int)$query->row['manufacturer_id'];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer` SET
            `name` = '" . $this->db->escape($name) . "',
            `image` = '',
            `sort_order` = 0");
        $manufacturerId = (int)$this->db->getLastId();

        if ($this->tableExists('manufacturer_to_store')) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer_to_store` SET
                `manufacturer_id` = '" . (int)$manufacturerId . "',
                `store_id` = 0");
        }

        return $manufacturerId;
    }

    private function saveLink(int $productId, array $product, string $hash, int $taskId): void {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "moysklad_product_link` SET
            `product_id` = '" . (int)$productId . "',
            `moysklad_id` = '" . $this->db->escape((string)$product['id']) . "',
            `moysklad_href` = '" . $this->db->escape((string)($product['href'] ?? '')) . "',
            `external_code` = '" . $this->db->escape((string)($product['external_code'] ?? '')) . "',
            `article` = '" . $this->db->escape((string)($product['article'] ?? '')) . "',
            `last_hash` = '" . $this->db->escape($hash) . "',
            `last_seen_task_id` = '" . (int)$taskId . "',
            `last_seen_at` = NOW(),
            `last_synced_at` = NOW(),
            `created_at` = NOW(),
            `updated_at` = NOW()");
    }

    private function touchLink(string $moyskladId, array $product, int $taskId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_product_link` SET
            `moysklad_href` = '" . $this->db->escape((string)($product['href'] ?? '')) . "',
            `external_code` = '" . $this->db->escape((string)($product['external_code'] ?? '')) . "',
            `article` = '" . $this->db->escape((string)($product['article'] ?? '')) . "',
            `last_seen_task_id` = '" . (int)$taskId . "',
            `last_seen_at` = NOW(),
            `updated_at` = NOW()
            WHERE `moysklad_id` = '" . $this->db->escape($moyskladId) . "'");
    }

    private function updateLinkHash(string $moyskladId, array $product, string $hash, int $taskId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_product_link` SET
            `moysklad_href` = '" . $this->db->escape((string)($product['href'] ?? '')) . "',
            `external_code` = '" . $this->db->escape((string)($product['external_code'] ?? '')) . "',
            `article` = '" . $this->db->escape((string)($product['article'] ?? '')) . "',
            `last_hash` = '" . $this->db->escape($hash) . "',
            `last_seen_task_id` = '" . (int)$taskId . "',
            `last_seen_at` = NOW(),
            `last_synced_at` = NOW(),
            `updated_at` = NOW()
            WHERE `moysklad_id` = '" . $this->db->escape($moyskladId) . "'");
    }

    /**
     * Проверяет, существует ли физическая карточка товара в oc_product.
     *
     * Наличие связи с МойСклад еще не гарантирует наличие товара: во время
     * разработки или после ручных правок в базе могли остаться "осиротевшие"
     * связи. Такая проверка защищает импорт от тихого UPDATE по несуществующему ID.
     */
    private function productExists(int $productId): bool {
        if ($productId <= 0) {
            return false;
        }

        $query = $this->db->query("SELECT `product_id` FROM `" . DB_PREFIX . "product`
            WHERE `product_id` = '" . (int)$productId . "'
            LIMIT 1");

        return (bool)$query->num_rows;
    }

    /**
     * Перепривязывает существующую связь МойСклад к новой карточке товара.
     *
     * Используется, когда связь уже есть, но старый product_id больше не существует.
     * Мы не вставляем новую строку связи, чтобы не упереться в уникальный moysklad_id.
     */
    private function relinkExistingMoyskladLink(string $moyskladId, int $newProductId, array $product, string $hash, int $taskId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_product_link` SET
            `product_id` = '" . (int)$newProductId . "',
            `moysklad_href` = '" . $this->db->escape((string)($product['href'] ?? '')) . "',
            `external_code` = '" . $this->db->escape((string)($product['external_code'] ?? '')) . "',
            `article` = '" . $this->db->escape((string)($product['article'] ?? '')) . "',
            `last_hash` = '" . $this->db->escape($hash) . "',
            `last_seen_task_id` = '" . (int)$taskId . "',
            `last_seen_at` = NOW(),
            `last_synced_at` = NOW(),
            `updated_at` = NOW()
            WHERE `moysklad_id` = '" . $this->db->escape($moyskladId) . "'");
    }

    private function getLinkByMoyskladId(string $moyskladId): ?array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "moysklad_product_link`
            WHERE `moysklad_id` = '" . $this->db->escape($moyskladId) . "'
            LIMIT 1");

        return $query->num_rows ? $query->row : null;
    }

    private function getCategoryIdByMoyskladId(string $moyskladId): int {
        $query = $this->db->query("SELECT `category_id` FROM `" . DB_PREFIX . "moysklad_category_link`
            WHERE `moysklad_id` = '" . $this->db->escape($moyskladId) . "'
            LIMIT 1");

        return $query->num_rows ? (int)$query->row['category_id'] : 0;
    }

    private function disableProduct(int $productId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET `status` = 0, `date_modified` = NOW() WHERE `product_id` = '" . (int)$productId . "'");
    }

    private function deleteProduct(int $productId): void {
        // Удаляем только стандартные связи товара. Таблицы проверяем динамически,
        // чтобы модуль не падал на сборках ocStore/OpenCart с отличающейся схемой.
        $tables = [
            'product',
            'product_description',
            'product_to_store',
            'product_to_category',
            'product_attribute',
            'product_discount',
            'product_special',
            'product_image',
            'product_option',
            'product_option_value',
            'product_related',
            'product_reward',
            'product_to_layout',
            'product_filter',
            'product_recurring',
            'product_subscription',
        ];

        foreach ($tables as $table) {
            if ($this->tableExists($table) && $this->columnExists($table, 'product_id')) {
                $this->db->query("DELETE FROM `" . DB_PREFIX . $table . "` WHERE `product_id` = '" . (int)$productId . "'");
            }
        }

        $this->db->query("DELETE FROM `" . DB_PREFIX . "moysklad_product_link` WHERE `product_id` = '" . (int)$productId . "'");
        $this->db->query("DELETE FROM `" . DB_PREFIX . "moysklad_image_link` WHERE `product_id` = '" . (int)$productId . "'");

        if ($this->tableExists('seo_url') && $this->seoUrlHasKeyValueColumns()) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE `key` = 'product_id' AND `value` = '" . (int)$productId . "'");
        }
    }

    private function ensureSeoUrl(int $productId, string $name): void {
        if (!$this->tableExists('seo_url') || !$this->seoUrlHasKeyValueColumns()) {
            return;
        }

        $languageId = $this->getRussianLanguageId();

        $exists = $this->db->query("SELECT `seo_url_id` FROM `" . DB_PREFIX . "seo_url`
            WHERE `store_id` = 0
              AND `language_id` = '" . (int)$languageId . "'
              AND `key` = 'product_id'
              AND `value` = '" . (int)$productId . "'
            LIMIT 1");

        if ($exists->num_rows) {
            return;
        }

        $keyword = $this->makeKeywordUnique($this->slugify($name), $languageId);

        if ($keyword === '') {
            return;
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET
            `store_id` = 0,
            `language_id` = '" . (int)$languageId . "',
            `key` = 'product_id',
            `value` = '" . (int)$productId . "',
            `keyword` = '" . $this->db->escape($keyword) . "',
            `sort_order` = 0");
    }

    private function makeKeywordUnique(string $keyword, int $languageId): string {
        $base = trim($keyword, '-');

        if ($base === '') {
            return '';
        }

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

    private function getDefaultWeightClassId(): int {
        return (int)$this->config->get('config_weight_class_id') ?: 1;
    }

    private function getDefaultLengthClassId(): int {
        return (int)$this->config->get('config_length_class_id') ?: 1;
    }

    private function getOutOfStockStatusId(): int {
        return (int)$this->config->get('config_stock_status_id') ?: 0;
    }

    private function filterExistingColumns(string $table, array $fields): array {
        $filtered = [];

        foreach ($fields as $column => $value) {
            if ($this->columnExists($table, $column)) {
                $filtered[$column] = $value;
            }
        }

        return $filtered;
    }

    private function buildSqlSet(array $fields): string {
        $parts = [];

        foreach ($fields as $column => $value) {
            if ($value === null) {
                $parts[] = "`" . $column . "` = NULL";
            } elseif (is_int($value) || is_float($value)) {
                $parts[] = "`" . $column . "` = '" . $this->db->escape((string)$value) . "'";
            } else {
                $parts[] = "`" . $column . "` = '" . $this->db->escape((string)$value) . "'";
            }
        }

        return implode(', ', $parts);
    }

    private function tableExists(string $table): bool {
        $query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $table) . "'");

        return (bool)$query->num_rows;
    }

    private function columnExists(string $table, string $column): bool {
        if (!$this->tableExists($table)) {
            return false;
        }

        $query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . $table . "` LIKE '" . $this->db->escape($column) . "'");

        return (bool)$query->num_rows;
    }

    private function seoUrlHasKeyValueColumns(): bool {
        $key = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "seo_url` LIKE 'key'");
        $value = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "seo_url` LIKE 'value'");

        return $key->num_rows && $value->num_rows;
    }

    private function makeHash(array $data): string {
        ksort($data);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
