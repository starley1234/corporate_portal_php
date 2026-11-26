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

    /** Все шаблоны (валидные JSON-массивы): встроенные файлы + кастомные из БД. */
    public static function all(): array
    {
        static $cache = NULL;
        if ($cache !== NULL)
        {
            return $cache;
        }

        $disabled = [];
        $customs = [];
        try
        {
            foreach (Model_Prx400_Template::all() as $row)
            {
                if ((string) $row['source'] === 'builtin' AND ! (bool) $row['enabled'])
                {
                    $disabled[(string) $row['code']] = TRUE;
                }
                if ((string) $row['source'] === 'custom' AND (bool) $row['enabled'] AND $row['json'] !== NULL)
                {
                    $data = json_decode((string) $row['json'], TRUE);
                    if (is_array($data) AND ! empty($data['template_id']))
                    {
                        $customs[] = $data;
                    }
                }
            }
        }
        catch (Throwable $e) { /* миграции 002 ещё нет — только встроенные */ }

        $out = [];
        foreach ((array) glob(self::dir().'/*.json') as $file)
        {
            $data = json_decode((string) file_get_contents((string) $file), TRUE);
            if (is_array($data) AND ! empty($data['template_id']) AND ! empty($data['table_rules']['columns'])
                AND empty($disabled[(string) $data['template_id']]))
            {
                $out[] = $data;
            }
        }

        return $cache = array_merge($out, $customs);
    }

    /** Шаблоны для payload задачи (компактно, без name-обёрток). */
    public static function for_payload(): array
    {
        return self::all();
    }

    /** Строки реестра для UI: код/название/источник/вкл + счётчики якорей и колонок. */
    public static function registry(): array
    {
        $builtin = [];
        foreach ((array) glob(self::dir().'/*.json') as $file)
        {
            $data = json_decode((string) file_get_contents((string) $file), TRUE);
            if ( ! is_array($data) OR empty($data['template_id']))
            {
                continue;
            }
            $builtin[(string) $data['template_id']] = [
                'code' => (string) $data['template_id'],
                'title' => (string) ($data['template_name'] ?? $data['template_id']),
                'source' => 'builtin',
                'json' => $data,
                'anchors' => count((array) (($data['detection_policy'] ?? [])['anchor_keywords'] ?? [])),
                'columns' => count((array) (($data['table_rules'] ?? [])['columns'] ?? [])),
            ];
        }

        $out = $builtin;
        try
        {
            foreach (Model_Prx400_Template::all() as $row)
            {
                $code = (string) $row['code'];
                $enabled = (bool) $row['enabled'];
                if ((string) $row['source'] === 'builtin')
                {
                    if (isset($out[$code]))
                    {
                        $out[$code]['enabled'] = $enabled;
                    }
                    continue;
                }
                $data = $row['json'] !== NULL ? json_decode((string) $row['json'], TRUE) : NULL;
                $out[$code] = [
                    'code' => $code,
                    'title' => (string) ($row['title'] ?: ($data['template_name'] ?? $code)),
                    'source' => 'custom',
                    'json' => is_array($data) ? $data : NULL,
                    'anchors' => is_array($data) ? count((array) (($data['detection_policy'] ?? [])['anchor_keywords'] ?? [])) : 0,
                    'columns' => is_array($data) ? count((array) (($data['table_rules'] ?? [])['columns'] ?? [])) : 0,
                    'enabled' => $enabled,
                ];
            }
        }
        catch (Throwable $e) { /* миграции 002 ещё нет */ }

        foreach ($out as &$row)
        {
            $row['enabled'] = (bool) ($row['enabled'] ?? TRUE);
        }

        ksort($out);

        return array_values($out);
    }

    /** Валидация пользовательского шаблона; возвращает [code, title, json-строка]. */
    public static function validate(array $data): array
    {
        $code = mb_substr(trim((string) ($data['template_id'] ?? '')), 0, 64);
        $title = mb_substr(trim((string) ($data['template_name'] ?? '')), 0, 255);
        $anchors = (array) (($data['detection_policy'] ?? [])['anchor_keywords'] ?? []);
        $columns = (array) (($data['table_rules'] ?? [])['columns'] ?? []);
        if ($code === '' OR $title === '' OR $anchors === [] OR $columns === [])
        {
            throw new Prx400_Error('template_invalid', 400);
        }
        // Коды встроенных шаблонов зарезервированы: их источник — файлы модуля.
        foreach ((array) glob(self::dir().'/*.json') as $file)
        {
            $b = json_decode((string) file_get_contents((string) $file), TRUE);
            if (is_array($b) AND (string) ($b['template_id'] ?? '') === $code)
            {
                throw new Prx400_Error('template_code_reserved', 409);
            }
        }

        return [$code, $title, (string) json_encode($data, JSON_UNESCAPED_UNICODE)];
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
