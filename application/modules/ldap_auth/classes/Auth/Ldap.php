<?php defined('SYSPATH') or die('No direct script access.');

/** LDAP проверяет пароль; локальная учётная запись и роли остаются в Auth ORM. */
class Auth_Ldap extends Auth_ORM
{
    protected function _login($username, $password, $remember)
    {
        // Только точное имя локального аккаунта, без email-алиасов и автосоздания.
        if ( ! is_string($username) OR $username === '' OR strlen($username) > 255
            OR ! is_string($password) OR $password === '') return FALSE;

        $user = ORM::factory('User')->where('username', '=', $username)->find();
        if ( ! $user->loaded() OR (string) $user->username !== $username
            OR ! $user->has('roles', ORM::factory('Role', ['name' => 'login']))) return FALSE;
        if ( ! $this->verify_ldap($username, $password)) return FALSE;

        // Сессия, CSRF и второй фактор остаются в существующем контроллере.
        // Постоянные токены не выдаются: при следующем входе пароль проверяется заново.
        $this->complete_login($user);
        return TRUE;
    }

    /** Старые ORM-cookie не должны обходить LDAP при включении драйвера. */
    public function auto_login()
    {
        return FALSE;
    }

    /** Для проверок текущего пароля — тот же LDAP, не локальный хэш. */
    public function check_password($password)
    {
        $user = $this->get_user();
        return $user AND is_string($password) AND $password !== ''
            AND $this->verify_ldap((string) $user->username, $password);
    }

    protected function verify_ldap(string $username, string $password): bool
    {
        $uri = trim((string) getenv('PRX_LDAP_URI'));
        $base = trim((string) getenv('PRX_LDAP_BASE_DN'));
        $bind_dn = trim((string) getenv('PRX_LDAP_BIND_DN'));
        $bind_password = (string) getenv('PRX_LDAP_BIND_PASSWORD');
        $attribute = trim((string) getenv('PRX_LDAP_LOGIN_ATTRIBUTE')) ?: 'uid';
        $starttls = getenv('PRX_LDAP_STARTTLS') === '1';
        $url = parse_url($uri);

        // Никаких анонимных bind, незашифрованных каналов и управляемых фильтров.
        if ( ! function_exists('ldap_connect') OR ! function_exists('ldap_escape')
            OR ! is_array($url) OR empty($url['host']) OR isset($url['user']) OR isset($url['pass'])
            OR isset($url['path']) OR isset($url['query']) OR isset($url['fragment'])
            OR ! in_array($url['scheme'] ?? '', ['ldap', 'ldaps'], TRUE)
            OR (($url['scheme'] ?? '') === 'ldap' AND ! $starttls)
            OR $base === '' OR $bind_dn === '' OR $bind_password === '' OR $password === ''
            OR ! preg_match('/\A[a-zA-Z][a-zA-Z0-9-]*\z/', $attribute)) return FALSE;

        $connection = NULL;
        try
        {
            // Проверка сертификата обязательна даже при небезопасном ldap.conf.
            if ( ! defined('LDAP_OPT_X_TLS_REQUIRE_CERT') OR ! defined('LDAP_OPT_X_TLS_DEMAND')
                OR ! @ldap_set_option(NULL, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND)) return FALSE;
            $connection = @ldap_connect($uri);
            if ( ! $connection) return FALSE;
            if ( ! @ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3)
                OR ! @ldap_set_option($connection, LDAP_OPT_REFERRALS, 0)
                OR ! @ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 5)) return FALSE;
            if (($url['scheme'] ?? '') === 'ldap' AND ! @ldap_start_tls($connection)) return FALSE;
            if ( ! @ldap_bind($connection, $bind_dn, $bind_password)) return FALSE;

            $filter = '('.$attribute.'='.ldap_escape($username, '', LDAP_ESCAPE_FILTER).')';
            $result = @ldap_search($connection, $base, $filter, ['1.1'], 0, 2, 5);
            if ( ! $result) return FALSE;
            $entries = @ldap_get_entries($connection, $result);
            if ( ! is_array($entries) OR ($entries['count'] ?? 0) !== 1
                OR empty($entries[0]['dn'])) return FALSE;

            return (bool) @ldap_bind($connection, $entries[0]['dn'], $password);
        }
        catch (Throwable $e)
        {
            // Fail closed: не писать пароли, DN или ответы сервера в журнал.
            return FALSE;
        }
        finally
        {
            if ($connection) @ldap_unbind($connection);
        }
    }
}
