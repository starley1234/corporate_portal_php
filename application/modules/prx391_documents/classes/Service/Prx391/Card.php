<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Запись карточки документа prx391: ручные правки, закрытие, применение ИИ.
 *
 * Выделено из `Service_Prx391_Documents`, чтобы провенанс и правила изменения
 * полей жили рядом. Каждое изменение проходит через `_field_event()` и
 * `_history()` — карточка остаётся единственным местом реквизитов.
 */
class Service_Prx391_Card
{
    // ------------------------------------------------------------------
    // Ручное редактирование
    // ------------------------------------------------------------------

    public static function save_card($id, array $input, array $actor = [])
    {
        $doc = Service_Prx391_Documents::document($id, $actor);
        if ( ! $doc)
        {
            throw new InvalidArgumentException('Документ не найден');
        }
        if ( ! self::_can_edit($actor))
        {
            throw new RuntimeException('Недостаточно прав на редактирование карточки');
        }

        // Понижение грифа и архивирование меняют круг читателей — это
        // решение руководителя (`prx391.admin`), а не право редактора.
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
            $valid = $type_id > 0 ? self::_row("SELECT id FROM prx_391_types WHERE id = ".$type_id." AND is_active = 1") : NULL;
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
            $valid = $cid > 0 ? self::_row("SELECT id FROM prx_391_correspondents WHERE id = ".$cid) : NULL;
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
            // numeric из PostgreSQL приходит строкой «1245000.00»; сравниваем
            // числа, иначе каждое сохранение давало ложное изменение.
            $old = $doc['amount'] === NULL ? NULL : round((float) $doc['amount'], 2);
            if ($new !== $old)
            {
                $sets[] = "amount = ".($new === NULL ? 'NULL' : (string) $new);
                $events[] = ['amount', $old === NULL ? '' : (string) $old, (string) $new];
            }
        }

