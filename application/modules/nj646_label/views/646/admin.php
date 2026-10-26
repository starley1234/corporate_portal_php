<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Админка «Разбора состава» — ДАШБОРД (/nj646/admin): цифры и что разбирали.
 *
 * Владелец (03.10.2026): «/nj646/admin — тоже разбить на две страницы, одна про
 * дашборд и про то что разбирали, а вторая про карточки и незнакомые вещества».
 *
 * Здесь только состояние: сколько накопилось, что разбирали люди, здоровье
 * очереди изучения и лимиты модели. Работа (черновики, незнакомые вещества,
 * массовые операции) — на /nj646/admin/moderation.
 */

$fmt = function ($n) {
    return number_format((float) $n, 0, ',', ' ');
};

$kind_labels = ['food' => 'еда', 'cosmetic' => 'косметика', 'both' => 'состав', 'auto' => 'состав'];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
        <?php
        // Цвет по исходу действия: «не получилось» должно выглядеть иначе, чем
        // «поставлено в очередь» — владелец видел одинаковые плашки и не мог
        // отличить предупреждение от справки.
        $flash_class = ($flash_kind ?? '') === 'warn'
            ? 'border-amber-300/80 bg-amber-50/90 text-amber-900 dark:border-amber-800/80 dark:bg-amber-950/40 dark:text-amber-200'
            : 'border-blue-300/80 bg-blue-50/90 text-blue-900 dark:border-blue-800/80 dark:bg-blue-950/40 dark:text-blue-200';
        ?>
        <div class="mb-5 rounded-xl border px-4 py-3 text-sm <?php echo $flash_class; ?>">
            <i class="fas <?php echo ($flash_kind ?? '') === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-info'; ?> mr-1"></i><?php echo HTML::chars($flash); ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="n2j-h1">Разбор состава · дашборд</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Что накопила база и что разбирали люди. Карточки, черновики и незнакомые вещества —
                на <a href="/nj646/admin/moderation" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">странице модерации</a>.
            </p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            <a href="/nj646/admin/moderation"
               class="n2j-btn-process">
                <i class="fas fa-file-circle-check mr-1"></i>модерация<?php if ((int) $drafts_total + (int) $unknown_todo > 0): ?>
                    (<?php echo (int) $drafts_total + (int) $unknown_todo; ?>)<?php endif; ?>
            </a>
            <a href="/nj646/admin/catalog"
               class="n2j-btn-secondary">
                <i class="fas fa-sitemap mr-1"></i>справочник
            </a>
            <a href="/nj646/admin/goods"
               class="n2j-btn-secondary">
                <i class="fas fa-barcode mr-1"></i>товары (<?php echo $fmt($goods['producers']).'/'.$fmt($goods['products']); ?>)
            </a>
            <a href="/nj646/admin/history"
               class="n2j-btn-secondary">
                <i class="fas fa-clock-rotate-left mr-1"></i>история (<?php echo $fmt($analyses_total); ?>)
            </a>
            <a href="/nj646/admin/import"
               class="n2j-btn-secondary">
                <i class="fas fa-file-import mr-1"></i>импорт словаря
            </a>
            <a href="/nj646/admin/merge"
               class="n2j-btn-secondary">
                <i class="fas fa-object-group mr-1"></i>слияние дублей
            </a>
        </div>
    </div>

    <!-- ============================ ЦИФРЫ ============================ -->
    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $cards = [
            ['Веществ опубликовано', $stats['ingredients'], 'карточек в базе', '/nj646/admin/catalog'],
            ['Черновиков', $stats['drafts'], 'ждут модерации', '/nj646/admin/moderation#nj646-drafts'],
            ['Незнакомых веществ', $stats['unknowns'], 'в очереди базы', '/nj646/admin/moderation#nj646-unknowns'],
            ['Разборов', $stats['analyses'], 'история посетителей', '/nj646/admin/history'],
            ['Изучено ИИ', $stats['learned'], 'карточки source=llm', '/nj646/admin/catalog'],
            ['Изучено неизвестных', $stats['unknowns_studied'], 'стали карточками', '/nj646/admin/catalog'],
            ['Правок посетителей', $stats['votes'], 'голоса «согласен/спорно»', '/nj646/admin/history'],
            ['Спорных оценок', $stats['disputes'], 'голосов против', '/nj646/admin/history'],
        ];
        foreach ($cards as $card): ?>
            <a href="<?php echo $card[3]; ?>"
               class="n2j-card transition hover:border-blue-300 dark:hover:border-blue-700">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo $card[0]; ?></div>
                <div class="mt-1 text-xl font-bold text-gray-900 dark:text-white"><?php echo $fmt($card[1]); ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo $card[2]; ?></div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- ============================ ЗДОРОВЬЕ ОЧЕРЕДИ ============================ -->
    <div class="n2j-card mt-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="n2j-h3">Очередь изучения</h2>
            <span class="flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-1.5">
                    <?php if ($llm_ready): ?>
                        <i class="fas fa-circle-check text-emerald-500"></i>модель подключена
                    <?php else: ?>
                        <i class="fas fa-circle-xmark text-rose-500"></i>модель НЕ подключена — изучение вернёт отказ
                    <?php endif; ?>
                </span>
                <span>· изучений за сутки: <?php echo (int) $study_used; ?> из <?php echo (int) $study_limits['per_day']; ?></span>
                <span>· развёрнутый ответ <?php echo $study_limits['detailed'] ? 'включён' : 'выключен'; ?>
                    (до <?php echo (int) $study_limits['detailed_studies']; ?> веществ за разбор)</span>
            </span>
        </div>

        <?php if (($study_queue['hint'] ?? '') !== ''): ?>
            <p class="n2j-flash n2j-flash-warn mt-3 text-xs block">
                <i class="fas fa-triangle-exclamation mr-1"></i><?php echo HTML::chars($study_queue['hint']); ?>
            </p>
        <?php endif; ?>

        <?php if ( ! $study_queue['jobs']): ?>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                Задач изучения ещё не было — они появляются, когда в составе встречается вещество, которого база не знает.
            </p>
        <?php else: ?>
            <div class="mt-3 overflow-auto">
                <table class="n2j-table">
                    <thead class="text-gray-400">
                        <tr>
                            <th class="py-1 pr-3 font-semibold">#</th>
                            <th class="py-1 pr-3 font-semibold">вещество</th>
                            <th class="py-1 pr-3 font-semibold">статус</th>
                            <th class="py-1 pr-3 font-semibold">этап воркера</th>
                            <th class="py-1 pr-3 font-semibold">взял</th>
                            <th class="py-1 pr-3 font-semibold">ждал</th>
                            <th class="py-1 font-semibold">ошибка</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700 dark:text-gray-200">
                    <?php foreach ($study_queue['jobs'] as $job): ?>
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="py-1 pr-3 font-mono text-gray-400"><?php echo (int) $job['id']; ?></td>
                            <td class="py-1 pr-3"><?php echo HTML::chars($job['name']); ?></td>
                            <td class="py-1 pr-3">
                                <span class="<?php echo $job['status'] === 'done' ? 'text-emerald-600 dark:text-emerald-400' : ($job['status'] === 'failed' ? 'text-rose-600 dark:text-rose-400' : 'text-amber-600 dark:text-amber-400'); ?>">
                                    <?php // Статус задачи — по-русски (i18n модуля), код остаётся в title. ?>
                                    <span title="<?php echo HTML::chars((string) $job['status']); ?>">
                                        <?php echo HTML::chars(Service_Nj646_Label::status_label((string) $job['status'], 'job')); ?>
                                    </span>
                                </span>
                                <span class="text-gray-400">· попытка <?php echo (int) $job['attempts']; ?></span>
                            </td>
                            <td class="py-1 pr-3 text-gray-600 dark:text-gray-300">
                                <?php if ((string) ($job['stage'] ?? '') !== ''): ?>
                                    <span class="inline-flex items-center gap-1.5">
                                        <?php if (in_array($job['status'], ['pending', 'claimed'], TRUE)): ?>
                                            <i class="fas fa-spinner n2j-spin text-[10px] text-blue-500"></i>
                                        <?php endif; ?>
                                        <?php echo HTML::chars((string) $job['stage']); ?>
                                    </span>
                                <?php elseif (in_array($job['status'], ['pending', 'claimed'], TRUE)): ?>
                                    <span class="inline-flex items-center gap-1.5 text-gray-400">
                                        <i class="fas fa-spinner n2j-spin text-[10px]"></i>ждёт воркера
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-1 pr-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($job['claimed'] !== '' ? $job['claimed'] : '— никто'); ?></td>
                            <td class="py-1 pr-3 <?php echo $job['stuck'] ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-gray-500 dark:text-gray-400'; ?>">
                                <?php echo (int) $job['age_min']; ?> мин
                            </td>
                            <td class="py-1 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($job['error']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================ ЧТО РАЗБИРАЛИ ============================ -->
    <div class="n2j-card mt-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="n2j-h3">Последние разборы</h2>
            <a href="/nj646/admin/history"
               class="n2j-btn-secondary n2j-btn-sm">
                вся история (<?php echo $fmt($analyses_total); ?>) →
            </a>
        </div>
        <?php if ( ! $history_recent): ?>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Разборов ещё не было.</p>
        <?php else: ?>
            <div class="mt-1 divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($history_recent as $row): ?>
                    <?php echo View::factory('646/list_row', ['item' => $row, 'flat' => TRUE]); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================ ПРАВКИ ПОСЕТИТЕЛЕЙ ============================ -->
    <div class="n2j-card mt-4">
        <h2 class="n2j-h3">Последние правки посетителей</h2>
        <?php if ( ! $votes): ?>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Правок пока нет.</p>
        <?php else: ?>
            <div class="mt-3 grid gap-1.5 text-sm text-gray-700 sm:grid-cols-2 dark:text-gray-200">
                <?php foreach ($votes as $vote): ?>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold"><?php echo HTML::chars($vote['name_ru']); ?></span>
                        <span class="n2j-badge <?php echo (int) $vote['value'] > 0 ? 'n2j-badge-ok' : 'n2j-badge-err'; ?>">
                            <?php echo (int) $vote['value'] > 0 ? 'согласен' : 'спорно'; ?>
                        </span>
                        <?php if ($vote['note'] !== ''): ?>
                            <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($vote['note']); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
