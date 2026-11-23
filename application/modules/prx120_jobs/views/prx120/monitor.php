<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120/monitor — «Монитор воркеров»: пульс воркера, очередь, последние 50 задач,
 * ошибки, параметры, отменить/прервать/удалить (two-click). Автообновление 15 с.
 */
$st = [
    'pending'    => ['Ожидает', 'prx-badge-wait'],
    'processing' => ['Выполняется', 'prx-badge-run'],
    'done'       => ['Готово', 'prx-badge-ok'],
    'failed'     => ['Ошибка', 'prx-badge-err'],
    'cancelled'  => ['Отменена', 'prx-badge-wait'],
];
$c   = function ($v) { return number_format((float) $v, 0, ',', ' '); };
$dt  = function ($v) { return $v ? HTML::chars(Date::formatted_time((string) $v, 'd.m.y H:i:s')) : '—'; };
$verdict = [
    'ok'    => ['Воркер на связи', 'text-emerald-600 dark:text-emerald-400', 'fa-heart-pulse'],
    // Формулировка под новый счётчик: «застряли» = пульс молчит дольше
    // авто-стопа ИЛИ прогресс не обновляется. Воркер при этом может быть жив —
    // раньше подпись «воркер молчит» врала именно в этом случае (28.09).
    'stuck' => ['Работа встала — задачи закрываются сами', 'text-rose-600 dark:text-rose-400', 'fa-triangle-exclamation'],
    'idle'  => ['Нет активных задач', 'text-gray-500 dark:text-gray-400', 'fa-moon'],
];
$v = $verdict[$health['verdict']] ?? $verdict['idle'];
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/tasks" class="transition hover:text-emerald-600 dark:hover:text-emerald-400">Задачи</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Монитор воркеров</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
        <h1 class="prx-h1">
            <i class="fas fa-heart-pulse mr-2 text-emerald-600 dark:text-emerald-400"></i>Монитор воркеров
        </h1>
        <div class="flex flex-wrap items-center gap-3">
            <a href="/prx120/workers" class="prx-btn-secondary prx-btn-sm">
                <i class="fas fa-book text-xs"></i>Документация воркеров
            </a>
            <form method="post" action="/prx120/monitor" class="inline">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <button type="submit" name="act" value="probe" class="prx-btn-secondary prx-btn-sm">
                    <i class="fas fa-heart-pulse text-xs"></i>Проверить воркер
                </button>
            </form>
            <?php if ((int) $stale > 0): ?>
            <!-- Зависшие задачи: чистятся одним кликом -->
            <form data-njmon-ajax method="post" action="/prx120/monitor<?php echo $p !== '' ? '?p='.$p : ''; ?>" class="inline">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="kill_stale">
                <button data-prx-confirm="Будут прерваны задачи «выполняется» без пульса дольше 30 минут." data-prx-title="Прервать все зависшие?" data-prx-ok="Прервать" type="submit" title="Задачи «выполняется» без пульса дольше 30 минут" class="prx-btn-danger prx-btn-sm">
                    <i class="fas fa-skull-crossbones text-xs"></i>Прервать зависшие (<?php echo (int) $stale; ?>)
                </button>
            </form>
            <?php endif; ?>
            <!-- Очистить все записи (журнал задач целиком) -->
            <form data-njmon-ajax method="post" action="/prx120/monitor<?php echo $p !== '' ? '?p='.$p : ''; ?>" class="inline">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="clear">
                <button data-prx-confirm="Удалятся ВСЕ записи задач: выполненные, ожидающие, исполняющиеся." data-prx-title="Очистить все записи?" data-prx-ok="Очистить" type="submit" title="Удалить ВСЕ записи задач: выполненные, ожидающие, исполняющиеся" class="prx-btn-secondary prx-btn-sm">
                    <i class="fas fa-eraser text-xs"></i>Очистить все записи
                </button>
            </form>
            <label class="flex cursor-pointer items-center gap-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
                <input type="checkbox" id="njmon-auto" checked class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
                автообновление 15 с
            </label>
        </div>
    </div>

    <?php if ( ! empty($flash)): ?>
    <div class="prx-flash prx-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div id="njmon-health" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="prx-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Ожидают</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo $c($health['counts']['pending'] ?? 0); ?></p>
        </div>
        <div class="prx-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Исполняется</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo $c($health['processing']); ?></p>
            <?php if ($health['stuck'] > 0): ?>
            <p class="mt-0.5 text-sm font-bold text-rose-500">застряли: <?php echo $health['stuck']; ?></p>
            <?php if (($health['silent_now'] ?? 0) > 0 OR ($health['stalled_now'] ?? 0) > 0): ?>
            <p class="prx-hint mt-0.5">
                без пульса: <?php echo (int) ($health['silent_now'] ?? 0); ?> ·
                без прогресса: <?php echo (int) ($health['stalled_now'] ?? 0); ?>
            </p>
            <?php endif; ?>
            <?php /* Честно объясняем, что будет дальше: пульса нет дольше 2 минут —
             * такую задачу закрывает авто-стоп (порог — silent floor), а не
             * «висит вечно». Владелец читал «застряли: 1» как поломку. */ ?>
            <p class="prx-hint mt-0.5">
                пульс молчит &gt; <?php echo max(1, (int) round(($health['silent_sec'] ?? $silent_stop) / 60)); ?> мин
                или прогресс стоит &gt; <?php echo max(1, (int) round(($health['stalled_sec'] ?? 600) / 60)); ?> мин ·
                закроются сами (без крона — при любом заходе на сайт)
            </p>
            <?php endif; ?>
        </div>
        <div class="prx-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Здоровье воркера</p>
            <p class="mt-1 flex items-center gap-1.5 text-sm font-black <?php echo $v[1]; ?>"><i class="fas <?php echo $v[2]; ?>"></i><?php echo $v[0]; ?></p>
            <?php if ($health['verdict'] === 'stuck'): ?>
            <p class="prx-hint mt-0.5">решение: уборка идёт при каждом заходе на сайт и по крону — ждать не нужно; если надо сейчас, кнопка «Прервать зависшие»</p>
            <?php endif; ?>
            <p class="mt-0.5 truncate text-sm text-gray-400" title="<?php echo HTML::chars(implode(', ', $health['workers'])); ?>">
                <?php echo $health['workers'] ? HTML::chars(implode(', ', $health['workers'])) : 'за 24 ч не видны'; ?>
                <?php if ($health['beat_ago'] !== NULL): ?>· пульс <?php echo (int) $health['beat_ago']; ?> с назад<?php endif; ?>
            </p>
            <?php if ( ! empty($health['last_finished'])): ?>
                <?php $lf = $health['last_finished'][0]; ?>
                <p class="prx-hint mt-1.5" title="<?php echo HTML::chars($lf['finish_at']); ?>">
                    последний статус: <span class="font-bold <?php echo $lf['status'] === 'done' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'; ?>"><?php echo $lf['status'] === 'done' ? 'готово' : 'ошибка'; ?></span>
                    · <?php echo HTML::chars($lf['label']); ?>
                    <span class="text-gray-400 dark:text-gray-500"><?php echo Date::formatted_time((string) $lf['finish_at'], 'H:i'); ?></span>
                </p>
            <?php else: ?>
                <p class="prx-hint mt-1.5">завершённых задач ещё нет</p>
            <?php endif; ?>
        </div>
        <div class="prx-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Готово за 24 ч</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo $c($health['done_24h']); ?></p>
            <?php if ($health['avg_ms_24h'] !== NULL): ?><p class="prx-hint mt-0.5">ср. время <?php echo $c(($health['avg_ms_24h'] / 1000)); ?> с</p><?php endif; ?>
        </div>
        <a href="/prx120/monitor?p=failed" class="prx-card transition hover:border-rose-300 dark:hover:border-rose-800">
            <p class="text-xs font-semibold uppercase text-gray-400">Ошибки за 24 ч</p>
            <p class="mt-1 text-2xl font-black <?php echo $health['failed_24h'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-white'; ?>"><?php echo $c($health['failed_24h']); ?></p>
        </a>
    </div>

    <div id="njmon-now" class="prx-card mt-3 <?php echo empty($health['now_doing']) ? 'hidden' : ''; ?>">
        <p class="text-xs font-semibold uppercase text-gray-400">Воркер сейчас делает</p>
        <ul class="mt-1.5 space-y-1">
            <?php foreach ((array) $health['now_doing'] as $nd): ?>
            <li class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <?php
                $nd_silent  = $nd['beat_at'] !== '' ? time() - strtotime((string) $nd['beat_at']) : NULL;
                $nd_stalled = $nd['progress_ago'] ?? NULL;
                // Скобки обязательны: AND слабее присваивания — без них третье
                // условие (возраст последнего рапорта) не учитывалось, и воркер
                // с застывшим прогрессом горел зелёным «работает».
                $nd_ok      = (($nd_silent !== NULL AND $nd_silent < (int) ($health['silent_sec'] ?? 240))
                           AND ($nd_stalled !== NULL ? $nd_stalled < (int) ($health['stalled_sec'] ?? 600) : TRUE));
                ?>
                <span class="inline-flex h-2 w-2 shrink-0 <?php echo $nd_ok ? 'animate-pulse bg-emerald-500' : 'bg-amber-500'; ?>"></span>
                <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($nd['label']); ?></span>
                <?php if (! empty($nd['stage'])): ?><span class="truncate text-blue-600 dark:text-blue-400" title="Этап воркера"><?php echo HTML::chars($nd['stage']); ?></span>
                <?php elseif ($nd['title'] !== ''): ?><span class="truncate text-gray-500 dark:text-gray-400">— <?php echo HTML::chars($nd['title']); ?></span><?php endif; ?>
                <span class="ml-auto shrink-0 text-gray-400">
                    <?php /* Раньше здесь был только пульс: «пульс 10 с назад» при
                     * застывшем этапе выглядел как живая работа. Теперь рядом
                     * видно и возраст последнего рапорта о ходе. */ ?>
                    <?php if ($nd['progress_ago'] !== NULL): ?>
                    <span class="<?php echo ($nd_stalled !== NULL AND $nd_stalled >= (int) ($health['stalled_sec'] ?? 600)) ? 'font-semibold text-amber-600 dark:text-amber-400' : ''; ?>">прогресс <?php echo (int) floor($nd['progress_ago'] / 60) > 0 ? (int) floor($nd['progress_ago'] / 60).' мин' : (int) $nd['progress_ago'].' с'; ?> назад</span> ·
                    <?php endif; ?>
                    пульс <?php echo $nd['beat_at'] !== '' ? Date::formatted_time((string) $nd['beat_at'], 'H:i:s') : '—'; ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div id="njmon-filters" class="prx-seg mt-4 flex-wrap gap-1">
        <a href="/prx120/monitor" class="prx-seg-item<?php echo $p === '' ? ' is-active' : ''; ?>">Все</a>
        <?php foreach ($st as $k => [$label, $cls]): ?>
        <a href="/prx120/monitor?p=<?php echo $k; ?>" class="prx-seg-item<?php echo $p === $k ? ' is-active' : ''; ?>"><?php echo $label; ?> (<?php echo $c($health['counts'][$k] ?? 0); ?>)</a>
        <?php endforeach; ?>
    </div>

    <div id="njmon-table" class="prx-card p-0 mt-3 overflow-x-auto">
        <table class="prx-table">
            <thead>
                <tr class="text-left text-gray-400">
                    <th class="px-3 py-2.5">#</th><th>Задача</th><th>Кто поставил</th><th>Статус</th><th>Создана</th><th>Длит.</th><th class="text-right pr-3">Действия</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 dark:text-gray-300">
                <?php foreach ($jobs as $r): [$stl, $stc] = $st[$r['status']] ?? [$r['status'], 'prx-badge-wait']; ?>
                <tr class="border-t border-gray-50 align-top dark:border-gray-700/50">
                    <td class="px-3 py-2 font-mono text-gray-400"><a href="/prx120/task/<?php echo (int) $r['id']; ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400" title="Открыть журнал задачи"><?php echo (int) $r['id']; ?></a></td>
                    <td class="max-w-[16rem] py-2">
                        <p class="truncate font-semibold" title="<?php echo HTML::chars($r['label']); ?>"><?php if ( ! empty($r['url'])): ?><a href="<?php echo HTML::chars($r['url']); ?>" class="text-blue-600 hover:underline dark:text-blue-400" title="Открыть результат"><?php echo HTML::chars($r['title'] ?: $r['label']); ?></a><?php else: echo HTML::chars($r['title'] ?: $r['label']); endif; ?></p>
                        <p class="mt-0.5 flex items-center gap-1.5 text-gray-400">
                            <span class="prx-count"><?php echo HTML::chars($r['label']); ?></span>
                            <?php if ((int) $r['attempts'] > 1): ?><span>попытка <?php echo (int) $r['attempts']; ?>/<?php echo (int) $r['max_attempts']; ?></span><?php endif; ?>
                        </p>
                        <?php // Этап воркера: что задача делает ПРЯМО СЕЙЧАС (ctx.progress → журнал) ?>
                        <?php if ($r['status'] === 'processing' AND ! empty($r['stage'])): ?>
                        <p class="mt-0.5 truncate text-xs text-blue-600 dark:text-blue-400" title="<?php echo HTML::chars((string) $r['stage']); ?>">
                            <i class="fas fa-circle-notch fa-spin mr-1 text-[10px]"></i><?php echo HTML::chars(mb_substr((string) $r['stage'], 0, 90)); ?>
                            <?php if ( ! empty($r['stage_at'])): ?><span class="text-gray-400">· <?php echo Date::formatted_time((string) $r['stage_at'], 'H:i:s'); ?></span><?php endif; ?>
                        </p>
                        <?php endif; ?>
                    </td>
                    <td class="max-w-[10rem] py-2 truncate" title="visitor: <?php echo HTML::chars($r['visitor_id']); ?>"><?php echo HTML::chars($r['owner_name'] ?: (($r['visitor_id'] !== '' AND $r['visitor_id'] !== 'anon') ? $r['visitor_id'] : 'система')); ?></td>
                    <td class="py-2">
                        <span class="prx-badge whitespace-nowrap <?php echo $stc; ?>"><span class="prx-dot"></span><?php echo $stl; ?></span>
                        <?php if ($r['status'] === 'processing' AND ! empty($r['heartbeat_at'])): ?>
                        <?php $silent = time() - strtotime((string) $r['heartbeat_at']); ?>
                        <?php $stop_left = max(0, (int) $silent_stop - $silent); ?>
                        <?php if ($silent > 1800): ?><span class="prx-badge prx-badge-err ml-1 whitespace-nowrap" title="Воркер не подаёт признаков жизни"><span class="prx-dot"></span>нет пульса <?php echo $c($silent / 60); ?> мин</span>
                        <?php elseif ($silent > 120): ?><span class="prx-badge prx-badge-run ml-1 whitespace-nowrap"><span class="prx-dot"></span>пульс <?php echo $c($silent / 60); ?> мин</span>
                        <?php endif; ?>
                        <?php // Что будет дальше: свипер закроет строку через N секунд, объяснять это до визита админа некому. ?>
                        <?php if ($silent > 60): ?>
                        <span class="prx-count ml-1 whitespace-nowrap" title="Порог авто-остановки: <?php echo (int) $silent_stop; ?> с молчания (PRX_SILENT_STOP_SEC)">авто-стоп через <?php echo $c($stop_left); ?> с</span>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ( ! empty($r['error'])): ?><p class="mt-1 max-w-[14rem] truncate text-rose-500" title="<?php echo HTML::chars($r['error']); ?>"><?php echo HTML::chars(mb_substr((string) $r['error'], 0, 60)); ?></p><?php endif; ?>
                    </td>
                    <td class="whitespace-nowrap py-2 text-gray-400"><?php echo $dt($r['created_at']); ?></td>
                    <td class="whitespace-nowrap py-2 text-gray-400"><?php
                        if ($r['started_at'] AND $r['finished_at'])
                        {
                            echo $c((strtotime((string) $r['finished_at']) - strtotime((string) $r['started_at']))). ' с';
                        }
                        elseif ($r['status'] === 'processing' AND $r['started_at'])
                        {
                            echo $c((time() - strtotime((string) $r['started_at']))).' с…';
                        }
                        else { echo '—'; }
                    ?></td>
                    <td class="whitespace-nowrap py-2 text-right">
                        <button type="button" data-njmon-details="<?php echo (int) $r['id']; ?>" class="prx-btn-secondary prx-btn-sm">Параметры</button>
                        <?php if ($r['status'] === 'pending'): ?>
                        <form data-njmon-ajax method="post" action="/prx120/monitor<?php echo $p !== '' ? '?p='.$p : ''; ?>" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="cancel"><input type="hidden" name="job_id" value="<?php echo (int) $r['id']; ?>">
                            <button data-prx-arm="Отменить?" type="submit" class="prx-btn-danger-soft prx-btn-sm"><span data-prx-arm-label>Отменить</span></button>
                        </form>
                        <?php elseif ($r['status'] === 'processing'): ?>
                        <form data-njmon-ajax method="post" action="/prx120/monitor<?php echo $p !== '' ? '?p='.$p : ''; ?>" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="kill"><input type="hidden" name="job_id" value="<?php echo (int) $r['id']; ?>">
                            <button data-prx-arm="Прервать?" type="submit" class="prx-btn-danger-soft prx-btn-sm"><span data-prx-arm-label>Прервать</span></button>
                        </form>
                        <?php else: ?>
                        <form data-njmon-ajax method="post" action="/prx120/monitor<?php echo $p !== '' ? '?p='.$p : ''; ?>" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="job_id" value="<?php echo (int) $r['id']; ?>">
                            <button data-prx-arm="Удалить?" type="submit" class="prx-btn-danger prx-btn-sm"><span data-prx-arm-label>Удалить</span></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr data-njmon-row="<?php echo (int) $r['id']; ?>" class="hidden border-t border-gray-50 bg-gray-50/60 dark:border-gray-700/50 dark:bg-gray-900/40">
                    <td colspan="7" class="px-3 py-2.5">
                        <div class="flex flex-wrap items-start gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="text-gray-400">Параметры задачи (payload)</p>
                                <pre class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-white p-2 font-mono text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300"><?php echo HTML::chars(json_encode($r['payload_arr'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                            </div>
                            <div class="w-72 shrink-0 space-y-1 text-gray-500 dark:text-gray-400">
                                <p><b class="text-gray-700 dark:text-gray-200">Ошибки:</b> <?php echo ! empty($r['error']) ? HTML::chars($r['error']) : '—'; ?></p>
                                <p><b class="text-gray-700 dark:text-gray-200">Старт:</b> <?php echo $dt($r['started_at']); ?> · <b class="text-gray-700 dark:text-gray-200">Финиш:</b> <?php echo $dt($r['finished_at']); ?></p>
                                <p><b class="text-gray-700 dark:text-gray-200">visitor:</b> <?php echo HTML::chars($r['visitor_id'] ?: '—'); ?></p>
                                <?php if ($r['url_tpl'] !== '' AND isset($r['payload_arr']['code'])): ?>
                                <a href="<?php echo HTML::chars(str_replace('<code>', urlencode((string) $r['payload_arr']['code']), $r['url_tpl'])); ?>" class="prx-btn-secondary prx-btn-sm">К результату <i class="fas fa-arrow-right ml-1.5"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ( ! $jobs): ?><tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Задач пока нет.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
