<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_templates — реестр шаблонов разбора: вкл/выкл встроенных (config/
 * templates/*.json) и пользовательские JSON из UI (источник = custom).
 * Владелец таблицы — prx400_specflow; содержимое шаблона пользовательских —
 * в колонке json, встроенных — в файлах модуля.
 */
class Model_Prx400_Template {

    public static function all()
    {
        return Prx_Db::select('SELECT * FROM prx_400_templates ORDER BY source, code');
    }

    public static function get($code)
    {
        $rows = Prx_Db::select('SELECT * FROM prx_400_templates WHERE code = :c', [':c' => (string) $code]);

        return $rows ? (array) $rows[0] : NULL;
    }

    /** Вкл/выкл: встраиваемые — строка-оверлей (json NULL), кастомные — UPDATE. */
    public static function set_enabled($code, $flag, $is_builtin)
    {
        return Prx_Db::execute(
            'INSERT INTO prx_400_templates (code, title, json, enabled, source, created_at, updated_at)
             VALUES (:c, :t, NULL, :e, :s, now(), now())
             ON CONFLICT (code) DO UPDATE SET enabled = :e, updated_at = now()',
            [':c' => (string) $code, ':t' => (string) $code, ':e' => $flag ? 't' : 'f', ':s' => $is_builtin ? 'builtin' : 'custom']);
    }

    public static function save_custom($code, $title, $json)
    {
        return Prx_Db::execute(
            "INSERT INTO prx_400_templates (code, title, json, enabled, source, created_at, updated_at)
             VALUES (:c, :t, :j, 't', 'custom', now(), now())
             ON CONFLICT (code) DO UPDATE SET title = :t, json = :j, enabled = 't', source = 'custom', updated_at = now()",
            [':c' => (string) $code, ':t' => (string) $title, ':j' => $json]);
    }

    public static function delete_custom($code)
    {
        return Prx_Db::execute(
            "DELETE FROM prx_400_templates WHERE code = :c AND source = 'custom'",
            [':c' => (string) $code]);
    }
}
