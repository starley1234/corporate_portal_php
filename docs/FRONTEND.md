# Фронтенд: структура и правила

> Текущий стек: PHP-вьюхи Kohana + Tailwind CSS (сборка CLI → `public_html/assets/css/app.css`,
> без CDN) + дизайн-система v6 (`docs/DESIGN-SYSTEM.md`) + jQuery (legacy) + FontAwesome (локально).
> Тёмная/светлая тема через класс `dark` на `<html>` (localStorage).

---

## 0. Левая панель навигации (sidebar)

Основная навигация платформы — **левая панель** (внедрена вместо
горизонтального меню):

| Что | Где |
|---|---|
| Разметка панели | `application/views/partials/sidebar.php` |
| Подключение | `template_tailwindcss.php` (View::factory('partials/sidebar')) |
| Стили/поведение | там же в шаблоне: CSS-переменная `--sbw`, классы `sb-collapsed`, скрипт в конце шаблона |
| Интерактивный прототип | `docs/prototypes/sidebar.html` (в dev-панели песочницы: `/prototype`) |

**Источник меню — каталог `prx110_catalog`**: публичные сервисы описывают себя
манифестом `application/modules/prxXXX_slug/config/prx_module.php` и попадают в панель,
на главную и в чипы шапки автоматически (шаблон — в `application/modules/prx110_catalog/AGENTS.md`).
Контроллер сервиса при этом вызывает `set_menu(Service_Prx110_Catalog::menu_items())`.

**Быстрый поиск по сервисам** — поле в панели (`#nav-search`): Ctrl+K / Cmd+K / `/`
фокусирует, ввод фильтрует пункты по `data-search` (title + keywords манифеста),
Esc сбрасывает, пустая группа прячется целиком.

**Чипы раздела в шапке** — на страницах каталога `Service_Prx110_Catalog::header_nav()`
рисует группу и её сервисы (активный подсвечен); вне каталога шапка остаётся чистой.

**Контракт данных** — тот же `set_menu()` из `Controller_Prx100_Base`, контроллеры
не меняются. Дополнительные (опциональные) ключи пунктов:

```php
$this->set_menu([
    // группа в сайдбаре (по умолчанию «Разделы»)
    ['title' => 'Конвертеры', 'href' => '/prx501_converters', 'icon' => 'fas fa-right-left',
     'group' => 'Сервисы'],
    // счётчик справа (в свёрнутой панели — точка)
    ['title' => 'Уведомления', 'href' => '/prx597', 'icon' => 'fas fa-bell',
     'group' => 'Платформа', 'badge' => '3'],
]);
```

Поведение:
* `md+` — постоянная колонка 264px; шеврон сворачивает до 72px (только иконки,
  бейджи становятся точками); состояние в `localStorage('prx.sidebar.collapsed')`;
* `<md` — выезжающая панель + оверлей (кнопка-бургер `#mobile-menu-button`,
  закрытие: оверлей/Esc/клик по пункту);
* активный пункт подсвечивается по текущему пути (самый длинный префикс) —
  контроллерам не нужно выставлять `active`;
* пункты с `children` (бывшие dropdown) рендерятся подзаголовком группы + подпункты;
* ссылки с пустым `title` (служебные, как кнопка-закладка prx118) в панель не попадают.

## 1. Слои

```
application/views/
  template_tailwindcss.php     — главный layout (head, сайдбар, тема, app.css/app.js)
  template_bootstrap_5_3.php   — legacy-шаблон (не развиваем)
  auth/ contact/ errors/ pages/ pagination/ welcome/
application/modules/<модуль>/views/<prxXXX>/   — вьюхи модулей
public_html/assets/
  css/app.css                  — СОБРАННЫЙ Tailwind (источник .tools/tailwind/input.css:
                                 токены шкалы, компоненты .prx-*), руками не править
  js/app.js                    — общие JS-утилиты (window.PRX): humanSize, timer,
                                 downloadBlob, copyText, toast, busy; дизайн v6 — run,
                                 stopwatch, fieldError, confirm, hotkey и атрибуты
                                 data-prx-arm/-confirm/-copy/-dismiss. Подключён в
                                 template_tailwindcss.php ПОСЛЕ template.js и ДО
                                 модульных инлайн-скриптов. Копировать эти хелперы
                                 в script.php модулей НЕЛЬЗЯ — использовать PRX.*
                                 (с локальным фолбэком, чтобы модуль жил автономно).
  js/template.js               — cookies (ubid), scrollToTop
  <prxXXX>/                    — статика конкретного модуля (css/js/img)
```

