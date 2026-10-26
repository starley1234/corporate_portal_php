<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <label for="nj618-input" class="n2j-label">Текст</label>
        <textarea id="nj618-input" spellcheck="false" autocomplete="off"
                  placeholder="Введите строку для хеширования…"
                  class="n2j-textarea mt-2 min-h-[140px] w-full font-mono leading-relaxed"
        ><?php echo HTML::chars($text); ?></textarea>

        <span class="mt-4 block text-sm font-semibold text-gray-700 dark:text-gray-300">Алгоритм</span>
        <fieldset class="mt-2 flex flex-wrap gap-2">
            <?php foreach ($algos as $code => $label): ?>
                <label class="flex cursor-pointer select-none items-center">
                    <input type="radio" name="nj618-algo" value="<?php echo HTML::chars($code); ?>"<?php echo $algo === $code ? ' checked' : ''; ?> class="peer sr-only">
                    <span class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 transition hover:border-gray-400 hover:text-gray-800 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 dark:border-gray-600 dark:text-gray-300 dark:hover:border-gray-500 dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-900/30 dark:peer-checked:text-blue-300">
                        <?php echo HTML::chars($label); ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                <input type="checkbox" id="nj618-upper"<?php echo $upper ? ' checked' : ''; ?> class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                Верхний регистр
            </label>
            <button type="button" id="nj618-copy" class="n2j-btn-process ml-auto">
                <i class="fas fa-copy"></i> Копировать
            </button>
        </div>
    </div>

    <div id="nj618-results" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"></div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Назначение</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>• <b>MD5/SHA-1</b> — только для сверки несекретных данных, не для паролей.</li>
            <li>• <b>SHA-256</b> — контрольные суммы, целостность файлов, git.</li>
            <li>• <b>SHA-512</b> — где нужен запас прочности на годы вперёд.</li>
        </ul>
        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
            Для паролей используйте bcrypt/argon2, а не простые хеши.
        </p>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Рекомендуется ли MD5?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Для контроля целостности — ок, для криптографии и паролей — нет.
                    MD5 и SHA-1 коллизионно уязвимы; выбирайте SHA-256 и выше.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Данные отправляются на сервер?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет, в браузере всё считается локально (WebCrypto). Серверный API —
                    для цифровых агентов и интеграций, которым нужен расчёт на сервере.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('618/script'); ?>
