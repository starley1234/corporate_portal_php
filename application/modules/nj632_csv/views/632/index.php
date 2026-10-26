<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <div class="flex flex-wrap items-center gap-4">
            <fieldset class="flex gap-1.5">
                <label class="flex cursor-pointer select-none items-center">
                    <input type="radio" name="nj632-mode" value="csv2json"<?php echo $mode === 'csv2json' ? ' checked' : ''; ?> class="peer sr-only">
                    <span class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 transition hover:border-gray-400 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 dark:border-gray-600 dark:text-gray-300 dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-900/30 dark:peer-checked:text-blue-300">CSV → JSON</span>
                </label>
                <label class="flex cursor-pointer select-none items-center">
                    <input type="radio" name="nj632-mode" value="json2csv"<?php echo $mode === 'json2csv' ? ' checked' : ''; ?> class="peer sr-only">
                    <span class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 transition hover:border-gray-400 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 dark:border-gray-600 dark:text-gray-300 dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-900/30 dark:peer-checked:text-blue-300">JSON → CSV</span>
                </label>
            </fieldset>

            <div id="nj632-delimwrap">
                <label for="nj632-delim" class="n2j-label">Разделитель</label>
                <select id="nj632-delim" class="n2j-input n2j-select mt-1 w-auto">
                    <?php foreach ($delimiters as $code => $label): ?>
                        <option value="<?php echo HTML::chars($code); ?>"<?php echo $delimiter === $code ? ' selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <label for="nj632-in" id="nj632-inlabel" class="n2j-label mt-4">CSV</label>
        <textarea id="nj632-in" rows="8" placeholder="name,age&#10;Иван,30&#10;Мария,25" class="n2j-textarea mt-1 w-full min-h-40 font-mono"></textarea>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="button" id="nj632-clear" class="n2j-btn-secondary n2j-btn-sm">Очистить</button>
            <button type="button" id="nj632-go" class="n2j-btn-process ml-auto">
                <i class="fas fa-arrow-right-arrow-left"></i> Конвертировать
            </button>
        </div>

        <div class="mt-3 flex flex-wrap gap-1.5">
            <button type="button" class="n2j-chip nj632-example" data-mode="csv2json" data-value="name,age,city&#10;Иван,30,Москва&#10;&quot;Мария, Иванова&quot;,25,Казань">CSV с кавычками</button>
            <button type="button" class="n2j-chip nj632-example" data-mode="json2csv" data-value='[{&quot;name&quot;:&quot;Иван&quot;,&quot;age&quot;:30},{&quot;name&quot;:&quot;Мария&quot;,&quot;age&quot;:25}]'>JSON-массив</button>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-file-export mr-1.5 text-gray-400"></i>Результат</p>
            <button type="button" id="nj632-copy" class="n2j-btn-secondary n2j-btn-sm" disabled>
                <i class="fas fa-copy mr-1"></i>Копировать
            </button>
        </div>
        <pre id="nj632-out" class="mt-3 overflow-x-auto whitespace-nowrap whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-800 dark:bg-gray-950 dark:text-gray-100">Вставьте данные и нажмите «Конвертировать».</pre>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>Первая строка CSV — заголовки, из них строятся ключи JSON.</li>
            <li>Запятые внутри ячеек «в кавычках» разбираются корректно.</li>
            <li>Разделитель «;» — для таблиц из российских 1С и Excel.</li>
            <li>JSON принимается как массив объектов или массивов.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что делать с русскими разделителями?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Российский Excel и 1С часто выгружают CSV через точку с запятой —
                    выберите её в «Разделитель», и всё разберётся.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Поддерживаются ли кавычки?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да, по RFC 4180: «поле, с запятой» внутри кавычек — это одна ячейка,
                    а двойная кавычка "" означает саму кавычку.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Есть ли JSON API?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: POST /nj632/api/convert с mode, data и delimiter — вернёт готовый результат.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('632/script'); ?>
