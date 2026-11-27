<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Библиотека шаблонов спецификаций (Config-Driven).
 *
 * Шаблоны — декларативные JSON: встроенные в config/templates модуля,
 * пользовательские — в prx_400_templates (страница «Шаблоны»). Семантические
 * якоря детекции, синонимы колонок (columns.*.match), правила пропуска строк
 * и математики. Новый формат документа = новый шаблон, код не меняется.
 * Шаблоны уезжают в payload задачи — воркер парсит по ним без обращения к PHP.
 */
final class Service_Prx400_Templates
{
    /** Колонки, которые понимает парсер воркера (parse.py). */
    // level — уровень вложенности BOM («2», «..2»), если в документе есть такая графа.
    public const COLUMNS = ['pos', 'item_name', 'code_doc', 'qty', 'unit', 'unit_price', 'total_price', 'level'];
    private const PATTERN_KEYS = ['skip_line_patterns', 'totals_patterns', 'section_patterns'];
    private const MAX_JSON_BYTES = 65536;

    /** @var array|null кэш запроса: встроенные + включённые пользовательские */
    private static ?array $cache = NULL;

    /** Каталог встроенных шаблонов (config/templates этого модуля). */
    public static function dir(): string
    {
        return dirname(__DIR__, 3).'/config/templates';
    }

    /** Активные шаблоны: встроенные (без отключённых) + включённые пользовательские. */
    public static function all(): array
    {
        if (self::$cache !== NULL) return self::$cache;

        $disabled = [];
        $customs = [];
        foreach (Model_Prx400_Template::all() as $row)
        {
            if ($row['source'] === 'builtin' AND ! $row['enabled'])
            {
                $disabled[(string) $row['code']] = TRUE;
            }
            if ($row['source'] === 'custom' AND $row['enabled'] AND is_array($row['json']))
            {
                $customs[] = $row['json'];
            }
        }

        $out = [];
        foreach (self::builtin() as $code => $data)
        {
            if (empty($disabled[$code])) $out[] = $data;
        }

        return self::$cache = array_merge($out, $customs);
    }

    /** Шаблоны для payload задачи; пустая библиотека — честная ошибка сайта. */
    public static function for_payload(): array
    {
        $templates = self::all();
        if ($templates === []) throw new Prx400_Error('template_library_empty', 503);

        return $templates;
    }

    /** Код шаблона из запроса: '' = авто-детекция; неизвестный код — ошибка, а не тихий «авто». */
    public static function resolve(string $code): string
    {
        $code = trim($code);
        if ($code === '') return '';
        if (self::get($code) === NULL) throw new Prx400_Error('template_not_found', 400);

        return $code;
    }

    /** Строки реестра для UI: код/название/источник/вкл + счётчики якорей и колонок. */
    public static function registry(): array
    {
        $out = [];
        foreach (self::builtin() as $code => $data)
        {
            $out[$code] = self::registry_row($code, (string) ($data['template_name'] ?? $code), 'builtin', $data, TRUE);
        }
        foreach (Model_Prx400_Template::all() as $row)
        {
            $code = (string) $row['code'];
            if ($row['source'] === 'builtin')
            {
                if (isset($out[$code])) $out[$code]['enabled'] = $row['enabled'];
                continue;
            }
            $data = is_array($row['json']) ? $row['json'] : NULL;
            $title = (string) ($row['title'] ?: ($data['template_name'] ?? $code));
            $out[$code] = self::registry_row($code, $title, 'custom', $data, $row['enabled']);
        }
        ksort($out);

        return array_values($out);
    }

    /** Публичный список для выбора профиля оператором (id + название). */
    public static function options(): array
    {
        return array_map(static fn (array $tpl): array => [
            'id' => (string) $tpl['template_id'],
            'title' => (string) ($tpl['template_name'] ?? $tpl['template_id']),
        ], self::all());
    }

    public static function get(string $template_id): ?array
    {
        foreach (self::all() as $tpl)
        {
            if ((string) $tpl['template_id'] === $template_id) return $tpl;
        }

        return NULL;
    }

    /** Загрузка пользовательского шаблона (страница «Шаблоны»). */
    public static function upload(Prx_Actor $actor, $file): string
    {
        Policy_Prx400_Spec::require_edit($actor);
        if ( ! is_array($file) OR ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            OR ! is_string($file['tmp_name'] ?? NULL) OR ! is_uploaded_file($file['tmp_name']))
        {
            throw new Prx400_Error('template_file_required', 400);
        }
        if ((int) filesize($file['tmp_name']) > self::MAX_JSON_BYTES) throw new Prx400_Error('template_too_large', 413);
        $data = json_decode((string) file_get_contents($file['tmp_name']), TRUE);
        if ( ! is_array($data)) throw new Prx400_Error('template_invalid_json', 400);

        [$code, $title, $json] = self::validate($data);
        if (Model_Prx400_Template::save_custom($code, $title, $json, $actor->id()) < 1)
        {
            throw new Prx400_Error('template_code_reserved', 409);
        }
        self::$cache = NULL;

        return $title;
    }

