# Дизайн-система v6 — как вёрстать модуль

Эталон — `/pages/required_design_sample` (`application/views/pages/required_design_sample.php`).
**Агенту читать этот файл, а не 70 КБ разметки эталона.** Код слоя:

| что | где |
|---|---|
| классы `.prl-*` и токены шкалы | `.tools/tailwind/input.css` → `npm run build:css` → `public_html/assets/css/app.css` |
| поведение `window.PRL`, атрибуты `data-prl-*` | `public_html/assets/js/app.js`, тест `tests/Js/prl_ui.test.mjs` |
| шапка модуля | `application/views/partials/page_header.php` |
| проверка модуля | `python3 .tools/design_audit.py --module prlXXX` |
| план перевода модулей | `docs/PLAN-2026-10-20-design.md` |

### Выбор будущего направления

До выбора владельцем нового направления эта v6 остаётся действующим эталоном
рабочих модулей. Отдельное явное исключение — публичная витрина
`prl140_design_lab` (`/design-lab`): одиннадцать вариантов одного содержания
имеют собственные автономные CSS, дневную/ночную тему, мобильные сетки и
переключаемый бренд-акцент компании (`orange`, `blue`, `yellow`) и не
используют общий шаблон или `app.css`. Пять `sample-*` вариантов специально
приближены к шкале и семантике `required_design_sample`. Не копируйте CSS
витрины в рабочие модули. После выбора варианта его токены и компоненты
нужно спроектировать в общей дизайн-системе и переводить модули постепенно.
Акцент компании применяется только к бренд-деталям; Emerald/Blue/Rose/Gray
сохраняют смысловые роли действий и статусов.

## 1. Палитра: цвет только по смыслу

| роль | кнопка | когда |
|---|---|---|
| **Emerald** — главное действие | `.prl-btn-cta` | создать, сохранить, опубликовать |
| **Blue** — процесс | `.prl-btn-process` | запустить, вычислить, ИИ-разбор, фильтр |
| **Rose** — опасность | `.prl-btn-danger`, `-danger-soft`, `-danger-solid` (в окне) | удалить, прервать, сбросить |
| **Gray** — второстепенное | `.prl-btn-secondary` | отмена, назад, логи |

Amber — только ожидание и предупреждение (`.prl-badge-wait`, `.prl-flash-warn`).
Не используем: `red-*` (→ rose), `green-*` (→ emerald), indigo/violet/purple/sky/teal/cyan/
slate… (→ роль по смыслу). Одно главное действие на блок, тени не крупнее `shadow-sm`.

**Статусный бейдж — только для настоящего состояния** (готово, в работе, ошибка,
ожидание). Декоративный «статус» сервиса запрещён: до 26.10.2026 на 43 карточках
главной стоял зелёный `.prl-badge-ok` с пульсирующей точкой и словом «онлайн» —
он читался как индикатор доступности сервера и спорил с главным действием
карточки. Признак возможности (бесплатно, без регистрации, ставки года) — это
текст в описании или нейтральный `.prl-count`/`.prl-badge-wait`, а не «ok».
Теги-ссылки над формой — нейтральные (`.prl-badge-wait`), синий остаётся за
процессом и главным действием.

## 2. Каркас страницы

```php
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Сервисы', '/'], ['Генератор страниц']],    // последняя — текущая
        'title'   => 'Генератор страниц',
        'pill'    => ['Модуль PRL', 'fa-layer-group'],               // необязательно
        'actions' => [['label' => 'Новая запись', 'href' => '/x/new', 'icon' => 'fa-plus', 'role' => 'cta']],
        'lead'    => 'Что делает страница — одной-двумя фразами.',
    ]); ?>
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <section class="prl-card md:col-span-2">…форма и результат…</section>
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
| `md` и шире | постоянная колонка (`--sbw` 264 px), «шеврон» на её границе | 72 px, только иконки, состояние в `localStorage: prl.sidebar.collapsed` |
| уже `md` | выезжающая шторка по гамбургеру (`#mobile-menu-button`), ширина `min(320px, 86vw)`, фон заперт | **нет и быть не может** |

Свёрнутость — режим постоянной колонки, поэтому в CSS она объявлена внутри
`@media (min-width: 768px)` (`.tools/tailwind/input.css`, раздел 4). Иначе
состояние, включённое на компьютере, протекает в шторку: она открывается без
подписей, поиска и заголовков групп, одними иконками, и снять это нечем —
«шеврона» в шторке нет (жалоба владельца 26.10.2026, планшет 540×720).
Держит `.tools/prl110_menu_stand.php`.

## 3. Компоненты

