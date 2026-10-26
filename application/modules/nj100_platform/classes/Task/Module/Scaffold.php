<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Генератор скелета модуля: module:scaffold --id=401 --slug=tasks --name="Задачи"
 *
 * Создаёт application/modules/nj<id>_<slug> со стандартной структурой:
 * init.php, config/, migrations_pg/, classes/{Controller,Service,Model}/Erp<id>/,
 * views/ и AGENTS.md — карточка модуля (поддерживать актуальной!).
 * README.md намеренно НЕ создаётся: у модуля один документ. README
 * заводят вручную, если модуль дорос примерно до 1000 строк кода
 * (решение 28.09.2026, docs/PLAN-2026-09-28.md п. 5.5).
 *
 * Существующие файлы не перезаписываются: если каталог модуля уже есть — выход.
 */
class Task_Module_Scaffold extends Minion_Task {

    protected $_options = [
        'id'    => NULL,
        'slug'  => NULL,
        'name'  => NULL,
    ];

    protected function _execute(array $params)
    {
        $id    = (int) ($params['id'] ?? 0);
        $slug  = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($params['slug'] ?? '')));
        $name  = (string) ($params['name'] ?? $slug);

        if ($id < 100 OR $slug === '')
        {
            return Minion_CLI::write("Использование: bin/console module:scaffold --id=401 --slug=tasks --name=\"Задачи\"");
        }

        $module    = "nj{$id}_{$slug}";
        $base      = APPPATH.'modules/'.$module;
        $camel     = str_replace(' ', '_', ucwords(str_replace('_', ' ', $slug)));
        $NjId      = "Nj{$id}";

        if (is_dir($base))
        {
            return Minion_CLI::write("Модуль {$module} уже существует: {$base}");
        }

        $dirs = [
            $base,
            $base.'/config',
            $base.'/migrations_pg',
            $base.'/classes/Controller/'.$NjId,
            $base.'/classes/Service/'.$NjId,
            $base.'/classes/Model/'.$NjId,
            $base.'/views/'.$id,
        ];
        foreach ($dirs as $dir)
        {
            if ( ! mkdir($dir, 0775, TRUE) AND ! is_dir($dir))
            {
                return Minion_CLI::write("Не удалось создать каталог: {$dir}");
            }
        }

        $files = [
            // --- init.php ---------------------------------------------------
            $base.'/init.php' => $this->_tpl_init($id, $slug, $NjId, $camel),
            // --- конфиг -----------------------------------------------------
            $base."/config/{$module}.php" => $this->_tpl_config($module, $name),
            // --- миграция ---------------------------------------------------
            $base.'/migrations_pg/'."pgMigration_Nj_{$id}_001_{$camel}_CreateTables.php"
                => $this->_tpl_migration($id, $slug, $camel),
            // --- контроллер-страницы ------------------------------------------
            $base.'/classes/Controller/'.$NjId.'/'.$camel.'.php'
                => $this->_tpl_controller($id, $slug, $name, $NjId, $camel),
            // --- API ----------------------------------------------------------
            $base.'/classes/Controller/'.$NjId.'/Api.php'
                => $this->_tpl_api($id, $NjId),
            // --- виджеты --------------------------------------------------------
            $base.'/classes/Controller/'.$NjId.'/Widget.php'
                => $this->_tpl_widget($id, $NjId, $camel),
            // --- сервис ----------------------------------------------------------
            $base.'/classes/Service/'.$NjId.'/'.$camel.'.php'
                => $this->_tpl_service($id, $slug, $NjId, $camel),
            // --- модель ----------------------------------------------------------
            $base.'/classes/Model/'.$NjId.'/'.$camel.'.php'
                => $this->_tpl_model($id, $slug, $NjId, $camel),
            // --- вьюха ------------------------------------------------------------
            $base.'/views/'.$id.'/index.php' => $this->_tpl_view($name),
            // --- AGENTS.md -----------------------------------------------------------
            $base.'/AGENTS.md' => $this->_tpl_agents($module, $name, $id, $slug),
        ];

        foreach ($files as $path => $content)
        {
            file_put_contents($path, $content);
        }

        Minion_CLI::write(implode("\n", [
            "Модуль {$module} создан: {$base}",
            "Дальше:",
            "  1. Подключите модуль в application/bootstrap.php: '{$module}' => APPPATH.'modules/{$module}',",
            "  2. Наполните миграцию pgMigration_Nj_{$id}_001_{$camel}_CreateTables реальными таблицами (nj_{$id}_*)",
            "  3. Запустите: bin/console db:migrate",
            "  4. Заполните {$base}/AGENTS.md: «Состояние работ» — 3–5 строк",
            "     (шаблон: docs/templates/module-AGENTS.md, протокол: docs/CONTEXT.md)",
            "  5. Вход в модуль для агента: make brief m={$module}",
        ]));
    }

    // ------------------------------------------------------------------ шаблоны

    protected function _tpl_init($id, $slug, $NjId, $camel)
    {
        return <<<PHP
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj{$id}_{$slug}.
 * Здесь только роуты и подписки на события — никакой бизнес-логики.
 */

// --- Роуты -------------------------------------------------------------------
Route::set('nj{$id}_pages', '{$slug}(/<action>(/<id>))')
    ->defaults([
        'controller' => '{$NjId}_{$camel}',
        'action'     => 'index',
    ]);

Route::set('nj{$id}_api', '{$slug}/api(/<action>(/<id>))')
    ->defaults([
        'controller' => '{$NjId}_Api',
        'action'     => 'index',
    ]);

Route::set('nj{$id}_widget', '{$slug}/widget(/<action>(/<id>))')
    ->defaults([
        'controller' => '{$NjId}_Widget',
        'action'     => 'index',
    ]);

// --- Подписки на события -------------------------------------------------------
// Service_Erp208_EventBus::instance()->listen('njXXX.something', function (\$payload) { ... });

PHP;
    }

    protected function _tpl_config($module, $name)
    {
        return <<<PHP
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфигурация модуля {$module} ({$name}).
 * Секреты — только через getenv()/\.env, здесь — настройки поведения.
 */
return [
    'enabled'      => TRUE,
    'items_per_page' => 20,
];
PHP;
    }

    protected function _tpl_migration($id, $slug, $camel)
    {
        return <<<PHP
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj{$id}_{$slug}: начальные таблицы.
 * Идемпотентность обязательна: CREATE TABLE IF NOT EXISTS и т.п.
 */
class pgMigration_Nj_{$id}_001_{$camel}_CreateTables extends pgMigration {

    public function up()
    {
        \$this->run_query("CREATE TABLE IF NOT EXISTS nj_{$id}_{$slug}
        (
          id          bigserial PRIMARY KEY,
          user_id     integer NOT NULL,
          title       varchar(255) NOT NULL DEFAULT '',
          payload     jsonb        NOT NULL DEFAULT '{}'::jsonb,
          created_at  timestamptz  NOT NULL DEFAULT now(),
          updated_at  timestamptz  NOT NULL DEFAULT now()
        );");

        \$this->run_query("CREATE INDEX IF NOT EXISTS nj_{$id}_{$slug}_user_idx
            ON nj_{$id}_{$slug} (user_id);");
    }

    public function down()
    {
        \$this->run_query("DROP TABLE IF EXISTS nj_{$id}_{$slug};");
    }
}
PHP;
    }

    protected function _tpl_controller($id, $slug, $name, $NjId, $camel)
    {
        return <<<PHP
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страницы модуля nj{$id}_{$slug} ({$name}).
 */
class Controller_{$NjId}_{$camel} extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        \$this->set_title('{$name}');
        \$this->set_menu([
            ['title' => '{$name}', 'href' => '/{$slug}', 'icon' => 'fas fa-cube', 'acl' => 'login'],
        ]);
    }

    public function action_index()
    {
        \$items = Service_{$NjId}_{$camel}::for_user(\$this->current_user->id);

        \$this->template->content = View::factory('{$id}/index', [
            'items' => \$items,
        ]);
    }
}
PHP;
    }

    protected function _tpl_api($id, $NjId)
    {
        return <<<PHP
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API модуля nj{$id}.
 */
class Controller_{$NjId}_Api extends Controller_Base {

    public \$auto_render = FALSE;

    public function before()
    {
        parent::before();
        \$this->response->headers('Content-Type', 'application/json; charset='.Kohana::\$charset);
    }

    public function action_index()
    {
        \$this->response->body(json_encode([
            'ok'      => TRUE,
            'message' => 'API nj{$id}',
        ]));
    }
}
PHP;
    }

    protected function _tpl_widget($id, $NjId, $camel)
    {
        return <<<PHP
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * HMVC-виджеты модуля nj{$id}: отдают готовый HTML-фрагмент.
 * Использование в чужих вьюхах:
 *   echo Request::factory('{$id}/widget/summary')->execute();
 */
class Controller_{$NjId}_Widget extends Controller {

    public \$auto_render = FALSE;

    public function action_index()
    {
        \$this->response->body(View::factory('{$id}/index'));
    }

    public function action_summary()
    {
        \$this->response->body('<div class="widget">Виджет {$camel}</div>');
    }
}
PHP;
    }

    protected function _tpl_service($id, $slug, $NjId, $camel)
    {
        return <<<PHP
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Вся бизнес-логика модуля nj{$id}_{$slug}.
 * Другие модули работают с данными nj_{$id}_* ТОЛЬКО через этот сервис.
 */
class Service_{$NjId}_{$camel} {

    /**
     * Записи пользователя.
     */
    public static function for_user(\$user_id, array \$filters = [])
    {
        return DB::select()
            ->from('nj_{$id}_{$slug}')
            ->where('user_id', '=', (int) \$user_id)
            ->order_by('created_at', 'DESC')
            ->execute()
            ->as_array();
    }

    /**
     * Создание записи + событие.
     */
    public static function create(\$user_id, array \$data): int
    {
        \$data['user_id'] = (int) \$user_id;

        list(\$id, \$rows) = DB::insert('nj_{$id}_{$slug}', array_keys(\$data))
            ->values(array_values(\$data))
            ->execute();

        Event::fire('nj{$id}.{$slug}.created', ['id' => \$id, 'user_id' => \$user_id]);

        return (int) \$id;
    }
}
PHP;
    }

    protected function _tpl_model($id, $slug, $NjId, $camel)
    {
        return <<<PHP
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ORM-модель nj_{$id}_{$slug}. Используется только внутри модуля.
 */
class Model_{$NjId}_{$camel} extends ORM {

    protected \$_table_name = 'nj_{$id}_{$slug}';
    protected \$_primary_key = 'id';

    protected \$_created_column = ['column' => 'created_at', 'format' => TRUE];
    protected \$_updated_column = ['column' => 'updated_at', 'format' => TRUE];
}
PHP;
    }

    protected function _tpl_view($name)
    {
        return <<<PHP
<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="space-y-4">
    <h1 class="n2j-h1"><?= HTML::chars(\$title ?? '{$name}') ?></h1>

    <?php if (empty(\$items)): ?>
        <p class="text-gray-500">Пока ничего нет.</p>
    <?php else: ?>
        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
            <?php foreach (\$items as \$item): ?>
                <li class="py-2"><?= HTML::chars(\$item['title'] ?? '') ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
PHP;
    }


    protected function _tpl_agents($module, $name, $id, $slug)
    {
        return <<<MD
# AGENTS.md — {$module}

> Памятка агенту. Общие правила платформы — в корневом /AGENTS.md.
> Заполните этот файл при первой доработке модуля и поддерживайте актуальным.
> Вход в модуль для агента — контекст-пак: `make brief m={$module}`
> (протокол: docs/CONTEXT.md; шаблон: docs/templates/module-AGENTS.md).

## Назначение
{$name}. (дописать: что модуль делает и для кого)

## Состояние работ
- Готовность: каркас (создан module:scaffold, логики пока нет)
- Сделано: — (ДД.ММ — что именно; старое удаляем)
- В работе: —
- Следующий шаг: —
- Долг / идеи: см. раздел ниже

## Ключевые классы
| Класс | Роль |
|---|---|
| `Service_Nj{$id}_...` | бизнес-логика (единственная дверь в модуль) |
| `Controller_Nj{$id}_...` | страницы |
| `Controller_Nj{$id}_Api` | JSON API |

## Таблицы (полное владение)
- `nj_{$id}_{$slug}` — (описать назначение колонок)

## Маршруты и API
- `GET /{$slug}` — страница модуля
- `GET /{$slug}/api/*` — JSON API

## Очередь
- Каналы: — (если появятся: манифест `'worker' => ['channels' => [...]]` + обработчик)

## Env
- `NJ{$id}_...` — назначение (дописать в хвост .env.example)

## Правила, которые нельзя нарушать
- —

## Не трогать / подводные камни
- —

## Как тестировать
- `bin/console db:migrate`, `GET /{$slug}`

## Долги и идеи (TODO)
- Заполнить этот файл
MD;
    }
}
