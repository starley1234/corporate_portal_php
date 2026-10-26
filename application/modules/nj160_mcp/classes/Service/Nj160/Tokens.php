<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj160_mcp: self-service токены — создание/отзыв пользователем на /mcp,
 * проверка в Rpc. Ключ показывается один раз, в БД только sha256.
 */
class Service_Nj160_Tokens {

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
            DB::query(NULL, 'SELECT 1 FROM "nj_160_tokens" LIMIT 1')->execute();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj160_mcp: таблицы токенов недоступны: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Создать токен пользователю. @return [ok, token] — ключ виден один раз. */
    public static function create($user_id, $label)
    {
        self::ensure_tables();

        if ((int) $user_id <= 0)
        {
            return ['error' => 'Нужен вход'];
        }

        $token = 'n2jmcp_'.bin2hex(random_bytes(20));
        $db = Database::instance();

        $db->query(Database::INSERT,
            'INSERT INTO nj_160_tokens (token_hash, label, user_id)
             VALUES ('.$db->quote(hash('sha256', $token)).', '
            .$db->quote(mb_substr(trim((string) $label) ?: 'мой ключ', 0, 120)).', '.(int) $user_id.')', FALSE);

        return ['ok' => TRUE, 'token' => $token];
    }

    /** Отозвать свой токен (безвозвратно). */
    public static function revoke($id, $user_id)
    {
        self::ensure_tables();
        $db = Database::instance();

        $res = $db->query(Database::UPDATE,
            'UPDATE nj_160_tokens SET revoked_at = now()
             WHERE id = '.(int) $id.' AND user_id = '.(int) $user_id.' AND revoked_at IS NULL', FALSE);

        return (is_int($res) AND $res > 0);
    }

    public static function of_user($user_id)
    {
        self::ensure_tables();

        return Database::instance()->query(Database::SELECT,
            'SELECT id, label, calls, last_call_at, created_at, revoked_at
             FROM nj_160_tokens WHERE user_id = '.(int) $user_id.' ORDER BY id DESC', FALSE)->as_array();
    }

    /**
     * Проверка Bearer-токена RPC: активный персональный токен.
     * Возвращает [ok, user_id] (legacy MCP_TOKEN проверяется в контроллере отдельно).
     */
    public static function authenticate($plain_token)
    {
        self::ensure_tables();

        if (strpos((string) $plain_token, 'n2jmcp_') !== 0)
        {
            return [FALSE, NULL];
        }

        $row = Database::instance()->query(Database::SELECT,
            'SELECT id, user_id FROM nj_160_tokens
             WHERE token_hash = '.Database::instance()->quote(hash('sha256', (string) $plain_token))
            .' AND revoked_at IS NULL', FALSE)->current();

        return ($row ? [TRUE, (int) $row['user_id']] : [FALSE, NULL]);
    }

    /** Владелец токена — администратор сайта? (роль admin из roles.) */
    public static function is_admin($user_id)
    {
        if ( ! $user_id)
        {
            return FALSE;
        }

        try
        {
            $row = Database::instance()->query(Database::SELECT,
                'SELECT COUNT(*) AS c FROM roles_users ru
                 JOIN roles r ON r.id = ru.role_id
                 WHERE ru.user_id = '.(int) $user_id." AND r.name = 'admin'", FALSE)->current();

            return $row ? ((int) $row['c'] > 0) : FALSE;
        }
        catch (Throwable $e)
        {
            return FALSE;
        }
    }

    /** Учёт вызова: счётчик + время (ошибки не мешают ответу инструмента). */
    public static function touch($token_hash)
    {
        try
        {
            Database::instance()->query(Database::UPDATE,
                'UPDATE nj_160_tokens SET calls = calls + 1, last_call_at = now()
                 WHERE token_hash = '.Database::instance()->quote((string) $token_hash), FALSE);
        }
        catch (Throwable $e) { /* статистика не критична */ }
    }
}
