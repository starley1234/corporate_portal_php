<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Камеры цеха» (nj640_cameras) — серверный слой видеонаблюдения цеха/офиса.
 *
 * Контролируемые сущности (управляются конфигом topics()):
 *   discipline  — трудовая дисциплина (присутствие на месте, посторонние…);
 *   ppe         — средства индивидуальной защиты (каска, жилет, очки…);
 *   equipment   — загрузка оборудования (работает/простаивает, зоны).
 *
 * Схема «событие/триггер → кадр → VLM → JSON-схема → ответ API + БД»:
 *   1. Пользователь задаёт СЦЕНАРИЙ (пресет темы + текст + схема ответа) и
 *      КАМЕРУ (источник кадра: snapshot/rtsp/onvif/http + триггер + часы).
 *   2. Триггер (таймер через nj603-«пиналку», webhook, ручной тест) ставит
 *      задачу очереди nj120 (канал nj640.analyze).
 *   3. Воркер захватывает МАЛЕНЬКИЙ кадр, шлёт его VLM с текстом сценария и
 *      схемой, валидирует ответ схемой, возвращает результат через
 *      /cameras/api/worker-result (X-Worker-Token).
 *   4. Сайт пишет вердикт в nj_640_runs и, если сценарий нашёл нарушение,
 *      ведёт dedupe/cooldown-журнал событий nj_640_events + уведомление
 *      через Service_Nj597_Notify.
 *
 * Безопасность: rate limit (nj100_platform), лимиты размеров, валидация
 * схемы без eval, SSRF-запреты на стороне воркера, секреты только из .env.
 */
class Service_Nj640_Cameras
{
    const RATE_PER_MINUTE = 5;
    const RATE_PER_HOUR   = 40;

    const CHANNEL_ANALYZE = 'nj640.analyze';

    const STATUS_QUEUED = 'queued';
    const STATUS_DONE   = 'done';
    const STATUS_ERROR  = 'error';

    const NID_KIND = 'nj640_cameras';

    /** Конфиг модуля. */
    public static function cfg($key, $default = NULL)
    {
        $cfg = Kohana::$config->load('nj640_cameras');

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
            'nj_640_runs', (string) $ip, 'client_ip');

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

    // -------------------------------------------------------- темы сценариев

    /** Темы контроля (пресеты). label — для UI, prompt — базовый текст сценария. */
    public static function topics(): array
    {
        return [
            'discipline' => [
                'label'  => 'Трудовая дисциплина',
                'prompt' => 'Оцени трудовую дисциплину на кадре: все ли сотрудники на рабочих местах, '
                           .'нет ли посторонних лиц, сна на посту, скопления людей не по процессу, '
                           .'отсутствия сотрудника на закреплённом месте.',
            ],
            'ppe' => [
                'label'  => 'Средства защиты (СИЗ)',
                'prompt' => 'Проверь средства индивидуальной защиты на кадре: каски, защитные очки, '
                           .'жилеты, перчатки, спецобувь. Отметь людей без обязательных СИЗ и что именно '
                           .'отсутствует.',
            ],
            'equipment' => [
                'label'  => 'Загрузка оборудования',
                'prompt' => 'Оцени загрузку оборудования на кадре: работает ли станок/конвейер, '
                           .'есть ли признаки простоя (нет оператора, стоп-сигнал, пустая зона), '
                           .'сколько единиц техники задействовано.',
            ],
        ];
    }

    /** Иконка темы для UI. */
    public static function topic_icon(string $topic): string
    {
        switch ($topic)
        {
            case 'ppe':
                return 'fa-hard-hat';
            case 'equipment':
                return 'fa-industry';
            default:
                return 'fa-user-clock';
        }
    }

    /** Стандартная выходная JSON-схема темы (контракт ответа VLM). */
    public static function topic_default_schema(string $topic): ?array
    {
        switch ($topic)
        {
            case 'ppe':
                return [
                    'type' => 'object',
                    'required' => ['violations', 'verdict'],
                    'properties' => [
                        'violations' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'verdict'    => ['type' => 'string', 'enum' => ['ok', 'violation', 'unclear']],
                        'confidence' => ['type' => 'number'],
                    ],
                ];

            case 'equipment':
                return [
                    'type' => 'object',
                    'required' => ['equipment_state', 'verdict'],
                    'properties' => [
                        'equipment_state' => ['type' => 'string'],
                        'verdict'         => ['type' => 'string', 'enum' => ['busy', 'idle', 'unclear']],
                        'confidence'      => ['type' => 'number'],
                    ],
                ];

            default: // discipline
                return [
                    'type' => 'object',
                    'required' => ['violations', 'verdict'],
                    'properties' => [
                        'violations' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'verdict'    => ['type' => 'string', 'enum' => ['ok', 'violation', 'unclear']],
                        'confidence' => ['type' => 'number'],
                    ],
                ];
        }
    }

    // ------------------------------------------------------ изображение/схема

    public static function max_image_bytes()
    {
        return (int) self::cfg('max_image_mb', 15) * 1024 * 1024;
    }

