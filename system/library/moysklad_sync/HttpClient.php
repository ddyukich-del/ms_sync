<?php
namespace MoyskladSync;

/**
 * Низкоуровневый HTTP-клиент для МойСклад.
 *
 * Задача этого класса — только сеть: собрать URL, добавить заголовки,
 * выполнить запрос, разобрать JSON и превратить ошибки в ApiException.
 * Бизнес-логики синхронизации здесь быть не должно.
 */
class HttpClient {
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private int $connectTimeout;
    private int $maxRetries;
    private bool $debugEnabled;

    public function __construct(
        string $token,
        string $baseUrl = 'https://api.moysklad.ru/api/remap/1.2',
        int $timeout = 20,
        int $connectTimeout = 5,
        int $maxRetries = 2,
        bool $debugEnabled = false
    ) {
        $this->token = trim($token);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->maxRetries = max(0, $maxRetries);
        $this->debugEnabled = $debugEnabled;
    }

    /**
     * Выполняет JSON-запрос к API.
     *
     * Важно для слабого сервера: метод не делает параллельных запросов и не держит
     * соединение дольше нужного. Повторы короткие и только для временных ошибок.
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array {
        if ($this->token === '') {
            throw new ApiException('Не указан API-токен МойСклад.');
        }

        $url = $this->buildUrl($path, $query);
        $attempt = 0;

        do {
            $attempt++;
            $response = $this->send($method, $url, $body);

            if (!$this->shouldRetry($response['status']) || $attempt > $this->maxRetries + 1) {
                return $this->parseResponse($response);
            }

            // Короткая пауза нужна при 429/5xx, но не должна подвешивать админку надолго.
            usleep($attempt === 1 ? 250000 : 750000);
        } while (true);
    }



    /**
     * Скачивает файл по URL с авторизацией МойСклад и пишет его сразу на диск.
     *
     * Для изображений нельзя использовать file_get_contents(): большой файл целиком
     * попадет в оперативную память. Здесь cURL пишет поток напрямую во временный
     * файл, поэтому даже слабый сервер расходует минимум памяти.
     */
    public function downloadFile(string $url, string $targetPath, int $maxBytes = 10485760): array {
        if ($this->token === '') {
            throw new ApiException('Не указан API-токен МойСклад.');
        }

        if (!function_exists('curl_init')) {
            throw new ApiException('На сервере недоступно PHP-расширение cURL, оно нужно для загрузки изображений МойСклад.');
        }

        $url = trim($url);

        if ($url === '') {
            throw new ApiException('Пустая ссылка на изображение МойСклад.');
        }

        $directory = dirname($targetPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ApiException('Не удалось создать директорию для изображения: ' . $directory);
        }

        $tmpPath = $targetPath . '.tmp';
        $handle = fopen($tmpPath, 'wb');

        if (!$handle) {
            throw new ApiException('Не удалось открыть временный файл для записи изображения: ' . $tmpPath);
        }

        $downloadedBytes = 0;
        $ch = curl_init($url);

        $headers = [
            'Accept: image/*,*/*;q=0.8',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: ocstore-moysklad-sync/1.1.5'
        ];

        curl_setopt_array($ch, [
            CURLOPT_FILE => $handle,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => max($this->timeout, 30),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function (...$args) use ($maxBytes, &$downloadedBytes): int {
                // В разных версиях PHP/cURL callback получает 4 или 5 аргументов.
                // Берем downloaded из правильной позиции, чтобы модуль не зависел
                // от конкретной версии PHP на хостинге.
                $downloaded = count($args) >= 5 ? (float)$args[2] : (float)($args[1] ?? 0);
                $downloadedBytes = (int)$downloaded;

                // Если МойСклад или прокси отдает слишком большой файл, прерываем
                // скачивание до того, как сервер заполнит диск.
                if ($maxBytes > 0 && $downloaded > $maxBytes) {
                    return 1;
                }

                return 0;
            }
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);

        curl_close($ch);
        fclose($handle);

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($tmpPath);

            if ($maxBytes > 0 && $downloadedBytes > $maxBytes) {
                throw new ApiException('Изображение превышает допустимый размер ' . $maxBytes . ' байт.', $status);
            }

            throw new ApiException('Не удалось скачать изображение МойСклад: ' . ($curlError ?: 'HTTP ' . $status), $status);
        }

        if (!rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);
            throw new ApiException('Не удалось сохранить изображение: ' . $targetPath);
        }

        clearstatcache(true, $targetPath);