## 2. Правила

1. **Контроллер** (наследник `Controller_Prx100_Base`) задаёт `title`, стили,
   скрипты и меню; вьюха получает данные через `$this->template->content = View::factory('prx401/tasks/list', [...])`.
2. **HMVC-виджеты**: чужие блоки вставляются через
   `Request::factory('prx118/widget/cards')->execute()` — модуль отдаёт готовый
   HTML, никто не включает чужие view-файлы напрямую.
3. **Tailwind — сборка CLI** (с 28.09.2026 CDN не подключаем): правка классов →
   `npm run build:css` → коммит `public_html/assets/css/app.css` (CI сверяет).
   Классы пишутся целиком — склейка `'bg-' . $c` в сборку не попадёт
   (`tailwind_classes_lint`). Плагины Tailwind не добавлять.
4. **Дизайн-система v6** — `docs/DESIGN-SYSTEM.md`: палитра по ролям, компоненты
   `.prx-*`, помощники `PRX.*`, шапка `partials/page_header`. Токены и компоненты —
   в `.tools/tailwind/input.css`, не hex по вьюхам и не `<style>` в модуле;
   проверка — `python3 .tools/design_audit.py --module <модуль>`.
5. **JS**: jQuery уже подключён (legacy) — использовать для мелочей; новый код
   — на vanilla JS/ES-модулях в `public_html/assets/js/`. Никаких новых
   тяжёлых фреймворков без обсуждения.
6. **Безопасность в шаблонах**: всё пользовательское — через
   `HTML::chars()`; JS-данные — `json_encode(..., JSON_HEX_TAG|JSON_HEX_AMP)`.
7. **Асинхронные интерфейсы** (LLM-генерации, статусы задач): фронт опрашивает
   API своего модуля или слушает SSE/поллинг очереди — тяжёлое вычисление
   всё равно в воркере (см. docs/WORKERS.md).
8. **Шапка на телефоне (320 px — минимальная ширина, которую держим).** Имя сайта
   показывается с 380 px (`min-[380px]:inline`), кнопка-закладка prx118 — с `sm`,
   отступы правой группы `space-x-1.5`; левая группа — `min-w-0 flex-1`, чтобы чипы
   раздела скроллялись внутри себя, а не распирали шапку. Выпадающие окна
   (`#gsearch-drop`, `#prx597-drop`) на `<sm` привязаны К ШАПКЕ
   (`max-sm:fixed inset-x-3 top-[4.25rem]`), а не к кнопке: кнопка стоит в середине
   строки, и панель в 22rem уезжала за левый край. Поле поиска — 16 px: с `text-sm`
   iOS на фокусе приближает всю страницу.

## 3. Как добавить страницу модуля

```php
class Controller_Prx401_Tasks extends Controller_Prx100_Base {
    public function before() {
        parent::before();
        $this->set_title('Задачи');
        $this->set_menu([
            ['title' => 'Задачи', 'href' => '/prx401/tasks', 'icon' => 'fas fa-tasks', 'acl' => 'login'],
        ]);
    }
    public function action_index() {
        $tasks = Service_Prx401_Tasks::for_user($this->current_user->id);
        $this->template->content = View::factory('prx401/tasks/list', ['tasks' => $tasks]);
    }
}
```

Роут модуля в `init.php`:

```php
Route::set('prx401_tasks', 'prx401/tasks(/<action>(/<id>))')
    ->defaults(['controller' => 'Prx401_Tasks', 'action' => 'index']);
```

## 4. Файлы статики модуля

Путь всегда `/assets/<prxXXX>/...` (папка создаётся в `public_html/assets/`).
Подключение: `$this->add_style('assets/prx401/tasks.css')` /
`$this->add_script('assets/prx401/tasks.js')` в `before()`.
