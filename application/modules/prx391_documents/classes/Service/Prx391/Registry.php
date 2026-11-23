<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Чтение реестра документов prx391: список, карточка, справочники, сводка.
 *
 * Выделено из `Service_Prx391_Documents`, чтобы запись карточки и чтение
 * реестра менялись независимо. Публичный фасад `Documents` делегирует сюда,
 * поэтому контроллеры и соседние модули продолжают работать без правок.
 *
 * Контракт: все методы только читают БД, кроме загрузки `field_events` и
 * `history` в карточку. Права проверяются тем же `can_view()` владельца.
 */
class Service_Prx391_Registry
{
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
            FROM prx_391_documents d
            LEFT JOIN prx_391_types t ON t.id = d.type_id
            LEFT JOIN prx_391_correspondents c ON c.id = d.correspondent_id
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
                   (SELECT count(*) FROM prx_391_field_events f WHERE f.document_id = d.id AND f.source = 'llm') AS llm_events
            FROM prx_391_documents d
            LEFT JOIN prx_391_types t ON t.id = d.type_id
            LEFT JOIN prx_391_correspondents c ON c.id = d.correspondent_id
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
            FROM prx_391_documents d
            LEFT JOIN prx_391_types t ON t.id = d.type_id
            LEFT JOIN prx_391_correspondents c ON c.id = d.correspondent_id
            WHERE d.id = ".(int) $id."
        ");

        if ( ! $row OR ! Service_Prx391_Documents::can_view($row, $actor))
        {
            return NULL;
        }

        $row['field_events'] = self::_all("
            SELECT * FROM prx_391_field_events WHERE document_id = ".(int) $id." ORDER BY created_at DESC, id DESC LIMIT 50
        ");
        $row['history'] = self::_all("
            SELECT * FROM prx_391_history WHERE document_id = ".(int) $id." ORDER BY created_at DESC, id DESC LIMIT 50
        ");

        if ( ! empty($row['source_message_id']))
        {
            $row['message'] = Service_Prx390_Intake::message((int) $row['source_message_id']);
        }
        else
        {
            $row['message'] = NULL;
        }

        return $row;
    }

    public static function types()
    {
        return self::_all("SELECT * FROM prx_391_types WHERE is_active = 1 ORDER BY sort, title");
    }

    public static function correspondents($limit = 200)
    {
        return self::_all("SELECT * FROM prx_391_correspondents ORDER BY lower(name) LIMIT ".(int) $limit);
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
            FROM prx_391_documents
            WHERE ".$w."
        ");

        foreach ($row as $k => $v)
        {
            $row[$k] = (int) $v;
        }

        return $row;
    }

    /** Проверяет только переданные ID; порядок и уникальность сохраняются. */
    public static function visible_ids(array $ids, array $actor = [])
    {
        $candidates = [];
        foreach ($ids as $id)
        {
            if ( ! is_int($id) AND ! (is_string($id) AND ctype_digit($id)))
            {
                continue;
            }
            $id = (int) $id;
            if ($id > 0)
            {
                $candidates[$id] = $id;
            }
        }
        if ( ! $candidates)
        {
            return [];
        }

        $visible = [];
        foreach (self::_all('SELECT id, sensitivity FROM prx_391_documents WHERE id IN ('.implode(',', $candidates).')') as $doc)
        {
            if (Service_Prx391_Documents::can_view($doc, $actor))
            {
                $visible[(int) $doc['id']] = TRUE;
            }
        }

        return array_values(array_filter($candidates, function ($id) use ($visible) {
            return isset($visible[$id]);
        }));
    }

    public static function recent_history($limit = 20, array $actor = [])
    {
        if ( ! Service_Prx391_Documents::can_view(['sensitivity' => 'public'], $actor))
        {
            return [];
        }
        $where = Service_Prx391_Documents::can_view_all($actor)
            ? '' : "WHERE d.sensitivity IN ('public', 'internal')";

        return self::_all("
            SELECT h.*, d.reg_no, d.subject
            FROM prx_391_history h
            JOIN prx_391_documents d ON d.id = h.document_id
            ".$where."
            ORDER BY h.created_at DESC, h.id DESC
            LIMIT ".max(0, (int) $limit)."
        ");
    }

    // ------------------------------------------------------------------
    // Внутреннее: права и мини-слой БД
    // ------------------------------------------------------------------

    protected static function _can_admin(array $actor)
    {
        if ( ! empty($actor['is_system']) OR in_array('system', $actor['roles'] ?? [], TRUE))
        {
            return TRUE;
        }

        return (new Prx_Actor(max(1, (int) ($actor['user_id'] ?? 0)), $actor['roles'] ?? []))->can('prx391.admin');
    }

    protected static function _db()
    {
        return Database::instance();
    }

    protected static function _q($value)
    {
        return self::_db()->quote((string) $value);
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
}
