# Дизайн-система v6 — как вёрстать модуль

Эталон — `/pages/required_design_sample` (`application/views/pages/required_design_sample.php`).
**Агенту читать этот файл, а не 70 КБ разметки эталона.** Код слоя:

| что | где |
|---|---|
| классы `.n2j-*` и токены шкалы | `.tools/tailwind/input.css` → `npm run build:css` → `public_html/assets/css/app.css` |
| поведение `window.N2J`, атрибуты `data-n2j-*` | `public_html/assets/js/app.js`, тест `tests/Js/n2j_ui.test.mjs` |
| шапка модуля | `application/views/partials/page_header.php` |
| проверка модуля | `python3 .tools/design_audit.py --module njXXX` |
| план перевода модулей | `docs/PLAN-2026-10-20-design.md` |

## 1. Палитра: цвет только по смыслу

| роль | кнопка | когда |
|---|---|---|
| **Emerald** — главное действие | `.n2j-btn-cta` | создать, сохранить, опубликовать |
| **Blue** — процесс | `.n2j-btn-process` | запустить, вычислить, ИИ-разбор, фильтр |
| **Rose** — опасность | `.n2j-btn-danger`, `-danger-soft`, `-danger-solid` (в окне) | удалить, прервать, сбросить |
| **Gray** — второстепенное | `.n2j-btn-secondary` | отмена, назад, логи |

Amber — только ожидание и предупреждение (`.n2j-badge-wait`, `.n2j-flash-warn`).
Не используем: `red-*` (→ rose), `green-*` (→ emerald), indigo/violet/purple/sky/teal/cyan/
slate… (→ роль по смыслу). Одно главное действие на блок, тени не крупнее `shadow-sm`.

**Статусный бейдж — только для настоящего состояния** (готово, в работе, ошибка,
ожидание). Декоративный «статус» сервиса запрещён: до 26.10.2026 на 43 карточках
главной стоял зелёный `.n2j-badge-ok` с пульсирующей точкой и словом «онлайн» —
он читался как индикатор доступности сервера и спорил с главным действием
карточки. Признак возможности (бесплатно, без регистрации, ставки года) — это
текст в описании или нейтральный `.n2j-count`/`.n2j-badge-wait`, а не «ok».
Теги-ссылки над формой — нейтральные (`.n2j-badge-wait`), синий остаётся за
процессом и главным действием.

## 2. Каркас страницы

```php
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Сервисы', '/'], ['Генератор страниц']],    // последняя — текущая
        'title'   => 'Генератор страниц',
        'pill'    => ['Модуль N2J', 'fa-layer-group'],               // необязательно
        'actions' => [['label' => 'Новая запись', 'href' => '/x/new', 'icon' => 'fa-plus', 'role' => 'cta']],
        'lead'    => 'Что делает страница — одной-двумя фразами.',
    ]); ?>
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <section class="n2j-card md:col-span-2">…форма и результат…</section>
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
| `md` и шире | постоянная колонка (`--sbw` 264 px), «шеврон» на её границе | 72 px, только иконки, состояние в `localStorage: n2j.sidebar.collapsed` |
| уже `md` | выезжающая шторка по гамбургеру (`#mobile-menu-button`), ширина `min(320px, 86vw)`, фон заперт | **нет и быть не может** |

Свёрнутость — режим постоянной колонки, поэтому в CSS она объявлена внутри
`@media (min-width: 768px)` (`.tools/tailwind/input.css`, раздел 4). Иначе
состояние, включённое на компьютере, протекает в шторку: она открывается без
подписей, поиска и заголовков групп, одними иконками, и снять это нечем —
«шеврона» в шторке нет (жалоба владельца 26.10.2026, планшет 540×720).
Держит `.tools/nj110_menu_stand.php`.

## 3. Компоненты

