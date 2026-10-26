<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Robokassa (nj601_billing): счета и проверка платежей — фундамент будущей
 * монетизации модулей. Протокол: docs.robokassa.ru
 *   * инициализация: GET auth.robokassa.ru/Merchant/Index.aspx,
 *     подпись md5("Login:OutSum:InvId:Password1");
 *   * ResultURL (оповещение): подпись md5("OutSum:InvId:Password2"), ответ OK$InvId;
 *   * SuccessURL: md5("OutSum:InvId:Password1").
 * Receipt/Shp/ResultUrl2 не используем (v1) — формула подписи простая.
 * Result URL задаётся в кабинете Robokassa: https://n2j.ru/billing/result
 * Success/Fail передаются в запросе явно (в подписи не участвуют).
 */
class Service_Nj601_Billing
{
    public static function ensure_tables()
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_601_invoices" (
    "id"          BIGSERIAL PRIMARY KEY,
    "visitor_id"  VARCHAR(64)  NOT NULL DEFAULT '',
    "user_id"     BIGINT       NULL,
    "service"     VARCHAR(60)  NOT NULL DEFAULT '',
    "description" VARCHAR(200) NOT NULL DEFAULT '',
    "amount_kop"  INTEGER      NOT NULL DEFAULT 0,
    "email"       VARCHAR(120) NOT NULL DEFAULT '',
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "out_sum"     VARCHAR(20)  NOT NULL DEFAULT '',
    "fee"         VARCHAR(20)  NOT NULL DEFAULT '',
    "raw"         JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "paid_at"     TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_601_invoices_visitor_idx" ON "nj_601_invoices" ("visitor_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_601_invoices_status_idx" ON "nj_601_invoices" ("status", "id" DESC);
SQL
        )->execute();

        $done = TRUE;
    }

    /** env полным слагом модуля. */
    public static function env($key, $default = '')
    {
        return trim((string) getenv('NJ601_ROBOKASSA_'.$key)) ?: $default;
    }

    // =====================================================================
    // nj601_billing: кредиты AI-операций (таблицы — миграция 002)
    // =====================================================================

    /** Субъект кредитов: 'u:<user_id>' (вход) или 'g:<ubid>' (анон по куке). */
    public static function subject_of($user_id, $visitor_uid = '')
    {
        if ((int) $user_id > 0)
        {
            return 'u:'.(int) $user_id;
        }

        return ($visitor_uid !== '' ? $visitor_uid : Visitor::uid());
    }

    /** Текущий субъект сессии (для контроллеров). */
    public static function subject_me()
    {
        $user = Auth::instance()->get_user();

        return self::subject_of($user ? (int) $user->id : 0);
    }

    /** Прайс: цена операции модуля в кредитах (0 = бесплатно/beta). */
    /**
     * Глобальный выключатель монетизации (nj601_billing.enabled).
     * NJ601_BILLING_ENABLED=0 в .env — сервис работает полностью бесплатно:
     * списания не происходят и бесплатная норма не тратится. Пока аудитории
     * нет — удобно держать 0; включать, когда появятся платящие.
     */
    public static function enabled()
    {
        return trim((string) getenv('NJ601_BILLING_ENABLED')) !== '0';
    }

    public static function cost($module_slug)
    {
        // монетизация выключена — для пользователя всё стоит 0
        if ( ! self::enabled())
        {
            return 0;
        }

        return (int) (Kohana::$config->load('nj601_billing.cost.'.$module_slug) ?: 0);
    }

    /** Строка баланса; субъекту при первом обращении начисляется бесплатная норма. */
    public static function state($subject)
    {
        self::ensure_tables();
        $db = Database::instance();
        $subject = self::_norm_subject($subject);
        $month = date('Y-m');

        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_601_credits WHERE subject = '.$db->quote($subject), FALSE)->current();

        if ($row)
        {
            $row = (array) $row;

            // наступил новый месяц — бесплатная норма обновляется
            if ($row['free_month'] !== $month)
            {
                $free = (int) Kohana::$config->load('nj601_billing.free_per_month');
                $db->query(Database::UPDATE,
                    'UPDATE nj_601_credits SET free_month = '.$db->quote($month)
                    .', free_left = '.$free.', updated_at = now() WHERE subject = '.$db->quote($subject), FALSE);

                $row['free_month'] = $month;
                $row['free_left']  = $free;
            }

            return $row;
        }

        // первый визит: строка с бесплатной нормой (мгновенный триал)
        $free = (int) Kohana::$config->load('nj601_billing.free_per_month');
        $db->query(Database::INSERT,
            'INSERT INTO nj_601_credits (subject, balance, free_month, free_left)
             VALUES ('.$db->quote($subject).', 0, '.$db->quote($month).', '.$free.')', FALSE);

        return ['subject' => $subject, 'balance' => 0, 'free_month' => $month, 'free_left' => $free];
    }

    /**
     * Списать кредит за операцию модуля. Вызывают create()-методы сервисов.
     * Порядок: бесплатная норма месяца → баланс → отказ.
     *
     * @return  array  ['ok'=>TRUE,...] или ['error' => текст для пользователя]
     */
    public static function charge($module_slug, $subject = NULL, $note = '')
    {
        $cost = self::cost($module_slug);

        if ($cost <= 0)
        {
            return ['ok' => TRUE, 'cost' => 0, 'free' => FALSE]; // beta/вне тарифа
        }

        $subject = self::_norm_subject($subject ?: self::subject_me());
        $s = self::state($subject);

        // 1) бесплатная норма текущего месяца
        if ((int) $s['free_left'] > 0)
        {
            $db = Database::instance();
            $db->query(Database::UPDATE,
                'UPDATE nj_601_credits SET free_left = free_left - 1, updated_at = now()
                 WHERE subject = '.$db->quote($subject).' AND free_left > 0', FALSE);

            self::_ledger($subject, 0, (int) $s['balance'], $module_slug, 'бесплатная норма');

            return ['ok' => TRUE, 'cost' => 0, 'free' => TRUE, 'left' => (int) $s['free_left'] - 1];
        }

        // 2) платный баланс (условие в WHERE — защита от гонки)
        if ((int) $s['balance'] >= $cost)
        {
            $db = Database::instance();
            $res = $db->query(Database::UPDATE,
                'UPDATE nj_601_credits SET balance = balance - '.$cost.', updated_at = now()
                 WHERE subject = '.$db->quote($subject).' AND balance >= '.$cost, FALSE);

            if (is_int($res) AND $res > 0)
            {
                self::_ledger($subject, -$cost, (int) $s['balance'] - $cost, $module_slug, $note ?: 'операция');

                return ['ok' => TRUE, 'cost' => $cost, 'left' => (int) $s['balance'] - $cost];
            }
        }

        // 3) не хватает — честный отказ с путём решения
        return ['error' => 'Бесплатный лимит на месяц исчерпан, кредитов не хватает (нужно '.$cost.'). '
            .'Пополните баланс на /billing — это поддержит развитие сервиса.'];
    }

    /** Начислить кредиты (пакет, управление, компенсация). */
    public static function grant($subject, $credits, $module = 'nj601_billing', $note = '', $invoice_id = NULL)
    {
        $credits = (int) $credits;

        if ($credits <= 0)
        {
            return FALSE;
        }

        $s  = self::state($subject);
        $db = Database::instance();
        $db->query(Database::UPDATE,
            'UPDATE nj_601_credits SET balance = balance + '.$credits.', updated_at = now()
             WHERE subject = '.$db->quote($s['subject']), FALSE);

        self::_ledger($s['subject'], $credits, (int) $s['balance'] + $credits, $module, $note, $invoice_id);

        return TRUE;
    }

    /** Покупка пакета: счёт Robokassa, ключ пакета — в поле service ('credits:<pack>'). */
    public static function buy_pack($pack_key)
    {
        $packs = (array) Kohana::$config->load('nj601_billing.packs');

        if ( ! isset($packs[$pack_key]))
        {
            return ['error' => 'Неизвестный пакет'];
        }

        $p = $packs[$pack_key];

        return self::create_invoice((int) $p['price'] * 100, $p['label'], 'credits:'.$pack_key);
    }

    /** Начисление после оплаты: process_result вызывает с полным рядом счёта. */
    public static function grant_from_invoice(array $invoice)
    {
        if (strpos((string) ($invoice['service'] ?? ''), 'credits:') !== 0
            OR empty($invoice['visitor_id']))
        {
            return;
        }

        $packs = (array) Kohana::$config->load('nj601_billing.packs');
        $pack  = substr((string) $invoice['service'], 8);

        if (isset($packs[$pack]))
        {
            self::grant($invoice['visitor_id'], (int) $packs[$pack]['credits'],
                'nj601_billing', 'оплачен пакет '.$pack, (int) $invoice['id']);
        }
    }

    /** Журнал движения (ошибки журнала не роняют операцию). */
    protected static function _ledger($subject, $delta, $balance_after, $module, $note, $invoice_id = NULL)
    {
        try
        {
            $db = Database::instance();
            $db->query(Database::INSERT,
                'INSERT INTO nj_601_ledger (subject, delta, balance_after, module, note, invoice_id)
                 VALUES ('.$db->quote($subject).', '.(int) $delta.', '.(int) $balance_after.', '
                .$db->quote(mb_substr((string) $module, 0, 40)).', '
                .$db->quote(mb_substr((string) $note, 0, 200)).', '
                .($invoice_id ? (int) $invoice_id : 'NULL').')', FALSE);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj601_billing: ledger: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Субъект к канонической форме. */
    protected static function _norm_subject($subject)
    {
        $subject = trim((string) $subject);

        return mb_substr($subject, 0, 70) ?: 'g:unknown';
    }

    /** История операций субъекта (страница /billing). */
    public static function history($subject, $limit = 20)
    {
        self::ensure_tables();
        $db = Database::instance();

        return $db->query(Database::SELECT,
            'SELECT delta, balance_after, module, note, created_at FROM nj_601_ledger
             WHERE subject = '.$db->quote(self::_norm_subject($subject))
            .' ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    public static function configured()
    {
        return self::env('LOGIN') !== '' AND self::env('PASSWORD1') !== '' AND self::env('PASSWORD2') !== '';
    }

    public static function is_test()
    {
        return self::env('IS_TEST', '1') === '1';
    }

    /** Копейки → формат Robokassa «123.45». */
    public static function out_sum($amount_kop)
    {
        return number_format(((int) $amount_kop) / 100, 2, '.', '');
    }

    /**
     * Создать счёт. $service — слаг будущей монетизации (например «nj550.pack»).
     * Возвращает массив [invoice, pay_url] или [error].
     */
    public static function create_invoice($amount_kop, $description, $service = '', $email = '')
    {
        self::ensure_tables();

        if ( ! self::configured())
        {
            return ['error' => 'Robokassa не настроена: задайте NJ601_ROBOKASSA_LOGIN/PASSWORD1/PASSWORD2 в .env'];
        }

        $amount_kop = (int) $amount_kop;

        if ($amount_kop < 1000 OR $amount_kop > 50000000)
        {
            return ['error' => 'Сумма вне диапазона (от 10 до 500 000 рублей)'];
        }

        $description = mb_substr(trim((string) $description) ?: 'Оплата сервисов N2J.ru', 0, 100);
        $visitor = Visitor::uid();
        $user_id = (strncmp($visitor, 'u:', 2) === 0) ? (int) substr($visitor, 2) : NULL;
        $db = Database::instance();

        $res = $db->query(Database::INSERT,
            'INSERT INTO nj_601_invoices (visitor_id, user_id, service, description, amount_kop, email, out_sum)
             VALUES ('.$db->quote($visitor).', '.($user_id === NULL ? 'NULL' : $user_id).', '
                .$db->quote(mb_substr((string) $service, 0, 60)).', '.$db->quote($description).',
                '.$amount_kop.', '.$db->quote(mb_substr((string) $email, 0, 120)).',
                '.$db->quote(self::out_sum($amount_kop)).') RETURNING id', FALSE)->current();

        if ( ! $res)
        {
            return ['error' => 'Не удалось создать счёт — попробуйте позже'];
        }

        $inv_id = (int) $res['id'];

        return [
            'invoice_id' => $inv_id,
            'pay_url'    => self::pay_url($inv_id, $amount_kop, $description, $email),
        ];
    }

    /** URL оплаты (подпись v1: Login:OutSum:InvId:Password1 — по докам Robokassa). */
    public static function pay_url($inv_id, $amount_kop, $description, $email = '')
    {
        $login  = self::env('LOGIN');
        $pass1  = self::env('PASSWORD1');
        $out    = self::out_sum($amount_kop);
        $sig    = md5($login.':'.$out.':'.(int) $inv_id.':'.$pass1);
        $base   = self::is_test()
            ? 'https://test.robokassa.ru/Merchant/Index.aspx'
            : 'https://auth.robokassa.ru/Merchant/Index.aspx';

        return $base.'?'.http_build_query([
            'MerchantLogin'  => $login,
            'OutSum'         => $out,
            'InvId'          => (int) $inv_id,
            'Description'    => (string) $description,
            'SignatureValue' => $sig,
            'Culture'        => 'ru',
            'Encoding'       => 'utf-8',
            'SuccessUrl'     => URL::site('/billing/success', TRUE),
            'FailUrl'        => URL::site('/billing/fail', TRUE),
        ] + (self::is_test() ? ['IsTest' => '1'] : [])
          + (trim((string) $email) !== '' ? ['Email' => mb_substr(trim((string) $email), 0, 100)] : []));
    }

    /** ResultURL: сверка md5("OutSum:InvId:Password2"), фиксация оплаты. */
    public static function process_result($out_sum, $inv_id, $signature, array $raw = [])
    {
        self::ensure_tables();

        $inv_id = (int) $inv_id;
        $expected = strtoupper(md5((string) $out_sum.':'.$inv_id.':'.self::env('PASSWORD2')));

        if (strtoupper((string) $signature) !== $expected)
        {
            Kohana::$log->add(Log::WARNING, 'nj601: result bad sign inv=:i', [':i' => $inv_id]);

            return ['error' => 'bad sign'];
        }

        $db  = Database::instance();
        $row = $db->query(Database::SELECT,
            'SELECT id, status, amount_kop, out_sum, service, visitor_id FROM nj_601_invoices WHERE id = '.$inv_id, FALSE)->current();

        if ($row === NULL)
        {
            return ['error' => 'invoice not found'];
        }

        // сумма должна совпасть. Robokassa шлёт OutSum строкой с переменным
        // числом знаков («100.000000» в уведомлении против «100.00» при старте),
        // поэтому сверяем ЧИСЛА, а не строки — иначе оплата не зачтётся.
        if (abs((float) str_replace(',', '.', (string) $out_sum) - ((int) $row['amount_kop'] / 100)) > 0.005)
        {
            Kohana::$log->add(Log::WARNING, 'nj601: result sum mismatch inv=:i', [':i' => $inv_id]);

            return ['error' => 'sum mismatch'];
        }

        $already = ($row['status'] === 'paid');

        if ( ! $already)
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_601_invoices SET status = '.$db->quote('paid').',
                     paid_at = now(), fee = '.$db->quote((string) ($raw['fee'] ?? '')).',
                     raw = '.$db->quote(json_encode($raw, JSON_UNESCAPED_UNICODE)).'
                 WHERE id = '.$inv_id, FALSE);

            // nj601_billing: оплачен пакет кредитов — начисляем на субъект счёта
            try
            {
                self::grant_from_invoice([
                    'id'         => $inv_id,
                    'service'    => (string) $row['service'],
                    'visitor_id' => (string) $row['visitor_id'],
                ]);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj601_billing: grant: :e', [':e' => $e->getMessage()]);
            }

            // событие для будущей монетизации модулей (шина платформы)
            if (class_exists('Service_Erp208_EventBus'))
            {
                try
                {
                    Service_Erp208_EventBus::instance()->publish('nj601.invoice_paid', [
                        'invoice_id' => $inv_id,
                        'out_sum'    => (string) $out_sum,
                        'amount_kop' => (int) $row['amount_kop'],
                        'service'    => '',
                        'visitor_id' => '',
                    ]);
                }
                catch (Throwable $e) { /* событие не блокирует ответ Robokassa */ }
            }
        }
        else
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_601_invoices SET raw = '.$db->quote(json_encode($raw, JSON_UNESCAPED_UNICODE))
                .' WHERE id = '.$inv_id, FALSE);
        }

        return ['ok' => TRUE, 'already' => $already];
    }

    /** SuccessURL: сверка md5("OutSum:InvId:Password1"). */
    public static function verify_success($out_sum, $inv_id, $signature)
    {
        $expected = strtoupper(md5((string) $out_sum.':'.(int) $inv_id.':'.self::env('PASSWORD1')));

        return strtoupper((string) $signature) === $expected;
    }

    /** Счёт по id (для success/fail страниц). */
    public static function invoice($inv_id)
    {
        self::ensure_tables();

        return Database::instance()->query(Database::SELECT,
            'SELECT * FROM nj_601_invoices WHERE id = '.(int) $inv_id, FALSE)->current();
    }

    /** Последние счета (админка). */
    public static function latest($limit = 50)
    {
        self::ensure_tables();

        return Database::instance()->query(Database::SELECT,
            'SELECT i.*, coalesce(us.username, i.visitor_id) AS owner
             FROM nj_601_invoices i
             LEFT JOIN users us ON us.id = i.user_id
             ORDER BY i.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Сводка (админка): оплачено счетов/сумма. */
    public static function stats()
    {
        self::ensure_tables();
        $db = Database::instance();

        $r = $db->query(Database::SELECT,
            "SELECT count(*) FILTER (WHERE status = 'paid') AS paid_cnt,
                    coalesce(sum(amount_kop) FILTER (WHERE status = 'paid'), 0) AS paid_kop,
                    count(*) AS total
             FROM nj_601_invoices", FALSE)->current();

        return $r ? [
            'paid_cnt' => (int) $r['paid_cnt'],
            'paid_rub' => number_format(((int) $r['paid_kop']) / 100, 2, ',', ' '),
            'total'    => (int) $r['total'],
        ] : ['paid_cnt' => 0, 'paid_rub' => '0,00', 'total' => 0];
    }
}
