<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Сотрудники', '/prx300/admin/employees'], [$employee['id'] ? 'Карточка сотрудника' : 'Новый сотрудник']],
        'title' => $employee['id'] ? $employee['full_name'] : 'Новый сотрудник',
        'pill' => [$employee['employment_status_label'], 'fa-id-card'],
        'actions' => [['label' => 'К списку', 'href' => '/prx300/admin/employees', 'icon' => 'fa-arrow-left', 'role' => 'secondary']],
        'lead' => 'Кадровый профиль отделён от Auth: сопоставление с аккаунтом и внешние идентификаторы управляются отдельными контрактами справа.',
    ]); ?>
    <?php echo View::factory('prx300_employee/_flash', ['flash' => $flash]); ?>

    <div class="mt-6 gap-6 space-y-6 xl:grid xl:grid-cols-3 xl:items-start xl:space-y-0">
        <form method="post" action="/prx300/admin/employee_save/<?php echo (int) $employee['id']; ?>" class="prx-card xl:col-span-2">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <fieldset <?php echo $can_manage ? '' : 'disabled'; ?>>
                <h2 class="prx-h3 border-b border-gray-100 pb-3 dark:border-gray-700">Рабочий профиль</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div><label class="prx-label" for="employee-org">Организация</label><select id="employee-org" name="organization_id" required class="prx-select mt-1 w-full"><?php foreach ($options['organizations'] as $org): ?><option value="<?php echo (int) $org['id']; ?>" <?php echo (int) $employee['organization_id'] === (int) $org['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($org['name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="prx-label" for="employee-personnel">Табельный номер</label><input id="employee-personnel" name="personnel_number" value="<?php echo HTML::chars($employee['personnel_number']); ?>" class="prx-input mt-1 w-full"></div>
                    <div><label class="prx-label" for="employee-last">Фамилия</label><input id="employee-last" name="last_name" required value="<?php echo HTML::chars($employee['last_name']); ?>" class="prx-input mt-1 w-full"></div>
                    <div><label class="prx-label" for="employee-first">Имя</label><input id="employee-first" name="first_name" required value="<?php echo HTML::chars($employee['first_name']); ?>" class="prx-input mt-1 w-full"></div>
                    <div><label class="prx-label" for="employee-middle">Отчество</label><input id="employee-middle" name="middle_name" value="<?php echo HTML::chars($employee['middle_name']); ?>" class="prx-input mt-1 w-full"></div>
                    <div><label class="prx-label" for="employee-position">Должность</label><input id="employee-position" name="position_title" value="<?php echo HTML::chars($employee['position_title']); ?>" class="prx-input mt-1 w-full"></div>
                    <div><label class="prx-label" for="employee-dept">Подразделение</label><select id="employee-dept" name="department_id" class="prx-select prx300-employee-dependent mt-1 w-full"><option value="">Не назначено</option><?php foreach ($options['departments'] as $dept): ?><option data-organization-id="<?php echo (int) $dept['organization_id']; ?>" value="<?php echo (int) $dept['id']; ?>" <?php echo (int) ($employee['department_id'] ?? 0) === (int) $dept['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($dept['name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="prx-label" for="employee-site">Площадка</label><select id="employee-site" name="site_id" class="prx-select prx300-employee-dependent mt-1 w-full"><option value="">Не назначена</option><?php foreach ($options['sites'] as $site): ?><option data-organization-id="<?php echo (int) $site['organization_id']; ?>" value="<?php echo (int) $site['id']; ?>" <?php echo (int) ($employee['site_id'] ?? 0) === (int) $site['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($site['name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="prx-label" for="employee-manager">Непосредственный руководитель</label><select id="employee-manager" name="manager_employee_id" class="prx-select prx300-employee-dependent mt-1 w-full"><option value="">Не назначен</option><?php foreach ($options['employees'] as $manager): if ((int) $manager['id'] === (int) $employee['id']) continue; ?><option data-organization-id="<?php echo (int) $manager['organization_id']; ?>" value="<?php echo (int) $manager['id']; ?>" <?php echo (int) ($employee['manager_employee_id'] ?? 0) === (int) $manager['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($manager['full_name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="prx-label" for="employee-status">Кадровый статус</label><select id="employee-status" name="employment_status" class="prx-select mt-1 w-full"><?php foreach (['active' => 'Работает', 'on_leave' => 'В отпуске / отсутствует', 'suspended' => 'Временно приостановлен', 'terminated' => 'Уволен'] as $status => $label): ?><option value="<?php echo $status; ?>" <?php echo $employee['employment_status'] === $status ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
                    <div><label class="prx-label" for="employee-email">Рабочий email</label><input id="employee-email" name="work_email" type="email" value="<?php echo HTML::chars($employee['work_email']); ?>" class="prx-input mt-1 w-full"></div>
                    <div><label class="prx-label" for="employee-phone">Рабочий телефон</label><input id="employee-phone" name="work_phone" value="<?php echo HTML::chars($employee['work_phone']); ?>" class="prx-input mt-1 w-full"></div>
                    <div><label class="prx-label" for="employee-hired">Дата приёма</label><input id="employee-hired" name="hired_on" type="date" value="<?php echo HTML::chars(substr((string) $employee['hired_on'], 0, 10)); ?>" class="prx-input mt-1 w-full"></div>
                    <div><label class="prx-label" for="employee-terminated">Дата увольнения</label><input id="employee-terminated" name="terminated_on" type="date" value="<?php echo HTML::chars(substr((string) $employee['terminated_on'], 0, 10)); ?>" class="prx-input mt-1 w-full"></div>
                </div>
                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button class="prx-btn-cta" type="submit"><i class="fas fa-save"></i>Сохранить профиль</button>
                    <span class="prx-hint">Источник: <?php echo HTML::chars($employee['source_system'] ?: 'manual'); ?>. Личные контакты и зарплатные данные здесь не хранятся.</span>
                </div>
            </fieldset>
        </form>

        <div class="space-y-6">
            <section class="prx-card">
                <h2 class="prx-h3 border-b border-gray-100 pb-3 dark:border-gray-700"><i class="fas fa-link mr-2 text-blue-600 dark:text-blue-400"></i>Аккаунт портала</h2>
                <?php if ( ! $employee['id']): ?>
                    <p class="prx-hint mt-3">Сначала сохраните сотрудника.</p>
                <?php elseif ($active_link): ?>
                    <p class="mt-4 font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($active_link['user']['username']); ?></p>
                    <p class="break-all text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars($active_link['user']['email']); ?></p>
                    <p class="prx-hint mt-2">Связь #<?php echo (int) $active_link['id']; ?> · <?php echo HTML::chars($active_link['linked_via']); ?> · <?php echo HTML::chars(date('d.m.Y H:i', strtotime($active_link['linked_at']))); ?></p>
                    <?php if ($can_identity): ?>
                    <form method="post" action="/prx300/admin/unlink_user/<?php echo (int) $employee['id']; ?>" class="mt-4 space-y-3">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <div><label class="prx-label" for="unlink-reason">Причина отзыва</label><input id="unlink-reason" name="reason" class="prx-input mt-1 w-full" placeholder="ошибка сопоставления, смена аккаунта…"></div>
                        <button type="submit" class="prx-btn-danger-soft" data-prx-arm="Отозвать связь?"><span data-prx-arm-label>Отозвать связь</span></button>
                    </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="prx-hint mt-3">Активной связи с пользователем Auth нет.</p>
                    <?php if ($can_identity): ?>
                    <form method="post" action="/prx300/admin/link_user/<?php echo (int) $employee['id']; ?>" class="mt-4 space-y-3">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <div><label class="prx-label" for="link-user">ID, username или email пользователя</label><input id="link-user" name="user_identifier" required class="prx-input mt-1 w-full" autocomplete="off"></div>
                        <div><label class="prx-label" for="link-via">Способ</label><input id="link-via" name="linked_via" value="manual" class="prx-input mt-1 w-full"></div>
                        <div><label class="prx-label" for="link-note">Комментарий</label><input id="link-note" name="note" class="prx-input mt-1 w-full"></div>
                        <button class="prx-btn-cta" type="submit"><i class="fas fa-link"></i>Сопоставить аккаунт</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($link_history): ?>
                <h3 class="mt-6 text-sm font-bold text-gray-900 dark:text-white">История связей</h3>
                <ul class="mt-2 space-y-2 text-xs text-gray-500 dark:text-gray-400">
                    <?php foreach ($link_history as $link): ?><li>#<?php echo (int) $link['id']; ?> · пользователь #<?php echo (int) $link['user_id']; ?> · <?php echo HTML::chars($link['status']); ?> · <?php echo HTML::chars(date('d.m.Y', strtotime($link['linked_at']))); ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>

            <section class="prx-card">
                <h2 class="prx-h3 border-b border-gray-100 pb-3 dark:border-gray-700"><i class="fas fa-barcode mr-2 text-blue-600 dark:text-blue-400"></i>Внешние идентификаторы</h2>
                <?php if ( ! $employee['id']): ?>
                    <p class="prx-hint mt-3">Станет доступно после создания сотрудника.</p>
                <?php else: ?>
                    <?php if ($identities): ?>
                    <ul class="mt-4 space-y-3">
                        <?php foreach ($identities as $identity): ?>
                        <li class="rounded-xl border border-gray-100 p-3 dark:border-gray-700">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0"><p class="font-mono text-xs font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($identity['system_code']); ?></p><p class="break-all font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($identity['external_id']); ?></p><?php if ($identity['external_login']): ?><p class="break-all text-xs text-gray-500 dark:text-gray-400">логин: <?php echo HTML::chars($identity['external_login']); ?></p><?php endif; ?></div>
                                <?php if ($identity['status'] === 'active'): ?><span class="prx-badge prx-badge-ok"><span class="prx-dot"></span>активен</span><?php else: ?><span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>отключён</span><?php endif; ?>
                            </div>
                            <?php if ($can_identity): ?>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a class="prx-btn-secondary prx-btn-sm" href="/prx300/admin/employee/<?php echo (int) $employee['id']; ?>?identity_id=<?php echo (int) $identity['id']; ?>">Изменить</a>
                                <?php if ($identity['status'] === 'active'): ?><form method="post" action="/prx300/admin/identity_disable/<?php echo (int) $identity['id']; ?>"><input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="employee_id" value="<?php echo (int) $employee['id']; ?>"><button class="prx-btn-danger-soft prx-btn-sm" type="submit" data-prx-arm="Отключить ID?"><span data-prx-arm-label>Отключить</span></button></form><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?><p class="prx-hint mt-3">Идентификаторов внешних систем пока нет.</p><?php endif; ?>

                    <?php if ($can_identity): ?>
                    <form method="post" action="/prx300/admin/identity_save/<?php echo (int) $employee['id']; ?>" class="mt-5 space-y-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="identity_id" value="<?php echo $selected_identity ? (int) $selected_identity['id'] : ''; ?>">
                        <h3 class="text-sm font-bold text-gray-900 dark:text-white"><?php echo $selected_identity ? 'Изменить идентификатор' : 'Добавить идентификатор'; ?></h3>
                        <div><label class="prx-label" for="identity-system">Код системы</label><input id="identity-system" name="system_code" required value="<?php echo HTML::chars($selected_identity['system_code'] ?? 'hris'); ?>" class="prx-input mt-1 w-full" placeholder="hris, ad, erp"></div>
                        <div><label class="prx-label" for="identity-external">Внешний ID</label><input id="identity-external" name="external_id" required value="<?php echo HTML::chars($selected_identity['external_id'] ?? ''); ?>" class="prx-input mt-1 w-full"></div>
                        <div><label class="prx-label" for="identity-login">Внешний логин</label><input id="identity-login" name="external_login" value="<?php echo HTML::chars($selected_identity['external_login'] ?? ''); ?>" class="prx-input mt-1 w-full"></div>
                        <div class="grid gap-3 sm:grid-cols-2"><div><label class="prx-label" for="identity-from">Действует с</label><input id="identity-from" name="valid_from" type="date" value="<?php echo HTML::chars(substr((string) ($selected_identity['valid_from'] ?? ''), 0, 10)); ?>" class="prx-input mt-1 w-full"></div><div><label class="prx-label" for="identity-to">по</label><input id="identity-to" name="valid_to" type="date" value="<?php echo HTML::chars(substr((string) ($selected_identity['valid_to'] ?? ''), 0, 10)); ?>" class="prx-input mt-1 w-full"></div></div>
                        <div><label class="prx-label" for="identity-status">Статус</label><select id="identity-status" name="status" class="prx-select mt-1 w-full"><option value="active" <?php echo ($selected_identity['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Активен</option><option value="disabled" <?php echo ($selected_identity['status'] ?? '') === 'disabled' ? 'selected' : ''; ?>>Отключён</option></select></div>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" name="is_primary" value="1" <?php echo ! empty($selected_identity['is_primary']) ? 'checked' : ''; ?> class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">Основной для этой системы</label>
                        <button class="prx-btn-cta" type="submit"><i class="fas fa-save"></i>Сохранить ID</button>
                        <?php if ($selected_identity): ?><a class="prx-btn-secondary" href="/prx300/admin/employee/<?php echo (int) $employee['id']; ?>">Сбросить</a><?php endif; ?>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <script>
    (function () {
        var org = document.getElementById('employee-org');
        if (!org) return;
        function sync() {
            document.querySelectorAll('.prx300-employee-dependent').forEach(function (select) {
                Array.prototype.forEach.call(select.options, function (option) {
                    var owner = option.getAttribute('data-organization-id');
                    option.hidden = !!(owner && owner !== org.value);
                });
                if (select.selectedOptions.length && select.selectedOptions[0].hidden) select.value = '';
            });
        }
        org.addEventListener('change', sync);
        sync();
    })();
    </script>
</div>
