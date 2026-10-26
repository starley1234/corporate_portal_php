<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Отзыв 2 Ответ» (NJ-540, бренд «Review 2 Reply»).
 *
 * Публичный сервис: владелец магазина вставляет отзыв клиента — LLM-воркер
 * (LangChain, канал nj540.reply) готовит публичный ответ. Долгая LLM-работа —
 * НЕ в HTTP-запросе: страница ставит задачу в очередь nj120 и показывает
 * прогресс по /nj540/api/status/<code>.
 *
 * Стиль БД — как в Service_Nj520_Poll: Database::instance(), quote(),
 * INSERT..RETURNING через Database::SELECT. Никаких ORM из других модулей.
 *
 * Надёжность: воркер в режиме pg пишет ответ прямо в таблицу; в http-режиме
 * (воркер без БД) результат остаётся в nj_120_jobs.result — тогда API статуса
 * «дозаписывает» его в отзыв (self::reconcile). Оба пути ведут к одному итогу.
 */
class Service_Nj540_Reply {

    const CODE_PREFIX = 'r2a';
    const MAX_REVIEW  = 1500;
    const MIN_REVIEW  = 10;
    const MAX_SHOP    = 120;

    /** Анти-абьюз: LLM стоит денег */
    const RATE_PER_HOUR = 15;
    const RATE_PER_DAY  = 25;

    /** Тоны ответа: ключ → описание для промпта воркера и подпись в UI */
    const TONES = [
        'friendly' => ['label' => 'Дружелюбный',     'hint' => 'тёплый, человечный, дружелюбный'],
        'formal'   => ['label' => 'Официальный',      'hint' => 'официально-деловой, сдержанно-вежливый'],
        'brief'    => ['label' => 'Краткий',          'hint' => 'максимально краткий, 1–2 предложения, по делу'],
        'apology'  => ['label' => 'С извинением',     'hint' => 'с искренним извинением и конкретным шагом решения проблемы'],
        'premium'  => ['label' => 'Премиальный',      'hint' => 'премиальный, персональный, подчёркнуто заботливый'],
    ];

    /** @var Database */
    protected static $_db;

    protected static function _db()
    {
        return self::$_db !== NULL ? self::$_db : self::$_db = Database::instance();
    }

    /**
     * Значение из config/nj540_reply.php (точки — вложенность), иначе дефолт.
     * Константы класса ниже — только последние дефолты, менять настройки
     * нужно в конфиге модуля.
     */
    protected static function _cfg($key, $default = NULL)
    {
        static $cfg = NULL;

        if ($cfg === NULL)
        {
            $cfg = (array) Kohana::$config->load('nj540_reply');
        }

        $value = $cfg;

        foreach (explode('.', $key) as $part)
        {
            if ( ! is_array($value) OR ! array_key_exists($part, $value))
            {
                return $default;
            }

            $value = $value[$part];
        }

        return $value === NULL ? $default : $value;
    }

    /** Тоны ответа (config → тоны из конфига, иначе дефолтные). */
    public static function tones()
    {
        return self::_cfg('tones', self::TONES);
    }

    /** Ограничения ввода: ['min' => .., 'max' => .., 'max_shop' => ..]. */
    public static function limits()
    {
        return [
            'min'      => (int) self::_cfg('min_review', self::MIN_REVIEW),
            'max'      => (int) self::_cfg('max_review', self::MAX_REVIEW),
            'max_shop' => (int) self::_cfg('max_shop', self::MAX_SHOP),
        ];
    }

    /** IP клиента (за nginx берём X-Forwarded-For — см. nj520). */
    public static function client_ip()
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $xff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

        if ($xff !== '' AND ($remote === '' OR filter_var($remote, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE))
        {
            return trim((string) strtok($xff, ','));
        }

        return $remote;
    }

    /**
     * Нормализовать ввод формы: [review, shop, tone] или ['error' => ...].
     */
    public static function normalize($review, $shop, $tone)
    {
        $limits = self::limits();

        $review = trim((string) $review);
        $shop   = mb_substr(trim((string) $shop), 0, $limits['max_shop']);
        $tone   = array_key_exists((string) $tone, self::tones()) ? (string) $tone : 'friendly';

        if (mb_strlen($review) < $limits['min'])
        {
            return ['error' => 'Отзыв слишком короткий — нужно минимум '.$limits['min'].' символов.'];
        }

        if (mb_strlen($review) > $limits['max'])
        {
            return ['error' => 'Отзыв длиннее '.$limits['max'].' символов — сократите его.'];
        }

        return ['review' => $review, 'shop' => $shop, 'tone' => $tone];
    }

