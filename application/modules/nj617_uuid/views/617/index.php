<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Формат</span>
        <fieldset class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
            <?php foreach ($kinds as $code => $k): ?>
                <label class="flex cursor-pointer select-none items-center">
                    <input type="radio" name="nj617-kind" value="<?php echo HTML::chars($code); ?>"<?php echo $kind === $code ? ' checked' : ''; ?> class="peer sr-only">
                    <span class="flex w-full items-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 text-sm font-medium text-gray-600 transition hover:border-gray-400 hover:text-gray-800 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 dark:border-gray-600 dark:text-gray-300 dark:hover:border-gray-500 dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-900/30 dark:peer-checked:text-blue-300">
                        <span><?php echo HTML::chars($k[0]); ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="mt-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="nj617-count" class="n2j-label">Количество</label>
                <input id="nj617-count" type="number" min="1" max="100" value="1"
                       class="n2j-input mt-1 w-24 font-mono">
            </div>
            <label class="flex items-center gap-2 pb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                <input type="checkbox" id="nj617-upper" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                Верхний регистр
            </label>
            <button type="button" id="nj617-gen" class="n2j-btn-process ml-auto">
                <i class="fas fa-rotate"></i> Сгенерировать
            </button>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-list-ul mr-1.5 text-gray-400"></i>Результат</p>
            <button type="button" id="nj617-copy" class="n2j-btn-secondary n2j-btn-sm" disabled>
                <i class="fas fa-copy mr-1"></i>Копировать все
            </button>
        </div>
        <ul id="nj617-list" class="mt-3 space-y-1.5 font-mono text-sm text-gray-800 dark:text-gray-100"></ul>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Когда что брать</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li><b>UUID v4</b> — классика: случайный, для ключей и сессий.</li>
            <li><b>UUID v7</b> — сортируется по времени: удобен для индексов БД.</li>
            <li><b>ULID</b> — короткий и читаемый, монотонный в пределах миллисекунды.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Может ли выпасть одинаковый UUID?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Вероятность коллизии у UUID v4 практически нулевая (122 случайных бита).
                    Идентификаторы генерируются криптографическим генератором случайных чисел.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Чем v7 лучше v4?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    v7 хранит метку времени в первых битах, поэтому соседние записи в базе
                    идут рядом и индексы работают быстрее.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Где взять пачку для кода?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    GET /nj617/api/generate?k=uuid4&n=20 — вернёт массив строк в JSON.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('617/script'); ?>
