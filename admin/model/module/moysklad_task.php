<?php
namespace Opencart\Admin\Model\Extension\MoyskladSync\Module;

/**
 * @author d_dyuk
 */

/**
 * Модель очереди задач.
 *
 * Здесь сосредоточены все SQL-операции по задачам, логам и ошибкам. Контроллер
 * не должен вручную писать в эти таблицы: так проще контролировать блокировки,
 * индексы и поведение на слабом сервере.
 */
class MoyskladTask extends \Opencart\System\Engine\Model {
    private const ACTIVE_STATUSES = ['new', 'running', 'paused'];

    /**
     * Создает новую задачу, если сейчас нет другой активной задачи.
     *
     * Мы специально разрешаем только одну активную задачу на модуль. Даже если
     * технически можно обновлять остатки параллельно с картинками, слабый сервер
     * от этого будет страдать, а каталог может получить конфликтующие записи.
     */
    public function createTask(string $type, int $limit, array $payload = []): array {
        $activeTask = $this->getActiveTask();

        if ($activeTask) {
            throw new \RuntimeException('Уже есть активная задача #' . (int)$activeTask['task_id'] . '. Завершите или остановите ее перед запуском новой.');
        }

        $type = $this->db->escape($type);
        $payloadJson = $this->db->escape(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $limit = max(1, $limit);

        $this->db->query("INSERT INTO `" . DB_PREFIX . "moysklad_sync_task` SET
            `task_type` = '" . $type . "',
            `status` = 'new',
            `current_step` = 'init',
            `limit_value` = '" . (int)$limit . "',
            `payload` = '" . $payloadJson . "',
            `created_at` = NOW(),
            `updated_at` = NOW()");

        $taskId = (int)$this->db->getLastId();

        $this->addLog($taskId, 'info', 'task', (string)$taskId, 'Создана задача типа ' . $type . '.');

        return $this->getTask($taskId) ?: [];
    }

    public function getTask(int $taskId): ?array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "moysklad_sync_task` WHERE `task_id` = '" . (int)$taskId . "' LIMIT 1");

        return $query->num_rows ? $query->row : null;
    }

