<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <label for="nj625-in" class="n2j-label">Значение</label>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <input id="nj625-in" type="text" value="<?php echo HTML::chars($value === '' ? '1.5 гибибайт' : $value); ?>" placeholder="500 МБ или 1.5 гибибайт"
                   class="n2j-input min-w-0 flex-1 font-mono">
            <button type="button" id="nj625-go" class="n2j-btn-process">
                <i class="fas fa-rotate"></i> Перевести
            </button>
        </div>
        <div class="mt-3 flex flex-wrap gap-1.5">
            <?php foreach (['1 ТБ', '500 ГБ', '4 ГиБ', '256 МБ', '1 Мбит'] as $s): ?>
                <button type="button" class="n2j-chip nj625-example" data-value="<?php echo HTML::chars($s); ?>"><?php echo HTML::chars($s); ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-table-cells mr-1.5 text-gray-400"></i>Результат</p>
            <button type="button" id="nj625-copy" class="n2j-btn-secondary n2j-btn-sm" disabled>
                <i class="fas fa-copy mr-1"></i>Копировать таблицу
            </button>
        </div>
        <p id="nj625-human" class="mt-2 text-base font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($human); ?></p>
        <div id="nj625-table" class="mt-3 grid grid-cols-1 gap-1.5 text-sm sm:grid-cols-2">
            <?php foreach ($units as $code => $q): ?>
                <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-950">
                    <span class="text-gray-500 dark:text-gray-400"><?php echo $code; ?></span>
                    <span class="font-mono font-bold text-gray-800 dark:text-gray-100"><?php echo isset($table[$code]) ? HTML::chars($table[$code]) : '—'; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li><b>КиБ/МиБ/ГиБ</b> — двоичная шкала по 1024, как считает память и ОС.</li>
            <li><b>КБ/МБ/ГБ</b> — десятичная по 1000, как на упаковке дисков.</li>
            <li>1 байт = 8 бит; «Мбит/c» интернет-скорости — это мега<u>бит</u>ы.</li>
            <li>Диск «500 ГБ» ОС покажет как ~465 ГиБ — это не обман, а разная шкала.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Почему диск 500 ГБ — это 465 ГиБ?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Производитель считает гигабайт = 1 000 000 000 байт, а операционная система —
                    гибибайт = 1 073 741 824 байта. Сами байты одни и те же, шкалы разные.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Мбит/с и МБ/с — в чём разница?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Скорость сети традиционно в мегабитах в секунду. Чтобы получить мегабайты,
                    делите на 8: 100 Мбит/с ≈ 12,5 МБ/с.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Можно ли вызвать программно?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: GET /nj625/api/convert?value=500MB вернёт JSON с байтами и полной таблицей.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('625/script'); ?>
