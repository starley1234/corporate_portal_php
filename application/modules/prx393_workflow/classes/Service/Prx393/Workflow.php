<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты и сроки.
 *
 * Правила-first: сначала ищем правило по типу/домену/ИНН/ключевым словам,
 * затем считаем срок по производственному календарю. ИИ здесь не участвует.
 * Если модель ошиблась с типом, правило просто не сработает — останется
 * безопасный маршрут «канцелярия».
 */
class Service_Prx393_Workflow
{
    protected static $_config;

    public static function config($key = NULL, $default = NULL)
    {
        if (self::$_config === NULL)
        {
            self::$_config = Kohana::$config->load('prx393_workflow')->as_array();
        }

        if ($key === NULL)
        {
            return self::$_config;
        }

        return array_key_exists($key, self::$_config) ? self::$_config[$key] : $default;
    }

    // ------------------------------------------------------------------
    // Маршрутизация
    // ------------------------------------------------------------------

    public static function route($document_id, array $actor = [])
    {
        $document_id = (int) $document_id;
        $document = Service_Prx391_Documents::document($document_id, self::_system_actor($actor));
        if ( ! $document)
        {
            throw new InvalidArgumentException('Документ не найден');
        }

        if (in_array($document['status'], ['closed', 'archived'], TRUE))
        {
            self::_exec("UPDATE prx_393_deadlines SET status = 'met', updated_at = now() WHERE document_id = ".$document_id." AND status = 'open'");

            return self::deadline_for($document_id);
        }

        $message = $document['message'] ?? NULL;
        $domain = '';
        if ( ! empty($message['sender_email']))
        {
            $domain = strtolower(substr(strrchr($message['sender_email'], '@') ?: '', 1));
        }
        elseif ( ! empty($document['sender_email']))
        {
            $domain = strtolower(substr(strrchr($document['sender_email'], '@') ?: '', 1));
        }

        $type_code = (string) ($document['type_code'] ?? '');
        $haystack = mb_strtolower($document['subject'].' '.($document['summary'] ?? ''), 'UTF-8');
        $inn = (string) ($document['sender_inn'] ?: ($document['correspondent_inn'] ?? ''));

        $matched = self::match_rule($type_code, $domain, $inn, $haystack);
        $matched_rule = $matched;

        $department = $matched ? (string) ($matched['assign_department'] ?: 'Канцелярия') : 'Канцелярия';
        $role = $matched ? (string) ($matched['assign_role'] ?: 'docflow_clerk') : 'docflow_clerk';
        $priority = $matched ? (string) $matched['priority_set'] : (string) ($document['priority'] ?: 'normal');
        $sla_hours = $matched && $matched['sla_hours'] !== NULL
            ? (int) $matched['sla_hours']
            : (int) self::config('default_sla_hours', 120);
        $business_days = $matched ? ! empty($matched['business_days']) : (bool) self::config('default_business_days', TRUE);

        $start = $document['received_at'] ?: date('Y-m-d H:i:s');
        $source = $matched ? 'rule' : 'default';
        $note = $matched ? ('Правило: '.$matched['title']) : 'Правило не найдено — очередь канцелярии';

        // Явная подсказка срока из письма важнее правила, но не отменяет
        // маршрут: срок и исполнитель считаются отдельно.
        if ( ! empty($document['deadline_hint_days']) AND (int) $document['deadline_hint_days'] > 0)
        {
            $due = self::add_days(
                $start,
                (int) $document['deadline_hint_days'],
                ($document['deadline_hint_unit'] ?? 'calendar') === 'business',
                self::holidays()
            );
            $source = 'hint';
            $note .= '; срок из текста письма';
        }
        elseif ($business_days AND $sla_hours >= 72)
        {
            $due = self::add_days($start, (int) ceil($sla_hours / 24), TRUE, self::holidays());
        }
        else
        {
            $due = date('Y-m-d H:i:s', strtotime($start.' +'.$sla_hours.' hours'));
        }

        self::_exec("
            INSERT INTO prx_393_assignments
                (document_id, reg_no, subject, department, role_code, rule_id, priority, status, note)
            VALUES (
                ".$document_id.", ".self::_q($document['reg_no']).", ".self::_q($document['subject']).",
                ".self::_q($department).", ".self::_q($role).", ".self::_int_or_null($matched['id'] ?? NULL).",
                ".self::_q($priority).", 'assigned', ".self::_q($note)."
            )
            ON CONFLICT (document_id) DO UPDATE SET
                reg_no = EXCLUDED.reg_no, subject = EXCLUDED.subject,
                department = EXCLUDED.department, role_code = EXCLUDED.role_code,
                rule_id = EXCLUDED.rule_id, priority = EXCLUDED.priority,
                note = EXCLUDED.note, updated_at = now()
        ");

        self::_exec("
            INSERT INTO prx_393_deadlines
                (document_id, reg_no, subject, department, kind, source, due_at, started_at, status, business_days, note)
            VALUES (
                ".$document_id.", ".self::_q($document['reg_no']).", ".self::_q($document['subject']).",
                ".self::_q($department).", 'sla', ".self::_q($source).", ".self::_q($due).",
                ".self::_q($start).", 'open', ".($business_days ? 'true' : 'false').", ".self::_q($note)."
            )
            ON CONFLICT (document_id) DO UPDATE SET
                reg_no = EXCLUDED.reg_no, subject = EXCLUDED.subject,
                department = EXCLUDED.department, source = EXCLUDED.source,
                due_at = EXCLUDED.due_at, started_at = EXCLUDED.started_at,
                // Документ снова в работе — и срок снова открыт. Иначе после
                // reopen он навсегда оставался 'met' и пропадал из контроля.
                status = 'open',
                business_days = EXCLUDED.business_days, note = EXCLUDED.note, updated_at = now()
        ");

        Service_Prx391_Documents::set_sla_snapshot($document_id, [
            'deadline_at' => $due,
            'deadline_source' => $source,
            'assignee_role' => $role,
            'department' => $department,
            'priority' => $priority,
        ]);

        Event::fire('prx393.document.assigned', [
            'document_id' => $document_id,
            'reg_no' => $document['reg_no'],
            'department' => $department,
            'role' => $role,
            'due_at' => $due,
            'rule' => $matched['code'] ?? NULL,
        ]);

        return self::deadline_for($document_id);
    }

    public static function recalculate_all($limit = 200)
    {
        $ids = self::_all("
            SELECT id FROM prx_391_documents
            WHERE status NOT IN ('closed', 'archived')
            ORDER BY received_at DESC LIMIT ".(int) $limit."
        ");

        $n = 0;
        foreach ($ids as $row)
        {
            try
            {
                self::route((int) $row['id']);
                $n++;
            }
            catch (Exception $e)
            {
                Log::instance()->add(Log::WARNING, 'prx393: пересчёт #:id не удался: :e', [
                    ':id' => $row['id'], ':e' => $e->getMessage(),
                ]);
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    public static function rules($active_only = FALSE)
    {
        $where = $active_only ? 'WHERE is_active = 1' : '';

        return self::_all("SELECT * FROM prx_393_rules ".$where." ORDER BY priority, id");
    }

    /**
     * Первое подходящее правило (rules уже отсортированы по priority).
     *
     * Публичный и с необязательным списком — чтобы этот выбор можно было
     * проверить стендом без PostgreSQL.
     */
    public static function match_rule($type_code, $domain, $inn, $haystack, ?array $rules = NULL)
    {
        if ($rules === NULL)
        {
            $rules = self::rules(TRUE);
        }

        foreach ($rules as $rule)
        {
            if (self::_rule_matches($rule, $type_code, $domain, $inn, $haystack))
            {
                return $rule;
            }
        }

        return NULL;
    }

    public static function rule($id)
    {
        return self::_row("SELECT * FROM prx_393_rules WHERE id = ".(int) $id);
    }

    public static function deadline_for($document_id)
    {
        return self::_row("
            SELECT dl.*, a.department, a.role_code, a.priority
            FROM prx_393_deadlines dl
            LEFT JOIN prx_393_assignments a ON a.document_id = dl.document_id
            WHERE dl.document_id = ".(int) $document_id."
        ");
    }

    public static function assignment_for($document_id)
    {
        return self::_row("SELECT * FROM prx_393_assignments WHERE document_id = ".(int) $document_id);
    }

    public static function deadlines($limit = 60, $only_open = TRUE)
    {
        $where = $only_open ? "WHERE dl.status = 'open'" : '';

        return self::_all("
            SELECT dl.*, a.role_code, a.priority,
                   CASE
                     WHEN dl.status = 'met' THEN 'met'
                     WHEN dl.due_at < now() THEN 'overdue'
                     WHEN dl.due_at < now() + interval '24 hours' THEN 'soon'
                     ELSE 'ok'
                   END AS state,
                   EXTRACT(EPOCH FROM (dl.due_at - now())) / 3600 AS hours_left
            FROM prx_393_deadlines dl
            LEFT JOIN prx_393_assignments a ON a.document_id = dl.document_id
            ".$where."
            ORDER BY dl.due_at ASC, dl.id ASC
            LIMIT ".(int) $limit."
        ");
    }

    public static function escalations($limit = 30)
    {
        return self::_all("
            SELECT e.*, dl.reg_no, dl.subject, dl.due_at
            FROM prx_393_escalations e
            JOIN prx_393_deadlines dl ON dl.id = e.deadline_id
            ORDER BY e.sent_at DESC, e.id DESC LIMIT ".(int) $limit."
        ");
    }

    public static function stats()
    {
        $row = self::_row("
            SELECT
                count(*) FILTER (WHERE status = 'open') AS open_count,
                count(*) FILTER (WHERE status = 'open' AND due_at < now()) AS overdue,
                count(*) FILTER (WHERE status = 'open' AND due_at >= now() AND due_at < now() + interval '24 hours') AS soon,
                count(*) FILTER (WHERE status = 'met') AS met,
                avg(EXTRACT(EPOCH FROM (due_at - started_at)) / 3600) AS avg_hours
            FROM prx_393_deadlines
        ");

        foreach ($row as $k => $v)
        {
            $row[$k] = $k === 'avg_hours' ? ($v === NULL ? NULL : round((float) $v, 1)) : (int) $v;
        }

        return $row;
    }

    public static function holidays()
    {
        $row = self::_row("SELECT holidays FROM prx_393_calendars WHERE code = ".self::_q((string) self::config('calendar_code', 'ru')));
        if ( ! $row OR empty($row['holidays']))
        {
            return [];
        }

        $out = [];
        foreach (self::_pg_array($row['holidays']) as $date)
        {
            if ($date)
            {
                $out[$date] = TRUE;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Эскалации
    // ------------------------------------------------------------------

    /**
     * Проверить ближайшие сроки и записать эскалации. Идемпотентно:
     * уникальный индекс (deadline_id, level) не даёт дублей.
     */
    public static function maybe_escalate()
    {
        $rows = self::_all("
            SELECT dl.*, a.role_code
            FROM prx_393_deadlines dl
            LEFT JOIN prx_393_assignments a ON a.document_id = dl.document_id
            WHERE dl.status = 'open' AND dl.due_at <= now() + interval '24 hours'
            ORDER BY dl.due_at ASC LIMIT 200
        ");

        $sent = 0;
        foreach ($rows as $row)
        {
            $hours_left = (strtotime($row['due_at']) - time()) / 3600;
            if ($hours_left < 0)
            {
                $level = 'overdue';
                $event = 'prx393.deadline.overdue';
            }
            elseif ($hours_left <= 4)
            {
                $level = 't4';
                $event = 'prx393.deadline.approaching';
            }
            elseif ($hours_left <= 24)
            {
                $level = 't24';
                $event = 'prx393.deadline.approaching';
            }
            else
            {
                continue;
            }

            $exists = self::_row("SELECT id FROM prx_393_escalations WHERE deadline_id = ".(int) $row['id']." AND level = ".self::_q($level));
            if ($exists)
            {
                continue;
            }

            try
            {
                self::_row("
                    INSERT INTO prx_393_escalations (deadline_id, document_id, level)
                    VALUES (".(int) $row['id'].", ".(int) $row['document_id'].", ".self::_q($level).")
                    RETURNING id
                ");
            }
            catch (Exception $e)
            {
                continue; // гонка: уровень уже записан другим запросом
            }

            $targets = self::_role_users((string) ($row['role_code'] ?: 'docflow_manager'));
            Event::fire($event, [
                'document_id' => (int) $row['document_id'],
                'reg_no' => $row['reg_no'],
                'subject' => $row['subject'],
                'due_at' => $row['due_at'],
                'hours_left' => round($hours_left, 1),
                'level' => $level,
                'title' => 'Срок: '.$row['reg_no'],
                'href' => '/documents/'.(int) $row['document_id'],
                'target_user_ids' => $targets,
            ]);
            $sent++;
        }

        return $sent;
    }

    // ------------------------------------------------------------------
    // Календарь
    // ------------------------------------------------------------------

    public static function add_days($start, $days, $business = TRUE, array $holidays = [])
    {
        $days = max(0, (int) $days);
        $date = new DateTimeImmutable($start ?: 'now');

        if ( ! $business)
        {
            $date = $date->modify('+'.$days.' days');
        }
        else
        {
            $added = 0;
            while ($added < $days)
            {
                $date = $date->modify('+1 day');
                $dow = (int) $date->format('N');
                if ($dow >= 6 OR isset($holidays[$date->format('Y-m-d')]))
                {
                    continue;
                }
                $added++;
            }
        }

        return $date->setTime(18, 0, 0)->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    protected static function _rule_matches(array $rule, $type_code, $domain, $inn, $haystack)
    {
        $types = self::_pg_array($rule['match_types']);
        $domains = self::_pg_array($rule['match_domains']);
        $inns = self::_pg_array($rule['match_inns']);
        $keywords = self::_pg_array($rule['match_keywords']);

        $criteria = array_filter([$types, $domains, $inns, $keywords]);
        if ( ! $criteria)
        {
            return FALSE;
        }

        // Сильные признаки — отправитель и ИНН: они перекрывают ошибочный тип.
        if ($domains AND $domain !== '')
        {
            foreach ($domains as $d)
            {
                if ($domain === strtolower($d) OR str_ends_with($domain, '.'.strtolower($d)))
                {
                    return TRUE;
                }
            }
        }
        if ($inns AND $inn !== '' AND in_array($inn, $inns, TRUE))
        {
            return TRUE;
        }
        if ($types AND $type_code !== '' AND in_array($type_code, $types, TRUE))
        {
            return TRUE;
        }
        // Если тип уже известен и он другой, ключевое слово не повод менять
        // маршрут: «обратимся в суд» в претензии — не судебное извещение.
        if ($types AND $type_code !== '' AND $type_code !== 'other')
        {
            return FALSE;
        }
        if ($keywords)
        {
            foreach ($keywords as $kw)
            {
                if ($kw !== '' AND mb_strpos($haystack, mb_strtolower($kw, 'UTF-8')) !== FALSE)
                {
                    return TRUE;
                }
            }
        }

        return FALSE;
    }

    protected static function _pg_array($value)
    {
        if (is_array($value))
        {
            return array_values(array_filter($value, function ($v) { return $v !== NULL AND $v !== ''; }));
        }
        if ( ! is_string($value) OR trim($value) === '' OR trim($value) === '{}')
        {
            return [];
        }

        $value = trim($value, '{}');
        $out = [];
        foreach (str_getcsv($value, ',', '"', '\\') as $item)
        {
            $item = trim($item);
            if ($item !== '')
            {
                $out[] = $item;
            }
        }

        return $out;
    }

    protected static function _role_users($role)
    {
        $out = [];
        try
        {
            foreach (self::_all("
                SELECT DISTINCT ru.user_id
                FROM roles_users ru
                JOIN roles r ON r.id = ru.role_id
                WHERE r.name = ".self::_q($role)."
            ") as $row)
            {
                $out[] = (int) $row['user_id'];
            }
        }
        catch (Exception $e)
        {
            // Схема ролей может отличаться на старом стенде — эскалация всё
            // равно записана, просто без адресатов.
        }

        return $out;
    }

    protected static function _system_actor(array $actor = [])
    {
        return $actor ? $actor : ['user_id' => 0, 'roles' => ['system'], 'is_system' => TRUE];
    }

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
