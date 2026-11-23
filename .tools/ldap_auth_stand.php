<?php
namespace LdapStand;
/** Изолированный контракт LDAP-драйвера. Не заменяет тест с LDAP-сервером. */
if ( ! defined('SYSPATH')) define('SYSPATH', __DIR__.'/../system/');
foreach (['LDAP_OPT_PROTOCOL_VERSION'=>1, 'LDAP_OPT_REFERRALS'=>2, 'LDAP_OPT_NETWORK_TIMEOUT'=>3,
          'LDAP_OPT_X_TLS_REQUIRE_CERT'=>4, 'LDAP_OPT_X_TLS_DEMAND'=>5, 'LDAP_ESCAPE_FILTER'=>1] as $key=>$value) {
    if ( ! defined($key)) define($key, $value);
}
function function_exists($name) {
    return in_array($name, ['ldap_connect', 'ldap_escape'], TRUE) OR \function_exists($name);
}
class Auth_ORM {
    public $completed = FALSE;
    public $current = NULL;
    protected function complete_login($user) { $this->completed = TRUE; $this->current = $user; }
    public function get_user() { return $this->current; }
}
class FakeUser {
    public $username = 'alice';
    public $exists = TRUE;
    public $role = TRUE;
    public function loaded() { return $this->exists; }
    public function has($name, $role) { return $this->role; }
    public function where($key, $operator, $value) { $this->exists = ($value === $this->username); return $this; }
    public function find() { return $this; }
}
class ORM {
    public static $user;
    public static function factory($name, $params = NULL) { return $name === 'User' ? self::$user : new \stdClass; }
}
$GLOBALS['ldap_test'] = ['calls' => [], 'count' => 1, 'server_password' => 'correct', 'tls' => TRUE, 'service' => TRUE, 'options' => TRUE];
function ldap_set_option($conn, $option, $value) { return $GLOBALS['ldap_test']['options']; }
function ldap_connect($uri) { $GLOBALS['ldap_test']['calls'][] = 'connect'; return (object) []; }
function ldap_start_tls($conn) { $GLOBALS['ldap_test']['calls'][] = 'tls'; return $GLOBALS['ldap_test']['tls']; }
function ldap_bind($conn, $dn, $password) {
    $GLOBALS['ldap_test']['calls'][] = 'bind:'.$dn;
    return $dn === 'cn=reader,dc=example' ? $GLOBALS['ldap_test']['service'] : $password === $GLOBALS['ldap_test']['server_password'];
}
function ldap_search($conn, $base, $filter, $attrs, $only, $limit, $timeout) {
    $GLOBALS['ldap_test']['filter'] = $filter;
    $GLOBALS['ldap_test']['limit'] = $limit;
    return (object) [];
}
function ldap_escape($input, $ignore, $flags) { return str_replace(['\\','*','(',')',"\0"], ['\\5c','\\2a','\\28','\\29','\\00'], $input); }
function ldap_get_entries($conn, $result) { return ['count' => $GLOBALS['ldap_test']['count'], 0 => ['dn' => 'uid=alice,dc=example']]; }
function ldap_unbind($conn) { $GLOBALS['ldap_test']['calls'][] = 'unbind'; return TRUE; }
eval('namespace LdapStand; ?>'.file_get_contents(dirname(__DIR__).'/application/modules/ldap_auth/classes/Auth/Ldap.php'));
class Test_Ldap extends Auth_Ldap {
    public function login_test($name, $password, $remember = FALSE) { return $this->_login($name, $password, $remember); }
    public function verify_test($name, $password) { return $this->verify_ldap($name, $password); }
}
function check_ldap($name, $actual, $expected) {
    global $checks;
    if ($actual !== $expected) { echo "FAIL: $name\n"; exit(1); }
    $checks++;
}
$checks = 0;
foreach (['PRX_LDAP_URI=ldaps://ldap.example:636', 'PRX_LDAP_BASE_DN=dc=example',
    'PRX_LDAP_BIND_DN=cn=reader,dc=example', 'PRX_LDAP_BIND_PASSWORD=service',
    'PRX_LDAP_LOGIN_ATTRIBUTE=uid', 'PRX_LDAP_STARTTLS=0'] as $env) putenv($env);
ORM::$user = new FakeUser;
$auth = new Test_Ldap;
check_ldap('успешный вход', $auth->login_test('alice', 'correct', TRUE), TRUE);
check_ldap('сессия установлена', $auth->completed, TRUE);
check_ldap('неверный пароль', $auth->login_test('alice', 'wrong'), FALSE);
check_ldap('нет локального аккаунта', $auth->login_test('mallory', 'correct'), FALSE);
ORM::$user->role = FALSE;
check_ldap('нет роли login', $auth->login_test('alice', 'correct'), FALSE);
ORM::$user->role = TRUE;
check_ldap('пустой пароль', $auth->login_test('alice', ''), FALSE);
check_ldap('локальный пароль не используется', $auth->check_password('wrong'), FALSE);
check_ldap('текущий LDAP пароль', $auth->check_password('correct'), TRUE);
check_ldap('старые cookie закрыты', $auth->auto_login(), FALSE);
$GLOBALS['ldap_test']['count'] = 2;
check_ldap('неоднозначный LDAP-поиск', $auth->login_test('alice', 'correct'), FALSE);
$GLOBALS['ldap_test']['count'] = 1;
check_ldap('экранирование LDAP-инъекции', $auth->verify_test('a*)(uid=*)', 'correct'), TRUE);
check_ldap('корректный фильтр', $GLOBALS['ldap_test']['filter'], '(uid=a\\2a\\29\\28uid=\\2a\\29)');
check_ldap('ограничение результатов', $GLOBALS['ldap_test']['limit'], 2);
putenv('PRX_LDAP_URI=ldap://ldap.example:389');
check_ldap('незашифрованный LDAP закрыт', $auth->verify_test('alice', 'correct'), FALSE);
putenv('PRX_LDAP_STARTTLS=1');
$GLOBALS['ldap_test']['tls'] = FALSE;
check_ldap('сбой TLS закрывает вход', $auth->verify_test('alice', 'correct'), FALSE);
$GLOBALS['ldap_test']['tls'] = TRUE;
check_ldap('StartTLS работает', $auth->verify_test('alice', 'correct'), TRUE);
$GLOBALS['ldap_test']['service'] = FALSE;
check_ldap('сбой сервисного bind', $auth->verify_test('alice', 'correct'), FALSE);
$GLOBALS['ldap_test']['service'] = TRUE;
putenv('PRX_LDAP_LOGIN_ATTRIBUTE=uid)(cn=*');
check_ldap('небезопасный атрибут', $auth->verify_test('alice', 'correct'), FALSE);
putenv('PRX_LDAP_LOGIN_ATTRIBUTE=uid');
putenv('PRX_LDAP_BIND_PASSWORD=');
check_ldap('пустой сервисный пароль', $auth->verify_test('alice', 'correct'), FALSE);
putenv('PRX_LDAP_BIND_PASSWORD=service');
$GLOBALS['ldap_test']['options'] = FALSE;
check_ldap('невозможно потребовать TLS-сертификат', $auth->verify_test('alice', 'correct'), FALSE);
echo "LDAP: $checks проверок OK\n";
