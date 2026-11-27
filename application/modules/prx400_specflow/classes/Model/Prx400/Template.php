<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_templates — реестр шаблонов разбора: вкл/выкл встроенных (config/
 * templates/*.json) и пользовательские JSON из UI (source = custom).
 * enabled отдаётся PHP-булевым (драйвер возвращает 't'/'f').
 */
class Model_Prx400_Template {

    public static function all(): array
    {
        return array_map([self::class, 'decode'], Prx_Db::select('SELECT * FROM prx_400_templates ORDER BY source, code'));
    }

    public static function get(string $code): ?array
    {
        $row = Prx_Db::one('SELECT * FROM prx_400_templates WHERE code = :c', [':c' => $code]);

        return $row !== NULL ? self::decode($row) : NULL;
    }

    /** Вкл/выкл: встроенные — строка-оверлей (json NULL), пользовательские — UPDATE. */
    public static function set_enabled(string $code, bool $flag, bool $is_builtin): int
    {
        return Prx_Db::execute(
            'INSERT INTO prx_400_templates (code, title, json, enabled, source, created_at, updated_at)
             VALUES (:c, :c, NULL, :e, :s, now(), now())
             ON CONFLICT (code) DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = now()',
            [':c' => $code, ':e' => $flag, ':s' => $is_builtin ? 'builtin' : 'custom']);
    }

    public static function save_custom(string $code, string $title, string $json, int $actor_id): int
    {
        return Prx_Db::execute(
            "INSERT INTO prx_400_templates (code, title, json, enabled, source, created_by, created_at, updated_at)
             VALUES (:c, :t, CAST(:j AS jsonb), TRUE, 'custom', :by, now(), now())
             ON CONFLICT (code) DO UPDATE SET title = EXCLUDED.title, json = EXCLUDED.json, enabled = TRUE,
                 updated_at = now()
             WHERE prx_400_templates.source = 'custom'",
            [':c' => $code, ':t' => $title, ':j' => $json, ':by' => $actor_id]);
    }

    public static function delete_custom(string $code): int
    {
        return Prx_Db::execute(
            "DELETE FROM prx_400_templates WHERE code = :c AND source = 'custom'",
            [':c' => $code]);
    }

    private static function decode(array $row): array
    {
        $row['enabled'] = Prx400_Bool::from_db($row['enabled'] ?? TRUE);
        if (isset($row['json']) AND is_string($row['json']))
        {
            $data = json_decode($row['json'], TRUE);
            $row['json'] = is_array($data) ? $data : NULL;
        }

        return $row;
    }
}
