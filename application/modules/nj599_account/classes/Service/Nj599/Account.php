<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Аккаунт и безопасность (nj599_account): TOTP-двухфакторка, журнал входов,
 * активные сессии с «выйти везде».
 *
 * Схему ядра users НЕ меняем — всё в своих таблицах nj_599_*.
 * Механизм «выйти везде»: каждая сессия регистрируется в nj_599_sessions;
 * kick-метка (nj_599_kicks.after_ts) делает недействительными сессии,
 * созданные раньше неё. Проверку выполняют серверные точки, которые есть
 * на каждой странице (опрос колокольчика /nj597/api/unread раз в 45 с) и
 * страницы nj599/nj597 — при недействительности клиент отправляется на
 * /auth/logout. Строгий серверный гейт в каждый контроллер без правки
 * frozen-модуля erp100_base невозможен — поллинг покрывает практически.
 */
class Service_Nj599_Account
{
    protected static $_tables_ensured = FALSE;

    public static function ensure_tables()
    {
        if (self::$_tables_ensured)
        {
            return;
        }

        self::$_tables_ensured = TRUE;

        // Схема ставится миграциями. DDL на каждом HTTP-запросе был главным
        // источником задержки после включения nj599. Аварийное автосоздание
        // оставлено только как явный режим для первичной диагностики.
        if (trim((string) getenv('NJ599_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        try
        {
            DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_599_totp" (
    "user_id"    BIGINT      PRIMARY KEY,
    "secret"     VARCHAR(64) NOT NULL,
    "enabled_at" TIMESTAMPTZ NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_599_totp" IS 'NJ-599 TOTP: secret base32; enabled_at NULL = настройка не завершена';

CREATE TABLE IF NOT EXISTS "nj_599_logins" (
    "id"         BIGSERIAL PRIMARY KEY,
    "user_id"    BIGINT       NOT NULL,
    "ip"         VARCHAR(64)  NOT NULL DEFAULT '',
    "ua"         VARCHAR(300) NOT NULL DEFAULT '',
    "session_id" VARCHAR(64)  NOT NULL DEFAULT '',
    "via"        VARCHAR(20)  NOT NULL DEFAULT 'password',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_599_logins_user_idx" ON "nj_599_logins" ("user_id", "id" DESC);
COMMENT ON TABLE "nj_599_logins" IS 'NJ-599 журнал входов (хвост 90 дней чистится автоматически)';

CREATE TABLE IF NOT EXISTS "nj_599_sessions" (
    "session_id" VARCHAR(64) PRIMARY KEY,
    "user_id"    BIGINT       NOT NULL,
    "ip"         VARCHAR(64)  NOT NULL DEFAULT '',
    "ua"         VARCHAR(300) NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "last_seen"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_599_sessions_user_idx" ON "nj_599_sessions" ("user_id");
COMMENT ON TABLE "nj_599_sessions" IS 'NJ-599 активные сессии устройств';

CREATE TABLE IF NOT EXISTS "nj_599_kicks" (
    "user_id"  BIGINT      PRIMARY KEY,
    "after_ts" TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_599_kicks" IS 'NJ-599 «выйти везде»: сессии созданные раньше after_ts недействительны';
SQL
            )->execute();
        }
        catch (Throwable $e)
        {
            self::$_tables_ensured = FALSE;
            Kohana::$log->add(Log::WARNING, 'nj599: ensure_tables: :e', [':e' => $e->getMessage()]);
        }
    }

    protected static function _db()
    {
        return Database::instance();
    }

    // ------------------------------------------------------------------ TOTP

    const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Случайный секрет base32 (20 байт ≈ 32 символа). */
    public static function totp_secret()
    {
        $raw = random_bytes(20);
        $out = '';
        $bits = '';

        for ($i = 0; $i < strlen($raw); $i++)
        {
            $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
        }

        for ($i = 0; $i + 5 <= strlen($bits); $i += 5)
        {
            $out .= self::B32[bindec(substr($bits, $i, 5))];
        }

        return $out;
    }

    /** base32 → бинарное. */
    public static function b32_decode($b32)
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', (string) $b32));
        $bits = '';

        for ($i = 0, $n = strlen($b32); $i < $n; $i++)
        {
            $pos = strpos(self::B32, $b32[$i]);

            if ($pos === FALSE)
            {
                return FALSE;
            }

            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $raw = '';

        for ($i = 0; $i + 8 <= strlen($bits); $i += 8)
        {
            $raw .= chr(bindec(substr($bits, $i, 8)));
        }

        return $raw;
    }

    /** Код TOTP для секрета в момент $time (по умолчанию сейчас). */
    public static function totp_code($secret, $time = NULL)
    {
        $key = self::b32_decode($secret);

        if ($key === FALSE OR $key === '')
        {
            return '';
        }

        $counter = (int) floor(($time ?: time()) / 30);
        $bin = pack('N', 0).pack('N', $counter);
        $hmac = hash_hmac('sha1', $bin, $key, TRUE);
        $off = ord(substr($hmac, -1)) & 0x0F;
        $num = (unpack('N', substr($hmac, $off, 4))[1] & 0x7FFFFFFF) % 1000000;

        return sprintf('%06d', $num);
    }

    /** Проверка кода с окном ±1 (пропуск одной «тридцатки»). */
    public static function totp_verify($secret, $code)
    {
        $code = preg_replace('/\D/', '', (string) $code);

        if (strlen($code) !== 6)
        {
            return FALSE;
        }

        $t = time();

        foreach ([-1, 0, 1] as $w)
        {
            if (hash_equals(self::totp_code($secret, $t + $w * 30), $code))
            {
                return TRUE;
            }
        }

        return FALSE;
    }

    /** otpauth:// URI для приложения-аутентификатора. */
    public static function otpauth_uri($secret, $username)
    {
        return 'otpauth://totp/'.rawurlencode('N2J.ru:'.(string) $username)
            .'?secret='.$secret.'&issuer=N2J.ru&algorithm=SHA1&digits=6&period=30';
    }

    /** Строка TOTP пользователя (или NULL). */
    public static function totp_row($user_id)
    {
        self::ensure_tables();
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_599_totp WHERE user_id = '.(int) $user_id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function totp_enabled($user_id)
    {
        $r = self::totp_row($user_id);

        return $r !== NULL AND $r['enabled_at'] !== NULL;
    }

    /** Начать настройку: новый секрет (пока не включено). */
    public static function totp_begin($user_id)
    {
        self::ensure_tables();
        $secret = self::totp_secret();
        $db = self::_db();

        $db->query(Database::SELECT,
            'INSERT INTO nj_599_totp (user_id, secret, enabled_at)
             VALUES ('.(int) $user_id.', '.$db->quote($secret).', NULL)
             ON CONFLICT (user_id) DO UPDATE SET secret = EXCLUDED.secret, enabled_at = NULL', FALSE);

        return $secret;
    }

    /** Включить TOTP: код из приложения верен. */
    public static function totp_enable($user_id, $code)
    {
        $r = self::totp_row($user_id);

        if ($r === NULL)
        {
            return ['error' => 'Сначала нажмите «Начать настройку»'];
        }

        if ( ! self::totp_verify($r['secret'], $code))
        {
            return ['error' => 'Код не подошёл — проверьте время на телефоне и введите актуальный'];
        }

        self::_db()->query(Database::SELECT,
            'UPDATE nj_599_totp SET enabled_at = now() WHERE user_id = '.(int) $user_id, FALSE);

        return ['ok' => TRUE];
    }

    public static function totp_disable($user_id)
    {
        self::ensure_tables();
        self::_db()->query(Database::SELECT, 'DELETE FROM nj_599_totp WHERE user_id = '.(int) $user_id, FALSE);
    }

    // -------------------------------------------------- журнал / сессии / kick

    /** После успешного входа: журнал + регистрация сессии + сброс kick. */
    public static function after_login($user_id, $via = 'password')
    {
        self::ensure_tables();
        $db  = self::_db();
        $sid = self::_sid();
        $ip  = self::_ip();
        $ua  = mb_substr((string) Arr::get($_SERVER, 'HTTP_USER_AGENT', ''), 0, 300);

        $db->query(Database::SELECT,
            'INSERT INTO nj_599_logins (user_id, ip, ua, session_id, via)
             VALUES ('.(int) $user_id.', '.$db->quote($ip).', '.$db->quote($ua).',
                     '.$db->quote($sid).', '.$db->quote(mb_substr((string) $via, 0, 20)).')', FALSE);

        $db->query(Database::SELECT, 'DELETE FROM nj_599_kicks WHERE user_id = '.(int) $user_id, FALSE);
        $db->query(Database::SELECT, 'DELETE FROM nj_599_sessions WHERE user_id = '.(int) $user_id, FALSE);

        self::touch_session($user_id, $sid, $ip, $ua);

        // журнал: держим 90 дней
        $db->query(Database::SELECT,
            'DELETE FROM nj_599_logins WHERE user_id = '.(int) $user_id.'
             AND created_at < now() - interval \'90 days\'', FALSE);
    }

    /** Обновить/создать запись текущей сессии. */
    public static function touch_session($user_id, $sid = NULL, $ip = NULL, $ua = NULL)
    {
        self::ensure_tables();
        $db = self::_db();
        $sid = $sid ?: self::_sid();
        $ip  = $ip ?: self::_ip();
        $ua  = $ua ?: mb_substr((string) Arr::get($_SERVER, 'HTTP_USER_AGENT', ''), 0, 300);

        $db->query(Database::SELECT,
            'INSERT INTO nj_599_sessions (session_id, user_id, ip, ua, created_at, last_seen)
             VALUES ('.$db->quote($sid).', '.(int) $user_id.', '.$db->quote($ip).', '.$db->quote($ua).', now(), now())
             ON CONFLICT (session_id) DO UPDATE SET last_seen = now(), ip = EXCLUDED.ip', FALSE);
    }

    /**
     * Действительна ли сессия пользователя.
     * Семантика: kick-метка убивает все сессии, созданные ДО неё (и отсутствие
     * строки при наличии kick — тоже недействительно). Сессия без строки и без
     * kick — легаси/внешний вход (oauth): принимаем, touch_session её заведёт.
     */
    public static function session_valid($user_id, $sid = NULL)
    {
        self::ensure_tables();
        $db  = self::_db();
        $sid = $sid ?: self::_sid();

        if ($sid === '')
        {
            return TRUE; // сессию не идентифицировать — не блокируем
        }

        // Основной путь — один indexed lookup: сессия соединяется с kick-меткой.
        // Раньше здесь было два SELECT плюс отдельный upsert из Base на каждой странице.
        $sess = $db->query(Database::SELECT,
            'SELECT s.created_at, s.last_seen, k.after_ts
             FROM nj_599_sessions s
             LEFT JOIN nj_599_kicks k ON k.user_id = s.user_id
             WHERE s.session_id = '.$db->quote($sid).'
               AND s.user_id = '.(int) $user_id, FALSE)->current();

        if ($sess)
        {
            // Скобки обязательны: OR слабее присваивания — без них сессия с
            // проставленным after_ts (принудительный выход) считалась невалидной
            // всегда, а не только когда она старше этого времени.
            $valid = (empty($sess['after_ts'])
                OR strtotime((string) $sess['created_at']) > strtotime((string) $sess['after_ts']));

            if ($valid AND strtotime((string) $sess['last_seen']) < time() - 300)
            {
                // Прямой вызов драйвера: UPDATE возвращает int, ->execute() у него нет.
                $db->query(Database::UPDATE,
                    'UPDATE nj_599_sessions SET last_seen = now()
                     WHERE session_id = '.$db->quote($sid).' AND user_id = '.(int) $user_id, FALSE);
            }

            return $valid;
        }

        // Строки нет: отдельная проверка kick нужна только для legacy-сессии.
        $kick = $db->query(Database::SELECT,
            'SELECT 1 AS active FROM nj_599_kicks WHERE user_id = '.(int) $user_id.' LIMIT 1', FALSE)->current();

        if ($kick)
        {
            return FALSE;
        }

        // Легаси/OAuth-сессия без kick принимается и регистрируется один раз.
        self::touch_session((int) $user_id, $sid);

        return TRUE;
    }

    /** Активные сессии (свежие сверху). */
    public static function sessions($user_id)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_599_sessions WHERE user_id = '.(int) $user_id.'
             ORDER BY last_seen DESC LIMIT 20', FALSE)->as_array();
    }

    /** Журнал входов (хвост). */
    public static function logins($user_id, $limit = 20)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_599_logins WHERE user_id = '.(int) $user_id.'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Завершить одну сессию (не текущую — текущую через logout). */
    public static function end_session($user_id, $sid)
    {
        self::ensure_tables();
        self::_db()->query(Database::SELECT,
            'DELETE FROM nj_599_sessions WHERE user_id = '.(int) $user_id.'
             AND session_id = '.self::_db()->quote((string) $sid), FALSE);
    }

    /** «Выйти везде»: kick-метка + удаление всех сессий (включая текущую). */
    public static function logout_everywhere($user_id)
    {
        self::ensure_tables();
        $db = self::_db();
        $db->query(Database::SELECT,
            'INSERT INTO nj_599_kicks (user_id, after_ts) VALUES ('.(int) $user_id.', now())
             ON CONFLICT (user_id) DO UPDATE SET after_ts = now()', FALSE);
        $db->query(Database::SELECT, 'DELETE FROM nj_599_sessions WHERE user_id = '.(int) $user_id, FALSE);
    }

    protected static function _sid()
    {
        return (string) Session::instance()->id();
    }

    protected static function _ip()
    {
        return mb_substr((string) Arr::get($_SERVER, 'HTTP_X_FORWARDED_FOR',
            Arr::get($_SERVER, 'REMOTE_ADDR', '')), 0, 64);
    }
}