    public function getLatestTask(): ?array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "moysklad_sync_task` ORDER BY `task_id` DESC LIMIT 1");

        return $query->num_rows ? $query->row : null;
    }

    public function getActiveTask(): ?array {
        $statuses = array_map(fn(string $status): string => "'" . $this->db->escape($status) . "'", self::ACTIVE_STATUSES);

        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "moysklad_sync_task`
            WHERE `status` IN (" . implode(',', $statuses) . ")
            ORDER BY `task_id` DESC
            LIMIT 1");

        return $query->num_rows ? $query->row : null;
    }

    /**
     * Захватывает задачу для одного AJAX-шага.
     *
     * Условие `locked_until < NOW()` защищает от параллельного выполнения двух
     * шагов одной задачи. Если предыдущий PHP-процесс упал, блокировка истечет,
     * и задачу можно будет продолжить без ручной правки базы.
     */
    public function acquireTask(int $taskId, int $ttlSeconds): bool {
        $ttlSeconds = max(30, $ttlSeconds);

        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_sync_task` SET
            `status` = 'running',
            `started_at` = IF(`started_at` IS NULL, NOW(), `started_at`),
            `locked_until` = DATE_ADD(NOW(), INTERVAL " . (int)$ttlSeconds . " SECOND),
            `updated_at` = NOW()
            WHERE `task_id` = '" . (int)$taskId . "'
              AND `status` IN ('new', 'running', 'paused')
              AND (`locked_until` IS NULL OR `locked_until` < NOW() OR `status` IN ('new', 'paused'))");

        return $this->db->countAffected() > 0;
    }


    /**
     * Обновляет прогресс задачи и суммарные счетчики.
     *
     * offset_value в разных шагах может означать разное:
     * - для API МойСклад это offset страницы;
     * - для обработки missing/rebuild это cursor по link_id.
     * Важно, что TaskRunner не знает этих деталей, он только хранит прогресс.
     */
    public function updateTaskProgress(int $taskId, int $offsetValue, array $deltas = [], ?int $totalItems = null): void {
        $allowedCounters = [
            'processed_items',
            'created_items',
            'updated_items',
            'skipped_items',
            'deleted_items',
            'disabled_items',
            'error_items'
        ];

        $sets = ["`offset_value` = '" . (int)max(0, $offsetValue) . "'", "`updated_at` = NOW()"];

        if ($totalItems !== null) {
            $sets[] = "`total_items` = '" . (int)max(0, $totalItems) . "'";
        }

        foreach ($allowedCounters as $counter) {
            if (!empty($deltas[$counter])) {
                $sets[] = "`" . $counter . "` = `" . $counter . "` + '" . (int)$deltas[$counter] . "'";
            }
        }

        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_sync_task` SET " . implode(', ', $sets) . " WHERE `task_id` = '" . (int)$taskId . "'");
    }

    public function releaseTask(int $taskId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_sync_task` SET
            `locked_until` = NULL,
            `updated_at` = NOW()
            WHERE `task_id` = '" . (int)$taskId . "'");
    }

    /**
     * Переводит задачу на следующий шаг и сбрасывает offset/cursor.
     *
     * $limitValue нужен для импорта: категории и товары лучше обрабатывать
     * разными батчами. Например, категории можно брать по 30, а товары по 20.
     */
    public function moveToStep(int $taskId, string $step, ?int $limitValue = null): void {
        $sets = [
            "`current_step` = '" . $this->db->escape($step) . "'",
            "`offset_value` = 0",
            "`attempts` = 0",
            "`updated_at` = NOW()"
        ];

        if ($limitValue !== null) {
            $sets[] = "`limit_value` = '" . (int)max(1, $limitValue) . "'";
        }

        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_sync_task` SET " . implode(', ', $sets) . "
            WHERE `task_id` = '" . (int)$taskId . "'");
    }

    public function finishTask(int $taskId): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_sync_task` SET
            `status` = IF(`error_items` > 0, 'finished_with_errors', 'finished'),
            `current_step` = 'finish',
            `locked_until` = NULL,
            `finished_at` = NOW(),
            `updated_at` = NOW()
            WHERE `task_id` = '" . (int)$taskId . "'");
    }

    public function failTask(int $taskId, string $message): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_sync_task` SET
            `status` = 'failed',
            `last_error` = '" . $this->db->escape($message) . "',
            `error_items` = `error_items` + 1,
            `locked_until` = NULL,
            `finished_at` = NOW(),
            `updated_at` = NOW()
            WHERE `task_id` = '" . (int)$taskId . "'");

        $this->addError($taskId, 'task', (string)$taskId, 'TASK_FAILED', $message);
    }

    public function stopActiveTask(): ?array {
        $task = $this->getActiveTask();

        if (!$task) {
            return null;
        }

        $this->db->query("UPDATE `" . DB_PREFIX . "moysklad_sync_task` SET
            `status` = 'stopped',
            `locked_until` = NULL,
            `finished_at` = NOW(),
            `updated_at` = NOW()
            WHERE `task_id` = '" . (int)$task['task_id'] . "'");

        $this->addLog((int)$task['task_id'], 'warning', 'task', (string)$task['task_id'], 'Задача остановлена администратором.');

        return $this->getTask((int)$task['task_id']);
    }

    public function addLog(?int $taskId, string $level, ?string $entityType, ?string $entityId, string $message, array $context = []): void {
        $contextJson = $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;

        $this->db->query("INSERT INTO `" . DB_PREFIX . "moysklad_sync_log` SET
            `task_id` = " . ($taskId ? "'" . (int)$taskId . "'" : 'NULL') . ",
            `level` = '" . $this->db->escape($level) . "',
            `entity_type` = " . ($entityType !== null ? "'" . $this->db->escape($entityType) . "'" : 'NULL') . ",
            `entity_id` = " . ($entityId !== null ? "'" . $this->db->escape($entityId) . "'" : 'NULL') . ",
            `message` = '" . $this->db->escape($message) . "',
            `context` = " . ($contextJson !== null ? "'" . $this->db->escape($contextJson) . "'" : 'NULL') . ",
            `created_at` = NOW()");
    }

    public function addError(?int $taskId, ?string $entityType, ?string $entityId, ?string $code, string $message, array $payload = []): void {
        $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

        $this->db->query("INSERT INTO `" . DB_PREFIX . "moysklad_sync_error` SET
            `task_id` = " . ($taskId ? "'" . (int)$taskId . "'" : 'NULL') . ",
            `entity_type` = " . ($entityType !== null ? "'" . $this->db->escape($entityType) . "'" : 'NULL') . ",
            `entity_id` = " . ($entityId !== null ? "'" . $this->db->escape($entityId) . "'" : 'NULL') . ",
            `error_code` = " . ($code !== null ? "'" . $this->db->escape($code) . "'" : 'NULL') . ",
            `error_message` = '" . $this->db->escape($message) . "',
            `payload` = " . ($payloadJson !== null ? "'" . $this->db->escape($payloadJson) . "'" : 'NULL') . ",
            `created_at` = NOW()");
    }

    public function getRecentLogs(int $limit = 50): array {
        $limit = max(1, min(200, $limit));

        // Показываем в одной ленте и обычные логи, и ошибки по конкретным
        // товарам/категориям. Раньше счетчик ошибок рос, но администратор не видел
        // причину прямо в интерфейсе модуля, из-за чего диагностика была слепой.
        $query = $this->db->query("SELECT * FROM (
                SELECT
                    `created_at`,
                    `level`,
                    `entity_type`,
                    `entity_id`,
                    `message`
                FROM `" . DB_PREFIX . "moysklad_sync_log`
                UNION ALL
                SELECT
                    `created_at`,
                    'error' AS `level`,
                    `entity_type`,
                    `entity_id`,
                    CONCAT('[', COALESCE(`error_code`, 'ERROR'), '] ', COALESCE(`entity_id`, ''), ': ', `error_message`) AS `message`
                FROM `" . DB_PREFIX . "moysklad_sync_error`
            ) AS combined_logs
            ORDER BY `created_at` DESC
            LIMIT " . (int)$limit);

        return $query->rows;
    }
}
