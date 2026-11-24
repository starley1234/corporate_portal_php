<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['План и архив портала']],
        'title'  => $title,
        'lead'   => 'Дорожная карта производственных пайплайнов и архив прежнего плана портала.',
    ]); ?>

    <section class="mt-6" aria-labelledby="pipeline-plan">
        <h2 id="pipeline-plan" class="prx-h3">План производственных пайплайнов</h2>
        <p class="mt-3">Запланированные сценарии: от разработки изделия до работы с рекламациями.</p>
        <?php echo View::factory('welcome/parts/enterprise_plan', ['phases' => $phases]); ?>
    </section>

    <h2 class="prx-h3 mt-7">Прежний план портала</h2>
    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($domains as $domain): ?>
        <section class="prx-card">
            <h3 class="prx-h3"><?php echo HTML::chars($domain['title']); ?></h3>
            <ul class="mt-3 space-y-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                <?php foreach ($domain['services'] as $service): ?>
                <li>
                    <?php echo HTML::chars($service['title']); ?>
                    <?php if ($service['status'] === 'archived'): ?>
                        <span class="prx-badge ml-2">Архив</span>
                    <?php elseif ($service['status'] === 'active'): ?>
                        <span class="prx-badge prx-badge-info ml-2">Тестовый режим</span>
                    <?php elseif ($service['status'] === 'pilot'): ?>
                        <span class="prx-badge prx-badge-info ml-2">Не опубликован</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>
</div>
