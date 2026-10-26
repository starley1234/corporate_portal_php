<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конструктор А4 (nj638_pdfplan) — страница /pdf-plan.
 *
 * Слева (2/3) — поля документа. Справа (1/3) — компаньон.
 * Генерация идёт на сервере: /pdf-plan/api/build.
 */
$recent    = isset($recent) ? $recent : [];
$rate_text = isset($rate_text) ? $rate_text : '5 операций в минуту';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">Конструктор А4</h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-file-lines text-[11px]"></i>Документы
        </span>
    </div>
</div>
<p class="mt-1.5 text-xs sm:text-sm text-gray-600 dark:text-gray-300">Соберите аккуратный документ А4: шапка и дата, одна или две колонки, подвал с нумерацией страниц.</p>

<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

    <div class="n2j-card md:col-span-2">
        <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
            <h2 class="n2j-h3">Параметры документа</h2>
        </div>

        <div class="mt-4 space-y-4 sm:mt-5">
            <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                <div>
                    <label for="nj638-title" class="n2j-label">Заголовок</label>
                    <input id="nj638-title" type="text" placeholder="План публикаций на сентябрь" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
                <div>
                    <label for="nj638-date" class="n2j-label">Дата</label>
                    <input id="nj638-date" type="text" placeholder="23 сентября 2026" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
            </div>

            <div>
                <label for="nj638-subtitle" class="n2j-label">Подзаголовок</label>
                <input id="nj638-subtitle" type="text" placeholder="Отдел контента · внутренний документ" autocomplete="off"
                       class="n2j-input mt-1.5 w-full">
            </div>

            <div>
                <label for="nj638-text" class="n2j-label">Текст документа</label>
                <textarea id="nj638-text" rows="9" placeholder="Неделя 1: …&#10;&#10;Неделя 2: …"
                          autocomplete="off" spellcheck="false"
                          class="n2j-textarea mt-1.5 min-h-[44px] w-full leading-relaxed"></textarea>
            </div>

            <div class="grid gap-4 sm:grid-cols-3 sm:gap-5">
                <div>
                    <label for="nj638-columns" class="n2j-label">Колонки</label>
                    <select id="nj638-columns" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <option value="1" selected>Одна</option>
                        <option value="2">Две</option>
                    </select>
                </div>
                <div>
                    <label for="nj638-size" class="n2j-label">Кегль</label>
                    <select id="nj638-size" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <option value="10">10</option>
                        <option value="11" selected>11</option>
                        <option value="12">12</option>
                        <option value="14">14</option>
                    </select>
                </div>
                <div>
                    <label for="nj638-footer" class="n2j-label">Подвал (слева)</label>
                    <input id="nj638-footer" type="text" placeholder="N2J.ru — конструктор А4" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
            </div>

            <div class="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center">
                <button type="button" id="nj638-go"
                        class="n2j-btn-process w-full sm:w-auto">
                    <i class="fas fa-file-pdf"></i> Сгенерировать PDF
                </button>
                <p class="n2j-hint text-center sm:text-left">Лимит: <?php echo HTML::chars($rate_text); ?> с одного адреса.</p>
            </div>

            <div id="nj638-status" class="hidden items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <i class="fas fa-spinner n2j-spin text-base text-blue-500"></i>
                <span id="nj638-status-text">Собираю документ…</span>
                <span id="nj638-timer" class="font-mono text-xs text-gray-400"></span>
            </div>

            <div id="nj638-error" class="n2j-flash n2j-flash-err hidden block"></div>
            <div id="nj638-result" class="n2j-flash n2j-flash-ok hidden block"></div>

            <?php if ($recent): ?>
            <div class="border-t border-gray-100 pt-4 dark:border-gray-700/60">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Последние генерации</h3>
                <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($recent as $r): ?>
                    <li class="flex items-center justify-between gap-2">
                        <span class="truncate"><?php echo $r['title'] !== '' ? HTML::chars($r['title']) : 'без названия'; ?></span>
                        <span class="shrink-0 text-gray-400"><?php echo (int) $r['pages']; ?> стр. · <?php echo (int) $r['columns']; ?> кол.</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="hidden space-y-5 md:block">
        <div class="n2j-card">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Что получится</h3>
            <ul class="mt-3 space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Шапка: заголовок, подзаголовок, дата и разделитель.</span>
                </li>
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Тело переносится по словам, в две колонки при выборе.</span>
                </li>
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Подвал: «Страница X из Y» и ваш текст.</span>
                </li>
            </ul>
        </div>

        <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-5 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40">
            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                <i class="fas fa-cloud-off text-blue-600 dark:text-blue-400"></i>
                <h3 class="text-sm font-bold">Всё локально</h3>
            </div>
            <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                PDF собирается на сервере платформы из вашего текста, без внешних сервисов и загрузки в облако.
            </p>
        </div>

        <div class="n2j-card">
            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Ограничения</h3>
            <div class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                <p>До 120 000 символов текста.</p>
                <p>Кириллица встраивается корректно, текст копируется из PDF.</p>
            </div>
        </div>
    </div>
</div>
</div>
<?php echo View::factory('638/script'); ?>
