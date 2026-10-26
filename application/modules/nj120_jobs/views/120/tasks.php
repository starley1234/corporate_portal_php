<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Мои задачи» — список задач воркеров текущего посетителя (все модули).
 * По образцу: шапка с h1, слева список, справа блок-компаньон.
 */
$badge = [
    'done'       => ['bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400', 'готово'],
    'pending'    => ['bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300', 'в очереди'],
    'processing' => ['bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-400', 'выполняется'],
    'failed'     => ['bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400', 'ошибка'],
    'cancelled'  => ['bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500', 'отменена'],
];
$has_active = FALSE;
foreach ((array) $tasks as $t) { if (in_array($t['status'], ['pending', 'processing'], TRUE)) { $has_active = TRUE; break; } }

?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="mb-4">
        <h1 class="n2j-h1"><i class="fas fa-list-check mr-2 text-blue-500"></i>Мои задачи</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Фоновые операции ИИ-воркеров из всех сервисов N2J.ru<?php echo $has_active ? ' — страница сама обновит статус' : ''; ?>.</p>
    </div>

    <div class="gap-4 space-y-4 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Список задач (2/3) ============ -->
        <div class="min-w-0 md:col-span-2">
            <div class="n2j-card" data-nj120-tasks>
                <?php if (empty($tasks)): ?>
                    <div class="n2j-empty">
                        <div class="n2j-empty-icon"><i class="fas fa-inbox" aria-hidden="true"></i></div>
                        <p class="n2j-empty-text mt-4">Пока задач нет. Запустите любой ИИ-сервис — задачи появятся здесь.</p>
                        <div class="mt-5 flex flex-wrap justify-center gap-2">
                            <a href="/review" class="n2j-btn-secondary n2j-btn-sm">Ответ на отзыв</a>
                            <a href="/jobs" class="n2j-btn-secondary n2j-btn-sm">Поиск вакансий</a>
                            <a href="/docs" class="n2j-btn-secondary n2j-btn-sm">Документы организации</a>
                        </div>
                    </div>
                <?php else: ?>
                    <ul class="space-y-2">
                        <?php foreach ($tasks as $t):
                            $b = isset($badge[$t['status']]) ? $badge[$t['status']] : $badge['pending'];
                            $active = in_array($t['status'], ['pending', 'processing'], TRUE); ?>
                        <li class="n2j-card p-3 transition hover:border-gray-300">
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if ($active): ?><i class="fas fa-spinner n2j-spin text-xs text-blue-500"></i><?php endif; ?>
                                <span class="rounded-md px-2 py-0.5 text-xs font-bold uppercase <?php echo $b[0]; ?>"><?php echo $b[1]; ?></span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($t['label']); ?></span>
                                <?php if ($t['title'] !== ''): ?>
                                    <span class="min-w-0 truncate text-xs text-gray-500 dark:text-gray-400">— <?php echo HTML::chars(mb_substr($t['title'], 0, 60)); ?></span>
                                <?php endif; ?>
                                <?php if ($t['url']): ?>
                                    <a href="<?php echo HTML::chars($t['url']); ?>" class="ml-auto text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">открыть →</a>
                                <?php endif; ?>
                            </div>
                            <?php if ($active AND ! empty($t['stage'])): ?>
                            <p class="mt-1.5 truncate text-xs font-medium text-blue-600 dark:text-blue-400" title="<?php echo HTML::chars($t['stage']); ?>">
                                <i class="fas fa-spinner n2j-spin mr-1"></i><?php echo HTML::chars($t['stage']); ?>
                            </p>
                            <?php elseif ( ! $active AND $t['stage'] !== '' AND $t['status'] === 'done'): ?>
                            <p class="n2j-hint mt-1.5 truncate" title="Последний этап воркера"><i class="fas fa-circle-check mr-1 text-emerald-500"></i><?php echo HTML::chars($t['stage']); ?></p>
                            <?php endif; ?>
                            <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-gray-400 dark:text-gray-500">
                                <span><?php echo HTML::chars($t['created_at']); ?></span>
                                <?php if ($t['finished_at']): ?><span>завершена: <?php echo HTML::chars($t['finished_at']); ?></span><?php endif; ?>
                                <?php if ($t['status'] === 'failed' AND $t['error']): ?>
                                    <span class="text-rose-400"><?php echo HTML::chars(mb_substr($t['error'], 0, 120)); ?></span>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="n2j-hint mt-4">Показаны последние <?php echo count($tasks); ?> задач.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ Правая колонка (1/3) ============ -->
        <div class="min-w-0 space-y-4 md:col-span-1">
            <?php if ( ! empty($budget)): ?>
            <?php echo View::factory('120/_budget', ['budget' => $budget])->render(); ?>
            <?php endif; ?>
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-timeline mr-1.5 text-gray-400"></i>Жизненный цикл задачи</p>
                <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Форма сервиса ставит задачу в очередь (<code class="rounded bg-gray-100 px-1 font-mono text-xs dark:bg-gray-900">в очереди</code>) → воркер забирает (<code class="rounded bg-gray-100 px-1 font-mono text-xs dark:bg-gray-900">выполняется</code>) → результат (<code class="rounded bg-gray-100 px-1 font-mono text-xs dark:bg-gray-900">готово</code>) или ошибка с причиной. Статус можно смотреть и на странице самого сервиса.</p>
            </div>

            <?php if ( ! empty($notify_url) AND ! empty($logged_in)): ?>
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-bell mr-1.5 text-gray-400"></i>Уведомления</p>
                <p class="mt-1.5">Хотите получать уведомления о готовности задач —
                    <a href="<?php echo HTML::chars($notify_url); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">настройте подписки в центре уведомлений</a>.</p>
            </div>
            <?php elseif (empty($logged_in)): ?>
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-user-lock mr-1.5 text-gray-400"></i>Задачи привязаны к браузеру</p>
                <p class="mt-1.5"><a href="/auth/login?return_url=tasks" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Войдите</a> — и они будут доступны с любого устройства.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
/* nj120 «Мои задачи»: пока есть активные — мягкое автообновление списка */
(function () {
    'use strict';

    var root = document.querySelector('[data-nj120-tasks]');
    if (!root || !root.querySelector('.n2j-spin')) return;

    setTimeout(function () { location.reload(); }, 15000);
})();
</script>
