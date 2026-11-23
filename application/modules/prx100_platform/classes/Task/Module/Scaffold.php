<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Генератор модуля на фундаменте платформы (scaffold v2).
 *
 *   make new-module id=312 slug=claims name="Заявки" entity=Claim
 *
 * Создаёт модуль, который с первой строки следует контракту платформы:
 *
 *   config/prx_module.php   — полный манифест (kind/status/audience/acl);
 *   init.php                — маршруты страницы и внутреннего API;
 *   Controller/PrxXXX/Page  — actor + policy, никаких массивов прав;
 *   Controller/PrxXXX/Api   — JSON-ответы, CSRF через Prx_PostGuard;
 *   Policy/PrxXXX/<Entity>  — объектные правила доступа (наследует Prx_Policy);
 *   Service/PrxXXX/Store    — владелец таблиц prx_XXX_*, SQL через Prx_Db;
 *   views/…                 — page-kit (page_header, filters, empty_state, flash);
 *   migrations_pg/…         — идемпотентная миграция;
 *   tests/Unit/…            — тест политики (гость и чужая организация);
 *   AGENTS.md               — карта модуля для агента.
 *
 * Модуль НЕ подключается автоматически: включите его в
 * application/config/modules.php после проверки `make check`.
 */
class Task_Module_Scaffold extends Minion_Task {
    protected $_options = ['id' => NULL, 'slug' => NULL, 'name' => NULL, 'entity' => 'Item'];

