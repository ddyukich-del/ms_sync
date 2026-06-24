<?php
namespace MoyskladSync;

/**
 * Сервис загрузки изображений товаров.
 *
 * Этот сервис intentionally очень осторожный: один вызов обрабатывает только
 * маленький пакет товаров, а файлы скачиваются потоково через MoyskladClient.
 * На слабом сервере изображения — самая тяжелая часть синхронизации, поэтому
 * здесь нет больших массивов, параллельных загрузок и длинных циклов.
 */
class ImageSyncService {
    private MoyskladClient $client;
    private object $imageModel;
    private object $taskModel;

    public function __construct(MoyskladClient $client, object $imageModel, object $taskModel) {
        $this->client = $client;
        $this->imageModel = $imageModel;
        $this->taskModel = $taskModel;
    }

    /**
     * Обрабатывает один пакет товаров для загрузки изображений.
     *
     * offset_value здесь — это не offset API МойСклад, а cursor по product_id в
     * таблице moysklad_product_link. Такой cursor быстрее и стабильнее для
     * больших таблиц, чем OFFSET.
     */
    public function syncPage(array $task, array $settings): array {
        $taskId = (int)$task['task_id'];
        $limit = max(1, min(50, (int)$task['limit_value']));
        $lastLinkId = max(0, (int)$task['offset_value']);

        $batch = $this->imageModel->getProductsForImageSync($lastLinkId, $limit);
        $rows = $batch['rows'];
        $lastCursor = (int)$batch['last_cursor'];

        $stats = [
            'processed_items' => 0,
            'created_items' => 0,
            'updated_items' => 0,
            'skipped_items' => 0,
            'deleted_items' => 0,
            'disabled_items' => 0,
            'error_items' => 0,
        ];

        $maxImagesPerProduct = max(1, min(20, (int)($settings['module_moysklad_sync_max_images_per_product'] ?? 5)));
        $maxImageBytes = max(1024 * 1024, min(30 * 1024 * 1024, (int)($settings['module_moysklad_sync_max_image_bytes'] ?? (10 * 1024 * 1024))));

        foreach ($rows as $productLink) {
            $stats['processed_items']++;

            try {
                $result = $this->syncProductImages($productLink, $taskId, $maxImagesPerProduct, $maxImageBytes);
                $stats['created_items'] += $result['downloaded'];
                $stats['updated_items'] += $result['attached_existing'];
                $stats['skipped_items'] += $result['skipped'];
                $stats['error_items'] += $result['errors'];
            } catch (\Throwable $e) {
                // Ошибка по одному товару не должна останавливать загрузку картинок
                // для всех остальных товаров.
                $stats['error_items']++;
                $this->taskModel->addError($taskId, 'image', (string)($productLink['moysklad_id'] ?? ''), 'IMAGE_PRODUCT_SYNC_ERROR', $e->getMessage(), $productLink);
            }
        }

        $this->taskModel->updateTaskProgress($taskId, $lastCursor, $stats);

        if (!$batch['has_more']) {
            $this->taskModel->addLog($taskId, 'info', 'image', null, 'Загрузка изображений товаров завершена.');
            $this->taskModel->moveToStep($taskId, 'finish');
        }

        return $this->taskModel->getTask($taskId) ?: [];
    }

    /**
     * Загружает изображения одного товара.
     *
     * Первый файл из МойСклад считаем главным изображением. Остальные добавляем
     * как дополнительные. Уже загруженные файлы не скачиваем повторно: проверяем
     * служебную таблицу связей и наличие локального файла.
     */
    private function syncProductImages(array $productLink, int $taskId, int $maxImagesPerProduct, int $maxImageBytes): array {
        $productId = (int)$productLink['product_id'];
        $moyskladProductId = (string)$productLink['moysklad_id'];

        $stats = [
            'downloaded' => 0,
            'attached_existing' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if ($productId <= 0 || $moyskladProductId === '') {
            $stats['skipped']++;
            return $stats;
        }

        $images = $this->client->getProductImages($moyskladProductId, 100);

        if (!$images) {
            $stats['skipped']++;
            return $stats;
        }

        $index = 0;

        foreach ($images as $image) {
            if ($index >= $maxImagesPerProduct) {
                // Не считаем это ошибкой: ограничение нужно для защиты слабого
                // сервера. Следующие изображения можно будет добавить позже,
                // когда сделаем более детальный cursor по изображениям товара.
                break;
            }

            $index++;
            $isMain = $index === 1;
            $imageId = (string)($image['id'] ?? '');
            $downloadUrl = (string)($image['download_url'] ?? '');

            if ($imageId === '' || $downloadUrl === '') {
                $stats['skipped']++;
                continue;
            }

            try {
                $localPath = $this->imageModel->buildLocalPath($productId, $image);
                $existingLink = $this->imageModel->getImageLink($moyskladProductId, $imageId);

                if ($existingLink && $this->imageModel->localFileExists((string)$existingLink['local_path'])) {
                    // Связь и файл уже есть. На всякий случай заново прикрепляем
                    // изображение к товару: это чинит ситуацию, когда связь есть,
                    // а product.image/product_image были удалены вручную.
                    $this->imageModel->registerDownloadedImage($productId, $moyskladProductId, $image, (string)$existingLink['local_path'], $taskId, $isMain);
                    $stats['attached_existing']++;
                    continue;
                }

                $absolutePath = $this->imageModel->getAbsoluteImagePath($localPath);
                $this->client->downloadImageToFile($downloadUrl, $absolutePath, $maxImageBytes);

                // Проверяем, что скачался именно файл изображения. Это защищает
                // магазин от ситуации, когда API/прокси вернул HTML-страницу ошибки,
                // а мы сохранили ее с расширением .jpg.
                if (!@getimagesize($absolutePath)) {
                    @unlink($absolutePath);
                    throw new \RuntimeException('Скачанный файл не является изображением.');
                }

                $this->imageModel->registerDownloadedImage($productId, $moyskladProductId, $image, $localPath, $taskId, $isMain);
                $stats['downloaded']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->taskModel->addError($taskId, 'image', $imageId, 'IMAGE_DOWNLOAD_ERROR', $e->getMessage(), [
                    'product_id' => $productId,
                    'moysklad_product_id' => $moyskladProductId,
                    'image' => $image,
                ]);
            }
        }

        return $stats;
    }
}
