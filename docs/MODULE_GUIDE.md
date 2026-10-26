# Гайд по созданию и доработке модуля njXXX

> Каждый модуль — самостоятельный «мини-сервис» внутри монолита. Этот гайд —
> пошаговый чек-лист. Быстрая памятка для агентов живёт в `AGENTS.md` модуля.

> **Пространства имён:** все **новые** модули — `njXXX_*` (таблицы `nj_<id>_*`,
> классы `Service_Nj<id>_*`, события `nj<id>.*`). Модули `erpXXX_*` — legacy,
> общие с другим ERP-проектом: заморожены, новые сущности в них не добавлять.
> Общие таблицы ядра (`users`, `roles`, `sessions`, `llm_*`) без префикса —
> они принадлежат платформе, а не модулям.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index docs/MODULE_GUIDE.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   1. Быстрый старт (скелет одной командой) | 34 | 2,0 КБ |
|     1.1. Вход в уже существующий модуль — контекст-пак, а не чтение памятки | 44 | 1,5 КБ |
|   2. Структура модуля | 63 | 1,7 КБ |
|   3. Имена и префиксы (странно, но это самое важное) | 91 | 1,0 КБ |
|   4. Миграции | 105 | 1,5 КБ |
|   5. Сервисы — единственная дверь в модуль | 141 | 1,0 КБ |
|   6. Контроллеры: страницы, API, виджеты | 174 | 0,5 КБ |
|   7. init.php | 181 | 0,4 КБ |
|   7.1. Уведомления: как попасть в центр уведомлений (nj597) | 193 | 3,3 КБ |
|   8. AGENTS.md модуля — единственный обязательный документ | 252 | 2,7 КБ |
|   9. Тесты | 284 | 0,3 КБ |
|   MCP-инструменты модуля (опционально) | 291 | 0,7 КБ |
|   Наработки воркеров — в базу знаний (nj560) | 307 | 0,8 КБ |
|   Вёрстка страниц модуля (единый макет) | 324 | 0,5 КБ |
|   Верхнее меню модуля | 330 | 0,5 КБ |
<!-- context-index:end -->
---

## 1. Быстрый старт (скелет одной командой)

```bash
make new-module id=401 slug=tasks name="Задачи"
# создаёт application/modules/nj401_tasks со всеми файлами-заготовками
```

Если команда недоступна — создайте файлы руками по этому гайду (или попросите
агента: «создай модуль nj401_tasks по docs/MODULE_GUIDE.md»).

### 1.1. Вход в уже существующий модуль — контекст-пак, а не чтение памятки

Перед доработкой модуля (своей или чужой) сначала собирается карточка модуля —
она дешевле памятки в разы и собрана из файлов, а не из прозы:

```bash
make brief m=nj401_tasks                    # → .context/nj401_tasks.md (≤ 8 КБ)
python3 .tools/context_pack.py pack nj401_tasks            # показать в stdout
python3 .tools/context_pack.py section "Правила" --module nj401_tasks   # один раздел
python3 .tools/context_pack.py index application/modules/nj401_tasks/AGENTS.md --write
```

Карточка даёт: назначение и состояние, таблицы, каналы очереди, env, классы
с публичными методами, маршруты, зависимости, открытые пункты, **чем модуль
проверяется** (тесты, стенды, страница дыма — готовыми командами) и фрагмент
правил. Открытые пункты всех модулей разом — `make backlog`. Полный протокол,
потолки и правила записи знания обратно — `docs/CONTEXT.md` (кратко — `AGENTS.md`
§0.1). Памятку модуля целиком читают, только если она меньше карточки.

## 2. Структура модуля

