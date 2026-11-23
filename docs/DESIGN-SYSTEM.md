# Дизайн-система v6 — как вёрстать модуль

Эталон — `/pages/required_design_sample` (`application/views/pages/required_design_sample.php`).
**Агенту читать этот файл, а не 70 КБ разметки эталона.** Код слоя:

| что | где |
|---|---|
| классы `.prx-*` и токены шкалы | `.tools/tailwind/input.css` → `npm run build:css` → `public_html/assets/css/app.css` |
| поведение `window.PRX`, атрибуты `data-prx-*` | `public_html/assets/js/app.js`, тест `tests/Js/prx_ui.test.mjs` |
| шапка модуля | `application/views/partials/page_header.php` |
| проверка модуля | `python3 .tools/design_audit.py --module prxXXX` |
| полный аудит рабочих страниц | `python3 .tools/design_audit.py` и `--check` |

### Статус выравнивания

27.10.2026 рабочие пользовательские и административные страницы приведены
к этому эталону v6; полный `design_audit.py` чист, а храповик `DARK_DONE`
охватывает все production-модули и общие группы views. Альтернативная витрина
дизайна удалена 23.11.2026: рабочая дизайн-система одна — v6. Не добавляйте
вторую тему и не копируйте в модули автономные CSS.

## 1. Палитра: цвет только по смыслу

| роль | кнопка | когда |
|---|---|---|
| **Emerald** — главное действие | `.prx-btn-cta` | создать, сохранить, опубликовать |
| **Blue** — процесс | `.prx-btn-process` | запустить, вычислить, ИИ-разбор, фильтр |
| **Rose** — опасность | `.prx-btn-danger`, `-danger-soft`, `-danger-solid` (в окне) | удалить, прервать, сбросить |
| **Gray** — второстепенное | `.prx-btn-secondary` | отмена, назад, логи |

Amber — только выполняющееся состояние и предупреждение (`.prx-badge-run`, `.prx-flash-warn`); ожидание — серый `.prx-badge-wait`.
Не используем: `red-*` (→ rose), `green-*` (→ emerald), indigo/violet/purple/sky/teal/cyan/
slate… (→ роль по смыслу). Одно главное действие на блок, тени не крупнее `shadow-sm`.

### Брендовый акцент рабочего портала

`application/config/site_config.php` → `company_accent`: `orange` (по умолчанию),
`blue` или `yellow`. `partials/head.php` проверяет allowlist и выставляет
`data-company-accent` на `<html>`; ошибочное значение возвращается к orange.
Токены `--company-accent`, `--company-ink`, `--company-soft`, `--company-on`
определены для обеих тем в `.tools/tailwind/input.css`. Они применяются к знаку,
активной навигации, фокусу sidebar, аватару и переключателю темы — не к CTA,
кнопкам процессов и статусам. Это глобальный конфиг, а не выбор компании
пользователем.

**Статусный бейдж — только для настоящего состояния** (готово, в работе, ошибка,
ожидание). Декоративный «статус» сервиса запрещён: до 26.10.2026 на 43 карточках
главной стоял зелёный `.prx-badge-ok` с пульсирующей точкой и словом «онлайн» —
он читался как индикатор доступности сервера и спорил с главным действием
карточки. Признак возможности (бесплатно, без регистрации, ставки года) — это
текст в описании или нейтральный `.prx-count`/`.prx-badge-wait`, а не «ok».
Теги-ссылки над формой — нейтральные (`.prx-badge-wait`), синий остаётся за
процессом и главным действием.

## 2. Каркас страницы

```php
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Сервисы', '/'], ['Генератор страниц']],    // последняя — текущая
        'title'   => 'Генератор страниц',
        'pill'    => ['Модуль PRX', 'fa-layer-group'],               // необязательно
        'actions' => [['label' => 'Новая запись', 'href' => '/x/new', 'icon' => 'fa-plus', 'role' => 'cta']],
        'lead'    => 'Что делает страница — одной-двумя фразами.',
    ]); ?>
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <section class="prx-card md:col-span-2">…форма и результат…</section>
        <aside class="space-y-4">…процесс, пояснения, история…</aside>
    </div>
</div>
```

