<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кадровые данные', '/prx300/admin'], ['Сотрудники']],
        'title' => 'Сотрудники',
        'pill' => [number_format((int) $result['total'], 0, ',', ' ').' записей', 'fa-users'],
        'actions' => $can_manage ? [['label' => 'Новый сотрудник', 'href' => '/prx300/admin/employee/0', 'icon' => 'fa-plus', 'role' => 'cta']] : [],
        'lead' => 'Рабочие профили из HR-источника и ручных исправлений. Пароли, роли входа и сессии здесь не редактируются.',
    ]); ?>
    <?php echo View::factory('partials/flash', ['flash' => $flash]); ?>

    <?php echo View::factory('partials/filters', [
        'id' => 'employees',
        'action' => '/prx300/admin/employees',
        'fields' => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Поиск', 'placeholder' => 'ФИО, email, табельный номер', 'value' => $filters['q']],
            ['type' => 'select', 'name' => 'organization_id', 'label' => 'Организация', 'value' => (int) $filters['organization_id'],
             'options' => [0 => 'Доступные организации'] + array_column($options['organizations'], 'name', 'id')],
            ['type' => 'select', 'name' => 'department_id', 'label' => 'Подразделение', 'value' => (int) $filters['department_id'],
             'options' => [0 => 'Все подразделения'] + array_column($options['departments'], 'name', 'id')],
            ['type' => 'select', 'name' => 'site_id', 'label' => 'Площадка', 'value' => (int) $filters['site_id'],
             'options' => [0 => 'Все площадки'] + array_column($options['sites'], 'name', 'id')],
            ['type' => 'select', 'name' => 'status', 'label' => 'Статус', 'value' => (string) $filters['status'],
             'options' => ['' => 'Любой', 'active' => 'Работает', 'on_leave' => 'В отпуске / отсутствует', 'suspended' => 'Приостановлен', 'terminated' => 'Уволен']],
            ['type' => 'checkbox', 'name' => 'unlinked', 'label' => 'Без аккаунта', 'checked' => (bool) $filters['unlinked']],
            ['type' => 'checkbox', 'name' => 'without_external', 'label' => 'Без внешнего ID', 'checked' => (bool) $filters['without_external']],
        ],
        'reset' => '/prx300/admin/employees',
    ]); ?>

    <div class="prx-card mt-5 overflow-x-auto p-0">
        <table class="prx-table min-w-[980px]">
            <thead><tr><th>Сотрудник</th><th>Структура</th><th>Статус</th><th>Связи</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $employee): ?>
                <tr>
                    <td><span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($employee['full_name']); ?></span><span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($employee['position_title'] ?: 'Должность не указана'); ?></span><span class="block font-mono text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($employee['personnel_number'] ? 'таб. '.$employee['personnel_number'] : 'таб. —'); ?></span></td>
                    <td><span class="block"><?php echo HTML::chars($employee['organization_name']); ?></span><span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(trim(($employee['department_name'] ?: 'без подразделения').($employee['site_name'] ? ' · '.$employee['site_name'] : ''))); ?></span></td>
                    <td><?php if ($employee['employment_status'] === 'active'): ?><span class="prx-badge prx-badge-ok"><span class="prx-dot"></span><?php echo HTML::chars($employee['employment_status_label']); ?></span><?php elseif ($employee['employment_status'] === 'terminated'): ?><span class="prx-badge prx-badge-err"><span class="prx-dot"></span><?php echo HTML::chars($employee['employment_status_label']); ?></span><?php else: ?><span class="prx-badge prx-badge-wait"><span class="prx-dot"></span><?php echo HTML::chars($employee['employment_status_label']); ?></span><?php endif; ?></td>
                    <td><span class="block"><?php echo $employee['linked_user_id'] ? 'аккаунт #'.(int) $employee['linked_user_id'] : 'аккаунт не сопоставлен'; ?></span><span class="block text-xs text-gray-500 dark:text-gray-400">внешних ID: <?php echo (int) $employee['external_identity_count']; ?></span></td>
                    <td class="text-right"><a href="/prx300/admin/employee/<?php echo (int) $employee['id']; ?>" class="prx-btn-secondary prx-btn-sm">Карточка</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($result['rows'])): ?><tr><td colspan="5" class="text-center text-gray-500 dark:text-gray-400">Сотрудники не найдены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ((int) $result['pages'] > 1): ?>
    <nav class="mt-4 flex items-center justify-between" aria-label="Страницы сотрудников">
        <?php $base = $filters; unset($base['page']); ?>
        <?php if ((int) $result['page'] > 1): ?><a class="prx-btn-secondary" href="/prx300/admin/employees?<?php echo http_build_query($base + ['page' => (int) $result['page'] - 1]); ?>">← Назад</a><?php else: ?><span></span><?php endif; ?>
        <span class="text-sm text-gray-500 dark:text-gray-400">Страница <?php echo (int) $result['page']; ?> из <?php echo (int) $result['pages']; ?></span>
        <?php if ((int) $result['page'] < (int) $result['pages']): ?><a class="prx-btn-secondary" href="/prx300/admin/employees?<?php echo http_build_query($base + ['page' => (int) $result['page'] + 1]); ?>">Вперёд →</a><?php else: ?><span></span><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
