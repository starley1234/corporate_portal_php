<?php defined('SYSPATH') or die('No direct script access.');
/**
 * nj602/admin: живой журнал прогона статьи (фрагмент 602/trace).
 *
 * @var array $trace ['code' => string, 'items' => array[{t, phase, note}]]
 */
$phases = [
    'start'    => ['border-blue-300 bg-blue-50 text-blue-600 dark:border-blue-700 dark:bg-blue-950/40 dark:text-blue-300', 'far fa-circle-play'],
    'progress' => ['border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-900/60 dark:text-gray-300', 'fas fa-ellipsis'],
    'sources'  => ['border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300', 'fas fa-magnifying-glass'],
    'read'     => ['border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300', 'fas fa-book-open'],
    'llm'      => ['border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300', 'fas fa-brain'],
    'llm_ok'   => ['border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300', 'fas fa-brain'],
    'enrich'   => ['border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300', 'fas fa-diagram-project'],
    'rag'      => ['border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300', 'fas fa-database'],
    'posted'   => ['border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300', 'fas fa-paper-plane'],
    'done'     => ['border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300', 'fas fa-circle-check'],
    'error'    => ['border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300', 'fas fa-triangle-exclamation'],
];
?>
<div id="nj602-trace" data-code="<?php echo HTML::chars((string) $trace['code']); ?>">
    <?php if ((string) $trace['code'] === ''): ?>
    <p class="n2j-hint px-4 py-3">
        Выберите в таблице строку статьи со статусом «собирается» (или ошибкой) — здесь появится живой журнал прогона воркера: что он делает, где застрял и каков статус его задачи в очереди.
    </p>
    <?php else: ?>
    <p class="border-b border-gray-100 px-4 py-2 text-xs font-bold text-gray-700 dark:border-gray-700/60 dark:text-gray-300">
        Журнал <span class="font-mono text-blue-600 dark:text-blue-400"><?php echo HTML::chars($trace['code']); ?></span>
        <span class="ml-1 font-normal text-gray-400">— метки пишет сам воркер по ходу генерации</span>
    </p>
    <ol id="nj602-trace-list" class="max-h-64 space-y-2.5 overflow-y-auto px-4 py-3 n2j-scrollbar">
        <?php if (empty($trace['items'])): ?>
        <li class="flex items-start gap-2.5">
            <span class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full border border-gray-200 bg-gray-50 text-xs text-gray-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-500"><i class="fas fa-hourglass"></i></span>
            <div class="min-w-0">
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">Задача поставлена, воркер ещё не отметился</p>
                <p class="n2j-hint">Первая метка появится, когда воркер заберёт задачу из очереди и приступит к теме.</p>
            </div>
        </li>
        <?php else: foreach ($trace['items'] as $m): $p = (string) ($m['phase'] ?? 'progress'); $cl = $phases[$p] ?? $phases['progress']; $t = (int) ($m['t'] ?? 0); ?>
        <li class="flex items-start gap-2.5">
            <span class="mt-0.5 inline-flex h-5.5 w-5.5 shrink-0 items-center justify-center rounded-full border <?php echo $cl[0]; ?> text-xs"><i class="<?php echo $cl[1]; ?>"></i></span>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">
                    <?php echo ($t > 0 ? '<span class="font-mono text-xs font-normal text-gray-400 dark:text-gray-500">'.date('H:i:s', $t).'</span> ' : ''); ?>
                    <span class="n2j-badge n2j-badge-wait font-mono"><?php echo HTML::chars($p); ?></span>
                    <?php echo ($m['note'] ?? '') !== '' ? HTML::chars((string) $m['note']) : '<span class="text-gray-400">'.HTML::chars((string) $p).'</span>'; ?>
                </p>
            </div>
        </li>
        <?php endforeach; endif; ?>
    </ol>
    <?php endif; ?>
</div>