    protected function _execute(array $params)
    {
        $id = (int) ($params['id'] ?? 0);
        $slug = (string) ($params['slug'] ?? '');
        $name = trim((string) ($params['name'] ?? $slug));
        $entity = (string) ($params['entity'] ?? 'Item');

        if ($id < 300 OR $id > 999 OR ! preg_match('/^[a-z][a-z0-9_]*$/D', $slug))
        {
            throw new InvalidArgumentException('Нужны id=300..999 и slug из a-z, 0-9, _');
        }
        if ( ! preg_match('/^[A-Z][a-zA-Z0-9]*$/D', $entity))
        {
            throw new InvalidArgumentException('entity — CamelCase, например Claim');
        }
        if (glob(APPPATH.'modules/prx'.$id.'_*'))
        {
            throw new RuntimeException('ID модуля уже занят: '.$id);
        }
        foreach ((array) Kohana::$config->load('portal')->get('domains') as $domain)
        {
            foreach ($domain['services'] as $service)
            {
                if (strpos($service['module'], 'prx'.$id.'_') === 0
                    AND $service['module'] !== 'prx'.$id.'_'.$slug)
                {
                    throw new RuntimeException('ID зарезервирован для '.$service['module']);
                }
            }
        }

        $module = 'prx'.$id.'_'.$slug;
        $class = 'Prx'.$id;
        $table = 'prx_'.$id.'_'.strtolower($entity).'s';
        $base = APPPATH.'modules/'.$module;
        $replace = [
            '__MODULE__' => $module, '__CLASS__' => $class, '__ID__' => (string) $id,
            '__SLUG__' => $slug, '__TITLE__' => var_export($name, TRUE), '__TODAY__' => date('Y-m-d'),
            '__ENTITY__' => $entity, '__ENTITY_LOWER__' => strtolower($entity),
            '__TABLE__' => $table,
        ];

        $files = [
            'init.php' => <<<'PHP'
<?php defined('SYSPATH') or die('No direct script access.');

// Страница сервиса и его внутренний API. URL по умолчанию — префикс + слаг
// (/prxNNN_slug), чтобы разработчик сразу видел владельца. Внешний машинный
// контракт, если понадобится, живёт отдельно: /api/v1/<domain>/... (см. prx395).
Route::set('__MODULE___api', '__MODULE__/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => '__CLASS___Api', 'action' => 'index']);
Route::set('__MODULE__', '__MODULE__(/<action>(/<id>))', [
        'action' => '[a-z_]+', 'id' => '\d+',
    ])->defaults(['controller' => '__CLASS___Page', 'action' => 'index']);
PHP,
            'config/prx_module.php' => <<<'PHP'
<?php defined('SYSPATH') or die('No direct script access.');

/** Манифест — контракт модуля. Схема: python3 .tools/manifest_check.py. */
return [
    'id' => __ID__,
    'slug' => '__MODULE__',
    'title' => __TITLE__,
    'short' => 'Короткое описание для каталога: что здесь делает сотрудник',
    'icon' => 'fas fa-cube',
    'href' => '/__MODULE__',
    'sort' => __ID__,
    'created_at' => '__TODAY__',
    // draft — только admin/agent; переведите в beta/stable после тестов
    'status' => 'draft',
    'audience' => 'admin',
    'acl' => 'prx__ID__.view',
    'api' => ['/__MODULE__/api'],
];
PHP,
            'config/__MODULE__.php' => <<<'PHP'
<?php defined('SYSPATH') or die('No direct script access.');
// Настройки модуля. Секреты — только в .env: PRX__ID___SLUG___*
return [];
PHP,
            'classes/Controller/__CLASS__/Page.php' => <<<'PHP'
<?php defined('SYSPATH') or die('No direct script access.');

/** Страница сервиса. Доступ — через actor и политику, не через массивы. */
class Controller___CLASS___Page extends Controller_Base {

    public function before()
    {
        parent::before();
        $this->actor()->require('prx__ID__.view');
    }

    public function action_index()
    {
        $this->set_title(__TITLE__);
        $this->template->content = View::factory('__MODULE__/index', [
            'rows' => (new Service___CLASS___Store)->list($this->actor()),
        ]);
    }

}
PHP,
            'classes/Controller/__CLASS__/Api.php' => <<<'PHP'
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Внутренний API модуля: тот же пользователь, та же политика.
 * CSRF уже проверен Prx_PostGuard в Controller_Prx100_Base::before().
 */
class Controller___CLASS___Api extends Controller_Base {

    public $auto_render = FALSE;

    public function action_index()
    {
        $this->actor()->require('prx__ID__.view');
        $this->_json_response([
            'items' => (new Service___CLASS___Store)->list($this->actor()),
        ]);
    }

}
PHP,
            'classes/Policy/__CLASS__/__ENTITY__.php' => <<<'PHP'
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Объектные правила доступа к записи модуля.
 *
 * RBAC уже проверил Prx_Actor::require(); здесь только объектная часть:
 * организация, подразделение, владелец. Никаких SQL и выборок — только
 * сравнение полей переданной записи.
 */
class Policy___CLASS______ENTITY__ extends Prx_Policy {

    protected const VIEW_ACL = 'prx__ID__.view';
    protected const EDIT_ACL = 'prx__ID__.manage';

    protected function scope(Prx_Actor $actor, array $row): bool
    {
        if ($actor->isAdmin())
        {
            return TRUE;
        }

        // Пример: своя организация. Уберите, если сервис не орг-специфичный.
        return $actor->organizationId() !== NULL
            AND (int) ($row['organization_id'] ?? 0) === $actor->organizationId();
    }

}
PHP,
            'classes/Service/__CLASS__/Store.php' => <<<'PHP'
<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Владелец таблиц prx___ID___*. Единственное место с SQL модуля.
 *
 * Правила: именованные параметры (:name), идентификаторы — Prx_Db::identifier(),
 * запись — в Prx_Db::transaction(), доступ — через Policy.
 */
class Service___CLASS___Store {

    /** Список записей, доступных actor. */
    public function list(Prx_Actor $actor): array
    {
        $policy = new Policy___CLASS______ENTITY__;

        $rows = Prx_Db::select(
            'SELECT * FROM __TABLE__ ORDER BY id DESC LIMIT 100'
        );

        return array_values(array_filter(
            $rows,
            static fn (array $row) => $policy->view($actor, $row)
        ));
    }

    /** Создание записи: возвращает id. */
    public function create(Prx_Actor $actor, array $data): int
    {
        $data['created_by'] = $actor->id();

        return Prx_Db::transaction(function () use ($data) {
            [$id] = Prx_Db::insert(
                'INSERT INTO __TABLE__ (created_by) VALUES (:created_by) RETURNING id',
                [':created_by' => $data['created_by']]
            );

            return (int) $id;
        });
    }

}
PHP,
            'migrations_pg/pgMigration_Prx___ID___001_Initial.php' => <<<'PHP'
<?php defined('SYSPATH') or die('No direct script access.');

/** Идемпотентная схема модуля. После первого релиза не менять. */
class pgMigration_Prx___ID___001_Initial extends pgMigration {

    public function up()
    {
        $this->run_query('CREATE TABLE IF NOT EXISTS __TABLE__ (
            id bigserial PRIMARY KEY,
            created_by bigint NOT NULL DEFAULT 0,
            created_at timestamptz NOT NULL DEFAULT now()
        )');
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS __TABLE__');
    }

}
PHP,
            'views/__MODULE__/index.php' => <<<'PHP'
<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Сервисы', '/services'], [__TITLE__]],
        'title'  => __TITLE__,
        'pill'   => [count($rows).' записей', 'fa-cube'],
        'actions' => [['label' => 'Создать', 'href' => '/__MODULE__/create', 'icon' => 'fa-plus', 'role' => 'cta']],
        'lead'   => 'Одной-двумя фразами: что делает страница и кому доступна.',
    ]); ?>

    <div class="prx-panel mt-6">
<?php if (empty($rows)): ?>
        <?php echo View::factory('partials/empty_state', [
            'icon'  => 'fas fa-cube',
            'title' => 'Записей пока нет',
            'text'  => 'Создайте первую запись — она появится в этом списке.',
        ]); ?>
<?php else: ?>
        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
<?php foreach ($rows as $row): ?>
            <li class="py-3 text-sm text-gray-900 dark:text-white">#<?php echo (int) $row['id']; ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </div>
</div>
PHP,
            'AGENTS.md' => <<<'MD'
# __MODULE__

## Назначение
__TITLE__.

## Состояние работ
Каркас на фундаменте платформы: манифест, маршруты, контроллер, политика,
сервис, миграция и тест политики. Модуль не подключён.

## Контракты
- Таблицы: `prx___ID___*` — только через `Service___CLASS___Store`.
- Права: `prx__ID__.view`, `prx__ID__.manage` — объявлены в `application/config/acl.php`.
- Объектный доступ: `Policy___CLASS______ENTITY__` (наследует `Prx_Policy`).
- SQL: `Prx_Db::select/one/execute/insert/transaction`, параметры `:name`.
- События: `Prx_Event::publish('prx__ID__.<entity>.<action>', [...])`.

## URL
Страница — `/__MODULE__`, внутренний API — `/__MODULE__/api`. Внешний машинный
контракт — `/api/v1/<domain>/…` и ключ prx150; не смешивайте с страницаи модуля.

## Карта
| что | где |
|---|---|
| маршруты | `init.php` |
| манифест | `config/prx_module.php` |
| страница | `classes/Controller/__CLASS__/Page.php`, `views/__MODULE__/index.php` |
| API | `classes/Controller/__CLASS__/Api.php` |
| доступ | `classes/Policy/__CLASS__/__ENTITY__.php` |
| данные | `classes/Service/__CLASS__/Store.php` |

## Проверки
`make check` — манифест, ACL, маршруты, ссылки.
`phpunit --filter __CLASS__` — тест политики (гость, чужая организация).
MD,
            'tests/Unit/__CLASS__PolicyTest.php' => <<<'PHP'
<?php
use PHPUnit\Framework\TestCase;

/** Политика модуля: гость и чужая организация не проходят. */
class __CLASS__PolicyTest extends TestCase
{
    public function test_guest_cannot_view_anything(): void
    {
        $policy = new Policy___CLASS______ENTITY__;
        $this->assertFalse($policy->view(Prx_Actor::guest(), ['id' => 1]));
    }

    public function test_other_organization_is_denied(): void
    {
        $policy = new Policy___CLASS______ENTITY__;
        $actor = new Prx_Actor(1, ['login'], ['organization_id' => 7]);
        $this->assertFalse($policy->view($actor, ['id' => 1, 'organization_id' => 8]));
    }
}
PHP,
        ];

