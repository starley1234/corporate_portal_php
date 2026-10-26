<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Водяные знаки на PDF (nj637_watermark) — страница /watermark.
 *
 * Слева (2/3) — форма: файл, текст знака, режим и опции.
 * Справа (1/3, hidden md:block) — компаньон: лимиты и советы.
 * Вся работа с PDF — на сервере (/watermark/api/apply).
 */
$recent    = isset($recent) ? $recent : [];
$rate_text = isset($rate_text) ? $rate_text : '5 операций в минуту';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">Водяные знаки на PDF</h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-droplet text-[11px]"></i>Документы
        </span>
    </div>
</div>
<p class="mt-1.5 text-xs sm:text-sm text-gray-600 dark:text-gray-300">Добавьте защитный текст на страницы PDF: полупрозрачный по диагонали или заметный отпечаток.</p>

<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

    <!-- Главный блок: форма (2/3) -->
    <div class="n2j-card md:col-span-2">
        <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
            <h2 class="n2j-h3">Наложить знак</h2>
        </div>

        <div class="mt-4 space-y-4 sm:mt-5">
            <div>
                <label for="nj637-file" class="n2j-label">PDF-файл</label>
                <input id="nj637-file" type="file" accept=".pdf,application/pdf"
                       class="mt-1.5 block w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:file:bg-blue-950/60 dark:file:text-blue-300">
                <p class="n2j-hint">Файл обрабатывается на сервере и никому не передаётся.</p>
            </div>

            <div>
                <label for="nj637-text" class="n2j-label">Текст знака</label>
                <input id="nj637-text" type="text" placeholder="КОПИЯ — внутренний документ" autocomplete="off"
                       class="n2j-input mt-1.5 w-full">
            </div>

            <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                <div>
                    <label for="nj637-mode" class="n2j-label">Режим</label>
                    <select id="nj637-mode" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <option value="diagonal" selected>По диагонали каждой страницы</option>
                        <option value="stamp">Заметный отпечаток (внизу справа)</option>
                    </select>
                </div>
                <div>
                    <label for="nj637-pages" class="n2j-label">Страницы</label>
                    <select id="nj637-pages" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <option value="all" selected>Все</option>
                        <option value="first">Только первая</option>
                        <option value="last">Только последняя</option>
                    </select>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                <div>
                    <label for="nj637-size" class="n2j-label">Размер (пт)</label>
                    <input id="nj637-size" type="number" min="6" max="144" step="1" value="56"
                           class="n2j-input mt-1.5 w-full">
                </div>
                <div>
                    <label for="nj637-opacity" class="n2j-label">Насыщенность, %</label>
                    <input id="nj637-opacity" type="number" min="2" max="98" step="1" value="12"
                           class="n2j-input mt-1.5 w-full">
                </div>
            </div>

            <div class="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center">
                <button type="button" id="nj637-go"
                        class="n2j-btn-process w-full sm:w-auto">
                    <i class="fas fa-droplet"></i> Наложить знак
                </button>
                <p class="n2j-hint text-center sm:text-left">Лимит: <?php echo HTML::chars($rate_text); ?> с одного адреса.</p>
            </div>

            <!-- Нерасчленяемый статус: спиннер + этап + таймер -->
            <div id="nj637-status" class="hidden items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <i class="fas fa-spinner n2j-spin text-base text-blue-500"></i>
                <span id="nj637-status-text">Обрабатываю…</span>
                <span id="nj637-timer" class="font-mono text-xs text-gray-400"></span>
            </div>

            <div id="nj637-error" class="n2j-flash n2j-flash-err hidden block"></div>

            <div id="nj637-done" class="n2j-flash n2j-flash-ok hidden block"></div>

            <?php if ($recent): ?>
            <div class="border-t border-gray-100 pt-4 dark:border-gray-700/60">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Последние наложения</h3>
                <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($recent as $r): ?>
                    <li class="flex items-center justify-between gap-2">
                        <span class="truncate"><?php echo HTML::chars($r['name']); ?> · <?php echo ($r['mode'] === 'stamp' ? 'отпечаток' : 'диагональ'); ?></span>
                        <span class="shrink-0 text-gray-400"><?php echo (int) $r['pages']; ?> стр.</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Правая колонка: блок-компаньон (1/3), на мобильных скрыт -->
    <div class="hidden space-y-5 md:block">
        <div class="n2j-card">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Как это работает</h3>
            <ul class="mt-3 space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Знак по диагонали — полупрозрачный, не мешает чтению.</span>
                </li>
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Отпечаток ложится поверх, как подпись от руки.</span>
                </li>
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Кириллица встраивается корректно, текст можно скопировать.</span>
                </li>
            </ul>
        </div>

        <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-5 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40">
            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                <i class="fas fa-shield-halved text-blue-600 dark:text-blue-400"></i>
                <h3 class="text-sm font-bold">Конфиденциальность</h3>
            </div>
            <ul class="mt-3 space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Файл не покидает сервер и не используется третьими лицами.</span>
                </li>
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Без установки программ и загрузки в облако.</span>
                </li>
            </ul>
        </div>

        <div class="n2j-card">
            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Ограничения</h3>
            <div class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                <p>До <?php echo class_exists('Service_Nj637_Watermark') ? (int) Service_Nj637_Watermark::cfg('max_upload_mb', 15) : 15; ?> МБ на файл.</p>
                <p>Зашифрованные паролем PDF не принимаются.</p>
            </div>
        </div>
    </div>
</div>
</div>
<?php echo View::factory('637/script'); ?>
