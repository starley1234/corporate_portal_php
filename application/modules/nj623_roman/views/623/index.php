<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <label for="nj623-in" class="n2j-label">Число или римская запись</label>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <input id="nj623-in" type="text" value="<?php echo HTML::chars($sample); ?>" placeholder="1987 или MCMLXXXVII"
                   class="n2j-input min-w-0 flex-1 font-mono">
            <button type="button" id="nj623-go" class="n2j-btn-process">
                <i class="fas fa-arrow-right-arrow-left"></i> Перевести
            </button>
        </div>

        <div class="mt-3 flex flex-wrap gap-1.5">
            <?php foreach (['1987' => 'MCMLXXXVII', '2026' => 'MMXXVI', '3999' => 'MMMCMXCIX', 'XIV' => '14', 'MCMLXIV' => '1964'] as $v => $r): ?>
                <button type="button" class="n2j-chip nj623-example" data-value="<?php echo HTML::chars($v); ?>">
                    <?php echo HTML::chars($v); ?> = <?php echo HTML::chars($r); ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-equals mr-1.5 text-gray-400"></i>Результат</p>
            <button type="button" id="nj623-copy" class="n2j-btn-secondary n2j-btn-sm" disabled>
                <i class="fas fa-copy mr-1"></i>Копировать
            </button>
        </div>
        <div class="mt-3 flex items-center gap-3">
            <span id="nj623-dir" class="hidden rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-600 dark:bg-blue-900/30 dark:text-blue-300"></span>
            <span id="nj623-out" class="break-all font-mono text-2xl font-bold text-gray-900 dark:text-white"></span>
        </div>
        <p id="nj623-hint" class="n2j-hint mt-2 hidden"></p>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li><b>IV</b> — 4, <b>IX</b> — 9: меньшая цифра перед большей вычитается.</li>
            <li>Не больше трёх одинаковых цифр подряд — отсюда <b>IV</b>, а не <b>IIII</b>.</li>
            <li>Классический диапазон — <b>1–3999</b>; ноль в римской записи не было.</li>
            <li>Введите <span class="font-mono text-gray-400">2026</span> — получите <span class="font-mono text-gray-400">MMXXVI</span>.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как читать римское число?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Складывайте значения слева направо; если цифра меньше следующей — вычитайте.
                    MCMLXXXVII = 1000 + 900 + 50 + 30 + 5 + 1 + 1 = 1987.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Почему нельзя записать 0 или 5000?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    В классической римской системе нет нуля, а для больших чисел использовались
                    надстрочные обозначения. Этот конвертер работает в диапазоне 1–3999.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Подходит для программного вызова?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: GET /nj623/api/convert?value=1987 вернёт JSON с готовым переводом.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('623/script'); ?>
