<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_specs: SQL по владельцу-модели (стража архитектуры: только эта таблица).
 */
class Model_Prx400_Spec {

    public static function insert(array $data)
    {
        $row = Prx_Db::one(
            'INSERT INTO prx_400_specs (owner_id, original_name, storage_path, file_sha256, status)
             VALUES (:owner, :name, :path, :sha, :status) RETURNING *',
            [
                ':owner' => (int) $data['owner_id'],
                ':name' => (string) $data['original_name'],
                ':path' => (string) $data['storage_path'],
                ':sha' => (string) $data['file_sha256'],
                ':status' => 'queued',
            ]);

        return is_array($row) ? $row : NULL;
    }

    public static function get($id)
    {
        $row = Prx_Db::one('SELECT * FROM prx_400_specs WHERE id = :id', [':id' => (int) $id]);

        return is_array($row) ? $row : NULL;
    }

    public static function for_owner($owner_id, $limit = 50)
    {
        return Prx_Db::select(
            'SELECT * FROM prx_400_specs WHERE owner_id = :o ORDER BY id DESC LIMIT :l',
            [':o' => (int) $owner_id, ':l' => (int) $limit]);
    }

    public static function set_job($id, $job_id)
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs SET job_id = :job, updated_at = now() WHERE id = :id',
            [':id' => (int) $id, ':job' => (int) $job_id]);
    }

    public static function set_status($id, $status, $error = NULL)
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs SET status = :status, error = :error, updated_at = now() WHERE id = :id',
            [':id' => (int) $id, ':status' => (string) $status, ':error' => $error]);
    }

    /** Результат разбора воркера. Статус ставит сервис (зависит от итогов). */
    public static function set_parse_result($id, array $data)
    {
        return Prx_Db::execute(
            'UPDATE prx_400_specs
             SET template_id = :tid, template_title = :ttitle, spec_number = :num,
                 department = :dept, pages = :pages, text_sha256 = :tsha,
                 math = :math::jsonb, summary = :summary, updated_at = now()
             WHERE id = :id',
            [
                ':id' => (int) $id,
                ':tid' => (string) ($data['template_id'] ?? ''),
                ':ttitle' => (string) ($data['template_title'] ?? ''),
                ':num' => (string) ($data['spec_number'] ?? ''),
                ':dept' => (string) ($data['department'] ?? ''),
                ':pages' => (int) ($data['pages'] ?? 0),
                ':tsha' => (string) ($data['text_sha256'] ?? ''),
                ':math' => json_encode($data['math'] ?? [], JSON_UNESCAPED_UNICODE),
                ':summary' => (string) ($data['summary'] ?? ''),
            ]);
    }
}
