<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120: /tasks/<code> — задачи по коду артефакта (посадочная для ссылок из
 * уведомлений prx597: «деталь готова», «обзор собран» и т.п.).
 *
 * Почему отдельная вьюха: раньше эта страница рендерила views/prx120/task.php —
 * вьюху ОДНОЙ задачи, которая ждёт `job`, `job_id`, `label` и `events`. Здесь
 * этих переменных нет по определению (мы показываем список задач по коду), и
 * живой /tasks/cad5e0ff1e1 падал: «Undefined variable $job_id»
 * (APPATH/modules/prx120_jobs/views/prx120/task.php [41]). Один шаблон на две
 * разные формы экрана — типовая причина таких падений.
 *
 * Ожидает: $code, $latest (последняя задача | NULL), $tasks (список),
 * $result_url (ссылка на результат | NULL).
 */
$code       = isset($code) ? (string) $code : '';
$latest     = isset($latest) ? $latest : NULL;
$tasks      = isset($tasks) ? (array) $tasks : [];
$result_url = isset($result_url) ? (string) $result_url : '';

$badge = [
    'pending'    => 'prx-badge-wait',
    'processing' => 'prx-badge-run',
    'done'       => 'prx-badge-ok',
    'failed'     => 'prx-badge-err',
    'cancelled'  => 'prx-badge-wait',
];
$status_ru = [
    'pending'    => 'в очереди',
    'processing' => 'выполняется',
    'done'       => 'готово',
    'failed'     => 'ошибка',
    'cancelled'  => 'отменено',
];

$fmt = static function ($ts): string {
    return $ts ? HTML::chars(Date::formatted_time((string) $ts, 'd.m.y H:i:s')) : '—';
};

$latest_status = $latest ? (string) $latest['status'] : '';
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/tasks" class="transition hover:text-blue-600 dark:hover:text-blue-400">Мои задачи</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300"><?php echo HTML::chars($code); ?></span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2">
        <h1 class="prx-h1">
            <i class="fas fa-list-check mr-2 text-blue-500"></i>Задача <?php echo HTML::chars($code); ?>
        </h1>
        <?php if ($latest): ?>
        <span class="prx-badge <?php echo $badge[$latest_status] ?? 'prx-badge-wait'; ?>"><span class="prx-dot"></span><?php echo HTML::chars($status_ru[$latest_status] ?? $latest_status); ?></span>
        <span class="prx-count"><?php echo HTML::chars((string) $latest['label']); ?></span>
        <?php endif; ?>
        <?php if ($result_url !== ''): ?>
        <a href="<?php echo HTML::chars($result_url); ?>" class="prx-btn-secondary prx-btn-sm">
            <i class="fas fa-arrow-up-right-from-square text-[11px]"></i>Открыть результат
        </a>
        <?php endif; ?>
        <a href="/prx120/monitor" class="prx-btn-secondary prx-btn-sm">
            Монитор воркеров
        </a>
    </div>

    <?php if ( ! $tasks): ?>
    <div class="prx-card mt-4 text-sm text-gray-600 dark:text-gray-300">
        По этому коду задач не нашлось. Возможно, ссылка устарела или задача была удалена —
        <a href="/tasks" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">все мои задачи</a>.
    </div>
    <?php else: ?>

    <?php if ($latest AND trim((string) ($latest['error'] ?? '')) !== ''): ?>
    <p class="prx-flash prx-flash-err mt-3 block">
        <?php echo nl2br(HTML::chars((string) $latest['error'])); ?>
    </p>
    <?php endif; ?>

    <div class="prx-card p-0 mt-4 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="prx-table">
            <thead>
                <tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
                    <th class="px-3.5 py-2.5">#</th>
                    <th class="px-3 py-2.5">Что делали</th>
                    <th class="px-3 py-2.5">Состояние</th>
                    <th class="px-3 py-2.5">Создана</th>
                    <th class="px-3.5 py-2.5">Финиш</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $t): $st = (string) $t['status']; ?>
                <tr class="border-b border-gray-50 last:border-0 dark:border-gray-700/40">
                    <td class="px-3.5 py-2.5 font-mono text-gray-400">
                        <a href="/prx120/task/<?php echo (int) $t['id']; ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400" title="Открыть журнал задачи"><?php echo (int) $t['id']; ?></a>
                    </td>
                    <td class="px-3 py-2.5 text-gray-700 dark:text-gray-200">
                        <?php echo HTML::chars((string) $t['label']); ?>
                        <?php if (trim((string) $t['error']) !== ''): ?>
                        <span class="block text-rose-600 dark:text-rose-400"><?php echo HTML::chars(mb_substr((string) $t['error'], 0, 120)); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2.5">
                        <span class="prx-badge <?php echo $badge[$st] ?? 'prx-badge-wait'; ?>"><span class="prx-dot"></span><?php echo HTML::chars($status_ru[$st] ?? $st); ?></span>
                    </td>
                    <td class="px-3 py-2.5 text-gray-500 dark:text-gray-400"><?php echo $fmt($t['created']); ?></td>
                    <td class="px-3.5 py-2.5 text-gray-500 dark:text-gray-400"><?php echo $fmt($t['finished']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php if ($latest): ?>
    <div class="prx-card mt-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Параметры последней задачи</p>
        <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-50 p-3 font-mono text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300"><?php echo HTML::chars(json_encode($latest['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
        <?php if ( ! empty($latest['result'])): ?>
        <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Итог</p>
        <pre class="mt-2 max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-50 p-3 font-mono text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300"><?php echo HTML::chars(json_encode($latest['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