`actions[].role`: `cta | process | secondary | danger`; без `href` — `<button type="button">`,
`attrs` — свои атрибуты, `html` — своя разметка (форма). Тексты экранируются.
Без центрирования (`container mx-auto` нельзя), без sticky-блоков (AGENTS.md п. 10). Можно
липкую шапку или первый столбец таблицы в её прокрутке (`th`, `thead`, `td`, `tr`).

### 2.1. Левая панель: колонка и шторка

| ширина | что | свёрнутость |
|---|---|---|
| `md` и шире | постоянная колонка (`--sbw` 264 px), «шеврон» на её границе | 72 px, только иконки, состояние в `localStorage: prx.sidebar.collapsed` |
| уже `md` | выезжающая шторка по гамбургеру (`#mobile-menu-button`), ширина `min(320px, 86vw)`, фон заперт | **нет и быть не может** |

Свёрнутость — режим постоянной колонки, поэтому в CSS она объявлена внутри
`@media (min-width: 768px)` (`.tools/tailwind/input.css`, раздел 4). Иначе
состояние, включённое на компьютере, протекает в шторку: она открывается без
подписей, поиска и заголовков групп, одними иконками, и снять это нечем —
«шеврона» в шторке нет (жалоба владельца 26.10.2026, планшет 540×720).
Постоянные пункты панели — Избранное и «Все сервисы»; контекстные ссылки на
телефоне доступны в отдельной прокручиваемой строке под шапкой. См.
[контракт навигации](FRONTEND.md#0-левая-панель-навигации-sidebar).

### 2.2. Page-kit: страница из четырёх партиалов

Порядок сборки и подписи — в `docs/FOUNDATION.md` §6. Кратко:

| партиал | параметры |
|---|---|
| `partials/page_header` | `crumbs, title, pill, actions, lead` |
| `partials/filters` | `id, action, fields[], submit, reset` — GET-форма |
| `partials/empty_state` | `icon, title, text, actions[]` |
| `partials/flash` | `flash = ['type' => 'ok'|'err', 'text' => '…', 'items' => []]` |
| `partials/pagination` | `total, page, pages, per, url_base, params` |

Разметку фильтров, пустого состояния и flash не копировать в модуль: партиалы
держат единые отступы, тёмную тему и доступность. Свои классы `.prx-*` не
придумывать — расширяйте существующие в `.tools/tailwind/input.css`.

## 3. Компоненты

| группа | классы |
|---|---|
| кнопки | `.prx-btn-cta` `-process` `-secondary` `-danger` `-danger-soft` `-danger-solid`, `.prx-btn-icon`, `.prx-btn-icon-danger`, `.prx-link`; модификатор `.prx-btn-sm` — компактная кнопка; `.prx-btn-kbd` — подсказка «Ctrl+S» в кнопке; `.prx-page` — пагинация (`aria-current="page"`) |
| поля | `.prx-label`, `.prx-input`, `.prx-textarea`, `.prx-select`, `.prx-input-group` + `.prx-input-addon` + `.prx-input-bare`, `.prx-hint`, `.prx-field-error`, `.prx-invalid-icon` |
| блоки | `.prx-card` (+`.prx-card-head`), `.prx-panel` (+`-head`, `-foot`), `.prx-count`, `.prx-empty` (+`-icon`, `-text`) |
| текст | `.prx-h1` `.prx-h2` `.prx-h3`, `.prx-page-head`, `.prx-crumbs` (+`-sep`, `-current`), `.prx-lead`, `.prx-pill`, `.prx-kbd` |
| статусы | `.prx-badge` + `-ok` `-run` `-err` `-info` `-wait` (внутри `<span class="prx-dot">`), `.prx-status`, `.prx-clock`, `.prx-seg` + `.prx-seg-item` (выбран — `.is-active`, `aria-pressed` или `aria-selected` у `role="tab"`; компактные группы-фильтры «Все / Активные», «План / Факт»), `.prx-flash` + `-ok` `-info` `-warn` `-err` |
| таблица | `.prx-table` на `<table>`: шапка, строки, отступы ячеек; утилиты на `th/td` сильнее (`:where`) |
| служебные | `.prx-spin` (вращение иконки), `.prx-dots` (три точки), `.prx-scrollbar`, `.prx-chip` (чип выбора: режимы калькуляторов, фильтры по статусу, примеры; выбран — `.is-on`, `aria-pressed` или `aria-selected`; JS переключает только `is-on`, не цветовые утилиты), `.prx-prose` (HTML-текст статьи: заголовки, абзацы, списки — плагина Typography нет), `.prx-checker` (шахматка под прозрачным превью); печать — `.prx-print-area` (печатается только она), `.prx-print-only` (на экране за кадром), `.no-print`, `.page-break`; `.prx-modal*` строит `PRX.confirm` — своё окно только как `.prx-modal-box` в своей обёртке |

`<select class="prx-select w-full">` — самостоятельное поле: граница,
фон, текст, фокус, стрелка, `color-scheme` и цвета `option` задаются для обеих
тем без обязательного `.prx-input`. Ширину задаёт форма, компактную высоту
можно переопределить утилитой. Нативный выпадающий список окончательно рисует
браузер/ОС; CSS не гарантирует одинаковый вид его popup на всех платформах.

Правила:
- **Перекрашивать компонент утилитой бесполезно**: hover/dark компонента сильнее. Меняйте вариант
  (`prx-btn-process` → `prx-btn-cta`). Размер, отступы, ширину — утилитами можно.
- **Светлый цвет — только парой с `dark:`.** `text-gray-600…900`, `text-<цвет>-600…900`,
  `bg-white`, `bg-*-50…200`, `border-*-100…300` ночью тонут: рядом обязан стоять `dark:`
  той же утилиты (`text-gray-700 dark:text-gray-200`, `bg-blue-50 dark:bg-blue-950/40`,
  `border-gray-200 dark:border-gray-700`). Исключение — блок с тёмным фоном
  (`bg-gray-900`) и полупрозрачные плёнки `bg-white/10`. Держит правило `dark` в
  `.tools/design_audit.py`; храповик `DARK_DONE` — модуль попадает туда, когда чист.
- Прятать — классом `hidden`: атрибут `hidden` проигрывает `inline-flex`, `prx-btn-*` и т. п. Атрибут
  годится только элементу без своего display — так устроен `.prx-field-error` (им управляет `PRX.fieldError`).
- Новый общий компонент — в `@layer components` в `input.css`, затем `npm run build:css` и коммит
  `app.css` (CI сверяет). В сборку класс попадает, только когда он встречается в вёрстке или в `app.js`.
- Своих `<style>` во вьюхах нет; классы `.prx-*` не придумываем — `design_audit --check` роняет
  сборку на классе, которого нет в `app.css`.

## 4. Поведение: `window.PRX` и `data-prx-*`

| задача | как |
|---|---|
| долгий процесс | `PRX.run(btn, true)` / `PRX.run(btn, false)` (вернёт секунды); внутри кнопки `[data-prx-idle]`, `[data-prx-busy]` (`class="hidden"`), `[data-prx-clock]`; `data-prx-busy-show="#cancel, #hint"` — что показать на время работы |
| секундомер | `<span class="prx-clock" data-prx-stopwatch="<?php echo $elapsed; ?>">` — тикает сам; `PRX.stopwatch(el, from)` → `{stop(), seconds()}`; `PRX.formatClock(75)` → `1:15` |
| ошибка поля | `PRX.fieldError(input, 'Укажите адрес')`, снять — `null` (снимается и сама при правке). Текст — в `[data-prx-error-for="id"]`, иначе создаётся под полем |
| удалить строку | `<button class="prx-btn-icon-danger" data-prx-arm="Удалить?">`: 1-й клик взводит (4 с, сплошной красный), 2-й — выполняет submit/переход/click модуля и шлёт `prx:confirmed` (всплывает) · подпись — в `[data-prx-arm-label]`, иначе иконка + подпись; если обработчик модуля сам сменил подпись («Удаляю…», счётчик), снятие взвода её не затирает · признак взвода — класс `is-armed` (автообновление страницы ждёт его снятия) · своего взвода в модуле нет; скрытое `name="armed"` — серверная защита, не взвод |
| тяжёлое удаление | `data-prx-confirm="Удалить {subject} и все файлы?"` + `data-prx-subject`, `-title`, `-ok`, `-cancel`, `-tone` (`danger`/`cta`/`process`), `-note`; или `PRX.confirm({...}).then(yes => …)` |
| копировать | `<button data-prx-copy="значение"><i class="fas fa-copy"></i></button>` — иконка → галочка |
| закрыть | `data-prx-dismiss` (ближайший `.prx-flash`) или `data-prx-dismiss="#id"` |
| горячие клавиши | `PRX.hotkey('mod+s', fn)`, `PRX.hotkey('mod+enter', fn, { within: textarea })` → отписка; буквы — в любой раскладке |
| короткий итог | `PRX.toast('Сохранено')`; ошибка — `PRX.toast(текст, 0, 'err')` (вместо alert) |

Запрещено: `window.confirm`, `alert`, полосы прогресса и метры (ход — спиннер + этап + секундомер,
доля — числом или точками), копии этих помощников в модуле. Модульный JS — инлайн во вьюхе.

## 5. Рецепты

**Кнопка долгого процесса**
```html
<button type="button" id="run" class="prx-btn-process" data-prx-busy-show="#cancel">
  <span data-prx-idle class="inline-flex items-center gap-2"><i class="fas fa-play text-xs"></i>Запустить</span>
  <span data-prx-busy class="hidden inline-flex items-center gap-2">
    <i class="fas fa-circle-notch prx-spin"></i>Выполняется… (<span data-prx-clock class="font-mono tabular-nums">0:00</span>)
  </span>
</button>
<button type="button" id="cancel" class="prx-btn-danger-soft hidden">Прервать</button>
```
`PRX.run(run, true)` при старте, `PRX.run(run, false)` по завершении или отмене.

**Карточка процесса** (статус воркера — без полосы)
```html
<div class="prx-card" role="status" aria-live="polite">
  <div class="prx-status">
    <i class="fas fa-circle-notch prx-spin text-blue-600"></i><b>В работе</b> ·
    <span class="prx-clock" data-prx-stopwatch="<?php echo (int) $job['elapsed_sec']; ?>">0:00</span>
  </div>
  <p class="mt-2 text-xs text-gray-500">Сборка разметки · <b class="text-blue-600">Этап 2 из 3</b></p>
</div>
```

**Поле с ошибкой**
```html
<div class="flex items-center justify-between">
  <label for="url" class="prx-label">Адрес страницы</label>
  <span class="prx-field-error" data-prx-error-for="url" hidden></span>
</div>
<div class="relative mt-1.5">
  <input id="url" class="prx-input" type="url">
  <span class="prx-invalid-icon"><i class="fas fa-circle-exclamation"></i></span>
</div>
<p class="prx-hint">Принимаются общедоступные адреса.</p>
```

**Строка списка с удалением** — обычная форма, двойной клик делает app.js:
```html
<form method="post" action="/x/delete/<?php echo $id; ?>">
  <button class="prx-btn-icon-danger" data-prx-arm="Удалить?" aria-label="Удалить">
    <i class="fas fa-trash-can"></i>
  </button>
</form>
```

**Статус в таблице**: `<span class="prx-badge prx-badge-run"><span class="prx-dot"></span>в работе</span>`
(`-ok` готово, `-err` ошибка, `-wait` в очереди, `-info` справка).

## 6. Шкала `.prx-ds`

База — 100 % (эталон): `.prx-ds-h1` 30 px, `-h2` 22, `-h3` 18, `-body` 16, `-sm` 14, `-xs` 13,
`-label`/`-badge` 13. `.prx-ds.is-125` — крупный режим (×1.125, отступы ×1.15); контракт —
`tests/Js/prx_ui.test.mjs`.
Текст мельче 12 px не ставим (кроме подсказок клавиш).

## 7. Приведение модуля к эталону

1. `python3 .tools/design_audit.py --module prxXXX` — список `файл:строка` с подсказкой «как».
2. Шапка → `partials/page_header`; кнопки → роли из §1; поля, карточки, статусы → §3.
3. `confirm/alert` → §4; свои `<style>` и копии armed-логики — удалить.
4. `npm run build:css`, посмотреть глазами: светлая/тёмная, 1280 и 390 px.
   `.tools/tailwind_classes_lint.py` ловит и `class="…"`, оборванный кавычкой внутри `[ ]`:
   пишите `data-[x='1']:…`, не `data-[x="1"]:…` (иначе все классы после неё теряются).
   Компонент + утилита: утилиты сильнее — `w-auto`, `min-h-0`, `p-0`, `px/py` правят
   размер и ширину компонента, визуальные утилиты (цвет, рамка, тень) рядом не нужны.
5. Модуль чист → внести в `DONE` в `.tools/design_audit.py` (храповик: CI не даст откатиться).
   Законное исключение — комментарий в коде с причиной:
   `<?php /* design-audit: allow palette — цвета категорий */ ?>`.
