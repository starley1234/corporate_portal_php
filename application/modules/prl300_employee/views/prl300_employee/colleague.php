<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Справочник', '/employee/directory'], ['Карточка сотрудника']],
        'title' => $colleague['full_name'],
        'pill' => [$colleague['organization_name'], 'fa-building'],
        'actions' => [['label' => 'К справочнику', 'href' => '/employee/directory', 'icon' => 'fa-arrow-left', 'role' => 'secondary']],
        'lead' => $colleague['position_title'] ?: 'Должность не указана',
    ]); ?>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="prl-card lg:col-span-2">
            <h2 class="prl-h3 border-b border-gray-100 pb-3 dark:border-gray-700">Рабочий контекст</h2>
            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div><dt class="prl-label">Организация</dt><dd class="mt-1 font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($colleague['organization_name']); ?></dd></div>
                <div><dt class="prl-label">Подразделение</dt><dd class="mt-1 font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($colleague['department_name'] ?: 'Не назначено'); ?></dd></div>
                <div><dt class="prl-label">Площадка</dt><dd class="mt-1 font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($colleague['site_name'] ?: 'Не назначена'); ?></dd><?php if ($colleague['site_city']): ?><dd class="prl-hint"><?php echo HTML::chars($colleague['site_city']); ?></dd><?php endif; ?></div>
                <div>
                    <dt class="prl-label">Руководитель</dt>
                    <dd class="mt-1 font-semibold text-gray-900 dark:text-white"><?php if ($colleague['manager_employee_id']): ?><a class="prl-link" href="/employee/colleague/<?php echo (int) $colleague['manager_employee_id']; ?>"><?php echo HTML::chars($colleague['manager_name']); ?></a><?php else: ?>Не указан<?php endif; ?></dd>
                </div>
            </dl>
        </section>
        <aside class="prl-card">
            <h2 class="prl-h3 border-b border-gray-100 pb-3 dark:border-gray-700">Рабочие контакты</h2>
            <div class="mt-4 space-y-4 text-sm">
                <div><p class="prl-label">Email</p><p class="mt-1 break-all text-gray-700 dark:text-gray-200"><?php echo HTML::chars($colleague['work_email'] ?: 'Не указан'); ?></p></div>
                <div><p class="prl-label">Телефон</p><p class="mt-1 text-gray-700 dark:text-gray-200"><?php echo HTML::chars($colleague['work_phone'] ?: 'Не указан'); ?></p></div>
                <div><p class="prl-label">Статус</p><p class="mt-2"><span class="prl-badge <?php echo $colleague['employment_status'] === 'active' ? 'prl-badge-ok' : 'prl-badge-wait'; ?>"><span class="prl-dot"></span><?php echo HTML::chars($colleague['employment_status_label']); ?></span></p></div>
            </div>
        </aside>
    </div>
</div>
