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
// Табличный вид: один плоский список по всем доменам; сортировка по умолчанию —
// по дате решения (свежие сверху), чтобы новые модули находились сразу.
$state_rank = ['approved' => 0, 'wip' => 1, 'unpublished' => 2, 'planned' => 3, 'archived' => 4];
$plan_rows = [];
foreach ($domains as $group_title => $group_services)
{
    foreach ($group_services as $service)
    {
        $plan_rows[] = $service + ['group' => $group_title];
    }
}
usort($plan_rows, static function (array $a, array $b): int {
    $ta = ! empty($a['decided_at']) ? (int) strtotime((string) $a['decided_at']) : 0;
    $tb = ! empty($b['decided_at']) ? (int) strtotime((string) $b['decided_at']) : 0;
    if ($ta !== $tb) return $tb <=> $ta;
    return strcasecmp((string) $a['title'], (string) $b['title']);
});
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['План и архив']],
        'title' => 'План и архив',
        'lead' => 'Здесь видны сервисы во всех состояниях. В каталог (/services) попадают только одобренные.',
        'actions' => [['label' => 'Каталог сервисов', 'href' => '/services', 'icon' => 'fa-grip']],
    ]); ?>
    <?php echo View::factory('partials/service_search', [
        'id' => 'portal-plan-search', 'label' => 'Поиск по плану платформы', 'controls' => 'portal-plan-content',
        'empty_message' => 'Ничего не найдено в выбранном состоянии. Измените запрос или смените фильтр.',
    ]); ?>

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

    <?php // Вид списка: карточки (по умолчанию) или таблица — выбор запоминается. ?>
    <div class="prx-seg mt-2 w-fit max-w-full flex-wrap gap-1" role="group" aria-label="Вид списка" data-plan-view-switch>
        <button type="button" class="prx-seg-item inline-flex items-center gap-1.5" data-plan-view="cards" aria-pressed="true">
            <i class="fas fa-grip" aria-hidden="true"></i>Карточки
        </button>
        <button type="button" class="prx-seg-item inline-flex items-center gap-1.5" data-plan-view="table" aria-pressed="false">
            <i class="fas fa-table-list" aria-hidden="true"></i>Таблица
        </button>
    </div>

    <?php if ( ! $domains): ?>
        <p class="prx-hint mt-6">Нет сервисов в этом состоянии</p>
    <?php else: ?>
    <div class="mt-6 grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3" data-plan-view-panel="cards">
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
                $primary = array_key_first($service_actions);
                $can_archive = isset($service_actions['archived']);
                $can_pin = $service_state === 'approved' && ! empty($service['href']);
                $has_decision = ! empty($service['decided_at']);
                $has_manifest_hint = isset($service['manifest_state']) && $service['manifest_state'] !== $service_state;
                $has_details = $can_archive || $can_pin || $has_decision || $has_manifest_hint
                    || ! empty($service['description']) || ! empty($service['result']);
                $action_data = [
                    'module' => $service['publication_module'] ?? NULL, 'title' => $service['title'], 'return_state' => $state,
                ];
                ?>
                <li data-portal-plan-item="<?php echo HTML::chars($service['title'].' '.($service['description'] ?? '').' '.($service['keywords'] ?? '').' '.implode(' ', (array) ($service['services'] ?? []))); ?>"
                    class="relative min-h-10 rounded-md px-1.5 py-1 -mx-1.5"<?php if ($service_actions): ?> data-portal-plan-module="<?php echo HTML::chars($service['publication_module']); ?>" tabindex="-1"<?php endif; ?>>
                    <div class="flex min-h-9 items-center gap-1<?php echo $has_details ? ' pr-10' : ''; ?>">
                        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                            <i class="<?php echo HTML::chars($service['icon'] ?? 'fas fa-list-check'); ?> w-4 shrink-0 text-center text-gray-400" aria-hidden="true"></i>
                            <?php if ( ! empty($service['href'])): ?>
                                <a class="prx-link min-w-0 break-words font-medium" href="<?php echo HTML::chars($service['href']); ?>"<?php echo empty($service['description']) ? '' : ' title="'.HTML::chars($service['description']).'"'; ?>><?php echo HTML::chars($service['title']); ?></a>
                            <?php else: ?>
                                <span class="min-w-0 break-words font-medium"><?php echo HTML::chars($service['title']); ?></span>
                            <?php endif; ?>
                            <span class="prx-badge <?php echo $badges[$service_state]; ?>"><?php echo HTML::chars(Service_Prx110_Catalog::state_label($service_state)); ?></span>
                        </div>
                        <?php if ($primary !== NULL): ?>
                            <?php echo View::factory('prx112/publication_action', $action_data + [
                                'next' => $primary, 'action' => $actions[$service_actions[$primary]], 'compact' => TRUE,
                            ]); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($has_details): ?>
                        <details>
                            <summary class="prx-btn-icon absolute right-1.5 top-1 list-none [&::-webkit-details-marker]:hidden"
                                     title="Подробнее" aria-label="<?php echo HTML::chars('Подробнее: '.$service['title']); ?>">
                                <i class="fas fa-ellipsis" aria-hidden="true"></i>
                            </summary>
                            <div class="space-y-1 break-words pb-1 pl-6 prx-hint">
                                <?php if ( ! empty($service['description'])): ?>
                                    <p><?php echo HTML::chars($service['description']); ?></p>
                                <?php endif; ?>
                                <?php if ( ! empty($service['result'])): ?>
                                    <p><strong>На выходе:</strong> <?php echo HTML::chars($service['result']); ?></p>
                                <?php endif; ?>
                                <?php if ( ! empty($service['services'])): ?>
                                    <p data-portal-plan-services><strong>Сервисы:</strong> <?php echo HTML::chars(implode(', ', $service['services'])); ?></p>
                                <?php endif; ?>
                                <?php if ($has_decision): ?>
                                    <p><?php echo HTML::chars($decision_labels[$service_state]); ?>
                                        <?php echo (new DateTimeImmutable($service['decided_at']))->format('d.m.Y'); ?><?php
                                        echo empty($service['decided_by_name']) ? '' : ', '.HTML::chars($service['decided_by_name']); ?></p>
                                    <?php if ( ! empty($service['note'])): ?>
                                        <p><?php echo HTML::chars($service['note']); ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($has_manifest_hint): ?>
                                    <p>По манифесту: <?php echo HTML::chars(Service_Prx110_Catalog::state_label($service['manifest_state'])); ?></p>
                                <?php endif; ?>
                                <?php if ($can_archive || $can_pin): ?>
                                <div class="flex flex-wrap items-center gap-1">
                                    <?php if ($can_archive): ?>
                                        <?php echo View::factory('prx112/publication_action', $action_data + [
                                            'next' => 'archived', 'action' => $actions['archived'], 'compact' => FALSE,
                                        ]); ?>
                                    <?php endif; ?>
                                    <?php if ($can_pin): ?>
                                    <?php $pinned = isset($favorite_hrefs[$service['href']]);
                                          $pin_label = ! $favorites_ready ? 'Избранное недоступно' : ($pinned ? 'Убрать из Избранного' : 'В Избранное'); ?>
                                    <button type="button" class="prx-btn-icon nav-pin-card <?php echo $pinned ? 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300' : 'text-gray-400 dark:text-gray-500'; ?>"
                                            data-nav-pin-card="<?php echo HTML::chars($service['href']); ?>"
                                            data-nav-pin-title="<?php echo HTML::chars($service['title']); ?>"
                                            data-nav-pin-icon="<?php echo HTML::chars($service['icon']); ?>"
                                            title="<?php echo HTML::chars($pin_label); ?>" aria-label="<?php echo HTML::chars($pin_label.': '.$service['title']); ?>"
                                            aria-pressed="<?php echo $pinned ? 'true' : 'false'; ?>"<?php echo $favorites_ready ? '' : ' disabled'; ?>><i class="fas fa-star" aria-hidden="true"></i></button>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>

    <?php // Табличный вид: все атрибуты в колонках, сортировка кликом по заголовку, поиск — тот же. ?>
    <div class="prx-card mt-6 overflow-x-auto" data-plan-view-panel="table" hidden>
        <table class="prx-table min-w-[880px]" data-plan-table>
            <thead>
            <tr>
                <th scope="col" data-plan-sort-th><button type="button" class="prx-btn-icon" data-plan-sort="title">Сервис <i class="fas fa-sort text-gray-400" data-plan-sort-icon aria-hidden="true"></i></button></th>
                <th scope="col" data-plan-sort-th><button type="button" class="prx-btn-icon" data-plan-sort="module">Модуль <i class="fas fa-sort text-gray-400" data-plan-sort-icon aria-hidden="true"></i></button></th>
                <th scope="col" data-plan-sort-th><button type="button" class="prx-btn-icon" data-plan-sort="group">Домен <i class="fas fa-sort text-gray-400" data-plan-sort-icon aria-hidden="true"></i></button></th>
                <th scope="col" data-plan-sort-th><button type="button" class="prx-btn-icon" data-plan-sort="state">Состояние <i class="fas fa-sort text-gray-400" data-plan-sort-icon aria-hidden="true"></i></button></th>
                <th scope="col" data-plan-sort-th aria-sort="descending"><button type="button" class="prx-btn-icon" data-plan-sort="ts">Решение <i class="fas fa-sort-down text-gray-400" data-plan-sort-icon aria-hidden="true"></i></button></th>
                <th scope="col"><span class="sr-only">Действия</span></th>
            </tr>
            </thead>
            <tbody>