/* prx120/monitor: AJAX-обновление фрагментов (без location.reload — оно «моргает») */
(function () {
    'use strict';


    /* раскрытие «Параметры» */
    function bindDetails(root) {
        root.querySelectorAll('[data-njmon-details]').forEach(function (btn) {
            if (btn.dataset.bound) return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function () {
                var row = document.querySelector('[data-njmon-row="' + btn.getAttribute('data-njmon-details') + '"]');
                if (row) row.classList.toggle('hidden');
            });
        });
    }

    function openRows() {
        var out = [];
        document.querySelectorAll('[data-njmon-row]:not(.hidden)').forEach(function (r) {
            out.push(r.getAttribute('data-njmon-row'));
        });
        return out;
    }

    function reopenRows(ids) {
        ids.forEach(function (id) {
            var row = document.querySelector('[data-njmon-row="' + id + '"]');
            if (row) row.classList.remove('hidden');
        });
    }

    bindDetails(document);

    /* AJAX-действия (отменить/прервать/удалить): fetch вместо submit →
       страница не перезагружается, фрагменты обновляются на месте */
    function toast(text, ok) {
        // Общий тост app.js; результат действия держим дольше обычного «ok».
        PRX.toast(text, ok ? 3500 : 0, ok ? 'ok' : 'err');
    }

    function bindAjax(root) {
        root.querySelectorAll('form[data-njmon-ajax]').forEach(function (form) {
            if (form.dataset.ajaxBound) return;
            form.dataset.ajaxBound = '1';
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(form);
                fd.set('ajax', '1');
                fetch(form.getAttribute('action') || location.pathname, {
                    method: 'POST', body: fd, cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    toast(res.flash || (res.ok ? 'Готово' : 'Не получилось'), !!res.ok);
                    if (res.ok) refresh(true);
                })
                .catch(function () { toast('Сеть не ответила — попробуйте ещё раз', false); });
            });
        });
    }
    bindAjax(document);

    /* прямой переход на задачу (?job=N): раскрыть, подкрасить, проскроллить */
    var FOCUS = <?php echo (int) $job_id; ?>;
    if (FOCUS > 0) {
        var row = document.querySelector('[data-njmon-row="' + FOCUS + '"]');
        if (row) {
            row.classList.remove('hidden');
            row.classList.add('bg-blue-50/70', 'dark:bg-blue-950/30');
            row.scrollIntoView({ block: 'center' });
        }
    }

    /* автообновление: подмена трёх фрагментов; пока раскрыта строка или взведена
       кнопка (data-prx-arm, класс is-armed) — не обновляем, чтобы не сбить взвод */
    var box = document.getElementById('njmon-auto');
    if (!box) return;
    if (localStorage.getItem('njmon_auto') === '0') box.checked = false;
    var busy = false;

    box.addEventListener('change', function () {
        localStorage.setItem('njmon_auto', box.checked ? '1' : '0');
    });

    function userBusy() {
        return document.querySelector('[data-njmon-row]:not(.hidden)') !== null
            || !!document.querySelector('[data-prx-arm].is-armed');
    }

    function refresh(force) {
        if (busy || document.hidden) return;
        if (!force && (!box.checked || userBusy())) return;
        busy = true;
        var opened = openRows();
        fetch(location.pathname + location.search, { headers: { 'X-Monitor': '1' }, cache: 'no-store' })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (html) {
                if (!html) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['njmon-health', 'njmon-now', 'njmon-filters', 'njmon-table'].forEach(function (id) {
                    var fresh = doc.getElementById(id), live = document.getElementById(id);
                    if (fresh && live) {
                        live.replaceChildren.apply(live, Array.prototype.slice.call(fresh.childNodes));
                        live.className = fresh.className; // «hidden»/показан блока «сейчас делает»
                    }
                });
                reopenRows(opened);
                bindDetails(document.getElementById('njmon-table'));
                bindAjax(document.getElementById('njmon-table'));
            })
            .catch(function () { /* сеть моргнула — попробуем в следующий тик */ })
            .finally(function () { busy = false; });
    }

    setInterval(refresh, 15000);
})();
</script>
