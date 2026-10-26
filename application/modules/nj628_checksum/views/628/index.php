<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Что проверяем</span>
        <fieldset class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($algos as $code => $meta): ?>
                <label class="flex cursor-pointer select-none items-center">
                    <input type="radio" name="nj628-algo" value="<?php echo HTML::chars($code); ?>"<?php echo $algo === $code ? ' checked' : ''; ?> class="peer sr-only">
                    <span class="flex w-full flex-col rounded-lg border border-gray-200 px-3 py-2.5 text-sm transition hover:border-gray-400 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 dark:border-gray-600 dark:hover:border-gray-500 dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-900/30 dark:peer-checked:text-blue-300">
                        <span class="font-medium"><?php echo HTML::chars($meta[0]); ?></span>
                        <span class="mt-0.5 text-xs text-gray-400"><?php echo HTML::chars($meta[1]); ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <label for="nj628-in" class="n2j-label mt-4">Значение</label>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <input id="nj628-in" type="text" value="<?php echo HTML::chars($value); ?>" placeholder="4556 7375 8689 9855"
                   class="n2j-input min-w-0 flex-1 font-mono">
            <button type="button" id="nj628-go" class="n2j-btn-process">
                <i class="fas fa-check"></i> Проверить
            </button>
        </div>
        <div class="mt-3 flex flex-wrap gap-1.5">
            <button type="button" class="n2j-chip nj628-example" data-a="luhn" data-v="4556737586899855">карта (Луна)</button>
            <button type="button" class="n2j-chip nj628-example" data-a="inn" data-v="7707083893">ИНН 10</button>
            <button type="button" class="n2j-chip nj628-example" data-a="ogrn" data-v="1027700132195">ОГРН</button>
            <button type="button" class="n2j-chip nj628-example" data-a="ean" data-v="4607086561966">EAN-13</button>
            <button type="button" class="n2j-chip nj628-example" data-a="crc32" data-v="n2j.ru">CRC32</button>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-clipboard-check mr-1.5 text-gray-400"></i>Результат</p>
        <div id="nj628-out" class="mt-3 flex items-center gap-3">
            <span class="text-gray-400">Введите значение — узнаете, сходится ли контрольная цифра.</span>
        </div>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>Контрольная цифра ловит <b>опечатки</b>, а не подделку: любой валидный номер можно выдумать.</li>
            <li>ИНН: 10 знаков — юрлицо, 12 — физлицо/ИП.</li>
            <li>Карты проверяются по Луне — но не является «живой».</li>
            <li>CRC32 — целостность, не шифрование: считается без ключа.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что означает «не сходится»?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Число не прошло свой алгоритм — скорее всего опечатка. Контрольная цифра
                    для того и придумана, чтобы отлавливать такие ошибки на вводе.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Можно ли проверить существование ИНН?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет: здесь проверяется только корректность контрольной цифры. Существование
                    номера подтверждает только реестр ФНС.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Есть ли JSON API?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: GET /nj628/api/check?a=inn&amp;value=7707083893 — вернёт валидность и пояснение.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('628/script'); ?>