    /**
     * Создать отзыв-задачу. Возвращает ['code' => ...] или ['error' => ...].
     */
    public static function create($review, $shop, $tone, $ip)
    {
        $norm = self::normalize($review, $shop, $tone);

        if (isset($norm['error']))
        {
            return $norm;
        }

        if ( ! self::_rate_ok($ip))
        {
            return ['error' => 'Слишком много запросов с этого адреса. Лимит: '
                .(int) self::_cfg('rate.per_hour', self::RATE_PER_HOUR).' в час — попробуйте позже.'];
        }

        $db   = self::_db();
        $code = (string) self::_cfg('code_prefix', self::CODE_PREFIX)
            .bin2hex(random_bytes((int) self::_cfg('code_bytes', 5)));

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_540_replies (code, shop_name, review_text, tone, client_ip)
             VALUES ('.$db->quote($code).', '.self::_quote_nullable($db, $norm['shop']).',
                     '.$db->quote($norm['review']).', '.$db->quote($norm['tone']).', '.$db->quote((string) $ip).')
             RETURNING id, code', FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Не удалось сохранить отзыв — попробуйте ещё раз.'];
        }

        $job_id = self::_push_job((int) $row['id'], $code, $norm);

        if ($job_id === 0)
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_540_replies SET status = 'error', error = 'Очередь задач недоступна'
                 WHERE id = ".(int) $row['id']);
            return ['error' => 'Очередь задач недоступна — попробуйте позже.'];
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_540_replies SET job_id = '.(int) $job_id.' WHERE id = '.(int) $row['id']);

        return ['code' => $code];
    }

    /**
     * Переписать ответ: прошлый вариант — в meta.history, новый запуск воркера.
     */
    public static function regen($code, $ip)
    {
        $row = self::by_code($code);

        if ($row === NULL)
        {
            return ['error' => 'Отзыв не найден'];
        }

        if ( ! self::_rate_ok($ip))
        {
            return ['error' => 'Слишком много запросов с этого адреса — попробуйте позже.'];
        }

        $db  = self::_db();
        $meta = json_decode($row['meta'] ?: '{}', TRUE) ?: [];

        if ( ! empty($row['reply_text']))
        {
            $meta['history'][] = $row['reply_text'];
            $meta['history']   = array_slice($meta['history'], -(int) self::_cfg('history_limit', 5));
        }

        $norm = ['review' => $row['review_text'], 'shop' => (string) $row['shop_name'], 'tone' => $row['tone']];

        $job_id = self::_push_job((int) $row['id'], $row['code'], $norm);

        if ($job_id === 0)
        {
            return ['error' => 'Очередь задач недоступна — попробуйте позже.'];
        }

        $db->query(Database::UPDATE,
            "UPDATE nj_540_replies
             SET status = 'new', reply_text = NULL, analysis = NULL, error = NULL,
                 finished_at = NULL, job_id = ".(int) $job_id.",
                 meta = ".$db->quote(json_encode($meta, JSON_UNESCAPED_UNICODE))."
             WHERE id = ".(int) $row['id']);

        return ['code' => $row['code']];
    }

    /** Отзыв по публичному коду или NULL. */
    public static function by_code($code)
    {
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_540_replies WHERE code = '.$db->quote((string) $code), FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /**
     * Дозаписать результат из очереди (воркер http-режима не имеет доступа к БД).
     * Вызывается лениво из API статуса — идемпотентно.
     */
    public static function reconcile(array $row)
    {
        if ($row['status'] !== 'new' OR empty($row['job_id']))
        {
            return $row;
        }

        $job = Service_Nj120_JobQueue::instance()->get((int) $row['job_id']);

        if ($job === NULL)
        {
            return $row;
        }

        $db = self::_db();

        if ($job['status'] === Service_Nj120_JobQueue::STATUS_DONE)
        {
            $res = $job['result'];
            is_string($res) AND $res = json_decode($res, TRUE);

            if (is_array($res) AND ! empty($res['reply']))
            {
                $meta = [];

                if ( ! empty($res['model']))
                {
                    $meta['model'] = (string) $res['model'];
                }

                if ( ! empty($res['engine']))
                {
                    $meta['engine'] = (string) $res['engine'];
                }

                $db->query(Database::UPDATE,
                    "UPDATE nj_540_replies
                     SET status = 'done', reply_text = ".$db->quote((string) $res['reply']).",
                         analysis = ".self::_quote_json($db, $res['analysis'] ?? NULL).",
                         meta = meta || ".self::_quote_json($db, $meta ?: NULL).",
                         finished_at = now(), error = NULL
                     WHERE id = ".(int) $row['id']." AND status = 'new'");

                // наработка — в общую базу знаний (не критично для ответа)
                try
                {
                    if (class_exists('Service_Nj560_Knowledge'))
                    {
                        Service_Nj560_Knowledge::remember('nj540', 'review_reply',
                            Service_Nj560_Knowledge::make_key((string) ($job['payload']['review'] ?? '')),
                            'Ответ на отзыв («'.($res['tone'] ?? 'friendly').'»)',
                            (string) $res['reply'],
                            [
                                'tone'      => (string) ($job['payload']['tone'] ?? 'friendly'),
                                'sentiment' => (string) ($res['analysis']['sentiment'] ?? ''),
                                'topics'    => $res['analysis']['topics'] ?? [],
                                'code'      => $row['code'],
                            ]);
                    }
                }
                catch (Exception $e)
                {
                    Kohana::$log->add(Log::WARNING, 'nj540: база знаний недоступна: :e', [':e' => $e->getMessage()]);
                }

                return self::by_code($row['code']);
            }
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_FAILED
            AND (int) $job['attempts'] >= (int) $job['max_attempts'])
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_540_replies SET status = 'error', error = "
                    .$db->quote((string) ($job['error'] ?: 'Воркер не смог обработать отзыв')).",
                     finished_at = now()
                 WHERE id = ".(int) $row['id']." AND status = 'new'");

            return self::by_code($row['code']);
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_CANCELLED)
        {
            // Авто-стоп молчащего воркера или остановка вручную: «ждём вечно»
            // превращается в честную ошибку с возможностью повторить.
            $db->query(Database::UPDATE,
                "UPDATE nj_540_replies SET status = 'error', error = "
                    .$db->quote('Воркер не ответил — попробуйте ещё раз (или обратитесь к администратору)').",
                     finished_at = now()
                 WHERE id = ".(int) $row['id']." AND status = 'new'");

            return self::by_code($row['code']);
        }

        return $row;
    }

    /** JSON для API статуса. */
    public static function status_payload(array $row)
    {
        return [
            'ok'          => TRUE,
            'code'        => $row['code'],
            'status'      => $row['status'],
            'reply'       => $row['reply_text'],
            'analysis'    => $row['analysis'] ? json_decode((string) $row['analysis'], TRUE) : NULL,
            'error'       => $row['error'],
            'meta'        => $row['meta'] ? json_decode((string) $row['meta'], TRUE) : NULL,
            'created_at'  => $row['created_at'],
            'finished_at' => $row['finished_at'],
        ];
    }

    // ---------------------------------------------------------------------

    protected static function _push_job($reply_id, $code, array $norm)
    {
        return Service_Nj120_JobQueue::push((string) self::_cfg('job.channel', 'nj540.reply'), [
            'reply_id' => (int) $reply_id,
            'code'     => (string) $code,
            'review'   => (string) $norm['review'],
            'shop'     => (string) $norm['shop'],
            'tone'     => (string) $norm['tone'],
        ], [
            'visitor_id'   => Visitor::uid(),
            'module'       => 'nj540',
            'max_attempts' => (int) self::_cfg('job.max_attempts', 2),
            'timeout_sec'  => (int) self::_cfg('job.timeout_sec', 180),
        ]);
    }

    /** Не превышен ли лимит запросов с этого IP. */
    protected static function _rate_ok($ip)
    {
        $db  = self::_db();
        $cnt = $db->query(Database::SELECT,
            'SELECT
                COUNT(*) FILTER (WHERE created_at > now() - interval \'1 hour\') AS h,
                COUNT(*) FILTER (WHERE created_at > now() - interval \'1 day\')  AS d
             FROM nj_540_replies WHERE client_ip = '.$db->quote((string) $ip), FALSE)->current();

        return $cnt
            AND (int) $cnt['h'] < (int) self::_cfg('rate.per_hour', self::RATE_PER_HOUR)
            AND (int) $cnt['d'] < (int) self::_cfg('rate.per_day', self::RATE_PER_DAY);
    }

    protected static function _quote_nullable(Database $db, $value)
    {
        return ($value === '' OR $value === NULL) ? 'NULL' : $db->quote((string) $value);
    }

    /** JSONB-литерал или NULL (для конкатенации в SQL). */
    protected static function _quote_json(Database $db, $value)
    {
        return $value === NULL ? 'NULL' : $db->quote(json_encode($value, JSON_UNESCAPED_UNICODE)).'::jsonb';
    }
}
