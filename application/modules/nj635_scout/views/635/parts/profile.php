<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Блок «Сайт компании» единого отчёта (/check): сфера, масштаб, контакты, ЛПР,
 * стек из открытых страниц компании (владелец данных — nj591).
 */

$d      = (array) ($step['data'] ?? []);
$st     = (string) ($step['status'] ?? 'missing');
$badges = [
    'queued'    => ['В очереди', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
    'running'   => ['Обходим сайт', 'bg-blue-600 text-white'],
    'done'      => ['Готово', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'],
    'failed'    => ['Ошибка', 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'],
    'cancelled' => ['Остановлено', 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'],
    'locked'    => ['Закрыто', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'],
];
$badge = $badges[$st] ?? ['Не запускалось', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'];

// скобки обязательны: AND ниже `=` и `?:` — без них в переменную попадёт bool
$contacts = (isset($d['contacts']) AND is_array($d['contacts'])) ? $d['contacts'] : [];
$dms      = (isset($d['decision_makers']) AND is_array($d['decision_makers'])) ? $d['decision_makers'] : [];
$tech     = (isset($d['tech_stack']) AND is_array($d['tech_stack'])) ? $d['tech_stack'] : [];
$has_contacts = ( ! empty($contacts['emails']) OR ! empty($contacts['phones']) OR ! empty($contacts['address']));

$login_url = '/auth/login?return_url='.rawurlencode('check?run=1'.((string) ($ref['query'] ?? '') !== '' ? '&q='.rawurlencode((string) $ref['query']) : ''));
?>

<div class="n2j-card p-0" data-nj635-step="profile" data-rev="<?php echo HTML::chars((string) ($step['rev'] ?? '')); ?>">
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
            Этот разбор сделан под другой учётной записью — показать не можем.
            <?php elseif ( ! empty($step['needs_login'])): ?>
            Обход сайта компании идёт от вашего аккаунта (контакты и ЛПР — персональные данные). Войдите — и отчёт соберётся целиком.
            <?php else: ?>
            <?php echo HTML::chars((string) $step['hint']); ?>.
            <?php endif; ?>
        </p>
        <?php if ( ! empty($step['needs_login'])): ?>
        <a href="<?php echo HTML::chars($login_url); ?>" class="n2j-btn-secondary n2j-btn-sm mt-2.5">
            <i class="fas fa-right-to-bracket mr-1.5"></i>Войти и собрать всё целиком
        </a>
        <?php endif; ?>
    </div>

    <?php elseif ($st === 'queued' OR $st === 'running'): ?>
    <div class="px-4 py-3.5 sm:px-5">
        <div class="flex items-center gap-2.5">
            <i class="fas fa-circle-notch fa-spin text-blue-500"></i>
            <span class="text-xs font-semibold text-gray-800 sm:text-sm dark:text-gray-200" data-nj635-stage><?php echo HTML::chars((string) ($step['stage'] !== '' ? $step['stage'] : 'Ждём воркер…')); ?></span>
            <span class="ml-auto font-mono text-xs text-gray-400" data-nj635-timer data-elapsed="<?php echo (int) $step['elapsed_sec']; ?>"><?php echo (int) $step['elapsed_sec']; ?> с</span>
        </div>
        <p class="n2j-hint mt-1.5">Обычно 30–90 секунд.</p>
    </div>

    <?php elseif ($st === 'failed' OR $st === 'cancelled'): ?>
    <div class="px-4 py-3.5 sm:px-5">
        <p class="text-xs text-rose-600 sm:text-sm dark:text-rose-400"><i class="fas fa-circle-exclamation mr-1.5"></i><?php echo HTML::chars((string) ($step['error'] !== '' ? $step['error'] : 'Обойти сайт не удалось')); ?></p>
    </div>

    <?php elseif ($st === 'done'): ?>
    <div class="px-4 py-3.5 sm:px-5">
        <?php if (trim((string) ($d['sphere'] ?? '')) !== ''): ?>
        <p class="text-xs font-semibold text-blue-700 sm:text-sm dark:text-blue-300"><i class="fas fa-industry mr-1.5"></i><?php echo HTML::chars((string) $d['sphere']); ?></p>
        <?php endif; ?>

        <?php if (trim((string) ($d['summary'] ?? '')) !== ''): ?>
        <p class="mt-1.5 break-words text-xs leading-relaxed text-gray-600 sm:text-sm dark:text-gray-300"><?php echo HTML::chars((string) $d['summary']); ?></p>
        <?php endif; ?>

        <div class="mt-2.5 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
            <?php foreach ([
                ['Масштаб', (string) ($d['size_estimate'] ?? '')],
                ['Точность', (string) ($d['confidence'] ?? '')],
            ] as $m): ?>
            <div class="rounded-lg border border-gray-100 px-2.5 py-2 dark:border-gray-700">
                <p class="n2j-hint"><?php echo HTML::chars($m[0]); ?></p>
                <p class="mt-0.5 break-words font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($m[1] !== '' ? $m[1] : '—'); ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($has_contacts): ?>
        <div class="mt-2.5 rounded-lg border border-gray-100 p-3 dark:border-gray-700">
            <p class="text-xs font-bold text-gray-900 dark:text-white"><i class="fas fa-address-card mr-1.5 text-gray-400"></i>Контакты</p>
            <ul class="mt-1.5 space-y-1 text-xs text-gray-700 dark:text-gray-200">
                <?php foreach ((array) ($contacts['emails'] ?? []) as $e): ?>
                <li class="break-words"><i class="fas fa-envelope mr-1.5 text-gray-400"></i><?php echo HTML::chars((string) $e); ?></li>
                <?php endforeach; ?>
                <?php foreach ((array) ($contacts['phones'] ?? []) as $ph): ?>
                <li class="break-words"><i class="fas fa-phone mr-1.5 text-gray-400"></i><?php echo HTML::chars((string) $ph); ?></li>
                <?php endforeach; ?>
                <?php if (trim((string) ($contacts['address'] ?? '')) !== ''): ?>
                <li class="break-words"><i class="fas fa-location-dot mr-1.5 text-gray-400"></i><?php echo HTML::chars((string) $contacts['address']); ?></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ( ! empty($dms)): ?>
        <div class="mt-2.5 rounded-lg border border-gray-100 p-3 dark:border-gray-700">
            <p class="text-xs font-bold text-gray-900 dark:text-white"><i class="fas fa-user-tie mr-1.5 text-gray-400"></i>Люди, с кем говорить (ЛПР)</p>
            <ul class="mt-1.5 space-y-1 text-xs text-gray-700 dark:text-gray-200">
                <?php foreach (array_slice($dms, 0, 3) as $dm): ?>
                <li class="break-words"><?php echo HTML::chars(trim((string) (($dm['name'] ?? '').' — '.($dm['role'] ?? '')), ' —')); ?><?php if (trim((string) ($dm['source'] ?? '')) !== ''): ?> <span class="text-gray-400"><?php echo HTML::chars((string) $dm['source']); ?></span><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ( ! empty($tech)): ?>
        <div class="mt-2.5">
            <p class="text-xs font-bold text-gray-900 dark:text-white"><i class="fas fa-layer-group mr-1.5 text-gray-400"></i>Технологии</p>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                <?php foreach (array_slice($tech, 0, 10) as $t): ?>
                <span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars((string) $t); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ((string) $step['page'] !== ''): ?>
        <div class="mt-3">
            <a href="<?php echo HTML::chars((string) $step['page']); ?>" class="n2j-btn-secondary n2j-btn-sm">
                <i class="fas fa-book-open mr-1.5 text-gray-400"></i>Профиль целиком с источниками
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
