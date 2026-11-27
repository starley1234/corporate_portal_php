<?php defined('SYSPATH') or die('No direct script access.');

/** Файл пакета: базовый договор (kind='base', ordinal=0) или ДС (kind='ds'). */
class Model_Prx399_File {

    public static function get($id)
    {
        return Prx_Db::one('SELECT * FROM prx_399_files WHERE id = :id', [':id' => (int) $id]);
    }

    public static function for_contract($contract_id)
    {
        return Prx_Db::select(
            'SELECT * FROM prx_399_files WHERE contract_id = :id ORDER BY kind DESC, ordinal, id',
            [':id' => (int) $contract_id]);
    }

    public static function insert(array $row)
    {
        return Prx_Db::one(
            'INSERT INTO prx_399_files (contract_id, kind, ordinal, original_name, file_id, status)
             VALUES (:contract_id, :kind, :ordinal, :name, :file_id, :status) RETURNING *',
            [
                ':contract_id' => (int) $row['contract_id'],
                ':kind' => (string) $row['kind'],
                ':ordinal' => (int) $row['ordinal'],
                ':name' => (string) $row['original_name'],
                ':file_id' => (int) $row['file_id'],
                ':status' => 'queued',
            ]);
    }

    public static function set_status($id, $status, $error = NULL)
    {
        return Prx_Db::execute(
            'UPDATE prx_399_files SET status = :status, error = :error WHERE id = :id',
            [':id' => (int) $id, ':status' => (string) $status, ':error' => $error]);
    }

    public static function set_job($id, $job_id)
    {
        return Prx_Db::execute(
            'UPDATE prx_399_files SET job_id = :job WHERE id = :id',
            [':id' => (int) $id, ':job' => (int) $job_id]);
    }

    public static function set_base_result($id, $pages, $sha, array $ast)
    {
        return Prx_Db::execute(
            "UPDATE prx_399_files
             SET status = 'parsed', pages = :pages, text_sha256 = :sha,
                 ast = :ast::jsonb, error = NULL
             WHERE id = :id",
            [
                ':id' => (int) $id,
                ':pages' => (int) $pages,
                ':sha' => (string) $sha,
                ':ast' => json_encode($ast, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            ]);
    }

    public static function set_ds_result($id, $pages, $sha, $text = NULL)
    {
        return Prx_Db::execute(
            "UPDATE prx_399_files
             SET status = 'parsed', pages = :pages, text_sha256 = :sha, text = :text, error = NULL
             WHERE id = :id",
            [
                ':id' => (int) $id,
                ':pages' => (int) $pages,
                ':sha' => (string) $sha,
                ':text' => $text,
            ]);
    }

    /**
     * Какие из файлов prx212 ещё нужны пакетам (уборка сирот Storage::sweep_orphans).
     *
     * @param int[] $file_ids
     * @return int[]
     */
    public static function referenced_files(array $file_ids): array
    {
        if ($file_ids === []) return [];

        return array_map('intval', array_column(Prx_Db::select(
            'SELECT file_id FROM prx_399_files WHERE file_id IN ('.implode(', ', array_map('intval', $file_ids)).')'), 'file_id'));
    }

    public static function ready_count($contract_id)
    {
        return (int) Prx_Db::one(
            "SELECT count(*) AS n FROM prx_399_files
             WHERE contract_id = :id AND status NOT IN ('queued', 'working')",
            [':id' => (int) $contract_id])['n'];
    }
}
