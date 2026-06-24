<?php
namespace Opencart\Admin\Model\Extension\MoyskladSync\Module;

/**
 * Модель изображений МойСклад.
 *
 * Здесь находится только работа с БД и файловой структурой ocStore. Сетевые
 * запросы и скачивание выполняет ImageSyncService через MoyskladClient. Такое
 * разделение нужно, чтобы не смешивать API, файловую систему и SQL в одном
 * большом классе.
 */
class MoyskladImage extends \Opencart\System\Engine\Model {
    /**
     * Берет небольшой пакет товаров, для которых уже есть связь с МойСклад.
     *
     * Используем cursor по product_id вместо OFFSET. На больших таблицах OFFSET
     * становится все дороже, а условие product_id > last_id хорошо работает по
     * уникальной связи товара и не нагружает слабый MySQL.
     *
     * Раньше здесь использовался link_id, но на ранних тестовых установках таблица
     * связей могла быть создана без этой колонки. product_id надежнее для
     * обратной совместимости.
     */
    public function getProductsForImageSync(int $lastLinkId, int $limit): array {
        $limit = max(1, min(50, $limit));

        $query = $this->db->query("SELECT l.`product_id`, l.`moysklad_id`, l.`moysklad_href`, p.`image`
            FROM `" . DB_PREFIX . "moysklad_product_link` l
            INNER JOIN `" . DB_PREFIX . "product` p ON p.`product_id` = l.`product_id`
            WHERE l.`product_id` > '" . (int)$lastLinkId . "'
            ORDER BY l.`product_id` ASC
            LIMIT " . (int)$limit);

        $lastCursor = $lastLinkId;

        foreach ($query->rows as $row) {
            $lastCursor = max($lastCursor, (int)$row['product_id']);
        }

        return [
            'rows' => $query->rows,
            'last_cursor' => $lastCursor,
            'has_more' => $query->num_rows === $limit,
        ];
    }

    /** Возвращает уже сохраненную связь изображения, если она есть. */
    public function getImageLink(string $moyskladProductId, string $moyskladImageId): ?array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "moysklad_image_link`
            WHERE `moysklad_product_id` = '" . $this->db->escape($moyskladProductId) . "'
              AND `moysklad_image_id` = '" . $this->db->escape($moyskladImageId) . "'
            LIMIT 1");

        return $query->num_rows ? $query->row : null;
    }

    /** Проверяет, существует ли локальный файл изображения. */
    public function localFileExists(string $relativePath): bool {
        $relativePath = $this->normalizeRelativePath($relativePath);

        return $relativePath !== '' && is_file(DIR_IMAGE . $relativePath);
    }

    /**
     * Строит относительный путь внутри image/ для файла товара.
     *
     * Файл кладем в catalog/moysklad/product_{id}/, чтобы не засорять корень
     * image/catalog и чтобы было легко понять, какие файлы принадлежат интеграции.
     */
    public function buildLocalPath(int $productId, array $image): string {
        $imageId = $this->sanitizeFilePart((string)($image['id'] ?? 'image'));
        $extension = $this->detectExtension($image);

        return 'catalog/moysklad/product_' . (int)$productId . '/' . $imageId . '.' . $extension;
    }

    /** Возвращает абсолютный путь для записи файла на диск. */
    public function getAbsoluteImagePath(string $relativePath): string {
        return DIR_IMAGE . $this->normalizeRelativePath($relativePath);
    }

