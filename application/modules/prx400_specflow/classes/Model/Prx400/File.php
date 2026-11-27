<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_spec_files — PDF спецификации по порядку (file_no 1..N). Файл №1
 * дублируется в prx_400_specs.file_id/file_sha256 (совместимость); источник
 * истины для воркера и хранения — эта таблица.
 */
class Model_Prx400_File {

    public static function add(int $spec_id, int $file_no, int $file_id, string $sha, string $name, int $size): void
    {
        Prx_Db::execute(
            'INSERT INTO prx_400_spec_files (spec_id, file_no, file_id, file_sha256, original_name, size_bytes)
             VALUES (:spec, :no, :file, :sha, :name, :size)',
            [':spec' => $spec_id, ':no' => $file_no, ':file' => $file_id, ':sha' => $sha, ':name' => $name, ':size' => $size]);
    }

    /** Файлы спецификации по порядку; запись до миграции 007 — из prx_400_specs. */
    public static function for_spec(int $spec_id): array
    {
        return array_map(static fn (array $f): array => [
            'file_no' => (int) $f['file_no'], 'file_id' => (int) $f['file_id'], 'file_sha256' => (string) $f['file_sha256'],
            'original_name' => (string) $f['original_name'], 'size_bytes' => (int) $f['size_bytes'],
        ], Prx_Db::select('SELECT * FROM prx_400_spec_files WHERE spec_id = :spec ORDER BY file_no', [':spec' => $spec_id]));
    }

    /** @return array<int, int> spec_id → число файлов */
    public static function counts(array $spec_ids): array
    {
        if ($spec_ids === []) return [];
        $rows = Prx_Db::select('SELECT spec_id, count(*) AS c FROM prx_400_spec_files
             WHERE spec_id IN ('.implode(', ', array_map('intval', $spec_ids)).') GROUP BY spec_id');

        return array_map('intval', array_column($rows, 'c', 'spec_id'));
    }

    public static function delete_for_spec(int $spec_id): int
    {
        return Prx_Db::execute('DELETE FROM prx_400_spec_files WHERE spec_id = :spec', [':spec' => $spec_id]);
    }

    /** Те же PDF (по sha256) в других спецификациях: [{spec_id, file_no, original_name, owner_id, status}]. */
    public static function duplicates(array $shas, int $except_spec, ?int $owner, int $limit = 5): array
    {
        if ($shas === []) return [];
        $params = [':except' => $except_spec, ':owner' => $owner];
        $in = [];
        foreach (array_values(array_unique($shas)) as $i => $sha)
        {
            $in[] = ':sha'.$i;
            $params[':sha'.$i] = (string) $sha;
        }

        return Prx_Db::select(
            'SELECT f.spec_id, f.file_no, f.original_name, s.owner_id, s.status, s.created_at
               FROM prx_400_spec_files f JOIN prx_400_specs s ON s.id = f.spec_id
              WHERE f.file_sha256 IN ('.implode(', ', $in).') AND f.spec_id <> :except
                AND (CAST(:owner AS BIGINT) IS NULL OR s.owner_id = CAST(:owner AS BIGINT))
              ORDER BY f.spec_id DESC LIMIT '.max(1, $limit),
            $params);
    }
}
