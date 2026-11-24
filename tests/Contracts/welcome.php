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

require __DIR__.'/../../application/classes/Controller/Welcome.php';

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
