<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная']],
        'title'  => 'Корпоративный портал',
        'pill'   => ['Единое рабочее пространство', 'fa-building'],
        'lead'   => 'Сервисы сотрудников, знания и коммуникации холдинга на единой модульной платформе.',
    ]); ?>

    <section class="prx-panel mt-6">
        <div class="prx-panel-head">
            <h2 class="prx-h3"><i class="fas fa-compass mr-2 text-blue-500"></i>Быстрый переход</h2>
        </div>
        <div class="flex flex-wrap gap-2.5 p-5">
            <a class="prx-btn-secondary" href="/employee"><i class="fas fa-id-card"></i>Мой профиль</a>
            <a class="prx-btn-secondary" href="/employee/directory"><i class="fas fa-users"></i>Сотрудники</a>
            <a class="prx-btn-secondary" href="/account/security"><i class="fas fa-shield-halved"></i>Мой аккаунт</a>
            <a class="prx-btn-secondary" href="/prx597"><i class="fas fa-bell"></i>Уведомления</a>
            <a class="prx-btn-secondary" href="/services"><i class="fas fa-layer-group"></i>Доступные сервисы</a>
        </div>
    </section>

    <div class="mt-8">
        <h2 class="prx-h2">Направления развития</h2>
        <p class="mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400">Первый HR-MVP уже доступен: структура холдинга, профиль сотрудника и справочник коллег. Остальные сервисы подключаются по мере готовности.</p>
    </div>

    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($domains as $domain): ?>
        <section class="prx-card">
            <h3 class="prx-h3"><?php echo HTML::chars($domain['title']); ?></h3>
            <ul class="mt-3 space-y-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                <?php foreach ($domain['services'] as $service): ?>
                <li>
                    <?php if ($service['status'] === 'active' AND $service['module'] === 'prx300_employee'): ?>
                        <a class="prx-link font-semibold" href="/employee"><?php echo HTML::chars($service['title']); ?></a>
                        <span class="prx-badge prx-badge-ok ml-2"><span class="prx-dot"></span>доступно</span>
                    <?php else: ?>
                        <?php echo HTML::chars($service['title']); ?>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>
</div>
