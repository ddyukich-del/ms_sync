<?php
namespace MoyskladSync;

/**
 * Клиент верхнего уровня для API МойСклад.
 *
 * В этом классе есть знание о конкретных endpoint'ах МойСклад, но нет знания
 * о таблицах ocStore. Он только получает данные и нормализует их в простой
 * массив, удобный для сервисов синхронизации.
 */
class MoyskladClient {
    private HttpClient $http;

    public function __construct(HttpClient $http) {
        $this->http = $http;
    }

    /**
     * Проверка авторизации.
     *
     * Берем легкий endpoint настроек компании. Если токен неверный — API вернет 401/403,
     * и HttpClient превратит это в ApiException с понятным сообщением.
     */
    public function testConnection(): array {
        $settings = $this->http->request('GET', '/context/companysettings');

        return [
            'ok' => true,
            'company_name' => (string)($settings['name'] ?? $settings['companyName'] ?? ''),
        ];
    }

    /**
     * Возвращает список складов.
     *
     * В МойСклад склады находятся в сущности entity/store. Для админки нам нужны
     * только id, href, название и признак архива — не тащим лишние поля дальше.
     */
    public function getWarehouses(int $limit = 100): array {
        $result = $this->http->request('GET', '/entity/store', [
            'limit' => $this->normalizeLimit($limit),
            'offset' => 0
        ]);

        return $this->normalizeRows($result, function (array $row): array {
            return [
                'id' => $this->extractId($row),
                'name' => (string)($row['name'] ?? ''),
                'href' => (string)($row['meta']['href'] ?? ''),
                'archived' => $this->toBool($row['archived'] ?? false)
            ];
        });
    }

    /**
     * Возвращает типы цен.
     *
     * В дальнейшем выбранный тип цены ищется в salePrices товара и записывается
     * в стандартное поле price в ocStore.
     */
    public function getPriceTypes(): array {
        $result = $this->http->request('GET', '/context/companysettings/pricetype');

        return $this->normalizeRows($result, function (array $row): array {
            return [
                'id' => $this->extractId($row),
                'name' => (string)($row['name'] ?? ''),
                'href' => (string)($row['meta']['href'] ?? ''),
                'external_code' => (string)($row['externalCode'] ?? '')
            ];
        });
    }

    /**
     * Возвращает одну страницу групп товаров МойСклад.
     *
     * Endpoint productfolder — это дерево групп/категорий товаров. Мы не делаем
     * getAll: каждая страница обрабатывается отдельным AJAX-шагом, чтобы не
     * перегружать память и PHP-timeout на слабом сервере.
     */
    public function getProductFoldersPage(int $limit, int $offset): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $result = $this->http->request('GET', '/entity/productfolder', [
            'limit' => $limit,
            'offset' => $offset
        ]);

        $rows = $this->normalizeRows($result, function (array $row): array {
            return $this->normalizeProductFolderRow($row);
        });