<?php foreach ($plan_rows as $service): ?>
<?php
    $service_state = $service['state'];
    $service_actions = empty($service['publication_module']) ? [] : ($transitions[$service_state] ?? []);
    $primary = array_key_first($service_actions);
    $ts = ! empty($service['decided_at']) ? (int) strtotime((string) $service['decided_at']) : 0;
?>
            <tr data-portal-plan-item="<?php echo HTML::chars($service['title'].' '.($service['description'] ?? '').' '.($service['module'] ?? '').' '.($service['keywords'] ?? '')); ?>"
                data-plan-row
                data-plan-title="<?php echo HTML::chars((string) $service['title']); ?>"
                data-plan-module="<?php echo HTML::chars((string) ($service['module'] ?? '')); ?>"
                data-plan-group="<?php echo HTML::chars((string) $service['group']); ?>"
                data-plan-state-rank="<?php echo (int) ($state_rank[$service_state] ?? 9); ?>"
                data-plan-ts="<?php echo $ts; ?>"<?php if ($primary !== NULL): ?> data-portal-plan-module="<?php echo HTML::chars((string) $service['publication_module']); ?>" tabindex="-1"<?php endif; ?>>
                <td class="min-w-0">
                    <div class="flex items-center gap-2">
                        <i class="<?php echo HTML::chars($service['icon'] ?? 'fas fa-list-check'); ?> w-4 shrink-0 text-center text-gray-400" aria-hidden="true"></i>
                        <span class="min-w-0">
