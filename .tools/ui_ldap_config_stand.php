<?php
/** Проверка конфигурации LDAP и отображения очереди без БД. */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');
if ( ! defined('APPPATH')) define('APPPATH', dirname(__DIR__).'/application/');
if ( ! defined('MODPATH')) define('MODPATH', dirname(__DIR__).'/modules/');
class Session { public static $default = 'native'; }
class HTML { public static function chars($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } }
class Security { public static function token() { return 'test-token'; } }
class Date { public static function formatted_time($value, $format) { return $value; } }
function __($key) { static $tr; if ($tr === NULL) $tr = require APPPATH.'modules/prx595_useradmin/i18n/ru.php'; return $tr[$key] ?? $key; }
$checks = 0;
function check_ui($label, $actual, $expected) {
    global $checks;
    if ($actual !== $expected) { echo "FAIL: $label\n"; exit(1); }
    $checks++;
}
putenv('PRX_LDAP_ENABLED=0');
$modules = require APPPATH.'config/modules.php';
$auth = require APPPATH.'config/auth.php';
check_ui('LDAP отключён', isset($modules['ldap_auth']), FALSE);
check_ui('ORM по умолчанию', $auth['driver'], 'ORM');
putenv('PRX_LDAP_ENABLED=1');
$modules = require APPPATH.'config/modules.php';
$auth = require APPPATH.'config/auth.php';
check_ui('LDAP подключён', isset($modules['ldap_auth']), TRUE);
check_ui('LDAP драйвер', $auth['driver'], 'Ldap');
$kpi = []; $channels = []; $new_users = []; $jobs = [];
foreach (['done', 'failed', 'processing', 'pending', 'cancelled', '<script>'] as $n => $status) {
    $jobs[] = ['id'=>$n+1, 'status'=>$status, 'channel'=>'echo', 'visitor_id'=>'anon',
               'created_at'=>'2026-01-01', 'finished_at'=>NULL];
}
ob_start();
require APPPATH.'modules/prx595_useradmin/views/prx595/activity.php';
$html = ob_get_clean();
foreach (['Готово', 'Ошибка', 'Выполняется', 'В очереди', 'Отменена', '&lt;script&gt;'] as $label) {
    check_ui('подпись '.$label, strpos($html, $label) !== FALSE, TRUE);
}
check_ui('неизвестный статус экранирован', strpos($html, '<script>'), FALSE);
$tasks = []; $budget = []; $logged_in = FALSE;
ob_start();
require APPPATH.'modules/prx120_jobs/views/prx120/tasks.php';
$task_html = ob_get_clean();
foreach (['/review', '/jobs', '/docs'] as $href) {
    check_ui('удалена ссылка '.$href, strpos($task_html, 'href="'.$href.'"'), FALSE);
}
check_ui('сохранено пустое состояние', strpos($task_html, 'Пока задач нет') !== FALSE, TRUE);
$errors = [];
putenv('PRX_LDAP_ENABLED=1');
ob_start(); require APPPATH.'views/auth/login.php'; $login_ldap = ob_get_clean();
check_ui('LDAP: нет обещания постоянного входа', strpos($login_ldap, 'name="remember"'), FALSE);
check_ui('LDAP: нет локального сброса пароля', strpos($login_ldap, '/auth/forgot_password'), FALSE);
putenv('PRX_LDAP_ENABLED=0');
ob_start(); require APPPATH.'views/auth/login.php'; $login_orm = ob_get_clean();
check_ui('ORM: постоянный вход на месте', strpos($login_orm, 'name="remember"') !== FALSE, TRUE);
check_ui('ORM: восстановление на месте', strpos($login_orm, '/auth/forgot_password') !== FALSE, TRUE);
echo "Интерфейс и настройка LDAP: $checks проверок OK\n";
