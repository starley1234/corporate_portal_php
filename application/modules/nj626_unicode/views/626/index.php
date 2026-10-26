<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <label for="nj626-in" class="n2j-label">Текст или escape-коды</label>
        <textarea id="nj626-in" rows="3" placeholder="Привет 👋&#10;или \u041f\u0440\u0438\u0432\u0435\u0442" class="n2j-textarea mt-1 w-full min-h-24 font-mono"><?php echo HTML::chars($text); ?></textarea>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="button" id="nj626-go" class="n2j-btn-process">
                <i class="fas fa-magnifying-glass"></i> Разобрать
            </button>
            <button type="button" id="nj626-copy" class="n2j-btn-secondary n2j-btn-sm" disabled>
                <i class="fas fa-copy mr-1"></i>Копировать коды
            </button>
            <div class="flex flex-wrap gap-1.5">
                <?php foreach (['Привет', '👋', '\u041f\u0440\u0438\u0432\u0435\u0442', 'A̶b̶c̶'] as $s): ?>
                    <button type="button" class="n2j-chip nj626-example" data-value="<?php echo HTML::chars($s); ?>"><?php echo HTML::chars($s); ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <p id="nj626-decoded" class="mt-3 hidden rounded-lg bg-gray-50 p-3 font-mono text-sm text-gray-700 dark:bg-gray-950 dark:text-gray-200"></p>
    </div>

    <div class="n2j-card mt-4">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-table mr-1.5 text-gray-400"></i>Символы</p>
        <div id="nj626-table" class="mt-3">
            <?php if (empty($items)): ?>
                <p class="text-gray-400">Введите текст — увидите коды каждого символа.</p>
            <?php else: ?>
                <?php foreach ($items as $it): ?>
                <div class="flex items-center gap-3 border-b border-gray-100 py-2 font-mono text-xs dark:border-gray-700">
                    <span class="w-8 text-center text-xl text-gray-800 dark:text-gray-100"><?php echo HTML::chars($it['char']); ?></span>
                    <span class="font-bold text-blue-600 dark:text-blue-400"><?php echo HTML::chars($it['uplus']); ?></span>
                    <span class="text-gray-400">UTF-8</span><span class="text-gray-700 dark:text-gray-300"><?php echo HTML::chars($it['utf8']); ?></span>
                    <span class="text-gray-400">esc</span><span class="break-all text-gray-700 dark:text-gray-300"><?php echo HTML::chars($it['escape']); ?></span>
                    <span class="text-gray-400">JS</span><span class="break-all text-gray-700 dark:text-gray-300"><?php echo HTML::chars($it['u_braces']); ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li><span class="font-mono">U+041F</span> — код буквы «П»; коды старше <span class="font-mono">FFFF</span> — суррогатные пары.</li>
            <li>«Испорченный» текст <span class="font-mono">\u041f\u0440…</span> вставьте — раскодируется.</li>
            <li><span class="font-mono">U+200B</span> — невидимый символ, часто ломающий парсеры.</li>
            <li>Эмодзи 👋 — это <span class="font-mono">U+1F44B</span>, один символ, но 4 байта в UTF-8.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что такое кодовая точка?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Это номер символа в таблице Unicode: русской «П» — U+041F, латинской «P» — U+0050.
                    Кодировки (UTF-8, UTF-16) — разные способы записать этот номер байтами.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Почему эмодзи занимает больше одного символа?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    В UTF-16 эмодзи — пара 16-битных суррогатов, поэтому в старом JS
                    «👋».length равно 2. Вбайтах UTF-8 это 4 байта.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как вставить символ в код?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Современно — \u{1F44B} (JS/Python), в JSON — суррогатная пара
                    \uD83D\uDC4B, в HTML — &#x1F44B;. Все три показывает анализатор.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('626/script'); ?>
