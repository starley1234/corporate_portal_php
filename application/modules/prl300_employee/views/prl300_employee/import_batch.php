<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Импорт', '/prl300/admin/import'], ['Пакет #'.(int) $batch['id']]],
        'title' => 'Пакет импорта #'.(int) $batch['id'],
        'pill' => [$batch['status'], 'fa-box-archive'],
        'actions' => [['label' => 'Все пакеты', 'href' => '/prl300/admin/import', 'icon' => 'fa-arrow-left', 'role' => 'secondary']],
        'lead' => 'Файл '.$batch['file_name'].' · источник '.$batch['source_system'].' · загружен '.date('d.m.Y H:i', strtotime($batch['created_at'])).'.',
    ]); ?>
    <?php echo View::factory('prl300_employee/_flash', ['flash' => $flash]); ?>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <?php foreach ([
            ['Всего строк', $batch['total_rows']],
            ['Валидных', $batch['valid_rows']],
            ['Ошибок', $batch['error_rows']],
            ['Пропущено устаревших', $batch['skipped_count']],
            ['Создано / обновлено', (int) $batch['created_count'].' / '.(int) $batch['updated_count']],
        ] as $card): ?>
        <section class="prl-card"><p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400"><?php echo HTML::chars($card[0]); ?></p><p class="mt-2 text-3xl font-black tracking-tight text-gray-900 dark:text-white"><?php echo HTML::chars((string) $card[1]); ?></p></section>
        <?php endforeach; ?>
    </div>

    <section class="prl-card mt-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="prl-h3">Применение</h2>
                <p class="prl-hint mt-1">Организации: <?php echo HTML::chars(implode(', ', $batch['organization_codes']) ?: 'не указаны'); ?>. SHA-256: <span class="break-all font-mono"><?php echo HTML::chars($batch['file_sha256']); ?></span></p>
                <?php if ($batch['applied_at']): ?><p class="prl-hint">Применён: <?php echo HTML::chars(date('d.m.Y H:i', strtotime($batch['applied_at']))); ?></p><?php endif; ?>
            </div>
            <?php if ($can_import AND $batch['status'] === 'validated'): ?>
            <form method="post" action="/prl300/admin/import_apply/<?php echo (int) $batch['id']; ?>">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <button class="prl-btn-cta" type="submit" data-prl-arm="Применить пакет?"><span data-prl-arm-label><i class="fas fa-check"></i>Применить пакет</span></button>
            </form>
            <?php elseif ($batch['status'] === 'rejected'): ?>
            <span class="prl-badge prl-badge-err"><span class="prl-dot"></span>Исправьте CSV и загрузите новый файл</span>
            <?php elseif ($batch['status'] === 'applied'): ?>
            <span class="prl-badge prl-badge-ok"><span class="prl-dot"></span>Повторное применение не требуется</span>
            <?php endif; ?>
        </div>
        <?php if ( ! empty($batch['summary']['applied'])): ?>
        <div class="mt-4 grid gap-3 border-t border-gray-100 pt-4 text-sm dark:border-gray-700 sm:grid-cols-3">
            <?php foreach ((array) $batch['summary']['applied'] as $key => $value): if ($key === 'applied_at') continue; ?><div class="flex justify-between gap-3"><span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($key); ?></span><b class="text-gray-900 dark:text-white"><?php echo HTML::chars((string) $value); ?></b></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="prl-card mt-6 overflow-x-auto p-0">
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
            <h2 class="prl-h3">Строки пакета</h2>
            <p class="prl-hint mt-1">Показаны первые 200 строк. Staging-данные удаляются плановой чисткой после срока хранения.</p>
        </div>
        <table class="prl-table min-w-[900px]">
            <thead><tr><th>Строка</th><th>Сотрудник</th><th>Организация / подразделение</th><th>Статус</th><th>Результат</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($batch['rows'], 0, 200) as $row): $payload = $row['payload']; ?>
                <tr>
                    <td class="font-mono text-xs"><?php echo (int) $row['row_number']; ?></td>
                    <td><span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars(trim(($payload['last_name'] ?? '').' '.($payload['first_name'] ?? '').' '.($payload['middle_name'] ?? ''))); ?></span><span class="block font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($payload['employee_external_id'] ?? ''); ?></span></td>
                    <td><span class="block font-mono text-xs"><?php echo HTML::chars($payload['organization_code'] ?? ''); ?></span><span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($payload['department_name'] ?? ($payload['department_code'] ?? '')); ?></span></td>
                    <td><?php if ($row['status'] === 'applied' OR $row['status'] === 'valid'): ?><span class="prl-badge prl-badge-ok"><span class="prl-dot"></span><?php echo HTML::chars($row['status']); ?></span><?php elseif ($row['status'] === 'error'): ?><span class="prl-badge prl-badge-err"><span class="prl-dot"></span>error</span><?php else: ?><span class="prl-badge prl-badge-wait"><span class="prl-dot"></span><?php echo HTML::chars($row['status']); ?></span><?php endif; ?></td>
                    <td><?php if ($row['error']): ?><span class="text-rose-700 dark:text-rose-300"><?php echo HTML::chars($row['error']); ?></span><?php elseif ($row['result_employee_id']): ?><a class="prl-link" href="/prl300/admin/employee/<?php echo (int) $row['result_employee_id']; ?>">сотрудник #<?php echo (int) $row['result_employee_id']; ?></a><?php else: ?>—<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
