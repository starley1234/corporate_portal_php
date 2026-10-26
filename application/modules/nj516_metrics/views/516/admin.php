<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Админ-панель метрик витрины.
 *
 * @var array $stats дневные агрегаты, без данных о конкретных людях
 * @var int   $days  7|30|90
 */

$number = function ($n) { return number_format((int) $n, 0, ',', "\xC2\xA0"); };
$source_name = ['direct' => 'Прямой заход', 'internal' => 'Внутренний переход', 'search' => 'Поиск', 'external' => 'Другие сайты'];
$audience_name = ['guest' => 'Гости', 'logged' => 'Вошедшие'];
$views = (int) $stats['views'];
$max_daily = 1;
foreach ($stats['daily'] as $d) $max_daily = max($max_daily, (int) $d['views']);
$max_group = 1;
foreach ($stats['groups'] as $g) $max_group = max($max_group, (int) $g['views']);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">Витрина N2J</p>
            <h1 class="n2j-h1 mt-1">Просмотры публичных сервисов</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Обезличенные дневные агрегаты · без внешней аналитики и идентификаторов посетителей</p>
        </div>
        <form method="get" action="/nj516/admin" class="flex items-center gap-2">
            <label for="metrics-days" class="text-sm text-gray-600 dark:text-gray-300">Период</label>
            <select id="metrics-days" name="days" onchange="this.form.submit()" class="n2j-input n2j-select font-semibold w-auto">
                <?php foreach ([7 => '7 дней', 30 => '30 дней', 90 => '90 дней'] as $n => $label): ?>
                    <option value="<?php echo $n; ?>" <?php echo $days === $n ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="n2j-btn-process">Показать</button></noscript>
        </form>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/60 dark:bg-blue-950/30">
            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Просмотров за <?php echo $days; ?> дней</p>
            <p class="mt-1 text-3xl font-extrabold text-blue-800 dark:text-blue-200"><?php echo $number($stats['views']); ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Сервисов с просмотрами</p>
            <p class="mt-1 text-3xl font-extrabold text-gray-900 dark:text-white"><?php echo $number($stats['services']); ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Предыдущие <?php echo $days; ?> дней</p>
            <p class="mt-1 text-3xl font-extrabold text-gray-900 dark:text-white"><?php echo $number($stats['previous_views']); ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Изменение к прошлому периоду</p>
            <?php if ($stats['change_pct'] === NULL): ?>
                <p class="mt-1 text-2xl font-extrabold text-gray-900 dark:text-white">—</p>
                <p class="n2j-hint">нет данных для сравнения</p>
            <?php else: ?>
                <p class="mt-1 text-2xl font-extrabold <?php echo $stats['change_pct'] >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300'; ?>">
                    <?php echo $stats['change_pct'] > 0 ? '+' : ''; ?><?php echo str_replace('.', ',', (string) $stats['change_pct']); ?> %
                </p>
                <p class="n2j-hint">просмотры, не уникальные люди</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        <section class="n2j-card xl:col-span-2">
            <h2 class="n2j-h3">Динамика по дням</h2>
            <div class="mt-4 flex h-44 items-end gap-1 border-b border-gray-200 pb-1 dark:border-gray-700" role="img" aria-label="Просмотры по дням">
                <?php foreach ($stats['daily'] as $d):
                    $height = max(2, (int) round((int) $d['views'] / $max_daily * 100));
                    ?>
                    <div class="group flex h-full min-w-0 flex-1 flex-col justify-end" title="<?php echo HTML::chars($d['day'].': '.$number($d['views'])); ?>">
                        <div class="w-full rounded-t bg-blue-500 transition hover:bg-blue-600 dark:bg-blue-400" style="height: <?php echo $height; ?>%"></div>
                        <?php if ($days <= 30): ?><span class="mt-1 truncate text-center text-xs text-gray-400"><?php echo HTML::chars(substr($d['day'], 8)); ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-2 flex justify-between text-xs text-gray-500 dark:text-gray-400"><span><?php echo HTML::chars($stats['from']); ?></span><span><?php echo HTML::chars($stats['to']); ?></span></div>
        </section>

        <section class="n2j-card">
            <h2 class="n2j-h3">Источники перехода</h2>
            <?php if ( ! $stats['sources']): ?>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Пока просмотров нет. Откройте публичную страницу сервиса.</p>
            <?php else: ?>
                <ul class="mt-3 space-y-3">
                    <?php foreach ($stats['sources'] as $source): $pct = $views > 0 ? round($source['views'] / $views * 100) : 0; ?>
                        <li>
                            <?php // Доли — числами, без полосы: полосы убраны везде (владелец, 03.10.2026). ?>
                            <div class="flex justify-between gap-2 text-sm"><span class="text-gray-700 dark:text-gray-200"><?php echo HTML::chars($source_name[$source['name']] ?? $source['name']); ?></span><span class="shrink-0 text-gray-500 dark:text-gray-400"><b class="text-gray-900 dark:text-white"><?php echo $number($source['views']); ?></b> · <?php echo (int) $pct; ?>%</span></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        <section class="n2j-card">
            <h2 class="n2j-h3">Популярные группы</h2>
            <?php if ( ! $stats['groups']): ?><p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Пока данных нет.</p>
            <?php else: ?>
                <ul class="mt-3 space-y-3">
                    <?php foreach ($stats['groups'] as $group): $pct = (int) round($group['views'] / $max_group * 100); ?>
                        <li>
                            <div class="flex justify-between gap-2 text-sm"><span class="text-gray-700 dark:text-gray-200"><?php echo HTML::chars($group['name']); ?></span><span class="shrink-0 text-gray-500 dark:text-gray-400"><b class="text-gray-900 dark:text-white"><?php echo $number($group['views']); ?></b> · <?php echo (int) $pct; ?>%</span></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="n2j-card xl:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="n2j-h3">Топ сервисов</h2>
                <span class="text-xs text-gray-500 dark:text-gray-400">просмотры за <?php echo $days; ?> дней</span>
            </div>
            <?php if ( ! $stats['top']): ?><p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Пока данных нет.</p>
            <?php else: ?>
                <div class="mt-3 overflow-x-auto">
                    <table class="n2j-table min-w-[520px]">
                        <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400"><tr><th class="py-2 pr-3">Сервис</th><th class="py-2 pr-3">Группа</th><th class="py-2 pr-3 text-right">Просмотры</th><th class="py-2 text-right">Гость / вошёл</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-200">
                            <?php foreach ($stats['top'] as $row): ?>
                                <tr>
                                    <td class="py-2 pr-3"><a href="<?php echo HTML::chars($row['path']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($row['path']); ?></a></td>
                                    <td class="py-2 pr-3"><?php echo HTML::chars($row['group']); ?></td>
                                    <td class="py-2 pr-3 text-right font-bold"><?php echo $number($row['views']); ?></td>
                                    <td class="py-2 text-right text-xs text-gray-500 dark:text-gray-400"><?php echo $number($row['guests']); ?> / <?php echo $number($row['logged']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
        <p class="font-semibold"><i class="fas fa-shield-halved mr-1.5 text-emerald-600 dark:text-emerald-400"></i>Приватность по умолчанию</p>
        <p class="mt-1">Хранятся только суммы просмотров по дню, странице, группе, типу перехода и признаку «гость / вошёл». Идентификаторы, cookie ID, IP, поисковые фразы, полный адрес источника и user-agent не сохраняются. «Просмотры» — не «уникальные посетители».</p>
    </section>
</div>
