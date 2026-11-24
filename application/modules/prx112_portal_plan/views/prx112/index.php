<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
$badges = [
    'approved' => 'prx-badge-ok', 'wip' => 'prx-badge-run',
    'unpublished' => 'prx-badge-wait', 'archived' => '', 'planned' => 'prx-badge-wait',
];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['План и архив портала']],
        'title' => 'План и архив портала',
        'lead' => 'Сервисы домена: одобренные, находящиеся в работе и запланированные. Реализованные сервисы показывают состояние из собственного манифеста.',
        'actions' => [['label' => 'Каталог сервисов', 'href' => '/services', 'icon' => 'fa-grip']],
    ]); ?>
    <?php if ($approved): ?>
        <p class="prx-flash prx-flash-ok mt-6" role="status">Сервис одобрен для каталога. Его аудитория и права доступа не изменились.</p>
    <?php endif; ?>

    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($domains as $title => $services): ?>
        <section class="prx-card">
            <h2 class="prx-h3"><?php echo HTML::chars($title); ?></h2>
            <ul class="mt-3 space-y-4 text-sm leading-relaxed">
                <?php foreach ($services as $service): ?>
                <?php $state = $service['state']; ?>
                <li>
                    <?php if ( ! empty($service['href'])): ?>
                        <a class="prx-link font-semibold" href="<?php echo HTML::chars($service['href']); ?>"><?php echo HTML::chars($service['title']); ?></a>
                    <?php else: ?>
                        <span class="font-semibold"><?php echo HTML::chars($service['title']); ?></span>
                    <?php endif; ?>
                    <span class="prx-badge <?php echo $badges[$state]; ?> ml-2"><?php echo HTML::chars(Service_Prx110_Catalog::state_label($state)); ?></span>
                    <?php if (isset($service['manifest_state']) && $service['manifest_state'] !== $state): ?>
                        <span class="prx-hint">По манифесту: <?php echo HTML::chars(Service_Prx110_Catalog::state_label($service['manifest_state'])); ?></span>
                    <?php endif; ?>
                    <?php if ( ! empty($service['description'])): ?>
                        <p class="prx-hint mt-1"><?php echo HTML::chars($service['description']); ?></p>
                    <?php endif; ?>
                    <?php if ( ! empty($service['result'])): ?>
                        <p class="prx-hint mt-1"><strong>На выходе:</strong> <?php echo HTML::chars($service['result']); ?></p>
                    <?php endif; ?>
                    <?php if ( ! empty($service['approve'])): ?>
                        <form method="post" action="/portal-plan/approve" class="mt-2">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
                            <input type="hidden" name="module" value="<?php echo HTML::chars($service['approve']); ?>">
                            <button type="submit" class="prx-btn-secondary" aria-label="Одобрить сервис: <?php echo HTML::chars($service['title']); ?>">Одобрить</button>
                        </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>
</div>
