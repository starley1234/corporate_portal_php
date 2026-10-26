<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Вкладка «Прогоны»: что сделала сборка и почему.
 *
 * Счётчики без объяснений — половина ответа. Рядом с итогом лежит отчёт по
 * строкам (`nj_676_run_items`): добавлено / обновлено / пропущено и причина по
 * каждой карточке, чтобы не искать её в логе воркера.
 */
$run = $run ?? NULL;
$runs = (array) ($runs ?? []);
$run_requested = (int) ($run_requested ?? 0);
$run_items = (array) ($run_items ?? ['items' => [], 'reconstructed' => FALSE]);
$kind_labels = [
    'discover' => 'автосборка',
    'registry' => 'реестр MCP',
    'probe' => 'опрос эндпоинтов',
    'audit' => 'исследование (алмазы)',
];
?>
<div class="space-y-5">
    <?php if ( ! empty($run_missing)): ?>
        <div class="n2j-flash n2j-flash-warn" role="status">
            <i class="fas fa-circle-info shrink-0 text-lg" aria-hidden="true"></i>
            <span>Прогона #<?php echo (int) $run_requested; ?> уже нет — ниже показана последняя сборка<?php echo $run ? ' (№'.(int) $run['id'].')' : ''; ?>.</span>
        </div>
    <?php endif; ?>

    <div class="gap-5 space-y-5 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <div class="min-w-0 space-y-5 md:col-span-2">
            <?php if ($run_running): ?>
                <?php echo View::factory('partials/live_status', [
                    'url' => '/mcphub/api/status/'.(int) $run['id'],
                    'title' => 'Собираем каталог',
                    'status' => 'running',
                    'stage' => (string) $run['progress'],
                    'elapsed' => max(0, time() - (int) strtotime((string) $run['started_at'])),
                    'note' => 'Поиск идёт в фоне. Найденное появляется в каталоге сразу — страница обновится сама.',
                    'stop' => '<form method="post" action="/mcphub/admin/cancel" class="inline">'
                        .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                        .'<input type="hidden" name="run_id" value="'.(int) $run['id'].'">'
                        .'<button type="submit" class="n2j-btn-danger-soft n2j-btn-sm">Остановить</button></form>',
                ]); ?>
                <?php echo View::factory('partials/live_status_js'); ?>
            <?php elseif ($run): ?>
    <!-- ============ 1. Итог прогона: сначала результат, потом форма ============ -->
    <section class="n2j-card" id="run" aria-labelledby="run-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="n2j-h3 flex flex-wrap items-center gap-2" id="run-title">
                    Прогон #<?php echo (int) $run['id']; ?>
                    <span class="n2j-badge n2j-badge-<?php echo HTML::chars($run_state[0]); ?>">
                        <span class="n2j-dot"></span><?php echo HTML::chars($run_state[1]); ?>
                    </span>
                    <?php $run_kind = (string) ($run['kind'] ?? 'discover'); ?>
                    <?php if (isset($kind_labels[$run_kind])): ?>
                        <span class="n2j-chip !cursor-default"><?php echo HTML::chars($kind_labels[$run_kind]); ?></span>
                    <?php endif; ?>
                </h2>
                <p class="n2j-hint mt-1">
                    <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($run_titles[(string) $run['status']] ?? 'Сборка завершена'); ?></span>
                    · начат <?php echo HTML::chars($fmt_dt($run['started_at'] ?? '')); ?>
                    <?php if (trim((string) ($run['finished_at'] ?? '')) !== ''): ?>
                        · закончен <?php echo HTML::chars($fmt_dt($run['finished_at'])); ?>
                    <?php endif; ?>
                    <?php if ($run_duration !== ''): ?>
                        · заняло <?php echo HTML::chars($run_duration); ?>
                    <?php endif; ?>
                </p>
                <p class="n2j-hint mt-0.5">
                    Заказано карточек: <?php echo (int) ($run['requested'] ?? 0); ?>
                    <?php if (trim((string) $run['theme']) !== ''): ?>
                        · тематика: <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $run['theme']); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <a class="n2j-btn-secondary n2j-btn-sm" href="/mcphub">
                <i class="fas fa-layer-group" aria-hidden="true"></i> Смотреть каталог
            </a>
        </div>

        <?php // Пункт адреса #runs-log ведёт к отчёту прогона, а не в старый лог. ?>
        <span id="runs-log" class="sr-only"></span>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Найдено</p>
                <p class="mt-0.5 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $run['found']; ?></p>
            </div>
            <div class="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-950/30">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700/70 dark:text-emerald-300/70">Добавлено</p>
                <p class="mt-0.5 text-2xl font-bold tabular-nums text-emerald-800 dark:text-emerald-300"><?php echo (int) $run['added']; ?></p>
            </div>
            <div class="rounded-lg bg-blue-50 p-3 dark:bg-blue-950/30">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Обновлено</p>
                <p class="mt-0.5 text-2xl font-bold tabular-nums text-blue-800 dark:text-blue-300"><?php echo (int) $run['updated']; ?></p>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Пропущено</p>
                <p class="mt-0.5 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $run['skipped']; ?></p>
            </div>
        </div>

        <?php
        // Отчёт по строкам прогона: что добавлено, что обновлено,
        // что пропущено и почему. Раньше ответ приходилось искать
        // в логе воркера — теперь он здесь, рядом со счётчиками.
        $ri_items = (array) ($run_items['items'] ?? []);
        $ri_groups = ['added' => [], 'updated' => [], 'skipped' => []];
        $ri_reasons = [];

        foreach ($ri_items as $ri_item)
        {
            $ri_item = (array) $ri_item;
            $ri_status = (string) ($ri_item['status'] ?? 'skipped');

            if ( ! isset($ri_groups[$ri_status]))
            {
                $ri_status = 'skipped';
            }

            $ri_groups[$ri_status][] = $ri_item;

            if ($ri_status === 'skipped')
            {
                $ri_reason = trim((string) ($ri_item['reason'] ?? ''));

                if ($ri_reason === '')
                {
                    $ri_reason = 'без указания причины';
                }

                $ri_reasons[$ri_reason] = (int) ($ri_reasons[$ri_reason] ?? 0) + 1;
            }
        }

        arsort($ri_reasons);
        $ri_tone = ['added' => 'ok', 'updated' => 'info', 'skipped' => 'wait'];
        $ri_title = ['added' => 'Добавлено', 'updated' => 'Обновлено', 'skipped' => 'Пропущено'];
        ?>

        <?php if ($ri_items !== []): ?>
            <div class="mt-4 scroll-mt-24 border-t border-gray-100 pt-3 dark:border-gray-700/60" id="run-report">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">Что сделал прогон</h3>
                    <span class="n2j-hint">
                        карточек в отчёте: <?php echo count($ri_items); ?><?php echo ! empty($run_items['reconstructed']) ? ' · восстановлено по каталогу' : ''; ?>
                    </span>
                </div>

                <?php if ($ri_reasons !== []): ?>
                    <p class="mt-2 flex flex-wrap items-center gap-1.5">
                        <span class="n2j-hint">Почему пропущено:</span>
                        <?php foreach (array_slice($ri_reasons, 0, 6, TRUE) as $ri_reason => $ri_count): ?>
                            <span class="n2j-chip !cursor-default" title="<?php echo HTML::chars((string) $ri_reason); ?>">
                                <?php echo HTML::chars(mb_substr((string) $ri_reason, 0, 48, 'UTF-8')); ?><?php echo mb_strlen((string) $ri_reason, 'UTF-8') > 48 ? '…' : ''; ?>
                                <span class="font-semibold tabular-nums">×<?php echo (int) $ri_count; ?></span>
                            </span>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>

                <div class="mt-3 space-y-3">
                    <?php foreach (['added', 'updated', 'skipped'] as $ri_key): ?>
                        <?php if ($ri_groups[$ri_key] === []) continue; ?>
                        <?php $ri_head = array_slice($ri_groups[$ri_key], 0, 15); ?>
                        <?php $ri_tail = array_slice($ri_groups[$ri_key], 15, 185); ?>
                        <div>
                            <p class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <span class="n2j-badge n2j-badge-<?php echo $ri_tone[$ri_key]; ?>"><span class="n2j-dot"></span><?php echo $ri_title[$ri_key]; ?> · <?php echo count($ri_groups[$ri_key]); ?></span>
                            </p>
                            <ul class="mt-1.5 space-y-1">
                                <?php foreach ($ri_head as $ri_item): ?>
                                    <?php $ri_slug = (string) ($ri_item['slug'] ?? ''); ?>
                                    <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 rounded-lg px-2 py-1 text-sm hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                        <?php if ($ri_slug !== ''): ?>
                                            <a class="font-semibold text-gray-900 hover:text-blue-700 dark:text-white dark:hover:text-blue-300"
                                               href="/mcphub/<?php echo HTML::chars(rawurlencode($ri_slug)); ?>"><?php echo HTML::chars((string) ($ri_item['name'] ?? $ri_slug)); ?></a>
                                        <?php else: ?>
                                            <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) ($ri_item['name'] ?? '—')); ?></span>
                                        <?php endif; ?>
                                        <?php if ($ri_slug !== ''): ?>
                                            <span class="font-mono text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($ri_slug); ?></span>
                                        <?php endif; ?>
                                        <?php if ((string) ($ri_item['reason'] ?? '') !== ''): ?>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">— <?php echo HTML::chars((string) $ri_item['reason']); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($ri_tail !== []): ?>
                                <details class="mt-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 dark:border-gray-700">
                                    <summary class="cursor-pointer select-none text-xs font-semibold text-gray-600 dark:text-gray-300">
                                        Показать ещё <?php echo count($ri_tail); ?> строк
                                    </summary>
                                    <ul class="mt-1.5 space-y-1">
                                        <?php foreach ($ri_tail as $ri_item): ?>
                                            <?php $ri_slug = (string) ($ri_item['slug'] ?? ''); ?>
                                            <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 rounded-lg px-2 py-1 text-sm hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                                <?php if ($ri_slug !== ''): ?>
                                                    <a class="font-semibold text-gray-900 hover:text-blue-700 dark:text-white dark:hover:text-blue-300"
                                                       href="/mcphub/<?php echo HTML::chars(rawurlencode($ri_slug)); ?>"><?php echo HTML::chars((string) ($ri_item['name'] ?? $ri_slug)); ?></a>
                                                <?php else: ?>
                                                    <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) ($ri_item['name'] ?? '—')); ?></span>
                                                <?php endif; ?>
                                                <?php if ($ri_slug !== ''): ?>
                                                    <span class="font-mono text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($ri_slug); ?></span>
                                                <?php endif; ?>
                                                <?php if ((string) ($ri_item['reason'] ?? '') !== ''): ?>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">— <?php echo HTML::chars((string) $ri_item['reason']); ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                            <?php if (count($ri_groups[$ri_key]) > 200): ?>
                                <p class="n2j-hint mt-1">…и ещё <?php echo count($ri_groups[$ri_key]) - 200; ?> строк — остальные в журнале.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif ( ! empty($run_items['reconstructed'])): ?>
            <p class="n2j-hint mt-3 border-t border-gray-100 pt-3 dark:border-gray-700/60">
                Строки этого прогона не сохранились: журнал появился позже. Счётчики выше — из самого прогона.
            </p>
        <?php endif; ?>

        <?php if ((int) $run['probed'] > 0 OR $run['error'] !== ''): ?>
            <div class="mt-3 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-700/60">
                <?php if ((int) $run['probed'] > 0): ?>
                    <p class="n2j-hint">
                        <i class="fas fa-satellite-dish mr-1" aria-hidden="true"></i>
                        Проверено вживую вызовом <span class="font-mono">tools/list</span>: <strong class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $run['probed']; ?></strong>
                    </p>
                <?php endif; ?>
                <?php if ($run['error'] !== ''): ?>
                    <p class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
                        <i class="fas fa-circle-exclamation mr-1" aria-hidden="true"></i><?php echo HTML::chars((string) $run['error']); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
            <?php else: ?>
                <section class="n2j-card">
                    <div class="n2j-empty">
                        <span class="n2j-empty-icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></span>
                        <p class="n2j-empty-text mt-3 font-semibold text-gray-900 dark:text-white">Прогонов ещё не было</p>
                        <p class="n2j-hint mt-1">Запустите сборку на вкладке <a class="n2j-link" href="/mcphub/admin?tab=build">Сборка</a>.</p>
                    </div>
                </section>
            <?php endif; ?>
        </div>

    <aside class="w-full space-y-4">
        <!-- ============ Журнал прогонов ============ -->
        <section class="n2j-card" id="runs-list">
            <div class="flex items-center justify-between gap-2">
                <h2 class="n2j-h3">Журнал прогонов</h2>
                <?php if ($runs !== []): ?><span class="n2j-count"><?php echo count($runs); ?></span><?php endif; ?>
            </div>
            <?php if ($runs === []): ?>
                <p class="n2j-hint mt-2">Пока ни одной сборки.</p>
            <?php else: ?>
                <ul class="mt-3 space-y-1">
                    <?php foreach ($runs as $item): ?>
                        <?php
                        $item_state = $run_tones[(string) $item['status']] ?? $run_tones['cancelled'];
                        $is_current = ($run AND (int) $item['id'] === (int) $run['id']);
                        $item_duration = $fmt_dur($item['started_at'] ?? '', $item['finished_at'] ?? '');
                        ?>
                        <li>
                            <a href="/mcphub/admin?tab=runs&run=<?php echo (int) $item['id']; ?>#runs-log"
                               class="block rounded-lg border border-transparent px-2.5 py-2 transition hover:border-gray-200 hover:bg-gray-50 dark:hover:border-gray-700 dark:hover:bg-gray-900<?php echo $is_current ? ' border-blue-200 bg-blue-50/60 dark:border-blue-900/60 dark:bg-blue-950/30' : ''; ?>"
                               <?php echo $is_current ? 'aria-current="true"' : ''; ?>>
                                <span class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                                    Прогон #<?php echo (int) $item['id']; ?>
                                    <span class="n2j-badge n2j-badge-<?php echo HTML::chars($item_state[0]); ?>"><span class="n2j-dot"></span><?php echo HTML::chars($item_state[1]); ?></span>
                                </span>
                                <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-calendar-day" aria-hidden="true"></i><?php echo HTML::chars($fmt_dt($item['started_at'] ?? '')); ?>
                                    <?php if ($item_duration !== ''): ?><span aria-hidden="true">·</span><?php echo HTML::chars($item_duration); ?><?php endif; ?>
                                </span>
                                <span class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">
                                    <?php if (($item['kind'] ?? '') === 'probe' AND (int) ($item['probed'] ?? 0) > 0): ?>
                                        <span class="text-blue-700 dark:text-blue-400"><i class="fas fa-satellite-dish" aria-hidden="true"></i> опрошено <?php echo (int) $item['probed']; ?></span>
                                    <?php else: ?>
                                        <span class="text-emerald-700 dark:text-emerald-400"><i class="fas fa-plus" aria-hidden="true"></i> <?php echo (int) $item['added']; ?> новых</span>
                                        <span class="text-blue-700 dark:text-blue-400"><i class="fas fa-rotate" aria-hidden="true"></i> <?php echo (int) $item['updated']; ?> обновлено</span>
                                    <?php endif; ?>
                                    <?php if ((int) $item['found'] > 0): ?>
                                        <span class="text-gray-500 dark:text-gray-400">найдено <?php echo (int) $item['found']; ?></span>
                                    <?php endif; ?>
                                </span>
                                <?php if (trim((string) $item['theme']) !== ''): ?>
                                    <span class="mt-1 inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                        <i class="fas fa-tag" aria-hidden="true"></i><?php echo HTML::chars((string) $item['theme']); ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </aside>
    </div>
</div>
