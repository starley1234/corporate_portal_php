<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ИИ-разбор входящих.
 *
 * Границы модуля:
 *  - читает текст через публичный метод prx390 и карточку через prx391;
 *  - пишет только свои прогоны и предложения;
 *  - в карточку попадает лишь через `apply_analysis()` prx391 после «Применить».
 *
 * Модель обязана быть локальной (`allowed_providers`). Проверка выполняется
 * ДО постановки задачи в очередь.
 */
class Service_Prx392_Analysis
{
    protected static $_config;

    public static function config($key = NULL, $default = NULL)
    {
        if (self::$_config === NULL)
        {
            self::$_config = Kohana::$config->load('prx392_analysis')->as_array();
        }

        if ($key === NULL)
        {
            return self::$_config;
        }

        return array_key_exists($key, self::$_config) ? self::$_config[$key] : $default;
    }

    public static function profiles()
    {
        return self::config('profiles', []);
    }

    public static function profile($code)
    {
        $all = self::profiles();

        return $all[$code] ?? NULL;
    }

    /** Проверка allowlist до очереди. Возвращает провайдера. */
    public static function provider_for_model($model)
    {
        $model = trim((string) $model);
        $pos = strpos($model, '::');
        $provider = $pos === FALSE ? '' : substr($model, 0, $pos);
        $allowed = self::config('allowed_providers', ['local']);

        if ($provider === '' OR ! in_array($provider, $allowed, TRUE))
        {
            throw new RuntimeException('Провайдер модели "'.$provider.'" не разрешён для документов (только '.implode(', ', $allowed).')');
        }

        return $provider;
    }

    // ------------------------------------------------------------------
    // Постановка в очередь
    // ------------------------------------------------------------------

