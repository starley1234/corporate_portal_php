<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php $bases = Service_Nj624_Base::bases(); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-0 flex-1">
                <label for="nj624-in" class="n2j-label">Число</label>
                <input id="nj624-in" type="text" placeholder="FF или 255" value="FF"
                       class="n2j-input mt-1 w-full font-mono">
            </div>
            <div>
                <label for="nj624-from" class="n2j-label">Из</label>
                <select id="nj624-from" class="n2j-input n2j-select mt-1 w-auto">
                    <?php foreach ($bases as $b => $label): ?>
                        <option value="<?php echo $b; ?>"<?php echo $b === 16 ? ' selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="nj624-to" class="n2j-label">В</label>
                <select id="nj624-to" class="n2j-input n2j-select mt-1 w-auto">
                    <?php foreach ($bases as $b => $label): ?>
                        <option value="<?php echo $b; ?>"<?php echo $b === 2 ? ' selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap gap-1.5">
                <?php foreach (['FF' => [16, 2], '255' => [10, 16], '11111111' => [2, 10], '755' => [8, 10]] as $v => $fb): ?>
                    <button type="button" class="n2j-chip nj624-example" data-value="<?php echo HTML::chars($v); ?>" data-from="<?php echo $fb[0]; ?>" data-to="<?php echo $fb[1]; ?>">
                        <?php echo HTML::chars($v); ?><sub><?php echo $fb[0]; ?></sub>
                    </button>
                <?php endforeach; ?>
            </div>
            <button type="button" id="nj624-copy" class="n2j-btn-secondary n2j-btn-sm" disabled>
                <i class="fas fa-copy mr-1"></i>Копировать результат
            </button>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-grid-2 mr-1.5 text-gray-400"></i>Результат</p>
        <div id="nj624-out" class="mt-3 space-y-1.5 font-mono text-sm">
            <p class="text-gray-400">Введите число — увидите его во всех системах.</p>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-bars-staggered mr-1.5 text-gray-400"></i>Побитовые операции <span class="text-xs font-normal text-gray-400">(над десятичными значениями из поля выше)</span></p>
        <div class="mt-2 flex flex-wrap gap-1.5">
            <button type="button" class="n2j-btn-secondary n2j-btn-sm nj624-op" data-op="and">И (AND)</button>
            <button type="button" class="n2j-btn-secondary n2j-btn-sm nj624-op" data-op="or">ИЛИ (OR)</button>
            <button type="button" class="n2j-btn-secondary n2j-btn-sm nj624-op" data-op="xor">искл. ИЛИ (XOR)</button>
            <button type="button" class="n2j-btn-secondary n2j-btn-sm nj624-op" data-op="not">НЕ (NOT)</button>
            <button type="button" class="n2j-btn-secondary n2j-btn-sm nj624-op" data-op="shl">сдвиг влево «1</button>
            <button type="button" class="n2j-btn-secondary n2j-btn-sm nj624-op" data-op="shr">сдвиг вправо »1</button>
        </div>
        <div id="nj624-bitout" class="mt-3 hidden rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-950 dark:text-gray-200"></div>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>Префикс <span class="font-mono">0x</span> означает шестнадцатеричное, <span class="font-mono">0b</span> — двоичное.</li>
            <li>Одна hex-цифра ровно 4 бита: <span class="font-mono">FF = 1111 1111</span>.</li>
            <li><span class="font-mono">XOR</span> без переноса — дешёвые маски и инверсия битов.</li>
            <li><span class="font-mono">сдвиг влево на 1</span> = умножение на основание системы.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как перевести 255 в двоичную?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Введите 255, выберите «из десятичной, в двоичную» — получите 1111 1111.
                    Тот же результат: поделив 255 на 2 восемь раз и записав остатки задом наперёд.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Зачем побитовые операции?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Флаги, маски и компактное хранение: например, права доступа как битовая маска,
                    где включение бита — OR, проверка — AND, сброс — AND с инверсией.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Есть ли ограничение по размеру числа?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Перевод работает для обычных значений до десятков бит; очень большие числа
                    обрезаются до безопасного диапазона — этого хватает для флагов и масок.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('624/script'); ?>
