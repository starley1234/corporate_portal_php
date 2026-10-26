<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Компактная плашка итога последнего сбора (после того как живой ряд погас).
 *
 * Принцип: неактивный процесс занимает ОДНУ строку —
 * «Готово · 24 мин назад · компаний: 2 · за 2 мин», а подробные этапы воркера
 * спрятаны в гармошку «этапы: 7» (свёрнута по умолчанию). Так список радаров и
 * потоков остаётся минималистичным (владелец: «сделай компактнее и
 * минималистичнее, неактивные процессы сверни в гармошку»).
 *
 * Поля: status (done|failed|cancelled), finished_at, error, found (число или
 * NULL), steps (этапы воркера), elapsed (секунд длился прогон),
 * found_label («находок»), extra (HTML справа, необязательно).
 */
$st_status = (string) ($status ?? 'done');
$st_finished = (string) ($finished_at ?? '');
$st_error = trim((string) ($error ?? ''));
// Итоговая приписка модуля (из result.note задачи): «собрано по эвристике,
// модель не ответила» и т.п. — видно, откуда взялись данные.
$st_note  = trim((string) ($note ?? ''));
$st_found = (isset($found) AND $found !== NULL) ? (int) $found : NULL;
$st_found_label = (string) ($found_label ?? 'найдено');
$st_extra = (string) ($extra ?? '');
// История воркера: что именно делал последний сбор (этапы из журнала очереди).
$st_steps = array_values(array_filter((array) ($steps ?? []), function ($s) {
    $m = is_array($s) ? (string) ($s['message'] ?? '') : (string) $s;

    return trim($m) !== '';
}));
$st_elapsed = max(0, (int) ($elapsed ?? 0));

$st_ts = $st_finished !== '' ? strtotime($st_finished) : 0;

if ($st_ts)
{
    $mins = (int) floor(max(0, time() - $st_ts) / 60);

    if ($mins < 1)          $ago = 'только что';
    elseif ($mins < 60)     $ago = $mins.' мин назад';
    elseif ($mins < 1440)   $ago = ((int) floor($mins / 60)).' ч назад';
    else                    $ago = ((int) floor($mins / 1440)).' дн назад';
}
else
{
    $ago = '';
}

$st_done = ($st_status === 'done');
$st_stopped = ($st_status === 'cancelled');

// Длительность прогона — «за 2 мин» (для долгих сборов полезнее времени старта).
$dur = '';

if ($st_elapsed > 0)
{
    $mins = (int) floor($st_elapsed / 60);
    $dur = $mins > 0 ? ($mins.' мин') : ((int) $st_elapsed.' с');
}

// этап с кликабельными доменами («какие-то источники он же нашёл — результат есть»)
$st_esc = function ($text) {
    return class_exists('Service_Nj120_Viewer')
        ? Service_Nj120_Viewer::step_html($text)
        : HTML::chars((string) $text);
};

$st_word = $st_done ? 'Готово' : ($st_stopped ? 'Отменено' : 'Ошибка');
$st_tone = $st_done
    ? 'text-emerald-600 dark:text-emerald-400'
    : ($st_stopped ? 'text-gray-500 dark:text-gray-400' : 'text-rose-600 dark:text-rose-400');
$st_icon = $st_done ? 'fa-circle-check' : ($st_stopped ? 'fa-circle-stop' : 'fa-triangle-exclamation');
?>
<div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs" data-n2j-last="<?php echo HTML::chars($st_status); ?>">
    <span class="inline-flex items-center gap-1 font-semibold <?php echo $st_tone; ?>">
        <i class="fas <?php echo $st_icon; ?>"></i><?php echo $st_word; ?>
    </span>
    <?php if ($ago !== ''): ?><span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars($ago); ?></span><?php endif; ?>
    <?php if ($st_done AND $st_found !== NULL): ?>
    <span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($st_found_label); ?>: <b class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $st_found; ?></b></span>
    <?php endif; ?>
    <?php if ($dur !== ''): ?><span class="text-gray-400 dark:text-gray-500">за <?php echo HTML::chars($dur); ?></span><?php endif; ?>
    <?php if ( ! $st_done AND $st_error !== ''): ?>
    <span class="max-w-full truncate text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars($st_error); ?>">· <?php echo HTML::chars(Text::limit_chars($st_error, 90)); ?></span>
    <?php endif; ?>
    <?php if ($st_note !== ''): ?>
    <span class="max-w-full truncate text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars($st_note); ?>">· <?php echo HTML::chars($st_note); ?></span>
    <?php endif; ?>
    <?php echo $st_extra; ?>
    <?php if ( ! empty($st_steps)): ?>
    <details class="ml-auto">
        <summary class="cursor-pointer list-none text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" title="Что делал воркер">этапы: <?php echo count($st_steps); ?> <i class="fas fa-chevron-down text-[10px]"></i></summary>
        <ol class="mt-1 max-w-xl space-y-0.5 text-xs text-gray-400 dark:text-gray-500" data-n2j-last-steps>
            <?php foreach ($st_steps as $st_step): ?>
            <li>· <?php echo $st_esc(is_array($st_step) ? (string) ($st_step['message'] ?? '') : (string) $st_step); ?></li>
            <?php endforeach; ?>
        </ol>
    </details>
    <?php endif; ?>
</div>
