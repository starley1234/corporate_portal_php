<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Контроль качества» (nj639_qc) — серверный слой VLM-сервиса проверки изделий.
 *
 * Схема «событие/триггер → кадр → VLM → JSON-схема → ответ API + запись в БД»:
 *   1. Пользователь задаёт ЭТАЛОН (изображения), ПРАВИЛО (текст + варианты
 *      вердикта) и ВЫХОДНУЮ JSON-СХЕМУ (контракт ответа для API).
 *   2. Ручной режим: /qc/api/run (фото изделия) — или автономный источник:
 *      IP-камера (Snapshot URL / RTSP / ONVIF), FTP/File-drop, очередь
 *      (Kafka / RabbitMQ / MQTT), webhook от датчика/ПЛК.
 *   3. Сайт пишет прогон в nj_639_runs и ставит задачу в очередь nj120
 *      (канал nj639.analyze). Воркер захватывает кадр, шлёт его VLM вместе
 *      с эталоном/правилом/схемой, валидирует ответ схемой и возвращает
 *      результат через /qc/api/worker-result (X-Worker-Token).
 *
 * Безопасность: rate limit (nj100_platform), лимиты размеров, валидация
 * схемы без eval, секреты только из .env.
 */
class Service_Nj639_Qc
{
    const RATE_PER_MINUTE = 5;
    const RATE_PER_HOUR   = 40;

    const CHANNEL_ANALYZE = 'nj639.analyze';

    /** Потолок base64 кадров+эталонов в одной задаче очереди, байт. */
    const PAYLOAD_MAX_B64 = 6291456; // 6 МБ

    const STATUS_QUEUED = 'queued';
    const STATUS_DONE   = 'done';
    const STATUS_ERROR  = 'error';

    /** Конфиг модуля. */
    public static function cfg($key, $default = NULL)
    {
        $cfg = Kohana::$config->load('nj639_qc');

        return $cfg ? $cfg->get($key, $default) : $default;
    }

