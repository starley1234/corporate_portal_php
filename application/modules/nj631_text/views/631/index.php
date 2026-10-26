<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Инструмент</span>
        <fieldset class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <?php foreach ($tools as $code => $meta): ?>
                <label class="flex cursor-pointer select-none items-center">
                    <input type="radio" name="nj631-tool" value="<?php echo HTML::chars($code); ?>"<?php echo $tool === $code ? ' checked' : ''; ?> class="peer sr-only">
                    <span class="flex w-full flex-col rounded-lg border border-gray-200 px-3 py-2 text-sm transition hover:border-gray-400 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 dark:border-gray-600 dark:hover:border-gray-500 dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-900/30 dark:peer-checked:text-blue-300">
                        <span class="font-medium"><?php echo HTML::chars($meta[0]); ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div id="nj631-params" class="mt-3 hidden grid gap-3 sm:grid-cols-2">
            <div>
                <label for="nj631-find" class="n2j-label">Найти</label>
                <input id="nj631-find" type="text" class="n2j-input mt-1 w-full">
            </div>
            <div>
                <label for="nj631-replace" class="n2j-label">Заменить на</label>
                <input id="nj631-replace" type="text" class="n2j-input mt-1 w-full">
            </div>
        </div>

        <label for="nj631-text" class="n2j-label mt-4">Текст</label>
        <textarea id="nj631-text" rows="8" placeholder="Вставьте текст…" class="n2j-textarea mt-1 w-full min-h-40"><?php echo HTML::chars($text); ?></textarea>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <div id="nj631-stats" class="text-xs text-gray-400 dark:text-gray-500"></div>
            <div class="flex gap-2">
                <button type="button" id="nj631-clear" class="n2j-btn-secondary n2j-btn-sm">
                    Очистить
                </button>
                <button type="button" id="nj631-copy" class="n2j-btn-secondary n2j-btn-sm" disabled>
                    <i class="fas fa-copy mr-1"></i>Копировать результат
                </button>
                <button type="button" id="nj631-go" class="n2j-btn-process">
                    <i class="fas fa-wand-magic-sparkles"></i> Обработать
                </button>
            </div>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-square-poll-vertical mr-1.5 text-gray-400"></i>Результат</p>
            <span id="nj631-toolname" class="rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-600 dark:bg-blue-900/30 dark:text-blue-300"></span>
        </div>
        <div id="nj631-out" class="mt-3"></div>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>Статистика считает символы по Unicode (кириллица и эмодзи — по-честному).</li>
            <li>«Убрать дубликаты» сохраняет порядок строк и первое вхождение.</li>
            <li>«Как В Предложении» — заглавная после точки/переноса строки.</li>
            <li>Результат не уходит никуда: всё считается в вашем браузере.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как считается время чтения?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    По средней скорости 200 слов в минуту. Слова считаются как группы
                    символов без пробелов и переносов.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Текст куда-то отправляется?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет: все операции выполняются локально у вас в браузере, текст не
                    покидает вкладку. Серверный API доступен отдельно для интеграций.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Есть ли JSON API?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: GET /nj631/api/tool?tool=stats&amp;text=Привет — вернёт статистику.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('631/script'); ?>
