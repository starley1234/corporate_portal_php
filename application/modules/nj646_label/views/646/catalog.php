<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Справочник веществ (админка, /nj646/admin/catalog) — дерево и правка карточек.
 *
 * Владелец (03.10.2026): «страница для админа для просмотра сформировавшегося
 * справочника веществ — удобное иерархическое дерево (выбор разных вариантов
 * построения дерева по разным критериям), чтобы было удобное… возможно строчки
 * надо делать компактными и ajax'ом подтягивать ветки (элементов, наверное,
 * будет тысячи) — назначение просмотр справочника и удобное редактирование
 * (удалить, переизучить и т.п.)».
 *
 * Как устроено и почему именно так:
 *   • слева — дерево, справа — карточка выбранного вещества с правкой;
 *   • узлы подтягиваются AJAX'ом по одному уровню (`/nj646/admin/tree`), поэтому
 *     страница открывается мгновенно даже на тысячах веществ: в HTML попадает
 *     только верхний уровень;
 *   • строки дерева компактные (название, счётчик, метки), а подробности — в
 *     правой колонке, чтобы список оставался читаемым;
 *   • критериев несколько (категория, риск, модерация, источник, встречаемость,
 *     алфавит, E-коды) — переключатель режима перестраивает дерево целиком;
 *   • листья листаются (по `limits.catalog_page`), в ветви есть поиск;
 *   • действия — прямо в карточке: сохранить правку, опубликовать/скрыть/в
 *     черновик, переизучить, удалить (с подтверждением).
 *
 * Страница работает и без JS настолько, насколько может: без него видно
 * подсказку и ссылки в обычную админку (дерево строится браузером).
 */

$verdict_labels = [
    'useful'  => 'полезное',
    'neutral' => 'нейтральное',
    'caution' => 'осторожно',
    'risky'   => 'рискованное',
    'avoid'   => 'избегать',
];