    public static function queue($document_id, $message_id, array $actor = [], $profile_code = 'classify_extract_v1')
    {
        $document_id = (int) $document_id;
        $message_id = (int) $message_id;
        $profile = self::profile($profile_code);
        if ( ! $profile)
        {
            throw new InvalidArgumentException('Профиль анализа не найден: '.$profile_code);
        }

        $document = Service_Prx391_Documents::document($document_id, self::_system_actor($actor));
        if ( ! $document)
        {
            throw new InvalidArgumentException('Документ не найден');
        }

        $text = Service_Prx390_Intake::text_for_message($message_id);
        if ( ! $text OR (int) $text['chars'] < 40)
        {
            throw new RuntimeException('У документа нет распознанного текста — сначала дождитесь doc.normalize');
        }

        self::provider_for_model($profile['model']);
        $input_hash = md5($text['text']."\n".$profile_code.'|'.$profile['model'].'|'.$profile['prompt_version'].'|'.$profile['schema_version']);

        $existing = self::_row("
            SELECT * FROM prx_392_runs
            WHERE document_id = ".$document_id." AND profile = ".self::_q($profile_code)."
              AND input_hash = ".self::_q($input_hash)."
              AND status IN ('queued', 'running', 'ready')
            ORDER BY id DESC LIMIT 1
        ");
        if ($existing)
        {
            return (int) $existing['id'];
        }

        $run = self::_row("
            INSERT INTO prx_392_runs
                (document_id, message_id, profile, model, prompt_version, schema_version, input_hash, status, created_by)
            VALUES (
                ".$document_id.", ".$message_id.", ".self::_q($profile_code).", ".self::_q($profile['model']).",
                ".self::_q($profile['prompt_version']).", ".self::_q($profile['schema_version']).",
                ".self::_q($input_hash).", 'queued', ".self::_int_or_null($actor['user_id'] ?? NULL)."
            )
            RETURNING id
        ");

        $run_id = (int) $run['id'];

        try
        {
            $job_id = Service_Prx120_JobQueue::push('doc.analyze', [
                'run_id' => $run_id,
                'document_id' => $document_id,
                'profile' => $profile_code,
            ], [
                'module' => 'prx392',
                'priority' => 40,
                'max_attempts' => 2,
                'timeout_sec' => 900,
                'created_by' => (int) ($actor['user_id'] ?? 0),
            ]);
        }
        catch (Exception $e)
        {
            self::_exec("UPDATE prx_392_runs SET status = 'failed', error = ".self::_q($e->getMessage()).", finished_at = now() WHERE id = ".$run_id);
            throw $e;
        }

        self::_exec("UPDATE prx_392_runs SET job_id = ".(int) $job_id." WHERE id = ".$run_id);

        return $run_id;
    }

    public static function queue_for_document($document_id, array $actor = [])
    {
        $document = Service_Prx391_Documents::document($document_id, self::_system_actor($actor));
        if ( ! $document)
        {
            throw new InvalidArgumentException('Документ не найден');
        }
        if (empty($document['source_message_id']))
        {
            throw new RuntimeException('У документа нет конверта prx390');
        }

        return self::queue($document_id, (int) $document['source_message_id'], $actor);
    }

    // ------------------------------------------------------------------
    // Контракт воркера
    // ------------------------------------------------------------------

    public static function input_for_worker($run_id)
    {
        $run = self::run($run_id);
        if ( ! $run)
        {
            throw new InvalidArgumentException('Прогон не найден');
        }
        if (in_array($run['status'], ['ready', 'failed'], TRUE))
        {
            return ['done' => TRUE, 'status' => $run['status']];
        }

        $profile = self::profile($run['profile']);
        if ( ! $profile)
        {
            throw new RuntimeException('Профиль анализа не найден');
        }
        self::provider_for_model($run['model']);

        $document = Service_Prx391_Documents::document((int) $run['document_id'], self::_system_actor());
        if ( ! $document)
        {
            throw new RuntimeException('Документ не найден');
        }

        $text_row = Service_Prx390_Intake::text_for_message((int) $run['message_id']);
        if ( ! $text_row)
        {
            throw new RuntimeException('Текст документа не найден');
        }

        $text = (string) $text_row['text'];
        $max = (int) self::config('max_input_chars', 60000);
        if (mb_strlen($text) > $max)
        {
            $text = mb_substr($text, 0, $max)."\n\n[... текст обрезан ...]";
        }

        $types = [];
        foreach (array_slice(Service_Prx391_Documents::types(), 0, (int) self::config('max_types_in_prompt', 30)) as $type)
        {
            $types[] = $type['code'].' — '.$type['title'];
        }

        $meta = [
            'reg_no' => $document['reg_no'],
            'subject' => $document['subject'],
            'received_at' => $document['received_at'],
            'sender' => $document['sender_name'] ?: $document['correspondent_name'],
            'type' => $document['type_code'] ?: 'other',
            'attachments' => isset($document['message']['files']) ? count($document['message']['files']) : 0,
        ];

        $user = strtr($profile['user'], [
            '{{types}}' => implode("\n", $types),
            '{{meta}}' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            '{{text}}' => $text,
        ]);

        return [
            'done' => FALSE,
            'run_id' => (int) $run['id'],
            'profile' => $run['profile'],
            'model' => $run['model'],
            'temperature' => (float) $profile['temperature'],
            'max_tokens' => (int) $profile['max_tokens'],
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $profile['system']],
                ['role' => 'user', 'content' => $user],
            ],
            'schema_version' => $profile['schema_version'],
        ];
    }

