<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Реестр входящих документов.
 *
 * Карточка — единственное место, где живут реквизиты. prx392 не пишет сюда
 * напрямую: он возвращает предложение, а `apply_analysis()` применяет его
 * только после явного решения человека. Каждое изменение поля попадает в
 * `prx_391_field_events` с источником (manual/llm/import).
 */
class Service_Prx391_Documents
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
            self::$_config = Kohana::$config->load('prx391_documents')->as_array();
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
    // Регистрация из конверта prx390
    // ------------------------------------------------------------------

    public static function register_from_message($message_id)
    {
        $message_id = (int) $message_id;
        $existing = self::_row("SELECT * FROM prx_391_documents WHERE source_message_id = ".$message_id);
        if ($existing)
        {
            return (int) $existing['id'];
        }

        $message = Service_Prx390_Intake::message($message_id);
        if ( ! $message)
        {
            throw new InvalidArgumentException('Конверт prx390 не найден');
        }

        $subject = trim((string) ($message['subject'] ?? ''));
        if ($subject === '')
        {
            $subject = $message['source_ref'] ?: ('Входящий #'.$message_id);
        }

        $seq = self::_row("SELECT nextval('prx_391_reg_no_seq') AS n");
        $reg_no = 'ВХ-'.date('Y').'-'.str_pad((string) (int) $seq['n'], 5, '0', STR_PAD_LEFT);

        try
        {
            $row = self::_row("
                INSERT INTO prx_391_documents
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
            $existing = self::_row("SELECT * FROM prx_391_documents WHERE source_message_id = ".$message_id);
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
        Service_Prx390_Intake::link_document($message_id, $document_id);

        Event::publish('prx391.document.registered', [
            'document_id' => $document_id,
            'message_id' => $message_id,
            'reg_no' => $reg_no,
            'subject' => $subject,
            'href' => '/prx391_documents/'.$document_id,
        ], $message['created_by'] ? (int) $message['created_by'] : NULL);

        return $document_id;
    }

    // ------------------------------------------------------------------
    // Чтение (делегирует в Registry — см. Service/Prx391/Registry.php)
    // ------------------------------------------------------------------

    public static function list_documents(array $filters = [], array $actor = [])
    {
        return Service_Prx391_Registry::list_documents($filters, $actor);
    }

    public static function document($id, array $actor = [])
    {
        return Service_Prx391_Registry::document($id, $actor);
    }

    public static function types()
    {
        return Service_Prx391_Registry::types();
    }

    public static function correspondents($limit = 200)
    {
        return Service_Prx391_Registry::correspondents($limit);
    }

    public static function stats(array $actor = [])
    {
        return Service_Prx391_Registry::stats($actor);
    }

    /** ID из предложенного списка, чьи карточки доступны актору. */
    public static function visible_ids(array $ids, array $actor = [])
    {
        return Service_Prx391_Registry::visible_ids($ids, $actor);
    }

    public static function recent_history($limit = 20, array $actor = [])
    {
        return Service_Prx391_Registry::recent_history($limit, $actor);
    }

    // ------------------------------------------------------------------
    // Ручное редактирование (делегирует в Card — см. Service/Prx391/Card.php)
    // ------------------------------------------------------------------

    public static function save_card($id, array $input, array $actor = [])
    {
        return Service_Prx391_Card::save_card($id, $input, $actor);
    }

    public static function close($id, array $actor = [])
    {
        return Service_Prx391_Card::close($id, $actor);
    }

    public static function reopen($id, array $actor = [])
    {
        return Service_Prx391_Card::reopen($id, $actor);
    }

    public static function apply_analysis($id, array $payload, $suggestion_id, array $actor = [], $overwrite = FALSE)
    {
        return Service_Prx391_Card::apply_analysis($id, $payload, $suggestion_id, $actor, $overwrite);
    }

    public static function upsert_correspondent($name, $inn = NULL, $email = NULL)
    {
        return Service_Prx391_Card::upsert_correspondent($name, $inn, $email);
    }

    public static function set_sla_snapshot($id, array $data = [])
    {
        return Service_Prx391_Card::set_sla_snapshot($id, $data);
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
        if ( ! ACL::check($actor['roles'] ?? [], 'prx391.view'))
        {
            return FALSE;
        }
        if (in_array($doc['sensitivity'] ?? 'internal', ['public', 'internal'], TRUE))
        {
            return TRUE;
        }

        return self::can_view_all($actor);
    }

    /** Может ли актор видеть карточки с любым грифом, включая персональные. */
    public static function can_view_all(array $actor = [])
    {
        if (self::_is_system($actor))
        {
            return TRUE;
        }
        $roles = $actor['roles'] ?? [];
        return ACL::check($roles, 'prx391.view')
            && (in_array('docflow_lawyer', $roles, TRUE)
                || in_array('docflow_manager', $roles, TRUE)
                || in_array('admin', $roles, TRUE));
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

        return ACL::check($actor['roles'] ?? [], 'prx391.edit');
    }

    protected static function _is_system(array $actor)
    {
        return ! empty($actor['is_system']) OR in_array('system', $actor['roles'] ?? [], TRUE);
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    protected static function _history($document_id, $event, $note, $actor_id, array $payload = [])
    {
        self::_exec(
            "INSERT INTO prx_391_history (document_id, event, note, actor_id, payload) "
            ."VALUES (".(int) $document_id.", ".self::_q($event).", ".self::_q_or_null($note).", "
            .self::_int_or_null($actor_id).", ".self::_q(json_encode($payload, JSON_UNESCAPED_UNICODE))."::jsonb)"
        );
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