        if (array_key_exists('status', $input) AND array_key_exists((string) $input['status'], Service_Prx391_Documents::statuses()))
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
            self::_exec("UPDATE prx_391_documents SET ".implode(', ', $sets).", updated_at = now() WHERE id = ".(int) $id);
            foreach ($events as $e)
            {
                self::_field_event($id, $e[0], $e[1], $e[2], 'manual', NULL, $actor['user_id'] ?? NULL);
            }
            self::_history($id, 'card_updated', 'Карточка изменена вручную: '.count($events).' пол.', $actor['user_id'] ?? NULL, [
                'fields' => array_column($events, 0),
            ]);
        }

        Event::publish('prx391.document.updated', [
            'document_id' => (int) $id,
            'recalc' => TRUE,
            'reason' => 'card',
            'actor_id' => (int) ($actor['user_id'] ?? 0),
        ], (int) ($actor['user_id'] ?? 0) ?: NULL);

        return ['changed' => count($events), 'fields' => array_column($events, 0)];
    }

    public static function close($id, array $actor = [])
    {
        $doc = Service_Prx391_Documents::document($id, $actor);
        if ( ! $doc)
        {
            throw new InvalidArgumentException('Документ не найден');
        }
        if ( ! self::_can_edit($actor))
        {
            throw new RuntimeException('Недостаточно прав');
        }

        self::_exec("UPDATE prx_391_documents SET status = 'closed', closed_at = now(), updated_at = now() WHERE id = ".(int) $id);
        self::_history($id, 'closed', 'Документ закрыт', $actor['user_id'] ?? NULL);
        Event::publish('prx391.document.updated', [
            'document_id' => (int) $id, 'recalc' => FALSE, 'reason' => 'closed',
            'actor_id' => (int) ($actor['user_id'] ?? 0),
        ], (int) ($actor['user_id'] ?? 0) ?: NULL);

        return TRUE;
    }

    public static function reopen($id, array $actor = [])
    {
        $doc = Service_Prx391_Documents::document($id, $actor);
        if ( ! $doc)
        {
            throw new InvalidArgumentException('Документ не найден');
        }
        if ( ! self::_can_edit($actor))
        {
            throw new RuntimeException('Недостаточно прав');
        }

        self::_exec("UPDATE prx_391_documents SET status = 'in_work', closed_at = NULL, updated_at = now() WHERE id = ".(int) $id);
        self::_history($id, 'reopened', 'Документ возвращён в работу', $actor['user_id'] ?? NULL);
        Event::publish('prx391.document.updated', [
            'document_id' => (int) $id, 'recalc' => TRUE, 'reason' => 'reopened',
            'actor_id' => (int) ($actor['user_id'] ?? 0),
        ], (int) ($actor['user_id'] ?? 0) ?: NULL);

        return TRUE;
    }

    // ------------------------------------------------------------------
    // Применение предложения ИИ
    // ------------------------------------------------------------------

    /**
     * Применить предложение prx392 к карточке.
     *
     * @param array $payload нормализованное предложение
     * @param int   $suggestion_id провенанс
     * @param array $actor кто применил
     * @param bool  $overwrite перезаписывать ли уже заполненные поля
     */
    public static function apply_analysis($id, array $payload, $suggestion_id, array $actor = [], $overwrite = FALSE)
    {
        $doc = Service_Prx391_Documents::document($id, $actor);
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
            $type = self::_row("SELECT id, code FROM prx_391_types WHERE code = ".self::_q($type_code)." AND is_active = 1");
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
            self::_exec("UPDATE prx_391_documents SET ".implode(', ', $sets).", updated_at = now() WHERE id = ".(int) $id);
            foreach ($events as $e)
            {
                self::_field_event($id, $e[0], $e[1], $e[2], 'llm', $suggestion_id, $actor['user_id'] ?? NULL);
            }
            self::_history($id, 'analysis_applied', 'Применено предложение ИИ #'.(int) $suggestion_id, $actor['user_id'] ?? NULL, [
                'fields' => array_column($events, 0),
                'skipped' => array_values(array_unique($skipped)),
            ]);
        }

        Event::publish('prx391.document.updated', [
            'document_id' => (int) $id,
            'recalc' => TRUE,
            'reason' => 'analysis',
            'actor_id' => (int) ($actor['user_id'] ?? 0),
        ], (int) ($actor['user_id'] ?? 0) ?: NULL);

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
            $row = self::_row("SELECT id FROM prx_391_correspondents WHERE inn = ".self::_q($inn));
        }
        if ( ! $row)
        {
            $row = self::_row("SELECT id FROM prx_391_correspondents WHERE lower(name) = lower(".self::_q($name).")");
        }
        if ($row)
        {
            return (int) $row['id'];
        }

        $new = self::_row("
            INSERT INTO prx_391_correspondents (name, inn, email)
            VALUES (".self::_q(mb_substr($name, 0, 255)).", ".($inn !== '' ? self::_q($inn) : 'NULL').", ".($email ? self::_q($email) : 'NULL').")
            RETURNING id
        ");

        return (int) $new['id'];
    }

    /** Снимок срока: пишет только prx393, здесь — публичный приёмник. */
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
            self::_exec("UPDATE prx_391_documents SET ".implode(', ', $sets).", updated_at = now() WHERE id = ".(int) $id);
        }

        return TRUE;
    }

    // ------------------------------------------------------------------
    // Права и внутреннее
    // ------------------------------------------------------------------

    protected static function _can_edit(array $actor)
    {
        if (self::_is_system($actor))
        {
            return TRUE;
        }

        return Prx_Actor::fromArray($actor)->can('prx391.edit');
    }

    protected static function _can_admin(array $actor)
    {
        if (self::_is_system($actor))
        {
            return TRUE;
        }

        return Prx_Actor::fromArray($actor)->can('prx391.admin');
    }

    protected static function _is_system(array $actor)
    {
        return ! empty($actor['is_system']) OR in_array('system', $actor['roles'] ?? [], TRUE);
    }

    protected static function _field_event($document_id, $field, $old, $new, $source, $suggestion_id, $actor_id)
    {
        self::_exec(
            "INSERT INTO prx_391_field_events (document_id, field, old_value, new_value, source, suggestion_id, actor_id) "
            ."VALUES (".(int) $document_id.", ".self::_q($field).", ".self::_q_or_null($old).", "
            .self::_q_or_null($new).", ".self::_q($source).", ".self::_int_or_null($suggestion_id).", "
            .self::_int_or_null($actor_id).")"
        );
    }

    protected static function _history($document_id, $event, $note, $actor_id, array $payload = [])
    {
        self::_exec(
            "INSERT INTO prx_391_history (document_id, event, note, actor_id, payload) "
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

    protected static function _exec($sql)
    {
        return self::_db()->query(Database::UPDATE, $sql, FALSE);
    }
}