    /** Прочитать загруженный кадр из multipart ($_FILES) или base64 data URL. */
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
                return ['ok' => FALSE, 'error' => 'Кадр больше лимита '.self::cfg('max_image_mb', 15).' МБ.'];
            }

            $bytes = @file_get_contents($f['tmp_name']);

            if ($bytes === FALSE)
            {
                return ['ok' => FALSE, 'error' => 'Не удалось прочитать файл.'];
            }

            if ( ! self::looks_like_image($bytes))
            {
                return ['ok' => FALSE, 'error' => 'Файл не похож на изображение.'];
            }

            return ['ok' => TRUE, 'name' => (string) ($f['name'] ?? 'frame.jpg'),
                    'bytes' => $bytes, 'media_type' => self::sniff_media_type($bytes)];
        }

        foreach ((array) $data_urls as $url)
        {
            if (is_string($url) AND strpos($url, 'data:') === 0)
            {
                $d = self::decode_data_url($url);

                if ($d['ok'])
                {
                    return $d;
                }
            }
        }

        return ['ok' => FALSE, 'error' => 'Приложите кадр (файл или data URL).'];
    }

    /** Разбор data: URL изображения. */
    public static function decode_data_url($url): array
    {
        $s = (string) $url;

        if ( ! preg_match('~^data:([a-z0-9.+-]+/[a-z0-9.+-]+)?(?:;charset=[^;]+)?(?:;base64)?,(.*)$~is', $s, $m))
        {
            return ['ok' => FALSE, 'error' => 'Некорректный data URL.'];
        }

        $mt  = strtolower((string) ($m[1] ?? ''));
        $raw = (string) $m[2];

        if (strpos(strtolower($s), ';base64') !== FALSE)
        {
            $bytes = base64_decode($raw, TRUE);
        }
        else
        {
            $bytes = rawurldecode($raw);
        }

        if ($bytes === FALSE OR $bytes === '' OR strlen($bytes) > self::max_image_bytes())
        {
            return ['ok' => FALSE, 'error' => 'Размер кадра вне лимита ('.self::cfg('max_image_mb', 15).' МБ).'];
        }

        if ( ! in_array($mt, ['image/jpeg', 'image/png', 'image/webp', 'image/bmp', 'image/gif', ''], TRUE))
        {
            if ( ! self::looks_like_image($bytes))
            {
                return ['ok' => FALSE, 'error' => 'Data URL не является изображением.'];
            }

            $mt = self::sniff_media_type($bytes);
        }

        if ( ! self::looks_like_image($bytes))
        {
            return ['ok' => FALSE, 'error' => 'Данные не похожи на изображение.'];
        }

        return ['ok' => TRUE, 'bytes' => $bytes, 'media_type' => $mt !== '' ? $mt : self::sniff_media_type($bytes)];
    }

    /** Облегчённая проверка «похоже на изображение» по магии формата. */
    public static function looks_like_image($bytes)
    {
        if ( ! is_string($bytes) OR $bytes === '')
        {
            return FALSE;
        }

        $head = substr($bytes, 0, 12);

        if (strpos($head, "\xFF\xD8\xFF") === 0) return TRUE;               // JPEG
        if (strpos($head, "\x89PNG\r\n\x1A\n") === 0) return TRUE;         // PNG
        if (strpos($head, 'GIF87a') === 0 OR strpos($head, 'GIF89a') === 0) return TRUE; // GIF
        if (strpos($bytes, 'BM') === 0) return TRUE;                       // BMP
        if (strpos(substr($bytes, 0, 16), 'RIFF') === 0 AND strpos(substr($bytes, 8, 8), 'WEBP') === 0) return TRUE; // WEBP

        return FALSE;
    }

    public static function sniff_media_type($bytes)
    {
        if (strpos((string) $bytes, "\xFF\xD8\xFF") === 0) return 'image/jpeg';
        if (strpos((string) $bytes, "\x89PNG\r\n\x1A\n") === 0) return 'image/png';
        if (strpos((string) $bytes, 'GIF8') === 0) return 'image/gif';
        if (strpos((string) $bytes, 'BM') === 0) return 'image/bmp';
        if (strpos(substr((string) $bytes, 0, 16), 'RIFF') === 0 AND strpos(substr((string) $bytes, 8, 8), 'WEBP') === 0) return 'image/webp';

        return 'application/octet-stream';
    }

    /** Парсинг выходной JSON-схемы (безопасное подмножество draft-07). */
    public static function parse_schema($json)
    {
        $s = trim((string) $json);

        if ($s === '')
        {
            return ['ok' => TRUE, 'schema' => NULL];
        }

        $decoded = json_decode($s, TRUE);

        if ( ! is_array($decoded))
        {
            return ['ok' => FALSE, 'error' => 'Схема не является валидным JSON-объектом.'];
        }

        if (isset($decoded['type']) AND ! in_array($decoded['type'], ['object', 'array', 'string', 'number', 'integer', 'boolean'], TRUE))
        {
            return ['ok' => FALSE, 'error' => 'Поддерживаются схемы type: object/array/string/number/boolean.'];
        }

        return ['ok' => TRUE, 'schema' => $decoded];
    }

    /** Валидация вердикта VLM по подмножеству схемы. @return [ok, verdict, errors] */
    public static function validate_verdict($data, ?array $schema): array
    {
        $verdict = is_array($data) ? $data : [];
        $errors  = [];

        if ( ! is_array($schema) OR ! $schema)
        {
            return [TRUE, $verdict, []];
        }

        $type = $schema['type'] ?? 'object';

        if ($type === 'object')
        {
            if ( ! self::_is_assoc($verdict))
            {
                return [FALSE, $verdict, ['ожидался объект']];
            }

            foreach ((array) ($schema['required'] ?? []) as $req)
            {
                if ( ! array_key_exists($req, $verdict))
                {
                    $errors[] = 'нет обязательного поля '.$req;
                }
            }

            $props = (array) ($schema['properties'] ?? []);

            foreach ($props as $name => $ps)
            {
                if ( ! array_key_exists($name, $verdict) OR $verdict[$name] === NULL)
                {
                    continue;
                }

                if ( ! self::_type_matches($verdict[$name], (string) ($ps['type'] ?? 'string')))
                {
                    $errors[] = $name.' : ожидался '.($ps['type'] ?? 'string');
                }
            }

            if (isset($schema['additionalProperties']) AND $schema['additionalProperties'] === FALSE)
            {
                foreach (array_keys($verdict) as $key)
                {
                    if ( ! array_key_exists($key, $props))
                    {
                        $errors[] = 'лишнее поле '.$key;
                    }
                }
            }
        }
        elseif ($type === 'array')
        {
            if ( ! (isset($schema['items']) AND is_array($schema['items'])))
            {
                return [TRUE, $verdict, []];
            }
        }
        else
        {
            if ( ! self::_type_matches($verdict, $type))
            {
                $errors[] = 'ожидался '.$type;
            }
        }

        return [ ! $errors, $verdict, $errors];
    }

    protected static function _is_assoc($v)
    {
        if ( ! is_array($v) OR $v === [])
        {
            return is_array($v);
        }

        return array_keys($v) !== range(0, count($v) - 1);
    }

    protected static function _type_matches($v, string $ptype): bool
    {
        switch ($ptype)
        {
            case 'string':
                return is_string($v);
            case 'number':
                return is_int($v) OR is_float($v);
            case 'integer':
                return is_int($v);
            case 'boolean':
                return is_bool($v);
            case 'array':
                return is_array($v) AND ! self::_is_assoc($v);
            case 'object':
                return is_array($v) AND self::_is_assoc($v);
            default:
                return TRUE;
        }
    }

    // ------------------------------------------------------------- сценарии

    /** Сохранить/перезаписать сценарий. @return [ok, scene_id, name] | [ok, error] */
    public static function save_scene(string $uid, string $topic, string $name, string $text, array $variants = [], ?string $schema_json = NULL, int $scene_id = 0): array
    {
        self::ensure_tables();

        $topics = self::topics();
        $topic  = (string) $topic;

        if ( ! array_key_exists($topic, $topics))
        {
            return ['ok' => FALSE, 'error' => 'Неизвестная тема. Доступно: '.implode(', ', array_keys($topics)).'.'];
        }

        $name = trim((string) $name);

        if ($name === '')
        {
            $name = $topics[$topic]['label'].' ('.$topic.')';
        }

        $name = mb_substr($name, 0, 140);
        $text = trim((string) $text);

        if ($text === '')
        {
            $text = $topics[$topic]['prompt'];
        }

        if (mb_strlen($text) > 3000)
        {
            return ['ok' => FALSE, 'error' => 'Текст сценария длиннее 3000 символов.'];
        }

        $variants = self::_clean_variants($variants);

        $schema = NULL;

        if ($schema_json !== NULL AND trim((string) $schema_json) !== '')
        {
            $parsed = self::parse_schema((string) $schema_json);

            if ( ! $parsed['ok'])
            {
                return ['ok' => FALSE, 'error' => $parsed['error']];
            }

            $schema = $parsed['schema'];
        }
        elseif ($schema_json === NULL OR trim((string) $schema_json) === '')
        {
            $schema = self::topic_default_schema($topic);
        }

        $db = self::_db();

        if ($scene_id > 0 AND self::scene_by_id($uid, $scene_id) !== NULL)
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_640_scenes SET topic = '.$db->quote($topic).', name = '.$db->quote($name)
                .', rule_text = '.$db->quote($text)
                .', variants = '.self::_jsonb($variants)
                .', schema = '.($schema === NULL ? 'NULL' : self::_jsonb($schema))
                .' WHERE id = '.(int) $scene_id.' AND visitor_uid = '.$db->quote((string) $uid), FALSE);

            return ['ok' => TRUE, 'scene_id' => (int) $scene_id, 'name' => $name];
        }

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_640_scenes (visitor_uid, name, topic, rule_text, variants, schema, created_at)
             VALUES ('.$db->quote((string) $uid).', '.$db->quote($name).', '.$db->quote($topic).', '
            .$db->quote($text).', '.self::_jsonb($variants).', '
            .($schema === NULL ? 'NULL' : self::_jsonb($schema)).', now())
             RETURNING id', FALSE)->current();

        return ['ok' => TRUE, 'scene_id' => (int) $row['id'], 'name' => $name];
    }

    protected static function _clean_variants(array $variants): array
    {
        $out = [];

        foreach ($variants as $v)
        {
            $s = trim((string) $v);

            if ($s !== '' AND count($out) < 12)
            {
                $out[] = mb_substr($s, 0, 200);
            }
        }

        return $out;
    }

    public static function scenes_of($uid, int $limit = 40): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, name, topic, rule_text, variants, created_at
             FROM nj_640_scenes WHERE visitor_uid = '.self::_db()->quote((string) $uid)
            .' ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $out[] = self::_scene_row($r);
        }

        return $out;
    }

    protected static function _scene_row(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'topic'      => (string) $r['topic'],
            'topic_label'=> (string) (self::topics()[(string) $r['topic']]['label'] ?? (string) $r['topic']),
            'rule_text'  => (string) $r['rule_text'],
            'variants'   => json_decode((string) $r['variants'], TRUE) ?: [],
            'created_at' => (string) $r['created_at'],
        ];
    }

    public static function scene_by_id(string $uid, int $scene_id): ?array
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_640_scenes WHERE id = '.(int) $scene_id
            .' AND(visitor_uid = '.self::_db()->quote((string) $uid).' OR visitor_uid = \'\')', FALSE)->current();

        return $row ? self::_scene_row($row) + [
            'schema'     => $row['schema'] === NULL ? NULL : (json_decode((string) $row['schema'], TRUE) ?: NULL),
            'visitor_uid'=> (string) $row['visitor_uid'],
        ] : NULL;
    }

    /** Данные сценария для формы (текст/варианты/схема/тема). */
    public static function scene_form_data(string $uid, int $scene_id): ?array
    {
        $scene = self::scene_by_id($uid, $scene_id);

        if ($scene === NULL)
        {
            return NULL;
        }

        return [
            'scene_id'   => (int) $scene['id'],
            'name'       => (string) $scene['name'],
            'topic'      => (string) $scene['topic'],
            'rule_text'  => (string) $scene['rule_text'],
            'variants'   => (array) ($scene['variants'] ?? []),
            'schema_json'=> self::schema_json_text($scene['schema'] ?? NULL),
        ];
    }

    public static function schema_json_text(?array $schema): string
    {
        if ( ! is_array($schema))
        {
            return '';
        }

        $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $json === FALSE ? '' : $json;
    }

    // --------------------------------------------------------------- камеры

    public static function camera_kinds(): array
    {
        return [
            'snapshot' => 'IP-камера: Snapshot URL / RTSP',
            'onvif'    => 'IP-камера: ONVIF',
            'http'     => 'Объектное хранилище / HTTP кадр',
        ];
    }

    public static function camera_triggers(): array
    {
        return [
            'timer'   => 'По таймеру (опрос каждые N секунд)',
            'webhook' => 'Внешний webhook (датчик/ПЛК)',
        ];
    }

    public static function create_camera(string $uid, array $p): array
    {
        self::ensure_tables();

        $db = self::_db();
        $kind = (string) ($p['kind'] ?? 'snapshot');

        if ( ! array_key_exists($kind, self::camera_kinds()))
        {
            return ['ok' => FALSE, 'error' => 'Неизвестный тип камеры. Доступно: '.implode(', ', array_keys(self::camera_kinds())).'.'];
        }

        $trigger = (string) ($p['trigger'] ?? 'timer');

        if ( ! array_key_exists($trigger, self::camera_triggers()))
        {
            return ['ok' => FALSE, 'error' => 'Неизвестный тип триггера (timer/webhook).'];
        }

        $name = trim((string) ($p['name'] ?? ''));

        if ($name === '')
        {
            return ['ok' => FALSE, 'error' => 'Назовите камеру.'];
        }

        $scene_id = (int) ($p['scene_id'] ?? 0);

        if ($scene_id <= 0 OR ! self::scene_by_id((string) $uid, $scene_id))
        {
            return ['ok' => FALSE, 'error' => 'Привяжите камеру к сохранённому сценарию.'];
        }

        $count = (int) $db->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_640_cameras WHERE visitor_uid = '.$db->quote((string) $uid), FALSE)->current()['c'];

        if ($count >= (int) self::cfg('max_cameras', 12))
        {
            return ['ok' => FALSE, 'error' => 'Лимит камер на аккаунт: '.(int) self::cfg('max_cameras', 12).'.'];
        }

        $config = $p['config'] ?? [];

        if ( ! is_array($config))
        {
            $decoded = json_decode((string) $config, TRUE);
            $config = is_array($decoded) ? $decoded : [];
        }

        $config = self::_camera_config($kind, $config);
        $config = self::_sanitize_creds($config);

        if ($trigger === 'webhook')
        {
            $config['webhook_token'] = bin2hex(random_bytes(16));
        }

        $location = mb_substr(trim((string) ($p['location'] ?? '')), 0, 120);
        $interval = max(5, min(3600, (int) ($p['interval_sec'] ?? 60)));
        $cooldown = max(0, min(86400, (int) ($p['cooldown_sec'] ?? 3600)));
        $hours    = self::_clean_work_hours($p['work_hours'] ?? []);

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_640_cameras (visitor_uid, code, name, kind, location, scene_id, config, trigger, interval_sec, work_hours, cooldown_sec, enabled, next_run_at, created_at)
             VALUES ('.$db->quote((string) $uid).', '.$db->quote('cam'.bin2hex(random_bytes(4))).', '
            .$db->quote(mb_substr($name, 0, 140)).', '.$db->quote($kind).', '.$db->quote($location).', '
            .(int) $scene_id.', '.self::_jsonb($config).', '.$db->quote($trigger).', '.$interval.', '
            .($hours === NULL ? 'NULL' : self::_jsonb($hours)).', '.$cooldown.', TRUE, now(), now())
             RETURNING id', FALSE)->current();

        $wh = $trigger === 'webhook'
            ? URL::site('cameras/webhook/'.$config['webhook_token'], TRUE)
            : NULL;

        return ['ok' => TRUE, 'camera_id' => (int) $row['id'], 'webhook_url' => $wh];
    }

    /** Нормализовать конфиг камеры по типу. */
    protected static function _camera_config(string $kind, array $c): array
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

            case 'http':
                return [
                    'url'      => $pick(['url', 'snapshot_url'], 500),
                    'login'    => $pick(['login', 'user'], 120),
                    'password' => $pick(['password', 'pass'], 200),
                ];

            default:
                return [];
        }
    }

    /** Скрыть пароли в конфиге при отдаче в UI/логе. */
    protected static function _sanitize_creds(array $config): array
    {
        foreach (['password', 'pass'] as $key)
        {
            if (isset($config[$key]) AND is_string($config[$key]) AND $config[$key] !== '')
            {
                $config[$key] = '***';
            }
        }

        if (isset($config['webhook_token']))
        {
            unset($config['webhook_token']);
        }

        return $config;
    }

    /** Часы работы: [start,end,zone] либо NULL (круглосуточно). */
    protected static function _clean_work_hours($hours): ?array
    {
        if ( ! is_array($hours) OR ! $hours)
        {
            return NULL;
        }

        $start = (string) ($hours['start'] ?? '');
        $end   = (string) ($hours['end'] ?? '');

        if ( ! preg_match('/^\d\d:\d\d$/', $start) OR ! preg_match('/^\d\d:\d\d$/', $end))
        {
            return NULL;
        }

        if ($start === $end)
        {
            return NULL;
        }

        return [
            'start' => $start,
            'end'   => $end,
            'zone'  => in_array((string) ($hours['zone'] ?? ''), ['Europe/Moscow', 'Europe/Berlin', 'UTC'], TRUE)
                ? (string) $hours['zone'] : 'Europe/Moscow',
        ];
    }

    public static function cameras_of($uid, int $limit = 50): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, code, name, kind, location, scene_id, trigger, interval_sec, cooldown_sec, enabled, last_run_at, work_hours
             FROM nj_640_cameras WHERE visitor_uid = '.self::_db()->quote((string) $uid)
            .' ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $out[] = self::_camera_row($r);
        }

        return $out;
    }

    protected static function _camera_row(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'code'         => (string) $r['code'],
            'name'         => (string) $r['name'],
            'kind'         => (string) $r['kind'],
            'location'     => (string) $r['location'],
            'scene_id'     => (int) $r['scene_id'],
            'trigger'      => (string) $r['trigger'],
            'interval_sec' => (int) $r['interval_sec'],
            'cooldown_sec' => (int) $r['cooldown_sec'],
            'work_hours'   => $r['work_hours'] === NULL ? NULL : (json_decode((string) $r['work_hours'], TRUE) ?: NULL),
            'enabled'      => in_array($r['enabled'], ['1', 't', 'true', TRUE], TRUE),
            'last_run_at'  => $r['last_run_at'] === NULL ? NULL : (string) $r['last_run_at'],
        ];
    }

    public static function camera_by_id(int $id): ?array
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_640_cameras WHERE id = '.(int) $id, FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        $config = json_decode((string) $row['config'], TRUE) ?: [];

        return self::_camera_row($row) + [
            'visitor_uid' => (string) $row['visitor_uid'],
            'config'      => $config,
        ];
    }

    public static function camera_by_code($uid, string $code, bool $scoped = TRUE): ?array
    {
        self::ensure_tables();

        $db  = self::_db();
        $sql = 'SELECT * FROM nj_640_cameras WHERE code = '.$db->quote($code);

        if ($scoped)
        {
            $sql .= ' AND(visitor_uid = '.$db->quote((string) $uid).' OR visitor_uid = \'\')';
        }

        $row = $db->query(Database::SELECT, $sql.' LIMIT 1', FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        $config = json_decode((string) $row['config'], TRUE) ?: [];

        return self::_camera_row($row) + [
            'visitor_uid' => (string) $row['visitor_uid'],
            'config'      => $config,
        ];
    }

    /** Время сейчас внутри «рабочих часов» камеры (если заданы). */
    public static function camera_within_hours(?array $hours): bool
    {
        if ( ! is_array($hours))
        {
            return TRUE;
        }

        $start = (string) ($hours['start'] ?? '');
        $end   = (string) ($hours['end'] ?? '');

        if ($start === '' OR $end === '')
        {
            return TRUE;
        }

        $zone = new DateTimeZone((string) ($hours['zone'] ?? 'Europe/Moscow'));
        $now  = new DateTime('now', $zone);
        $hh   = $now->format('Hi');

        $a = (int) str_replace(':', '', $start);
        $b = (int) str_replace(':', '', $end);
        $n = (int) $hh;

        // Простой интервал (00:00–23:59); ночные смены (например 22:00–06:00) — «через полночь»
        if ($a < $b)
        {
            return $n >= $a AND $n < $b;
        }

        return $n >= $a OR $n < $b;
    }

    // --------------------------------------------------------------- прогоны

    /** Ручная проверка кадра по сценарию. @return [ok, run_id] | [ok, error] */
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

        // сценарий: из сохранённого или новый из пресета темы
        $scene_id = (int) ($p['scene_id'] ?? 0);
        $scene    = $scene_id > 0 ? self::scene_by_id($uid, $scene_id) : NULL;

        if ($scene === NULL)
        {
            $topic = (string) ($p['topic'] ?? 'discipline');

            if ( ! array_key_exists($topic, self::topics()))
            {
                return ['ok' => FALSE, 'error' => 'Неизвестная тема сценария.'];
            }

            $saved = self::save_scene($uid, $topic,
                (string) ($p['scene_name'] ?? ''),
                (string) ($p['rule_text'] ?? ''),
                (array) ($p['variants'] ?? []),
                (string) ($p['schema_json'] ?? ''));

            if ( ! $saved['ok'])
            {
                return ['ok' => FALSE, 'error' => $saved['error']];
            }

            $scene_id = (int) $saved['scene_id'];
            $scene    = self::scene_by_id($uid, $scene_id);
        }

        if ($scene === NULL)
        {
            return ['ok' => FALSE, 'error' => 'Сценарий не найден.'];
        }

        $schema = $scene['schema'] ?? NULL;

        if (array_key_exists('schema_json', $p) AND trim((string) $p['schema_json']) !== '')
        {
            $parsed = self::parse_schema((string) $p['schema_json']);

            if ( ! $parsed['ok'])
            {
                return ['ok' => FALSE, 'error' => $parsed['error']];
            }

            $schema = $parsed['schema'];
        }

        $enq = self::enqueue($uid, [
            'mode'    => 'manual',
            'topic'   => (string) $scene['topic'],
            'rule'    => (string) $scene['rule_text'],
            'variants'=> (array) ($scene['variants'] ?? []),
            'schema'  => $schema,
            'capture' => $capture,
            'source'  => NULL,
        ], NULL, (int) $scene_id, (string) $scene['topic'], 'manual');

        if ( ! $enq OR empty($enq['job_id']))
        {
            return ['ok' => FALSE, 'error' => 'Очередь задач недоступна — попробуйте позже.'];
        }

        return ['ok' => TRUE, 'run_id' => (int) $enq['run_id'], 'status' => self::STATUS_QUEUED];
    }

    /** Прогон по камере (webhook/таймер/ручной тест). */
    public static function camera_fire(array $p): array
    {
        self::ensure_tables();

        $db  = self::_db();
        $cam = NULL;

        if (isset($p['camera_id']) AND (int) $p['camera_id'] > 0)
        {
            $cam = self::camera_by_id((int) $p['camera_id']);
        }
        elseif (isset($p['code']) AND is_string($p['code']) AND $p['code'] !== '')
        {
            $cam = self::camera_by_code('', (string) $p['code'], FALSE);
        }

        if ( ! $cam)
        {
            return ['ok' => FALSE, 'error' => 'Камера не найдена.'];
        }

        if ( ! $cam['enabled'])
        {
            return ['ok' => FALSE, 'error' => 'Камера выключена.'];
        }

        if ( ! self::camera_within_hours($cam['work_hours']))
        {
            return ['ok' => FALSE, 'error' => 'Сейчас вне рабочих часов камеры — прогон пропущен.'];
        }

        $scene = self::scene_by_id((string) $cam['visitor_uid'], (int) $cam['scene_id']);

        if ( ! $scene)
        {
            return ['ok' => FALSE, 'error' => 'Сценарий камеры не найден.'];
        }

        $capture = self::_capture_payload((array) ($p['capture'] ?? []));

        $source = [
            'code'         => (string) $cam['code'],
            'kind'         => (string) $cam['kind'],
            'config'       => $cam['config'],
            'trigger'      => (string) $cam['trigger'],
            'interval_sec' => (int) $cam['interval_sec'],
        ];

        $enq = self::enqueue((string) $cam['visitor_uid'], [
            'mode'    => 'camera',
            'topic'   => (string) $scene['topic'],
            'rule'    => (string) $scene['rule_text'],
            'variants'=> (array) ($scene['variants'] ?? []),
            'schema'  => $scene['schema'] ?? NULL,
            'capture' => $capture,
            'source'  => $source,
        ], (int) $cam['id'], (int) $cam['scene_id'], (string) $scene['topic'], (string) ($p['trigger'] ?? $cam['trigger']));

        if ( ! $enq OR empty($enq['job_id']))
        {
            return ['ok' => FALSE, 'error' => 'Очередь задач недоступна.'];
        }

        self::touch_camera_run((int) $cam['id']);

        return ['ok' => TRUE, 'run_id' => (int) $enq['run_id'], 'job_id' => (int) $enq['job_id']];
    }

    /** Создать прогон + задачу очереди nj120. */
    protected static function enqueue(string $uid, array $req, ?int $camera_id, int $scene_id, string $topic, string $trigger): array
    {
        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_640_runs (visitor_uid, client_ip, camera_id, scene_id, topic, mode, trigger, status, created_at)
             VALUES ('.$db->quote($uid).', '.$db->quote(self::client_ip()).', '
            .($camera_id === NULL ? 'NULL' : (int) $camera_id).', '.(int) $scene_id.', '
            .$db->quote($topic).', '.$db->quote(isset($req['mode']) ? (string) $req['mode'] : 'manual').', '
            .$db->quote($trigger).', '.$db->quote(self::STATUS_QUEUED).', now())
             RETURNING id', FALSE)->current();

        $run_id = (int) $row['id'];

        $payload = [
            'run_id'  => $run_id,
            'mode'    => $req['mode'] ?? 'manual',
            'topic'   => $req['topic'] ?? $topic,
            'rule'    => (string) ($req['rule'] ?? ''),
            'variants'=> (array) ($req['variants'] ?? []),
            'schema'  => $req['schema'] ?? NULL,
            'capture' => (array) ($req['capture'] ?? []),
            'source'  => $req['source'] ?? NULL,
        ];

        try
        {
            $job_id = Service_Nj120_JobQueue::push(self::CHANNEL_ANALYZE, $payload, [
                'max_attempts' => 2,
                'module'       => 'nj640',
                'timeout_sec'  => 1200,
            ]);

            $db->query(Database::UPDATE,
                'UPDATE nj_640_runs SET job_id = '.(int) $job_id.' WHERE id = '.(int) $run_id, FALSE);

            return ['run_id' => $run_id, 'job_id' => (int) $job_id];
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj640: не удалось поставить задачу: :e', [':e' => $e->getMessage()]);
            $db->query(Database::UPDATE,
                "UPDATE nj_640_runs SET status = 'error', error = 'Очередь задач недоступна', finished_at = now() WHERE id = ".(int) $run_id, FALSE);

            return ['run_id' => $run_id, 'job_id' => 0];
        }
    }

    protected static function _capture_payload(array $items): array
    {
        $out = [];

        foreach ($items as $c)
        {
            if (is_array($c) AND is_scalar($c['b64'] ?? NULL))
            {
                $out[] = ['b64' => (string) $c['b64'], 'media_type' => (string) ($c['media_type'] ?? '')];
            }
            elseif (is_string($c) AND strpos($c, 'data:') === 0)
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

    public static function run_by_id($uid, int $run_id): ?array
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_640_runs WHERE id = '.(int) $run_id
            .' AND(visitor_uid = '.self::_db()->quote((string) $uid).' OR visitor_uid = \'\')', FALSE)->current();

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
            'camera_id'   => $row['camera_id'] === NULL ? NULL : (int) $row['camera_id'],
            'scene_id'    => (int) $row['scene_id'],
            'topic'       => (string) $row['topic'],
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
                "UPDATE nj_640_runs SET status = 'error', error = ".$db->quote(mb_substr((string) $job['error'], 0, 900)).",
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
                    "UPDATE nj_640_runs SET status = 'error', error = 'Воркер не отвечал больше "
                    .max(1, (int) round($queue->silent_stop_sec() / 60))
                    ." мин — анализ остановлен автоматически. Запустите заново',
                     finished_at = now() WHERE id = ".(int) $run['id'], FALSE);
            }
            elseif ($job['claimed_at'] ? ((time() - strtotime((string) $job['claimed_at'])) > $limit) : ((time() - strtotime((string) $job['created_at'])) > $limit))
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_640_runs SET status = 'error', error = 'Задача зависла (воркер недоступен) — попробуйте ещё раз',
                     finished_at = now() WHERE id = ".(int) $run['id'], FALSE);
            }
        }

        $row = $db->query(Database::SELECT, 'SELECT * FROM nj_640_runs WHERE id = '.(int) $run['id'], FALSE)->current();

        return $row ? self::_run_row($row) : $run;
    }

    /**
     * Записать финальный результат (воркер → worker-result). Здесь же —
     * разбор вердикта в событие журнала (dedupe + cooldown) и уведомление.
     */
    public static function apply_result(int $run_id, array $res): bool
    {
        self::ensure_tables();

        $db  = self::_db();
        $run = $db->query(Database::SELECT, 'SELECT * FROM nj_640_runs WHERE id = '.(int) $run_id, FALSE)->current();

        if ( ! $run)
        {
            return FALSE;
        }

        // не перезаписываем уже завершённый прогон (идемпотентность колбэков)
        if (in_array((string) $run['status'], [self::STATUS_DONE, self::STATUS_ERROR], TRUE))
        {
            return TRUE;
        }

        $verdict = (isset($res['verdict']) AND is_array($res['verdict'])) ? $res['verdict'] : NULL;
        $status  = isset($res['status']) ? (string) $res['status'] : (isset($res['ok']) && $res['ok'] ? 'done' : 'error');
        $status  = in_array($status, ['done', 'error', 'queued'], TRUE) ? $status : 'done';

        $db->query(Database::UPDATE,
            'UPDATE nj_640_runs SET '
            .'status = '.$db->quote($status).', '
            .'verdict = '.($verdict === NULL ? 'NULL' : self::_jsonb($verdict)).', '
            .'schema_ok = '.($res['schema_ok'] === NULL ? 'NULL' : ($res['schema_ok'] ? 'TRUE' : 'FALSE')).', '
            .'model = '.self::_q(isset($res['model']) ? mb_substr((string) $res['model'], 0, 160) : NULL).', '
            .'prompt_tokens = '.(int) ($res['prompt_tokens'] ?? 0).', '
            .'completion_tokens = '.(int) ($res['completion_tokens'] ?? 0).', '
            .'duration_ms = '.(int) ($res['duration_ms'] ?? 0).', '
            .'error = '.self::_q(isset($res['error']) ? mb_substr((string) $res['error'], 0, 900) : NULL).', '
            .'finished_at = now() WHERE id = '.(int) $run_id, FALSE);

        // Дожим очереди (образец nj602): прогон записан сайтом — задача nj120
        // становится «Готово», даже если воркер не успел complete() или свипер
        // отказал её за секунды до доставки. Только для status='done'.
        if ($status === self::STATUS_DONE)
        {
            try
            {
                $jrow = $db->query(Database::SELECT,
                    'SELECT job_id FROM nj_640_runs WHERE id = '.(int) $run_id, FALSE)->current();

                if (class_exists('Service_Nj120_JobQueue') AND $jrow AND (int) ($jrow['job_id'] ?? 0) > 0)
                {
                    Service_Nj120_JobQueue::instance()->mark_delivered(
                        (int) $jrow['job_id'],
                        'Результат анализа камеры принят сайтом — задача закрыта из приёмки nj640',
                    );
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj640: связать задачу с готовым прогоном не удалось: :e',
                    [':e' => $e->getMessage()]);
            }
        }

        // Загрузить свежий ряд (status/topic/scene/camera/uid) для журнала событий
        $run = $db->query(Database::SELECT, 'SELECT * FROM nj_640_runs WHERE id = '.(int) $run_id, FALSE)->current();

        if ($run AND $verdict !== NULL AND $status === self::STATUS_DONE)
        {
            $event = self::_event_from_verdict((string) $run['visitor_uid'], (int) $run['camera_id'],
                (int) $run['scene_id'], (int) $run_id, (string) $run['topic'], $verdict, self::_schema_ok_flag($res));

            if ($event !== NULL)
            {
                $cooldown = self::camera_cooldown_for((int) $run['camera_id']);
                $stored   = self::record_event($event, $cooldown, (int) $run['camera_id']);

                // новое событие и уведомления у владельца — шлём колокольчик
                if (($stored['created'] ?? FALSE))
                {
                    self::notify_event($event + ['camera_id' => (int) $run['camera_id']]);
                }
            }
        }

        return TRUE;
    }

    protected static function _schema_ok_flag(array $res)
    {
        if ($res['schema_ok'] === NULL)
        {
            return NULL;
        }

        return (bool) $res['schema_ok'];
    }

    public static function camera_cooldown_for(int $camera_id): int
    {
        $default = (int) self::cfg('cooldown_min_sec', 300);

        if ($camera_id <= 0)
        {
            return $default;
        }

        $row = self::_db()->query(Database::SELECT,
            'SELECT cooldown_sec FROM nj_640_cameras WHERE id = '.(int) $camera_id, FALSE)->current();

        if ( ! $row)
        {
            return $default;
        }

        $v = (int) $row['cooldown_sec'];

        return $v > 0 ? $v : $default;
    }

    /**
     * Превратить вердикт в событие (или ничего). Событие создаётся, только
     * когда сценарий нашёл нарушение/аварию (schema_ok и явно не-null verdict).
     */
    public static function _event_from_verdict(string $uid, int $camera_id, int $scene_id, int $run_id, string $topic, array $verdict, ?bool $schema_ok): ?array
    {
        // схема не прошла (schema_ok === false) => «непонятный» кадр, не событие
        if ($schema_ok === FALSE)
        {
            return NULL;
        }

        $defects   = (array) ($verdict['violations'] ?? []);
        $verdict   = trim(mb_strtolower((string) ($verdict['verdict'] ?? '')));

        $severity = NULL;

        switch ($topic)
        {
            case 'ppe':
            case 'discipline':
                if ($verdict === 'violation')
                {
                    $severity = 'major';
                }

                break;

            case 'equipment':
                if ($verdict === 'idle')
                {
                    $severity = 'major'; // простой оборудования
                }
                elseif ($verdict === 'unclear')
                {
                    $severity = 'info';
                }

                break;
        }

        if ($severity === NULL)
        {
            return NULL;
        }

        $title  = self::_event_title($topic, $verdict, $defects);
        $detail = self::_event_detail($topic, $verdict, $defects);
        $key    = self::event_key($topic, $verdict, $defects);

        return [
            'uid'       => $uid,
            'camera_id' => $camera_id,
            'scene_id'  => $scene_id,
            'run_id'    => $run_id,
            'key'       => $key,
            'severity'  => $severity,
            'title'     => $title,
            'detail'    => $detail,
        ];
    }

    /** Стабильный ключ дедупликации: тема + вердикт + первое нарушение. */
    public static function event_key(string $topic, string $verdict, array $defects = []): string
    {
        $d = '';

        foreach ($defects as $def)
        {
            $s = trim((string) $def);

            if ($s !== '')
            {
                $d = $s;
                break;
            }
        }

        $base = $topic.':'.($verdict !== '' ? $verdict : 'n/a');

        if ($d !== '')
        {
            $base .= ':'.mb_substr($d, 0, 60);
        }

        return mb_substr(mb_strtolower($base), 0, 120);
    }

    protected static function _event_title(string $topic, string $verdict, array $defects): string
    {
        if ($topic === 'ppe')
        {
            $first = self::_first_defect($defects);

            return $first !== '' ? 'СИЗ: нарушение — '.$first : 'СИЗ: зафиксировано нарушение';
        }

        if ($topic === 'equipment')
        {
            return $verdict === 'idle' ? 'Оборудование простаивает' : 'Оборудование: кадр неясен';
        }

        return 'Нарушение трудовой дисциплины';
    }

    protected static function _event_detail(string $topic, string $verdict, array $defects): string
    {
        $parts = [];

        foreach ($defects as $def)
        {
            $s = trim((string) $def);

            if ($s !== '' AND ! in_array($s, $parts, TRUE))
            {
                $parts[] = $s;
            }
        }

        if ($parts)
        {
            return 'На кадре: '.implode('; ', array_slice($parts, 0, 6)).'.';
        }

        $topics = self::topics();
        $label  = (string) ($topics[$topic]['label'] ?? $topic);

        return 'Камера зафиксировала подозрение (тема: '.$label.').';
    }

    protected static function _first_defect(array $defects): string
    {
        foreach ($defects as $def)
        {
            $s = trim((string) $def);

            if ($s !== '')
            {
                return mb_substr($s, 0, 80);
            }
        }

        return '';
    }

    // ------------------------------------------------------ события (журнал)

    /**
     * Записать событие с dedupe/cooldown. Возвращает:
     *   ['created' => TRUE]  — новое событие (и можно слать уведомление);
     *   ['created' => FALSE] — обновлено last_seen_at существующего (тихо).
     */
    public static function record_event(array $event, int $cooldown_sec = 300, int $camera_id = 0): array
    {
        self::ensure_tables();

        $db  = self::_db();
        $uid = (string) ($event['uid'] ?? '');

        $camera_col  = (int) ($event['camera_id'] ?? 0) > 0 ? (int) $event['camera_id'] : $camera_id;
        $windows = max(60, (int) self::cfg('dedupe_window_sec', 600));

        // 1) существующее событие того же ключа, не снятое и открытое
        $existing = $db->query(Database::SELECT,
            'SELECT id, dismissed, last_seen_at, camera_id FROM nj_640_events
             WHERE visitor_uid = '.$db->quote($uid).' AND key = '.$db->quote((string) ($event['key'] ?? ''))
            .' AND camera_id = '.$camera_col.' AND dismissed = FALSE
             ORDER BY id DESC LIMIT 1', FALSE)->current();

        if ($existing)
        {
            $last = $existing['last_seen_at'] === NULL ? 0 : strtotime((string) $existing['last_seen_at']);
            $age  = time() - $last;

            $db->query(Database::UPDATE,
                'UPDATE nj_640_events SET last_seen_at = now(), run_id = '.(int) ($event['run_id'] ?? 0)
                .' WHERE id = '.(int) $existing['id'], FALSE);

            // внутри window — просто «подтвердили», тихо
            if ($age <= $windows)
            {
                return ['created' => FALSE, 'event_id' => (int) $existing['id']];
            }
        }

        // 2) cooldown: похожая тревога той же камеры недавно — молчим
        if ($camera_id > 0)
        {
            $recent = $db->query(Database::SELECT,
                'SELECT id FROM nj_640_events
                 WHERE visitor_uid = '.$db->quote($uid).' AND camera_id = '.(int) $camera_id
                ." AND severity <> 'info' AND created_at > now() - interval '".(int) $cooldown_sec." seconds'
                 LIMIT 1", FALSE)->current();

            if ($recent)
            {
                return ['created' => FALSE, 'event_id' => (int) $recent['id']];
            }
        }

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_640_events (visitor_uid, camera_id, scene_id, run_id, key, severity, title, detail, first_seen_at, last_seen_at, created_at)
             VALUES ('.$db->quote($uid).', '.$camera_col.', '.(int) ($event['scene_id'] ?? 0).', '
            .(int) ($event['run_id'] ?? 0).', '.$db->quote(mb_substr((string) ($event['key'] ?? ''), 0, 120)).', '
            .$db->quote(mb_substr((string) ($event['severity'] ?? 'info'), 0, 12)).', '
            .$db->quote(mb_substr((string) ($event['title'] ?? ''), 0, 160)).', '
            .$db->quote(mb_substr((string) ($event['detail'] ?? ''), 0, 2000)).', now(), now(), now())
             RETURNING id', FALSE)->current();

        return ['created' => TRUE, 'event_id' => (int) $row['id']];
    }

    public static function events_of($uid, int $limit = 40, bool $undismissed_only = FALSE): array
    {
        self::ensure_tables();

        $db  = self::_db();
        $sql = 'SELECT * FROM nj_640_events WHERE visitor_uid = '.$db->quote((string) $uid);

        if ($undismissed_only)
        {
            $sql .= ' AND dismissed = FALSE';
        }

        $sql .= ' ORDER BY id DESC LIMIT '.(int) $limit;

        $rows = $db->query(Database::SELECT, $sql, FALSE)->as_array();
        $out  = [];

        foreach ((array) $rows as $r)
        {
            $out[] = self::_event_row($r);
        }

        return $out;
    }

    protected static function _event_row(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'camera_id'     => (int) $r['camera_id'],
            'scene_id'      => (int) $r['scene_id'],
            'run_id'        => (int) $r['run_id'],
            'key'           => (string) $r['key'],
            'severity'      => (string) $r['severity'],
            'title'         => (string) $r['title'],
            'detail'        => (string) $r['detail'],
            'dismissed'     => in_array($r['dismissed'], ['1', 't', 'true', TRUE], TRUE),
            'first_seen_at' => (string) $r['first_seen_at'],
            'last_seen_at'  => (string) $r['last_seen_at'],
            'created_at'    => (string) $r['created_at'],
        ];
    }

    // -------------------------------------------------------- подтверждения

    public static function confirm_event($uid, int $event_id, string $verdict, string $note = ''): array
    {
        self::ensure_tables();

        $db = self::_db();

        $ev = $db->query(Database::SELECT,
            'SELECT * FROM nj_640_events WHERE id = '.(int) $event_id
            .' AND(visitor_uid = '.$db->quote((string) $uid).' OR visitor_uid = \'\')', FALSE)->current();

        if ( ! $ev)
        {
            return ['ok' => FALSE, 'error' => 'Событие не найдено.'];
        }

        $verdict = in_array($verdict, ['confirmed', 'false_alarm', 'ignored'], TRUE) ? $verdict : 'false_alarm';

        $db->query(Database::INSERT,
            'INSERT INTO nj_640_confirmations (event_id, visitor_uid, verdict, note, created_at)
             VALUES ('.(int) $event_id.', '.$db->quote((string) $uid).', '
            .$db->quote($verdict).', '.$db->quote(mb_substr(trim((string) $note), 0, 400)).', now())', FALSE);

        $db->query(Database::UPDATE,
            'UPDATE nj_640_events SET dismissed = TRUE WHERE id = '.(int) $event_id, FALSE);

        return ['ok' => TRUE, 'event_id' => (int) $event_id, 'verdict' => $verdict];
    }

    /** Статистика подтверждений для сводки (учёт ложных тревог). */
    public static function confirmation_stats($uid): array
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT
                count(*) FILTER (WHERE verdict = \'confirmed\')   AS confirmed,
                count(*) FILTER (WHERE verdict = \'false_alarm\') AS false_alarm,
                count(*) FILTER (WHERE verdict = \'ignored\')     AS ignored
             FROM nj_640_confirmations WHERE visitor_uid = '.self::_db()->quote((string) $uid), FALSE)->current();

        if ( ! $row)
        {
            return ['confirmed' => 0, 'false_alarm' => 0, 'ignored' => 0];
        }

        return [
            'confirmed'   => (int) $row['confirmed'],
            'false_alarm' => (int) $row['false_alarm'],
            'ignored'     => (int) $row['ignored'],
        ];
    }

    // ---------------------------------------------------------------- история

    public static function recent_runs($uid, int $limit = 12): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, camera_id, scene_id, topic, mode, trigger, status, error, verdict, created_at, finished_at
             FROM nj_640_runs WHERE visitor_uid = '.self::_db()->quote((string) $uid)
            .' ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $verdict = $r['verdict'] === NULL ? NULL : (json_decode((string) $r['verdict'], TRUE) ?: NULL);

            $out[] = [
                'id'          => (int) $r['id'],
                'camera_id'   => $r['camera_id'] === NULL ? NULL : (int) $r['camera_id'],
                'scene_id'    => (int) $r['scene_id'],
                'topic'       => (string) $r['topic'],
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

    public static function verdict_summary($verdict): string
    {
        if ( ! is_array($verdict))
        {
            return '';
        }

        // 1) списки нарушений/дефектов — самое информативное
        foreach (['violations', 'defects'] as $list_key)
        {
            if (isset($verdict[$list_key]) AND is_array($verdict[$list_key]) AND $verdict[$list_key])
            {
                $parts = array_filter(array_map(function ($v) {
                    return trim((string) $v);
                }, $verdict[$list_key]));

                if ($parts)
                {
                    return mb_substr('нарушения: '.implode(', ', $parts), 0, 120);
                }
            }
        }

        // 2) состояние оборудования
        if (isset($verdict['equipment_state']) AND is_scalar($verdict['equipment_state']))
        {
            $s = trim((string) $verdict['equipment_state']);

            if ($s !== '')
            {
                return mb_substr('состояние: '.$s, 0, 120);
            }
        }

        // 3) голый вердикт
        if (isset($verdict['verdict']) AND is_scalar($verdict['verdict']))
        {
            $s = trim((string) $verdict['verdict']);

            if ($s !== '')
            {
                return mb_substr($s, 0, 120);
            }
        }

        return '';
    }

    // --------------------------------------------------------------- расписание

    public static function touch_camera_run(int $camera_id)
    {
        self::ensure_tables();

        try
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_640_cameras SET last_run_at = now() WHERE id = '.(int) $camera_id, FALSE);
        }
        catch (Throwable $e)
        {
            // не критично
        }
    }

    public static function due_cameras(int $limit = 100): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, code, interval_sec, work_hours FROM nj_640_cameras
             WHERE trigger = '.self::_db()->quote('timer')."
               AND enabled = TRUE
               AND (next_run_at IS NULL OR next_run_at <= now())
             ORDER BY id ASC LIMIT ".(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $out[] = [
                'id'           => (int) $r['id'],
                'code'         => (string) $r['code'],
                'interval_sec' => (int) $r['interval_sec'],
                'work_hours'   => $r['work_hours'] === NULL ? NULL : (json_decode((string) $r['work_hours'], TRUE) ?: NULL),
            ];
        }

        return $out;
    }

    public static function schedule_camera(int $camera_id, int $interval_sec)
    {
        self::ensure_tables();

        try
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_640_cameras SET next_run_at = now() + interval \''.(int) $interval_sec.' seconds\' WHERE id = '.(int) $camera_id,
                FALSE);
        }
        catch (Throwable $e)
        {
            // не критично
        }
    }

    /** Cron-пиналка nj603: поставить задачи на захват созревшим камерам. */
    public static function cron_poll(): array
    {
        $queued = 0;
        $skipped_hours = 0;

        foreach (self::due_cameras() as $c)
        {
            try
            {
                if ( ! self::camera_within_hours($c['work_hours']))
                {
                    $skipped_hours++;
                    self::schedule_camera((int) $c['id'], (int) $c['interval_sec']);

                    continue;
                }

                $res = self::camera_fire(['camera_id' => (int) $c['id'], 'trigger' => 'timer', 'capture' => []]);

                if (($res['ok'] ?? FALSE))
                {
                    $queued++;
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj640: cron_poll: :e', [':e' => $e->getMessage()]);
            }

            self::schedule_camera((int) $c['id'], (int) $c['interval_sec']);
        }

        return ['queued' => $queued, 'skipped_hours' => $skipped_hours];
    }

    /** Камера по webhook-токену. */
    public static function camera_by_webhook(string $token): ?array
    {
        self::ensure_tables();

        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            "SELECT * FROM nj_640_cameras
             WHERE trigger = 'webhook'
               AND config->>'webhook_token' = ".$db->quote($token)."
             LIMIT 1", FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        $config = json_decode((string) $row['config'], TRUE) ?: [];

        return self::_camera_row($row) + [
            'visitor_uid' => (string) $row['visitor_uid'],
            'config'      => $config,
        ];
    }

    // -------------------------------------------------------------- уведомления

    /**
     * Колокольчик nj597 о новом событии (лимит: не чаще careful_cooldown).
     * Для анонима (uid без u:) — событие в журнале, уведомление пропускается.
     */
    public static function notify_event(array $event, int $max_per_hour = 2): bool
    {
        if ( ! class_exists('Service_Nj597_Notify'))
        {
            return FALSE;
        }

        $uid  = (string) ($event['uid'] ?? '');
        $user = self::notify_user_id($uid);

        if ($user <= 0)
        {
            return FALSE;
        }

        $db = self::_db();

        $recent = $db->query(Database::SELECT,
            'SELECT id FROM nj_597_notifications
             WHERE user_id = '.(int) $user.' AND kind = '.$db->quote(self::NID_KIND)."
             AND created_at > now() - interval '1 hour' LIMIT 1", FALSE)->current();

        if ($recent)
        {
            return FALSE;
        }

        Service_Nj597_Notify::notify($user, self::NID_KIND,
            (string) ($event['title'] ?? 'Событие видеонаблюдения'),
            (string) ($event['detail'] ?? '')."\n\nПриложите кадр и подтвердите или снимите тревогу.",
            '/cameras');

        return TRUE;
    }

    protected static function notify_user_id(string $uid): int
    {
        // зарегистрированный: uid = 'u:<id>'
        if (strpos($uid, 'u:') === 0)
        {
            return (int) substr($uid, 2);
        }

        return Visitor::user_id() ? (int) Visitor::user_id() : 0;
    }

    // -------------------------------------------------------------- схема

    public static function ensure_tables()
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        $done = TRUE;

        if (trim((string) getenv('NJ640_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        DB::query(NULL, <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_640_scenes" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "name"        VARCHAR(140) NOT NULL DEFAULT '',
    "topic"       VARCHAR(20)  NOT NULL DEFAULT 'discipline',
    "rule_text"   TEXT         NOT NULL DEFAULT '',
    "variants"    JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "schema"      JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS "nj_640_cameras" (
    "id"             BIGSERIAL    PRIMARY KEY,
    "visitor_uid"    VARCHAR(40)  NOT NULL DEFAULT '',
    "code"           VARCHAR(40)  NOT NULL DEFAULT '',
    "name"           VARCHAR(140) NOT NULL DEFAULT '',
    "kind"           VARCHAR(16)  NOT NULL DEFAULT 'snapshot',
    "location"       VARCHAR(120) NOT NULL DEFAULT '',
    "scene_id"       BIGINT       NOT NULL DEFAULT 0,
    "config"         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "trigger"        VARCHAR(12)  NOT NULL DEFAULT 'timer',
    "interval_sec"   INTEGER      NOT NULL DEFAULT 60,
    "work_hours"     JSONB        NULL,
    "cooldown_sec"   INTEGER      NOT NULL DEFAULT 300,
    "enabled"        BOOLEAN      NOT NULL DEFAULT TRUE,
    "last_run_at"    TIMESTAMPTZ  NULL,
    "next_run_at"    TIMESTAMPTZ  NULL,
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS "nj_640_runs" (
    "id"                BIGSERIAL   PRIMARY KEY,
    "visitor_uid"       VARCHAR(40) NOT NULL DEFAULT '',
    "client_ip"         VARCHAR(64) NOT NULL DEFAULT '',
    "camera_id"         BIGINT      NULL,
    "scene_id"          BIGINT      NOT NULL DEFAULT 0,
    "topic"             VARCHAR(20) NOT NULL DEFAULT 'discipline',
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
CREATE TABLE IF NOT EXISTS "nj_640_events" (
    "id"             BIGSERIAL    PRIMARY KEY,
    "visitor_uid"    VARCHAR(40)  NOT NULL DEFAULT '',
    "camera_id"      BIGINT       NOT NULL DEFAULT 0,
    "scene_id"       BIGINT       NOT NULL DEFAULT 0,
    "run_id"         BIGINT       NOT NULL DEFAULT 0,
    "key"            VARCHAR(120) NOT NULL DEFAULT '',
    "severity"       VARCHAR(12)  NOT NULL DEFAULT 'info',
    "title"          VARCHAR(160) NOT NULL DEFAULT '',
    "detail"         TEXT         NOT NULL DEFAULT '',
    "dismissed"      BOOLEAN      NOT NULL DEFAULT FALSE,
    "first_seen_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "last_seen_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS "nj_640_confirmations" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "event_id"    BIGINT       NOT NULL DEFAULT 0,
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "verdict"     VARCHAR(24)  NOT NULL DEFAULT 'false_alarm',
    "note"        VARCHAR(400) NOT NULL DEFAULT '',
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
SQL
        )->execute();
    }
}
