<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <label for="nj622-pattern" class="n2j-label">Регулярное выражение</label>
        <input id="nj622-pattern" type="text" autocomplete="off" spellcheck="false"
               value="<?php echo HTML::chars($pattern); ?>"
               placeholder="/\d{4}-\d{2}-\d{2}/"
               class="n2j-input mt-2 w-full font-mono">

        <label for="nj622-test" class="n2j-label mt-4">Тестируемый текст</label>
        <textarea id="nj622-test" spellcheck="false" autocomplete="off"
                  placeholder="Даты: 2024-01-31, а также 2025-06-15."
                  class="n2j-textarea mt-2 min-h-[120px] w-full font-mono leading-relaxed"
        ><?php echo HTML::chars($test); ?></textarea>

        <label for="nj622-replace" class="n2j-label mt-4">Замена (необязательно)</label>
        <input id="nj622-replace" type="text" autocomplete="off" spellcheck="false"
               placeholder="[ДАТА]"
               class="n2j-input mt-2 w-full font-mono">

        <span class="mt-4 block text-sm font-semibold text-gray-700 dark:text-gray-300">Флаги</span>
        <fieldset class="mt-2 flex flex-wrap gap-2">
            <?php $flag_defs = ['i' => 'без учёта регистра', 'm' => 'многострочный', 's' => 'точка = любой символ', 'u' => 'Unicode', 'x' => 'комментарии']; ?>
            <?php foreach ($flag_defs as $fc => $fl): ?>
                <label class="flex cursor-pointer select-none items-center">
                    <input type="checkbox" class="nj622-flag peer sr-only" value="<?php echo $fc; ?>"<?php echo strpos($flags, $fc) !== FALSE ? ' checked' : ''; ?>>
                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:border-blue-400 hover:text-blue-600 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-700 dark:border-gray-600 dark:text-gray-300 dark:hover:border-blue-500 dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-900/30 dark:peer-checked:text-blue-300">
                        <span class="font-mono"><?php echo $fc; ?></span> <?php echo HTML::chars($fl); ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>
    </div>

    <div id="nj622-out" class="mt-4 space-y-4"></div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Примеры</p>
        <div class="mt-3 space-y-2">
            <?php
            $examples = [
                ['label' => 'Даты', 'pattern' => '\\d{4}-\\d{2}-\\d{2}', 'test' => 'Старт 2024-01-31, окончание 2025-06-15.'],
                ['label' => 'E-mail', 'pattern' => '[a-z0-9._%+-]+@[a-z0-9.-]+\\.[a-z]{2,}', 'test' => 'Пишите на ivan@example.com или на support@n2j.ru', 'flags' => 'i'],
                ['label' => 'HEX-цвет', 'pattern' => '#[0-9a-fA-F]{6}\\b', 'test' => 'Бренд: #2563eb, акцент: #f59e0b.'],
                ['label' => 'Телефон', 'pattern' => '\\+7[\\s(]?\\d{3}[\\s)]?\\d{3}[-\\s]?\\d{2}[-\\s]?\\d{2}', 'test' => 'Звоните +7 495 123-45-67 или +7(812)999-00-11.'],
                ['label' => 'INN (ИНН)', 'pattern' => '\\b\\d{10,12}\\b', 'test' => 'ИНН 7707083893 и 500100732259 — проверим.'],
            ];
            ?>
            <?php foreach ($examples as $i => $ex): ?>
                <button type="button"
                        class="n2j-btn-secondary n2j-btn-sm nj622-example w-full text-left"
                        data-pattern="<?php echo HTML::chars($ex['pattern']); ?>"
                        data-test="<?php echo HTML::chars($ex['test']); ?>"
                        data-flags="<?php echo HTML::chars($ex['flags'] ?? ''); ?>">
                    <span class="text-blue-600 dark:text-blue-400"><?php echo HTML::chars($ex['label']); ?></span>
                    <code class="mt-0.5 block truncate font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($ex['pattern']); ?></code>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Почему серверный прогон?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Паттерн исполняется движком PCRE на сервере (офлайн, без интернета) —
                    так видно ровно то же поведение, что и в PHP-приложении. Токены в интернет не уходят.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Какие разделители поддерживаются?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    / # ~ % ! @. Первый символ — разделитель, остальное — флаги.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('622/script'); ?>