```
application/modules/nj401_tasks/
├── AGENTS.md                 # ЕДИНСТВЕННЫЙ документ модуля (шаблон: docs/templates/module-AGENTS.md)
│                             # README.md заводят вручную и только у крупных модулей
│                             # (примерно от 1000 строк кода) — см. п. 8
├── init.php                  # роуты, подписки на события (не бизнес-логика!)
├── config/
│   └── nj401_tasks.php       # настройки модуля (без секретов — их в .env)
├── migrations_pg/
│   ├── pgMigration_Nj_401_001_Tasks_CreateTables.php
│   └── pgMigration_Nj_401_002_Tasks_AddIndexes.php
├── classes/
│   ├── Controller/Nj401/
│   │   ├── Tasks.php         # страницы (наследник Controller_Erp100_Base)
│   │   ├── Api.php           # JSON API /tasks/api/*
│   │   └── Widget.php        # HMVC-виджеты /tasks/widget/*
│   ├── Service/Nj401/
│   │   └── Tasks.php         # ВСЯ бизнес-логика (Service_Nj401_Tasks)
│   └── Model/Nj401/
│       └── Task.php          # ORM-модель (только внутри модуля!)
└── views/401/                # вьюхи модуля (никто снаружи их не включает)
```

> Базовые контроллеры пока живут в legacy-модуле `erp100_base`
> (`Controller_Erp100_Base`) — это ядро платформы, наследуемся от него.

## 3. Имена и префиксы (странно, но это самое важное)

| Сущность | Правило | Пример |
|---|---|---|
| Каталог модуля | `nj<id>_<slug>` | `nj401_tasks` |
| Контроллеры | `Controller_Nj<id>_<Имя>` → `classes/Controller/Nj<id>/<Имя>.php` | `Controller_Nj401_Tasks` |
| Сервисы | `Service_Nj<id>_<Имя>` | `Service_Nj401_Tasks` |
| Модели | `Model_Nj<id>_<Имя>`, `$_table_name = 'nj_<id>_<имя>'` | `nj_401_tasks` |
| Таблицы | `nj_<id>_<имя>` | `nj_401_task_comments` |
| Миграции | `pgMigration_Nj_<id>_<NNN>_<Название>`, файл = класс | `pgMigration_Nj_401_001_Tasks_CreateTables.php` |
| Роуты | `nj<id>_<назначение>` | `Route::set('nj401_api', 'nj401/api(/<action>(/<id>))')` |
| События | `nj<id>.<сущность>.<действие>` | `nj401.task.done` |
| Каналы очереди | `<домен>.<действие>` (без префикса модуля) | `nj401.recalc`, `embeddings.index` |

## 4. Миграции

* Класс наследует `pgMigration` (см. `nj111_migrations`), метод `up()`,
  опционально `down()`.
* Запросы — `$this->run_query('...')`.
* **Идемпотентность обязательна**: `CREATE TABLE IF NOT EXISTS`,
  добавление колонок через проверку `information_schema.columns`.
* Порядок применения = порядок модулей в `application/bootstrap.php`. Новые
  модули добавляйте в конец списка (перед Kohana-модулями), если нет жёсткой
  зависимости.
* Применение: `bin/console db:migrate` (`make migrate`), статус: `--status`,
  откат: `--down=<класс>`.

```php
<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Nj_401_001_Tasks_CreateTables extends pgMigration {

    public function up()
    {
        $this->run_query("CREATE TABLE IF NOT EXISTS nj_401_tasks
        (
          id          bigserial PRIMARY KEY,
          user_id     integer NOT NULL,
          title       varchar(255) NOT NULL,
          status      varchar(32) NOT NULL DEFAULT 'new',
          payload     jsonb NOT NULL DEFAULT '{}'::jsonb,
          created_at  timestamptz NOT NULL DEFAULT now(),
          updated_at  timestamptz NOT NULL DEFAULT now()
        );");
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_401_tasks_user_idx
                          ON nj_401_tasks (user_id, status);");
    }
}
```

## 5. Сервисы — единственная дверь в модуль

```php
<?php defined('SYSPATH') or die('No direct script access.');

class Service_Nj401_Tasks {

    /** Задачи пользователя (используется другими модулями ТОЛЬКО так). */
    public static function for_user($user_id, array $filters = [])
    {
        return DB::select()
            ->from('nj_401_tasks')
            ->where('user_id', '=', (int) $user_id)
            ->order_by('created_at', 'DESC')
            ->execute()
            ->as_array();
    }

    /** Создание + событие. */
    public static function create($user_id, array $data): int
    {
        $data['user_id'] = (int) $user_id;

        list($id, $rows) = DB::insert('nj_401_tasks', array_keys($data))
            ->values(array_values($data))
            ->execute();

        Event::fire('nj401.task.created', ['id' => $id, 'user_id' => $user_id]);
        return (int) $id;
    }
}
```