| группа | классы |
|---|---|
| кнопки | `.n2j-btn-cta` `-process` `-secondary` `-danger` `-danger-soft` `-danger-solid`, `.n2j-btn-icon`, `.n2j-btn-icon-danger`, `.n2j-link`; модификатор `.n2j-btn-sm` — компактная кнопка; `.n2j-btn-kbd` — подсказка «Ctrl+S» в кнопке; `.n2j-page` — пагинация (`aria-current="page"`) |
| поля | `.n2j-label`, `.n2j-input`, `.n2j-textarea`, `.n2j-select`, `.n2j-input-group` + `.n2j-input-addon` + `.n2j-input-bare`, `.n2j-hint`, `.n2j-field-error`, `.n2j-invalid-icon` |
| блоки | `.n2j-card` (+`.n2j-card-head`), `.n2j-panel` (+`-head`, `-foot`), `.n2j-count`, `.n2j-empty` (+`-icon`, `-text`) |
| текст | `.n2j-h1` `.n2j-h2` `.n2j-h3`, `.n2j-page-head`, `.n2j-crumbs` (+`-sep`, `-current`), `.n2j-lead`, `.n2j-pill`, `.n2j-kbd` |
| статусы | `.n2j-badge` + `-ok` `-run` `-err` `-info` `-wait` (внутри `<span class="n2j-dot">`), `.n2j-status`, `.n2j-clock`, `.n2j-seg` + `.n2j-seg-item` (выбран — `.is-active`, `aria-pressed` или `aria-selected` у `role="tab"`; компактные группы-фильтры «Все / Активные», «План / Факт»), `.n2j-flash` + `-ok` `-info` `-warn` `-err` |
| таблица | `.n2j-table` на `<table>`: шапка, строки, отступы ячеек; утилиты на `th/td` сильнее (`:where`) |
| служебные | `.n2j-spin` (вращение иконки), `.n2j-dots` (три точки), `.n2j-scrollbar`, `.n2j-chip` (чип выбора: режимы калькуляторов, фильтры по статусу, примеры; выбран — `.is-on`, `aria-pressed` или `aria-selected`; JS переключает только `is-on`, не цветовые утилиты), `.n2j-prose` (HTML-текст статьи: заголовки, абзацы, списки — плагина Typography нет), `.n2j-checker` (шахматка под прозрачным превью); печать — `.n2j-print-area` (печатается только она), `.n2j-print-only` (на экране за кадром), `.no-print`, `.page-break`; `.n2j-modal*` строит `N2J.confirm` — своё окно только как `.n2j-modal-box` в своей обёртке |

Правила:
- **Перекрашивать компонент утилитой бесполезно**: hover/dark компонента сильнее. Меняйте вариант
  (`n2j-btn-process` → `n2j-btn-cta`). Размер, отступы, ширину — утилитами можно.
- **Светлый цвет — только парой с `dark:`.** `text-gray-600…900`, `text-<цвет>-600…900`,
  `bg-white`, `bg-*-50…200`, `border-*-100…300` ночью тонут: рядом обязан стоять `dark:`
  той же утилиты (`text-gray-700 dark:text-gray-200`, `bg-blue-50 dark:bg-blue-950/40`,
  `border-gray-200 dark:border-gray-700`). Исключение — блок с тёмным фоном
  (`bg-gray-900`) и полупрозрачные плёнки `bg-white/10`. Держит правило `dark` в
  `.tools/design_audit.py`; храповик `DARK_DONE` — модуль попадает туда, когда чист.
- Прятать — классом `hidden`: атрибут `hidden` проигрывает `inline-flex`, `n2j-btn-*` и т. п. Атрибут
  годится только элементу без своего display — так устроен `.n2j-field-error` (им управляет `N2J.fieldError`).
- Новый общий компонент — в `@layer components` в `input.css`, затем `npm run build:css` и коммит
  `app.css` (CI сверяет). В сборку класс попадает, только когда он встречается в вёрстке или в `app.js`.
- Своих `<style>` во вьюхах нет; классы `.n2j-*` не придумываем — `design_audit --check` роняет
  сборку на классе, которого нет в `app.css`.

## 4. Поведение: `window.N2J` и `data-n2j-*`