    public static function client_ip()
    {
        if (class_exists('Service_Nj100_Ratelimit'))
        {
            return Service_Nj100_Ratelimit::client_ip();
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    protected static function _db()
    {
        return Database::instance();
    }

    protected static function _q($v)
    {
        return $v === NULL ? 'NULL' : self::_db()->quote($v);
    }

    protected static function _jsonb(array $v)
    {
        return self::_db()->quote(json_encode($v, JSON_UNESCAPED_UNICODE)).'::jsonb';
    }

    // ------------------------------------------------------------ rate limit

    public static function rate_ok($ip)
    {
        self::ensure_tables();

        $counts = Service_Nj100_Ratelimit::window_counts(
            'nj_639_runs', (string) $ip, 'client_ip');

        return Service_Nj100_Ratelimit::allowed($counts,
            (int) self::cfg('rate.per_minute', self::RATE_PER_MINUTE),
            (int) self::cfg('rate.per_hour', self::RATE_PER_HOUR));
    }

    public static function rate_limit_text()
    {
        return Service_Nj100_Ratelimit::limit_text(
            (int) self::cfg('rate.per_minute', self::RATE_PER_MINUTE),
            (int) self::cfg('rate.per_hour', self::RATE_PER_HOUR),
            'операций');
    }

    // -------------------------------------------------------- чтение файла

    public static function max_image_bytes()
    {
        return (int) self::cfg('max_image_mb', 15) * 1024 * 1024;
    }

    /**
     * Прочитать загруженное изображение из multipart ($_FILES) или из
     * base64 data URL. @return array [ok, name?, bytes?, media_type?] | [ok, error].
     */
    public static function read_image(array $data_urls = []): array
    {
        $field = 'file';

        if (isset($_FILES[$field]) AND is_array($_FILES[$field]) AND (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
        {
            $f = $_FILES[$field];

            if (is_array($f['error']))
            {
                return ['ok' => FALSE, 'error' => 'Ожидается один файл.'];
            }

            if ((int) $f['error'] !== UPLOAD_ERR_OK)
            {
                return ['ok' => FALSE, 'error' => 'Ошибка загрузки файла (код '.$f['error'].').'];
            }

            if ( ! is_uploaded_file($f['tmp_name']))
            {
                return ['ok' => FALSE, 'error' => 'Некорректная загрузка файла.'];
            }

            if ((int) $f['size'] > self::max_image_bytes())
            {
                return ['ok' => FALSE, 'error' => 'Изображение больше лимита '.self::cfg('max_image_mb', 15).' МБ.'];
            }

            $bytes = @file_get_contents($f['tmp_name']);

            if ($bytes === FALSE)
            {
                return ['ok' => FALSE, 'error' => 'Не удалось прочитать файл.'];
            }

            if ( ! self::looks_like_image($bytes))
            {
                return ['ok' => FALSE, 'error' => 'Файл не похож на изображение (jpg/png/webp/bmp/gif).'];
            }

            $mt = strtolower((string) ($f['type'] ?? ''));

            return ['ok' => TRUE, 'name' => basename((string) ($f['name'] ?? 'image')), 'bytes' => $bytes,
                    'media_type' => $mt !== '' ? $mt : self::sniff_media_type($bytes)];
        }

        // fallback: data URL в теле (JSON/query)
        $raw = $data_urls[0] ?? '';

        if (is_string($raw) AND $raw !== '')
        {
            return self::decode_data_url($raw);
        }

        return ['ok' => FALSE, 'error' => 'Не получено изображение. Приложите файл или data URL.'];
    }

    /** Разобрать data:[<mediatype>][;base64],<data> → bytes. */
    public static function decode_data_url($url): array
    {
        $url = trim((string) $url);

        if ( ! preg_match('~^data:([^;,]*)(;base64)?,(.*)$~s', $url, $m))
        {
            return ['ok' => FALSE, 'error' => 'Не получено изображение.'];
        }

        $mt = $m[1] !== '' ? strtolower($m[1]) : 'application/octet-stream';

        if ( ! empty($m[2]))
        {
            $b64 = preg_replace('~\s+~', '', $m[3]);
            $bytes = @base64_decode($b64, TRUE);

            if ($bytes === FALSE)
            {
                return ['ok' => FALSE, 'error' => 'Некорректный base64 в data URL.'];
            }
        }
        else
        {
            $bytes = rawurldecode($m[3]);
        }

        if (strlen((string) $bytes) > self::max_image_bytes())
        {
            return ['ok' => FALSE, 'error' => 'Изображение больше лимита '.self::cfg('max_image_mb', 15).' МБ.'];
        }

        if ( ! self::looks_like_image($bytes))
        {
            return ['ok' => FALSE, 'error' => 'Файл не похож на изображение (jpg/png/webp/bmp/gif).'];
        }

        return ['ok' => TRUE, 'name' => 'image', 'bytes' => $bytes, 'media_type' => $mt];
    }

    /** Минимальная магия-проверка по байтам (без GD). */
    public static function looks_like_image($bytes)
    {
        $b = substr((string) $bytes, 0, 16);

        if ($b === FALSE OR $b === '')
        {
            return FALSE;
        }

        return substr($b, 0, 3) === "\xFF\xD8\xFF"          // jpeg
            OR substr($b, 0, 8) === "\x89PNG\r\n\x1A\n"    // png
            OR substr($b, 0, 6) === 'GIF87a' OR substr($b, 0, 6) === 'GIF89a'
            OR (substr($b, 0, 4) === 'RIFF' AND substr($b, 8, 4) === 'WEBP')
            OR substr($b, 0, 2) === 'BM';                  // bmp
    }

    public static function image_to_data_url($bytes, $mt = '')
    {
        if ($mt === '' OR strpos($mt, '/') === FALSE)
        {
            $mt = self::sniff_media_type($bytes);
        }

        return 'data:'.$mt.';base64,'.base64_encode($bytes);
    }

    public static function ext_for_media_type($mt): string
    {
        switch (strtolower((string) $mt))
        {
            case 'image/jpeg':
            case 'image/jpg':  return 'jpg';
            case 'image/png':  return 'png';
            case 'image/gif':  return 'gif';
            case 'image/webp': return 'webp';
            case 'image/bmp':  return 'bmp';
            default:           return 'jpg';
        }
    }

    public static function sniff_media_type($bytes)
    {
        $b = substr((string) $bytes, 0, 8);

        if (substr($b, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
        if (substr($b, 0, 8) === "\x89PNG\r\n\x1A\n") return 'image/png';
        if (substr($b, 0, 6) === 'GIF87a' OR substr($b, 0, 6) === 'GIF89a') return 'image/gif';
        if (substr($b, 0, 4) === 'RIFF' AND substr($b, 8, 4) === 'WEBP') return 'image/webp';
        if (substr($b, 0, 2) === 'BM') return 'image/bmp';

        return 'application/octet-stream';
    }

    // ------------------------------------------------------ JSON-схема (без deps)

    /**
     * Распарсить пользовательскую выходную JSON-схему (подмножество draft-07:
     * type/properties/required/additionalProperties, типы string/number/boolean/
     * integer/array/object/null). @return array [ok, schema?] | [ok, error].
     */
    public static function parse_schema($json)
    {
        $json = trim((string) $json);

        if ($json === '')
        {
            return ['ok' => TRUE, 'schema' => NULL];
        }

        $schema = json_decode($json, TRUE);

        if ( ! is_array($schema))
        {
            return ['ok' => FALSE, 'error' => 'JSON-схема не распарсилась: '.mb_substr(json_last_error_msg(), 0, 90)];
        }

        if ( ! array_key_exists('type', $schema))
        {
            $schema['type'] = 'object';
        }

        $schema['type'] = strtolower((string) ($schema['type'] ?? 'object'));

        if ( ! in_array($schema['type'], ['object', 'string', 'number', 'boolean', 'integer'], TRUE))
        {
            return ['ok' => FALSE, 'error' => 'Поддерживается верхний type = object|string|number|boolean|integer (получено «'.$schema['type'].'»).'];
        }

        if (isset($schema['properties']) AND ! is_array($schema['properties']))
        {
            return ['ok' => FALSE, 'error' => 'properties должен быть объектом.'];
        }

        if (isset($schema['required']) AND ! is_array($schema['required']))
        {
            return ['ok' => FALSE, 'error' => 'required должен быть массивом имён полей.'];
        }

        return ['ok' => TRUE, 'schema' => $schema];
    }

    /**
     * Валидация фактического ответа VLM по схеме. Возвращает
     * [ok(bool), verdict(array|null нормализованный), errors[]].
     */
    public static function validate_verdict($data, ?array $schema): array
    {
        $errors = [];

        if ( ! is_array($data))
        {
            return [FALSE, NULL, ['Ответ VLM не является JSON-объектом.']];
        }

        if ($schema === NULL)
        {
            return [TRUE, $data, []];
        }

        $type = (string) ($schema['type'] ?? 'object');

        if ($type === 'object')
        {
            $props = (isset($schema['properties']) AND is_array($schema['properties']))
                ? $schema['properties'] : [];

            foreach ((array) ($schema['required'] ?? []) as $name)
            {
                if ( ! array_key_exists((string) $name, $data))
                {
                    $errors[] = 'Нет обязательного поля «'.$name.'».';
                }
            }

            foreach ($props as $name => $spec)
            {
                if ( ! array_key_exists((string) $name, $data))
                {
                    continue;
                }

                $v = $data[(string) $name];
                $ptype = strtolower((string) ((is_array($spec) AND isset($spec['type'])) ? $spec['type'] : 'any'));

                if ( ! self::type_matches($v, $ptype))
                {
                    $errors[] = 'Поле «'.$name.'»: ожидался '.$ptype.', получено '.gettype($v).'.';
                }
            }

            if (isset($schema['additionalProperties']) AND $schema['additionalProperties'] === FALSE)
            {
                foreach ($data as $name => $_v)
                {
                    if ( ! array_key_exists((string) $name, $props))
                    {
                        $errors[] = 'Неожиданное поле «'.$name.'» (additionalProperties=false).';
                    }
                }
            }

            return [empty($errors), $data, $errors];
        }

        // верхний level не object — сам ответ и есть значение
        if (self::type_matches($data, $type))
        {
            return [TRUE, $data, []];
        }

        return [FALSE, NULL, ['Ответ не совпал с типом '.$type.' схемы.']];
    }

    protected static function type_matches($v, $ptype)
    {
        switch ($ptype)
        {
            case 'string':   return is_string($v);
            case 'number':   return is_int($v) OR is_float($v);
            case 'integer':  return is_int($v) OR (is_float($v) AND floor($v) === $v);
            case 'boolean':  return is_bool($v);
            case 'null':     return $v === NULL;
            case 'array':    return is_array($v) AND array_is_list($v);
            case 'object':   return is_array($v);
            case 'any':      return TRUE;
            default:         return TRUE;
        }
    }

    // ---------------------------------------------------------------- правила

    /** Создать правило (или обновить) и вернуть id. */
    public static function save_rule(string $uid, string $name, string $text, array $variants = [], ?string $schema = NULL): array
    {
        self::ensure_tables();

        $db = self::_db();
        $text = trim((string) $text);
        $name = trim((string) $name) !== '' ? mb_substr(trim((string) $name), 0, 160) : ('Правило от '.date('d.m.Y H:i'));

        if ($text === '')
        {
            return ['ok' => FALSE, 'error' => 'Задайте текст правила поиска брака.'];
        }

        $parsed = self::parse_schema($schema);

        if ( ! $parsed['ok'])
        {
            return ['ok' => FALSE, 'error' => $parsed['error']];
        }

        // Тот же человек, то же название, тот же текст — это не новое правило,
        // а повторная проверка (кнопка-пресет или вторая попытка с другим
        // фото). Иначе список правил зарастает копиями после каждого прогона.
        $same = $db->query(Database::SELECT,
            'SELECT id FROM nj_639_rules
             WHERE visitor_uid = '.$db->quote($uid).'
               AND name = '.$db->quote($name).'
               AND rule_text = '.$db->quote(mb_substr($text, 0, 4000)).'
             ORDER BY id DESC LIMIT 1', FALSE)->current();

        if ($same)
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_639_rules SET variants = '.self::_jsonb(self::_clean_variants($variants)).', '
                .'schema = '.($parsed['schema'] === NULL ? 'NULL' : self::_jsonb($parsed['schema']))
                .' WHERE id = '.(int) $same['id'], FALSE);

            return ['ok' => TRUE, 'rule_id' => (int) $same['id'], 'name' => $name];
        }

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_639_rules (visitor_uid, name, rule_text, variants, schema, created_at)
             VALUES ('.$db->quote($uid).', '.$db->quote($name).', '.$db->quote(mb_substr($text, 0, 4000)).', '
            .self::_jsonb(self::_clean_variants($variants)).', '
            .($parsed['schema'] === NULL ? 'NULL' : self::_jsonb($parsed['schema'])).', now())
             RETURNING id, name', FALSE)->current();

        return ['ok' => TRUE, 'rule_id' => (int) $row['id'], 'name' => (string) ($row['name'] ?? $name)];
    }

    protected static function _clean_variants(array $variants): array
    {
        $out = [];

        foreach ($variants as $v)
        {
            if ( ! is_scalar($v))
            {
                continue; // из JSON-тела может прилететь что угодно
            }

            $v = trim((string) $v);

            if ($v !== '' AND mb_strlen($v) <= 200 AND count($out) < 12)
            {
                $out[] = $v;
            }
        }

        return $out;
    }

    public static function rule_by_id($uid, $rule_id): ?array
    {
        self::ensure_tables();

        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'SELECT id, name, rule_text, variants, schema, created_at, visitor_uid
             FROM nj_639_rules WHERE id = '.(int) $rule_id.'
               AND(visitor_uid = '.$db->quote((string) $uid).' OR visitor_uid = \'\')', FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'rule_text'   => (string) $row['rule_text'],
            'variants'    => json_decode((string) $row['variants'], TRUE) ?: [],
            'schema'      => $row['schema'] === NULL ? NULL : (json_decode((string) $row['schema'], TRUE) ?: NULL),
            'visitor_uid' => (string) $row['visitor_uid'],
        ];
    }

    /** Публичный вид правила для формы: схема сериализована в JSON-строку. */
    public static function rule_form_data($uid, int $rule_id): ?array
    {
        $rule = self::rule_by_id((string) $uid, $rule_id);

        if ( ! $rule)
        {
            return NULL;
        }

        return [
            'id'          => (int) $rule['id'],
            'name'        => $rule['name'],
            'rule_text'   => $rule['rule_text'],
            'variants'    => $rule['variants'],
            'schema_json' => self::schema_json_text($rule['schema']),
            'refs'        => self::refs_data_urls($rule_id),
        ];
    }

    /** Схема → читаемая JSON-строка для textarea (или ''). */
    public static function schema_json_text(?array $schema): string
    {
        return $schema === NULL ? '' : (string) json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** Эталоны правила → data URL (для показа в форме). */
    public static function refs_data_urls(?int $rule_id): array
    {
        $raw = self::refs_for_rule($rule_id);

        $out = [];

        foreach ($raw as $r)
        {
            $mt  = is_string($r['media_type'] ?? '') ? $r['media_type'] : 'image/png';
            $out[] = 'data:'.(strpos($mt, '/') === FALSE ? 'image/png' : $mt).';base64,'.($r['b64'] ?? '');
        }

        return $out;
    }

    public static function rules_of($uid, int $limit = 30): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, name, rule_text, variants, created_at FROM nj_639_rules
             WHERE visitor_uid = '.self::_db()->quote((string) $uid).'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $out[] = [
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'rule_text'  => (string) $r['rule_text'],
                'variants'   => json_decode((string) $r['variants'], TRUE) ?: [],
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------- эталоны

    /** Список data-URL эталонов → payload воркера [{b64, media_type}]. */
    public static function refs_payload(array $posted): array
    {
        $refs = [];

        foreach ($posted as $item)
        {
            if ( ! is_string($item) OR $item === '')
            {
                continue;
            }

            $d = self::decode_data_url($item);

            if ($d['ok'])
            {
                $refs[] = ['b64' => base64_encode($d['bytes']), 'media_type' => $d['media_type']];
            }
        }

        return $refs;
    }

    /** Эталоны правила из таблицы nj_639_refs (файлы erp212). */
    public static function refs_for_rule(?int $rule_id): array
    {
        if ((int) $rule_id <= 0 OR ! class_exists('Service_Erp212_Storage'))
        {
            return [];
        }

        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT file_id FROM nj_639_refs WHERE rule_id = '.(int) $rule_id.' ORDER BY id LIMIT '.(int) self::cfg('max_images', 6),
            FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            try
            {
                $f = Service_Erp212_Storage::instance()->getFilePath((int) $r['file_id']);

                if ($f AND is_readable($f))
                {
                    $bytes = @file_get_contents($f);

                    if ($bytes !== FALSE)
                    {
                        $out[] = ['b64' => base64_encode($bytes), 'media_type' => self::sniff_media_type($bytes)];
                    }
                }
            }
            catch (Throwable $e)
            {
                // файл пропал — просто не берём
            }
        }

        return $out;
    }

    /** Сохранить эталон к правилу (erp212 + строка nj_639_refs). */
    public static function store_ref($uid, int $rule_id, $data_url): bool
    {
        self::ensure_tables();

        if ( ! class_exists('Service_Erp212_Storage'))
        {
            return FALSE;
        }

        $d = self::decode_data_url((string) $data_url);

        if ( ! $d['ok'])
        {
            return FALSE;
        }

        // Тот же эталон второй раз (повторный прогон, кнопка-пресет) не должен
        // плодить копии файлов: лимит эталонов правила быстро съедался бы
        // одной и той же картинкой.
        $hash = sha1((string) $d['bytes']);

        foreach (self::refs_for_rule($rule_id) as $known)
        {
            if (sha1((string) base64_decode((string) $known['b64'])) === $hash)
            {
                return TRUE;
            }
        }

        try
        {
            $ext = self::ext_for_media_type($d['media_type']);

            $file_id = Service_Erp212_Storage::instance()->saveRaw($d['bytes'],
                'qc-ref-'.$rule_id.'-'.bin2hex(random_bytes(3)).'.'.$ext,
                ['module' => 'nj639', 'visitor_uid' => (string) $uid]);

            self::_db()->query(Database::INSERT,
                'INSERT INTO nj_639_refs (rule_id, file_id) VALUES ('.(int) $rule_id.', '.(int) $file_id.')', FALSE);

            return TRUE;
        }
        catch (Throwable $e)
        {
            return FALSE;
        }
    }

    // --------------------------------------------------------------- прогоны

    /** Сохранить отдельный эталон-вставку как элемент формы (temp) — возвращает data_url строку. */
    protected static function _capture_payload(array $items): array
    {
        $out = [];

        foreach ($items as $c)
        {
            if (is_array($c) AND isset($c['b64']))
            {
                $out[] = $c;
            }
            elseif (is_string($c) AND $c !== '')
            {
                $d = self::decode_data_url($c);

                if ($d['ok'])
                {
                    $out[] = ['b64' => base64_encode($d['bytes']), 'media_type' => $d['media_type']];
                }
            }
        }

        return $out;
    }

    /**
     * Ручной прогон: фото изделия + эталон + правило + схема.
     * @return array [ok, run_id, status, error?]
     */
    public static function submit(array $p): array
    {
        self::ensure_tables();

        $uid = (string) Visitor::uid();

        $img = self::read_image(is_array($p['data_urls'] ?? NULL) ? $p['data_urls'] : []);

        if ( ! $img['ok'])
        {
            return ['ok' => FALSE, 'error' => $img['error']];
        }

        $capture = [['b64' => base64_encode($img['bytes']), 'media_type' => $img['media_type']]];

        // правило: из сохранённого или из текста (с автосохранением)
        $rule_id = (int) ($p['rule_id'] ?? 0);
        $rule = $rule_id > 0 ? self::rule_by_id($uid, $rule_id) : NULL;

        $rule_text = trim((string) ($p['rule_text'] ?? ''));
        $new_rule = FALSE;

        if ($rule === NULL)
        {
            if ($rule_text === '')
            {
                return ['ok' => FALSE, 'error' => 'Задайте текст правила или выберите сохранённое правило.'];
            }

            $saved = self::save_rule($uid, (string) ($p['rule_name'] ?? ''), $rule_text,
                (array) ($p['variants'] ?? []), (string) ($p['schema_json'] ?? ''));

            if ( ! $saved['ok'])
            {
                return ['ok' => FALSE, 'error' => $saved['error']];
            }

            $rule_id  = (int) $saved['rule_id'];
            $rule     = self::rule_by_id($uid, $rule_id);
            $new_rule = TRUE;

            // эталоны, приложенные к прогону, сохраняются к новому правилу
            foreach ((array) ($p['refs'] ?? []) as $ref_data_url)
            {
                if (is_string($ref_data_url) AND strpos($ref_data_url, 'data:') === 0)
                {
                    self::store_ref($uid, $rule_id, $ref_data_url);
                }
            }
        }

        if ($rule === NULL)
        {
            return ['ok' => FALSE, 'error' => 'Правило не найдено.'];
        }

        if (mb_strlen($rule_text) > 3000)
        {
            return ['ok' => FALSE, 'error' => 'Текст правила длиннее 3000 символов.'];
        }

        // схема (переопределённая в этом запросе — приоритет над сохранённой)
        $schema = $rule['schema'];

        if (array_key_exists('schema_json', $p) AND trim((string) $p['schema_json']) !== '')
        {
            $parsed = self::parse_schema((string) $p['schema_json']);

            if ( ! $parsed['ok'])
            {
                return ['ok' => FALSE, 'error' => $parsed['error']];
            }

            $schema = $parsed['schema'];
        }

        $refs = self::refs_payload((array) ($p['refs'] ?? []));

        // эталоны, приложенные к прогону с УЖЕ существующим правилом, дополняют
        // его базу эталонов (чтобы не потерялись после прогона)
        if ($rule_id > 0 AND ! $new_rule AND $refs)
        {
            foreach ((array) ($p['refs'] ?? []) as $ref_data_url)
            {
                if (is_string($ref_data_url) AND strpos($ref_data_url, 'data:') === 0)
                {
                    self::store_ref($uid, $rule_id, $ref_data_url);
                }
            }
        }

        // если эталоны не приложили — берём сохранённые для правила
        if ( ! $refs)
        {
            $refs = self::refs_for_rule($rule_id);
        }

        if (count($refs) > (int) self::cfg('max_images', 6))
        {
            return ['ok' => FALSE, 'error' => 'Эталонов больше лимита ('.(int) self::cfg('max_images', 6).').'];
        }

        // Текст и варианты, набранные в форме, главнее сохранённых: человек
        // выбрал правило, подправил формулировку (или нажал пресет) и ждёт
        // проверку по тому, что видит на экране, а не по старой записи в БД.
        $run_rule = $rule_text !== '' ? $rule_text : (string) $rule['rule_text'];
        $posted_variants = self::_clean_variants((array) ($p['variants'] ?? []));
        $run_variants = $posted_variants ?: (array) ($rule['variants'] ?? []);

        $enq = self::enqueue($uid, [
            'mode'    => 'manual',
            'rule'    => $run_rule,
            'variants'=> $run_variants,
            'schema'  => $schema,
            'refs'    => $refs,
            'capture' => $capture,
            'source'  => NULL,
        ], NULL, 'manual');

        if ( ! $enq OR empty($enq['job_id']))
        {
            return ['ok' => FALSE, 'error' => $enq['error'] ?? 'Очередь задач недоступна — попробуйте позже.'];
        }

        return ['ok' => TRUE, 'run_id' => (int) $enq['run_id'], 'status' => self::STATUS_QUEUED];
    }

    /**
     * Создать прогон + поставить задачу в очередь nj120 (payload самодостаточен).
     * @return array ['run_id' => int, 'job_id' => int] | [] при ошибке.
     */
    protected static function enqueue(string $uid, array $req, ?int $source_id, string $trigger): array
    {
        $db = self::_db();

        // Кадр и эталоны едут base64 ВНУТРИ задачи общей очереди: её payload
        // читает каждый опрос воркера. Десяток мегабайт там — это и есть
        // «анализ висит» (урок nj646), поэтому ставим честный потолок.
        $weight = 0;

        foreach (array_merge((array) ($req['capture'] ?? []), (array) ($req['refs'] ?? [])) as $img)
        {
            $weight += strlen((string) (is_array($img) ? ($img['b64'] ?? '') : $img));
        }

        if ($weight > self::PAYLOAD_MAX_B64)
        {
            return ['run_id' => 0, 'job_id' => 0, 'error' => 'Кадр и эталоны вместе тяжелее '
                .round(self::PAYLOAD_MAX_B64 / (1024 * 1024)).' МБ — уменьшите снимок '
                .'(хватает 1400 px по ширине) или снимите часть эталонов.'];
        }

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_639_runs (visitor_uid, client_ip, source_id, mode, trigger, status, created_at)
             VALUES ('.$db->quote($uid).', '.$db->quote(self::client_ip()).', '
            .($source_id === NULL ? 'NULL' : (int) $source_id).', '
            .$db->quote(isset($req['mode']) ? (string) $req['mode'] : 'manual').', '
            .$db->quote($trigger).', '.$db->quote(self::STATUS_QUEUED).', now())
             RETURNING id', FALSE)->current();

        $run_id = (int) $row['id'];

        $payload = [
            'run_id'  => $run_id,
            'mode'    => $req['mode'] ?? 'manual',
            'rule'    => (string) ($req['rule'] ?? ''),
            'variants'=> (array) ($req['variants'] ?? []),
            'schema'  => $req['schema'] ?? NULL,
            'refs'    => (array) ($req['refs'] ?? []),
            'capture' => (array) ($req['capture'] ?? []),
            'source'  => $req['source'] ?? NULL,
        ];

        try
        {
            $job_id = Service_Nj120_JobQueue::push(self::CHANNEL_ANALYZE, $payload, [
                'max_attempts' => 2,
                'module'       => 'nj639',
                'timeout_sec'  => 1200,
            ]);

            $db->query(Database::UPDATE,
                'UPDATE nj_639_runs SET job_id = '.(int) $job_id.' WHERE id = '.(int) $run_id, FALSE);

            return ['run_id' => $run_id, 'job_id' => (int) $job_id];
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj639: не удалось поставить задачу: :e', [':e' => $e->getMessage()]);
            $db->query(Database::UPDATE,
                "UPDATE nj_639_runs SET status = 'error', error = 'Очередь задач недоступна', finished_at = now() WHERE id = ".(int) $run_id, FALSE);

            return ['run_id' => $run_id, 'job_id' => 0];
        }
    }

    /**
     * Прогон из источника (webhook/датчик/таймер/очередь): по коду источника
     * достаём правило+эталон, прикладываем кадр (или ставим задачу на захват)
     * и ставим задачу в очередь. @return array [ok, run_id, job_id, error?]
     */
    public static function source_fire(array $p): array
    {
        self::ensure_tables();

        $db  = self::_db();
        $src = NULL;

        if (isset($p['source_id']) AND (int) $p['source_id'] > 0)
        {
            $src = self::source_by_id((int) $p['source_id']);
        }
        elseif (isset($p['code']) AND is_string($p['code']) AND $p['code'] !== '')
        {
            $src = self::source_by_code('', (string) $p['code'], FALSE);
        }

        if ( ! $src)
        {
            return ['ok' => FALSE, 'error' => 'Источник не найден.'];
        }

        if ( ! $src['enabled'])
        {
            return ['ok' => FALSE, 'error' => 'Источник выключен.'];
        }

        $rule = self::rule_by_id((string) $src['visitor_uid'], (int) $src['rule_id']);

        if ( ! $rule)
        {
            return ['ok' => FALSE, 'error' => 'Правило источника не найдено.'];
        }

        $capture = self::_capture_payload((array) ($p['capture'] ?? []));

        $source = [
            'code'         => (string) $src['code'],
            'kind'         => (string) $src['kind'],
            'config'       => $src['config'],
            'trigger'      => (string) $src['trigger'],
            'interval_sec' => (int) $src['interval_sec'],
        ];

        $enq = self::enqueue((string) $src['visitor_uid'], [
            'mode'    => 'source',
            'rule'    => $rule['rule_text'],
            'variants'=> $rule['variants'] ?? [],
            'schema'  => $rule['schema'],
            'refs'    => self::refs_for_rule((int) $src['rule_id']),
            'capture' => $capture,
            'source'  => $source,
        ], (int) $src['id'], (string) ($p['trigger'] ?? $src['trigger']));

        if ( ! $enq OR empty($enq['job_id']))
        {
            return ['ok' => FALSE, 'error' => $enq['error'] ?? 'Очередь задач недоступна.'];
        }

        self::touch_source_run((int) $src['id']);

        return ['ok' => TRUE, 'run_id' => (int) $enq['run_id'], 'job_id' => (int) $enq['job_id']];
    }

    // ------------------------------------------------------- статус/результат

    public static function run_by_id($uid, int $run_id): ?array
    {
        self::ensure_tables();

        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_639_runs WHERE id = '.(int) $run_id
            .' AND(visitor_uid = '.$db->quote((string) $uid).' OR visitor_uid = \'\')', FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        $run = self::_run_row($row);

        return self::reconcile($run);
    }

    protected static function _run_row(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'uid'         => (string) $row['visitor_uid'],
            'source_id'   => $row['source_id'] === NULL ? NULL : (int) $row['source_id'],
            'mode'        => (string) $row['mode'],
            'trigger'     => (string) $row['trigger'],
            'status'      => (string) $row['status'],
            'verdict'     => $row['verdict'] === NULL ? NULL : (json_decode((string) $row['verdict'], TRUE) ?: NULL),
            'schema_ok'   => $row['schema_ok'] === NULL ? NULL : (bool) $row['schema_ok'],
            'model'       => (string) $row['model'],
            'prompt_tokens'     => (int) $row['prompt_tokens'],
            'completion_tokens' => (int) $row['completion_tokens'],
            'duration_ms' => (int) $row['duration_ms'],
            'error'       => (string) ($row['error'] ?? ''),
            'job_id'      => (int) $row['job_id'],
            'created_at'  => (string) $row['created_at'],
            'finished_at' => $row['finished_at'] === NULL ? NULL : (string) $row['finished_at'],
        ];
    }

    /** Lazy reconcile: подтянуть статус висящей задачи nj120 (паттерн nj570). */
    public static function reconcile(array $run): array
    {
        if ($run['status'] !== self::STATUS_QUEUED OR $run['job_id'] <= 0)
        {
            return $run;
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return $run;
        }

        try
        {
            $job = Service_Nj120_JobQueue::instance()->get((int) $run['job_id']);
        }
        catch (Throwable $e)
        {
            return $run;
        }

        if ($job === NULL)
        {
            return $run;
        }

        $db = self::_db();

        if ($job['status'] === Service_Nj120_JobQueue::STATUS_DONE)
        {
            // воркер обычно уже записал результат через worker-result; страхуемся
            $res = $job['result'];
            is_string($res) AND $res = json_decode($res, TRUE);
            $res = is_array($res) ? $res : [];

            if (isset($res['verdict']) AND is_array($res['verdict']))
            {
                self::apply_result((int) $run['id'], $res);
            }
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_FAILED)
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_639_runs SET status = 'error', error = ".$db->quote(mb_substr((string) $job['error'], 0, 900)).",
                 finished_at = now() WHERE id = ".(int) $run['id'], FALSE);
        }
        elseif (in_array($job['status'], [Service_Nj120_JobQueue::STATUS_PENDING, Service_Nj120_JobQueue::STATUS_PROCESSING], TRUE))
        {
            $queue = Service_Nj120_JobQueue::instance();
            // Пульс задачи: воркер мог умереть, не отпустив задачу. Раньше это
            // выяснялось только по общему таймауту (задача + 5 мин), и страница
            // всё это время показывала «анализ идёт». Молчание дольше общего
            // порога (~4 мин) закрываем сразу — с внятной причиной.
            $beat  = $job['heartbeat_at'] ?: ($job['claimed_at'] ?: $job['created_at']);
            $quiet = $beat ? (time() - strtotime((string) $beat)) : 0;
            $limit = (int) ($job['timeout_sec'] ?: 600) + 300;

            if ($quiet > $queue->silent_stop_sec())
            {
                $queue->auto_stop_silent((int) $job['id']);

                $db->query(Database::UPDATE,
                    "UPDATE nj_639_runs SET status = 'error', error = 'Воркер не отвечал больше "
                    .max(1, (int) round($queue->silent_stop_sec() / 60))
                    ." мин — анализ остановлен автоматически. Запустите заново',
                     finished_at = now() WHERE id = ".(int) $run['id'], FALSE);
            }
            elseif ($job['claimed_at'] ? ((time() - strtotime((string) $job['claimed_at'])) > $limit) : ((time() - strtotime((string) $job['created_at'])) > $limit))
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_639_runs SET status = 'error', error = 'Задача зависла (воркер недоступен) — попробуйте ещё раз',
                     finished_at = now() WHERE id = ".(int) $run['id'], FALSE);
            }
        }

        $row = $db->query(Database::SELECT, 'SELECT * FROM nj_639_runs WHERE id = '.(int) $run['id'], FALSE)->current();

        return $row ? self::_run_row($row) : $run;
    }

    /** Записать финальный результат (вызов воркера worker-result). */
    public static function apply_result(int $run_id, array $res): bool
    {
        self::ensure_tables();

        $db = self::_db();

        $verdict = (isset($res['verdict']) AND is_array($res['verdict'])) ? $res['verdict'] : NULL;

        $status = isset($res['status']) ? (string) $res['status'] : (isset($res['ok']) && $res['ok'] ? 'done' : 'error');
        $status = in_array($status, ['done', 'error', 'queued'], TRUE) ? $status : 'done';

        $db->query(Database::UPDATE,
            'UPDATE nj_639_runs SET '
            .'status = '.$db->quote($status).', '
            .'verdict = '.($verdict === NULL ? 'NULL' : self::_jsonb($verdict)).', '
            // ?? обязателен: без ключа schema_ok PHP 8 поднимает E_WARNING, а
            // обработчик ошибок Kohana превращает его в исключение — приёмка
            // воркера отвечала бы 500 вместо записи вердикта.
            .'schema_ok = '.(($res['schema_ok'] ?? NULL) === NULL ? 'NULL' : ($res['schema_ok'] ? 'TRUE' : 'FALSE')).', '
            .'model = '.self::_q(isset($res['model']) ? mb_substr((string) $res['model'], 0, 160) : NULL).', '
            .'prompt_tokens = '.(int) ($res['prompt_tokens'] ?? 0).', '
            .'completion_tokens = '.(int) ($res['completion_tokens'] ?? 0).', '
            .'duration_ms = '.(int) ($res['duration_ms'] ?? 0).', '
            .'error = '.self::_q(isset($res['error']) ? mb_substr((string) $res['error'], 0, 900) : NULL).', '
            .'finished_at = now() WHERE id = '.(int) $run_id, FALSE);

        // Дожим очереди (образец nj602): прогон УСПЕШНО записан сайтом — задача
        // nj120 обязана стать «Готово», даже если воркер не успел complete() или
        // свипер отказал её за секунды до доставки. Только для status='done':
        // 'error' — это честный провал прогона, его закрывает сам воркер.
        if ($status === 'done')
        {
            try
            {
                $jrow = $db->query(Database::SELECT,
                    'SELECT job_id FROM nj_639_runs WHERE id = '.(int) $run_id, FALSE)->current();

                if (class_exists('Service_Nj120_JobQueue') AND $jrow AND (int) ($jrow['job_id'] ?? 0) > 0)
                {
                    Service_Nj120_JobQueue::instance()->mark_delivered(
                        (int) $jrow['job_id'],
                        'Результат контроля качества принят сайтом — задача закрыта из приёмки nj639',
                    );
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj639: связать задачу с готовым прогоном не удалось: :e',
                    [':e' => $e->getMessage()]);
            }
        }

        return TRUE;
    }

    public static function recent_runs($uid, int $limit = 12): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, mode, trigger, status, error, verdict, created_at, finished_at
             FROM nj_639_runs WHERE visitor_uid = '.self::_db()->quote((string) $uid).'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $verdict = $r['verdict'] === NULL ? NULL : (json_decode((string) $r['verdict'], TRUE) ?: NULL);

            $out[] = [
                'id'          => (int) $r['id'],
                'mode'        => (string) $r['mode'],
                'trigger'     => (string) $r['trigger'],
                'status'      => (string) $r['status'],
                'error'       => (string) ($r['error'] ?? ''),
                'verdict'     => $verdict,
                'summary'     => self::verdict_summary($verdict),
                'created_at'  => (string) $r['created_at'],
                'finished_at' => $r['finished_at'] === NULL ? NULL : (string) $r['finished_at'],
            ];
        }

        return $out;
    }

    /** Краткое текстовое резюме вердикта для списка истории. */
    public static function verdict_summary($verdict): string
    {
        if ( ! is_array($verdict))
        {
            return '';
        }

        if (isset($verdict['verdict']) AND is_scalar($verdict['verdict']))
        {
            $s = trim((string) $verdict['verdict']);

            if ($s !== '')
            {
                return mb_substr($s, 0, 120);
            }
        }

        if (isset($verdict['defects']) AND is_array($verdict['defects']) AND $verdict['defects'])
        {
            $parts = array_filter(array_map(function ($d) {
                return trim((string) $d);
            }, $verdict['defects']));

            if ($parts)
            {
                return mb_substr('дефекты: '.implode(', ', $parts), 0, 120);
            }
        }

        if (isset($verdict['ok']) AND is_bool($verdict['ok']))
        {
            return $verdict['ok'] ? 'годен' : 'брак';
        }

        return '';
    }

    // -------------------------------------------------------- источники (авто)

    public static function source_kinds(): array
    {
        return [
            'snapshot' => 'IP-камера: Snapshot URL / RTSP',
            'onvif'    => 'IP-камера: ONVIF',
            'ftp'      => 'FTP / Hot-Folder (file drop)',
            'http'     => 'Объектное хранилище / HTTP файл',
            'folder'   => 'Локальная папка (агент воркера)',
            'queue'    => 'Очередь сообщений (Kafka / RabbitMQ / MQTT)',
        ];
    }

    public static function source_triggers(): array
    {
        return [
            'timer'   => 'По таймеру (опрос каждые N секунд)',
            'webhook' => 'Внешний webhook (датчик/ПЛК)',
            'motion'  => 'Встроенный детектор движения (edge-CV)',
        ];
    }

    public static function create_source($uid, array $p): array
    {
        self::ensure_tables();

        $db = self::_db();
        $kind = (string) ($p['kind'] ?? 'snapshot');

        if ( ! array_key_exists($kind, self::source_kinds()))
        {
            return ['ok' => FALSE, 'error' => 'Неизвестный тип источника. Доступно: '.implode(', ', array_keys(self::source_kinds())).'.'];
        }

        $trigger = (string) ($p['trigger'] ?? 'timer');

        if ( ! array_key_exists($trigger, self::source_triggers()))
        {
            return ['ok' => FALSE, 'error' => 'Неизвестный тип триггера.'];
        }

        $name = trim((string) ($p['name'] ?? ''));

        if ($name === '')
        {
            return ['ok' => FALSE, 'error' => 'Назовите источник.'];
        }

        $rule_id = (int) ($p['rule_id'] ?? 0);

        if ($rule_id <= 0 OR ! self::rule_by_id((string) $uid, $rule_id))
        {
            return ['ok' => FALSE, 'error' => 'Привяжите источник к сохранённому правилу.'];
        }

        $config = $p['config'] ?? [];

        if ( ! is_array($config))
        {
            $decoded = json_decode((string) $config, TRUE);
            $config = is_array($decoded) ? $decoded : [];
        }

        $config = self::_source_config($kind, $config);

        if ($trigger === 'webhook')
        {
            $config['webhook_token'] = bin2hex(random_bytes(16));
        }

        $interval = max(5, min(3600, (int) ($p['interval_sec'] ?? 60)));

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_639_sources (visitor_uid, code, name, kind, config, rule_id, trigger, interval_sec, enabled, next_run_at, created_at)
             VALUES ('.$db->quote((string) $uid).', '.$db->quote('qc'.bin2hex(random_bytes(4))).', '.$db->quote(mb_substr($name, 0, 140)).', '
            .$db->quote($kind).', '.self::_jsonb($config).', '.(int) $rule_id.', '
            .$db->quote($trigger).', '.$interval.', TRUE, now(), now())
             RETURNING id', FALSE)->current();

        // вебхук — ставки на таблицу rules нужны только для источника; сразу отдаём url
        $wh = $trigger === 'webhook' ? URL::site('qc/webhook/'.$config['webhook_token'], TRUE) : NULL;

        return ['ok' => TRUE, 'source_id' => (int) $row['id'], 'webhook_url' => $wh];
    }

    protected static function _source_config(string $kind, array $c): array
    {
        $pick = function ($keys, $maxlen) use ($c) {
            foreach ($keys as $k)
            {
                if (array_key_exists($k, $c) AND is_scalar($c[$k]) AND trim((string) $c[$k]) !== '')
                {
                    return mb_substr(trim((string) $c[$k]), 0, $maxlen);
                }
            }

            return '';
        };

        $num = function ($keys, $min, $max, $def) use ($c) {
            $v = $def;

            foreach ($keys as $k)
            {
                if (array_key_exists($k, $c) AND is_numeric($c[$k]))
                {
                    $v = (int) $c[$k];
                    break;
                }
            }

            return max($min, min($max, $v));
        };

        switch ($kind)
        {
            case 'snapshot':
                return [
                    'snapshot_url' => $pick(['snapshot_url', 'url'], 500),
                    'rtsp_url'     => $pick(['rtsp_url'], 500),
                    'login'        => $pick(['login', 'user'], 120),
                    'password'     => $pick(['password', 'pass'], 200),
                ];

            case 'onvif':
                return [
                    'host'     => $pick(['host', 'url', 'snapshot_url'], 300),
                    'port'     => $num(['port'], 1, 65535, 80),
                    'login'    => $pick(['login', 'user'], 120),
                    'password' => $pick(['password', 'pass'], 200),
                ];

            case 'ftp':
                return [
                    'host'       => $pick(['host', 'url'], 300),
                    'port'       => $num(['port'], 1, 65535, 21),
                    'login'      => $pick(['login', 'user'], 120),
                    'password'   => $pick(['password', 'pass'], 200),
                    'remote_dir' => $pick(['remote_dir', 'path', 'dir'], 400),
                    'pattern'    => $pick(['pattern', 'mask'], 100),
                ];

            case 'http':
                return [
                    'url'     => $pick(['url', 'snapshot_url'], 500),
                    'headers' => (isset($c['headers']) AND is_array($c['headers']))
                        ? json_encode(array_slice(array_map('strval', $c['headers']), 0, 10), JSON_UNESCAPED_UNICODE)
                        : $pick(['headers'], 500),
                    'login'   => $pick(['login', 'user'], 120),
                    'password'=> $pick(['password', 'pass'], 200),
                ];

            case 'folder':
                return [
                    'path'    => $pick(['path', 'dir'], 500),
                    'pattern' => $pick(['pattern', 'mask'], 100),
                ];

            case 'queue':
                return [
                    'protocol' => $pick(['protocol', 'type'], 20) ?: 'mqtt',
                    'host'     => $pick(['host'], 300),
                    'port'     => $num(['port'], 1, 65535, 1883),
                    'login'    => $pick(['login', 'user'], 120),
                    'password' => $pick(['password', 'pass'], 200),
                    'topic'    => $pick(['topic', 'queue', 'routing_key'], 300),
                    'via'      => $pick(['via', 'message_format'], 30) ?: 'url', // url | base64
                ];

            default:
                return [];
        }
    }

    public static function sources_of($uid, int $limit = 50): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, code, name, kind, trigger, interval_sec, enabled, rule_id, last_run_at
             FROM nj_639_sources WHERE visitor_uid = '.self::_db()->quote((string) $uid).'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $out[] = [
                'id'           => (int) $r['id'],
                'code'         => (string) $r['code'],
                'name'         => (string) $r['name'],
                'kind'         => (string) $r['kind'],
                'trigger'      => (string) $r['trigger'],
                'interval_sec' => (int) $r['interval_sec'],
                'enabled'      => (string) $r['enabled'] === '1' OR $r['enabled'] === 't' OR $r['enabled'] === 'true',
                'rule_id'      => (int) $r['rule_id'],
                'last_run_at'  => $r['last_run_at'] === NULL ? NULL : (string) $r['last_run_at'],
            ];
        }

        return $out;
    }

    /** Полный ряд источника (для воркера/cron), включая visitor_uid. */
    public static function source_by_id(int $id): ?array
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_639_sources WHERE id = '.(int) $id, FALSE)->current();

        return $row ? self::_source_row($row) : NULL;
    }

    public static function source_by_code($uid, string $code, bool $scoped = TRUE): ?array
    {
        self::ensure_tables();

        $db  = self::_db();
        $sql = 'SELECT * FROM nj_639_sources WHERE code = '.$db->quote($code);

        if ($scoped)
        {
            $sql .= ' AND(visitor_uid = '.$db->quote((string) $uid).' OR visitor_uid = \'\')';
        }

        $row = $db->query(Database::SELECT, $sql.' LIMIT 1', FALSE)->current();

        return $row ? self::_source_row($row) : NULL;
    }

    protected static function _source_row(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'visitor_uid'  => (string) $row['visitor_uid'],
            'code'         => (string) $row['code'],
            'name'         => (string) $row['name'],
            'kind'         => (string) $row['kind'],
            'trigger'      => (string) $row['trigger'],
            'interval_sec' => (int) $row['interval_sec'],
            'enabled'      => (string) $row['enabled'] === '1' OR $row['enabled'] === 't' OR $row['enabled'] === 'true',
            'rule_id'      => (int) $row['rule_id'],
            'config'       => json_decode((string) $row['config'], TRUE) ?: [],
            'last_run_at'  => $row['last_run_at'] === NULL ? NULL : (string) $row['last_run_at'],
        ];
    }

    public static function touch_source_run(int $source_id)
    {
        self::ensure_tables();

        try
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_639_sources SET last_run_at = now() WHERE id = '.(int) $source_id, FALSE);
        }
        catch (Throwable $e)
        {
            // не критично
        }
    }

