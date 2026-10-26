<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кадровые данные', '/prl300/admin'], ['Сотрудники']],
        'title' => 'Сотрудники',
        'pill' => [number_format((int) $result['total'], 0, ',', ' ').' записей', 'fa-users'],
        'actions' => $can_manage ? [['label' => 'Новый сотрудник', 'href' => '/prl300/admin/employee/0', 'icon' => 'fa-plus', 'role' => 'cta']] : [],
        'lead' => 'Рабочие профили из HR-источника и ручных исправлений. Пароли, роли входа и сессии здесь не редактируются.',
    ]); ?>
    <?php echo View::factory('prl300_employee/_flash', ['flash' => $flash]); ?>

    <form method="get" action="/prl300/admin/employees" class="prl-card mt-5">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div><label class="prl-label" for="employees-q">Поиск</label><input id="employees-q" name="q" type="search" value="<?php echo HTML::chars($filters['q']); ?>" placeholder="ФИО, email, табельный номер" class="prl-input mt-1 w-full"></div>
            <div><label class="prl-label" for="employees-org">Организация</label><select id="employees-org" name="organization_id" class="prl-select mt-1 w-full"><option value="0">Доступные организации</option><?php foreach ($options['organizations'] as $org): ?><option value="<?php echo (int) $org['id']; ?>" <?php echo (int) $filters['organization_id'] === (int) $org['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($org['name']); ?></option><?php endforeach; ?></select></div>
            <div><label class="prl-label" for="employees-dept">Подразделение</label><select id="employees-dept" name="department_id" class="prl-select mt-1 w-full"><option value="0">Все подразделения</option><?php foreach ($options['departments'] as $dept): ?><option value="<?php echo (int) $dept['id']; ?>" <?php echo (int) $filters['department_id'] === (int) $dept['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($dept['name']); ?></option><?php endforeach; ?></select></div>
            <div><label class="prl-label" for="employees-site">Площадка</label><select id="employees-site" name="site_id" class="prl-select mt-1 w-full"><option value="0">Все площадки</option><?php foreach ($options['sites'] as $site): ?><option value="<?php echo (int) $site['id']; ?>" <?php echo (int) $filters['site_id'] === (int) $site['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($site['name']); ?></option><?php endforeach; ?></select></div>
            <div><label class="prl-label" for="employees-status">Статус</label><select id="employees-status" name="status" class="prl-select mt-1 w-full"><option value="">Любой</option><?php foreach (['active' => 'Работает', 'on_leave' => 'В отпуске / отсутствует', 'suspended' => 'Приостановлен', 'terminated' => 'Уволен'] as $status => $label): ?><option value="<?php echo $status; ?>" <?php echo $filters['status'] === $status ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
            <label class="flex min-h-[44px] items-center gap-2 self-end text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" name="unlinked" value="1" <?php echo $filters['unlinked'] ? 'checked' : ''; ?> class="h-4 w-4 accent-blue-600">Без аккаунта</label>
            <label class="flex min-h-[44px] items-center gap-2 self-end text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" name="without_external" value="1" <?php echo $filters['without_external'] ? 'checked' : ''; ?> class="h-4 w-4 accent-blue-600">Без внешнего ID</label>
            <div class="flex items-end gap-2"><button class="prl-btn-process" type="submit"><i class="fas fa-filter"></i>Применить</button><a class="prl-btn-secondary" href="/prl300/admin/employees">Сбросить</a></div>
        </div>
    </form>

    <div class="prl-card mt-5 overflow-x-auto p-0">
        <table class="prl-table min-w-[980px]">
            <thead><tr><th>Сотрудник</th><th>Структура</th><th>Статус</th><th>Связи</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $employee): ?>
                <tr>
                    <td><span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($employee['full_name']); ?></span><span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($employee['position_title'] ?: 'Должность не указана'); ?></span><span class="block font-mono text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($employee['personnel_number'] ? 'таб. '.$employee['personnel_number'] : 'таб. —'); ?></span></td>
                    <td><span class="block"><?php echo HTML::chars($employee['organization_name']); ?></span><span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(trim(($employee['department_name'] ?: 'без подразделения').($employee['site_name'] ? ' · '.$employee['site_name'] : ''))); ?></span></td>
                    <td><?php if ($employee['employment_status'] === 'active'): ?><span class="prl-badge prl-badge-ok"><span class="prl-dot"></span><?php echo HTML::chars($employee['employment_status_label']); ?></span><?php elseif ($employee['employment_status'] === 'terminated'): ?><span class="prl-badge prl-badge-err"><span class="prl-dot"></span><?php echo HTML::chars($employee['employment_status_label']); ?></span><?php else: ?><span class="prl-badge prl-badge-wait"><span class="prl-dot"></span><?php echo HTML::chars($employee['employment_status_label']); ?></span><?php endif; ?></td>
                    <td><span class="block"><?php echo $employee['linked_user_id'] ? 'аккаунт #'.(int) $employee['linked_user_id'] : 'аккаунт не сопоставлен'; ?></span><span class="block text-xs text-gray-500 dark:text-gray-400">внешних ID: <?php echo (int) $employee['external_identity_count']; ?></span></td>
                    <td class="text-right"><a href="/prl300/admin/employee/<?php echo (int) $employee['id']; ?>" class="prl-btn-secondary prl-btn-sm">Карточка</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($result['rows'])): ?><tr><td colspan="5" class="text-center text-gray-500 dark:text-gray-400">Сотрудники не найдены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ((int) $result['pages'] > 1): ?>
    <nav class="mt-4 flex items-center justify-between" aria-label="Страницы сотрудников">
        <?php $base = $filters; unset($base['page']); ?>
        <?php if ((int) $result['page'] > 1): ?><a class="prl-btn-secondary" href="/prl300/admin/employees?<?php echo http_build_query($base + ['page' => (int) $result['page'] - 1]); ?>">← Назад</a><?php else: ?><span></span><?php endif; ?>
        <span class="text-sm text-gray-500 dark:text-gray-400">Страница <?php echo (int) $result['page']; ?> из <?php echo (int) $result['pages']; ?></span>
        <?php if ((int) $result['page'] < (int) $result['pages']): ?><a class="prl-btn-secondary" href="/prl300/admin/employees?<?php echo http_build_query($base + ['page' => (int) $result['page'] + 1]); ?>">Вперёд →</a><?php else: ?><span></span><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
