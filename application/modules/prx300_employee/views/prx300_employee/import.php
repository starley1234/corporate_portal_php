<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кадровые данные', '/prx300/admin'], ['Импорт']],
        'title' => 'Импорт кадровых данных',
        'pill' => ['Delta · CSV', 'fa-file-import'],
        'actions' => [['label' => 'Скачать шаблон CSV', 'href' => '/prx300/admin/import_template', 'icon' => 'fa-download', 'role' => 'secondary']],
        'lead' => 'Сначала пакет только проверяется. Применение — отдельным действием и одной транзакцией: частично применённого импорта не будет.',
    ]); ?>
    <?php echo View::factory('prx300_employee/_flash', ['flash' => $flash]); ?>

    <div class="mt-6 gap-6 space-y-6 lg:grid lg:grid-cols-3 lg:items-start lg:space-y-0">
        <section class="prx-card">
            <h2 class="prx-h3 border-b border-gray-100 pb-3 dark:border-gray-700">Загрузить пакет</h2>
            <?php if ($can_import): ?>
            <form method="post" action="/prx300/admin/import_upload" enctype="multipart/form-data" class="mt-4 space-y-4">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <div>
                    <label class="prx-label" for="import-source">Код системы-источника</label>
                    <input id="import-source" name="source_system" required value="hris" class="prx-input mt-1 w-full" placeholder="hris">
                    <p class="prx-hint">Внешние ID уникальны внутри этой системы.</p>
                </div>
                <div>
                    <label class="prx-label" for="import-file">CSV-файл</label>
                    <input id="import-file" name="csv" type="file" required accept=".csv,.txt,text/csv,text/plain" class="prx-input mt-1 w-full">
                    <p class="prx-hint">UTF-8 или Windows-1251, разделитель «;» или «,», до 5 МБ и 5 000 строк.</p>
                </div>
                <button class="prx-btn-process w-full" type="submit">
                    <span class="inline-flex items-center gap-2"><i class="fas fa-clipboard-check"></i>Загрузить и проверить</span>
                </button>
            </form>
            <?php else: ?>
            <p class="prx-hint mt-3">У вашей роли есть просмотр пакетов; загрузка и применение доступны hr_admin.</p>
            <?php endif; ?>

            <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-700">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Правила пакета</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    <li>Отсутствие строки не увольняет и не удаляет сотрудника.</li>
                    <li>Более старая версия по <span class="font-mono">source_updated_at</span> пропускается.</li>
                    <li>Неизвестные столбцы отклоняются, чтобы лишние персональные данные не попадали в staging.</li>
                    <li>Связь с Auth автоматически не создаётся по email.</li>
                </ul>
            </div>
        </section>

        <section class="prx-card lg:col-span-2">
            <h2 class="prx-h3 border-b border-gray-100 pb-3 dark:border-gray-700">Столбцы CSV</h2>
            <p class="prx-hint mt-3">Обязательные заголовки: <span class="font-mono"><?php echo HTML::chars(implode(', ', $required_headers)); ?></span>.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php foreach ($headers as $header): ?><span class="prx-count"><?php echo HTML::chars($header); ?></span><?php endforeach; ?>
            </div>
            <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="font-semibold text-gray-900 dark:text-white">Статусы</dt><dd class="mt-1 font-mono text-xs text-gray-600 dark:text-gray-300">active · on_leave · suspended · terminated</dd></div>
                <div><dt class="font-semibold text-gray-900 dark:text-white">Даты</dt><dd class="mt-1 text-gray-600 dark:text-gray-300">ГГГГ-ММ-ДД; время источника — ISO 8601 с часовым поясом.</dd></div>
                <div><dt class="font-semibold text-gray-900 dark:text-white">Структура</dt><dd class="mt-1 text-gray-600 dark:text-gray-300">Коды организации, площадки и подразделений стабильны между выгрузками.</dd></div>
                <div><dt class="font-semibold text-gray-900 dark:text-white">Руководитель</dt><dd class="mt-1 text-gray-600 dark:text-gray-300">Указывается внешним ID сотрудника из файла или уже импортированного профиля.</dd></div>
            </dl>
        </section>
    </div>

    <section class="prx-card mt-6 overflow-x-auto p-0">
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"><h2 class="prx-h3">Последние пакеты</h2></div>
        <table class="prx-table min-w-[860px]">
            <thead><tr><th>Пакет</th><th>Источник / организации</th><th>Статус</th><th>Строки</th><th>Итог</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($batches as $batch): ?>
                <tr>
                    <td><span class="block font-semibold text-gray-900 dark:text-white">#<?php echo (int) $batch['id']; ?> · <?php echo HTML::chars($batch['file_name'] ?: 'без имени'); ?></span><span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($batch['created_at']))); ?></span></td>
                    <td><span class="block font-mono text-xs"><?php echo HTML::chars($batch['source_system']); ?></span><span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(implode(', ', $batch['organization_codes'])); ?></span></td>
                    <td><?php if ($batch['status'] === 'applied'): ?><span class="prx-badge prx-badge-ok"><span class="prx-dot"></span>применён</span><?php elseif ($batch['status'] === 'validated'): ?><span class="prx-badge prx-badge-info"><span class="prx-dot"></span>проверен</span><?php elseif (in_array($batch['status'], ['rejected', 'failed'], TRUE)): ?><span class="prx-badge prx-badge-err"><span class="prx-dot"></span><?php echo HTML::chars($batch['status']); ?></span><?php else: ?><span class="prx-badge prx-badge-wait"><span class="prx-dot"></span><?php echo HTML::chars($batch['status']); ?></span><?php endif; ?></td>
                    <td><?php echo (int) $batch['valid_rows']; ?> валидных · <?php echo (int) $batch['error_rows']; ?> ошибок · <?php echo (int) $batch['skipped_count']; ?> пропущено</td>
                    <td><?php echo (int) $batch['created_count']; ?> создано · <?php echo (int) $batch['updated_count']; ?> обновлено</td>
                    <td class="text-right"><a class="prx-btn-secondary prx-btn-sm" href="/prx300/admin/import/<?php echo (int) $batch['id']; ?>">Открыть</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($batches)): ?><tr><td colspan="6" class="text-center text-gray-500 dark:text-gray-400">Пакетов пока нет.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