| задача | как |
|---|---|
| долгий процесс | `N2J.run(btn, true)` / `N2J.run(btn, false)` (вернёт секунды); внутри кнопки `[data-n2j-idle]`, `[data-n2j-busy]` (`class="hidden"`), `[data-n2j-clock]`; `data-n2j-busy-show="#cancel, #hint"` — что показать на время работы |
| секундомер | `<span class="n2j-clock" data-n2j-stopwatch="<?php echo $elapsed; ?>">` — тикает сам; `N2J.stopwatch(el, from)` → `{stop(), seconds()}`; `N2J.formatClock(75)` → `1:15` |
| ошибка поля | `N2J.fieldError(input, 'Укажите адрес')`, снять — `null` (снимается и сама при правке). Текст — в `[data-n2j-error-for="id"]`, иначе создаётся под полем |
| удалить строку | `<button class="n2j-btn-icon-danger" data-n2j-arm="Удалить?">`: 1-й клик взводит (4 с, сплошной красный), 2-й — выполняет submit/переход/click модуля и шлёт `n2j:confirmed` (всплывает) · подпись — в `[data-n2j-arm-label]`, иначе иконка + подпись; если обработчик модуля сам сменил подпись («Удаляю…», счётчик), снятие взвода её не затирает · признак взвода — класс `is-armed` (автообновление страницы ждёт его снятия) · своего взвода в модуле нет; скрытое `name="armed"` — серверная защита, не взвод |
| тяжёлое удаление | `data-n2j-confirm="Удалить {subject} и все файлы?"` + `data-n2j-subject`, `-title`, `-ok`, `-cancel`, `-tone` (`danger`/`cta`/`process`), `-note`; или `N2J.confirm({...}).then(yes => …)` |
| копировать | `<button data-n2j-copy="значение"><i class="fas fa-copy"></i></button>` — иконка → галочка |
| закрыть | `data-n2j-dismiss` (ближайший `.n2j-flash`) или `data-n2j-dismiss="#id"` |
| горячие клавиши | `N2J.hotkey('mod+s', fn)`, `N2J.hotkey('mod+enter', fn, { within: textarea })` → отписка; буквы — в любой раскладке |
| короткий итог | `N2J.toast('Сохранено')`; ошибка — `N2J.toast(текст, 0, 'err')` (вместо alert) |

Запрещено: `window.confirm`, `alert`, полосы прогресса и метры (ход — спиннер + этап + секундомер,
доля — числом или точками), копии этих помощников в модуле. Модульный JS — инлайн во вьюхе.

## 5. Рецепты

**Кнопка долгого процесса**
```html
<button type="button" id="run" class="n2j-btn-process" data-n2j-busy-show="#cancel">
  <span data-n2j-idle class="inline-flex items-center gap-2"><i class="fas fa-play text-xs"></i>Запустить</span>
  <span data-n2j-busy class="hidden inline-flex items-center gap-2">
    <i class="fas fa-circle-notch n2j-spin"></i>Выполняется… (<span data-n2j-clock class="font-mono tabular-nums">0:00</span>)
  </span>
</button>
<button type="button" id="cancel" class="n2j-btn-danger-soft hidden">Прервать</button>
```
`N2J.run(run, true)` при старте, `N2J.run(run, false)` по завершении или отмене.

**Карточка процесса** (статус воркера — без полосы)
```html
<div class="n2j-card" role="status" aria-live="polite">
  <div class="n2j-status">
    <i class="fas fa-circle-notch n2j-spin text-blue-600"></i><b>В работе</b> ·
    <span class="n2j-clock" data-n2j-stopwatch="<?php echo (int) $job['elapsed_sec']; ?>">0:00</span>
  </div>
  <p class="mt-2 text-xs text-gray-500">Сборка разметки · <b class="text-blue-600">Этап 2 из 3</b></p>
</div>
```

**Поле с ошибкой**
```html
<div class="flex items-center justify-between">
  <label for="url" class="n2j-label">Адрес страницы</label>
  <span class="n2j-field-error" data-n2j-error-for="url" hidden></span>
</div>
<div class="relative mt-1.5">
  <input id="url" class="n2j-input" type="url">
  <span class="n2j-invalid-icon"><i class="fas fa-circle-exclamation"></i></span>
</div>
<p class="n2j-hint">Принимаются общедоступные адреса.</p>
```

**Строка списка с удалением** — обычная форма, двойной клик делает app.js:
```html
<form method="post" action="/x/delete/<?php echo $id; ?>">
  <button class="n2j-btn-icon-danger" data-n2j-arm="Удалить?" aria-label="Удалить">
    <i class="fas fa-trash-can"></i>
  </button>
</form>
```

**Статус в таблице**: `<span class="n2j-badge n2j-badge-run"><span class="n2j-dot"></span>в работе</span>`
(`-ok` готово, `-err` ошибка, `-wait` в очереди, `-info` справка).

## 6. Шкала `.n2j-ds`

База — 100 % (эталон): `.n2j-ds-h1` 30 px, `-h2` 22, `-h3` 18, `-body` 16, `-sm` 14, `-xs` 13,
`-label`/`-badge` 13. `.n2j-ds.is-125` — крупный режим (×1.125, отступы ×1.15): сейчас только
ProcessHub, контракт — `tests/Js/nj670_content_scale.test.mjs`. Подробно — `docs/design_content_scale.md`.
Текст мельче 12 px не ставим (кроме подсказок клавиш).

## 7. Приведение модуля к эталону

1. `python3 .tools/design_audit.py --module njXXX` — список `файл:строка` с подсказкой «как».
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