        foreach ($files as $template => $content)
        {
            $relative = strtr($template, $replace);
            $path = $base.'/'.$relative;
            if ( ! is_dir(dirname($path)) AND ! mkdir(dirname($path), 0775, TRUE))
            {
                throw new RuntimeException('Не удалось создать '.dirname($path));
            }
            if (file_put_contents($path, strtr($content, $replace)) === FALSE)
            {
                throw new RuntimeException('Не удалось записать '.$path);
            }
        }

        $this->_declare_acl("prx{$id}.view", 'prx'.$id.'.manage');

        Minion_CLI::write("Создан {$module} (scaffold v2).");
        Minion_CLI::write('Дальше: 1) реализуйте Store/Policy; 2) подключите модуль в application/config/modules.php;');
        Minion_CLI::write('        3) make migrate && make check; 4) переведите status в beta/stable.');
    }

    /** Объявляет права модуля в config/acl.php, если их там ещё нет. */
    protected function _declare_acl(string ...$keys)
    {
        $path = APPPATH.'config/acl.php';
        $source = file_get_contents($path);
        $missing = array_filter($keys, static function ($key) use ($source) {
            return strpos($source, "'{$key}'") === FALSE;
        });

        if ( ! $missing)
        {
            return;
        }

        $lines = '';
        foreach ($missing as $key)
        {
            $lines .= "    '{$key}' => ['admin'],\n";
        }

        $updated = preg_replace('/\];\s*$/', $lines.'];', $source, 1, $count);
        if ($count !== 1 OR file_put_contents($path, $updated) === FALSE)
        {
            throw new RuntimeException('Не удалось добавить права в config/acl.php');
        }
    }
}
