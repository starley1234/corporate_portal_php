<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
$favorite_hrefs = array_fill_keys(array_column($nav_favorites['items'] ?? [], 'href'), TRUE);
$favorites_ready = ($nav_favorites['status'] ?? '') === 'ready';
$badges = [
    'approved' => 'prx-badge-ok', 'wip' => 'prx-badge-run',
    'unpublished' => 'prx-badge-wait', 'archived' => '', 'planned' => 'prx-badge-wait',
];
$filter_labels = ['all' => 'Все', 'approved' => 'Одобрено', 'unpublished' => 'Не опубликовано'];
$messages = [
    'approved' => 'Сервис одобрен для каталога.',
    'unpublished' => 'Сервис не опубликован в каталоге.',
    'wip' => 'Сервис переведён в работу. В каталоге он не отображается.',
    'archived' => 'Сервис отправлен в архив. В каталоге он не отображается.',
];
$decision_labels = [
    'approved' => 'Одобрено', 'wip' => 'В работе с',
    'unpublished' => 'Не опубликовано с', 'archived' => 'В архиве с',
];
$actions = [
    'approved' => ['Одобрить', 'fa-check', ''],
    'withdraw' => ['Перевести в работу', 'fa-rotate-left', 'Сервис исчезнет из каталога.'],
    'restore' => ['Вернуть в работу', 'fa-rotate-left', ''],
    'archived' => ['В архив', 'fa-box-archive', 'Сервис не будет виден в каталоге. Его можно вернуть в работу из архива.'],
];
$transitions = [
    'approved' => ['wip' => 'withdraw', 'archived' => 'archived'],
    'wip' => ['approved' => 'approved', 'archived' => 'archived'],
    'unpublished' => ['approved' => 'approved', 'archived' => 'archived'],
    'archived' => ['wip' => 'restore'],
];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['План и архив портала']],
        'title' => 'План и архив портала',
        'lead' => 'Здесь видны сервисы во всех состояниях. В каталог (/services) попадают только одобренные.',
        'actions' => [['label' => 'Каталог сервисов', 'href' => '/services', 'icon' => 'fa-grip']],
    ]); ?>
    <div class="mt-6 max-w-2xl" data-portal-plan-search hidden>
        <label class="sr-only" for="portal-plan-search">Поиск по плану портала</label>
        <div class="relative">
            <i class="fas fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400" aria-hidden="true"></i>
            <input id="portal-plan-search" type="search" autocomplete="off" spellcheck="false"
                   aria-controls="portal-plan-content" aria-describedby="portal-plan-search-empty"
                   placeholder="Найти сервис по названию или ключевому слову…" class="prx-input pl-10">
        </div>
        <p id="portal-plan-search-empty" class="prx-hint mt-2" role="status" hidden>Ничего не найдено в выбранном состоянии. Измените запрос или выберите «Все».</p>
    </div>

    <div id="portal-plan-content" data-portal-plan-content>
    <?php if (isset($messages[$publication ?? ''])): ?>
        <p class="prx-flash prx-flash-ok mt-6" role="status" data-portal-plan-message><?php echo HTML::chars($messages[$publication]); ?></p>
    <?php endif; ?>

    <nav class="prx-seg mt-6 w-fit max-w-full flex-wrap gap-1" aria-label="Состояние сервисов">
        <?php foreach (Service_Prx112_Plan::STATES as $filter): ?>
            <a href="/portal-plan<?php echo $filter === 'all' ? '' : '?state='.$filter; ?>"
               class="prx-seg-item inline-flex items-center gap-1.5<?php echo $filter === $state ? ' is-active' : ''; ?>"<?php echo $filter === $state ? ' aria-current="page"' : ''; ?>>
                <?php echo HTML::chars($filter_labels[$filter] ?? Service_Prx110_Catalog::state_label($filter)); ?>
                <span class="prx-count"><?php echo (int) $counts[$filter]; ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ( ! $domains): ?>
        <p class="prx-hint mt-6">Нет сервисов в этом состоянии</p>
    <?php else: ?>
    <div class="mt-6 grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($domains as $title => $services): ?>
        <section class="prx-card min-w-0" data-portal-plan-group="<?php echo HTML::chars($title); ?>">
            <div class="flex items-center gap-2.5">
                <span class="rounded-lg bg-blue-50 p-2 text-sm text-blue-600 dark:bg-blue-950/80 dark:text-blue-400"><i class="fas fa-folder" aria-hidden="true"></i></span>
                <h2 class="prx-h3 min-w-0"><?php echo HTML::chars($title); ?></h2>
                <span class="prx-count ml-auto shrink-0"><?php echo count($services); ?></span>
            </div>
            <ul class="mt-3.5 space-y-1 text-sm leading-snug">
                <?php foreach ($services as $service): ?>
                <?php
                $service_state = $service['state'];
                $service_actions = empty($service['publication_module']) ? [] : ($transitions[$service_state] ?? []);
                $can_pin = $service_state === 'approved' && ! empty($service['href']);
                ?>
                <li data-portal-plan-item="<?php echo HTML::chars($service['title'].' '.($service['description'] ?? '').' '.($service['keywords'] ?? '')); ?>"
                    class="rounded-md px-1.5 py-1 -mx-1.5"<?php if ($service_actions): ?> data-portal-plan-module="<?php echo HTML::chars($service['publication_module']); ?>" tabindex="-1"<?php endif; ?>>
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                            <i class="<?php echo HTML::chars($service['icon'] ?? 'fas fa-list-check'); ?> w-4 shrink-0 text-center text-gray-400" aria-hidden="true"></i>
                            <?php if ( ! empty($service['href'])): ?>
                                <a class="prx-link min-w-0 break-words font-medium" href="<?php echo HTML::chars($service['href']); ?>"<?php echo empty($service['description']) ? '' : ' title="'.HTML::chars($service['description']).'"'; ?>><?php echo HTML::chars($service['title']); ?></a>
                            <?php else: ?>
                                <span class="min-w-0 break-words font-medium"><?php echo HTML::chars($service['title']); ?></span>
                            <?php endif; ?>
                            <span class="prx-badge <?php echo $badges[$service_state]; ?>"><?php echo HTML::chars(Service_Prx110_Catalog::state_label($service_state)); ?></span>
                        </div>
                        <?php if ($service_actions || $can_pin): ?>
                        <div class="ml-auto flex flex-wrap items-center gap-1">
                            <?php foreach ($service_actions as $next => $action): ?>
                            <?php [$label, $icon, $confirm] = $actions[$action]; ?>
                            <form method="post" action="/portal-plan/approve" data-portal-plan-action>
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
                                <input type="hidden" name="module" value="<?php echo HTML::chars($service['publication_module']); ?>">
                                <input type="hidden" name="state" value="<?php echo $next; ?>">
                                <input type="hidden" name="return_state" value="<?php echo $state; ?>">
                                <button type="submit" class="<?php echo $confirm ? 'prx-btn-icon-danger' : 'prx-btn-icon'; ?> h-10 w-10"
                                        title="<?php echo HTML::chars($label); ?>" aria-label="<?php echo HTML::chars($label.': '.$service['title']); ?>"
                                        <?php if ($confirm): ?>data-prx-confirm="<?php echo HTML::chars($confirm); ?>" data-prx-title="<?php echo HTML::chars($label.'?'); ?>" data-prx-ok="<?php echo HTML::chars($label); ?>" data-prx-subject="<?php echo HTML::chars($service['title']); ?>"<?php endif; ?>>
                                    <i class="fas <?php echo $icon; ?>" aria-hidden="true"></i>
                                </button>
                            </form>
                            <?php endforeach; ?>
                            <?php if ($can_pin): ?>
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
                    </div>
                    <?php if ( ! empty($service['decided_at'])): ?>
                        <p class="prx-hint pl-6"><?php echo HTML::chars($decision_labels[$service_state]); ?>
                            <?php echo (new DateTimeImmutable($service['decided_at']))->format('d.m.Y'); ?><?php
                            echo empty($service['decided_by_name']) ? '' : ', '.HTML::chars($service['decided_by_name']); ?></p>
                        <?php if ($service['note'] !== ''): ?>
                            <p class="prx-hint break-words pl-6"><?php echo HTML::chars($service['note']); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (isset($service['manifest_state']) && $service['manifest_state'] !== $service_state): ?>
                        <p class="prx-hint pl-6">По манифесту: <?php echo HTML::chars(Service_Prx110_Catalog::state_label($service['manifest_state'])); ?></p>
                    <?php endif; ?>
                    <?php if ( ! empty($service['result'])): ?>
                        <details class="prx-hint mt-1 pl-6">
                            <summary class="cursor-pointer">Подробнее</summary>
                            <p class="mt-1"><?php echo HTML::chars($service['description']); ?></p>
                            <p class="mt-1"><strong>На выходе:</strong> <?php echo HTML::chars($service['result']); ?></p>
                        </details>
                    <?php elseif (empty($service['href']) && ! empty($service['description'])): ?>
                        <p class="prx-hint mt-1 pl-6"><?php echo HTML::chars($service['description']); ?></p>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    </div>
</div>
<script src="/assets/js/portal-plan.js" defer></script>