        return [
            'path' => $targetPath,
            'size' => file_exists($targetPath) ? (int)filesize($targetPath) : 0,
            'content_type' => $contentType,
        ];
    }

    private function buildUrl(string $path, array $query): string {
        $path = '/' . ltrim($path, '/');
        $url = $this->baseUrl . $path;

        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    private function send(string $method, string $url, ?array $body): array {
        if (!function_exists('curl_init')) {
            throw new ApiException('На сервере недоступно PHP-расширение cURL, оно нужно для работы с API МойСклад.');
        }

        $headers = [
            'Accept: application/json;charset=utf-8',
            'Content-Type: application/json;charset=utf-8',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: ocstore-moysklad-sync/1.1.5'
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            $message = curl_error($ch) ?: 'Неизвестная ошибка cURL.';
            $this->appendApiDebugLog($method, $url, 0, '', 'CURL_ERROR: ' . $message);
            curl_close($ch);

            throw new ApiException('Ошибка соединения с МойСклад: ' . $message);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);

        // Диагностика реальных ответов API. В файл не пишем токен и заголовок
        // Authorization, только метод, URL, HTTP-статус и тело ответа МойСклад.
        // Это нужно именно для живой отладки различий API-ответов конкретного
        // аккаунта: например, где фактически лежит productFolder/pathName.
        $this->appendApiDebugLog($method, $url, $status, $rawHeaders, $rawBody);

        curl_close($ch);

        return [
            'status' => $status,
            'headers' => $rawHeaders,
            'body' => $rawBody
        ];
    }


    /**
     * Пишет сырые ответы МойСклад в текстовый файл на сервере.
     *
     * Файл: storage/logs/moysklad_sync_api_debug.log
     *
     * Важно: токен в файл не попадает. Лог нужен для диагностики реального
     * формата API-ответов. Если файл разрастается больше 20 МБ, он
     * автоматически ротируется в .old, чтобы не забить диск слабого сервера.
     */
    private function appendApiDebugLog(string $method, string $url, int $status, string $headers, string $body): void {
        $directory = defined('DIR_STORAGE') ? rtrim((string)DIR_STORAGE, '/\\') . '/logs/' : rtrim(sys_get_temp_dir(), '/\\') . '/';

        if (!is_dir($directory) || !is_writable($directory)) {
            return;
        }

        $file = $directory . 'moysklad_sync_api_debug.log';

        try {
            if (is_file($file) && filesize($file) > 20 * 1024 * 1024) {
                @rename($file, $file . '.old');
            }

            $separator = str_repeat('=', 100);
            $entry = $separator . PHP_EOL
                . '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($method) . ' ' . $this->maskUrl($url) . PHP_EOL
                . 'HTTP_STATUS: ' . $status . PHP_EOL
                . 'RESPONSE_HEADERS:' . PHP_EOL . trim($this->sanitizeHeaders($headers)) . PHP_EOL
                . 'RESPONSE_BODY:' . PHP_EOL . $body . PHP_EOL;

            @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Лог API — диагностический. Он не должен ломать импорт, даже если
            // storage/logs временно недоступен для записи.
        }
    }

    private function maskUrl(string $url): string {
        // На всякий случай убираем access_token/query token, если когда-нибудь
        // появится альтернативная авторизация через query string. Bearer-токен
        // мы в URL не добавляем, но защита здесь не лишняя.
        return preg_replace('/(access[_-]?token|token)=([^&]+)/i', '$1=***', $url) ?: $url;
    }

    private function sanitizeHeaders(string $headers): string {
        $lines = preg_split('/\r?\n/', $headers) ?: [];
        $safe = [];

        foreach ($lines as $line) {
            if (stripos($line, 'authorization:') === 0) {
                $safe[] = 'Authorization: ***';
            } else {
                $safe[] = $line;
            }
        }

        return implode(PHP_EOL, $safe);
    }

    private function shouldRetry(int $status): bool {
        // 429 — лимит API, 5xx — временные ошибки на стороне сервиса или сети.
        return in_array($status, [429, 500, 502, 503, 504], true);
    }

    private function parseResponse(array $response): array {
        $status = (int)$response['status'];
        $body = trim((string)$response['body']);
        $decoded = [];

        if ($body !== '') {
            $decoded = json_decode($body, true);

            if (!is_array($decoded)) {
                throw new ApiException('МойСклад вернул некорректный JSON.', $status, null, ['body' => mb_substr($body, 0, 500)]);
            }
        }

        if ($status < 200 || $status >= 300) {
            $error = $this->extractError($decoded);

            throw new ApiException(
                $error['message'],
                $status,
                $error['code'],
                ['response' => $decoded]
            );
        }

        return $decoded;
    }

    private function extractError(array $decoded): array {
        $message = 'API МойСклад вернул ошибку.';
        $code = null;

        if (!empty($decoded['errors'][0]) && is_array($decoded['errors'][0])) {
            $first = $decoded['errors'][0];
            $message = (string)($first['error'] ?? $first['message'] ?? $message);
            $code = isset($first['code']) ? (string)$first['code'] : null;
        } elseif (!empty($decoded['error'])) {
            $message = (string)$decoded['error'];
        }

        return ['message' => $message, 'code' => $code];
    }
}