| группа | классы |
|---|---|
| кнопки | `.prl-btn-cta` `-process` `-secondary` `-danger` `-danger-soft` `-danger-solid`, `.prl-btn-icon`, `.prl-btn-icon-danger`, `.prl-link`; модификатор `.prl-btn-sm` — компактная кнопка; `.prl-btn-kbd` — подсказка «Ctrl+S» в кнопке; `.prl-page` — пагинация (`aria-current="page"`) |
| поля | `.prl-label`, `.prl-input`, `.prl-textarea`, `.prl-select`, `.prl-input-group` + `.prl-input-addon` + `.prl-input-bare`, `.prl-hint`, `.prl-field-error`, `.prl-invalid-icon` |
| блоки | `.prl-card` (+`.prl-card-head`), `.prl-panel` (+`-head`, `-foot`), `.prl-count`, `.prl-empty` (+`-icon`, `-text`) |
| текст | `.prl-h1` `.prl-h2` `.prl-h3`, `.prl-page-head`, `.prl-crumbs` (+`-sep`, `-current`), `.prl-lead`, `.prl-pill`, `.prl-kbd` |
| статусы | `.prl-badge` + `-ok` `-run` `-err` `-info` `-wait` (внутри `<span class="prl-dot">`), `.prl-status`, `.prl-clock`, `.prl-seg` + `.prl-seg-item` (выбран — `.is-active`, `aria-pressed` или `aria-selected` у `role="tab"`; компактные группы-фильтры «Все / Активные», «План / Факт»), `.prl-flash` + `-ok` `-info` `-warn` `-err` |
| таблица | `.prl-table` на `<table>`: шапка, строки, отступы ячеек; утилиты на `th/td` сильнее (`:where`) |
| служебные | `.prl-spin` (вращение иконки), `.prl-dots` (три точки), `.prl-scrollbar`, `.prl-chip` (чип выбора: режимы калькуляторов, фильтры по статусу, примеры; выбран — `.is-on`, `aria-pressed` или `aria-selected`; JS переключает только `is-on`, не цветовые утилиты), `.prl-prose` (HTML-текст статьи: заголовки, абзацы, списки — плагина Typography нет), `.prl-checker` (шахматка под прозрачным превью); печать — `.prl-print-area` (печатается только она), `.prl-print-only` (на экране за кадром), `.no-print`, `.page-break`; `.prl-modal*` строит `PRL.confirm` — своё окно только как `.prl-modal-box` в своей обёртке |

Правила:
- **Перекрашивать компонент утилитой бесполезно**: hover/dark компонента сильнее. Меняйте вариант
  (`prl-btn-process` → `prl-btn-cta`). Размер, отступы, ширину — утилитами можно.
- **Светлый цвет — только парой с `dark:`.** `text-gray-600…900`, `text-<цвет>-600…900`,
  `bg-white`, `bg-*-50…200`, `border-*-100…300` ночью тонут: рядом обязан стоять `dark:`
  той же утилиты (`text-gray-700 dark:text-gray-200`, `bg-blue-50 dark:bg-blue-950/40`,
  `border-gray-200 dark:border-gray-700`). Исключение — блок с тёмным фоном
  (`bg-gray-900`) и полупрозрачные плёнки `bg-white/10`. Держит правило `dark` в
  `.tools/design_audit.py`; храповик `DARK_DONE` — модуль попадает туда, когда чист.
- Прятать — классом `hidden`: атрибут `hidden` проигрывает `inline-flex`, `prl-btn-*` и т. п. Атрибут
  годится только элементу без своего display — так устроен `.prl-field-error` (им управляет `PRL.fieldError`).
- Новый общий компонент — в `@layer components` в `input.css`, затем `npm run build:css` и коммит
  `app.css` (CI сверяет). В сборку класс попадает, только когда он встречается в вёрстке или в `app.js`.
- Своих `<style>` во вьюхах нет; классы `.prl-*` не придумываем — `design_audit --check` роняет
  сборку на классе, которого нет в `app.css`.

## 4. Поведение: `window.PRL` и `data-prl-*`

