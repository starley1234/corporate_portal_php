<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Сервисы', '/services'], ['Мой профиль сотрудника']],
        'title' => 'Мой профиль сотрудника',
        'pill' => ['HR', 'fa-id-card'],
        'actions' => [
            ['label' => 'Справочник коллег', 'href' => '/employee/directory', 'icon' => 'fa-users', 'role' => 'secondary'],
            ['label' => 'Безопасность аккаунта', 'href' => '/account/security', 'icon' => 'fa-shield-halved', 'role' => 'secondary'],
        ],
        'lead' => 'Рабочие данные из кадрового источника. Пароль, роли и сессии остаются настройками аккаунта, а не профиля сотрудника.',
    ]); ?>

    <?php if ($profile === NULL): ?>
    <div class="prx-card mt-6">
        <div class="prx-empty">
            <div class="prx-empty-icon"><i class="fas fa-link-slash" aria-hidden="true"></i></div>
            <p class="prx-empty-text mt-4">Аккаунт пока не сопоставлен с сотрудником.</p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Обратитесь к HR или администратору портала. До сопоставления справочник коллег и будущие HR-сервисы не смогут определить вашу организацию.</p>
            <?php if ($can_admin): ?>
            <a href="/prx300/admin/employees" class="prx-btn-secondary mt-5"><i class="fas fa-sitemap"></i>Открыть сопоставления</a>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <section class="prx-card md:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-100 pb-5 dark:border-gray-700">
                <div class="flex min-w-0 items-center gap-4">
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-blue-600 text-2xl font-bold text-white">
                        <?php echo HTML::chars(mb_strtoupper(mb_substr($profile['first_name'], 0, 1).mb_substr($profile['last_name'], 0, 1))); ?>
                    </div>
                    <div class="min-w-0">
                        <h2 class="prx-h2 truncate"><?php echo HTML::chars($profile['full_name']); ?></h2>
                        <p class="truncate text-gray-500 dark:text-gray-400"><?php echo HTML::chars($profile['position_title'] ?: 'Должность не указана'); ?></p>
                    </div>
                </div>
                <?php if ($profile['employment_status'] === 'active'): ?>
                <span class="prx-badge prx-badge-ok"><span class="prx-dot"></span><?php echo HTML::chars($profile['employment_status_label']); ?></span>
                <?php elseif ($profile['employment_status'] === 'terminated'): ?>
                <span class="prx-badge prx-badge-err"><span class="prx-dot"></span><?php echo HTML::chars($profile['employment_status_label']); ?></span>
                <?php else: ?>
                <span class="prx-badge prx-badge-wait"><span class="prx-dot"></span><?php echo HTML::chars($profile['employment_status_label']); ?></span>
                <?php endif; ?>
            </div>

            <dl class="mt-5 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <div>
                    <dt class="prx-label">Организация</dt>
                    <dd class="mt-1 font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($profile['organization_name']); ?></dd>
                    <dd class="prx-hint">код: <?php echo HTML::chars($profile['organization_code']); ?></dd>
                </div>
                <div>
                    <dt class="prx-label">Подразделение</dt>
                    <dd class="mt-1 font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($profile['department_name'] ?: 'Не назначено'); ?></dd>
                    <?php if ($profile['department_code']): ?><dd class="prx-hint">код: <?php echo HTML::chars($profile['department_code']); ?></dd><?php endif; ?>
                </div>
                <div>
                    <dt class="prx-label">Площадка</dt>
                    <dd class="mt-1 font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($profile['site_name'] ?: 'Не назначена'); ?></dd>
                    <?php if ($profile['site_city']): ?><dd class="prx-hint"><?php echo HTML::chars($profile['site_city']); ?></dd><?php endif; ?>
                </div>
                <div>
                    <dt class="prx-label">Руководитель</dt>
                    <dd class="mt-1 font-semibold text-gray-900 dark:text-white">
                        <?php if ($profile['manager_employee_id']): ?>
                        <a class="prx-link" href="/employee/colleague/<?php echo (int) $profile['manager_employee_id']; ?>"><?php echo HTML::chars($profile['manager_name']); ?></a>
                        <?php else: ?>Не указан<?php endif; ?>
                    </dd>
                    <?php if ($profile['manager_position_title']): ?><dd class="prx-hint"><?php echo HTML::chars($profile['manager_position_title']); ?></dd><?php endif; ?>
                </div>
                <div>
                    <dt class="prx-label">Рабочий email</dt>
                    <dd class="mt-1 break-all text-gray-700 dark:text-gray-200"><?php echo HTML::chars($profile['work_email'] ?: 'Не указан'); ?></dd>
                </div>
                <div>
                    <dt class="prx-label">Рабочий телефон</dt>
                    <dd class="mt-1 text-gray-700 dark:text-gray-200"><?php echo HTML::chars($profile['work_phone'] ?: 'Не указан'); ?></dd>
                </div>
            </dl>
        </section>

        <aside class="space-y-5">
            <section class="prx-card">
                <h3 class="prx-h3"><i class="fas fa-fingerprint mr-2 text-blue-600 dark:text-blue-400"></i>Кадровая запись</h3>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Табельный номер</dt><dd class="font-mono font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($profile['personnel_number'] ?: '—'); ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Приём на работу</dt><dd class="text-gray-900 dark:text-white"><?php echo HTML::chars($profile['hired_on'] ? date('d.m.Y', strtotime($profile['hired_on'])) : '—'); ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Источник</dt><dd class="font-mono text-gray-900 dark:text-white"><?php echo HTML::chars($profile['source_system']); ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Обновлено</dt><dd class="text-gray-900 dark:text-white"><?php echo HTML::chars($profile['source_updated_at'] ? date('d.m.Y H:i', strtotime($profile['source_updated_at'])) : '—'); ?></dd></div>
                </dl>
            </section>

            <section class="prx-card">
                <h3 class="prx-h3"><i class="fas fa-user-lock mr-2 text-blue-600 dark:text-blue-400"></i>Аккаунт портала</h3>
                <p class="mt-3 break-all text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($user->username); ?></p>
                <p class="break-all text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars($user->email); ?></p>
                <p class="prx-hint mt-3">Сопоставление аккаунта и сотрудника хранится отдельно и может быть отозвано HR без изменения Auth-профиля.</p>
                <a href="/account/security" class="prx-btn-secondary mt-4"><i class="fas fa-shield-halved"></i>Безопасность и сессии</a>
            </section>
        </aside>
    </div>
    <?php endif; ?>
</div>
