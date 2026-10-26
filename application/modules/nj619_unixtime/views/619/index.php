<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <label for="nj619-ts" class="n2j-label">Timestamp</label>
        <input id="nj619-ts" type="text" inputmode="numeric" autocomplete="off"
               value="<?php echo HTML::chars($ts); ?>"
               placeholder="1700000000 или 1700000000000"
               class="n2j-input mt-2 w-full font-mono">
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" id="nj619-now" class="n2j-btn-secondary n2j-btn-sm">
                    <i class="fas fa-clock mr-1"></i>Сейчас
                </button>
                <button type="button" id="nj619-utility" class="n2j-btn-secondary n2j-btn-sm">
                    <i class="fas fa-chart-line mr-1"></i>Пример
                </button>
            </div>
            <p class="n2j-hint">Секунды или миллисекунды — определим автоматически.</p>
        </div>

        <span class="mt-4 block text-sm font-semibold text-gray-700 dark:text-gray-300">Часовой пояс</span>
        <fieldset class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($timezones as $code => $label): ?>
                <label class="flex cursor-pointer select-none items-center">
                    <input type="radio" name="nj619-tz" value="<?php echo HTML::chars($code); ?>"<?php echo $tz === $code ? ' checked' : ''; ?> class="peer sr-only">
                    <span class="flex w-full items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 transition hover:border-gray-400 hover:text-gray-800 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 dark:border-gray-600 dark:text-gray-300 dark:hover:border-gray-500 dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-900/30 dark:peer-checked:text-blue-300">
                        <?php echo HTML::chars($label); ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>
    </div>

    <div id="nj619-out" class="mt-4 grid gap-3 sm:grid-cols-2"></div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Шпаргалка</p>
        <ul class="mt-3 space-y-1.5 font-mono text-xs text-gray-600 dark:text-gray-300">
            <li>1 день = 86 400 с</li>
            <li>1 час = 3 600 с</li>
            <li>Текущее время — кнопка «Сейчас».</li>
            <li>1700000000000 = 13 знаков → миллисекунды.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Секунды или миллисекунды?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    10 знаков — секунды, 13 знаков — миллисекунды. Конвертер понимает оба варианта.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что такое Unix-время?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Число секунд (или миллисекунд) с полуночи 1 января 1970 года UTC.
                    Хранится в базах, логах и API — поэтому постоянно нужен перевод в дату.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('619/script'); ?>