    /**
     * Регистрирует скачанное изображение в ocStore и в служебной таблице связей.
     *
     * Первое изображение товара назначаем главным в product.image. Остальные —
     * добавляем в product_image. Перед вставкой проверяем дубли, чтобы повторный
     * запуск кнопки «Загрузить картинки» не плодил одинаковые записи.
     */
    public function registerDownloadedImage(int $productId, string $moyskladProductId, array $image, string $relativePath, int $taskId, bool $isMain): void {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $absolutePath = DIR_IMAGE . $relativePath;
        $fileSize = is_file($absolutePath) ? (int)filesize($absolutePath) : 0;
        $fileHash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;
        $moyskladImageId = (string)($image['id'] ?? '');
        $downloadUrl = (string)($image['download_url'] ?? $image['href'] ?? '');

        if ($moyskladImageId === '') {
            throw new \InvalidArgumentException('У изображения МойСклад нет ID.');
        }

        if ($isMain) {
            $this->setMainImage($productId, $relativePath);
        } else {
            $this->addAdditionalImage($productId, $relativePath);
        }

        // Если первое изображение уже было загружено раньше как дополнительное,
        // а теперь стало главным, снимаем флаг main с остальных ссылок товара.
        if ($isMain) {
            $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_image_link`
                SET `is_main` = '0', `updated_at` = NOW()
                WHERE `product_id` = '" . (int)$productId . "'");
        }

        $exists = $this->getImageLink($moyskladProductId, $moyskladImageId);

        if ($exists) {
            $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_image_link` SET
                `product_id` = '" . (int)$productId . "',
                `moysklad_image_url` = '" . $this->db->escape($downloadUrl) . "',
                `local_path` = '" . $this->db->escape($relativePath) . "',
                `file_hash` = " . ($fileHash ? "'" . $this->db->escape($fileHash) . "'" : 'NULL') . ",
                `file_size` = '" . (int)$fileSize . "',
                `is_main` = '" . ($isMain ? 1 : 0) . "',
                `last_seen_task_id` = '" . (int)$taskId . "',
                `updated_at` = NOW()
                WHERE `image_link_id` = '" . (int)$exists['image_link_id'] . "'");

            return;
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "moysklad_image_link` SET
            `product_id` = '" . (int)$productId . "',
            `moysklad_product_id` = '" . $this->db->escape($moyskladProductId) . "',
            `moysklad_image_id` = '" . $this->db->escape($moyskladImageId) . "',
            `moysklad_image_url` = '" . $this->db->escape($downloadUrl) . "',
            `local_path` = '" . $this->db->escape($relativePath) . "',
            `file_hash` = " . ($fileHash ? "'" . $this->db->escape($fileHash) . "'" : 'NULL') . ",
            `file_size` = '" . (int)$fileSize . "',
            `is_main` = '" . ($isMain ? 1 : 0) . "',
            `last_seen_task_id` = '" . (int)$taskId . "',
            `created_at` = NOW(),
            `updated_at` = NOW()");
    }

    /** Обновляет главное изображение товара. */
    private function setMainImage(int $productId, string $relativePath): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET
            `image` = '" . $this->db->escape($relativePath) . "',
            `date_modified` = NOW()
            WHERE `product_id` = '" . (int)$productId . "'");
    }

    /** Добавляет дополнительное изображение, если такого еще нет. */
    private function addAdditionalImage(int $productId, string $relativePath): void {
        $exists = $this->db->query("SELECT `product_image_id` FROM `" . DB_PREFIX . "product_image`
            WHERE `product_id` = '" . (int)$productId . "'
              AND `image` = '" . $this->db->escape($relativePath) . "'
            LIMIT 1");

        if ($exists->num_rows) {
            return;
        }

        $sort = $this->db->query("SELECT COALESCE(MAX(`sort_order`), 0) + 1 AS next_sort
            FROM `" . DB_PREFIX . "product_image`
            WHERE `product_id` = '" . (int)$productId . "'");

        $this->db->query("INSERT INTO `" . DB_PREFIX . "product_image` SET
            `product_id` = '" . (int)$productId . "',
            `image` = '" . $this->db->escape($relativePath) . "',
            `sort_order` = '" . (int)($sort->row['next_sort'] ?? 1) . "'");
    }

    private function normalizeRelativePath(string $path): string {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private function sanitizeFilePart(string $value): string {
        $value = trim($value);
        $value = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $value) ?: 'image';

        return trim($value, '_') ?: 'image';
    }

    /**
     * Определяет расширение файла по имени, content-type или ссылке.
     *
     * Не доверяем расширению слепо: разрешаем только безопасные форматы, которые
     * нормально поддерживаются браузерами и ocStore.
     */
    private function detectExtension(array $image): string {
        $candidates = [
            (string)($image['filename'] ?? ''),
            (string)($image['name'] ?? ''),
            (string)($image['download_url'] ?? ''),
            (string)($image['content_type'] ?? ''),
        ];

        $joined = strtolower(implode(' ', $candidates));

        if (str_contains($joined, 'image/png') || preg_match('/\.png(\?|$)/', $joined)) {
            return 'png';
        }

        if (str_contains($joined, 'image/webp') || preg_match('/\.webp(\?|$)/', $joined)) {
            return 'webp';
        }

        if (str_contains($joined, 'image/gif') || preg_match('/\.gif(\?|$)/', $joined)) {
            return 'gif';
        }

        return 'jpg';
    }
}