        return [
            'rows' => $rows,
            'total' => (int)($result['meta']['size'] ?? 0),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Получает одну группу товаров по ID.
     *
     * Этот метод нужен для нового алгоритма импорта по выбранному складу: мы
     * импортируем товары из отчета остатков, а категории создаем только те,
     * которые реально используются этими товарами. Для этого по productFolder
     * товара точечно запрашиваем категорию и ее родителей.
     */
    public function getProductFolderById(string $folderId): array {
        $folderId = trim($folderId);

        if ($folderId === '') {
            throw new \InvalidArgumentException('Пустой ID категории МойСклад.');
        }

        $row = $this->http->request('GET', '/entity/productfolder/' . rawurlencode($folderId));
        $folder = $this->normalizeProductFolderRow($row);

        if (($folder['id'] ?? '') === '') {
            throw new ApiException('МойСклад вернул категорию без ID: ' . $folderId);
        }

        return $folder;
    }

    /**
     * Возвращает одну страницу простых товаров.
     *
     * На этом этапе не берем модификации/варианты: пользователь отдельно решил,
     * что их подключим позже. Сервис товаров получает только небольшой пакет,
     * поэтому даже большой каталог не загружается в память целиком.
     */
    public function getProductsPage(int $limit, int $offset): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $result = $this->http->request('GET', '/entity/product', [
            'limit' => $limit,
            'offset' => $offset
        ]);

        $rows = $this->normalizeRows($result, function (array $row): array {
            return $this->normalizeProductRow($row);
        });

        return [
            'rows' => $rows,
            'total' => (int)($result['meta']['size'] ?? 0),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Получает полную карточку одного товара по ID.
     *
     * Нужен для правильного импорта по выбранному складу: список товаров берем
     * из отчета остатков по складу, а описание/цены/категорию добираем точечно
     * по каждому товару с положительным остатком. Это медленнее, чем тащить весь
     * каталог, но зато не создает товары с других складов.
     */
    public function getProductById(string $productId): array {
        $productId = trim($productId);

        if ($productId === '') {
            throw new \InvalidArgumentException('Пустой ID товара МойСклад.');
        }

        $row = $this->http->request('GET', '/entity/product/' . rawurlencode($productId), [
            // Разворачиваем группу товара, чтобы получить не только UUID категории,
            // но и ее название/родителя. Это снижает число отдельных API-запросов
            // и помогает корректно восстановить категории после ручного удаления.
            'expand' => 'productFolder'
        ]);
        $product = $this->normalizeProductRow($row);

        if (($product['id'] ?? '') === '') {
            throw new ApiException('МойСклад вернул карточку товара без ID: ' . $productId);
        }

        return $product;
    }

    /**
     * Возвращает одну страницу отчета остатков по выбранному складу.
     *
     * В МойСклад остатки корректнее брать не из карточки товара, а из отчета
     * stock/bystore с фильтром по складу. Полный импорт использует режим
     * nonEmpty, а отдельное обновление остатков может использовать all.
     */
    public function getStockPage(string $warehouseId, int $limit, int $offset, string $stockMode = 'nonEmpty'): array {
        $warehouseId = trim($warehouseId);
        $stockMode = in_array($stockMode, ['all', 'nonEmpty'], true) ? $stockMode : 'nonEmpty';
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        if ($warehouseId === '') {
            throw new \InvalidArgumentException('Не выбран склад МойСклад для обновления остатков.');
        }

        // Фильтр API ожидает ссылку на склад. В настройках мы храним UUID склада,
        // поэтому строим href в формате JSON API 1.2.
        $storeHref = 'https://api.moysklad.ru/api/remap/1.2/entity/store/' . rawurlencode($warehouseId);

        // Для одного выбранного склада используем отчет stock/bystore с фильтром
        // store + stockMode=nonEmpty. Старый вариант через stock/all на практике
        // мог возвращать суммарные остатки/не тот набор строк, из-за чего импорт
        // создавал товары с других складов и затем оставлял quantity=0.
        $result = $this->http->request('GET', '/report/stock/bystore', [
            'limit' => $limit,
            'offset' => $offset,
            'filter' => 'store=' . $storeHref . ';stockMode=' . $stockMode
        ]);

        $rows = $this->normalizeRows($result, function (array $row) use ($warehouseId): array {
            // В stock/bystore сама строка отчета описывает товар/услугу через meta.
            // В некоторых форматах может быть assortment.meta — поддерживаем оба,
            // но сначала берем meta строки, как в официальных примерах отчета.
            $meta = [];
            if (!empty($row['meta']) && is_array($row['meta'])) {
                $meta = $row['meta'];
            } elseif (!empty($row['assortment']['meta']) && is_array($row['assortment']['meta'])) {
                $meta = $row['assortment']['meta'];
            }

            $storeStock = $this->extractStoreStockValues($row);
            $stockValue = $storeStock['stock'];
            $reserveValue = $storeStock['reserve'];
            $inTransitValue = $storeStock['in_transit'];

            return [
                'id' => $this->extractIdFromMeta($meta),
                'href' => (string)($meta['href'] ?? ''),
                'type' => (string)($meta['type'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'article' => (string)($row['article'] ?? ''),
                'code' => (string)($row['code'] ?? ''),
                'warehouse_id' => $warehouseId,
                'category_id' => $this->extractIdFromMeta($row['productFolder']['meta'] ?? $row['assortment']['productFolder']['meta'] ?? []),
                'path_name' => (string)($row['pathName'] ?? $row['assortment']['pathName'] ?? ''),
                // quantity сайта равняем физическому остатку выбранного склада.
                'stock' => is_numeric($stockValue) ? (float)$stockValue : 0.0,
                'reserve' => is_numeric($reserveValue) ? (float)$reserveValue : 0.0,
                'in_transit' => is_numeric($inTransitValue) ? (float)$inTransitValue : 0.0,
            ];
        });

        return [
            'rows' => $rows,
            'total' => (int)($result['meta']['size'] ?? 0),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Возвращает одну страницу остатков по нескольким выбранным складам.
     *
     * МойСклад отдает отчет по конкретному складу, поэтому для нескольких складов
     * идем последовательно по каждому складу и используем общий offset задачи.
     * Это проще и безопаснее для слабого сервера, чем тащить все остатки в память.
     */
    public function getStockPageForWarehouses(array $warehouseIds, int $limit, int $offset, string $stockMode = 'nonEmpty'): array {
        $ids = [];
        foreach ($warehouseIds as $warehouseId) {
            $warehouseId = trim((string)$warehouseId);
            if ($warehouseId !== '') {
                $ids[$warehouseId] = $warehouseId;
            }
        }

        $ids = array_values($ids);

        if (!$ids) {
            throw new \InvalidArgumentException('Не выбраны склады МойСклад для обновления остатков.');
        }

        if (count($ids) === 1) {
            return $this->getStockPage($ids[0], $limit, $offset, $stockMode);
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $remainingOffset = $offset;
        $rows = [];
        $total = 0;

        foreach ($ids as $warehouseId) {
            // Небольшой запрос с limit=1 нужен только для meta.size текущего склада.
            $metaPage = $this->getStockPage($warehouseId, 1, 0, $stockMode);
            $warehouseTotal = (int)$metaPage['total'];
            $total += $warehouseTotal;

            if ($remainingOffset >= $warehouseTotal) {
                $remainingOffset -= $warehouseTotal;
                continue;
            }

            $need = $limit - count($rows);
            if ($need <= 0) {
                continue;
            }

            $page = $this->getStockPage($warehouseId, $need, $remainingOffset, $stockMode);
            foreach ($page['rows'] as $row) {
                $row['warehouse_id'] = $warehouseId;
                $rows[] = $row;
            }

            $remainingOffset = 0;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }


    /**
     * Возвращает статусы заказов поставщикам.
     *
     * Эти статусы нужны в настройках модуля как множественный выбор: пользователь
     * сам решает, какие документы считать ожидаемым поступлением, например
     * «ПРЕДЗАКАЗ» и «ПОПОЛНЕНИЕ СКЛАДА» одновременно.
     */
    public function getPurchaseOrderStates(): array {
        $result = $this->http->request('GET', '/entity/purchaseorder/metadata');
        $states = $result['states'] ?? [];

        if (!is_array($states)) {
            return [];
        }

        $rows = [];

        foreach ($states as $state) {
            if (!is_array($state)) {
                continue;
            }

            $row = $this->normalizePurchaseOrderStateRow($state);

            if (($row['id'] ?? '') !== '') {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** Возвращает одну страницу заказов поставщикам. */
    public function getPurchaseOrdersPage(int $limit, int $offset): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $result = $this->http->request('GET', '/entity/purchaseorder', [
            'limit' => $limit,
            'offset' => $offset,
            // Разворачиваем статус и склад. Статус нужен для выбранных статусов
            // поставки, склад — для защиты от смешивания факта одного склада с
            // ожидаемыми поступлениями на другой склад. Если аккаунт вернет только
            // meta, normalizePurchaseOrderRow все равно достанет ID из href.
            'expand' => 'state,store'
        ]);

        $rows = $this->normalizeRows($result, function (array $row): array {
            return $this->normalizePurchaseOrderRow($row);
        });

        return [
            'rows' => $rows,
            'total' => (int)($result['meta']['size'] ?? 0),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Возвращает позиции одного заказа поставщику.
     *
     * Позиции документа читаем внутри шага purchaseorder. Обычно их немного, но
     * метод все равно поддерживает пагинацию, чтобы не терять строки в больших
     * заказах. Ограничение 2000 позиций защищает слабый сервер от бесконечного
     * цикла при нестандартном ответе API.
     */
    public function getPurchaseOrderPositions(string $purchaseOrderId, int $limit = 100): array {
        $purchaseOrderId = trim($purchaseOrderId);
        $limit = max(1, min(100, $limit));

        if ($purchaseOrderId === '') {
            return [];
        }

        $rows = [];
        $offset = 0;
        $guard = 0;

        do {
            $result = $this->http->request('GET', '/entity/purchaseorder/' . rawurlencode($purchaseOrderId) . '/positions', [
                'limit' => $limit,
                'offset' => $offset,
                'expand' => 'assortment'
            ]);

            $pageRows = $this->normalizeRows($result, function (array $row): array {
                return $this->normalizePurchaseOrderPositionRow($row);
            });

            $rows = array_merge($rows, $pageRows);
            $count = count($result['rows'] ?? []);
            $total = (int)($result['meta']['size'] ?? 0);
            $offset += $count;
            $guard += $count;
        } while ($count === $limit && ($total === 0 || $offset < $total) && $guard < 2000);

        return $rows;
    }



    /**
     * Возвращает изображения/файлы конкретного товара МойСклад.
     *
     * В разных аккаунтах и версиях API изображения могут быть представлены как
     * коллекция images или как файлы. Поэтому сначала пробуем endpoint images,
     * а если он недоступен — аккуратно падаем обратно на files. Сервису выше не
     * важно, откуда пришла запись: он получает единый нормализованный массив.
     */
    public function getProductImages(string $productId, int $limit = 100): array {
        $productId = trim($productId);
        $limit = max(1, min(100, $limit));

        if ($productId === '') {
            return [];
        }

        $paths = [
            '/entity/product/' . rawurlencode($productId) . '/images',
            '/entity/product/' . rawurlencode($productId) . '/files',
        ];

        $lastException = null;

        foreach ($paths as $path) {
            try {
                $result = $this->http->request('GET', $path, [
                    'limit' => $limit,
                    'offset' => 0
                ]);

                return $this->normalizeRows($result, function (array $row) use ($productId): array {
                    return $this->normalizeImageRow($productId, $row);
                });
            } catch (ApiException $e) {
                $lastException = $e;

                // 404/405 означает, что конкретный endpoint не поддержан. Пробуем
                // следующий вариант. Остальные ошибки — авторизация, лимиты, сеть —
                // отдаём наверх, чтобы задача корректно записала ошибку.
                if (!in_array($e->getHttpStatus(), [404, 405], true)) {
                    throw $e;
                }
            }
        }

        if ($lastException) {
            throw $lastException;
        }

        return [];
    }

    /** Скачивает изображение через общий HTTP-клиент потоковой записью в файл. */
    public function downloadImageToFile(string $url, string $targetPath, int $maxBytes = 10485760): array {
        return $this->http->downloadFile($url, $targetPath, $maxBytes);
    }


    /** Нормализует группу товаров МойСклад в единый формат для категорий ocStore. */
    private function normalizeProductFolderRow(array $row): array {
        return [
            'id' => $this->extractId($row),
            'href' => (string)($row['meta']['href'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'external_code' => (string)($row['externalCode'] ?? ''),
            'archived' => $this->toBool($row['archived'] ?? false),
            'parent_id' => $this->extractProductFolderParentId($row['productFolder'] ?? []),
            'parent_href' => (string)($row['productFolder']['meta']['href'] ?? ''),
            'path_name' => (string)($row['pathName'] ?? ''),
            'updated' => (string)($row['updated'] ?? '')
        ];
    }

    /**
     * Нормализует карточку товара МойСклад в единый формат для ProductSyncService.
     */
    private function normalizeProductRow(array $row): array {
        $salePrices = [];

        foreach (($row['salePrices'] ?? []) as $price) {
            if (!is_array($price)) {
                continue;
            }

            $priceTypeMeta = $price['priceType']['meta'] ?? [];
            $priceValue = $price['value'] ?? null;

            $salePrices[] = [
                'price_type_id' => $this->extractIdFromMeta(is_array($priceTypeMeta) ? $priceTypeMeta : []),
                'price_type_href' => (string)($priceTypeMeta['href'] ?? ''),
                'price_type_name' => (string)($price['priceType']['name'] ?? ''),
                // В МойСклад денежные значения обычно приходят в копейках/центах.
                'value' => is_numeric($priceValue) ? ((float)$priceValue / 100) : null,
                'raw_value' => is_numeric($priceValue) ? (float)$priceValue : null,
            ];
        }

        $productFolder = is_array($row['productFolder'] ?? null) ? $row['productFolder'] : [];
        $productFolderMeta = is_array($productFolder['meta'] ?? null) ? $productFolder['meta'] : [];
        $categoryId = $this->extractIdFromMeta($productFolderMeta);

        // В некоторых ответах МойСклад productFolder приходит раскрытым объектом с id,
        // но без meta.href. Раньше в таком случае category_id оставался пустым, товар
        // создавался без категории, а сама категория даже не пыталась создаться.
        if ($categoryId === '' && $productFolder) {
            $categoryId = $this->extractId($productFolder);
        }

        return [
            'id' => $this->extractId($row),
            'href' => (string)($row['meta']['href'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'external_code' => (string)($row['externalCode'] ?? ''),
            'article' => (string)($row['article'] ?? ''),
            'code' => (string)($row['code'] ?? ''),
            'archived' => $this->toBool($row['archived'] ?? false),
            'category_id' => $categoryId,
            'category_href' => (string)($productFolderMeta['href'] ?? ''),
            // Если productFolder раскрыт через expand, здесь будет имя категории.
            // Сервис категорий сможет создать категорию без дополнительного запроса.
            'category' => $categoryId !== '' ? [
                'id' => $categoryId,
                'href' => (string)($productFolderMeta['href'] ?? ''),
                'name' => (string)($productFolder['name'] ?? ''),
                'description' => (string)($productFolder['description'] ?? ''),
                'external_code' => (string)($productFolder['externalCode'] ?? ''),
                'archived' => $this->toBool($productFolder['archived'] ?? false),
                'parent_id' => $this->extractProductFolderParentId($productFolder['productFolder'] ?? []),
                'parent_href' => (string)($productFolder['productFolder']['meta']['href'] ?? ''),
                'path_name' => (string)($productFolder['pathName'] ?? ''),
                'updated' => (string)($productFolder['updated'] ?? '')
            ] : [],
            'sale_prices' => $salePrices,
            // pathName — запасной источник категории. В некоторых аккаунтах API не
            // возвращает productFolder в карточке товара, но возвращает путь группы.
            // По этому пути мы можем создать виртуальную цепочку категорий, чтобы
            // товар не оставался без раздела.
            'path_name' => (string)($row['pathName'] ?? ''),
            'attributes' => $this->normalizeAttributes($row['attributes'] ?? []),
            'manufacturer_name' => $this->extractManufacturerName($row),
            'weight' => isset($row['weight']) && is_numeric($row['weight']) ? (float)$row['weight'] : null,
            'quantity' => isset($row['quantity']) && is_numeric($row['quantity']) ? (float)$row['quantity'] : null,
            'quantity_known' => isset($row['quantity']) && is_numeric($row['quantity']),
            'updated' => (string)($row['updated'] ?? '')
        ];
    }

    /**
     * Достает остаток выбранного склада из строки stock/bystore.
     */
    private function extractStoreStockValues(array $row): array {
        $stock = $row['stock'] ?? $row['quantity'] ?? 0;
        $reserve = $row['reserve'] ?? 0;
        $inTransit = $row['inTransit'] ?? 0;

        if (!empty($row['stockByStore']) && is_array($row['stockByStore'])) {
            // Фильтр store оставляет в массиве один нужный склад. На всякий случай
            // берем первую строку с числовым остатком.
            foreach ($row['stockByStore'] as $storeRow) {
                if (!is_array($storeRow)) {
                    continue;
                }

                if (isset($storeRow['stock']) && is_numeric($storeRow['stock'])) {
                    $stock = $storeRow['stock'];
                    $reserve = $storeRow['reserve'] ?? 0;
                    $inTransit = $storeRow['inTransit'] ?? 0;
                    break;
                }
            }
        }

        return [
            'stock' => is_numeric($stock) ? (float)$stock : 0.0,
            'reserve' => is_numeric($reserve) ? (float)$reserve : 0.0,
            'in_transit' => is_numeric($inTransit) ? (float)$inTransit : 0.0,
        ];
    }


    /** Нормализует статус заказа поставщику. */
    private function normalizePurchaseOrderStateRow(array $row): array {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];

        return [
            'id' => $this->extractId($row) ?: $this->extractIdFromMeta($meta),
            'name' => (string)($row['name'] ?? ''),
            'href' => (string)($meta['href'] ?? ''),
            'color' => (string)($row['color'] ?? ''),
        ];
    }

    /** Нормализует заказ поставщику. */
    private function normalizePurchaseOrderRow(array $row): array {
        $state = is_array($row['state'] ?? null) ? $row['state'] : [];
        $stateMeta = is_array($state['meta'] ?? null) ? $state['meta'] : [];
        $store = is_array($row['store'] ?? null) ? $row['store'] : [];
        $storeMeta = is_array($store['meta'] ?? null) ? $store['meta'] : [];
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];

        return [
            'id' => $this->extractId($row),
            'href' => (string)($meta['href'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'moment' => (string)($row['moment'] ?? ''),
            'applicable' => $this->toBool($row['applicable'] ?? true),
            'state_id' => $this->extractIdFromMeta($stateMeta) ?: $this->extractId($state),
            'state_name' => (string)($state['name'] ?? ''),
            'state_href' => (string)($stateMeta['href'] ?? ''),
            'store_id' => $this->extractIdFromMeta($storeMeta) ?: $this->extractId($store),
            'store_name' => (string)($store['name'] ?? ''),
            'store_href' => (string)($storeMeta['href'] ?? ''),
        ];
    }

    /** Нормализует позицию заказа поставщику. */
    private function normalizePurchaseOrderPositionRow(array $row): array {
        $assortment = is_array($row['assortment'] ?? null) ? $row['assortment'] : [];
        $meta = is_array($assortment['meta'] ?? null) ? $assortment['meta'] : [];
        $assortmentType = (string)($meta['type'] ?? '');
        $assortmentId = $this->extractIdFromMeta($meta) ?: $this->extractId($assortment);

        // В заказах поставщикам в позициях может лежать не только product, но и
        // variant. Модификации мы пока не синхронизируем как отдельные опции, но
        // если МойСклад отдал variant с ссылкой на родительский product, импортируем
        // родительский товар. Иначе такие ожидаемые позиции молча пропускались.
        $parentProduct = is_array($assortment['product'] ?? null) ? $assortment['product'] : [];
        $parentProductMeta = is_array($parentProduct['meta'] ?? null) ? $parentProduct['meta'] : [];
        $parentProductId = $this->extractIdFromMeta($parentProductMeta) ?: $this->extractId($parentProduct);

        $productId = $assortmentType === 'variant' && $parentProductId !== '' ? $parentProductId : $assortmentId;
        $productHref = $assortmentType === 'variant' && !empty($parentProductMeta['href']) ? (string)$parentProductMeta['href'] : (string)($meta['href'] ?? '');
        $positionId = $this->extractId($row);

        if ($positionId === '') {
            $positionId = $productId !== '' ? $productId : hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE));
        }

        $orderedQuantity = isset($row['quantity']) && is_numeric($row['quantity']) ? (float)$row['quantity'] : 0.0;
        $shippedQuantity = isset($row['shipped']) && is_numeric($row['shipped']) ? (float)$row['shipped'] : 0.0;
        $inTransitQuantity = isset($row['inTransit']) && is_numeric($row['inTransit']) ? (float)$row['inTransit'] : null;

        // Для витрины "ожидается от поставщика" — это не весь объем строки
        // заказа, а только еще не принятая часть. В МойСклад у позиции заказа
        // поставщику API может вернуть quantity=заказано, shipped=уже принято,
        // inTransit=еще в пути. Если взять quantity целиком, уже оприходованные
        // строки снова попадут как предзаказ и могут держать товар с фактом 0.
        $remainingQuantity = $inTransitQuantity !== null
            ? $inTransitQuantity
            : max(0.0, $orderedQuantity - $shippedQuantity);

        return [
            'id' => $positionId,
            'product_id' => $productId,
            'product_href' => $productHref,
            'assortment_id' => $assortmentId,
            'type' => $assortmentType,
            'name' => (string)($assortment['name'] ?? $row['name'] ?? ''),
            // В активной бизнес-логике quantity позиции = остаток к поступлению.
            'quantity' => max(0.0, $remainingQuantity),
            'ordered_quantity' => max(0.0, $orderedQuantity),
            'shipped_quantity' => max(0.0, $shippedQuantity),
            'in_transit_quantity' => $inTransitQuantity !== null ? max(0.0, $inTransitQuantity) : null,
        ];
    }

    /**
     * Приводит строку изображения/файла к единому формату.
     *
     * МойСклад может отдавать прямую ссылку в разных полях: downloadHref, href,
     * meta.href, miniature/tiny. Для оригинала сначала предпочитаем downloadHref,
     * затем href. Миниатюры не используем как основной источник, чтобы в магазин
     * не попали маленькие картинки вместо нормальных.
     */
    private function normalizeImageRow(string $productId, array $row): array {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];

        // В ответах МойСклад часть полей картинки может быть не строкой, а объектом:
        // например miniature/tiny иногда приходят массивом с href, а downloadHref может
        // лежать внутри meta. Никогда не приводим такие значения напрямую к string,
        // иначе PHP выводит warning "Array to string conversion" и ломает JSON-ответ
        // AJAX-запроса в админке.
        $downloadUrl = $this->firstUrl(
            $row['downloadHref'] ?? null,
            $row['href'] ?? null,
            $row['url'] ?? null,
            $meta['downloadHref'] ?? null,
            $meta['href'] ?? null,
            $row['image'] ?? null,
            $row['miniature'] ?? null,
            $row['tiny'] ?? null
        );

        $filename = $this->firstString(
            $row['filename'] ?? null,
            $row['name'] ?? null,
            $row['title'] ?? null,
            $meta['filename'] ?? null
        );

        if ($filename === '' && $downloadUrl !== '') {
            $path = (string)(parse_url($downloadUrl, PHP_URL_PATH) ?: '');
            $basename = basename($path);
            $filename = $basename !== '' && $basename !== '.' ? $basename : '';
        }

        $id = $this->firstString($row['id'] ?? null, $meta['id'] ?? null);

        if ($id === '') {
            $id = $this->extractIdFromMeta($meta);
        }

        if ($id === '' && $downloadUrl !== '') {
            // Для некоторых файлов API не дает отдельный id. Делаем стабильный id
            // от ссылки, чтобы повторный запуск не создавал дубли.
            $id = hash('sha256', $downloadUrl);
        }

        return [
            'id' => $id,
            'product_id' => $productId,
            'name' => $filename,
            'filename' => $filename,
            'href' => $this->firstUrl($meta['href'] ?? null, $row['href'] ?? null),
            'download_url' => $downloadUrl,
            'miniature_url' => $this->firstUrl($row['miniature'] ?? null, $row['tiny'] ?? null),
            'content_type' => $this->firstString($row['contentType'] ?? null, $row['mimeType'] ?? null, $meta['mediaType'] ?? null),
            'size' => $this->toIntOrNull($row['size'] ?? $meta['size'] ?? null),
            'updated' => $this->firstString($row['updated'] ?? null),
        ];
    }

    /**
     * Возвращает первое строковое значение из списка.
     *
     * API МойСклад не всегда стабилен по форме вложенных полей: то, что в одном
     * ответе является строкой, в другом может быть массивом. Этот helper нужен,
     * чтобы не ловить warning "Array to string conversion".
     */
    private function firstString(mixed ...$values): string {
        foreach ($values as $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $string = trim((string)$value);

                if ($string !== '') {
                    return $string;
                }
            }
        }

        return '';
    }

    /**
     * Достает URL из строки или из массива вида ['href' => '...'] / ['meta' => ['href' => '...']].
     */
    private function firstUrl(mixed ...$values): string {
        foreach ($values as $value) {
            $url = $this->extractUrl($value);

            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function extractUrl(mixed $value): string {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $string = trim((string)$value);
            return $string !== '' ? $string : '';
        }

        if (!is_array($value)) {
            return '';
        }

        // Сначала самые вероятные поля прямой ссылки.
        foreach (['downloadHref', 'href', 'url'] as $key) {
            if (array_key_exists($key, $value)) {
                $url = $this->extractUrl($value[$key]);

                if ($url !== '') {
                    return $url;
                }
            }
        }

        // Затем ссылка внутри meta.
        if (isset($value['meta']) && is_array($value['meta'])) {
            foreach (['downloadHref', 'href', 'url'] as $key) {
                if (array_key_exists($key, $value['meta'])) {
                    $url = $this->extractUrl($value['meta'][$key]);

                    if ($url !== '') {
                        return $url;
                    }
                }
            }
        }

        return '';
    }

    private function toIntOrNull(mixed $value): ?int {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int)$value;
        }

        return null;
    }

    private function normalizeRows(array $result, callable $mapper): array {
        $rows = [];

        // Большинство списочных endpoint'ов МойСклад возвращают коллекцию в rows.
        // Некоторые справочники могут вернуть массив сразу — поддерживаем оба варианта.
        $sourceRows = $result['rows'] ?? $result;

        if (!is_array($sourceRows)) {
            return [];
        }

        foreach ($sourceRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = $mapper($row);

            // Элемент без id нельзя надежно связать с ocStore, поэтому пропускаем.
            if (($item['id'] ?? '') === '') {
                continue;
            }

            $rows[] = $item;
        }

        return $rows;
    }

    private function normalizeAttributes(mixed $attributes): array {
        if (!is_array($attributes)) {
            return [];
        }

        $result = [];

        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $name = trim((string)($attribute['name'] ?? ''));
            $value = $attribute['value'] ?? '';

            if ($name === '') {
                continue;
            }

            if (is_array($value)) {
                $value = $value['name'] ?? $value['id'] ?? json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $value = $value ? 'Да' : 'Нет';
            }

            $value = trim((string)$value);

            // Пустые характеристики не создаем: они только засоряют атрибуты магазина.
            if ($value === '') {
                continue;
            }

            $result[] = [
                'name' => $name,
                'value' => $value
            ];
        }

        return $result;
    }

    private function extractManufacturerName(array $row): string {
        // В разных аккаунтах производитель может храниться по-разному. На первой
        // версии берем только очевидные варианты, без сложного ручного маппинга.
        foreach (['manufacturer', 'manufacturerName', 'country'] as $key) {
            if (!empty($row[$key])) {
                if (is_array($row[$key])) {
                    return trim((string)($row[$key]['name'] ?? ''));
                }

                return trim((string)$row[$key]);
            }
        }

        return '';
    }


    /**
     * Достает ID родительской группы товаров из разных форматов productFolder.
     *
     * МойСклад может вернуть родителя как объект с meta.href, как раскрытый объект
     * с id, либо вообще не вернуть. Эта маленькая нормализация нужна, чтобы дерево
     * категорий не разваливалось из-за различий формата ответа API.
     */
    private function extractProductFolderParentId(mixed $parent): string {
        if (!is_array($parent) || !$parent) {
            return '';
        }

        $id = $this->extractIdFromMeta(is_array($parent['meta'] ?? null) ? $parent['meta'] : []);

        if ($id !== '') {
            return $id;
        }

        return $this->extractId($parent);
    }

    private function extractIdFromMeta(array $meta): string {
        if (!empty($meta['id'])) {
            return (string)$meta['id'];
        }

        $href = (string)($meta['href'] ?? '');

        if ($href === '') {
            return '';
        }

        // В отчетах МойСклад href иногда приходит с query string, например
        // /entity/product/<id>?expand=supplier. Если не убрать ?expand, связь по ID
        // не совпадет с ID карточки товара, и остатки будут пропускаться.
        $path = (string)(parse_url($href, PHP_URL_PATH) ?: $href);
        $parts = explode('/', trim($path, '/'));

        return (string)end($parts);
    }

    private function extractId(array $row): string {
        if (!empty($row['id'])) {
            return (string)$row['id'];
        }

        $href = (string)($row['meta']['href'] ?? '');

        if ($href === '') {
            return '';
        }

        $path = (string)(parse_url($href, PHP_URL_PATH) ?: $href);
        $parts = explode('/', trim($path, '/'));

        return (string)end($parts);
    }


    /**
     * Безопасно приводит значения МойСклад к boolean.
     *
     * Почему это нужно: API или промежуточные слои могут вернуть archived не
     * только как true/false, но и как строки "true"/"false" или числа 1/0.
     * В PHP строка "false" считается непустой и при обычном !empty() дает true,
     * поэтому категории/товары могли ошибочно отключаться.
     */
    private function toBool(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'n', 'off', ''], true)) {
                return false;
            }
        }

        return false;
    }

    private function normalizeLimit(int $limit): int {
        // Для админских выпадающих списков 100 достаточно и не нагружает API.
        return max(1, min(100, $limit));
    }
}
