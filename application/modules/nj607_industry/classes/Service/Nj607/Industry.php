<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj607_industry: «Карта отрасли» (авто-отчёт воркером) и «Разведка под запрос»
 * (ручная услуга владельца: заказ → уведомление админу → закрытие с отчётом).
 */
class Service_Nj607_Industry {

    /** Человеческая подпись статуса через i18n; код БД/API не меняется. */
    public static function status_label($status)
    {
        $status = trim((string) $status);

        if ($status === '')
        {
            return '';
        }

        $key   = 'nj607.industry.report.status.'.$status;
        $label = __($key);

        return $label === $key ? $status : $label;
    }

    public static function ensure_tables()
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        $done = TRUE;

        try
        {
            DB::query(NULL, 'SELECT 1 FROM "nj_607_reports" LIMIT 1')->execute();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj607_industry: таблиц нет — примените миграции: :e',
                [':e' => $e->getMessage()]);
        }
    }

    /**
     * Авто-карта отрасли: список игроков, что и как производят, сегменты,
     * цены (где видны), каналы, возможности входа. Платно (cost nj607_industry).
     */
    public static function create_self($user_id, $industry, $region)
    {
        self::ensure_tables();

        if ((int) $user_id <= 0)
        {
            return ['error' => 'Сервис для зарегистрированных — войдите.'];
        }

        $industry = trim((string) $industry);

        if (mb_strlen($industry) < 5)
        {
            return ['error' => 'Укажите отрасль или продукт (от 5 знаков).'];
        }

        // nj601_billing: карта отрасли — 5 кредитов (самый дорогой отчёт)
        $charge = Service_Nj601_Billing::charge('nj607_industry',
            Service_Nj601_Billing::subject_of($user_id));

        if (isset($charge['error']))
        {
            return $charge;
        }

        return self::_insert($user_id, '', 'self', $industry, $region, '');
    }

    /**
     * Заказ «Разведки под запрос» — бесплатно, это заявка на ручную услугу.
     * Админу уходит уведомление nj597 (kind nj607_order).
     */
    public static function create_order($user_id, $visitor_uid, $industry, $region, $contact)
    {
        self::ensure_tables();

        $industry = trim((string) $industry);
        $contact  = trim((string) $contact);

        if (mb_strlen($industry) < 5)
        {
            return ['error' => 'Опишите задачу разведки (от 5 знаков): отрасль, что ищете.'];
        }

        if ($contact === '')
        {
            return ['error' => 'Оставьте контакт (email или телефон) — иначе не сможем вернуть результат.'];
        }

        $res = self::_insert((int) $user_id, $visitor_uid, 'order', $industry, $region, $contact);

        if (isset($res['ok']))
        {
            // уведомление владельцу (user_id 1) — заявку видно и в /nj597, и в почте
            try
            {
                if (class_exists('Service_Nj597_Notify'))
                {
                    Service_Nj597_Notify::notify(1, 'nj607_order',
                        'Заказ разведки: '.mb_substr($industry, 0, 80),
                        'Контакт: '.$contact.($region !== '' ? '; регион: '.$region : ''),
                        '/nj607/admin');
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj607_industry: notify: :e', [':e' => $e->getMessage()]);
            }
        }

        return $res;
    }

    protected static function _insert($user_id, $visitor_uid, $kind, $industry, $region, $contact)
    {
        $db = Database::instance();
        $code = ($kind === 'order' ? 'ord' : 'map').bin2hex(random_bytes(4));

        $db->query(Database::INSERT,
            'INSERT INTO nj_607_reports (code, user_id, visitor_id, kind, industry, region, contact, status)
             VALUES ('.$db->quote($code).', '.((int) $user_id > 0 ? (int) $user_id : 'NULL').', '
            .$db->quote((string) $visitor_uid).', '.$db->quote($kind).', '
            .$db->quote(mb_substr($industry, 0, 300)).', '
            .$db->quote(mb_substr(trim((string) $region), 0, 120)).', '
            .$db->quote(mb_substr($contact, 0, 300)).', '
            .$db->quote($kind === 'order' ? 'new' : 'running').')', FALSE);

        $row = $db->query(Database::SELECT,
            'SELECT id FROM nj_607_reports WHERE code = '.$db->quote($code), FALSE)->current();

        if ($kind === 'self')
        {
            // авто-сбор — задача в очередь (тяжёлое: поиск + чтение + LLM)
            Service_Nj120_JobQueue::push('nj607.industry', [
                'report_id' => (int) $row['id'],
                'code'      => $code,
                'industry'  => $industry,
                'region'    => (string) $region,
            ], ['module' => 'nj607', 'timeout_sec' => 1800]);
        }

        return ['ok' => TRUE, 'code' => $code];
    }

    public static function by_code($code)
    {
        self::ensure_tables();
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_607_reports WHERE code = '.self::_db()->quote((string) $code), FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function list_of($user_id, $limit = 20)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT code, kind, industry, region, status, created_at, finished_at
             FROM nj_607_reports WHERE user_id = '.(int) $user_id.'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    public static function admin_orders($limit = 50)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            "SELECT * FROM nj_607_reports WHERE kind = 'order' ORDER BY id DESC LIMIT ".(int) $limit, FALSE)->as_array();
    }

    public static function admin_self($limit = 50)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            "SELECT * FROM nj_607_reports WHERE kind = 'self' ORDER BY id DESC LIMIT ".(int) $limit, FALSE)->as_array();
    }

    /** Заказ обработан (управление): закрыть. */
    public static function close_order($code)
    {
        $db = self::_db();
        $res = $db->query(Database::UPDATE,
            "UPDATE nj_607_reports SET status = 'closed', finished_at = now(), updated_at = now()
             WHERE code = ".$db->quote((string) $code)." AND kind = 'order'", FALSE);

        return (is_int($res) AND $res > 0);
    }

    // ----------------------------------------------------------------- управление

    /** Все отчёты (управление): кто, когда, статус. */
    public static function admin_overview($limit = 60)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT r.*, u.username AS owner_name
             FROM nj_607_reports r LEFT JOIN users u ON u.id = r.user_id
             ORDER BY r.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Одна строка отчёта для AJAX-админки (подмена без перезагрузки). */
    public static function admin_row($code)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT r.*, u.username AS owner_name
             FROM nj_607_reports r LEFT JOIN users u ON u.id = r.user_id
             WHERE r.code = '.self::_db()->quote((string) $code).'
             LIMIT 1', FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Удалить отчёт (управление). */
    public static function admin_delete($code)
    {
        $db = self::_db();
        $res = $db->query(Database::DELETE,
            'DELETE FROM nj_607_reports WHERE code = '.$db->quote((string) $code), FALSE);

        return (is_int($res) AND $res > 0);
    }

    /** Перезапустить сбор (управление): отчёт снова running, задача — сразу в очередь. */
    public static function admin_restart($code)
    {
        $db = self::_db();
        $r = $db->query(Database::SELECT,
            'SELECT id, code, industry, region FROM nj_607_reports WHERE code = '.$db->quote((string) $code), FALSE)->current();

        if (empty($r))
        {
            return ['error' => 'Отчёт не найден'];
        }

        $db->query(Database::UPDATE,
            "UPDATE nj_607_reports SET status = 'running', error = NULL, progress = '',
                    finished_at = NULL, updated_at = now()
             WHERE id = ".(int) $r['id'], FALSE);

        Service_Nj120_JobQueue::push('nj607.industry', [
            'report_id' => (int) $r['id'],
            'code'      => (string) $r['code'],
            'industry'  => (string) $r['industry'],
            'region'    => (string) $r['region'],
        ], ['module' => 'nj607', 'timeout_sec' => 1800]);

        return ['ok' => TRUE];
    }

    // ------------------------------------------------------- API для воркера

    public static function api_progress($code, $phase)
    {
        $db = self::_db();
        $db->query(Database::UPDATE,
            'UPDATE nj_607_reports SET progress = '.$db->quote(mb_substr((string) $phase, 0, 300))
            .', updated_at = now() WHERE code = '.$db->quote((string) $code), FALSE);

        return ['ok' => TRUE];
    }

    public static function api_result($code, $md, $model_used)
    {
        $db = self::_db();
        $db->query(Database::UPDATE,
            "UPDATE nj_607_reports SET report_md = ".$db->quote((string) $md)
            .", model_used = ".$db->quote(mb_substr((string) $model_used, 0, 120))
            .", status = 'done', error = NULL, finished_at = now(), updated_at = now()
             WHERE code = ".$db->quote((string) $code), FALSE);

        // Дожим очереди (образец nj602): отчёт принят САЙТОМ — задача nj120
        // должна стать «Готово». В задаче нет своей колонки job_id, поэтому
        // ищем её по payload.code (find_for_delivery) и оживляем в том числе
        // свежий failed — свипер мог отказать задачу за секунды до доставки.
        try
        {
            if (class_exists('Service_Nj120_JobQueue'))
            {
                $queue  = Service_Nj120_JobQueue::instance();
                $job_id = method_exists($queue, 'find_for_delivery')
                    ? $queue->find_for_delivery('nj607.industry', 'code', (string) $code)
                    : 0;

                if ($job_id > 0)
                {
                    $queue->mark_delivered($job_id,
                        'Карта отрасли '.$code.' принята сайтом — задача закрыта из приёмки nj607');
                }
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj607: связать задачу с готовым отчётом не удалось: :e',
                [':e' => $e->getMessage()]);
        }

        // Онтология: из готового отчёта делаем СУЩНОСТИ (компании, регионы,
        // сегменты) и связи с объектами других модулей. Отчёт при этом не
        // меняется; сбой разбора задачу не роняет (владелец: «отчёт хороший —
        // его оставь, но и сделай из него сущности, которые надо будет связать»).
        try
        {
            if (class_exists('Service_Nj607_Ontology'))
            {
                $r = $db->query(Database::SELECT,
                    'SELECT id, industry, region FROM nj_607_reports WHERE code = '.$db->quote((string) $code),
                    FALSE)->current();

                if ($r)
                {
                    Service_Nj607_Ontology::sync_report((int) $r['id'], (string) $code,
                        (string) $r['industry'], (string) $r['region'], (string) $md);
                }
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj607: онтология отчёта :c: :e',
                [':c' => (string) $code, ':e' => $e->getMessage()]);
        }

        return ['ok' => TRUE];
    }

    public static function api_fail($code, $error)
    {
        $db = self::_db();
        $db->query(Database::UPDATE,
            "UPDATE nj_607_reports SET status = 'error', error = ".$db->quote(mb_substr((string) $error, 0, 1000))
            .", finished_at = now(), updated_at = now() WHERE code = ".$db->quote((string) $code), FALSE);

        return ['ok' => TRUE];
    }

    protected static function _db()
    {
        return Database::instance();
    }
}
