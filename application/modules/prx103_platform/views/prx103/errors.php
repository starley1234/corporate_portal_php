<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * «Хвост ошибок» — единая лента неисправностей: БД-модули + системный лог.
 * Только чтение; лечится руками по ссылкам из модулей.
 */
$card  = 'prx-card';
$lbl   = 'text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500';

$tint = function (string $level): string {
    switch (strtoupper($level))
    {
        case 'ERROR': case 'FAILED': case 'CRITICAL': case 'ALERT': case 'EMERGENCY':
            return 'prx-badge-err';
        case 'WARNING':   return 'prx-badge-run';
        case 'STUCK':     return 'prx-badge-info';
        default:          return 'prx-badge-wait';
    }
};

$fmt = function (string $ts): string {
    if ($ts === '' OR $ts === NULL) return '—';
    $t = strtotime((string) $ts);

    return $t ? date('d.m H:i', $t) : HTML::chars((string) $ts);
};
?>

<div class="prx-ds w-full space-y-6 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="prx-h1">Хвост ошибок</h1>
            <p class="prx-hint">Единая лента неисправностей: модули в БД и системный лог (WARNING+). На момент: <?php echo $now; ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/prx100/errors" class="prx-btn-secondary prx-btn-sm"><i class="fas fa-rotate mr-1.5"></i>Обновить</a>
            <a href="/prx100/logs" class="prx-btn-secondary prx-btn-sm"><i class="fas fa-terminal mr-1.5"></i>Логи</a>
            <a href="/prx208_events" class="prx-btn-secondary prx-btn-sm"><i class="fas fa-bolt mr-1.5"></i>События</a>
            <a href="/dashboard" class="prx-btn-secondary prx-btn-sm"><i class="fas fa-gauge mr-1.5"></i>Дашборд</a>
        </div>
    </div>

    <!-- Сводка -->
    <div class="grid grid-cols-2 gap-4">
        <div class="<?php echo $card; ?>">
            <p class="<?php echo $lbl; ?>">Провалено задач</p>
            <p class="mt-1 text-2xl font-bold <?php echo $stats['jobs_failed'] > 0 ? 'text-rose-500' : 'text-gray-900 dark:text-white'; ?>"><?php echo (int) $stats['jobs_failed']; ?></p>
            <p class="prx-hint">prx_120_jobs · failed</p>
        </div>
        <div class="<?php echo $card; ?>">
            <p class="<?php echo $lbl; ?>">Залипших задач</p>
            <p class="mt-1 text-2xl font-bold <?php echo $stats['jobs_stuck'] > 0 ? 'text-blue-500' : 'text-gray-900 dark:text-white'; ?>"><?php echo (int) $stats['jobs_stuck']; ?></p>
            <p class="prx-hint">processing, heartbeat &gt; 15 мин</p>
        </div>
    </div>

    <!-- Ошибки модулей (БД) -->
    <div class="<?php echo $card; ?>">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3 dark:border-gray-700/60">
            <h2 class="prx-h3"><i class="fas fa-database mr-1.5 text-gray-400"></i>Модули: свежие ошибочные записи</h2>
            <span class="prx-count"><?php echo count($db); ?></span>
        </div>
        <?php if (empty($db)): ?>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400"><i class="fas fa-circle-check mr-1 text-emerald-500"></i>Ошибочных записей в модулях нет.</p>
        <?php else: ?>
            <div class="mt-1 overflow-x-auto">
                <table class="prx-table">
                    <thead class="text-gray-400">
                        <tr>
                            <th class="py-2 pr-3 font-semibold">Когда</th>
                            <th class="py-2 pr-3 font-semibold">Модуль</th>
                            <th class="py-2 pr-3 font-semibold">Объект</th>
                            <th class="py-2 pr-3 font-semibold">Статус</th>
                            <th class="py-2 font-semibold">Ошибка</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-700/40">
                        <?php foreach ($db as $e): ?>
                        <tr class="align-top transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="whitespace-nowrap py-2 pr-3 text-gray-500 dark:text-gray-400"><?php echo $fmt($e['ts']); ?></td>
                            <td class="py-2 pr-3 font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($e['src']); ?></td>
                            <td class="py-2 pr-3 font-mono text-gray-600 dark:text-gray-300">
                                <?php if ($e['href']): ?><a class="text-blue-600 hover:underline dark:text-blue-400" href="<?php echo HTML::chars($e['href']); ?>"><?php echo HTML::chars($e['code']); ?></a>
                                <?php else: ?><?php echo HTML::chars($e['code']); ?><?php endif; ?>
                            </td>
                            <td class="py-2 pr-3"><span class="prx-badge uppercase <?php echo $tint(in_array($e['status'], ['error', 'failed'], TRUE) ? $e['status'] : ''); ?>"><span class="prx-dot"></span><?php echo HTML::chars($e['status'] ?: '—'); ?></span></td>
                            <td class="max-w-md py-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars($e['error']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Системный лог -->
    <div class="<?php echo $card; ?>">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3 dark:border-gray-700/60">
            <h2 class="prx-h3"><i class="fas fa-terminal mr-1.5 text-gray-400"></i>Системный лог: WARNING и выше (сегодня и вчера)</h2>
            <span class="prx-count"><?php echo count($log); ?></span>
        </div>
        <?php if (empty($log)): ?>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400"><i class="fas fa-circle-check mr-1 text-emerald-500"></i>Предупреждений и ошибок в логе нет.</p>
        <?php else: ?>
            <ul class="mt-1 space-y-2.5">
                <?php foreach ($log as $e): ?>
                <li class="rounded-lg border border-gray-100 p-2.5 dark:border-gray-700/60">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="prx-badge uppercase <?php echo $tint($e['level']); ?>"><span class="prx-dot"></span><?php echo HTML::chars($e['level']); ?></span>
                        <span class="text-xs text-gray-400"><?php echo HTML::chars($e['time']); ?></span>
                    </div>
                    <p class="mt-1 break-words font-mono text-xs text-gray-700 dark:text-gray-200"><?php echo HTML::chars($e['msg']); ?></p>
                    <?php if ( ! empty($e['trace'])): ?>
                    <details class="mt-1">
                        <summary class="cursor-pointer text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">стек (<?php echo count($e['trace']); ?>)</summary>
                        <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-all text-xs leading-4 text-gray-500 dark:text-gray-400"><?php echo HTML::chars(implode("\n", $e['trace'])); ?></pre>
                    </details>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
