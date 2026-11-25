<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Мой профиль', '/prx300_employee'], ['Справочник сотрудников']],
        'title' => 'Справочник сотрудников',
        'pill' => [number_format((int) $result['total'], 0, ',', ' ').' сотрудников', 'fa-users'],
        'lead' => 'Только рабочие сведения коллег, доступных в вашем организационном контексте. Табельные номера, даты найма и внешние идентификаторы здесь не показываются.',
    ]); ?>

    <form method="get" action="/employee/directory" class="prx-card mt-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="xl:col-span-2">
                <label for="directory-q" class="prx-label">Поиск</label>
                <input id="directory-q" name="q" type="search" value="<?php echo HTML::chars($filters['q']); ?>" placeholder="ФИО, должность, email, подразделение" class="prx-input mt-1 w-full">
            </div>
            <?php if (count($options['organizations']) > 1): ?>
            <div>
                <label for="directory-org" class="prx-label">Организация</label>
                <select id="directory-org" name="organization_id" class="prx-select mt-1 w-full">
                    <option value="0">Все организации</option>
                    <?php foreach ($options['organizations'] as $org): ?>
                    <option value="<?php echo (int) $org['id']; ?>" <?php echo (int) $filters['organization_id'] === (int) $org['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($org['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label for="directory-dept" class="prx-label">Подразделение</label>
                <select id="directory-dept" name="department_id" class="prx-select mt-1 w-full">
                    <option value="0">Все подразделения</option>
                    <?php foreach ($options['departments'] as $dept): ?>
                    <option value="<?php echo (int) $dept['id']; ?>" <?php echo (int) $filters['department_id'] === (int) $dept['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="directory-site" class="prx-label">Площадка</label>
                <select id="directory-site" name="site_id" class="prx-select mt-1 w-full">
                    <option value="0">Все площадки</option>
                    <?php foreach ($options['sites'] as $site): ?>
                    <option value="<?php echo (int) $site['id']; ?>" <?php echo (int) $filters['site_id'] === (int) $site['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($site['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="prx-btn-process"><i class="fas fa-magnifying-glass"></i>Найти</button>
                <a href="/employee/directory" class="prx-btn-secondary">Сбросить</a>
            </div>
        </div>
    </form>

    <div class="prx-card mt-5 overflow-x-auto p-0">
        <table class="prx-table min-w-[760px]">
            <thead><tr><th>Сотрудник</th><th>Подразделение</th><th>Площадка</th><th>Рабочие контакты</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $employee): ?>
                <tr>
                    <td>
                        <span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($employee['full_name']); ?></span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($employee['position_title'] ?: 'Должность не указана'); ?></span>
                        <span class="block text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($employee['organization_name']); ?></span>
                    </td>
                    <td><?php echo HTML::chars($employee['department_name'] ?: '—'); ?></td>
                    <td><?php echo HTML::chars(trim($employee['site_name'].($employee['site_city'] ? ', '.$employee['site_city'] : '')) ?: '—'); ?></td>
                    <td>
                        <?php if ($employee['work_email']): ?><span class="block break-all"><?php echo HTML::chars($employee['work_email']); ?></span><?php endif; ?>
                        <?php if ($employee['work_phone']): ?><span class="block"><?php echo HTML::chars($employee['work_phone']); ?></span><?php endif; ?>
                        <?php if ( ! $employee['work_email'] AND ! $employee['work_phone']): ?>—<?php endif; ?>
                    </td>
                    <td class="text-right"><a href="/employee/colleague/<?php echo (int) $employee['id']; ?>" class="prx-btn-secondary prx-btn-sm">Открыть</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($result['rows'])): ?>
                <tr><td colspan="5"><div class="prx-empty"><div class="prx-empty-icon"><i class="fas fa-user-magnifying-glass"></i></div><p class="prx-empty-text mt-3">Сотрудники по этим условиям не найдены.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ((int) $result['pages'] > 1): ?>
    <nav class="mt-4 flex items-center justify-between" aria-label="Страницы справочника">
        <?php $base = $filters; unset($base['page']); ?>
        <?php if ((int) $result['page'] > 1): ?><a class="prx-btn-secondary" href="/employee/directory?<?php echo http_build_query($base + ['page' => (int) $result['page'] - 1]); ?>">← Назад</a><?php else: ?><span></span><?php endif; ?>
        <span class="text-sm text-gray-500 dark:text-gray-400">Страница <?php echo (int) $result['page']; ?> из <?php echo (int) $result['pages']; ?></span>
        <?php if ((int) $result['page'] < (int) $result['pages']): ?><a class="prx-btn-secondary" href="/employee/directory?<?php echo http_build_query($base + ['page' => (int) $result['page'] + 1]); ?>">Вперёд →</a><?php else: ?><span></span><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
