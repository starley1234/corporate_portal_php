<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <label for="nj621-expr" class="n2j-label">Cron-выражение</label>
        <input id="nj621-expr" type="text" autocomplete="off" spellcheck="false"
               value="<?php echo HTML::chars($expr); ?>"
               placeholder="*/15 * * * *"
               class="n2j-input mt-2 w-full font-mono">
        <div class="mt-3 flex flex-wrap gap-2">
            <?php $presets = ['* * * * *' => 'Каждую минуту', '0 * * * *' => 'В начале часа', '*/15 * * * *' => 'Каждые 15 мин', '0 3 * * *' => 'Ежедневно в 03:00', '0 9 * * 1-5' => 'Будни в 09:00', '0 0 1 * *' => '1-го числа в 00:00']; ?>
            <?php foreach ($presets as $preset => $label): ?>
                <button type="button" class="n2j-btn-secondary n2j-btn-sm nj621-preset" data-expr="<?php echo HTML::chars($preset); ?>"><?php echo HTML::chars($label); ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="nj621-out" class="mt-4 space-y-4"></div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Синтаксис</p>
        <p class="mt-3 font-mono text-xs leading-relaxed text-gray-600 dark:text-gray-300">
            мин час день месяц день_недели [команда]
        </p>
        <ul class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
            <li>* — любое значение</li>
            <li>a-b — диапазон</li>
            <li>*/n — каждые n</li>
            <li>день недели: 0 или 7 = воскресенье</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что значит */15?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    «Каждые 15»: 0, 15, 30, 45. В поле минут — каждые 15 минут.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Учтён ли часовой пояс?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Следующие запуски показываются в часовом поясе Москвы (Europe/Moscow).
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('621/script'); ?>
