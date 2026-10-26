<?php defined('SYSPATH') or die('No direct script access.');

/** Генератор закрытого по умолчанию модуля. Не включает его автоматически. */
class Task_Module_Scaffold extends Minion_Task {
    protected $_options = ['id' => NULL, 'slug' => NULL, 'name' => NULL];

    protected function _execute(array $params)
    {
        $id = (int) ($params['id'] ?? 0);
        $slug = (string) ($params['slug'] ?? '');
        $name = trim((string) ($params['name'] ?? $slug));
        if ($id < 300 OR $id > 999 OR ! preg_match('/^[a-z][a-z0-9_]*$/D', $slug))
        {
            throw new InvalidArgumentException('Нужны id=300..999 и slug из a-z, 0-9, _');
        }
        if (glob(APPPATH.'modules/prl'.$id.'_*'))
        {
            throw new RuntimeException('ID модуля уже занят: '.$id);
        }
        foreach ((array) Kohana::$config->load('portal')->get('domains') as $domain)
        {
            foreach ($domain['services'] as $service)
            {
                if (strpos($service['module'], 'prl'.$id.'_') === 0
                    AND $service['module'] !== 'prl'.$id.'_'.$slug)
                {
                    throw new RuntimeException('ID зарезервирован для '.$service['module']);
                }
            }
        }
        $module = 'prl'.$id.'_'.$slug;
        $base = APPPATH.'modules/'.$module;
        $class = 'Prl'.$id;
        $title = var_export($name, TRUE);
        $files = [
            'init.php' => "<?php defined('SYSPATH') or die('No direct script access.');\n"
                ."Route::set('{$module}', '{$slug}')\n"
                ."    ->defaults(['controller' => '{$class}_Page', 'action' => 'index']);\n",
            'config/prl_module.php' => "<?php defined('SYSPATH') or die('No direct script access.');\n"
                ."return ['id' => {$id}, 'slug' => '{$module}', 'title' => {$title},\n"
                ."    'short' => 'Модуль в разработке', 'status' => 'alpha', 'audience' => 'admin'];\n"
                ."// Добавьте href только после реализации и тестов политики доступа.\n",
            'config/'.$module.'.php' => "<?php defined('SYSPATH') or die('No direct script access.');\nreturn [];\n",
            'classes/Controller/'.$class.'/Page.php' => "<?php defined('SYSPATH') or die('No direct script access.');\n"
                ."class Controller_{$class}_Page extends Controller_Base {\n"
                ."    public function before() {\n        parent::before();\n"
                ."        if (! \$this->can('prl{$id}.view')) { throw HTTP_Exception::factory(403); }\n    }\n"
                ."    public function action_index() {\n        \$this->set_title({$title});\n"
                ."        \$this->template->content = View::factory('{$module}/index');\n    }\n}\n",
            'classes/Service/'.$class.'/Module.php' => "<?php defined('SYSPATH') or die('No direct script access.');\n"
                ."/** Владелец таблиц prl_{$id}_*. Проверяйте ACL и оргконтекст здесь. */\n"
                ."class Service_{$class}_Module {\n    // Публичный контракт реализуется вместе с policy и тестами.\n}\n",
            'migrations_pg/pgMigration_Prl_'.$id.'_001_Initial.php' => "<?php defined('SYSPATH') or die('No direct script access.');\n"
                ."class pgMigration_Prl_{$id}_001_Initial extends pgMigration {\n"
                ."    public function up() { /* Добавьте идемпотентную схему prl_{$id}_*. */ }\n"
                ."    public function down() { /* Явный план отката. */ }\n}\n",
            'views/'.$module.'/index.php' => "<?php defined('SYSPATH') or die('No direct script access.'); ?>\n"
                ."<div class=\"prl-panel p-6\"><h1 class=\"prl-h1\"><?= HTML::chars({$title}) ?></h1>\n"
                ."<p class=\"mt-3 text-gray-500\">Модуль в разработке.</p></div>\n",
            'AGENTS.md' => "# {$module}\n\n## Назначение\n{$name}\n\n## Состояние работ\n"
                ."Каркас. Не подключён. Права не выданы, API и бизнес-операций нет.\n\n"
                ."## Контракты\nТаблицы: prl_{$id}_*. Сервис: Service_{$class}_Module.\n"
                ."Право prl{$id}.view — добавить в application/config/acl.php явно.\n"
                ."Объектные политики и оргконтекст проверяются в сервисе.\n\n"
                ."## Проверки\nДобавить unit/integration тесты, запрет гостю и чужой организации.\n",
        ];
        foreach ($files as $relative => $content)
        {
            $path = $base.'/'.$relative;
            if (! is_dir(dirname($path)) AND ! mkdir(dirname($path), 0775, TRUE))
            {
                throw new RuntimeException('Не удалось создать '.dirname($path));
            }
            if (file_put_contents($path, $content) === FALSE)
            {
                throw new RuntimeException('Не удалось записать '.$path);
            }
        }
        Minion_CLI::write("Создан {$module}. Реализуйте схему, policy и тесты; затем подключите в application/config/modules.php и выполните make migrate.");
    }
}
