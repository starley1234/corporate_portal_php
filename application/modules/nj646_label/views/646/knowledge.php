<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * База знаний (nj646_label) — /label/knowledge: справочник веществ.
 *
 * Владелец (03.10.2026): «база знаний — своя страница», «список слева,
 * подробности справа». Раньше справочник был вкладкой на /label рядом с полем
 * ввода состава: человек, пришедший разобрать этикетку, видел чужой интерфейс.
 *
 * Список грузится запросом (/label/api/search), карточка — /label/api/ingredient:
 * веществ тысячи, и тащить их все в HTML незачем. Ссылка на вещество —
 * /label/knowledge?slug=<slug>, сюда ведут позиции из разбора.
 */

$fmt = function ($n) {
    return number_format((float) $n, 0, ',', ' ');
};

$risk_dot = ['bg-emerald-500', 'bg-gray-400', 'bg-amber-500', 'bg-rose-400', 'bg-rose-600'];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="n2j-h1">База знаний</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Что сервис знает о веществах: <?php echo $fmt($stats['ingredients']); ?> опубликованных карточек,
                из них <?php echo $fmt($stats['learned']); ?> изучила модель.
                Выберите вещество слева — карточка откроется справа. Чем чаще оно встречается в разборах, тем выше в списке.
                У каждого вещества есть и постоянная страница — её удобно послать знакомому или открыть позже.
            </p>
        </div>
        <a href="/label"
           class="n2j-btn-process">
            <i class="fas fa-wand-magic-sparkles mr-1"></i>разобрать состав
        </a>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">

        <!-- ============================ СПИСОК ============================ -->
        <div class="n2j-card">
            <div class="flex flex-col gap-2">
                <input id="nj646-search" type="text" autocomplete="off" placeholder="Название, E-код, категория…"
                       class="n2j-input w-full">
                <button type="button" id="nj646-search-btn"
                        class="n2j-btn-secondary">
                    <i class="fas fa-magnifying-glass mr-1"></i>Найти
                </button>
            </div>
            <div id="nj646-book-list" class="mt-3 space-y-2 lg:max-h-[70vh] lg:overflow-y-auto lg:pr-1"></div>
        </div>

        <!-- ============================ КАРТОЧКА ============================ -->
        <div id="nj646-card">
            <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50/60 p-6 text-center text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-900/40 dark:text-gray-400">
                <i class="fas fa-hand-pointer mb-2 block text-lg text-gray-400"></i>
                Выберите вещество в списке слева — здесь появится карточка с риском, пояснением и источниками.
            </div>
        </div>
    </div>

    <!-- Сводка «что накопила база» — на странице базы знаний, а не на входе в разбор. -->
    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $cards = [
            ['Изучено ИИ', $stats['learned'], 'карточек собрала модель'],
            ['Незнакомых веществ', $stats['unknowns'], 'в очереди базы'],
            ['Из них изучено', $stats['unknowns_studied'], 'стали карточками'],
            ['Правок посетителей', $stats['votes'], 'голоса «согласен / спорно»'],
        ];
        foreach ($cards as $card): ?>
            <div class="n2j-card">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo $card[0]; ?></div>
                <div class="mt-1 text-xl font-bold text-gray-900 dark:text-white"><?php echo $fmt($card[1]); ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo $card[2]; ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($stats['top']): ?>
        <div class="n2j-card mt-4">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                <i class="fas fa-fire mr-1.5 text-amber-500"></i>Чаще всего встречается в составах
            </p>
            <ul class="mt-3 grid gap-2 text-xs text-gray-600 sm:grid-cols-2 lg:grid-cols-4 dark:text-gray-300">
                <?php foreach ($stats['top'] as $item): ?>
                    <li class="flex items-center justify-between gap-2">
                        <a class="min-w-0 truncate hover:text-blue-700 hover:underline dark:hover:text-blue-300"
                           href="/label/ingredient/<?php echo urlencode((string) $item['slug']); ?>">
                            <span class="mr-1.5 inline-block h-2 w-2 rounded-full <?php echo $risk_dot[(int) $item['risk']] ?? 'bg-gray-400'; ?>"></span>
                            <?php echo HTML::chars($item['name_ru']); ?>
                        </a>
                        <span class="shrink-0 text-gray-400">×<?php echo $fmt($item['hits']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="n2j-hint mt-4">
        Карточка: риск 0–4, пояснение, синонимы, источники (EFSA, FDA, ВОЗ, SCCS, CIR).
        Карточки, собранные моделью, помечены черновиком до проверки редактором.
        Вы с чем-то не согласны — нажмите «Спорно»: сервис запомнит.
    </p>
</div>

<?php echo View::factory('646/js/core'); ?>
<?php echo View::factory('646/js/book'); ?>
