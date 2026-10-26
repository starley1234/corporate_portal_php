<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Админка «Разбора состава» — МОДЕРАЦИЯ (/nj646/admin/moderation).
 *
 * Владелец (03.10.2026): «/nj646/admin — тоже разбить на две страницы, одна про
 * дашборд и про то что разбирали, а вторая про карточки и незнакомые вещества».
 *
 * Здесь работа с базой знаний: черновики карточек, изученные ИИ вещества и
 * очередь незнакомых. Массовые операции — селект действия + «к выбранным» или
 * «ко всем»; действия обычные POST + CSRF, поэтому работают и без JS.
 *
 * Дашборд (цифры, очередь изучения, что разбирали) — /nj646/admin.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
        <?php
        // Цвет по исходу действия: «не получилось» должно выглядеть иначе, чем
        // «поставлено в очередь».
        $flash_class = ($flash_kind ?? '') === 'warn'
            ? 'border-amber-300/80 bg-amber-50/90 text-amber-900 dark:border-amber-800/80 dark:bg-amber-950/40 dark:text-amber-200'
            : 'border-blue-300/80 bg-blue-50/90 text-blue-900 dark:border-blue-800/80 dark:bg-blue-950/40 dark:text-blue-200';
        ?>
        <div class="mb-5 rounded-xl border px-4 py-3 text-sm <?php echo $flash_class; ?>">
            <i class="fas <?php echo ($flash_kind ?? '') === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-info'; ?> mr-1"></i><?php echo HTML::chars($flash); ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="n2j-h1">Модерация базы знаний</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                <?php echo (int) $drafts_total; ?> черновиков ждут проверки,
                <?php echo (int) $unknown_todo; ?> незнакомых веществ — изучения.
                Карточки, изученные ИИ, видны посетителям только после публикации.
                <?php if ( ! $llm_ready): ?>
                    <span class="text-amber-600 dark:text-amber-400">Модель сейчас не подключена — изучение вернёт отказ.</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            <a href="/nj646/admin"
               class="n2j-btn-secondary">
                <i class="fas fa-gauge-high mr-1"></i>дашборд
            </a>
            <a href="/nj646/admin/catalog"
               class="n2j-btn-secondary">
                <i class="fas fa-sitemap mr-1"></i>справочник деревом
            </a>
            <a href="/nj646/admin/import"
               class="n2j-btn-secondary">
                <i class="fas fa-file-csv mr-1"></i>импорт словаря
            </a>
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="n2j-card">
            <h2 id="nj646-drafts" class="n2j-h3">
                Черновики карточек
                <span class="ml-1 text-xs font-normal text-gray-400"><?php echo (int) $drafts_total; ?> ждут модерации</span>
                <a href="/nj646/admin/catalog" class="ml-2 text-xs font-normal text-blue-600 hover:underline dark:text-blue-400">открыть справочник деревом →</a>
            </h2>
            <?php if ( ! $drafts): ?>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Черновиков нет — база в порядке.</p>
            <?php else: ?>
                <!-- Массовая модерация: селект действия + «к выбранным» или «ко всем».
                     Пустая форма с id, а галочки/селект/кнопки связаны с ней
                     атрибутом form — вложенные формы в списке недопустимы
                     (в строках уже есть свои формы «опубликовать/скрыть»). -->
                <form id="nj646-bulk-drafts" method="post" action="/nj646/admin/bulk" class="nj646-bulk"
                      data-entity="draft"></form>
                <input type="hidden" form="nj646-bulk-drafts" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" form="nj646-bulk-drafts" name="entity" value="draft">
                <div class="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-gray-100 bg-gray-50/70 px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-900/40">
                    <label class="inline-flex cursor-pointer items-center gap-1.5 text-gray-600 dark:text-gray-300">
                        <input type="checkbox" id="nj646-drafts-all" class="nj646-check-all h-4 w-4 rounded border-gray-300 text-blue-600" data-target="nj646-bulk-drafts">
                        выбрать все
                    </label>
                    <select form="nj646-bulk-drafts" name="action"
                            class="n2j-input n2j-select py-1 text-xs min-h-0 w-auto">
                        <option value="publish">опубликовать</option>
                        <option value="hide">скрыть</option>
                        <option value="draft">вернуть в черновики</option>
                    </select>
                    <button form="nj646-bulk-drafts" type="submit" name="scope" value="selected"
                            class="n2j-btn-process n2j-btn-sm">применить к выбранным</button>
                    <button form="nj646-bulk-drafts" type="submit" name="scope" value="all"
                            data-nj646-bulk-all
                            class="n2j-btn-secondary n2j-btn-sm">
                        ко всем черновикам (<?php echo (int) $drafts_total; ?>)
                    </button>
                    <span class="text-gray-400">селект — что сделать, кнопки — к кому: галочки или весь список.</span>
                    <span class="text-gray-400" data-nj646-bulk-out="draft"></span>
                </div>
                <div class="mt-3 max-h-96 space-y-3 overflow-auto pr-1">
                    <?php foreach ($drafts as $item): ?>
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span class="flex min-w-0 items-center gap-2">
                                    <input type="checkbox" form="nj646-bulk-drafts" name="ids[]" value="<?php echo (int) $item['id']; ?>"
                                           class="nj646-check h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600" data-group="nj646-bulk-drafts">
                                    <span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($item['name_ru']); ?></span>
                                </span>
                                <span class="text-xs text-gray-400">
                                    риск <?php echo (int) $item['risk']; ?>/4 ·
                                    уверенность <?php echo (int) $item['confidence']; ?>% ·
                                    <?php echo HTML::chars($item['category']); ?>
                                </span>
                            </div>
                            <?php if ($item['summary'] !== ''): ?>
                                <p class="n2j-hint"><?php echo HTML::chars($item['summary']); ?></p>
                            <?php endif; ?>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <form method="post" action="/nj646/admin/ingredient_status/<?php echo (int) $item['id']; ?>">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <input type="hidden" name="status" value="published">
                                    <button type="submit" class="n2j-btn-cta n2j-btn-sm">Опубликовать</button>
                                </form>
                                <form method="post" action="/nj646/admin/ingredient_status/<?php echo (int) $item['id']; ?>">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <input type="hidden" name="status" value="hidden">
                                    <button type="submit" class="n2j-btn-danger n2j-btn-sm">Скрыть</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="n2j-card"
         id="nj646-unknowns">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="n2j-h3">
                    Незнакомые вещества
                    <span class="ml-1 text-xs font-normal text-gray-400"><?php echo (int) $unknown_todo; ?> ждут изучения</span>
                </h2>
            </div>
            <?php if ($unknown_todo > 0): ?>
                <!-- Те же массовые операции: выбрать действие (изучить/игнорировать)
                     и применить к галочкам или ко всей очереди. Пустая форма +
                     атрибут form: в строках есть свои формы, вкладывать нельзя. -->
                <form id="nj646-bulk-unknowns" method="post" action="/nj646/admin/bulk" class="nj646-bulk"
                      data-entity="unknown"></form>
                <input type="hidden" form="nj646-bulk-unknowns" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" form="nj646-bulk-unknowns" name="entity" value="unknown">
                <div class="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-gray-100 bg-gray-50/70 px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-900/40">
                    <label class="inline-flex cursor-pointer items-center gap-1.5 text-gray-600 dark:text-gray-300">
                        <input type="checkbox" id="nj646-unknowns-all" class="nj646-check-all h-4 w-4 rounded border-gray-300 text-blue-600" data-target="nj646-bulk-unknowns">
                        выбрать все
                    </label>
                    <select form="nj646-bulk-unknowns" name="action"
                            class="n2j-input n2j-select py-1 text-xs min-h-0 w-auto">
                        <option value="study">изучить</option>
                        <option value="ignore">игнорировать (мусор)</option>
                    </select>
                    <button form="nj646-bulk-unknowns" type="submit" name="scope" value="selected"
                            class="n2j-btn-process n2j-btn-sm">применить к выбранным</button>
                    <button form="nj646-bulk-unknowns" type="submit" name="scope" value="all"
                            data-nj646-bulk-all
                            class="n2j-btn-secondary n2j-btn-sm">
                        ко всей очереди (<?php echo (int) $unknown_todo; ?>)
                    </button>
                    <span class="text-gray-400">селект — что сделать, кнопки — к кому: галочки или весь список.</span>
                    <span class="text-gray-400" data-nj646-bulk-out="unknown"></span>
                </div>
            <?php endif; ?>

            <?php /* ---------------------------------------------------------------
                 Чистка очереди от служебных строк этикетки. Поводом был живой
                 разбор: до `Label::composition_block()` в очередь уезжали «5 г»,
                 «ГОСТ 24901-2014», «Изготовитель: ООО …» — и каждая строка
                 вставала на ОПЛАЧИВАЕМОЕ изучение моделью. Удаление в два клика:
                 сначала список, потом кнопка (как в импорте словаря).
            --------------------------------------------------------------- */ ?>
            <div class="mt-3 rounded-lg border border-gray-100 bg-gray-50/70 px-3 py-2 dark:border-gray-700 dark:bg-gray-900/40">
                <?php if ( ! $junk): ?>
                    <form method="post" action="/nj646/admin/unknowns_junk" class="flex flex-wrap items-center gap-2">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <button type="submit" class="n2j-btn-secondary n2j-btn-sm">
                            <i class="fas fa-broom mr-1.5 text-xs"></i>показать мусор в очереди
                        </button>
                        <span class="text-xs text-gray-400">
                            служебные строки с упаковки («5 г», «ГОСТ …», «Изготовитель: …») — их изучать не надо
                        </span>
                    </form>
                <?php else: ?>
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">
                        Похоже на служебные строки этикетки: <?php echo count($junk); ?>
                    </p>
                    <ul class="mt-2 max-h-56 space-y-1 overflow-auto pr-1 text-xs text-gray-600 dark:text-gray-300">
                        <?php foreach ($junk as $row): ?>
                            <li class="truncate">
                                <span class="text-gray-400">#<?php echo (int) $row['id']; ?></span>
                                <?php echo HTML::chars((string) $row['name']); ?>
                                <span class="text-gray-400">· встреч <?php echo (int) $row['seen_count']; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post" action="/nj646/admin/unknowns_junk_delete" class="mt-2 flex flex-wrap items-center gap-2">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <?php foreach ($junk as $row): ?>
                            <input type="hidden" name="ids[]" value="<?php echo (int) $row['id']; ?>">
                        <?php endforeach; ?>
                        <button type="submit" class="n2j-btn-danger n2j-btn-sm">
                            <i class="fas fa-trash mr-1.5 text-xs"></i>удалить эти <?php echo count($junk); ?>
                        </button>
                        <a href="/nj646/admin/moderation" class="n2j-btn-secondary n2j-btn-sm">
                            отмена
                        </a>
                        <span class="text-xs text-gray-400">удаляются только строки без карточки</span>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Место для сводки пачки: сколько поставлено, что мешало. -->
            <p class="mt-2 hidden text-xs" data-nj646-all-out></p>
            <?php if ( ! $unknowns): ?>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Очередь пуста.</p>
            <?php else: ?>
                <div class="mt-3 max-h-96 space-y-2 overflow-auto pr-1">
                    <?php foreach ($unknowns as $item): ?>
                        <div class="rounded-lg border border-gray-100 px-3 py-2 text-sm dark:border-gray-700"
                             data-nj646-unknown="<?php echo (int) $item['id']; ?>">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="flex min-w-0 flex-1 items-center gap-2 text-gray-700 dark:text-gray-200">
                                    <?php if ($item['status'] !== 'studied'): ?>
                                        <input type="checkbox" form="nj646-bulk-unknowns" name="ids[]" value="<?php echo (int) $item['id']; ?>"
                                               class="nj646-check h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600" data-group="nj646-bulk-unknowns">
                                    <?php endif; ?>
                                    <?php echo HTML::chars($item['name_raw']); ?>
                                    <span class="ml-1 text-xs text-gray-400">
                                        ×<?php echo (int) $item['seen_count']; ?> ·
                                        <?php // Статус незнакомца — по-русски (i18n модуля), код в title. ?>
                                        <span data-nj646-status title="<?php echo HTML::chars((string) $item['status']); ?>"><?php
                                            echo HTML::chars(Service_Nj646_Label::status_label((string) $item['status'], 'unknown')); ?></span>
                                    </span>
                                </span>
                                <?php if ($item['status'] !== 'studied'): ?>
                                    <!-- Кнопка работает и без JS (обычный POST с редиректом),
                                         а с JS отвечает сразу: «изучаю…» → «готово»/причина отказа. -->
                                    <form method="post" action="/nj646/admin/study/<?php echo (int) $item['id']; ?>"
                                          class="nj646-study-form" data-name="<?php echo HTML::chars($item['name_raw']); ?>">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <input type="hidden" name="ajax" value="1" disabled>
                                        <button type="submit" data-nj646-study
                                                class="n2j-btn-secondary n2j-btn-sm">Изучить</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-emerald-600 dark:text-emerald-400">
                                        <i class="fas fa-circle-check mr-1"></i>изучено
                                    </span>
                                <?php endif; ?>
                                <form method="post" action="/nj646/admin/ignore/<?php echo (int) $item['id']; ?>">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <button type="submit" class="n2j-btn-secondary n2j-btn-sm">Игнор</button>
                                </form>
                            </div>
                            <!-- Место для ответа действия: причина отказа, ход изучения, ссылка на черновик. -->
                            <p class="mt-1 hidden text-xs" data-nj646-out></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<script>