## 6. Контроллеры: страницы, API, виджеты

* Страничные — наследники `Controller_Erp100_Base` (шаблон, меню, ACL уже там).
* API — возвращают JSON (`$this->response->headers('Content-Type','application/json')`).
* Виджеты — `action_*` рендерит готовый HTML-фрагмент; вызов из чужих вьюх:
  `echo Request::factory('nj401/widget/user_tasks/'.$id)->execute();`

## 7. init.php

Регистрирует роуты и подписки на события. Никакой бизнес-логики:

```php
Route::set('nj401_api', 'nj401/api(/<action>(/<id>))')
    ->defaults(['controller' => 'Nj401_Api', 'action' => 'index']);

// Реакция на события других модулей:
// Service_Erp208_EventBus::instance()->listen('nj597.notify.sent', function ($payload) { ... });
```

## 7.1. Уведомления: как попасть в центр уведомлений (nj597)

Центр уведомлений **один на всю платформу** (`nj597_notifications`). Модуль не
отправляет письма и не шлёт в Telegram сам: он объявляет событие и публикует
его. Дальше nj597 сам решает — кому, по каким каналам, с какими лимитами и в
какое время суток. Пользователь настраивает это один раз на `/nj597`.

**Шаг 1 — объявить событие** в `config/nj_module.php` своего модуля:

```php
'notify' => [
    'events' => [
        'nj401.task.overdue' => [
            'title'        => 'Задача просрочена',
            'description'  => 'Срок задачи прошёл, она всё ещё открыта',
            'category'     => 'jobs',
            'icon'         => 'fas fa-calendar-xmark',
            'channels'     => 'in_app,email',   // доступные каналы
            'roles'        => 'login',          // кому вообще показываем
            'ability'      => 'nj401.view',     // пусто — по ролям
            'digest'       => FALSE,            // TRUE — склеивать в сводку
            'placeholders' => 'task_title,due_date,task_url',
        ],
    ],
],
```

Реестр подхватывает объявления сам (`Sync_manifests()`, не чаще раза в час);
то, что админ правил руками, не затирается.

**Шаг 2 — опубликовать событие** в момент, когда оно произошло:

```php
Service_Nj597_Notify::emit('nj401.task.overdue', [
    'task_title' => $task->title,
    'due_date'   => date('d.m.Y', strtotime($task->due_at)),
    'task_url'   => '/tasks/'.$task->id,
], [
    'user_id'  => (int) $task->owner_id,   // получатель
    'priority' => 7,                       // 1..10, выше — важнее
    'href'     => '/tasks/'.$task->id,     // куда ведёт клик в колокольчике
    'dedup'    => 'task-'.$task->id,       // защита от повторов
]);
```

`emit()` не шлёт ничего синхронно: пишет в ленту и ставит задания в очередь.
Доставка — отдельным проходом движка (HTTP `/nj597/api/outbox` раз в 5 минут).

**Шаг 3 — написать шаблон** (необязательно, но желательно): `/nj597/admin`
→ «Шаблоны». Подстановки — `{{task_title}}`, `{{user.name}}`, `{{site.url}}`;
если шаблона нет, центр соберёт сообщение из заголовка и текста сам.

**Чего не делать:** своих `mail()`, своих таблиц уведомлений, своего
колокольчика, INSERT в `nj_597_*` руками и вызова каналов напрямую.

Подробности, грабли и схема данных — в
`application/modules/nj597_notifications/AGENTS.md` (раздел «Как модулю
попасть в центр уведомлений»).

## 8. AGENTS.md модуля — единственный обязательный документ

Копируется из `docs/templates/module-AGENTS.md` и **поддерживается актуальным**:
состояние, таблицы, классы, API, события, что нельзя трогать, идеи/долги.
Агент, открывший модуль, читает только его — файл должен отвечать на 90% вопросов.

