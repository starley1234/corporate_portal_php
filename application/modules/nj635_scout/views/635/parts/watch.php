<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Блок «Наблюдение за рисками» единого отчёта (/check): найденные сигналы
 * (суды, банкротство, изменения реестра) и ход текущей проверки (nj609).
 */

$st      = (string) ($step['status'] ?? 'missing');
$events  = (array) ($step['events'] ?? []);
$badges  = [
    'queued'   => ['В очереди', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
    'running'  => ['Проверяем', 'bg-blue-600 text-white'],
    'watching' => ['Наблюдаем', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'],
    'failed'   => ['Ошибка', 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'],
    'cancelled'=> ['Остановлено', 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'],
    'locked'   => ['Закрыто', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'],
];
$badge = $badges[$st] ?? ['Не включено', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'];

$sev = [
    'risk' => ['Риск', 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300', 'fas fa-triangle-exclamation text-rose-500'],
    'warn' => ['Внимание', 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300', 'fas fa-circle-exclamation text-amber-500'],
    'info' => ['К сведению', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', 'fas fa-circle-info text-gray-400'],
];

$login_url = '/auth/login?return_url='.rawurlencode('check?run=1'.((string) ($ref['query'] ?? '') !== '' ? '&q='.rawurlencode((string) $ref['query']) : ''));
?>

<div class="n2j-card p-0" data-nj635-step="watch" data-rev="<?php echo HTML::chars((string) ($step['rev'] ?? '')); ?>">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-700/60">
        <p class="text-sm font-bold text-gray-900 dark:text-white">
            <i class="<?php echo HTML::chars((string) $step['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars((string) $step['label']); ?>
        </p>
        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold <?php echo $badge[1]; ?>"><?php echo HTML::chars($badge[0]); ?></span>
    </div>

    <?php if ($st === 'locked' OR $st === 'missing'): ?>
    <div class="px-4 py-3.5 sm:px-5">
        <p class="n2j-hint">
            <?php if ((string) $step['code'] !== '' AND $st === 'locked'): ?>
            Это наблюдение заведено под другой учётной записью — показать не можем.
            <?php elseif ( ! empty($step['needs_login'])): ?>
            Наблюдение работает от вашего аккаунта: раз в неделю проверяем суды, банкротство и изменения реестра и присылаем только реальные сигналы.
            <?php else: ?>
            <?php echo HTML::chars((string) $step['hint']); ?>.
            <?php endif; ?>
        </p>
        <?php if ( ! empty($step['needs_login'])): ?>
        <a href="<?php echo HTML::chars($login_url); ?>" class="n2j-btn-secondary n2j-btn-sm mt-2.5">
            <i class="fas fa-right-as-bracket mr-1.5"></i>Войти и включить наблюдение
        </a>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="px-4 py-3.5 sm:px-5">
        <?php if ($st === 'queued' OR $st === 'running'): ?>
        <div class="flex items-center gap-2.5">
            <i class="fas fa-circle-notch fa-spin text-blue-500"></i>
            <span class="text-xs font-semibold text-gray-800 sm:text-sm dark:text-gray-200" data-nj635-stage><?php echo HTML::chars((string) ($step['stage'] !== '' ? $step['stage'] : 'проверяем суды и реестр…')); ?></span>
            <span class="ml-auto font-mono text-xs text-gray-400" data-nj635-timer data-elapsed="<?php echo (int) $step['elapsed_sec']; ?>"><?php echo (int) $step['elapsed_sec']; ?> с</span>
        </div>
        <?php elseif ($st === 'failed' OR $st === 'cancelled'): ?>
        <p class="text-xs text-rose-600 sm:text-sm dark:text-rose-400"><i class="fas fa-circle-exclamation mr-1.5"></i><?php echo HTML::chars((string) ($step['error'] !== '' ? $step['error'] : 'Проверка не удалась')); ?></p>
        <?php else: ?>
        <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300"><i class="fas fa-shield-halved mr-1.5 text-emerald-500"></i>Наблюдение включено: проверяем сразу и дальше раз в неделю<?php if (trim((string) $step['stage']) !== ''): ?> · <span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $step['stage']); ?></span><?php endif; ?>.</p>
        <?php endif; ?>

        <?php if ( ! empty($events)): ?>
        <ul class="mt-2.5 divide-y divide-gray-100 dark:divide-gray-700/60">
            <?php foreach ($events as $e): ?>
            <?php $s = (string) ($e['severity'] ?? 'info'); $se = $sev[$s] ?? $sev['info']; ?>
            <li class="flex items-start gap-2.5 py-2 first:pt-0">
                <i class="<?php echo $se[2]; ?> mt-0.5 shrink-0"></i>
                <div class="min-w-0 flex-1">
                    <p class="break-words text-xs font-semibold text-gray-900 sm:text-sm dark:text-white">
                        <?php if (trim((string) $e['url']) !== ''): ?>
                        <a href="<?php echo HTML::chars((string) $e['url']); ?>" target="_blank" rel="noopener noreferrer nofollow" class="hover:underline"><?php echo HTML::chars((string) $e['title']); ?></a>
                        <?php else: ?>
                        <?php echo HTML::chars((string) $e['title']); ?>
                        <?php endif; ?>
                    </p>
                    <?php if (trim((string) $e['summary']) !== ''): ?>
                    <p class="n2j-hint mt-0.5 break-words"><?php echo HTML::chars(mb_substr((string) $e['summary'], 0, 220)); ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1">
                    <span class="rounded-full px-2 py-0.5 text-xs font-bold <?php echo $se[1]; ?>"><?php echo HTML::chars($se[0]); ?></span>
                    <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $e['created_at'], 'd.m.y')); ?></span>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php elseif ($st === 'watching'): ?>
        <p class="n2j-hint mt-2">Сигналов пока нет — это хорошая новость: судов и банкротства в открытых источниках не видно.</p>
        <?php endif; ?>

        <?php if ((string) $step['page'] !== ''): ?>
        <div class="mt-2.5">
            <a href="<?php echo HTML::chars((string) $step['page']); ?>" class="n2j-btn-secondary n2j-btn-sm">
                <i class="fas fa-bell mr-1.5 text-gray-400"></i>Все сигналы и настройки наблюдения
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
