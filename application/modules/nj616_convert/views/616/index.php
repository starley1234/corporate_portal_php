<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Кодировщик (nj616_convert) — страница. Паттерн: главный блок + колонка-компаньон.
 */
$mode_labels = []; foreach ($modes as $code => $m) { $mode_labels[$code] = $m[0]; }
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Операция</span>
        <fieldset class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <?php foreach ($modes as $code => $m): ?>
                <label class="flex cursor-pointer select-none items-center">
                    <input type="radio" name="nj616-mode" value="<?php echo HTML::chars($code); ?>"<?php echo $mode === $code ? ' checked' : ''; ?> class="peer sr-only">
                    <span class="flex w-full items-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 text-sm font-medium text-gray-600 transition hover:border-gray-400 hover:text-gray-800 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 dark:border-gray-600 dark:text-gray-300 dark:hover:border-gray-500 dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-900/30 dark:peer-checked:text-blue-300">
                        <i class="<?php echo HTML::chars($m[1]); ?> text-xs opacity-70"></i>
                        <span><?php echo HTML::chars($m[0]); ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <textarea id="nj616-input" spellcheck="false" autocomplete="off"
                  placeholder="Введите текст…"
                  class="n2j-textarea mt-4 min-h-[180px] w-full font-mono leading-relaxed"
        ><?php echo HTML::chars($text); ?></textarea>

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" id="nj616-swap" class="n2j-btn-secondary">
                <i class="fas fa-arrows-up-down"></i> Поменять местами
            </button>
            <button type="button" id="nj616-copy" class="n2j-btn-process">
                <i class="fas fa-copy"></i> Копировать результат
            </button>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-code mr-1.5 text-gray-400"></i>Результат</p>
        <div id="nj616-error" class="n2j-flash n2j-flash-err hidden mt-2 block"></div>
        <pre id="nj616-output" class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-50 p-4 font-mono text-sm text-gray-800 dark:bg-gray-950 dark:text-gray-100"><span class="text-gray-400">Результат появится здесь.</span></pre>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>• Base64 работает с UTF-8 — кириллица не ломается.</li>
            <li>• «Поменять местами» — вход и результат меняются местами; режим инвертируется автоматически там, где это возможно.</li>
            <li>• Raw URL-кодирование кодирует и служебные символы (RFC 3986).</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Base64 с русским текстом?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: кодирование идёт по байтам UTF-8, поэтому кириллица, эмодзи и любой текст
                    прогоняются корректно в обе стороны.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Данные уходят на сервер?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет, преобразование выполняется в браузере. Серверный API /nj616/api/convert —
                    для интеграций и ИИ-агентов, которым нужен прогон через сервер.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Чем URL-кодирование отличается от raw?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Обычное (application/x-www-form-urlencoded) кодирует пробел как «+», raw —
                    как «%20» и дополнительно кодирует служебные символы.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('616/script'); ?>
