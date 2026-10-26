<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * «Мои разборы» (nj646_label) — /label/my_analysis.
 *
 * История показывает товары, а не строки-оценки: категория, имя, производитель,
 * дата и короткие тезисы. Весь состав и предупреждения открываются по строке.
 * Оформление следует /pages/required_design_sample: общая шапка, рабочая
 * область во всю ширину как на /label, без центрирующей max-width обёртки.
 *
 * @var array $history последние разборы текущего браузера
 * @var int   $history_total общее число доступных записей
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <header>
        <div class="flex flex-wrap items-center gap-2.5">
            <h1 class="n2j-h1">Мои разборы</h1>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
                <i class="fas fa-clock-rotate-left text-[11px]" aria-hidden="true"></i>История
            </span>
        </div>
        <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">
            Разборы привязаны к этому браузеру. Сохранено: <?php echo (int) $history_total; ?>.
            Откройте товар, чтобы посмотреть полный состав, вещества и персональные предупреждения.
        </p>
    </header>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <div class="min-w-0 md:col-span-2">
            <section class="n2j-card">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3 dark:border-gray-700/60">
                    <div>
                        <h2 class="n2j-h3">Недавно разобранные товары</h2>
                        <p class="n2j-hint mt-0.5">Нажмите на строку, чтобы вернуться к разбору.</p>
                    </div>
                    <span class="n2j-badge n2j-badge-wait"><?php echo (int) $history_total; ?> всего</span>
                </div>

                <?php if (empty($history)): ?>
                    <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-6 text-center dark:border-gray-600 dark:bg-gray-900">
                        <i class="fas fa-clipboard-list text-2xl text-gray-300" aria-hidden="true"></i>
                        <p class="mt-2 text-sm font-semibold text-gray-700 dark:text-gray-200">История пока пустая</p>
                        <p class="n2j-hint">Разберите первую этикетку — товар появится здесь.</p>
                        <a href="/label" class="n2j-btn-process mt-4">
                            <i class="fas fa-magnifying-glass" aria-hidden="true"></i>Разобрать состав
                        </a>
                    </div>
                <?php else: ?>
                    <div class="mt-4 space-y-2">
                        <?php foreach ($history as $item): ?>
                            <?php echo View::factory('646/list_row', ['item' => $item]); ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($history_total > count($history)): ?>
                        <p class="n2j-hint mt-3">
                            Показаны последние <?php echo count($history); ?> из <?php echo (int) $history_total; ?>.
                        </p>
                    <?php endif; ?>

                    <a href="/label" class="n2j-btn-cta mt-4">
                        <i class="fas fa-plus" aria-hidden="true"></i>Новый разбор
                    </a>
                <?php endif; ?>
            </section>
        </div>

        <aside class="space-y-5">
            <section class="n2j-card">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500" aria-hidden="true"></i>
                    <h2 class="n2j-h3">Как устроена история</h2>
                </div>
                <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2"><span class="font-bold text-blue-500">•</span><span>В списке — товар, дата и короткие выводы; подробности открываются отдельно.</span></li>
                    <li class="flex gap-2"><span class="font-bold text-blue-500">•</span><span>Разборы видны только из того же браузера и не смешиваются с чужими.</span></li>
                    <li class="flex gap-2"><span class="font-bold text-blue-500">•</span><span>История очищается автоматически по сроку хранения модуля.</span></li>
                </ul>
            </section>

            <section class="rounded-xl border border-blue-100 bg-blue-50/70 p-4 shadow-xs sm:p-5 dark:border-blue-900/50 dark:bg-blue-950/30">
                <h2 class="n2j-h3"><i class="fas fa-user-shield mr-1 text-blue-500" aria-hidden="true"></i>Персональные предупреждения</h2>
                <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Аллергии, питание и другие настройки меняют только формулировки предупреждений. Профиль можно проверить или изменить в любой момент.</p>
            </section>
        </aside>
    </div>
</div>
