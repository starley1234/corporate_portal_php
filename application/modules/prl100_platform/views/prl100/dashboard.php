<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Платформа'], ['Мой аккаунт']],
        'title'  => 'Мой аккаунт',
        'pill'   => ['Учётная запись PRL', 'fa-id-card'],
        'lead'   => 'Данные входа, безопасность, уведомления и личные фоновые задачи.',
    ]); ?>

    <section class="prl-panel mt-6">
        <div class="prl-panel-head">
            <div>
                <h2 class="prl-h3"><i class="fas fa-id-card mr-2 text-blue-500"></i>Учётная запись</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Данные, с которыми вы входите в портал.</p>
            </div>
            <?php if ($is_admin): ?>
                <span class="prl-badge prl-badge-ok"><span class="prl-dot"></span>Администратор</span>
            <?php endif; ?>
        </div>
        <div class="p-6">
            <p class="text-lg font-semibold text-gray-900 dark:text-white"><?= HTML::chars($me->username) ?></p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= HTML::chars($me->email) ?></p>
            <p class="mt-4 text-sm leading-relaxed text-gray-600 dark:text-gray-300">Это кабинет доступа к платформе. Рабочий профиль сотрудника ведётся отдельно в HR-модуле и связан с учётной записью только через сопоставление.</p>
        </div>
    </section>

    <section class="prl-panel mt-6">
        <div class="prl-panel-head">
            <div>
                <h2 class="prl-h3"><i class="fas fa-compass mr-2 text-blue-500"></i>Разделы аккаунта</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Личные настройки и сервисы платформы.</p>
            </div>
        </div>
        <nav class="flex flex-wrap gap-2.5 p-5" aria-label="Аккаунт">
            <a class="prl-btn-secondary" href="/auth/profile"><i class="fas fa-id-card"></i> Профиль аккаунта</a>
            <a class="prl-btn-secondary" href="/account/security"><i class="fas fa-shield-halved"></i> Безопасность и сессии</a>
            <a class="prl-btn-secondary" href="/prl597"><i class="fas fa-bell"></i> Уведомления</a>
            <a class="prl-btn-secondary" href="/tasks"><i class="fas fa-list-check"></i> Мои фоновые задачи</a>
            <?php if ($is_admin): ?><a class="prl-btn-secondary" href="/admin"><i class="fas fa-screwdriver-wrench"></i> Администрирование</a><?php endif; ?>
        </nav>
    </section>
</div>
