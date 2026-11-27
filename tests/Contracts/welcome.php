<?php

define('SYSPATH', __DIR__);

class Controller_Base
{
    public $template;
    public $permissions = [];
    public function set_title($title) {}
    public function can($permission) { return in_array($permission, $this->permissions, TRUE); }
}

class Service_Prx110_Catalog
{
    public static function visible_modules()
    {
        return [
            ['module_name' => 'prx395_document_extract', 'acl' => 'prx395.ui', 'title' => 'PDF', 'href' => '/pdf'],
            ['module_name' => 'prx391_documents', 'acl' => 'prx391.view', 'title' => 'Registry', 'href' => '/registry'],
            ['module_name' => 'prx393_workflow', 'title' => 'No ACL', 'href' => '/workflow'],
        ];
    }

    public static function plan_modules()
    {
        return self::visible_modules();
    }
}

class Kohana
{
    public static $modules = ['prx120_jobs' => '/jobs'];
    public static function modules() { return self::$modules; }
}

class View
{
    public static function factory($name, $data) { return $data; }
}

class Arr
{
    public static function get($array, $key, $default = NULL) { return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default; }
}

require __DIR__.'/../../application/classes/Prx/Hooks.php';
require __DIR__.'/../../application/classes/Controller/Welcome.php';

/** Хуки — как их регистрируют init.php модулей prx110/prx120/prx599 (ядро слагов не знает). */
function welcome_modules(bool $account = FALSE): void
{
    Prx_Hooks::reset();
    Prx_Hooks::on('nav.catalog_plan', fn () => Service_Prx110_Catalog::plan_modules());
    Prx_Hooks::on('nav.welcome_plan', fn () => [
        'work'  => ['prx395_document_extract', 'prx597_notifications'],
        'stand' => ['prx390_intake', 'prx391_documents', 'prx392_analysis', 'prx393_workflow'],
    ]);
    Prx_Hooks::on('nav.monitor_acl', fn () => 'prx120.monitor');
    if ($account) Prx_Hooks::on('account.security_link', fn () => '/account/security');
}
welcome_modules();

$controller = new Controller_Welcome();
$controller->template = new stdClass();
foreach ([
    [[], ['/services'], [], FALSE],
    [['prx395.ui'], ['/services', '/pdf'], [], FALSE],
    [['prx391.view', 'prx393.view'], ['/services'], ['/registry'], FALSE],
    [['prx120.monitor'], ['/services'], [], TRUE],
] as [$permissions, $work, $stand, $monitor])
{
    $controller->permissions = $permissions;
    $controller->action_index();
    $page = $controller->template->content;
    if (array_column($page['work'], 'href') !== $work
        || array_column($page['stand'], 'href') !== $stand
        || $page['can_monitor'] !== $monitor)
    {
        fwrite(STDERR, 'Unexpected home links for permissions: '.implode(', ', $permissions).PHP_EOL);
        exit(1);
    }
}

// Модуль аккаунта подключён — ссылка «Мой аккаунт» в рабочей колонке.
welcome_modules(TRUE);
$controller->permissions = [];
$controller->action_index();
if (array_column($controller->template->content['work'], 'href') !== ['/services', '/account/security'])
{
    fwrite(STDERR, 'Account link from hook is missing'.PHP_EOL);
    exit(1);
}

// Ни одного модуля: только «Все сервисы», без стенда и монитора даже с правом.
Prx_Hooks::reset();
$controller->permissions = ['prx395.ui', 'prx391.view', 'prx120.monitor'];
$controller->action_index();
$page = $controller->template->content;
if (array_column($page['work'], 'href') !== ['/services'] || $page['stand'] !== [] || $page['can_monitor'] !== FALSE)
{
    fwrite(STDERR, 'Core home depends on modules that are not connected'.PHP_EOL);
    exit(1);
}

echo "Welcome: links by ACL and extension points OK\n";