    public static function set_enabled(Prx_Actor $actor, string $code, bool $enabled): void
    {
        Policy_Prx400_Spec::require_edit($actor);
        $code = trim($code);
        $builtin = isset(self::builtin()[$code]);
        if ( ! $builtin AND Model_Prx400_Template::get($code) === NULL) throw new Prx400_Error('template_not_found', 404);
        Model_Prx400_Template::set_enabled($code, $enabled, $builtin);
        self::$cache = NULL;
    }

    public static function delete(Prx_Actor $actor, string $code): void
    {
        Policy_Prx400_Spec::require_edit($actor);
        if (Model_Prx400_Template::delete_custom(trim($code)) < 1) throw new Prx400_Error('template_not_found', 404);
        self::$cache = NULL;
    }

    /**
     * Валидация пользовательского шаблона; возвращает [code, title, json].
     * Регулярные выражения проверяются компиляцией: битый шаблон не должен
     * доехать до воркера и уронить разбор всех спецификаций.
     */
    public static function validate(array $data): array
    {
        $code = trim((string) ($data['template_id'] ?? ''));
        $title = mb_substr(trim((string) ($data['template_name'] ?? '')), 0, 255);
        $anchors = ($data['detection_policy'] ?? [])['anchor_keywords'] ?? NULL;
        $rules = $data['table_rules'] ?? NULL;
        $columns = is_array($rules) ? ($rules['columns'] ?? NULL) : NULL;

        if ( ! preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $code) OR $title === ''
            OR ! is_array($anchors) OR $anchors === [] OR ! is_array($columns) OR $columns === [])
        {
            throw new Prx400_Error('template_invalid', 400);
        }
        foreach ($anchors as $anchor)
        {
            if ( ! is_string($anchor) OR trim($anchor) === '') throw new Prx400_Error('template_invalid', 400);
        }
        foreach ($columns as $key => $meta)
        {
            $match = is_array($meta) ? ($meta['match'] ?? NULL) : NULL;
            if ( ! in_array($key, self::COLUMNS, TRUE) OR ! is_array($match) OR $match === [])
            {
                throw new Prx400_Error('template_invalid_column', 400);
            }
        }
        if ( ! isset($columns['pos'])) throw new Prx400_Error('template_invalid_column', 400);
        foreach (self::PATTERN_KEYS as $key)
        {
            foreach ((array) ($rules[$key] ?? []) as $pattern)
            {
                if ( ! is_string($pattern) OR ! self::regex_ok($pattern)) throw new Prx400_Error('template_invalid_regex', 400);
            }
        }
        if (isset(self::builtin()[$code])) throw new Prx400_Error('template_code_reserved', 409);

        return [$code, $title, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    /** Шаблон воркера — Python re; общее подмножество с PCRE проверяем компиляцией. */
    private static function regex_ok(string $pattern): bool
    {
        if ($pattern === '' OR strlen($pattern) > 500) return FALSE;
        if (preg_match('/\(\?P?<[^>]*>|\(\?[a-zA-Z]+\)|\\\\[pPXRK]/', $pattern)) return FALSE; // различия диалектов

        return @preg_match('~'.str_replace('~', '\~', $pattern).'~iu', '') !== FALSE;
    }

    /** @return array<string, array> встроенные шаблоны по коду */
    private static function builtin(): array
    {
        static $builtin = NULL;
        if ($builtin !== NULL) return $builtin;

        $builtin = [];
        foreach ((array) glob(self::dir().'/*.json') as $file)
        {
            $data = json_decode((string) file_get_contents((string) $file), TRUE);
            if (is_array($data) AND ! empty($data['template_id']) AND ! empty($data['table_rules']['columns']))
            {
                $builtin[(string) $data['template_id']] = $data;
            }
            else
            {
                Log_Plain::event(Log::ERROR, 'prx400 stage=templates invalid_builtin file=:f', [':f' => basename((string) $file)]);
            }
        }

        return $builtin;
    }

    private static function registry_row(string $code, string $title, string $source, ?array $data, bool $enabled): array
    {
        return [
            'code' => $code,
            'title' => $title,
            'source' => $source,
            'json' => $data,
            'anchors' => is_array($data) ? count((array) (($data['detection_policy'] ?? [])['anchor_keywords'] ?? [])) : 0,
            'columns' => is_array($data) ? count((array) (($data['table_rules'] ?? [])['columns'] ?? [])) : 0,
            'enabled' => $enabled,
        ];
    }
}
