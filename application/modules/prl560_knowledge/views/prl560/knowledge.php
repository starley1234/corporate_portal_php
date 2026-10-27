<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Данные и знания'], ['Техническая база знаний']],
        'title'  => 'Техническая база знаний · RAG',
        'pill'   => ['prl560 · кэш наработок', 'fa-database'],
        'lead'   => 'Кэш технических наработок платформы. Не является хранилищем кадровых, секретных или персональных документов.',
    ]); ?>

    <section class="prl-panel mt-6">
        <div class="prl-panel-head">
            <div>
                <h2 class="prl-h3"><i class="fas fa-magnifying-glass mr-2 text-blue-500"></i>Поиск по базе знаний</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Текстовый поиск и, при доступности семантического сервиса, поиск по смыслу.</p>
            </div>
        </div>
        <form method="get" class="flex flex-wrap items-end gap-3 p-5">
            <div class="min-w-64 flex-1">
                <label class="prl-label" for="prl560-q">Запрос</label>
                <div class="relative mt-1.5">
                    <i class="fas fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400"></i>
                    <input id="prl560-q" class="prl-input pl-10" name="q" value="<?= HTML::chars($q) ?>" placeholder="Поиск по базе знаний">
                </div>
            </div>
            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                <input class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700" type="checkbox" name="semantic" value="1" <?= $semantic ? 'checked' : '' ?>>
                По смыслу
            </label>
            <button class="prl-btn-process" type="submit"><i class="fas fa-magnifying-glass"></i> Найти</button>
        </form>
    </section>

    <section class="prl-panel mt-6">
        <div class="prl-panel-head">
            <div>
                <h2 class="prl-h3"><i class="fas fa-list-ul mr-2 text-blue-500"></i><?= ($q !== '') ? 'Результаты поиска' : 'Последние записи' ?></h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Техническое представление данных сервиса базы знаний.</p>
            </div>
        </div>
        <div class="p-5">
            <pre class="overflow-auto rounded-lg bg-gray-50 p-4 font-mono text-[13px] leading-relaxed text-gray-700 dark:bg-gray-900 dark:text-gray-200"><?= HTML::chars(json_encode($result ?? $recent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
    </section>
</div>