**Один документ, а не два.** До 28.09.2026 у модуля были и `README.md`, и
`AGENTS.md`: они пересказывали друг другу маршруты, состав каталогов и «модуль
stateless» — и расходились. У 35 небольших модулей README слит в `AGENTS.md`
и удалён, `module:scaffold` его больше не создаёт. README заводят руками и
только там, где документации людям действительно много, — ориентир примерно
1000 строк кода. Перечень каталогов в документацию не выносим: он всегда верен,
никем не читается и устаревает молча.

**Размер — часть контракта** (проверяет `.tools/doc_budget_lint.py` в общем
прогоне):

| Объём памятки | Что требуется |
|---|---|
| до 12 КБ | ничего, читается целиком |
| 12–40 КБ | индекс разделов: `context_pack.py index <файл> --write` (индекс сверяется в CI) |
| больше 40 КБ | разделить: файл такого размера не прочитает ни агент, ни человек |

**«Состояние работ» — раздел для следующей сессии**, 3–5 строк: готовность,
что сделано (с датой), что в работе, следующий шаг, долг. Без него каждый новый
заход начинается с восстановления контекста по коду и переписке. Шаблон — в
`docs/templates/module-AGENTS.md`, разбор — `docs/CONTEXT.md` §5.

Заголовки разделов пишем так, чтобы их можно было найти командой
`context_pack.py section "<часть заголовка>" --module <модуль>`: «Правила…»,
«Не трогать…», «Очередь…», «Env…». Раздел с заголовком «Разное» не найдёт никто.

## 9. Тесты

Дымовые — `tests/` (корень). Логика модуля — по возможности чистые функции в
сервисах (тестируются без HTTP). Тяжёлые интеграции (LLM и пр.) — через очередь
и заглушки.


## MCP-инструменты модуля (опционально)

Если модуль открывает операции ИИ-агентам, создайте `config/mcp.php`:

```php
return ['tools' => [[
    'name'        => 'njXXX_action',
    'description' => 'Что делает (по-русски, для агента)',
    'inputSchema' => ['type' => 'object', 'properties' => [...], 'required' => [...]],
    'handler'     => 'Service_NjXXX_Something::mcp_action', // static, (array $args): array
]]];
```

Хаб `nj160_mcp` соберёт их автоматически и отдаст по `/mcp/api/rpc`
(JSON-RPC 2.0, Bearer `MCP_TOKEN`). Каталог для человека — `/mcp` (админ).

## Наработки воркеров — в базу знаний (nj560)

Если модуль воркером создаёт полезный результат (анализ, резюме, текст) —
после успешной задачи сложите его в общую базу знаний, чтобы похожие задачи
не выполнялись дважды, а наработки копились для пайплайнов:

```php
try {
    Service_Nj560_Knowledge::remember('nj550', 'job_search',
        Service_Nj560_Knowledge::make_key($direction.'|'.$city),
        $title, $content, ['code' => $code]);
} catch (Exception $e) { /* БЗ не критична */ }
```

Дедуп точный — по ключу; поиск (текст + векторы) — `/nj560` (админ) и
`Service_Nj560_Knowledge::search()`.

## Вёрстка страниц модуля (единый макет)

Эталон и правила — AGENTS.md п.10: инструментальные страницы — главная колонка +
aside `xl:w-80` (как /jobs); админ/панельные — контейнер `w-full` без центрирования,
главный блок слева и правая колонка пояснений/ссылок (как /dashboard, /nj560, /mcp).

## Верхнее меню модуля

В контроллере (после `set_menu` с каталогом):

```php
$this->set_module_nav([
    ['title' => 'Сервис', 'href' => '/slug', 'icon' => 'fas fa-...'],
    ['title' => 'Модуль · админ', 'href' => '/slug/admin', 'icon' => 'fas fa-shield-halved', 'roles' => ['admin']],
]);
```

Чипы рисуются над контентом (md+), на малых экранах уезжают в левую панель.
Фильтрация по ролям/acl — как в `set_menu()`.