/**
 * Изучение вещества из админки — без перезагрузки страницы.
 *
 * Повод (03.10.2026): владелец нажимал «Изучить» и попадал на
 * «?flash=Очередь+недоступна%3A+» — с пустой причиной и без понимания, что
 * произошло, хотя воркер работал. Причина была в том, что постановка отдавала
 * NULL на всё сразу (лимит, нет модели, очередь), а страница показывала одну
 * фразу на все случаи и теряла контекст (какое вещество, какой прогон).
 *
 * Теперь: кнопка → «изучаю…» → состояние прогона прямо в строке
 * («в очереди» → «изучаю» → «✔ изучено, карточка-черновик») либо честная
 * причина отказа. Без JavaScript форма отправляется обычным POST и работает
 * прежним путём (редирект 303 с сообщением).
 */
(function () {
    'use strict';

    // Выборка элементов: страница самодостаточна (не тянет N646), поэтому
    // хелпер объявлен здесь. Без него «выбрать все» падало в консоли
    // «Uncaught ReferenceError: $$ is not defined» — кнопка просто не работала,
    // а человек видел, что «выбрать все» не выбирает (жалоба владельца).
    function $$(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    var CSRF = <?php echo json_encode($csrf); ?>;
    // Подписи статусов приходят из i18n модуля (modules/nj646_label/i18n/ru.php):
    // перевод живёт в одном месте и для страницы, и для того, что дорисовывает JS.
    var STATUS = <?php echo json_encode(Service_Nj646_Label::status_labels('run') + ['cancelled' => 'отменено'], JSON_UNESCAPED_UNICODE); ?>;
    var STATUS_UNKNOWN = <?php echo json_encode(Service_Nj646_Label::status_labels('unknown'), JSON_UNESCAPED_UNICODE); ?>;

    function out(box, text, kind) {
        var p = box.querySelector('[data-nj646-out]');
        var cls = { ok: 'text-emerald-700 dark:text-emerald-300',
                    warn: 'text-amber-700 dark:text-amber-300',
                    info: 'text-gray-500 dark:text-gray-400' };
        p.className = 'mt-1 text-xs ' + (cls[kind] || cls.info);
        p.innerHTML = text;
        p.classList.remove('hidden');
    }

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = text === null || text === undefined ? '' : String(text);
        return d.innerHTML;
    }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify(data || {})
        }).then(function (r) { return r.json(); });
    }

    function watch(box, runId, name, tries) {
        if (tries <= 0) {
            out(box, esc(name) + ': задача ещё выполняется — обновите страницу через минуту.', 'info');
            return;
        }
        setTimeout(function () {
            post('/nj646/admin/states', { csrf: CSRF, runs: [runId] }).then(function (data) {
                var run = data && data.runs && data.runs[0];
                if (!run) {
                    out(box, esc(name) + ': задача #' + runId + ' поставлена, состояние пока неизвестно.', 'info');
                    return;
                }
                var status = box.querySelector('[data-nj646-status]');
                box.querySelector('[data-nj646-study]').textContent = STATUS[run.status] || run.status;
                if (status) {
                    status.textContent = run.status === 'done'
                        ? STATUS_UNKNOWN.studied
                        : (STATUS[run.status] || run.status);
                }

                if (!run.done) {
                    // Этап и секундомер воркера: «изучаю» без подробностей
                    // выглядело зависанием (жалоба владельца про индикацию).
                    var live = run.live || {};
                    var tail = '';
                    if (live.stage) { tail += ' · ' + esc(live.stage); }
                    if (typeof live.elapsed_sec === 'number') {
                        tail += ' · ' + Math.floor(live.elapsed_sec / 60) + ':' + ('0' + (live.elapsed_sec % 60)).slice(-2);
                    }
                    if (typeof live.silent_sec === 'number' && live.silent_sec > 180) {
                        tail += ' · нет пульса';
                    }
                    out(box, esc(name) + ': ' + (STATUS[run.status] || run.status) + tail + '…', 'info');
                    watch(box, runId, name, tries - 1);
                    return;
                }
                if (run.ok) {
                    out(box, '✔ ' + esc(name) + ': карточка «' + esc(run.name) + '» собрана — '
                        + (run.draft ? 'это черновик, он появится в списке ниже' : 'опубликована')
                        + '. <a class="underline" href="#nj646-drafts">к черновикам</a>', 'ok');
                    return;
                }
                out(box, '⚠ ' + esc(name) + ': модель не дала карточку — ' + esc(run.error || 'причина не записана'), 'warn');
            }).catch(function () {
                out(box, esc(name) + ': сеть недоступна, состояние не узнать.', 'warn');
            });
        }, 2500);
    }

    $$('.nj646-study-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var box = form.closest('[data-nj646-unknown]');
            var btn = form.querySelector('[data-nj646-study]');
            var name = form.getAttribute('data-name') || '';

            btn.disabled = true;
            btn.textContent = 'отправляю…';
            out(box, esc(name) + ': ставлю задачу в очередь…', 'info');

            post(form.getAttribute('action'), { csrf: CSRF, ajax: 1 }).then(function (data) {
                if (!data) { out(box, esc(name) + ': сервер ответил непонятно.', 'warn'); btn.disabled = false; btn.textContent = 'ещё раз'; return; }

                if (data.code === 'already') {
                    // «уже изучено» или «уже изучается» — текст говорит сервер,
                    // чтобы страница не додумывала за него.
                    out(box, '✔ ' + esc(data.message || (name + ': уже изучено.')), 'ok');
                    btn.textContent = data.run_id ? 'изучаю…' : 'изучено';
                    if (data.run_id) { watch(box, data.run_id, name, 20); }
                    return;
                }

                if (!data.ok) {
                    // Настоящая причина: лимит, модель, очередь — не «что-то пошло не так».
                    out(box, '⚠ ' + esc(data.message || 'не получилось поставить задачу'), 'warn');
                    btn.disabled = false;
                    btn.textContent = 'ещё раз';
                    return;
                }

                out(box, esc(name) + ': задача #' + data.run_id + ' в очереди, изучаю…', 'info');
                btn.textContent = 'изучаю…';
                watch(box, data.run_id, name, 20);
            }).catch(function () {
                out(box, esc(name) + ': сеть недоступна — попробуйте ещё раз.', 'warn');
                btn.disabled = false;
                btn.textContent = 'ещё раз';
            });
        });
    });

    // ------------------------------------------------- МАССОВЫЕ ОПЕРАЦИИ
    //
    // Владелец (03.10.2026): «сделай удобнее массовые операции для админа
    // (изучить всё, замодерировать всё и т.п.) — может через селекты».
    // Одна форма на список: селект действия + «применить к выбранным» или
    // «ко всем». Без JS форма уходит обычным POST и возвращает страницу с
    // сообщением; с JS — тот же запрос JSON'ом, сводка на месте и опрос
    // прогонов, если это была пачка «изучить».
    var allOut = document.querySelector('[data-nj646-all-out]');
    var bulkBusy = false;

    function allOutText(text, kind) {
        if (!allOut) { return; }
        var cls = { ok: 'text-emerald-700 dark:text-emerald-300',
                    warn: 'text-amber-700 dark:text-amber-300',
                    info: 'text-gray-500 dark:text-gray-400' };
        allOut.className = 'mt-2 text-xs ' + (cls[kind] || cls.info);
        allOut.innerHTML = text;
        allOut.classList.remove('hidden');
    }

    function rowOf(unknownId) {
        return document.querySelector('[data-nj646-unknown="' + unknownId + '"]');
    }

    // Общий опрос пачки прогонов: один запрос на все вместо десятка.
    function watchAll(runIds, rows, tries) {
        if (tries <= 0) {
            allOutText('Задачи ещё выполняются — обновите страницу через минуту.', 'info');
            return;
        }
        setTimeout(function () {
            post('/nj646/admin/states', { csrf: CSRF, runs: runIds }).then(function (data) {
                var runs = (data && data.runs) || [];
                var done = 0, failed = 0, lastError = '';

                runs.forEach(function (run) {
                    var row = rows[run.run_id];

                    if (run.done) {
                        done++;

                        if (run.ok) {
                            if (row) {
                                var st = row.querySelector('[data-nj646-status]');
                                if (st) { st.textContent = STATUS_UNKNOWN.studied; }
                                out(row, '✔ ' + esc(run.name) + ': карточка «' + esc(run.name)
                                    + '» собрана — ' + (run.draft ? 'черновик, ждёт проверки' : 'опубликована') + '.', 'ok');
                            }
                        } else {
                            failed++;
                            lastError = run.error || 'причина не записана';
                        }
                    } else if (row) {
                        var st2 = row.querySelector('[data-nj646-status]');
                        if (st2) { st2.textContent = STATUS[run.status] || STATUS.queued; }
                    }
                });

                if (done < runs.length) {
                    allOutText('Изучаю: готово ' + done + ' из ' + runs.length
                        + (failed ? ', сбоев ' + failed : '') + '…', 'info');
                    watchAll(runIds, rows, tries - 1);
                    return;
                }

                allOutText('Готово: ' + done + ' из ' + runs.length
                    + (failed ? ' · сбоев: ' + failed + ' (' + esc(lastError) + ')' : '')
                    + '. <a class="underline" href="#nj646-drafts">к черновикам</a>',
                    failed ? 'warn' : 'ok');
            }).catch(function () {
                allOutText('Сеть недоступна — состояние пачки не узнать.', 'warn');
            });
        }, 2500);
    }

    // «Выбрать все» в шапке списка отмечает галочки своей группы — и снимает
    // их тем же кликом. Мастер следует за строками: снял галочку в строке —
    // мастер погас (иначе он врал бы «выбрано всё» при пустом выборе).
    $$('.nj646-check-all').forEach(function (master) {
        var form = document.getElementById(master.getAttribute('data-target'));

        if (!form) { return; }

        var boxes = function () {
            return $$('input[data-group="' + form.id + '"]');
        };

        master.addEventListener('change', function () {
            boxes().forEach(function (box) {
                box.checked = master.checked;
            });
        });

        boxes().forEach(function (box) {
            box.addEventListener('change', function () {
                var all = boxes();
                var on  = all.filter(function (b) { return b.checked; }).length;

                master.checked = on > 0 && on === all.length;
                master.indeterminate = on > 0 && on < all.length;
            });
        });
    });

    function bulkOut(entity) {
        return document.querySelector('[data-nj646-bulk-out="' + entity + '"]');
    }

    function bulkNote(entity, text, kind) {
        var box = bulkOut(entity);

        if (!box) { return; }

        var cls = { ok: 'font-semibold text-emerald-700 dark:text-emerald-300',
                    warn: 'font-semibold text-amber-700 dark:text-amber-300',
                    info: 'text-gray-500 dark:text-gray-400' };
        box.className = (cls[kind] || cls.info);
        box.innerHTML = text;
    }

    $$('.nj646-bulk').forEach(function (form) {
        var entity = form.getAttribute('data-entity');
        var select = document.querySelector('select[form="' + form.id + '"]');
        var allBtn = form.parentNode.querySelector('[data-nj646-bulk-all]');

        // «Ко всем» должно называть, что именно сделает: действие выбирается
        // селектом рядом, и подпись кнопки обязана за ним следовать.
        if (select && allBtn) {
            var base = allBtn.textContent.trim();
            var syncAll = function () {
                var option = select.options[select.selectedIndex];
                allBtn.textContent = (option ? option.textContent : 'применить') + ' — ' + base;
            };

            select.addEventListener('change', syncAll);
            syncAll();
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (bulkBusy) { return; }

            // Кнопки и селект связаны с формой атрибутом form (в строках списка
            // свои формы — вкладывать нельзя), поэтому ищем их по документу.
            var scope  = (event.submitter && event.submitter.value) || 'selected';
            var action = select ? select.value : '';
            var ids    = $$('input[data-group="' + form.id + '"]:checked').map(function (box) {
                return Number(box.value);
            });

            if (scope === 'selected' && !ids.length) {
                bulkNote(entity, 'Отметьте строки галочками или нажмите «ко всем».', 'warn');
                return;
            }

            bulkBusy = true;
            bulkNote(entity, 'Применяю…', 'info');

            post('/nj646/admin/bulk', { csrf: CSRF, entity: entity, action: action, scope: scope, ids: ids })
                .then(function (data) {
                    bulkBusy = false;

                    if (!data) {
                        bulkNote(entity, 'Сервер ответил непонятно.', 'warn');
                        return;
                    }

                    // «Уже изучено/изучать нечего» — справка, а не ошибка.
                    var kind = data.ok ? 'ok' : (data.code === 'already' || data.code === 'empty' ? 'info' : 'warn');
                    bulkNote(entity, esc(data.message || 'Готово.'), kind);

                    var runs = data.runs || [];

                    if (action === 'study' && runs.length) {
                        allOutText(esc(data.message), 'info');

                        var byRun = {};

                        (data.queued || []).forEach(function (item) {
                            var row = rowOf(item.unknown_id);

                            if (row) { byRun[item.run_id] = row; }
                        });

                        watchAll(runs, byRun, 40);
                        return;
                    }

                    // Массовая операция меняет списки — показываем их честно.
                    setTimeout(function () { location.reload(); }, 1200);
                })
                .catch(function () {
                    bulkBusy = false;
                    bulkNote(entity, 'Сеть недоступна — попробуйте ещё раз.', 'warn');
                });
        });
    });

    // Формы работают и без JS — тогда отправляем ajax=1 только если он жив.
    $$('.nj646-study-form input[name=ajax]').forEach(function (input) {
        input.disabled = false;
    });
})();
</script>
