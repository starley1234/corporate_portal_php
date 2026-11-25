<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120/task — одна задача очереди: статус, параметры, итог и журнал
 * событий-прогресса (prx_120_job_events). Легковес: открывается из монитора
 * кликом по номеру задачи.
 */

$badge = [
    'pending'    => 'prx-badge-wait',
    'processing' => 'prx-badge-run',
    'done'       => 'prx-badge-ok',
    'failed'     => 'prx-badge-err',
    'cancelled'  => 'prx-badge-wait',
];
// Значения по умолчанию: вьюха обязана открываться даже с частичными данными.
// Живой случай 27.09.2026 — /tasks/<code> рендерил этот шаблон без $job_id и
// падал фаталом на строке 41 (Kohana превращает Warning в исключение).
$diagnosis = isset($diagnosis) ? (array) $diagnosis : [];
$job    = isset($job) ? (array) $job : [];
$job_id = isset($job_id) ? (int) $job_id : (int) ($job['id'] ?? 0);
$events = isset($events) ? (array) $events : [];
$label  = isset($label) ? (string) $label : (string) ($job['channel'] ?? '');

$status_ru = [
    'pending'    => 'в очереди',
    'processing' => 'выполняется',
    'done'       => 'готово',
    'failed'     => 'ошибка',
    'cancelled'  => 'отменено',
];

foreach (['payload', 'result'] as $col)
{
    if (isset($job[$col]) AND is_string($job[$col]))
    {
        $job[$col] = json_decode($job[$col], TRUE);
    }
}

$fmt = static function ($ts): string {
    return $ts ? HTML::chars(Date::formatted_time((string) $ts, 'd.m.y H:i:s')) : '—';
};
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/prx120_jobs" class="transition hover:text-emerald-600 dark:hover:text-emerald-400">Монитор воркеров</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Задача #<?php echo (int) $job_id; ?></span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2">
        <h1 class="prx-h1">
            <i class="fas fa-list-check mr-2 text-emerald-600 dark:text-emerald-400"></i>Задача #<?php echo (int) $job_id; ?>
        </h1>
        <span class="prx-badge <?php echo $badge[$job['status']] ?? 'prx-badge-wait'; ?>"><span class="prx-dot"></span><?php echo HTML::chars($status_ru[$job['status']] ?? $job['status']); ?></span>
        <span class="prx-count font-mono"><?php echo HTML::chars($label); ?></span>
    </div>

    <?php /* «Почему висит и что дальше»: одно предложение + цифры пульса и
     * прогресса. Раньше карточка показывала статус и попытки, но не отвечала
     * на главный вопрос владельца — ждать или чинить (28.09). */ ?>
    <?php if ( ! empty($diagnosis['text'])): ?>
    <div class="prx-card mt-3">
        <p class="flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white">
            <i class="fas <?php echo $job['status'] === 'processing' ? 'fa-stethoscope text-amber-500' : 'fa-circle-info text-blue-500'; ?>"></i>
            Что с задачей
        </p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $diagnosis['text']); ?></p>
        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-400">
            <span>пульс: <?php echo $diagnosis['pulse_ago'] !== NULL ? (int) floor($diagnosis['pulse_ago'] / 60).' мин назад' : '—'; ?></span>
            <span>последний рапорт: <?php echo $diagnosis['progress_ago'] !== NULL ? (int) floor($diagnosis['progress_ago'] / 60).' мин назад' : '—'; ?></span>
            <span>порог авто-стопа: <?php echo max(1, (int) round(((int) $diagnosis['silent_sec']) / 60)); ?> мин</span>
            <span>порог «работы нет»: <?php echo max(1, (int) round(((int) $diagnosis['stalled_sec']) / 60)); ?> мин</span>
            <span>попытка: <?php echo (int) $diagnosis['attempts']; ?>/<?php echo (int) $diagnosis['max_attempts']; ?></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="mt-4 grid gap-3 lg:grid-cols-2">
        <div class="prx-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Сведения</p>
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                <dt class="text-gray-400">Канал</dt><dd class="font-mono text-xs text-gray-700 dark:text-gray-300"><?php echo HTML::chars((string) $job['channel']); ?></dd>
                <dt class="text-gray-400">Воркер</dt><dd class="font-mono text-xs text-gray-700 dark:text-gray-300"><?php echo HTML::chars((string) ($job['claimed_by'] ?? '—')); ?></dd>
                <dt class="text-gray-400">Создана</dt><dd class="text-gray-700 dark:text-gray-300"><?php echo $fmt($job['created_at'] ?? NULL); ?></dd>
                <dt class="text-gray-400">Старт</dt><dd class="text-gray-700 dark:text-gray-300"><?php echo $fmt($job['started_at'] ?? NULL); ?></dd>
                <dt class="text-gray-400">Финиш</dt><dd class="text-gray-700 dark:text-gray-300"><?php echo $fmt($job['finished_at'] ?? NULL); ?></dd>
                <dt class="text-gray-400">Попытки</dt><dd class="text-gray-700 dark:text-gray-300"><?php echo (int) $job['attempts']; ?>/<?php echo (int) $job['max_attempts']; ?></dd>
            </dl>
            <?php if ( ! empty($job['error'])): ?>
            <p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-600 dark:bg-rose-950/40 dark:text-rose-300"><?php echo HTML::chars((string) $job['error']); ?></p>
            <?php endif; ?>
        </div>

        <div class="prx-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Параметры (payload)</p>
            <pre class="mt-2 max-h-56 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-600 dark:bg-gray-900/40 dark:text-gray-300"><?php echo HTML::chars(json_encode($job['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
        </div>
    </div>

    <div class="prx-card mt-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Результат</p>
        <?php if ( ! empty($job['result'])): ?>
        <pre class="mt-2 max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-600 dark:bg-gray-900/40 dark:text-gray-300"><?php echo HTML::chars(json_encode($job['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
        <?php else: ?>
        <p class="mt-2 text-sm text-gray-400">Результата ещё нет.</p>
        <?php endif; ?>
    </div>

    <div class="prx-card mt-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Журнал задачи</p>
        <?php if ( ! empty($events)): ?>
        <ul class="mt-2 space-y-1.5">
            <?php foreach ($events as $e): ?>
            <li class="flex items-start gap-2 text-xs">
                <span class="mt-0.5 w-16 shrink-0 font-mono text-gray-400"><?php echo $fmt($e['created_at']); ?></span>
                <span class="prx-count shrink-0 font-mono"><?php echo HTML::chars((string) $e['event']); ?></span>
                <span class="min-w-0 break-words text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) ($e['message'] ?? '')); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="mt-2 text-sm text-gray-400">Событий пока нет.</p>
        <?php endif; ?>
    </div>
</div>
