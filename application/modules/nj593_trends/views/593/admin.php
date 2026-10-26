<?php defined('SYSPATH') or die('No direct script access.');
/**
 * nj593 · управление: все обзоры, поиск и пагинация, создание «обзора дня» вручную.
 *
 * Дизайн — строго по эталону /pages/required_design_sample:
 *   • шапка: крошки → h1 и чипы в одном ряду → описание ниже;
 *   • рабочая область 2/3 + 1/3, компаньон `hidden md:block`;
 *   • карточки rounded-xl, отступы p-4 → sm:p-5/p-6, кнопки min-h-[44px];
 *   • плотность таблиц: строка 40px (py-2.5), кегль text-sm, статус — точкой
 *     и словом, удаление — иконкой-корзиной с окном подтверждения, пагинация и поиск.
 */
$st_dot = [
    'new'       => ['новый',       'text-gray-600 dark:text-gray-300',       'bg-gray-400'],
    'running'   => ['в работе',    'text-amber-700 dark:text-amber-400',     'bg-amber-500 animate-pulse'],
    'done'      => ['готово',      'text-emerald-700 dark:text-emerald-400', 'bg-emerald-500'],
    'error'     => ['ошибка',      'text-rose-700 dark:text-rose-400',         'bg-rose-500'],
    'cancelled' => ['остановлено', 'text-gray-500 dark:text-gray-400',       'bg-gray-400'],
];
$stats = isset($stats) && is_array($stats) ? $stats : ['total' => 0, 'done' => 0, 'live' => 0, 'failed' => 0];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-5 flex items-center gap-3 text-xs" data-flash>
        <i class="fas fa-circle-info shrink-0 text-base text-blue-600 dark:text-blue-400"></i>
        <span class="flex-1"><?php echo HTML::chars($flash); ?></span>
        <button type="button" class="n2j-btn-icon shrink-0 h-7 w-7" data-flash-close aria-label="Закрыть"><i class="fas fa-xmark"></i></button>
    </div>
    <?php endif; ?>

    <!-- ============ Шапка: крошки, h1 + чипы одной строкой ============ -->
    <header>
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сервисы</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <a href="/trends" class="transition hover:text-blue-600 dark:hover:text-blue-400">Тренды GitHub</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-medium text-gray-700 dark:text-gray-200">Управление</span>
        </nav>

        <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
            <h1 class="n2j-h1">Управление трендов</h1>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-100/90 px-3 py-1 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <i class="fas fa-layer-group text-[11px]"></i>всего <?php echo (int) $stats['total']; ?>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50/90 px-3 py-1 text-xs font-semibold text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-300">
                <i class="fas fa-circle-check text-[11px]"></i>готово <?php echo (int) $stats['done']; ?>
            </span>
            <?php if ((int) $stats['live'] > 0): ?>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50/90 px-3 py-1 text-xs font-semibold text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-300">
                <i class="fas fa-spinner text-[11px]"></i>в работе <?php echo (int) $stats['live']; ?>
            </span>
            <?php endif; ?>
        </div>

        <p class="mt-1.5 text-xs sm:text-sm text-gray-600 dark:text-gray-300">Все обзоры модуля: поиск по коду, теме, фокусу и репозиториям, удаление и ручной запуск «обзора дня» — то же, что делает ежедневный таймер.</p>
    </header>

    <!-- ============ Рабочая область: 2/3 + 1/3 ============ -->
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- Таблица всех обзоров (2/3) -->
        <div class="n2j-card p-0 md:col-span-2">

            <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 md:flex-row md:items-center md:justify-between dark:border-gray-700/60">
                <div class="flex items-center gap-2.5">
                    <h2 class="n2j-h3">Все обзоры</h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo (int) $total; ?></span>
                </div>

                <form method="get" action="/nj593/admin" class="flex w-full items-center gap-2 md:w-auto" role="search">
                    <input type="search" name="q" value="<?php echo HTML::chars($q); ?>" autocomplete="off"
                           placeholder="Поиск: код, тема, фокус, репозиторий"
                           class="n2j-input w-full min-w-0 md:w-72">
                    <button type="submit" class="n2j-btn-process shrink-0">
                        <i class="fas fa-magnifying-glass text-[11px]"></i>Найти
                    </button>
                    <?php if ($q !== ''): ?>
                    <a href="/nj593/admin" class="n2j-btn-secondary n2j-btn-sm shrink-0">Сбросить</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($rows)): ?>
            <p class="px-4 py-8 text-sm text-gray-500 sm:px-5 dark:text-gray-400">
                <?php if ($q !== ''): ?>
                По запросу «<span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($q); ?></span>» ничего не найдено.
                <a href="/nj593/admin" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Сбросить поиск</a>
                <?php else: ?>
                Обзоров пока нет — создайте первый справа.
                <?php endif; ?>
            </p>
            <?php else: ?>
            <div class="overflow-x-auto n2j-scrollbar">
                <table class="n2j-table min-w-[720px]">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pl-4 pr-3 sm:pl-5">Обзор</th>
                            <th class="py-2.5 px-3">Дата</th>
                            <th class="py-2.5 px-3">Фокус</th>
                            <th class="py-2.5 px-3">Позиции</th>
                            <th class="py-2.5 px-3">Статус</th>
                            <th class="py-2.5 pl-3 pr-4 text-right sm:pr-5">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                        <?php foreach ($rows as $row):
                            $dot = $st_dot[$row['status']] ?? $st_dot['new'];
                            $focus = trim((string) ($row['focus'] ?? '')); ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="py-2.5 pl-4 pr-3 sm:pl-5">
                                <div class="flex min-w-0 items-center gap-2">
                                    <a href="/trends/<?php echo HTML::chars($row['code']); ?>" class="shrink-0 font-mono text-sm font-bold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($row['code']); ?></a>
                                    <span class="min-w-0 truncate text-sm text-gray-600 dark:text-gray-300" title="<?php echo HTML::chars($row['title']); ?>"><?php echo HTML::chars($row['title'] !== '' ? $row['title'] : 'Без названия'); ?></span>
                                </div>
                            </td>
                            <td class="py-2.5 px-3 text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars(date('d.m.Y', strtotime((string) $row['rdate']))); ?></td>
                            <td class="max-w-[180px] truncate py-2.5 px-3 text-sm text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars($focus); ?>"><?php echo $focus !== '' ? HTML::chars($focus) : '—'; ?></td>
                            <td class="py-2.5 px-3 text-sm text-gray-500 dark:text-gray-400"><?php echo (int) $row['items_ready']; ?>/<?php echo (int) $row['item_count']; ?></td>
                            <td class="py-2.5 px-3">
                                <span class="inline-flex items-center gap-1.5 text-sm font-semibold <?php echo $dot[1]; ?>">
                                    <span class="h-2 w-2 rounded-full <?php echo $dot[2]; ?>"></span><?php echo $dot[0]; ?>
                                </span>
                            </td>
                            <td class="py-2.5 pl-3 pr-4 text-right sm:pr-5">
                                <div class="inline-flex items-center justify-end gap-1.5">
                                    <a href="/trends/<?php echo HTML::chars($row['code']); ?>" class="n2j-btn-secondary n2j-btn-sm">Открыть</a>
                                    <form method="post" action="/nj593/admin" class="inline-flex">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <input type="hidden" name="do" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                        <button data-n2j-confirm="Удалить обзор <?php echo HTML::chars($row['code']); ?> полностью? Позиции будут потеряны. Отменить нельзя." data-n2j-title="Удалить обзор?" type="submit" title="Удалить обзор" aria-label="Удалить обзор"
                                                class="n2j-btn-icon-danger">
                                            <i class="fas fa-trash-can text-sm"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php echo View::factory('partials/pagination', [
                'total'       => $total,
                'page'        => $page,
                'pages'       => $pages,
                'per'         => $per,
                'per_options' => $per_options,
                'url_base'    => '/nj593/admin',
                'params'      => ($q !== '' ? ['q' => $q] : []),
                'anchor'      => '',
            ]); ?>
            <?php endif; ?>
        </div>

        <!-- Компаньон (1/3): ручной запуск обзора дня -->
        <div class="hidden space-y-5 md:block">
            <div class="n2j-card">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-bolt text-base text-blue-600 dark:text-blue-400"></i>
                    <h2 class="n2j-h3">Обзор дня сейчас</h2>
                </div>

                <form method="post" action="/nj593/admin" class="mt-3 space-y-3.5">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="do" value="create">

                    <div>
                        <label for="ad-period" class="n2j-label">Репозитории созданы за</label>
                        <select id="ad-period" name="period" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                            <option value="1" selected>последние сутки</option>
                            <option value="2">последние 2 дня</option>
                            <option value="3">последние 3 дня</option>
                            <option value="7">последнюю неделю</option>
                        </select>
                    </div>

                    <div>
                        <label for="ad-count" class="n2j-label">Позиций в обзоре</label>
                        <select id="ad-count" name="count" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                            <option value="5">5</option>
                            <option value="8" selected>8</option>
                            <option value="10">10</option>
                        </select>
                    </div>

                    <input type="hidden" name="min_stars" value="50">

                    <p class="n2j-hint">То же, что делает ежедневный таймер: свежие репозитории, любой язык, звёзд ≥ 50. Владелец обзора — вы.</p>

                    <button type="submit" class="n2j-btn-cta w-full">
                        <i class="fas fa-bolt text-xs"></i>Создать обзор дня
                    </button>
                </form>
            </div>

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-shield-halved text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold">Памятка админу</h3>
                </div>
                <ul class="mt-3 space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Названия обзоров задаёт ИИ — код и дата надёжнее для поиска.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>«Готово» с нулём позиций — валидный обзор: ИИ посмотрел и не нашёл релевантного.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Удаление необратимо (позиции, выводы, ссылка) — подтверждается вторым кликом.</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php // общий скрипт модуля: флеш + двухшаговое удаление (иконка-корзина) ?>
<?php echo View::factory('593/script'); ?>
