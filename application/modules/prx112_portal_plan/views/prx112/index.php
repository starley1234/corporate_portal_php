<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
$favorite_hrefs = array_fill_keys(array_column($nav_favorites['items'] ?? [], 'href'), TRUE);
$favorites_ready = ($nav_favorites['status'] ?? '') === 'ready';
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
    <?php if ($publication): ?>
        <p class="prx-flash prx-flash-ok mt-6" role="status"><?php echo $publication === 'approved' ? 'Сервис одобрен для каталога.' : 'Сервис переведён в работу и убран из обычного каталога.'; ?></p>
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
                    <?php if ( ! empty($service['publication_module']) || ($state === 'approved' && ! empty($service['href']))): ?>
                    <div class="mt-1 flex gap-1">
                        <?php if ( ! empty($service['publication_module'])): ?>
                        <?php $next = $state === 'approved' ? 'wip' : 'approved';
                              $label = $next === 'wip' ? 'Перевести в работу' : 'Одобрить'; ?>
                        <form method="post" action="/portal-plan/approve">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
                            <input type="hidden" name="module" value="<?php echo HTML::chars($service['publication_module']); ?>">
                            <input type="hidden" name="state" value="<?php echo $next; ?>">
                            <button type="submit" class="prx-btn-icon h-10 w-10" title="<?php echo $label; ?>" aria-label="<?php echo HTML::chars($label.': '.$service['title']); ?>">
                                <i class="fas <?php echo $next === 'wip' ? 'fa-rotate-left' : 'fa-check'; ?>" aria-hidden="true"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($state === 'approved' && ! empty($service['href'])): ?>
                        <?php $pinned = isset($favorite_hrefs[$service['href']]);
                              $pin_label = ! $favorites_ready ? 'Избранное недоступно' : ($pinned ? 'Убрать из Избранного' : 'В Избранное'); ?>
                        <button type="button" class="prx-btn-icon nav-pin-card h-10 w-10 <?php echo $pinned ? 'text-amber-500' : 'text-gray-400'; ?>"
                                data-nav-pin-card="<?php echo HTML::chars($service['href']); ?>"
                                data-nav-pin-title="<?php echo HTML::chars($service['title']); ?>"
                                data-nav-pin-icon="<?php echo HTML::chars($service['icon']); ?>"
                                title="<?php echo HTML::chars($pin_label); ?>" aria-label="<?php echo HTML::chars($pin_label.': '.$service['title']); ?>"
                                aria-pressed="<?php echo $pinned ? 'true' : 'false'; ?>"<?php echo $favorites_ready ? '' : ' disabled'; ?>><i class="fas fa-star" aria-hidden="true"></i></button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>
</div>
