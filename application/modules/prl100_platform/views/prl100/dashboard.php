<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="space-y-4">
    <section class="prl-panel p-6">
        <h1 class="prl-h1">Мой аккаунт</h1>
        <p class="mt-3 font-semibold"><?= HTML::chars($me->username) ?></p>
        <p class="text-sm text-gray-500"><?= HTML::chars($me->email) ?></p>
        <p class="mt-3 text-sm text-gray-500">Это кабинет доступа к платформе. Профиль сотрудника, история работы и достижения появятся в HR-модуле.</p>
    </section>
    <nav class="prl-panel flex flex-wrap gap-3 p-5" aria-label="Аккаунт">
        <a class="prl-btn-secondary" href="/auth/profile">Профиль аккаунта</a>
        <a class="prl-btn-secondary" href="/account/security">Безопасность и сессии</a>
        <a class="prl-btn-secondary" href="/prl597">Уведомления</a>
        <a class="prl-btn-secondary" href="/tasks">Мои фоновые задачи</a>
        <?php if ($is_admin): ?><a class="prl-btn-secondary" href="/admin">Администрирование</a><?php endif; ?>
    </nav>
</div>