    public static function store_result($run_id, array $data)
    {
        $run = self::run($run_id);
        if ( ! $run)
        {
            throw new InvalidArgumentException('Прогон не найден');
        }
        if ($run['status'] === 'ready')
        {
            return self::latest_suggestion((int) $run['document_id']);
        }

        $raw = $data['result'] ?? NULL;
        if (is_string($raw))
        {
            $raw = json_decode($raw, TRUE);
        }
        if ( ! is_array($raw))
        {
            self::_fail_run($run_id, 'Модель вернула не JSON');

            throw new InvalidArgumentException('Пустой или невалидный ответ модели');
        }

        try
        {
            $normalized = self::normalize_result($raw);
        }
        catch (Exception $e)
        {
            self::_fail_run($run_id, $e->getMessage());

            throw $e;
        }

        $suggestion = self::_row("
            INSERT INTO prx_392_suggestions (run_id, document_id, payload, confidence, status)
            VALUES (
                ".(int) $run_id.", ".(int) $run['document_id'].",
                ".self::_q(json_encode($normalized, JSON_UNESCAPED_UNICODE))."::jsonb,
                ".self::_num_or_null($normalized['type_confidence']).", 'proposed'
            )
            ON CONFLICT (run_id) DO UPDATE SET payload = EXCLUDED.payload, confidence = EXCLUDED.confidence
            RETURNING id
        ");

        self::_exec("
            UPDATE prx_392_runs
            SET status = 'ready', tokens = ".self::_int_or_null($data['tokens'] ?? NULL).",
                duration_ms = ".self::_int_or_null($data['duration_ms'] ?? NULL).",
                finished_at = now(), error = NULL
            WHERE id = ".(int) $run_id."
        ");

        if ( ! empty($run['job_id']) AND class_exists('Service_Prx120_JobQueue'))
        {
            try
            {
                Service_Prx120_JobQueue::instance()->mark_delivered((int) $run['job_id'], 'analysis ready');
            }
            catch (Exception $e)
            {
                Log::instance()->add(Log::WARNING, 'prx392: mark_delivered не удался: :e', [':e' => $e->getMessage()]);
            }
        }

        $document = Service_Prx391_Documents::document((int) $run['document_id'], self::_system_actor());
        Event::fire('prx392.analysis.ready', [
            'document_id' => (int) $run['document_id'],
            'run_id' => (int) $run_id,
            'suggestion_id' => (int) $suggestion['id'],
            'reg_no' => $document['reg_no'] ?? '',
            'confidence' => $normalized['type_confidence'],
            'href' => '/documents/'.(int) $run['document_id'],
        ]);

        return self::suggestion((int) $suggestion['id']);
    }

    /**
     * Нормализация ответа модели. Публичная — для стенда и тестов.
     */
    public static function normalize_result(array $raw)
    {
        $types = [];
        foreach (Service_Prx391_Documents::types() as $type)
        {
            $types[$type['code']] = TRUE;
        }

        $type = trim((string) ($raw['type'] ?? ''));
        if ($type === '' OR ! isset($types[$type]))
        {
            $type = 'other';
        }

        $confidence = self::_confidence($raw['type_confidence'] ?? 0);
        if ($type === 'other')
        {
            $confidence = min($confidence, 0.500);
        }

        $allowed = [
            'correspondent_name' => 255,
            'sender_inn' => 12,
            'outbound_no' => 120,
            'outbound_date' => 10,
            'contract_no' => 120,
            'amount' => 32,
            'deadline_hint_days' => 5,
            'deadline_hint_unit' => 12,
        ];

        $fields = [];
        $raw_fields = isset($raw['fields']) && is_array($raw['fields']) ? $raw['fields'] : [];
        foreach ($allowed as $name => $max_len)
        {
            $item = $raw_fields[$name] ?? NULL;
            $value = '';
            $quote = '';
            $conf = 0.0;

            if (is_array($item))
            {
                $value = $item['value'] ?? '';
                $quote = $item['quote'] ?? '';
                $conf = self::_confidence($item['confidence'] ?? 0);
            }
            elseif (is_scalar($item))
            {
                $value = $item;
            }

            if (is_array($value))
            {
                $value = '';
            }
            $value = trim(strip_tags((string) $value));
            $quote = trim(strip_tags((string) $quote));

            if ($name === 'sender_inn')
            {
                $value = preg_replace('/\D+/', '', $value);
                if ($value !== '' AND ! in_array(strlen($value), [10, 12], TRUE))
                {
                    $conf = 0.0;
                }
            }
            elseif ($name === 'outbound_date')
            {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
                if ( ! $dt OR $dt->format('Y-m-d') !== $value)
                {
                    $value = '';
                    $conf = 0.0;
                }
            }
            elseif ($name === 'amount')
            {
                $value = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $value);
                if ($value !== '' AND ! is_numeric($value))
                {
                    $value = '';
                    $conf = 0.0;
                }
            }
            elseif ($name === 'deadline_hint_days')
            {
                $value = preg_replace('/\D+/', '', $value);
                if ($value !== '')
                {
                    $value = (string) min(365, max(1, (int) $value));
                }
            }
            elseif ($name === 'deadline_hint_unit')
            {
                $value = strtolower($value);
                if ( ! in_array($value, ['calendar', 'business', ''], TRUE))
                {
                    $value = '';
                }
            }

            $value = mb_substr($value, 0, $max_len);
            $quote = mb_substr($quote, 0, 500);
            $fields[$name] = ['value' => $value, 'quote' => $quote, 'confidence' => $conf];
        }

        $summary = trim(strip_tags((string) ($raw['summary'] ?? '')));
        $summary = mb_substr($summary, 0, 2000);

        $risk = isset($raw['risk']) && is_array($raw['risk']) ? $raw['risk'] : [];
        $level = (string) ($risk['level'] ?? 'low');
        if ( ! in_array($level, ['low', 'medium', 'high'], TRUE))
        {
            $level = 'low';
        }
        $reasons = [];
        foreach ((array) ($risk['reasons'] ?? []) as $reason)
        {
            $reason = trim(strip_tags((string) $reason));
            if ($reason !== '')
            {
                $reasons[] = mb_substr($reason, 0, 300);
            }
            if (count($reasons) >= 5)
            {
                break;
            }
        }

        $findings = [];
        foreach ((array) ($raw['findings'] ?? []) as $finding)
        {
            if ( ! is_array($finding))
            {
                continue;
            }
            $kind = preg_replace('/[^a-z_]/', '', strtolower((string) ($finding['kind'] ?? 'note')));
            $text = trim(strip_tags((string) ($finding['text'] ?? '')));
            if ($text !== '')
            {
                $findings[] = ['kind' => $kind ?: 'note', 'text' => mb_substr($text, 0, 400)];
            }
            if (count($findings) >= 10)
            {
                break;
            }
        }

        $filled = 0;
        foreach ($fields as $f)
        {
            $filled += $f['value'] === '' ? 0 : 1;
        }
        if ($summary === '' AND $filled === 0)
        {
            throw new InvalidArgumentException('В ответе модели нет ни полей, ни краткого содержания');
        }

        return [
            'type' => $type,
            'type_confidence' => $confidence,
            'fields' => $fields,
            'summary' => $summary,
            'risk' => ['level' => $level, 'reasons' => $reasons],
            'findings' => $findings,
            'schema_version' => 'doc_card_v1',
        ];
    }

    // ------------------------------------------------------------------
    // Проверка человеком
    // ------------------------------------------------------------------

    public static function latest_for_document($document_id)
    {
        $row = self::_row("
            SELECT s.*, r.profile, r.model, r.prompt_version, r.tokens, r.duration_ms, r.status AS run_status,
                   r.created_at AS run_created_at
            FROM prx_392_suggestions s
            JOIN prx_392_runs r ON r.id = s.run_id
            WHERE s.document_id = ".(int) $document_id."
            ORDER BY s.created_at DESC, s.id DESC LIMIT 1
        ");

        return $row ?: NULL;
    }

    public static function latest_suggestion($document_id)
    {
        $row = self::latest_for_document($document_id);
        if ( ! $row)
        {
            return NULL;
        }
        $row['payload'] = json_decode($row['payload'], TRUE);

        return $row;
    }

    public static function proposals($limit = 50)
    {
        return self::_all("
            SELECT s.*, r.profile, r.model, r.tokens, r.duration_ms, r.finished_at,
                   d.reg_no, d.subject
            FROM prx_392_suggestions s
            JOIN prx_392_runs r ON r.id = s.run_id
            JOIN prx_391_documents d ON d.id = s.document_id
            WHERE s.status IN ('proposed', 'edited')
            ORDER BY s.created_at DESC LIMIT ".(int) $limit."
        ");
    }

    public static function runs_for_document($document_id, $limit = 10)
    {
        return self::_all("
            SELECT r.*, d.reg_no, d.subject
            FROM prx_392_runs r
            LEFT JOIN prx_391_documents d ON d.id = r.document_id
            WHERE r.document_id = ".(int) $document_id."
            ORDER BY r.created_at DESC LIMIT ".(int) $limit."
        ");
    }

    public static function runs($limit = 50)
    {
        return self::_all("
            SELECT r.*, d.reg_no, d.subject
            FROM prx_392_runs r
            LEFT JOIN prx_391_documents d ON d.id = r.document_id
            ORDER BY r.created_at DESC LIMIT ".(int) $limit."
        ");
    }

    public static function run($id)
    {
        return self::_row("SELECT * FROM prx_392_runs WHERE id = ".(int) $id);
    }

    public static function suggestion($id)
    {
        $row = self::_row("
            SELECT s.*, r.profile, r.model, r.prompt_version, r.tokens, r.duration_ms, r.status AS run_status
            FROM prx_392_suggestions s JOIN prx_392_runs r ON r.id = s.run_id
            WHERE s.id = ".(int) $id."
        ");
        if ( ! $row)
        {
            return NULL;
        }
        $row['payload'] = json_decode($row['payload'], TRUE);

        return $row;
    }

    public static function accept($suggestion_id, array $overrides = [], array $actor = [])
    {
        $suggestion = self::suggestion($suggestion_id);
        if ( ! $suggestion)
        {
            throw new InvalidArgumentException('Предложение не найдено');
        }
        if ( ! in_array($suggestion['status'], ['proposed', 'edited'], TRUE))
        {
            throw new RuntimeException('Предложение уже обработано');
        }

        $payload = $suggestion['payload'];
        if ( ! empty($overrides['type']))
        {
            $payload['type'] = (string) $overrides['type'];
        }
        if (isset($overrides['summary']))
        {
            $payload['summary'] = (string) $overrides['summary'];
        }
        foreach ((array) ($overrides['fields'] ?? []) as $key => $value)
        {
            if ( ! isset($payload['fields'][$key]) OR ! is_array($payload['fields'][$key]))
            {
                $payload['fields'][$key] = ['value' => '', 'quote' => '', 'confidence' => 0];
            }
            $payload['fields'][$key]['value'] = (string) $value;
        }

        $result = Service_Prx391_Documents::apply_analysis((int) $suggestion['document_id'], $payload, (int) $suggestion_id, $actor, ! empty($overrides['overwrite']));

        self::_exec("
            UPDATE prx_392_suggestions
            SET status = ".self::_q(empty($overrides) ? 'accepted' : 'edited').",
                reviewed_by = ".self::_int_or_null($actor['user_id'] ?? NULL).",
                reviewed_at = now()
            WHERE id = ".(int) $suggestion_id."
        ");

        return $result;
    }

    public static function reject($suggestion_id, array $actor = [], $note = '')
    {
        $suggestion = self::suggestion($suggestion_id);
        if ( ! $suggestion)
        {
            throw new InvalidArgumentException('Предложение не найдено');
        }

        self::_exec("
            UPDATE prx_392_suggestions
            SET status = 'rejected', reviewed_by = ".self::_int_or_null($actor['user_id'] ?? NULL).", reviewed_at = now()
            WHERE id = ".(int) $suggestion_id."
        ");

        return TRUE;
    }

    public static function stats()
    {
        $row = self::_row("
            SELECT
                (SELECT count(*) FROM prx_392_runs WHERE created_at::date = current_date) AS today,
                (SELECT count(*) FROM prx_392_runs WHERE status IN ('queued', 'running')) AS active,
                (SELECT count(*) FROM prx_392_runs WHERE status = 'ready') AS ready,
                (SELECT count(*) FROM prx_392_runs WHERE status = 'failed') AS failed,
                (SELECT count(*) FROM prx_392_suggestions WHERE status = 'proposed') AS proposed,
                (SELECT count(*) FROM prx_392_suggestions WHERE status IN ('accepted', 'edited')) AS accepted,
                (SELECT avg(confidence) FROM prx_392_suggestions WHERE created_at > now() - interval '30 days') AS avg_confidence
        ");

        foreach ($row as $k => $v)
        {
            $row[$k] = $k === 'avg_confidence' ? ($v === NULL ? NULL : round((float) $v, 3)) : (int) $v;
        }

        return $row;
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    protected static function _fail_run($run_id, $error)
    {
        self::_exec("
            UPDATE prx_392_runs SET status = 'failed', error = ".self::_q($error).", finished_at = now()
            WHERE id = ".(int) $run_id."
        ");
    }

    protected static function _confidence($value)
    {
        $value = (float) $value;
        if ($value < 0)
        {
            return 0.0;
        }
        if ($value > 1)
        {
            return 1.0;
        }

        return round($value, 3);
    }

    protected static function _system_actor(array $actor = [])
    {
        return $actor ? $actor : ['user_id' => 0, 'roles' => ['system'], 'is_system' => TRUE];
    }

    // ------------------------------------------------------------------
    // Мини-слой БД
    // ------------------------------------------------------------------

    protected static function _db()
    {
        return Database::instance();
    }

    protected static function _q($value)
    {
        return self::_db()->quote((string) $value);
    }

    protected static function _int_or_null($value)
    {
        return ($value === NULL OR $value === '') ? 'NULL' : (string) (int) $value;
    }

    protected static function _num_or_null($value)
    {
        return ($value === NULL OR $value === '') ? 'NULL' : (string) (float) $value;
    }

    protected static function _row($sql)
    {
        $res = self::_db()->query(Database::SELECT, $sql, FALSE);
        $row = $res->current();

        return $row ?: NULL;
    }

    protected static function _all($sql)
    {
        $out = [];
        foreach (self::_db()->query(Database::SELECT, $sql, FALSE) as $row)
        {
            $out[] = $row;
        }

        return $out;
    }

    protected static function _exec($sql)
    {
        return self::_db()->query(Database::UPDATE, $sql, FALSE);
    }
}
