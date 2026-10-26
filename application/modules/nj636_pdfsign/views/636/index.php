<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Подписи и реквизиты PDF (nj636_pdfsign) — страница /pdf-sign.
 *
 * Форма 2/3 (файл, строки реквизитов, позиция, режим) + компаньон 1/3.
 * Серверная работа: /pdf-sign/api/{stamp,count,strip}.
 */
$recent    = isset($recent) ? $recent : [];
$rate_text = isset($rate_text) ? $rate_text : '5 операций в минуту';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">Подписи и реквизиты PDF</h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-pen-nib text-[11px]"></i>Документы
        </span>
    </div>
</div>
<p class="mt-1.5 text-xs sm:text-sm text-gray-600 dark:text-gray-300">Добавьте должность, ФИО и дату поверх документа, посчитайте или очистите существующие визуальные подписи.</p>

<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

    <div class="n2j-card md:col-span-2">
        <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
            <h2 class="n2j-h3">Реквизиты для подписи</h2>
        </div>

        <div class="mt-4 space-y-4 sm:mt-5">
            <div>
                <label for="nj636-file" class="n2j-label">PDF-файл</label>
                <input id="nj636-file" type="file" accept=".pdf,application/pdf"
                       class="mt-1.5 block w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:file:bg-blue-950/60 dark:file:text-blue-300">
                <p class="n2j-hint">Файл обрабатывается на сервере и никому не передаётся.</p>
            </div>

            <div>
                <label for="nj636-lines" class="n2j-label">Реквизиты — по строке на поле</label>
                <textarea id="nj636-lines" rows="4" placeholder="Генеральный директор&#10;Иванов Иван Иванович&#10;23.09.2026"
                          autocomplete="off" spellcheck="false"
                          class="n2j-textarea mt-1.5 min-h-[44px] w-full leading-relaxed"></textarea>
                <p class="n2j-hint">Каждая строка — отдельная строка блока подписи.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                <div>
                    <label for="nj636-pos" class="n2j-label">Позиция блока</label>
                    <select id="nj636-pos" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <option value="bl" selected>Слева внизу</option>
                        <option value="br">Справа внизу</option>
                        <option value="tl">Слева вверху</option>
                        <option value="tr">Справа вверху</option>
                        <option value="center">По центру</option>
                    </select>
                </div>
                <div>
                    <label for="nj636-pages" class="n2j-label">Страница</label>
                    <select id="nj636-pages" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <option value="last" selected>Последняя</option>
                        <option value="first">Первая</option>
                        <option value="all">Все</option>
                    </select>
                </div>
            </div>

            <div class="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center">
                <button type="button" id="nj636-stamp"
                        class="n2j-btn-process w-full sm:w-auto">
                    <i class="fas fa-pen-nib"></i> Наложить реквизиты
                </button>
                <button type="button" id="nj636-count"
                        class="n2j-btn-secondary w-full sm:w-auto">
                    <i class="fas fa-magnifying-glass"></i> Посчитать подписи
                </button>
                <button type="button" id="nj636-strip"
                        class="n2j-btn-danger w-full sm:w-auto">
                    <i class="fas fa-eraser"></i> Убрать подписи
                </button>
            </div>
            <p class="n2j-hint">«Убрать подписи» вырезает только визуальные аннотации-подписи, не изменяя текст документа.</p>

            <!-- Нерасчленяемый статус -->
            <div id="nj636-status" class="hidden items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <i class="fas fa-spinner n2j-spin text-base text-blue-500"></i>
                <span id="nj636-status-text">Обрабатываю…</span>
                <span id="nj636-timer" class="font-mono text-xs text-gray-400"></span>
            </div>

            <div id="nj636-error" class="n2j-flash n2j-flash-err hidden block"></div>
            <div id="nj636-result" class="n2j-flash n2j-flash-ok hidden block"></div>

            <?php if ($recent): ?>
            <div class="border-t border-gray-100 pt-4 dark:border-gray-700/60">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Последние операции</h3>
                <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($recent as $r): ?>
                    <li class="flex items-center justify-between gap-2">
                        <span class="truncate"><?php echo HTML::chars($r['name']); ?> · <?php echo HTML::chars($r['kind']); ?></span>
                        <span class="shrink-0 text-gray-400"><?php echo $r['pages'] !== NULL ? (int) $r['pages'].' стр.' : '—'; ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="hidden space-y-5 md:block">
        <div class="n2j-card">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Как это работает</h3>
            <ul class="mt-3 space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Реквизиты накладываются поверх содержимого документа.</span>
                </li>
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>По умолчанию — на последней странице (как лист подписи).</span>
                </li>
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Скопированный из PDF текст остаётся читаемым.</span>
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
                    <span>Документ обрабатывается на сервере и не передаётся третьим лицам.</span>
                </li>
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Зашифрованные файлы сервис не открывает.</span>
                </li>
            </ul>
        </div>

        <div class="n2j-card">
            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Ограничения</h3>
            <div class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                <p>До <?php echo class_exists('Service_Nj636_Pdfsign') ? (int) Service_Nj636_Pdfsign::cfg('max_upload_mb', 15) : 15; ?> МБ на файл.</p>
                <p>Лимит: <?php echo HTML::chars($rate_text); ?> с одного адреса.</p>
            </div>
        </div>
    </div>
</div>
</div>
<?php echo View::factory('636/script'); ?>
