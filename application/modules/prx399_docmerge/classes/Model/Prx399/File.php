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
            'INSERT INTO prx_399_files (contract_id, kind, ordinal, original_name, storage_path, status)
             VALUES (:contract_id, :kind, :ordinal, :name, :path, :status) RETURNING *',
            [
                ':contract_id' => (int) $row['contract_id'],
                ':kind' => (string) $row['kind'],
                ':ordinal' => (int) $row['ordinal'],
                ':name' => (string) $row['original_name'],
                ':path' => (string) $row['storage_path'],
                ':status' => 'queued',
            ]);
    }

    public static function set_status($id, $status, $error = NULL)
    {
        return Prx_Db::exec(
            'UPDATE prx_399_files SET status = :status, error = :error WHERE id = :id',
            [':id' => (int) $id, ':status' => (string) $status, ':error' => $error]);
    }

    public static function set_job($id, $job_id)
    {
        return Prx_Db::exec(
            'UPDATE prx_399_files SET job_id = :job WHERE id = :id',
            [':id' => (int) $id, ':job' => (int) $job_id]);
    }

    public static function set_base_result($id, $pages, $sha, array $ast)
    {
        return Prx_Db::exec(
            "UPDATE prx_399_files
             SET status = 'parsed', pages = :pages, text_sha256 = :sha,
                 ast = :ast::jsonb, error = NULL
             WHERE id = :id",
            [
                ':id' => (int) $id,
                ':pages' => (int) $pages,
                ':sha' => (string) $sha,
                ':ast' => json_encode($ast, JSON_UNESCAPED_UNICODE),
            ]);
    }

    public static function set_ds_result($id, $pages, $sha)
    {
        return Prx_Db::exec(
            "UPDATE prx_399_files
             SET status = 'parsed', pages = :pages, text_sha256 = :sha, error = NULL
             WHERE id = :id",
            [':id' => (int) $id, ':pages' => (int) $pages, ':sha' => (string) $sha]);
    }

    public static function ready_count($contract_id)
    {
        return (int) Prx_Db::one(
            "SELECT count(*) AS n FROM prx_399_files
             WHERE contract_id = :id AND status NOT IN ('queued', 'working')",
            [':id' => (int) $contract_id])['n'];
    }
}
