<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx120/channels — каналы очереди: нагрузка, лимиты, повтор упавших.
 *
 * @var array  $channels   Service_Prx120_Channels::overview()
 * @var string $flash
 * @var string $csrf
 * @var int    $retry_days
 * @var int    $retry_cap
 */
$n   = function ($v) { return number_format((int) $v, 0, ',', ' '); };
$age = function ($sec) {
    if ($sec === NULL) return '—';
    $sec = (int) $sec;
    if ($sec < 120) return $sec.' с';
    if ($sec < 7200) return (int) round($sec / 60).' мин';
    if ($sec < 172800) return (int) round($sec / 3600).' ч';
    return (int) round($sec / 86400).' дн';
};
$limited = count(array_filter($channels, function ($c) { return $c['max_concurrent'] > 0 OR $c['min_interval_sec'] > 0; }));
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php
    echo View::factory('partials/page_header', [
        'crumbs'  => [['Монитор воркеров', '/prx120/monitor'], ['Каналы и лимиты']],
        'title'   => 'Каналы очереди',
        'pill'    => ['prx120 · каналы', 'fa-sliders'],
        'lead'    => 'Нагрузка по каналам и их лимиты. «Одновременно» — не больше N задач канала в работе, «Интервал» — не чаще одного захвата за N секунд: для каналов, которые упираются во внешний ресурс (LLM-провайдер, OCR, НСИ). 0 — без лимита. Лимит соблюдают и PHP-, и python-воркеры.',
    ]);
    ?>

    <?php if ($flash !== ''): ?>
    <div class="prx-flash prx-flash-info mt-4 block text-xs font-semibold"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <p class="prx-hint mt-4">
        Каналов: <?php echo count($channels); ?> · с лимитами: <?php echo $limited; ?> ·
        «Повторить упавшие» ставит заново задачи, упавшие за <?php echo (int) $retry_days; ?> дн. и ещё не повторённые,
        до <?php echo (int) $retry_cap; ?> за раз — каждую новой задачей с теми же параметрами.
    </p>

    <div class="prx-card mt-3 overflow-x-auto p-0">
        <table class="prx-table">
            <thead>
                <tr class="text-left text-gray-400">
                    <th class="px-3 py-2.5">Канал</th>
                    <th class="text-right">Ожидают</th>
                    <th class="text-right">В работе</th>
                    <th class="text-right">Упали за 24 ч</th>
                    <th class="text-right">Готово за 24 ч</th>
                    <th class="text-right" title="Сколько ждёт самая старая задача, которую уже можно брать">Ждёт дольше всех</th>
                    <th>Лимиты</th>
                    <th class="pr-3 text-right">Действия</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 dark:text-gray-300">
            <?php if ( ! $channels): ?>
                <tr><td colspan="8" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">Каналов нет: ни один модуль не объявил worker.channels, задач в очереди не было.</td></tr>
            <?php endif; ?>
            <?php foreach ($channels as $c): ?>
                <tr class="border-t border-gray-50 align-top dark:border-gray-700/50" data-channel="<?php echo HTML::chars($c['channel']); ?>">
                    <td class="px-3 py-2">
                        <a href="/prx120/monitor?ch=<?php echo urlencode($c['channel']); ?>" class="font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"><?php echo HTML::chars($c['label']); ?></a>
                        <div class="font-mono text-xs text-gray-400"><?php echo HTML::chars($c['channel']); ?><?php if ( ! $c['module_known']): ?> · <span class="text-amber-600 dark:text-amber-400" title="Канал не объявлен ни в одном манифесте модуля">без владельца</span><?php endif; ?></div>
                    </td>
                    <td class="text-right tabular-nums"><?php echo $n($c['pending']); ?></td>
                    <td class="text-right tabular-nums"><?php echo $n($c['processing']); ?></td>
                    <td class="text-right tabular-nums <?php echo $c['failed_24h'] > 0 ? 'font-bold text-rose-600 dark:text-rose-400' : ''; ?>"><?php echo $n($c['failed_24h']); ?></td>
                    <td class="text-right tabular-nums"><?php echo $n($c['done_24h']); ?></td>
                    <td class="text-right tabular-nums <?php echo ($c['oldest_pending_sec'] ?? 0) > 1800 ? 'font-bold text-amber-600 dark:text-amber-400' : ''; ?>"><?php echo $age($c['oldest_pending_sec']); ?></td>
                    <td class="py-2">
                        <form method="post" action="/prx120/channels" class="flex flex-wrap items-center gap-2">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="act" value="limits">
                            <input type="hidden" name="channel" value="<?php echo HTML::chars($c['channel']); ?>">
                            <label class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400" title="Не больше N задач канала одновременно в работе (0 — без лимита)">
                                одновременно
                                <input type="number" name="max_concurrent" min="0" max="<?php echo Service_Prx120_Channels::MAX_CONCURRENT; ?>" value="<?php echo (int) $c['max_concurrent']; ?>" class="prx-input w-20 tabular-nums">
                            </label>
                            <label class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400" title="Не чаще одного захвата канала за N секунд (0 — без лимита)">
                                интервал, с
                                <input type="number" name="min_interval_sec" min="0" max="<?php echo Service_Prx120_Channels::MAX_INTERVAL_SEC; ?>" value="<?php echo (int) $c['min_interval_sec']; ?>" class="prx-input w-24 tabular-nums">
                            </label>
                            <button type="submit" class="prx-btn-secondary prx-btn-sm">Сохранить</button>
                        </form>
                    </td>
                    <td class="whitespace-nowrap py-2 pr-3 text-right">
                        <?php if ($c['retryable'] > 0): ?>
                        <form method="post" action="/prx120/channels" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="act" value="retry_failed">
                            <input type="hidden" name="channel" value="<?php echo HTML::chars($c['channel']); ?>">
                            <button type="submit" data-prx-confirm="Упавшие задачи канала за <?php echo (int) $retry_days; ?> дн. будут поставлены заново (до <?php echo (int) $retry_cap; ?>)." data-prx-title="Повторить упавшие?" data-prx-ok="Повторить" class="prx-btn-secondary prx-btn-sm"><i class="fas fa-rotate-right text-xs"></i>Повторить упавшие (<?php echo $n(min($c['retryable'], $retry_cap)); ?>)</button>
                        </form>
                        <?php endif; ?>
                        <a href="/prx120/monitor?ch=<?php echo urlencode($c['channel']); ?>" class="prx-btn-secondary prx-btn-sm">Задачи</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
