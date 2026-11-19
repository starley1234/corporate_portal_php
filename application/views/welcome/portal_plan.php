<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['План и архив портала']],
        'title'  => $title,
        'lead'   => 'Прежний план развития корпоративного портала. Доступные, тестовые и архивные модули отмечены отдельно.',
    ]); ?>

    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($domains as $domain): ?>
        <section class="prx-card">
            <h2 class="prx-h3"><?php echo HTML::chars($domain['title']); ?></h2>
            <ul class="mt-3 space-y-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                <?php foreach ($domain['services'] as $service): ?>
                <li>
                    <?php if ($service['status'] === 'active' && $service['module'] === 'prx300_employee'): ?>
                        <a class="prx-link font-semibold" href="/employee"><?php echo HTML::chars($service['title']); ?></a>
                        <span class="prx-badge prx-badge-ok ml-2">Доступно</span>
                    <?php else: ?>
                        <?php echo HTML::chars($service['title']); ?>
                        <?php if ($service['status'] === 'archived'): ?>
                        <span class="prx-badge ml-2">Архив</span>
                        <?php elseif ($service['status'] === 'active'): ?>
                        <span class="prx-badge prx-badge-info ml-2">Тестовый режим</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>
</div>
