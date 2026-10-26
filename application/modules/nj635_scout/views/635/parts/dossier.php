<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Блок «AI-досье» единого отчёта (/check). Показывает ход разведки и, когда
 * досье готово, — самое полезное: о компании, красные флаги, плюсы и вопросы
 * к собеседованию. Полное досье — по ссылке (там источники и всё остальное).
 */

$d      = (array) ($step['data'] ?? []);
$st     = (string) ($step['status'] ?? 'missing');
$badges = [
    'queued'    => ['В очереди', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
    'running'   => ['Собираем', 'bg-blue-600 text-white'],
    'done'      => ['Готово', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'],
    'failed'    => ['Ошибка', 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'],
    'cancelled' => ['Остановлено', 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'],
    'locked'    => ['Чужое', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'],
];
$badge = $badges[$st] ?? ['Не запускалось', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'];

$about = trim((string) ($d['summary'] ?? ''));

if ($about === '')
{
    $about = trim((string) ($d['cheatsheet']['about'] ?? ''));
}

/** Список пунктов досье: {text, source} или строка. */
$list = function ($items, $limit) {
    $out = '';

    foreach (array_slice((array) $items, 0, (int) $limit) as $it)
    {
        $t   = trim((string) (is_array($it) ? ($it['text'] ?? '') : $it));
        $src = is_array($it) ? trim((string) ($it['source'] ?? '')) : '';

        if ($t === '')
        {
            continue;
        }

        $out .= '<li class="mt-1.5 break-words text-xs leading-relaxed text-gray-700 sm:text-sm dark:text-gray-200">'.HTML::chars($t)
            .($src !== '' ? ' <span class="text-gray-400">— '.HTML::chars($src).'</span>' : '')
            .'</li>';
    }

    return $out;
};
?>

<div class="n2j-card p-0" data-nj635-step="dossier" data-rev="<?php echo HTML::chars((string) ($step['rev'] ?? '')); ?>">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-700/60">
        <p class="text-sm font-bold text-gray-900 dark:text-white">
            <i class="<?php echo HTML::chars((string) $step['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars((string) $step['label']); ?>
        </p>
        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold <?php echo $badge[1]; ?>"><?php echo HTML::chars($badge[0]); ?></span>
    </div>

    <?php if ($st === 'locked'): ?>
    <p class="n2j-hint px-4 py-3.5">Это досье собрано под другой учётной записью — показать не можем.</p>

    <?php elseif ($st === 'missing'): ?>
    <p class="n2j-hint px-4 py-3.5"><?php echo HTML::chars((string) $step['hint']); ?>.</p>

    <?php elseif ($st === 'queued' OR $st === 'running'): ?>
    <div class="px-4 py-3.5 sm:px-5">
        <div class="flex items-center gap-2.5">
            <i class="fas fa-circle-notch fa-spin text-blue-500"></i>
            <span class="text-xs font-semibold text-gray-800 sm:text-sm dark:text-gray-200" data-nj635-stage><?php echo HTML::chars((string) ($step['stage'] !== '' ? $step['stage'] : 'Ждём воркер…')); ?></span>
            <span class="ml-auto font-mono text-xs text-gray-400" data-nj635-timer data-elapsed="<?php echo (int) $step['elapsed_sec']; ?>"><?php echo (int) $step['elapsed_sec']; ?> с</span>
        </div>
        <?php if ($step['silent_sec'] !== NULL AND (int) $step['silent_sec'] > 60): ?>
        <p class="mt-2 text-xs text-amber-600 dark:text-amber-400"><i class="fas fa-triangle-exclamation mr-1"></i>Нет отклика <?php echo (int) $step['silent_sec']; ?> с — если так и останется, задача остановится сама.</p>
        <?php endif; ?>
        <div class="mt-2.5 flex flex-wrap items-center gap-2">
            <?php if ((string) $step['code'] !== ''): ?>
            <form method="post" action="/dossier/stop/<?php echo HTML::chars((string) $step['code']); ?>">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <button data-n2j-arm="Остановить?" type="submit" class="n2j-btn-danger n2j-btn-sm"><i class="fas fa-stop mr-1.5"></i>Остановить</button>
            </form>
            <?php endif; ?>
            <span class="text-xs text-gray-400">обычно 2–4 минуты; остальные блоки появятся сами</span>
        </div>
    </div>

    <?php elseif ($st === 'failed' OR $st === 'cancelled'): ?>
    <div class="px-4 py-3.5 sm:px-5">
        <p class="text-xs text-rose-600 sm:text-sm dark:text-rose-400"><i class="fas fa-circle-exclamation mr-1.5"></i><?php echo HTML::chars((string) ($step['error'] !== '' ? $step['error'] : 'Разведка не удалась')); ?></p>
    </div>

    <?php elseif ($st === 'done'): ?>
    <div class="px-4 py-3.5 sm:px-5">
        <?php if ($about !== ''): ?>
        <p class="break-words text-xs leading-relaxed text-gray-700 sm:text-sm dark:text-gray-200"><?php echo HTML::chars($about); ?></p>
        <?php endif; ?>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div class="rounded-lg border border-rose-100 bg-rose-50/40 p-3 dark:border-rose-900/60 dark:bg-rose-950/20">
                <p class="text-xs font-bold text-rose-700 dark:text-rose-300"><i class="fas fa-flag mr-1.5"></i>Красные флаги</p>
                <?php $rf = $list($d['red_flags'] ?? [], 4); ?>
                <?php echo $rf !== '' ? '<ul>'.$rf.'</ul>' : '<p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Явных красных флагов в открытых источниках не найдено.</p>'; ?>
            </div>
            <div class="rounded-lg border border-emerald-100 bg-emerald-50/40 p-3 dark:border-emerald-900/60 dark:bg-emerald-950/20">
                <p class="text-xs font-bold text-emerald-700 dark:text-emerald-300"><i class="fas fa-circle-check mr-1.5"></i>Сильные стороны</p>
                <?php $pr = $list($d['pros'] ?? [], 4); ?>
                <?php echo $pr !== '' ? '<ul>'.$pr.'</ul>' : '<p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Плюсы в открытых источниках не найдены — данных мало.</p>'; ?>
            </div>
        </div>

        <?php $q = $list($d['cheatsheet']['questions_to_ask'] ?? [], 3); ?>
        <?php if ($q !== ''): ?>
        <div class="mt-3 rounded-lg border border-blue-100 bg-blue-50/40 p-3 dark:border-blue-900/60 dark:bg-blue-950/20">
            <p class="text-xs font-bold text-blue-700 dark:text-blue-300"><i class="fas fa-circle-question mr-1.5"></i>О чём спросить на собеседовании</p>
            <ul><?php echo $q; ?></ul>
        </div>
        <?php endif; ?>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <?php if ((string) $step['page'] !== ''): ?>
            <a href="<?php echo HTML::chars((string) $step['page']); ?>" class="n2j-btn-secondary n2j-btn-sm">
                <i class="fas fa-book-open mr-1.5 text-gray-400"></i>Полное досье с источниками
            </a>
            <?php endif; ?>
            <span class="text-xs text-gray-400"><?php echo count((array) ($d['sources'] ?? [])); ?> источников прочитано</span>
        </div>
    </div>
    <?php endif; ?>
</div>
