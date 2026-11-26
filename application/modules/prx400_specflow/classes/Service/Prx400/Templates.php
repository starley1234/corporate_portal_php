<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Библиотека шаблонов спецификаций (Config-Driven).
 *
 * Шаблоны — декларативные JSON в config/templates модуля: семантические якоря
 * детекции, синонимы колонок, правила пропуска строк и математики. Новый
 * формат документа = новый файл, код не меняется. Шаблоны уезжают в payload
 * задачи — воркер парсит по ним без обращения к PHP.
 */
class Service_Prx400_Templates {

    /** Каталог шаблонов модуля (config/templates этого модуля). */
    public static function dir(): string
    {
        // __DIR__ = modules/prx400_specflow/classes/Service/Prx400 → три уровня
        // вверх — корень модуля. (Прежний dirname(...,5) указывал на application/
        // config/templates — каталог пуст, в payload уезжал [] и задача падала
        // PermanentJobError: template_missing.)
        return dirname(__DIR__, 3).'/config/templates';
    }

    /** Все шаблоны (валидные JSON-массивы). */
    public static function all(): array
    {
        static $cache = NULL;
        if ($cache !== NULL)
        {
            return $cache;
        }

        $out = [];
        foreach ((array) glob(self::dir().'/*.json') as $file)
        {
            $data = json_decode((string) file_get_contents((string) $file), TRUE);
            if (is_array($data) AND ! empty($data['template_id']) AND ! empty($data['table_rules']['columns']))
            {
                $out[] = $data;
            }
        }

        return $cache = $out;
    }

    /** Шаблоны для payload задачи (компактно, без name-обёрток). */
    public static function for_payload(): array
    {
        return self::all();
    }

    /** Публичный список для выбора профиля оператором (id + название). */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $tpl)
        {
            $out[] = ['id' => (string) $tpl['template_id'], 'title' => (string) ($tpl['template_name'] ?? $tpl['template_id'])];
        }

        return $out;
    }

    /** Найти шаблон по id. */
    public static function get($template_id): ?array
    {
        foreach (self::all() as $tpl)
        {
            if ((string) $tpl['template_id'] === (string) $template_id)
            {
                return $tpl;
            }
        }

        return NULL;
    }
}
