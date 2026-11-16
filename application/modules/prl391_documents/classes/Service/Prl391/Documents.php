<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Реестр входящих документов.
 *
 * Карточка — единственное место, где живут реквизиты. prl392 не пишет сюда
 * напрямую: он возвращает предложение, а `apply_analysis()` применяет его
 * только после явного решения человека. Каждое изменение поля попадает в
 * `prl_391_field_events` с источником (manual/llm/import).
 */
class Service_Prl391_Documents
{
    const STATUS_NEW      = 'new';
    const STATUS_REVIEW   = 'in_review';
    const STATUS_WORK     = 'in_work';
    const STATUS_HOLD     = 'on_hold';
    const STATUS_CLOSED   = 'closed';
    const STATUS_ARCHIVED = 'archived';

    protected static $_config;

    public static function config($key = NULL, $default = NULL)
    {
        if (self::$_config === NULL)
        {
            self::$_config = Kohana::$config->load('prl391_documents')->as_array();
        }

        if ($key === NULL)
        {
            return self::$_config;
        }

        return array_key_exists($key, self::$_config) ? self::$_config[$key] : $default;
    }

    public static function statuses()
    {
        return self::config('statuses', []);
    }

    public static function priorities()
    {
        return self::config('priorities', []);
    }

    public static function sensitivities()
    {
        return self::config('sensitivities', []);
    }

    public static function status_label($code)
    {
        $all = self::statuses();

        return $all[$code] ?? $code;
    }

    public static function priority_label($code)
    {
        $all = self::priorities();

        return $all[$code] ?? $code;
    }

    // ------------------------------------------------------------------
    // Регистрация из конверта prl390
    // ------------------------------------------------------------------

