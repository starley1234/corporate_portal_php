<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Главная']],
        'title'   => $title,
        'lead'    => 'Откройте нужный сервис или проверьте уведомления.',
        'actions' => [['label' => 'План и архив', 'href' => '/portal-plan', 'icon' => 'fa-box-archive']],
    ]); ?>

    <section class="prx-panel mt-6" aria-labelledby="home-work">
        <div class="prx-panel-head"><h2 id="home-work" class="prx-h3">Быстрый переход</h2></div>
        <div class="flex flex-wrap gap-2.5 p-5">
            <?php foreach ($work as $index => $link): ?>
                <a class="<?php echo $index === 0 ? 'prx-btn-process' : 'prx-btn-secondary'; ?>" href="<?php echo HTML::chars($link['href']); ?>"><?php echo HTML::chars($link['title']); ?></a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($stand || $can_monitor): ?>
    <section class="prx-panel mt-6" aria-labelledby="home-tools">
        <div class="prx-panel-head"><h2 id="home-tools" class="prx-h3">Стенд и диагностика</h2></div>
        <div class="p-5">
            <?php if ($stand): ?>
                <p class="mb-3">Документооборот работает в тестовом режиме. Используйте только тестовые документы.</p>
            <?php endif; ?>
            <div class="flex flex-wrap gap-2.5">
                <?php foreach ($stand as $link): ?>
                    <a class="prx-btn-secondary" href="<?php echo HTML::chars($link['href']); ?>"><?php echo HTML::chars($link['title']); ?></a>
                <?php endforeach; ?>
                <?php if ($can_monitor): ?>
                    <a class="prx-btn-secondary" href="/prx120_jobs">Монитор воркеров</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
</div>