// Статусы карточек — через i18n модуля (modules/nj646_label/i18n/ru.php):
// одна таблица переводов на страницу и на то, что дорисовывает JS.
$status_labels = Service_Nj646_Label::status_labels('card');
$kind_labels   = ['food' => 'еда', 'cosmetic' => 'косметика', 'both' => 'универсальное'];
$source_labels = ['seed' => 'стартовая база', 'llm' => 'изучено ИИ', 'user' => 'правка посетителя', 'admin' => 'правка админа', 'csv' => 'импорт словаря'];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
        <div class="mb-5 rounded-xl border px-4 py-3 text-sm <?php echo $flash_kind === 'warn'
            ? 'border-amber-300/80 bg-amber-50/90 text-amber-900 dark:border-amber-800/80 dark:bg-amber-950/40 dark:text-amber-200'
            : 'border-blue-300/80 bg-blue-50/90 text-blue-900 dark:border-blue-800/80 dark:bg-blue-950/40 dark:text-blue-200'; ?>">
            <?php echo HTML::chars($flash); ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="n2j-h1">Справочник веществ</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Всё, что накопила база: <?php echo number_format((float) $total, 0, ',', ' '); ?> веществ
                (опубликовано <?php echo number_format((float) $stats['ingredients'], 0, ',', ' '); ?>,
                изучено ИИ <?php echo number_format((float) $stats['learned'], 0, ',', ' '); ?>).
                Дерево строится по выбранному критерию, ветви подтягиваются по мере раскрытия.
            </p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            <a href="/nj646/admin" class="n2j-btn-secondary">← в админку</a>
            <a href="/nj646/admin/history" class="n2j-btn-secondary">история разборов</a>
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">

        <!-- ============================ ДЕРЕВО ============================ -->
        <div class="n2j-card">
            <label class="n2j-label" for="nj646-mode">Строить дерево</label>
            <select id="nj646-mode"
                    class="n2j-input n2j-select mt-1 w-full">
                <?php foreach ($modes as $key => $mode): ?>
                    <option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($mode['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php $first_mode = reset($modes); ?>
            <p id="nj646-mode-hint" class="n2j-hint">
                <?php echo HTML::chars((string) ($first_mode['hint'] ?? '')); ?>
            </p>

            <!-- Быстрые пресеты: что требует внимания прямо сейчас. Пресет —
                 это режим дерева плюс раскрытая ветвь, то есть обычный путь;
                 счётчики приходят с сервера, чтобы не ходить за деревом зря. -->
            <div class="mt-2 flex flex-wrap gap-1.5 text-xs">
                <?php
                $presets = [
                    ['status', ['draft'], 'черновики', (int) $counts['drafts'], 'amber'],
                    ['source', ['llm'], 'изучено ИИ', (int) $counts['llm'], 'blue'],
                    ['hits', ['never'], 'ни разу не встречались', (int) $counts['never'], 'gray'],
                    ['risk', ['4'], 'риск 4', (int) $counts['risky'], 'rose'],
                    ['status', ['hidden'], 'скрытые', (int) $counts['hidden'], 'gray'],
                ];
                $tones = [
                    'amber' => 'border-amber-200 bg-amber-50 text-amber-800 hover:border-amber-400 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200',
                    'blue'  => 'border-blue-200 bg-blue-50 text-blue-800 hover:border-blue-400 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-200',
                    'rose'  => 'border-rose-200 bg-rose-50 text-rose-800 hover:border-rose-400 dark:border-rose-800 dark:bg-rose-900/30 dark:text-rose-200',
                    'gray'  => 'border-gray-200 bg-gray-50 text-gray-600 hover:border-gray-400 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300',
                ];
                ?>
                <?php foreach ($presets as $preset): ?>
                    <button type="button" class="nj646-preset rounded-full border px-2 py-0.5 font-semibold <?php echo $tones[$preset[4]]; ?>"
                            data-mode="<?php echo HTML::chars($preset[0]); ?>"
                            data-path="<?php echo HTML::chars(json_encode($preset[1])); ?>">
                        <?php echo HTML::chars($preset[2]); ?>: <?php echo number_format((float) $preset[3], 0, ',', ' '); ?>
                    </button>
                <?php endforeach; ?>
                <?php if ($counts['unsure'] > 0): ?>
                    <span class="rounded-full border border-dashed border-gray-300 px-2 py-0.5 text-gray-500 dark:border-gray-600 dark:text-gray-400"
                          title="Карточки ИИ, в которых модель была неуверена (confidence < 60) — их стоит перечитать">
                        неуверенных карточек ИИ: <?php echo number_format((float) $counts['unsure'], 0, ',', ' '); ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="mt-3 flex gap-2">
                <input id="nj646-q" type="text" autocomplete="off" placeholder="Поиск: на корне — по всему справочнику, в ветке — по ней…"
                       class="n2j-input w-full">
                <button type="button" id="nj646-q-go"
                        class="n2j-btn-icon text-sm">
                    <i class="fas fa-magnifying-glass"></i>
                </button>
            </div>

            <nav id="nj646-crumbs" class="mt-3 flex flex-wrap items-center gap-1 text-xs text-gray-500 dark:text-gray-400"></nav>

            <div id="nj646-tree" class="mt-2 max-h-[70vh] space-y-1 overflow-y-auto pr-1">
                <p class="n2j-hint">Загружаю дерево…</p>
            </div>

            <div id="nj646-more" class="mt-2 hidden">
                <button type="button" id="nj646-more-btn"
                        class="n2j-btn-secondary n2j-btn-sm w-full">
                    показать ещё
                </button>
            </div>

            <p class="n2j-hint mt-3">
                Узел раскрывается одним запросом; ветви с сотнями веществ листаются.
            </p>
        </div>

        <!-- ============================ КАРТОЧКА ============================ -->
        <div class="n2j-card">
            <div id="nj646-card-empty" class="text-sm text-gray-500 dark:text-gray-400">
                <p class="font-semibold text-gray-700 dark:text-gray-200">Выберите вещество в дереве слева</p>
                <p class="mt-1 text-xs">
                    Здесь появится карточка в режиме чтения: пояснение, источники, статус модерации.
                    Правка — по кнопке «править», переобучение у модели — «переобучить».
                </p>
            </div>

            <div id="nj646-card" class="hidden">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 id="nj646-card-name" class="n2j-h3"></h2>
                        <p id="nj646-card-sub" class="n2j-hint mt-0.5"></p>
                    </div>
                    <span id="nj646-card-flags" class="flex flex-wrap items-center gap-1.5 text-xs"></span>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                    <!-- Просмотр — по умолчанию, правка — по карандашу (владелец:
                         «просмотр по умолчанию — на чтение, если надо редактировать
                         то нажать на карандашик, рядом иконка переобучить»). -->
                    <button type="button" id="nj646-edit-toggle"
                            class="n2j-btn-secondary n2j-btn-sm"
                            title="Редактировать карточку">
                        <i class="fas fa-pencil mr-1"></i>править
                    </button>
                    <button type="button" id="nj646-restudy"
                            class="n2j-btn-secondary n2j-btn-sm"
                            title="Отправить вещество модели на переизучение — карточка ИИ заменит текущую">
                        <i class="fas fa-rotate mr-1"></i>переобучить
                    </button>
                    <span class="mx-1 hidden h-5 w-px bg-gray-200 sm:inline-block dark:bg-gray-700"></span>
                    <button type="button" data-nj646-act="published"
                            class="n2j-btn-secondary n2j-btn-sm">опубликовать</button>
                    <button type="button" data-nj646-act="draft"
                            class="n2j-btn-secondary n2j-btn-sm">в черновики</button>
                    <button type="button" data-nj646-act="hidden"
                            class="n2j-btn-secondary n2j-btn-sm">скрыть</button>
                    <button type="button" id="nj646-delete"
                            class="n2j-btn-danger n2j-btn-sm">
                        <i class="fas fa-trash-can mr-1"></i>удалить
                    </button>
                </div>

                <p id="nj646-card-out" class="mt-2 hidden text-xs"></p>

                <!-- ЧТЕНИЕ: то, что видит админ до правки. -->
                <div id="nj646-view" class="mt-3 space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Кратко</p>
                        <p id="nj646-view-summary" class="mt-0.5 text-gray-800 dark:text-gray-100"></p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Подробно</p>
                        <p id="nj646-view-details" class="mt-0.5 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200"></p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Синонимы</p>
                            <p id="nj646-view-synonyms" class="mt-0.5 text-xs text-gray-600 dark:text-gray-300"></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Кому обратить внимание</p>
                            <p id="nj646-view-avoid" class="mt-0.5 text-xs text-amber-700 dark:text-amber-300"></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Источники</p>
                            <p id="nj646-view-sources" class="mt-0.5 text-xs text-gray-600 dark:text-gray-300"></p>
                        </div>
                    </div>
                </div>

                <form id="nj646-edit" class="mt-4 hidden space-y-3 text-xs">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block text-gray-600 dark:text-gray-300">Название
                            <input type="text" name="name_ru" class="n2j-input mt-1 w-full py-1.5 min-h-0">
                        </label>
                        <label class="block text-gray-600 dark:text-gray-300">Латинское / международное
                            <input type="text" name="name_int" class="n2j-input mt-1 w-full py-1.5 min-h-0">
                        </label>
                        <label class="block text-gray-600 dark:text-gray-300">Категория
                            <input type="text" name="category" class="n2j-input mt-1 w-full py-1.5 min-h-0">
                        </label>
                        <label class="block text-gray-600 dark:text-gray-300">E-код
                            <input type="text" name="e_code" class="n2j-input mt-1 w-full py-1.5 min-h-0">
                        </label>
                        <label class="block text-gray-600 dark:text-gray-300">Вид продукта
                            <select name="kind" class="n2j-input n2j-select mt-1 w-full py-1.5 min-h-0">
                                <?php foreach ($kind_labels as $key => $label): ?>
                                    <option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block text-gray-600 dark:text-gray-300">Риск (0–4)
                            <select name="risk" class="n2j-input n2j-select mt-1 w-full py-1.5 min-h-0">
                                <?php foreach ([0, 1, 2, 3, 4] as $risk): ?>
                                    <option value="<?php echo (int) $risk; ?>"><?php echo (int) $risk; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block text-gray-600 dark:text-gray-300">Вердикт
                            <select name="verdict" class="n2j-input n2j-select mt-1 w-full py-1.5 min-h-0">
                                <?php foreach ($verdict_labels as $key => $label): ?>
                                    <option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block text-gray-600 dark:text-gray-300">Статус
                            <select name="status" class="n2j-input n2j-select mt-1 w-full py-1.5 min-h-0">
                                <?php foreach ($status_labels as $key => $label): ?>
                                    <option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label class="block text-gray-600 dark:text-gray-300">Кратко (для разбора)
                        <input type="text" name="summary" class="n2j-input mt-1 w-full py-1.5 min-h-0">
                    </label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block text-gray-600 dark:text-gray-300">Синонимы
                            <input type="text" name="synonyms" placeholder="через запятую"
                                   class="n2j-input mt-1 w-full py-1.5 min-h-0">
                            <span class="mt-0.5 block text-xs text-gray-400">по ним работает поиск</span>
                        </label>
                        <label class="block text-gray-600 dark:text-gray-300">Ограничения
                            <input type="text" name="avoid" placeholder="allergy, kids…"
                                   class="n2j-input mt-1 w-full py-1.5 min-h-0">
                            <span class="mt-0.5 block text-xs text-gray-400">кому не подходит</span>
                        </label>
                        <label class="block text-gray-600 dark:text-gray-300">Источники
                            <input type="text" name="sources" placeholder="EFSA, FDA…"
                                   class="n2j-input mt-1 w-full py-1.5 min-h-0">
                            <span class="mt-0.5 block text-xs text-gray-400">на что опирались</span>
                        </label>
                    </div>
                    <label class="block text-gray-600 dark:text-gray-300">Подробно
                        <textarea name="details" rows="5" class="n2j-textarea mt-1 w-full font-mono"></textarea>
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit"
                                class="n2j-btn-cta n2j-btn-sm">сохранить</button>
                        <button type="button" id="nj646-edit-cancel"
                                class="n2j-btn-secondary n2j-btn-sm">отмена</button>
                        <span id="nj646-edit-out" class="text-xs text-gray-400"></span>
                    </div>
                </form>

                <details class="mt-4 text-xs text-gray-600 dark:text-gray-300">
                    <summary class="cursor-pointer select-none text-blue-600 hover:underline dark:text-blue-400">служебное</summary>
                    <ul id="nj646-card-meta" class="mt-2 space-y-0.5 text-xs text-gray-500 dark:text-gray-400"></ul>
                </details>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var CSRF = <?php echo json_encode((string) $csrf); ?>;
    var LABELS = {
        verdict: <?php echo json_encode($verdict_labels, JSON_UNESCAPED_UNICODE); ?>,
        status: <?php echo json_encode($status_labels, JSON_UNESCAPED_UNICODE); ?>,
        kind: <?php echo json_encode($kind_labels, JSON_UNESCAPED_UNICODE); ?>,
        source: <?php echo json_encode($source_labels, JSON_UNESCAPED_UNICODE); ?>
    };
    var HINTS = <?php echo json_encode(array_map(function ($m) { return $m['hint']; }, $modes), JSON_UNESCAPED_UNICODE); ?>;
    var PAGE = <?php echo (int) $page; ?>;
    // Коды ограничений («allergy», «kids»…) — человеческими подписями: в режиме
    // чтения админ должен понимать карточку без расшифровки кодов.
    var REASONS = <?php echo json_encode((array) ($reasons ?? []), JSON_UNESCAPED_UNICODE); ?>;

    var RISK_DOT = ['bg-emerald-500', 'bg-gray-400', 'bg-amber-500', 'bg-rose-400', 'bg-rose-600'];

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(text) {
        return String(text === null || text === undefined ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify(data || {})
        }).then(function (r) { return r.json(); });
    }

    // flat — показать список по текущему пути сразу (пресеты), а не ещё один
    // уровень дерева; обычное раскрытие ветвей его сбрасывает.
    var state = { mode: 'category', path: [], q: '', offset: 0, total: 0, current: null, flat: false };
    var tree = $('#nj646-tree');
    var more = $('#nj646-more');
    var crumbs = $('#nj646-crumbs');

    function note(text, kind) {
        var box = $('#nj646-card-out');
        if (!box) { return; }
        var cls = { ok: 'font-semibold text-emerald-700 dark:text-emerald-300',
                    warn: 'font-semibold text-amber-700 dark:text-amber-300',
                    info: 'text-gray-500 dark:text-gray-400' };
        box.className = 'mt-2 text-xs ' + (cls[kind] || cls.info);
        box.innerHTML = text;
        box.classList.remove('hidden');
    }

    function badge(text, cls) {
        return '<span class="rounded-full px-2 py-0.5 font-semibold ' + cls + '">' + esc(text) + '</span>';
    }

    function renderCrumbs(crumbs_) {
        var html = '<button type="button" data-nj646-crumb="-1" class="rounded px-1.5 py-0.5 font-semibold text-blue-700 hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-900/30">весь справочник</button>';

        (crumbs_ || []).forEach(function (crumb, i) {
            html += '<span class="text-gray-300">/</span>'
                + '<button type="button" data-nj646-crumb="' + i + '" class="rounded px-1.5 py-0.5 hover:bg-gray-100 dark:hover:bg-gray-700">'
                + esc(crumb.label) + '</button>';
        });

        crumbs.innerHTML = html;

        $$('[data-nj646-crumb]', crumbs).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var level = Number(btn.getAttribute('data-nj646-crumb'));
                state.path = level < 0 ? [] : state.path.slice(0, level + 1);
                state.flat = false;
                load();
            });
        });
    }

    function groupRow(group) {
        var row = document.createElement('button');
        row.type = 'button';
        row.className = 'flex w-full items-center justify-between gap-2 rounded-lg border border-gray-100 px-2.5 py-1.5 text-left text-xs transition hover:border-blue-300 hover:bg-blue-50/40 dark:border-gray-700 dark:hover:border-blue-700 dark:hover:bg-blue-900/20';
        row.innerHTML = '<span class="flex min-w-0 items-center gap-1.5">'
            + '<i class="fas fa-folder text-amber-500"></i>'
            + '<span class="truncate font-semibold text-gray-800 dark:text-gray-100">' + esc(group.label) + '</span></span>'
            + '<span class="shrink-0 text-gray-400">' + Number(group.total) + ' →</span>';
        row.addEventListener('click', function () {
            // Раскрытая ветвь — это текущий путь ПЛЮС выбранное значение:
            // группы на экране всегда соответствуют уровню state.path.length.
            state.path = state.path.concat([group.key]);
            state.offset = 0;
            state.flat = false;
            load();
        });
        return row;
    }

    function itemRow(item) {
        var row = document.createElement('button');
        row.type = 'button';
        row.className = 'nj646-item flex w-full items-center gap-2 rounded-lg border border-gray-100 px-2.5 py-1.5 text-left text-xs transition hover:border-blue-300 hover:bg-blue-50/40 dark:border-gray-700 dark:hover:border-blue-700 dark:hover:bg-blue-900/20';
        row.setAttribute('data-id', item.id);

        var dot = RISK_DOT[Math.max(0, Math.min(4, Number(item.risk)))] || 'bg-gray-400';
        var status_cls = item.status === 'published'
            ? 'text-emerald-600 dark:text-emerald-400'
            : (item.status === 'draft' ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400');

        row.innerHTML = '<span class="h-2 w-2 shrink-0 rounded-full ' + dot + '"></span>'
            + '<span class="min-w-0 flex-1 truncate font-semibold text-gray-800 dark:text-gray-100">' + esc(item.name_ru || item.name_int || item.slug) + '</span>'
            + (item.e_code ? '<span class="shrink-0 text-gray-400">' + esc(item.e_code) + '</span>' : '')
            + '<span class="shrink-0 ' + status_cls + '">' + esc(LABELS.status[item.status] || item.status) + '</span>'
            + (item.source !== 'seed' ? '<span class="shrink-0 text-gray-400">' + esc(LABELS.source[item.source] || item.source) + '</span>' : '')
            + '<span class="shrink-0 text-gray-400">×' + Number(item.hits || 0) + '</span>';

        row.addEventListener('click', function () { select(item); });
        return row;
    }

    // ------------------------------------------------- РЕЖИМ ЧТЕНИЯ И ПРАВКИ
    //
    // Владелец (03.10.2026): «просмотр по умолчанию — на чтение, если надо
    // редактировать то нажать на карандашик, рядом иконка переобучить».
    // Поэтому карточка открывается читаемой (текст крупнее, без полей ввода),
    // а форма правки появляется только по кнопке.

    function listText(value) {
        if (Array.isArray(value)) { return value.filter(Boolean).join(', '); }
        return value === null || value === undefined ? '' : String(value);
    }

    function avoidText(value) {
        var list = Array.isArray(value) ? value : (value ? String(value).split(',') : []);
        var out = list.map(function (code) {
            code = String(code).trim();
            return REASONS[code] || code;
        }).filter(Boolean);

        return out.join('; ');
    }

    function renderView(item) {
        var set = function (id, text) {
            var box = document.getElementById(id);
            if (box) { box.textContent = text !== '' ? text : '—'; }
        };

        set('nj646-view-summary', listText(item.summary));
        set('nj646-view-details', listText(item.details));
        set('nj646-view-synonyms', listText(item.synonyms));
        set('nj646-view-avoid', avoidText(item.avoid));
        set('nj646-view-sources', listText(item.sources));
    }

    function openEdit() {
        document.getElementById('nj646-view').classList.add('hidden');
        document.getElementById('nj646-edit').classList.remove('hidden');
        document.getElementById('nj646-edit-toggle').classList.add('hidden');
    }

    function closeEdit() {
        document.getElementById('nj646-edit').classList.add('hidden');
        document.getElementById('nj646-view').classList.remove('hidden');
        document.getElementById('nj646-edit-toggle').classList.remove('hidden');
        document.getElementById('nj646-edit-out').textContent = '';
    }

    document.getElementById('nj646-edit-toggle').addEventListener('click', openEdit);
    document.getElementById('nj646-edit-cancel').addEventListener('click', closeEdit);

    function select(item) {
        state.current = item;

        $$('.nj646-item', tree).forEach(function (row) {
            row.classList.toggle('border-blue-400', Number(row.getAttribute('data-id')) === Number(item.id));
            row.classList.toggle('bg-blue-50', Number(row.getAttribute('data-id')) === Number(item.id));
            row.classList.toggle('dark:bg-blue-900/30', Number(row.getAttribute('data-id')) === Number(item.id));
        });

        $('#nj646-card-empty').classList.add('hidden');
        $('#nj646-card').classList.remove('hidden');

        note('', 'info');

        $('#nj646-card-name').textContent = item.name_ru || item.name_int || item.slug;
        $('#nj646-card-sub').textContent = [item.name_int, item.category, item.e_code].filter(Boolean).join(' · ');

        var flags = '<span class="h-2.5 w-2.5 rounded-full ' + (RISK_DOT[Number(item.risk)] || 'bg-gray-400') + '"></span>'
            + badge('риск ' + Number(item.risk), 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-200')
            + badge(LABELS.verdict[item.verdict] || item.verdict, 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300');

        if (item.status === 'draft') {
            flags += badge('черновик · ждёт проверки', 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300');
        }

        $('#nj646-card-flags').innerHTML = flags;

        var form = $('#nj646-edit');
        ['name_ru', 'name_int', 'category', 'e_code', 'summary', 'details'].forEach(function (field) {
            form.elements[field].value = item[field] || '';
        });
        // Списки показываем строкой через запятую — так их правят руками.
        ['synonyms', 'avoid', 'sources'].forEach(function (field) {
            var list = item[field];
            form.elements[field].value = Array.isArray(list) ? list.join(', ')
                : (list === null || list === undefined ? '' : String(list));
        });
        form.elements.kind.value = item.kind || 'both';
        form.elements.risk.value = Number(item.risk || 0);
        form.elements.verdict.value = item.verdict || 'caution';
        form.elements.status.value = item.status || 'draft';

        // Показываем карточку на чтение: правка — осознанное действие.
        renderView(item);
        closeEdit();

        $('#nj646-card-meta').innerHTML = [
            ['slug', item.slug],
            ['источник', LABELS.source[item.source] || item.source || '—'],
            ['уверенность', (item.confidence === null || item.confidence === undefined ? '—' : item.confidence + ' %')],
            ['встречается', (item.hits || 0) + ' раз']
        ].map(function (pair) {
            return '<li><span class="text-gray-400">' + esc(pair[0]) + ':</span> ' + esc(pair[1]) + '</li>';
        }).join('');
    }

    function load() {
        var mode = state.mode;
        tree.innerHTML = '<p class="text-xs text-gray-400">Загружаю…</p>';
        more.classList.add('hidden');

        post('/nj646/admin/tree', {
            csrf: CSRF, mode: mode, path: state.path, q: state.q, offset: state.offset,
            flat: state.flat ? 1 : 0
        }).then(function (data) {
            if (!data || !data.ok) {
                tree.innerHTML = '<p class="text-xs text-amber-600">Не удалось получить дерево.</p>';
                return;
            }

            renderCrumbs(data.crumbs);
            tree.innerHTML = '';

            // Поиск ищет в текущей ветке (на корне — по всему справочнику) и
            // работает на любом уровне, а не только в раскрытом листе.
            if (data.flat) {
                var flatNote = document.createElement('p');
                flatNote.className = 'mb-1 text-xs text-gray-500 dark:text-gray-400';
                flatNote.textContent = 'Список по выбранному условию: ' + Number(data.total)
                    + '. Хлебные крошки выше возвращают к обычному дереву.';
                tree.appendChild(flatNote);
            }

            if (data.search) {
                var head = document.createElement('p');
                head.className = 'mb-1 flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400';
                head.innerHTML = '<span>Найдено: ' + Number(data.total) + '</span>';
                tree.appendChild(head);

                var reset = document.createElement('button');
                reset.type = 'button';
                reset.className = 'mb-1 w-full rounded-lg border border-gray-200 px-2 py-1 text-xs font-semibold text-gray-500 hover:border-blue-300 hover:text-blue-700 dark:border-gray-700 dark:text-gray-400';
                reset.textContent = 'сбросить поиск';
                reset.addEventListener('click', function () {
                    $('#nj646-q').value = '';
                    state.q = '';
                    state.offset = 0;
                    state.flat = false;
                    load();
                });
                tree.appendChild(reset);
            }

            if (!data.leaf) {
                (data.groups || []).forEach(function (group) {
                    tree.appendChild(groupRow(group));
                });

                if (!(data.groups || []).length) {
                    tree.innerHTML = '<p class="text-xs text-gray-400">В этой ветке пусто.</p>';
                }
                return;
            }

            (data.items || []).forEach(function (item) {
                tree.appendChild(itemRow(item));
            });

            if (!(data.items || []).length) {
                tree.innerHTML = '<p class="text-xs text-gray-400">'
                    + (state.q ? 'Ничего не нашлось в этой ветке.' : 'В этой ветке пока нет веществ.') + '</p>';
                return;
            }

            var shown = state.offset + (data.items || []).length;

            if (shown < Number(data.total)) {
                more.classList.remove('hidden');
                $('#nj646-more-btn').textContent = 'показать ещё (' + shown + ' из ' + data.total + ')';
            }
        }).catch(function () {
            tree.innerHTML = '<p class="text-xs text-amber-600">Сеть недоступна — обновите страницу.</p>';
        });
    }

    $('#nj646-more-btn').addEventListener('click', function () {
        state.offset += PAGE;
        load();
    });

    // Пресет — это «режим + путь»: ставим их как будто админ сам раскрыл ветку.
    $$('.nj646-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var mode = btn.getAttribute('data-mode');
            var path = [];

            try { path = JSON.parse(btn.getAttribute('data-path')) || []; } catch (e) { path = []; }

            state.mode = mode;
            state.path = path;
            state.q = '';
            state.offset = 0;
            state.flat = true;
            $('#nj646-q').value = '';
            $('#nj646-mode').value = mode;
            $('#nj646-mode-hint').textContent = HINTS[mode] || '';
            load();
        });
    });

    $('#nj646-mode').addEventListener('change', function () {
        state.mode = this.value;
        state.path = [];
        state.offset = 0;
        state.flat = false;
        $('#nj646-mode-hint').textContent = HINTS[state.mode] || '';
        load();
    });

    // Поиск не сбрасывает ветку: на корне ищет по всему справочнику, внутри
    // ветви — по её веществам. Пустая строка возвращает обычное дерево.
    function runSearch() {
        state.q = $('#nj646-q').value.trim();
        state.offset = 0;
        load();
    }

    $('#nj646-q').addEventListener('input', function () {
        if (this.value.trim() === '' && state.q !== '') { runSearch(); }
    });

    $('#nj646-q-go').addEventListener('click', runSearch);
    $('#nj646-q').addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); runSearch(); }
    });

    // ------------------------------------------------------------- действия

    function act(action) {
        if (!state.current) { return; }

        post('/nj646/admin/ingredient_status/' + state.current.id, { csrf: CSRF, status: action })
            .then(function (data) {
                if (!data || !data.ok) { note(esc((data && data.message) || 'не получилось'), 'warn'); return; }
                state.current.status = action;
                note('Статус обновлён: ' + esc(LABELS.status[action] || action) + '.', 'ok');
                load();
            })
            .catch(function () { note('Сеть недоступна.', 'warn'); });
    }

    $$('[data-nj646-act]').forEach(function (btn) {
        btn.addEventListener('click', function () { act(btn.getAttribute('data-nj646-act')); });
    });

    $('#nj646-restudy').addEventListener('click', function () {
        if (!state.current) { return; }
        note('Отправляю вещество на переобучение — карточку заменит ответ модели…', 'info');

        post('/nj646/admin/ingredient_restudy/' + state.current.id, { csrf: CSRF })
            .then(function (data) {
                if (!data) { note('Сервер ответил непонятно.', 'warn'); return; }
                note(esc(data.message || 'Задача поставлена.'), data.ok ? 'ok' : 'warn');
            })
            .catch(function () { note('Сеть недоступна.', 'warn'); });
    });

    $('#nj646-delete').addEventListener('click', function () {
        if (!state.current) { return; }

        var name = state.current.name_ru || state.current.slug;

        var id = state.current.id;

        N2J.confirm({ title: 'Удалить карточку?', message: 'Удалить карточку «' + name + '»? Вещество вернётся в очередь неизученных.', ok: 'Удалить', tone: 'danger' }).then(function (yes) {
            if (!yes) { return; }

            post('/nj646/admin/ingredient_delete/' + id, { csrf: CSRF })
                .then(function (data) {
                    if (!data) { note('Сервер ответил непонятно.', 'warn'); return; }
                    note(esc(data.message || ''), data.ok ? 'ok' : 'warn');

                    if (data.ok) {
                        state.current = null;
                        $('#nj646-card').classList.add('hidden');
                        $('#nj646-card-empty').classList.remove('hidden');
                        load();
                    }
                })
                .catch(function () { note('Сеть недоступна.', 'warn'); });
        });
    });

    $('#nj646-edit').addEventListener('submit', function (event) {
        event.preventDefault();

        if (!state.current) { return; }

        var form = event.target;
        var payload = { csrf: CSRF };

        ['name_ru', 'name_int', 'category', 'e_code', 'summary', 'details', 'kind', 'risk', 'verdict', 'status',
         'synonyms', 'avoid', 'sources'].forEach(function (field) {
            payload[field] = form.elements[field].value;
        });

        $('#nj646-edit-out').textContent = 'Сохраняю…';

        post('/nj646/admin/ingredient_save/' + state.current.id, payload).then(function (data) {
            $('#nj646-edit-out').textContent = (data && data.message) || 'Готово.';

            if (data && data.ok) {
                Object.keys(payload).forEach(function (key) { state.current[key] = payload[key]; });

                // Списки правили строкой через запятую — возвращаем их массивами,
                // иначе режим чтения покажет «allergy, kids» как одну строку кода.
                ['synonyms', 'avoid', 'sources'].forEach(function (key) {
                    state.current[key] = String(payload[key] || '')
                        .split(',').map(function (x) { return x.trim(); }).filter(Boolean);
                });

                renderView(state.current);
                closeEdit();
                note('Правка сохранена — карточка помечена как правка админа.', 'ok');
            }
        }).catch(function () {
            $('#nj646-edit-out').textContent = 'Сеть недоступна.';
        });
    });

    load();
})();
</script>
