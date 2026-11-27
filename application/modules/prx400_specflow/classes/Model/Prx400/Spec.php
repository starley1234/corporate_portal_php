<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_specs: весь SQL таблицы — здесь (сервис SQL не пишет).
 * owner_id — подписанный (Policy_Prx400_Spec::ownerId): API > 0, UI < 0.
 */
class Model_Prx400_Spec {

    public static function insert(array $data): array
    {
        $row = Prx_Db::one(
            'INSERT INTO prx_400_specs (owner_id, original_name, storage_path, file_id, file_sha256, parse_mode, status)
             VALUES (:owner, :name, :path, :file, :sha, :mode, \'queued\') RETURNING *',
            [
                ':owner' => (int) $data['owner_id'],
                ':name' => (string) $data['original_name'],
                ':path' => 'prx212:'.(int) $data['file_id'], // совместимость отчётов; источник истины — file_id
                ':file' => (int) $data['file_id'],
                ':sha' => (string) $data['file_sha256'],
                ':mode' => (string) $data['parse_mode'],
            ]);
        if ( ! is_array($row)) throw new RuntimeException('prx400: INSERT spec без RETURNING');

        return $row;
    }

    public static function get(int $id): ?array
    {
        return Prx_Db::one('SELECT * FROM prx_400_specs WHERE id = :id', [':id' => $id]);
    }

    /** Строка под блокировкой — внутри транзакции сервиса (повтор/перезаливка/результат). */
    public static function lock(int $id): ?array
    {
        return Prx_Db::one('SELECT * FROM prx_400_specs WHERE id = :id FOR UPDATE', [':id' => $id]);
    }

    /** Спецификация по id задачи prx120 (URL воркера несёт job_id — канон prx397). */
    public static function get_by_job(int $job_id): ?array
    {
        return Prx_Db::one('SELECT * FROM prx_400_specs WHERE job_id = :j', [':j' => $job_id]);
    }

    /**
     * Список с агрегатами строк одним запросом. $owner NULL — рабочее место
     * (все записи), иначе только записи владельца.
     */
    public static function listing(?int $owner, int $limit = 100): array
    {
        return Prx_Db::select(
            'SELECT s.*, coalesce(r.total, 0) AS rows_total, coalesce(r.issue, 0) AS rows_issue
               FROM prx_400_specs s
               LEFT JOIN (
                    SELECT spec_id, count(*) AS total,
                           count(*) FILTER (WHERE status <> \'ok\' AND NOT verified) AS issue
                      FROM prx_400_rows GROUP BY spec_id
               ) r ON r.spec_id = s.id
              WHERE (CAST(:owner AS BIGINT) IS NULL OR s.owner_id = CAST(:owner AS BIGINT))
              ORDER BY s.id DESC LIMIT '.max(1, $limit),
            [':owner' => $owner]);
    }

    public static function delete(int $id): int
    {
        // prx_400_rows/prx_400_edits удаляются каскадом (FK ON DELETE CASCADE).
        return Prx_Db::execute('DELETE FROM prx_400_specs WHERE id = :id', [':id' => $id]);
    }

    /** Новая задача разбора: задача, режим, сброс ошибки — одним UPDATE. */
    public static function queue(int $id, int $job_id, string $mode): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs SET job_id = :job, parse_mode = :mode, status = \'queued\', error = NULL,
                    recoveries = 0, updated_at = now()
              WHERE id = :id',
            [':id' => $id, ':job' => $job_id, ':mode' => $mode]);
    }

    /** Автоповтор после падения воркера: только если запись ждёт ту же задачу. */
    public static function requeue_recovered(int $id, int $lost_job_id, int $job_id): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs SET job_id = :job, status = \'queued\', error = NULL,
                    recoveries = recoveries + 1, updated_at = now()
              WHERE id = :id AND coalesce(job_id, 0) = :lost AND status IN (\'queued\', \'running\')',
            [':id' => $id, ':job' => $job_id, ':lost' => $lost_job_id]);
    }

    /** Замена файла (перезаливка). */
    public static function set_file(int $id, int $file_id, string $name, string $sha): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs SET file_id = :f, storage_path = :p, original_name = :n, file_sha256 = :s, updated_at = now()
              WHERE id = :id',
            [':id' => $id, ':f' => $file_id, ':p' => 'prx212:'.$file_id, ':n' => $name, ':s' => $sha]);
    }

    /** Воркер взял файл: queued → running (только из queued, без гонки). */
    public static function mark_running(int $id): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs SET status = \'running\', updated_at = now() WHERE id = :id AND status = \'queued\'',
            [':id' => $id]);
    }

    /** Терминальная ошибка: в error — машинный код (подсказку рисует вью). */
    public static function fail(int $id, string $code): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs SET status = \'failed\', error = :code, updated_at = now() WHERE id = :id',
            [':id' => $id, ':code' => $code]);
    }

    /** Сверка с очередью: failed только если запись всё ещё «в работе» с той же задачей. */
    public static function fail_in_flight(int $id, int $job_id, string $code): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs SET status = \'failed\', error = :code, updated_at = now()
              WHERE id = :id AND status IN (\'queued\', \'running\') AND coalesce(job_id, 0) = :job',
            [':id' => $id, ':job' => $job_id, ':code' => $code]);
    }

    public static function set_status(int $id, string $status): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs SET status = :status, error = NULL, updated_at = now() WHERE id = :id',
            [':id' => $id, ':status' => $status]);
    }

    /** Результат разбора воркера + итоговый статус (review/done). */
    public static function set_parse_result(int $id, array $data, string $status): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs
                SET template_id = :tid, template_title = :ttitle, spec_number = :num,
                    department = :dept, pages = :pages, text_sha256 = :tsha,
                    math = CAST(:math AS jsonb), summary = :summary,
                    status = :status, error = NULL, updated_at = now()
              WHERE id = :id',
            [
                ':id' => $id,
                ':tid' => mb_substr((string) $data['template_id'], 0, 64),
                ':ttitle' => mb_substr((string) $data['template_title'], 0, 255),
                ':num' => mb_substr((string) $data['spec_number'], 0, 64),
                ':dept' => mb_substr((string) $data['department'], 0, 255),
                ':pages' => (int) $data['pages'],
                ':tsha' => preg_match('/\A[a-f0-9]{64}\z/', (string) $data['text_sha256']) ? (string) $data['text_sha256'] : NULL,
                ':math' => json_encode($data['math'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':summary' => (string) $data['summary'],
                ':status' => $status,
            ]);
    }

    /** queued/running — кандидаты сверки с очередью (уборка). */
    public static function in_flight(int $limit = 200): array
    {
        return Prx_Db::select(
            'SELECT id, job_id, status, updated_at FROM prx_400_specs
              WHERE status IN (\'queued\', \'running\') ORDER BY id LIMIT '.max(1, $limit));
    }

    /** Завершённые старше срока хранения. */
    public static function expired(int $days, int $limit = 100): array
    {
        return Prx_Db::select(
            'SELECT id, job_id, file_id FROM prx_400_specs
              WHERE status IN (\'done\', \'failed\') AND updated_at < now() - (CAST(:d AS int) * interval \'1 day\')
              ORDER BY updated_at, id LIMIT '.max(1, $limit),
            [':d' => $days]);
    }

    public static function file_referenced(int $file_id): bool
    {
        return Prx_Db::one('SELECT 1 AS x FROM prx_400_specs WHERE file_id = :f LIMIT 1', [':f' => $file_id]) !== NULL;
    }
}