    public static function register_from_message($message_id)
    {
        $message_id = (int) $message_id;
        $existing = self::_row("SELECT * FROM prl_391_documents WHERE source_message_id = ".$message_id);
        if ($existing)
        {
            return (int) $existing['id'];
        }

        $message = Service_Prl390_Intake::message($message_id);
        if ( ! $message)
        {
            throw new InvalidArgumentException('Конверт prl390 не найден');
        }

        $subject = trim((string) ($message['subject'] ?? ''));
        if ($subject === '')
        {
            $subject = $message['source_ref'] ?: ('Входящий #'.$message_id);
        }

        $seq = self::_row("SELECT nextval('prl_391_reg_no_seq') AS n");
        $reg_no = 'ВХ-'.date('Y').'-'.str_pad((string) (int) $seq['n'], 5, '0', STR_PAD_LEFT);

        try
        {
            $row = self::_row("
                INSERT INTO prl_391_documents
                    (reg_no, source_message_id, subject, sender_name, sender_email, sender_inn,
                     received_at, status, priority, sensitivity, created_by)
                VALUES (
                    ".self::_q($reg_no).", ".$message_id.", ".self::_q($subject).",
                    ".self::_q_or_null($message['sender_name'] ?? NULL).",
                    ".self::_q_or_null($message['sender_email'] ?? NULL).",
                    ".self::_q_or_null($message['sender_inn'] ?? NULL).",
                    ".self::_q($message['received_at'] ?: date('Y-m-d H:i:s')).",
                    'new', 'normal', 'internal', ".self::_int_or_null($message['created_by'] ?? NULL)."
                )
                RETURNING id
            ");
        }
        catch (Exception $e)
        {
            // Гонка двух воркеров: второй получает existing по source_message_id.
            $existing = self::_row("SELECT * FROM prl_391_documents WHERE source_message_id = ".$message_id);
            if ($existing)
            {
                return (int) $existing['id'];
            }

            throw $e;
        }

        $document_id = (int) $row['id'];

        self::_history($document_id, 'registered', 'Документ зарегистрирован из конверта #'.$message_id, $message['created_by'] ?? NULL, [
            'message_id' => $message_id,
            'reg_no' => $reg_no,
        ]);
        Service_Prl390_Intake::link_document($message_id, $document_id);

        Event::fire('prl391.document.registered', [
            'document_id' => $document_id,
            'message_id' => $message_id,
            'reg_no' => $reg_no,
            'subject' => $subject,
            'href' => '/documents/'.$document_id,
        ]);

        return $document_id;
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    public static function list_documents(array $filters = [], array $actor = [])
    {
        $where = ['d.status <> \'archived\''];
        if ( ! self::_can_admin($actor))
        {
            $where[] = "d.sensitivity IN ('public', 'internal')";
        }

        if ( ! empty($filters['status']))
        {
            $where[] = "d.status = ".self::_q($filters['status']);
        }
        if ( ! empty($filters['type']))
        {
            $where[] = "t.code = ".self::_q($filters['type']);
        }
        if ( ! empty($filters['q']))
        {
            $q = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
            $where[] = "(d.subject ILIKE ".self::_q($q)." OR d.reg_no ILIKE ".self::_q($q)
                ." OR c.name ILIKE ".self::_q($q)." OR d.sender_name ILIKE ".self::_q($q).")";
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $per = max(1, min(100, (int) ($filters['per_page'] ?? self::config('page_size', 40))));
        $offset = ($page - 1) * $per;

        $total_row = self::_row("
            SELECT count(*) AS n
            FROM prl_391_documents d
            LEFT JOIN prl_391_types t ON t.id = d.type_id
            LEFT JOIN prl_391_correspondents c ON c.id = d.correspondent_id
            WHERE ".implode(' AND ', $where)."
        ");

        $rows = self::_all("
            SELECT d.*, t.code AS type_code, t.title AS type_title, t.icon AS type_icon,
                   c.name AS correspondent_name,
                   CASE
                     WHEN d.status IN ('closed', 'archived') THEN 'closed'
                     WHEN d.deadline_at IS NULL THEN 'none'
                     WHEN d.deadline_at < now() THEN 'overdue'
                     WHEN d.deadline_at < now() + interval '24 hours' THEN 'soon'
                     ELSE 'ok'
                   END AS deadline_state,
                   (SELECT count(*) FROM prl_391_field_events f WHERE f.document_id = d.id AND f.source = 'llm') AS llm_events
            FROM prl_391_documents d
            LEFT JOIN prl_391_types t ON t.id = d.type_id
            LEFT JOIN prl_391_correspondents c ON c.id = d.correspondent_id
            WHERE ".implode(' AND ', $where)."
            ORDER BY
                CASE d.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
                d.received_at DESC, d.id DESC
            LIMIT ".$per." OFFSET ".$offset."
        ");

        return [
            'rows' => $rows,
            'total' => (int) $total_row['n'],
            'page' => $page,
            'per_page' => $per,
            'pages' => max(1, (int) ceil(((int) $total_row['n']) / $per)),
        ];
    }

    public static function document($id, array $actor = [])
    {
        $row = self::_row("
            SELECT d.*, t.code AS type_code, t.title AS type_title, t.icon AS type_icon,
                   c.name AS correspondent_name, c.inn AS correspondent_inn, c.email AS correspondent_email,
                   CASE
                     WHEN d.status IN ('closed', 'archived') THEN 'closed'
                     WHEN d.deadline_at IS NULL THEN 'none'
                     WHEN d.deadline_at < now() THEN 'overdue'
                     WHEN d.deadline_at < now() + interval '24 hours' THEN 'soon'
                     ELSE 'ok'
                   END AS deadline_state
            FROM prl_391_documents d
            LEFT JOIN prl_391_types t ON t.id = d.type_id
            LEFT JOIN prl_391_correspondents c ON c.id = d.correspondent_id
            WHERE d.id = ".(int) $id."
        ");

        if ( ! $row OR ! self::can_view($row, $actor))
        {
            return NULL;
        }

        $row['field_events'] = self::_all("
            SELECT * FROM prl_391_field_events WHERE document_id = ".(int) $id." ORDER BY created_at DESC, id DESC LIMIT 50
        ");
        $row['history'] = self::_all("
            SELECT * FROM prl_391_history WHERE document_id = ".(int) $id." ORDER BY created_at DESC, id DESC LIMIT 50
        ");

        if ( ! empty($row['source_message_id']))
        {
            $row['message'] = Service_Prl390_Intake::message((int) $row['source_message_id']);
        }
        else
        {
            $row['message'] = NULL;
        }

        return $row;
    }

    public static function types()
    {
        return self::_all("SELECT * FROM prl_391_types WHERE is_active = 1 ORDER BY sort, title");
    }

    public static function correspondents($limit = 200)
    {
        return self::_all("SELECT * FROM prl_391_correspondents ORDER BY lower(name) LIMIT ".(int) $limit);
    }

    public static function stats(array $actor = [])
    {
        $where = ['1=1'];
        if ( ! self::_can_admin($actor))
        {
            $where[] = "sensitivity IN ('public', 'internal')";
        }
        $w = implode(' AND ', $where);

        $row = self::_row("
            SELECT
                count(*) AS total,
                count(*) FILTER (WHERE status = 'new') AS new_count,
                count(*) FILTER (WHERE status IN ('in_review', 'in_work')) AS work_count,
                count(*) FILTER (WHERE status IN ('closed', 'archived')) AS closed_count,
                count(*) FILTER (WHERE deadline_at < now() AND status NOT IN ('closed', 'archived')) AS overdue,
                count(*) FILTER (WHERE deadline_at < now() + interval '24 hours' AND deadline_at >= now() AND status NOT IN ('closed', 'archived')) AS due_soon,
                count(*) FILTER (WHERE received_at::date = current_date) AS today,
                count(*) FILTER (WHERE assignee_id IS NULL AND department IS NULL AND status NOT IN ('closed', 'archived')) AS unassigned
            FROM prl_391_documents
            WHERE ".$w."
        ");

        foreach ($row as $k => $v)
        {
            $row[$k] = (int) $v;
        }

        return $row;
    }

    public static function recent_history($limit = 20)
    {
        return self::_all("
            SELECT h.*, d.reg_no, d.subject
            FROM prl_391_history h
            JOIN prl_391_documents d ON d.id = h.document_id
            ORDER BY h.created_at DESC, h.id DESC
            LIMIT ".(int) $limit."
        ");
    }

    // ------------------------------------------------------------------
    // Ручное редактирование
    // ------------------------------------------------------------------

    public static function save_card($id, array $input, array $actor = [])
    {
        $doc = self::document($id, $actor);
        if ( ! $doc)
        {
            throw new InvalidArgumentException('Документ не найден');
        }
        if ( ! self::_can_edit($actor))
        {
            throw new RuntimeException('Недостаточно прав на редактирование карточки');
        }

        // Понижение грифа и архивирование меняют круг читателей — это
        // решение руководителя (`prl391.admin`), а не право редактора.
        if (array_key_exists('sensitivity', $input)
            && (string) $input['sensitivity'] !== (string) $doc['sensitivity']
            && ! self::_can_admin($actor))
        {
            throw new RuntimeException('Менять гриф документа может только руководитель');
        }
        if (isset($input['status']) && $input['status'] === 'archived' && ! self::_can_admin($actor))
        {
            throw new RuntimeException('Архивировать документ может только руководитель');
        }

        $sets = [];
        $events = [];
        $fields = ['subject', 'sender_name', 'sender_email', 'sender_inn', 'outbound_no',
            'contract_no', 'summary', 'priority', 'sensitivity'];

        foreach ($fields as $field)
        {
            if ( ! array_key_exists($field, $input))
            {
                continue;
            }

            $new = trim((string) $input[$field]);
            $old = (string) ($doc[$field] ?? '');
            if ($new === $old)
            {
                continue;
            }

            $sets[] = $field." = ".($new === '' ? ($field === 'subject' ? "''" : 'NULL') : self::_q($new));
            $events[] = [$field, $old, $new];
        }

        if (array_key_exists('type_id', $input))
        {
            $type_id = (int) $input['type_id'];
            $valid = $type_id > 0 ? self::_row("SELECT id FROM prl_391_types WHERE id = ".$type_id." AND is_active = 1") : NULL;
            $new = $valid ? $type_id : 0;
            if ($new !== (int) $doc['type_id'])
            {
                $sets[] = "type_id = ".($new ?: 'NULL');
                $events[] = ['type_id', (string) $doc['type_id'], (string) $new];
            }
        }

        if (array_key_exists('correspondent_id', $input))
        {
            $cid = (int) $input['correspondent_id'];
            $valid = $cid > 0 ? self::_row("SELECT id FROM prl_391_correspondents WHERE id = ".$cid) : NULL;
            $new = $valid ? $cid : 0;
            if ($new !== (int) $doc['correspondent_id'])
            {
                $sets[] = "correspondent_id = ".($new ?: 'NULL');
                $events[] = ['correspondent_id', (string) $doc['correspondent_id'], (string) $new];
            }
        }

        if (array_key_exists('outbound_date', $input))
        {
            $new = self::_date_or_null($input['outbound_date']);
            $old = (string) ($doc['outbound_date'] ?? '');
            if ((string) $new !== $old)
            {
                $sets[] = "outbound_date = ".($new ? self::_q($new) : 'NULL');
                $events[] = ['outbound_date', $old, (string) $new];
            }
        }

        if (array_key_exists('amount', $input))
        {
            $new = self::_amount_or_null($input['amount']);
            $old = $doc['amount'] === NULL ? '' : (string) $doc['amount'];
            if ((string) $new !== $old)
            {
                $sets[] = "amount = ".($new === NULL ? 'NULL' : (string) $new);
                $events[] = ['amount', $old, (string) $new];
            }
        }

        if (array_key_exists('status', $input) AND array_key_exists((string) $input['status'], self::statuses()))
        {
            $new = (string) $input['status'];
            if ($new !== (string) $doc['status'])
            {
                $sets[] = "status = ".self::_q($new);
                $sets[] = $new === 'closed' ? "closed_at = now()" : "closed_at = NULL";
                $events[] = ['status', (string) $doc['status'], $new];
            }
        }

        if ($sets)
        {
            self::_exec("UPDATE prl_391_documents SET ".implode(', ', $sets).", updated_at = now() WHERE id = ".(int) $id);
            foreach ($events as $e)
            {
                self::_field_event($id, $e[0], $e[1], $e[2], 'manual', NULL, $actor['user_id'] ?? NULL);
            }
            self::_history($id, 'card_updated', 'Карточка изменена вручную: '.count($events).' пол.', $actor['user_id'] ?? NULL, [
                'fields' => array_column($events, 0),
            ]);
        }

        Event::fire('prl391.document.updated', [
            'document_id' => (int) $id,
            'recalc' => TRUE,
            'reason' => 'card',
            'actor_id' => (int) ($actor['user_id'] ?? 0),
        ]);

        return ['changed' => count($events), 'fields' => array_column($events, 0)];
    }

    public static function close($id, array $actor = [])
    {
        $doc = self::document($id, $actor);
        if ( ! $doc)
        {
            throw new InvalidArgumentException('Документ не найден');
        }
        if ( ! self::_can_edit($actor))
        {
            throw new RuntimeException('Недостаточно прав');
        }

        self::_exec("UPDATE prl_391_documents SET status = 'closed', closed_at = now(), updated_at = now() WHERE id = ".(int) $id);
        self::_history($id, 'closed', 'Документ закрыт', $actor['user_id'] ?? NULL);
        Event::fire('prl391.document.updated', [
            'document_id' => (int) $id, 'recalc' => FALSE, 'reason' => 'closed',
            'actor_id' => (int) ($actor['user_id'] ?? 0),
        ]);

        return TRUE;
    }

    public static function reopen($id, array $actor = [])
    {
        $doc = self::document($id, $actor);
        if ( ! $doc)
        {
            throw new InvalidArgumentException('Документ не найден');
        }
        if ( ! self::_can_edit($actor))
        {
            throw new RuntimeException('Недостаточно прав');
        }

        self::_exec("UPDATE prl_391_documents SET status = 'in_work', closed_at = NULL, updated_at = now() WHERE id = ".(int) $id);
        self::_history($id, 'reopened', 'Документ возвращён в работу', $actor['user_id'] ?? NULL);
        Event::fire('prl391.document.updated', [
            'document_id' => (int) $id, 'recalc' => TRUE, 'reason' => 'reopened',
            'actor_id' => (int) ($actor['user_id'] ?? 0),
        ]);

        return TRUE;
    }

    // ------------------------------------------------------------------
    // Применение предложения ИИ
    // ------------------------------------------------------------------

    /**
     * Применить предложение prl392 к карточке.
     *
     * @param array $payload нормализованное предложение
     * @param int   $suggestion_id провенанс
     * @param array $actor кто применил
     * @param bool  $overwrite перезаписывать ли уже заполненные поля
     */
    public static function apply_analysis($id, array $payload, $suggestion_id, array $actor = [], $overwrite = FALSE)
    {
        $doc = self::document($id, $actor);
        if ( ! $doc)
        {
            throw new InvalidArgumentException('Документ не найден');
        }
        if ( ! self::_can_edit($actor))
        {
            throw new RuntimeException('Недостаточно прав на применение предложений');
        }

        $sets = [];
        $events = [];
        $skipped = [];

        $put = function ($field, $new) use (&$sets, &$events, &$skipped, $doc, $overwrite) {
            $new = trim((string) $new);
            if ($new === '')
            {
                return;
            }

            $old = (string) ($doc[$field] ?? '');
            if ($old !== '' AND ! $overwrite AND $old !== $new)
            {
                $skipped[] = $field;
                return;
            }
            if ($old === $new)
            {
                return;
            }

            $sets[] = $field." = ".self::_q($new);
            $events[] = [$field, $old, $new];
        };

        $type_code = trim((string) ($payload['type'] ?? ''));
        if ($type_code !== '' AND $type_code !== 'other')
        {
            $type = self::_row("SELECT id, code FROM prl_391_types WHERE code = ".self::_q($type_code)." AND is_active = 1");
            if ($type)
            {
                $current = (int) $doc['type_id'];
                if ( ! $current OR $overwrite)
                {
                    if ($current !== (int) $type['id'])
                    {
                        $sets[] = "type_id = ".(int) $type['id'];
                        $events[] = ['type_id', (string) $current, (string) $type['id']];
                    }
                }
                else
                {
                    $skipped[] = 'type_id';
                }
            }
        }

        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];
        $value = function ($key) use ($fields) {
            if ( ! isset($fields[$key]))
            {
                return '';
            }
            $v = $fields[$key];
            if (is_array($v))
            {
                return (string) ($v['value'] ?? '');
            }

            return (string) $v;
        };

        $put('outbound_no', $value('outbound_no'));
        $put('contract_no', $value('contract_no'));
        $put('sender_inn', preg_replace('/\D+/', '', $value('sender_inn')));

        $out_date = self::_date_or_null($value('outbound_date'));
        if ($out_date)
        {
            $put('outbound_date', $out_date);
        }

        $amount = self::_amount_or_null($value('amount'));
        if ($amount !== NULL)
        {
            $put('amount', $amount);
        }

        $hint_days = (int) preg_replace('/\D+/', '', $value('deadline_hint_days'));
        if ($hint_days > 0)
        {
            $unit = strtolower($value('deadline_hint_unit'));
            $unit = in_array($unit, ['business', 'calendar'], TRUE) ? $unit : 'calendar';
            $put('deadline_hint_days', $hint_days);
            $put('deadline_hint_unit', $unit);
            $quote = isset($fields['deadline_hint_days']['quote']) ? (string) $fields['deadline_hint_days']['quote'] : '';
            if ($quote !== '')
            {
                $put('deadline_quote', mb_substr($quote, 0, 500));
            }
        }

        $summary = trim(strip_tags((string) ($payload['summary'] ?? '')));
        if ($summary !== '')
        {
            $put('summary', mb_substr($summary, 0, 2000));
        }

        $risk = isset($payload['risk']['level']) ? (string) $payload['risk']['level'] : '';
        if (in_array($risk, ['low', 'medium', 'high'], TRUE))
        {
            $put('risk_level', $risk);
        }

        $corr_name = trim($value('correspondent_name'));
        if ($corr_name !== '')
        {
            $corr_id = self::upsert_correspondent($corr_name, $value('sender_inn'), $doc['sender_email'] ?? NULL);
            $current = (int) $doc['correspondent_id'];
            if ($corr_id AND ( ! $current OR $overwrite) AND $current !== $corr_id)
            {
                $sets[] = "correspondent_id = ".(int) $corr_id;
                $events[] = ['correspondent_id', (string) $current, (string) $corr_id];
            }
            elseif ($corr_id AND $current !== $corr_id)
            {
                $skipped[] = 'correspondent_id';
            }
        }

        if ($sets)
        {
            self::_exec("UPDATE prl_391_documents SET ".implode(', ', $sets).", updated_at = now() WHERE id = ".(int) $id);
            foreach ($events as $e)
            {
                self::_field_event($id, $e[0], $e[1], $e[2], 'llm', $suggestion_id, $actor['user_id'] ?? NULL);
            }
            self::_history($id, 'analysis_applied', 'Применено предложение ИИ #'.(int) $suggestion_id, $actor['user_id'] ?? NULL, [
                'fields' => array_column($events, 0),
                'skipped' => array_values(array_unique($skipped)),
            ]);
        }

        Event::fire('prl391.document.updated', [
            'document_id' => (int) $id,
            'recalc' => TRUE,
            'reason' => 'analysis',
            'actor_id' => (int) ($actor['user_id'] ?? 0),
        ]);

        return [
            'changed' => count($events),
            'fields' => array_column($events, 0),
            'skipped' => array_values(array_unique($skipped)),
        ];
    }

    public static function upsert_correspondent($name, $inn = NULL, $email = NULL)
    {
        $name = trim(strip_tags((string) $name));
        if ($name === '')
        {
            return 0;
        }

        $inn = preg_replace('/\D+/', '', (string) $inn);
        $row = NULL;
        if ($inn !== '')
        {
            $row = self::_row("SELECT id FROM prl_391_correspondents WHERE inn = ".self::_q($inn));
        }
        if ( ! $row)
        {
            $row = self::_row("SELECT id FROM prl_391_correspondents WHERE lower(name) = lower(".self::_q($name).")");
        }
        if ($row)
        {
            return (int) $row['id'];
        }

        $new = self::_row("
            INSERT INTO prl_391_correspondents (name, inn, email)
            VALUES (".self::_q(mb_substr($name, 0, 255)).", ".($inn !== '' ? self::_q($inn) : 'NULL').", ".($email ? self::_q($email) : 'NULL').")
            RETURNING id
        ");

        return (int) $new['id'];
    }

    /** Снимок срока: пишет только prl393, здесь — публичный приёмник. */
    public static function set_sla_snapshot($id, array $data = [])
    {
        $sets = [];
        foreach (['deadline_source', 'assignee_role', 'department', 'priority'] as $field)
        {
            if (array_key_exists($field, $data) AND $data[$field] !== NULL)
            {
                $sets[] = $field." = ".self::_q((string) $data[$field]);
            }
        }
        if (array_key_exists('deadline_at', $data))
        {
            $sets[] = "deadline_at = ".($data['deadline_at'] ? self::_q($data['deadline_at']) : 'NULL');
        }
        if (array_key_exists('assignee_id', $data))
        {
            $sets[] = "assignee_id = ".self::_int_or_null($data['assignee_id']);
        }
        if ($sets)
        {
            self::_exec("UPDATE prl_391_documents SET ".implode(', ', $sets).", updated_at = now() WHERE id = ".(int) $id);
        }

        return TRUE;
    }

    // ------------------------------------------------------------------
    // Права
    // ------------------------------------------------------------------

    public static function can_view(array $doc, array $actor = [])
    {
        if (self::_is_system($actor))
        {
            return TRUE;
        }
        if ( ! ACL::check($actor['roles'] ?? [], 'prl391.view'))
        {
            return FALSE;
        }
        if (in_array($doc['sensitivity'] ?? 'internal', ['public', 'internal'], TRUE))
        {
            return TRUE;
        }

        return in_array('docflow_lawyer', $actor['roles'] ?? [], TRUE)
            OR in_array('docflow_manager', $actor['roles'] ?? [], TRUE)
            OR in_array('admin', $actor['roles'] ?? [], TRUE);
    }

    public static function can_edit(array $actor = [])
    {
        return self::_is_system($actor) OR self::_can_edit($actor);
    }

    protected static function _can_edit(array $actor)
    {
        if (self::_is_system($actor))
        {
            return TRUE;
        }

        return ACL::check($actor['roles'] ?? [], 'prl391.edit');
    }

    protected static function _can_admin(array $actor)
    {
        if (self::_is_system($actor))
        {
            return TRUE;
        }

        return ACL::check($actor['roles'] ?? [], 'prl391.admin');
    }

    protected static function _is_system(array $actor)
    {
        return ! empty($actor['is_system']) OR in_array('system', $actor['roles'] ?? [], TRUE);
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    protected static function _field_event($document_id, $field, $old, $new, $source, $suggestion_id, $actor_id)
    {
        self::_exec(
            "INSERT INTO prl_391_field_events (document_id, field, old_value, new_value, source, suggestion_id, actor_id) "
            ."VALUES (".(int) $document_id.", ".self::_q($field).", ".self::_q_or_null($old).", "
            .self::_q_or_null($new).", ".self::_q($source).", ".self::_int_or_null($suggestion_id).", "
            .self::_int_or_null($actor_id).")"
        );
    }

    protected static function _history($document_id, $event, $note, $actor_id, array $payload = [])
    {
        self::_exec(
            "INSERT INTO prl_391_history (document_id, event, note, actor_id, payload) "
            ."VALUES (".(int) $document_id.", ".self::_q($event).", ".self::_q_or_null($note).", "
            .self::_int_or_null($actor_id).", ".self::_q(json_encode($payload, JSON_UNESCAPED_UNICODE))."::jsonb)"
        );
    }

    protected static function _date_or_null($value)
    {
        $value = trim((string) $value);
        if ($value === '')
        {
            return NULL;
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ( ! $dt OR $dt->format('Y-m-d') !== $value)
        {
            return NULL;
        }

        return $value;
    }

    protected static function _amount_or_null($value)
    {
        $value = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], trim((string) $value));
        if ($value === '' OR ! is_numeric($value))
        {
            return NULL;
        }

        return round((float) $value, 2);
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

    protected static function _q_or_null($value)
    {
        return ($value === NULL OR $value === '') ? 'NULL' : self::_q($value);
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
