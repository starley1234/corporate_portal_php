<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx150_api_hub/services — каталог API-сервисов: модули-владельцы scope.
 *
 * По умолчанию только одобренные (состояние берётся то же, что у витрины
 * /services); все состояния — по явному «Все» (?all=1). SSR: чипы-ссылки,
 * без JS.
 *
 * Переменные: $services, $approved_count, $all_count, $show_all.
 */
$state_badges = [
    'approved'    => ['prx-badge-ok', 'одобрен'],
    'wip'         => ['prx-badge-run', 'в работе'],
    'unpublished' => ['prx-badge-wait', 'не опубликован'],
    'archived'    => ['prx-badge-wait', 'в архиве'],
];
$total_scopes = 0;
foreach ($services as $s) {
    $total_scopes += count($s['scopes']);
}
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Администрирование', '/admin'], ['API-доступ', '/prx150_api_hub'], ['Сервисы']],
        'title'  => 'Каталог API-сервисов',
        'pill'   => ['prx150 · сервисы и права', 'fa-key'],
        'lead'   => 'Свой API-сервис модуль объявляет в манифесте (api_scopes). Ключ даёт внешней системе доступ только к отмеченным сервисам; журнал — в «Использовании».',
    ]); ?>

    <div class="prx-seg mt-5" role="tablist" aria-label="Разделы API-доступа">
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx150_api_hub"><i class="fas fa-key mr-1.5"></i>Ключи</a>
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx150_api_hub/usage"><i class="fas fa-chart-line mr-1.5"></i>Использование</a>
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx150_api_hub/docs"><i class="fas fa-book mr-1.5"></i>Документация</a>
        <a class="prx-seg-item is-active" role="tab" aria-selected="true" href="/prx150_api_hub/services"><i class="fas fa-layer-group mr-1.5"></i>Сервисы</a>
    </div>

    <div class="prx-seg mt-4 w-fit max-w-full flex-wrap" aria-label="Фильтр сервисов">
        <a class="prx-seg-item<?php echo $show_all ? '' : ' is-active'; ?>" href="/prx150_api_hub/services">Одобренные <span class="prx-count"><?php echo (int) $approved_count; ?></span></a>
        <a class="prx-seg-item<?php echo $show_all ? ' is-active' : ''; ?>" href="/prx150_api_hub/services?all=1">Все <span class="prx-count"><?php echo (int) $all_count; ?></span></a>
    </div>

<?php if (empty($services)): ?>
    <?php if ($show_all OR $all_count === 0): ?>
    <p class="prx-hint mt-6">Ни один модуль пока не объявил <code>api_scopes</code>. Добавьте сервис-модуль с правами в манифесте — он появится здесь.</p>
    <?php else: ?>
    <p class="prx-hint mt-6">Одобренных API-сервисов пока нет — сервисы «в работе» видны по «Все».</p>
    <?php endif; ?>
<?php else: ?>
    <p class="prx-hint mt-4">Прав (scope): <?php echo (int) $total_scopes; ?> · сервисов: <?php echo count($services); ?><?php echo $show_all ? ' · показаны все состояния' : ''; ?>.</p>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($services as $s): ?>
        <?php [$badge_class, $badge_label] = $state_badges[$s['state']] ?? ['prx-badge-wait', (string) $s['state']]; ?>
        <section class="prx-card flex flex-col">
            <div class="flex items-center gap-3">
                <span class="rounded-lg bg-blue-50 p-2.5 text-sm font-bold text-blue-600 dark:bg-blue-950/80 dark:text-blue-400"><i class="<?php echo HTML::chars($s['icon']); ?>"></i></span>
                <div class="min-w-0">
                    <h2 class="prx-h3 truncate">
<?php if ($s['href'] !== ''): ?><a href="<?php echo HTML::chars($s['href']); ?>" class="hover:underline"><?php echo HTML::chars($s['title']); ?></a>
<?php else: echo HTML::chars($s['title']); endif; ?>
                    </h2>
                    <span class="<?php echo HTML::chars($badge_class); ?> mt-0.5"><span class="prx-dot"></span><?php echo HTML::chars($badge_label); ?></span>
                </div>
            </div>
            <?php if ($s['short'] !== ''): ?>
            <p class="mt-3 text-sm leading-relaxed text-gray-500 dark:text-gray-400"><?php echo HTML::chars($s['short']); ?></p>
            <?php endif; ?>
            <ul class="mt-3 space-y-2">
                <?php foreach ($s['scopes'] as $scope => $meta): ?>
                <li class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                    <div class="flex flex-wrap items-center gap-2">
                        <code class="font-mono text-xs font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $scope); ?></code>
                        <span class="prx-count ml-auto"><?php echo (int) $meta['rate_limit']; ?>/мин</span>
                    </div>
                    <span class="mt-0.5 block text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $meta['title']); ?></span>
                    <?php if ($meta['description'] !== ''): ?>
                    <span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($meta['description']); ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