| задача | как |
|---|---|
| долгий процесс | `PRL.run(btn, true)` / `PRL.run(btn, false)` (вернёт секунды); внутри кнопки `[data-prl-idle]`, `[data-prl-busy]` (`class="hidden"`), `[data-prl-clock]`; `data-prl-busy-show="#cancel, #hint"` — что показать на время работы |
| секундомер | `<span class="prl-clock" data-prl-stopwatch="<?php echo $elapsed; ?>">` — тикает сам; `PRL.stopwatch(el, from)` → `{stop(), seconds()}`; `PRL.formatClock(75)` → `1:15` |
| ошибка поля | `PRL.fieldError(input, 'Укажите адрес')`, снять — `null` (снимается и сама при правке). Текст — в `[data-prl-error-for="id"]`, иначе создаётся под полем |
| удалить строку | `<button class="prl-btn-icon-danger" data-prl-arm="Удалить?">`: 1-й клик взводит (4 с, сплошной красный), 2-й — выполняет submit/переход/click модуля и шлёт `prl:confirmed` (всплывает) · подпись — в `[data-prl-arm-label]`, иначе иконка + подпись; если обработчик модуля сам сменил подпись («Удаляю…», счётчик), снятие взвода её не затирает · признак взвода — класс `is-armed` (автообновление страницы ждёт его снятия) · своего взвода в модуле нет; скрытое `name="armed"` — серверная защита, не взвод |
| тяжёлое удаление | `data-prl-confirm="Удалить {subject} и все файлы?"` + `data-prl-subject`, `-title`, `-ok`, `-cancel`, `-tone` (`danger`/`cta`/`process`), `-note`; или `PRL.confirm({...}).then(yes => …)` |
| копировать | `<button data-prl-copy="значение"><i class="fas fa-copy"></i></button>` — иконка → галочка |
| закрыть | `data-prl-dismiss` (ближайший `.prl-flash`) или `data-prl-dismiss="#id"` |
| горячие клавиши | `PRL.hotkey('mod+s', fn)`, `PRL.hotkey('mod+enter', fn, { within: textarea })` → отписка; буквы — в любой раскладке |
| короткий итог | `PRL.toast('Сохранено')`; ошибка — `PRL.toast(текст, 0, 'err')` (вместо alert) |

Запрещено: `window.confirm`, `alert`, полосы прогресса и метры (ход — спиннер + этап + секундомер,
доля — числом или точками), копии этих помощников в модуле. Модульный JS — инлайн во вьюхе.

## 5. Рецепты

**Кнопка долгого процесса**
```html
<button type="button" id="run" class="prl-btn-process" data-prl-busy-show="#cancel">
  <span data-prl-idle class="inline-flex items-center gap-2"><i class="fas fa-play text-xs"></i>Запустить</span>
  <span data-prl-busy class="hidden inline-flex items-center gap-2">
    <i class="fas fa-circle-notch prl-spin"></i>Выполняется… (<span data-prl-clock class="font-mono tabular-nums">0:00</span>)
  </span>
</button>
<button type="button" id="cancel" class="prl-btn-danger-soft hidden">Прервать</button>
```
`PRL.run(run, true)` при старте, `PRL.run(run, false)` по завершении или отмене.

**Карточка процесса** (статус воркера — без полосы)
```html
<div class="prl-card" role="status" aria-live="polite">
  <div class="prl-status">
    <i class="fas fa-circle-notch prl-spin text-blue-600"></i><b>В работе</b> ·
    <span class="prl-clock" data-prl-stopwatch="<?php echo (int) $job['elapsed_sec']; ?>">0:00</span>
  </div>
  <p class="mt-2 text-xs text-gray-500">Сборка разметки · <b class="text-blue-600">Этап 2 из 3</b></p>
</div>
```

**Поле с ошибкой**
```html
<div class="flex items-center justify-between">
  <label for="url" class="prl-label">Адрес страницы</label>
  <span class="prl-field-error" data-prl-error-for="url" hidden></span>
</div>
<div class="relative mt-1.5">
  <input id="url" class="prl-input" type="url">
  <span class="prl-invalid-icon"><i class="fas fa-circle-exclamation"></i></span>
</div>
<p class="prl-hint">Принимаются общедоступные адреса.</p>
```

**Строка списка с удалением** — обычная форма, двойной клик делает app.js:
```html
<form method="post" action="/x/delete/<?php echo $id; ?>">
  <button class="prl-btn-icon-danger" data-prl-arm="Удалить?" aria-label="Удалить">
    <i class="fas fa-trash-can"></i>
  </button>
</form>
```

**Статус в таблице**: `<span class="prl-badge prl-badge-run"><span class="prl-dot"></span>в работе</span>`
(`-ok` готово, `-err` ошибка, `-wait` в очереди, `-info` справка).

## 6. Шкала `.prl-ds`

База — 100 % (эталон): `.prl-ds-h1` 30 px, `-h2` 22, `-h3` 18, `-body` 16, `-sm` 14, `-xs` 13,
`-label`/`-badge` 13. `.prl-ds.is-125` — крупный режим (×1.125, отступы ×1.15): сейчас только
ProcessHub, контракт — `tests/Js/prl670_content_scale.test.mjs`. Подробно — `docs/design_content_scale.md`.
Текст мельче 12 px не ставим (кроме подсказок клавиш).

## 7. Приведение модуля к эталону

1. `python3 .tools/design_audit.py --module prlXXX` — список `файл:строка` с подсказкой «как».
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
