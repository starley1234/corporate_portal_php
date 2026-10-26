<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="space-y-6">
    <div class="prl-panel p-6">
        <p class="text-sm font-semibold text-blue-600 dark:text-blue-400">PRL · Единое рабочее пространство</p>
        <h1 class="prl-h1 mt-2">Корпоративный портал</h1>
        <p class="mt-3 text-gray-600 dark:text-gray-300">Сервисы сотрудников, знания и коммуникации холдинга на единой модульной платформе.</p>
        <div class="mt-4 flex flex-wrap gap-3">
            <a class="prl-btn-cta" href="/employee">Мой профиль</a>
            <a class="prl-btn-process" href="/employee/directory">Сотрудники</a>
            <a class="prl-btn-secondary" href="/account/security">Мой аккаунт</a>
            <a class="prl-btn-secondary" href="/prl597">Уведомления</a>
            <a class="prl-btn-secondary" href="/services">Доступные сервисы</a>
        </div>
    </div>
    <div>
        <h2 class="prl-h2">Направления развития</h2>
        <p class="mt-2 text-sm text-gray-500">Первый HR-MVP уже доступен: структура холдинга, профиль сотрудника и справочник коллег. Остальные сервисы подключаются по мере готовности.</p>
    </div>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($domains as $domain): ?>
        <section class="prl-panel p-5">
            <h2 class="prl-h2"><?= HTML::chars($domain['title']) ?></h2>
            <ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <?php foreach ($domain['services'] as $service): ?>
                <li>
                    <?php if ($service['status'] === 'active' AND $service['module'] === 'prl300_employee'): ?><a class="prl-link font-semibold" href="/employee"><?= HTML::chars($service['title']) ?></a><span class="prl-badge prl-badge-ok ml-2"><span class="prl-dot"></span>доступно</span><?php else: ?><?= HTML::chars($service['title']) ?><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>
</div>
