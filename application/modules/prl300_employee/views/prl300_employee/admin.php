<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Администрирование', '/admin'], ['Кадровые данные']],
        'title' => 'Структура холдинга',
        'pill' => [$dashboard['scope']['global'] ? 'Все организации' : 'Своя организация', 'fa-sitemap'],
        'actions' => [
            ['label' => 'Сотрудники', 'href' => '/prl300/admin/employees', 'icon' => 'fa-users', 'role' => 'secondary'],
            ['label' => 'Импорт HR-данных', 'href' => '/prl300/admin/import', 'icon' => 'fa-file-import', 'role' => 'secondary'],
        ],
        'lead' => 'Организации, площадки и иерархия подразделений. HR-пользователь видит и изменяет только организацию своего сопоставленного сотрудника; администратор портала — все.',
    ]); ?>
    <?php echo View::factory('prl300_employee/_flash', ['flash' => $flash]); ?>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?php foreach ([
            ['Организаций', $dashboard['organizations'], 'fa-building'],
            ['Площадок', $dashboard['sites'], 'fa-location-dot'],
            ['Подразделений', $dashboard['departments'], 'fa-sitemap'],
            ['Активных сотрудников', $dashboard['employees_active'], 'fa-users'],
            ['Без аккаунта', $dashboard['employees_unlinked'], 'fa-link-slash'],
            ['Без внешнего ID', $dashboard['employees_without_external_id'], 'fa-barcode'],
            ['Всего сотрудников', $dashboard['employees'], 'fa-address-book'],
            ['Уволенных', $dashboard['employees_terminated'], 'fa-user-xmark'],
        ] as $card): ?>
        <section class="prl-card">
            <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400"><i class="fas <?php echo HTML::chars($card[2]); ?> text-blue-600 dark:text-blue-400"></i><?php echo HTML::chars($card[0]); ?></p>
            <p class="mt-2 text-3xl font-black tracking-tight text-gray-900 dark:text-white"><?php echo number_format((int) $card[1], 0, ',', ' '); ?></p>
        </section>
        <?php endforeach; ?>
    </div>

    <?php if ($can_manage): ?>
    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <form method="post" action="/prl300/admin/organization" class="prl-card">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo $selected_organization ? (int) $selected_organization['id'] : ''; ?>">
            <h2 class="prl-h3 border-b border-gray-100 pb-3 dark:border-gray-700"><?php echo $selected_organization ? 'Изменить организацию' : 'Новая организация'; ?></h2>
            <?php if ( ! $is_portal_admin AND ! $selected_organization): ?><p class="prl-hint mt-3">Новую организацию создаёт администратор портала.</p><?php endif; ?>
            <div class="mt-4 space-y-3">
                <div><label class="prl-label" for="org-code">Код</label><input id="org-code" name="code" required value="<?php echo HTML::chars($selected_organization['code'] ?? ''); ?>" class="prl-input mt-1 w-full" placeholder="holding"></div>
                <div><label class="prl-label" for="org-name">Название</label><input id="org-name" name="name" required value="<?php echo HTML::chars($selected_organization['name'] ?? ''); ?>" class="prl-input mt-1 w-full"></div>
                <div><label class="prl-label" for="org-legal">Юридическое наименование</label><input id="org-legal" name="legal_name" value="<?php echo HTML::chars($selected_organization['legal_name'] ?? ''); ?>" class="prl-input mt-1 w-full"></div>
                <div><label class="prl-label" for="org-status">Статус</label><select id="org-status" name="status" class="prl-select mt-1 w-full"><option value="active" <?php echo ($selected_organization['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Активна</option><option value="archived" <?php echo ($selected_organization['status'] ?? '') === 'archived' ? 'selected' : ''; ?>>Архив</option></select></div>
            </div>
            <button class="prl-btn-cta mt-4 w-full" type="submit"><i class="fas fa-save"></i>Сохранить организацию</button>
            <?php if ($selected_organization): ?><a class="prl-btn-secondary mt-2 w-full" href="/prl300/admin">Сбросить выбор</a><?php endif; ?>
        </form>

        <form method="post" action="/prl300/admin/site" class="prl-card">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo $selected_site ? (int) $selected_site['id'] : ''; ?>">
            <h2 class="prl-h3 border-b border-gray-100 pb-3 dark:border-gray-700"><?php echo $selected_site ? 'Изменить площадку' : 'Новая площадка'; ?></h2>
            <div class="mt-4 space-y-3">
                <div><label class="prl-label" for="site-org">Организация</label><select id="site-org" name="organization_id" required class="prl-select mt-1 w-full"><?php foreach ($organizations as $org): ?><option value="<?php echo (int) $org['id']; ?>" <?php echo (int) ($selected_site['organization_id'] ?? 0) === (int) $org['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($org['name']); ?></option><?php endforeach; ?></select></div>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="prl-label" for="site-code">Код</label><input id="site-code" name="code" required value="<?php echo HTML::chars($selected_site['code'] ?? ''); ?>" class="prl-input mt-1 w-full"></div><div><label class="prl-label" for="site-city">Город</label><input id="site-city" name="city" value="<?php echo HTML::chars($selected_site['city'] ?? ''); ?>" class="prl-input mt-1 w-full"></div></div>
                <div><label class="prl-label" for="site-name">Название</label><input id="site-name" name="name" required value="<?php echo HTML::chars($selected_site['name'] ?? ''); ?>" class="prl-input mt-1 w-full"></div>
                <div><label class="prl-label" for="site-address">Адрес</label><input id="site-address" name="address" value="<?php echo HTML::chars($selected_site['address'] ?? ''); ?>" class="prl-input mt-1 w-full"></div>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="prl-label" for="site-timezone">Часовой пояс</label><input id="site-timezone" name="timezone" required value="<?php echo HTML::chars($selected_site['timezone'] ?? 'UTC'); ?>" class="prl-input mt-1 w-full"></div><div><label class="prl-label" for="site-status">Статус</label><select id="site-status" name="status" class="prl-select mt-1 w-full"><option value="active" <?php echo ($selected_site['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Активна</option><option value="archived" <?php echo ($selected_site['status'] ?? '') === 'archived' ? 'selected' : ''; ?>>Архив</option></select></div></div>
            </div>
            <button class="prl-btn-cta mt-4 w-full" type="submit"><i class="fas fa-save"></i>Сохранить площадку</button>
            <?php if ($selected_site): ?><a class="prl-btn-secondary mt-2 w-full" href="/prl300/admin">Сбросить выбор</a><?php endif; ?>
        </form>

        <form method="post" action="/prl300/admin/department" class="prl-card">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo $selected_department ? (int) $selected_department['id'] : ''; ?>">
            <h2 class="prl-h3 border-b border-gray-100 pb-3 dark:border-gray-700"><?php echo $selected_department ? 'Изменить подразделение' : 'Новое подразделение'; ?></h2>
            <div class="mt-4 space-y-3">
                <div><label class="prl-label" for="dept-org">Организация</label><select id="dept-org" name="organization_id" required class="prl-select mt-1 w-full"><?php foreach ($organizations as $org): ?><option value="<?php echo (int) $org['id']; ?>" <?php echo (int) ($selected_department['organization_id'] ?? 0) === (int) $org['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($org['name']); ?></option><?php endforeach; ?></select></div>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="prl-label" for="dept-code">Код</label><input id="dept-code" name="code" required value="<?php echo HTML::chars($selected_department['code'] ?? ''); ?>" class="prl-input mt-1 w-full"></div><div><label class="prl-label" for="dept-sort">Порядок</label><input id="dept-sort" name="sort_order" type="number" min="0" max="10000" value="<?php echo HTML::chars($selected_department['sort_order'] ?? '100'); ?>" class="prl-input mt-1 w-full"></div></div>
                <div><label class="prl-label" for="dept-name">Название</label><input id="dept-name" name="name" required value="<?php echo HTML::chars($selected_department['name'] ?? ''); ?>" class="prl-input mt-1 w-full"></div>
                <div><label class="prl-label" for="dept-parent">Родитель</label><select id="dept-parent" name="parent_id" class="prl-select prl300-org-dependent mt-1 w-full"><option value="">Корневое подразделение</option><?php foreach ($departments as $dept): if ($selected_department AND (int) $dept['id'] === (int) $selected_department['id']) continue; ?><option data-organization-id="<?php echo (int) $dept['organization_id']; ?>" value="<?php echo (int) $dept['id']; ?>" <?php echo (int) ($selected_department['parent_id'] ?? 0) === (int) $dept['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($dept['name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="prl-label" for="dept-site">Площадка</label><select id="dept-site" name="site_id" class="prl-select prl300-org-dependent mt-1 w-full"><option value="">Не привязано</option><?php foreach ($sites as $site): ?><option data-organization-id="<?php echo (int) $site['organization_id']; ?>" value="<?php echo (int) $site['id']; ?>" <?php echo (int) ($selected_department['site_id'] ?? 0) === (int) $site['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($site['name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="prl-label" for="dept-head">Руководитель подразделения</label><select id="dept-head" name="manager_employee_id" class="prl-select prl300-org-dependent mt-1 w-full"><option value="">Не назначен</option><?php foreach ($employees as $employee): ?><option data-organization-id="<?php echo (int) $employee['organization_id']; ?>" value="<?php echo (int) $employee['id']; ?>" <?php echo (int) ($selected_department['manager_employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($employee['full_name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="prl-label" for="dept-status">Статус</label><select id="dept-status" name="status" class="prl-select mt-1 w-full"><option value="active" <?php echo ($selected_department['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Активно</option><option value="archived" <?php echo ($selected_department['status'] ?? '') === 'archived' ? 'selected' : ''; ?>>Архив</option></select></div>
            </div>
            <button class="prl-btn-cta mt-4 w-full" type="submit"><i class="fas fa-save"></i>Сохранить подразделение</button>
            <?php if ($selected_department): ?><a class="prl-btn-secondary mt-2 w-full" href="/prl300/admin">Сбросить выбор</a><?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <div class="mt-6 space-y-6">
        <section class="prl-card overflow-x-auto p-0">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"><h2 class="prl-h3">Организации</h2></div>
            <table class="prl-table min-w-[620px]"><thead><tr><th>Код</th><th>Название</th><th>Статус</th><th></th></tr></thead><tbody>
            <?php foreach ($organizations as $org): ?><tr><td class="font-mono text-xs"><?php echo HTML::chars($org['code']); ?></td><td><span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($org['name']); ?></span><span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($org['legal_name']); ?></span></td><td><?php echo ($org['status'] === 'active') ? 'Активна' : 'Архив'; ?></td><td class="text-right"><?php if ($can_manage): ?><a class="prl-btn-secondary prl-btn-sm" href="/prl300/admin?organization_id=<?php echo (int) $org['id']; ?>">Изменить</a><?php endif; ?></td></tr><?php endforeach; ?>
            <?php if (empty($organizations)): ?><tr><td colspan="4" class="text-center text-gray-500 dark:text-gray-400">Организаций пока нет.</td></tr><?php endif; ?>
            </tbody></table>
        </section>

        <section class="prl-card overflow-x-auto p-0">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"><h2 class="prl-h3">Площадки</h2></div>
            <table class="prl-table min-w-[760px]"><thead><tr><th>Площадка</th><th>Организация</th><th>Город / пояс</th><th>Сотрудников</th><th></th></tr></thead><tbody>
            <?php foreach ($sites as $site): ?><tr><td><span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($site['name']); ?></span><span class="block font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($site['code']); ?></span></td><td><?php echo HTML::chars($site['organization_name']); ?></td><td><?php echo HTML::chars(trim(($site['city'] ?: '—').' · '.$site['timezone'])); ?></td><td><?php echo (int) $site['employee_count']; ?></td><td class="text-right"><?php if ($can_manage): ?><a class="prl-btn-secondary prl-btn-sm" href="/prl300/admin?site_id=<?php echo (int) $site['id']; ?>">Изменить</a><?php endif; ?></td></tr><?php endforeach; ?>
            <?php if (empty($sites)): ?><tr><td colspan="5" class="text-center text-gray-500 dark:text-gray-400">Площадок пока нет.</td></tr><?php endif; ?>
            </tbody></table>
        </section>

        <section class="prl-card overflow-x-auto p-0">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"><h2 class="prl-h3">Подразделения</h2></div>
            <table class="prl-table min-w-[900px]"><thead><tr><th>Подразделение</th><th>Родитель</th><th>Площадка</th><th>Руководитель</th><th>Сотрудников</th><th></th></tr></thead><tbody>
            <?php foreach ($departments as $dept): $head = trim((string) ($dept['manager_last_name'] ?? '').' '.(string) ($dept['manager_first_name'] ?? '').' '.(string) ($dept['manager_middle_name'] ?? '')); ?><tr><td><span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($dept['name']); ?></span><span class="block font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($dept['code']); ?> · <?php echo HTML::chars($dept['organization_name']); ?></span></td><td><?php echo HTML::chars($dept['parent_name'] ?: '—'); ?></td><td><?php echo HTML::chars($dept['site_name'] ?: '—'); ?></td><td><?php echo HTML::chars($head ?: '—'); ?></td><td><?php echo (int) $dept['employee_count']; ?></td><td class="text-right"><?php if ($can_manage): ?><a class="prl-btn-secondary prl-btn-sm" href="/prl300/admin?department_id=<?php echo (int) $dept['id']; ?>">Изменить</a><?php endif; ?></td></tr><?php endforeach; ?>
            <?php if (empty($departments)): ?><tr><td colspan="6" class="text-center text-gray-500 dark:text-gray-400">Подразделений пока нет.</td></tr><?php endif; ?>
            </tbody></table>
        </section>

        <section class="prl-card">
            <h2 class="prl-h3 border-b border-gray-100 pb-3 dark:border-gray-700">Последние операции</h2>
            <?php if (empty($dashboard['recent_audit'])): ?><p class="prl-hint mt-3">Операций пока не было.</p><?php else: ?>
            <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($dashboard['recent_audit'] as $audit): ?><li class="flex flex-wrap justify-between gap-2 py-2 text-sm"><span class="font-mono text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($audit['action']); ?></span><span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $audit['created_at']))); ?> · пользователь #<?php echo HTML::chars($audit['actor_user_id'] ?: 'CLI'); ?></span></li><?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>

    <script>
    (function () {
        var org = document.getElementById('dept-org');
        if (!org) return;
        function sync() {
            document.querySelectorAll('.prl300-org-dependent').forEach(function (select) {
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