    /** Все созревшие timer-источники (для cron-пиналки nj603). */
    public static function due_sources(int $limit = 50): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, code, interval_sec FROM nj_639_sources
             WHERE trigger = '.self::_db()->quote('timer')."
               AND enabled = TRUE
               AND (next_run_at IS NULL OR next_run_at <= now())
             ORDER BY id ASC LIMIT ".(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $out[] = ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'interval_sec' => (int) $r['interval_sec']];
        }

        return $out;
    }

    public static function schedule_source(int $source_id, int $interval_sec)
    {
        self::ensure_tables();

        try
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_639_sources SET next_run_at = now() + interval \''.(int) $interval_sec.' seconds\' WHERE id = '.(int) $source_id,
                FALSE);
        }
        catch (Throwable $e)
        {
            // не критично
        }
    }

    /** Cron-пиналка nj603: поставить задачи на захват для созревших timer-источников. */
    public static function cron_poll(): array
    {
        $queued = 0;

        foreach (self::due_sources() as $s)
        {
            try
            {
                $res = self::source_fire(['source_id' => (int) $s['id'], 'trigger' => 'timer', 'capture' => []]);

                if (($res['ok'] ?? FALSE))
                {
                    $queued++;
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj639: cron_poll: :e', [':e' => $e->getMessage()]);
            }

            self::schedule_source((int) $s['id'], (int) $s['interval_sec']);
        }

        return ['queued' => $queued];
    }

    /** Источник по webhook-токену. */
    public static function source_by_webhook(string $token): ?array
    {
        self::ensure_tables();

        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            "SELECT * FROM nj_639_sources
             WHERE trigger = 'webhook'
               AND config->>'webhook_token' = ".$db->quote($token)."
             LIMIT 1", FALSE)->current();

        return $row ? self::_source_row($row) : NULL;
    }

    // ------------------------------------------------------------ схема

    public static function ensure_tables()
    {
        if (trim((string) getenv('NJ639_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        DB::query(NULL, <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_639_rules" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "name"        VARCHAR(160) NOT NULL DEFAULT '',
    "rule_text"   TEXT         NOT NULL DEFAULT '',
    "variants"    JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "schema"      JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS "nj_639_refs" (
    "id"       BIGSERIAL PRIMARY KEY,
    "rule_id"  BIGINT    NOT NULL DEFAULT 0,
    "file_id"  BIGINT    NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS "nj_639_refs_rule_idx" ON "nj_639_refs" ("rule_id");
CREATE TABLE IF NOT EXISTS "nj_639_runs" (
    "id"                BIGSERIAL   PRIMARY KEY,
    "visitor_uid"       VARCHAR(40) NOT NULL DEFAULT '',
    "client_ip"         VARCHAR(64) NOT NULL DEFAULT '',
    "source_id"         BIGINT      NULL,
    "mode"              VARCHAR(12) NOT NULL DEFAULT 'manual',
    "trigger"           VARCHAR(12) NOT NULL DEFAULT 'manual',
    "status"            VARCHAR(12) NOT NULL DEFAULT 'queued',
    "verdict"           JSONB       NULL,
    "schema_ok"         BOOLEAN     NULL,
    "model"             VARCHAR(160) NULL,
    "prompt_tokens"     INTEGER     NOT NULL DEFAULT 0,
    "completion_tokens" INTEGER     NOT NULL DEFAULT 0,
    "duration_ms"       INTEGER     NOT NULL DEFAULT 0,
    "error"             TEXT        NULL,
    "job_id"            BIGINT      NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ NOT NULL DEFAULT now(),
    "finished_at"       TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS "nj_639_runs_uid_idx" ON "nj_639_runs" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_639_runs_ip_idx"  ON "nj_639_runs" ("client_ip", "id" DESC);
CREATE TABLE IF NOT EXISTS "nj_639_sources" (
    "id"           BIGSERIAL    PRIMARY KEY,
    "visitor_uid"  VARCHAR(40)  NOT NULL DEFAULT '',
    "code"         VARCHAR(40)  NOT NULL DEFAULT '',
    "name"         VARCHAR(140) NOT NULL DEFAULT '',
    "kind"         VARCHAR(16)  NOT NULL DEFAULT 'snapshot',
    "config"       JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "rule_id"      BIGINT       NOT NULL DEFAULT 0,
    "trigger"      VARCHAR(12)  NOT NULL DEFAULT 'timer',
    "interval_sec" INTEGER      NOT NULL DEFAULT 60,
    "enabled"      BOOLEAN      NOT NULL DEFAULT TRUE,
    "last_run_at"  TIMESTAMPTZ  NULL,
    "next_run_at"  TIMESTAMPTZ  NULL,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_639_sources_uid_idx" ON "nj_639_sources" ("visitor_uid", "id" DESC);
SQL
        )->execute();
    }
}