<?php if ( ! empty($service['href'])): ?>
                            <a class="prx-link break-words font-medium" href="<?php echo HTML::chars($service['href']); ?>"><?php echo HTML::chars((string) $service['title']); ?></a>
<?php else: ?>
                            <span class="break-words font-medium"><?php echo HTML::chars((string) $service['title']); ?></span>
<?php endif; ?>
<?php if ( ! empty($service['description'])): ?>
                            <span class="block truncate text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $service['description']); ?></span>
<?php endif; ?>
                        </span>
                    </div>
                </td>
                <td class="whitespace-nowrap font-mono text-xs text-gray-600 dark:text-gray-300"><?php echo ! empty($service['module']) ? HTML::chars((string) $service['module']) : '—'; ?></td>
                <td class="text-sm text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $service['group']); ?></td>
                <td><span class="prx-badge <?php echo $badges[$service_state]; ?>"><?php echo HTML::chars(Service_Prx110_Catalog::state_label($service_state)); ?></span></td>
                <td class="whitespace-nowrap text-xs text-gray-600 dark:text-gray-300">
<?php if ( ! empty($service['decided_at'])): ?>
                    <span class="tabular-nums"><?php echo (new DateTimeImmutable((string) $service['decided_at']))->format('d.m.Y'); ?></span><?php echo empty($service['decided_by_name']) ? '' : ' · '.HTML::chars((string) $service['decided_by_name']); ?>
<?php else: ?>
                    —
<?php endif; ?>
                </td>
                <td class="text-right">
<?php if ($primary !== NULL): ?>
                    <?php echo View::factory('prx112/publication_action', [
                        'module' => $service['publication_module'], 'title' => $service['title'],
                        'return_state' => $state, 'next' => $primary,
                        'action' => $actions[$service_actions[$primary]], 'compact' => TRUE,
                    ]); ?>
<?php else: ?>
                    <span class="text-gray-400" aria-hidden="true">—</span>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    </div>
</div>
<script src="/assets/js/portal-plan.js?v=3" defer></script>
