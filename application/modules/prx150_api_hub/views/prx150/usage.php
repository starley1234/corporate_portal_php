<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API-доступ: журнал использования.
 *
 * Переменные: $rows, $clients, $scopes, $summary, $total, $page, $per, $period,
 *             $client_id, $scope, $status, $q, $flash.
 */
$pages = max(1, (int) ceil($total / max(1, $per)));
$params = array_filter([
    'period'    => $period,
    'client_id' => $client_id > 0 ? $client_id : NULL,
    'scope'     => $scope !== '' ? $scope : NULL,
    'status'    => $status !== '' ? $status : NULL,
    'q'         => $q !== '' ? $q : NULL,
], 'strlen');

$status_badge = function (int $code): string {
    if ($code >= 500) return 'prx-badge-err';
    if ($code >= 400) return 'prx-badge-run';
    return 'prx-badge-ok';
};
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Администрирование', '/admin'], ['API-доступ', '/prx150_api_hub'], ['Использование']],
        'title'  => 'Использование API',
        'pill'   => ['prx150 · журнал', 'fa-chart-line'],
        'lead'   => 'Кто, куда и с каким результатом звал внешний API. Ошибки и лимиты видны сразу; фильтры — GET, ссылкой можно поделиться.',
        'actions'=> [
            ['label' => 'Ключи', 'href' => '/prx150_api_hub', 'icon' => 'fa-key', 'role' => 'secondary'],
            ['label' => 'Документация', 'href' => '/prx150_api_hub/docs', 'icon' => 'fa-book', 'role' => 'secondary'],
        ],
    ]); ?>

    <?php echo View::factory('partials/flash', ['flash' => $flash]); ?>

    <div class="prx-seg mt-5" role="tablist" aria-label="Разделы API-доступа">
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx150_api_hub"><i class="fas fa-key mr-1.5"></i>Ключи</a>
        <a class="prx-seg-item is-active" role="tab" aria-selected="true" href="/prx150_api_hub/usage"><i class="fas fa-chart-line mr-1.5"></i>Использование</a>
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx150_api_hub/docs"><i class="fas fa-book mr-1.5"></i>Документация</a>
    </div>

    <?php echo View::factory('partials/filters', [
        'id'     => 'api-usage',
        'action' => '/prx150_api_hub/usage',
        'fields' => [
            ['type' => 'select', 'name' => 'period', 'label' => 'Период', 'value' => $period,
             'options' => ['24h' => '24 часа', '7d' => '7 дней', '30d' => '30 дней']],
            ['type' => 'select', 'name' => 'client_id', 'label' => 'Ключ', 'value' => (string) $client_id,
             'options' => ['' => 'Все'] + array_column($clients, 'name', 'id')],
            ['type' => 'select', 'name' => 'scope', 'label' => 'Право', 'value' => $scope,
             'options' => ['' => 'Все'] + array_map(static fn ($m) => $m['title'], $scopes)],
            ['type' => 'select', 'name' => 'status', 'label' => 'Статус', 'value' => $status,
             'options' => ['' => 'Все', '2xx' => '2xx — успех', '4xx' => '4xx — ошибка клиента', '5xx' => '5xx — сбой сервиса']],
            ['type' => 'search', 'name' => 'q', 'label' => 'Путь', 'placeholder' => '/api/v1/…', 'value' => $q],
        ],
        'submit' => 'Применить',
        'reset'  => '/prx150_api_hub/usage',
    ]); ?>

    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="prx-card">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Запросов</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo number_format((int) $summary['total'], 0, ',', ' '); ?></p>
        </div>
        <div class="prx-card">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Ошибки 4xx/5xx</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-rose-600 dark:text-rose-400"><?php echo number_format((int) $summary['errors'], 0, ',', ' '); ?></p>
        </div>
        <div class="prx-card">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Среднее время</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) round((float) $summary['avg_ms']); ?> <span class="text-sm font-semibold text-gray-400">мс</span></p>
        </div>
        <div class="prx-card">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Активных ключей</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $summary['clients']; ?></p>
        </div>
    </div>
    <p class="prx-hint mt-2">Период: <?php echo ['24h' => '24 часа', '7d' => '7 дней', '30d' => '30 дней'][$period]; ?> · найдено: <?php echo (int) $total; ?></p>

    <section class="prx-card mt-4 overflow-x-auto p-0" aria-label="Запросы">
        <table class="prx-table min-w-[980px]">
            <thead><tr><th>Время</th><th>Ключ</th><th>Право</th><th>Метод и путь</th><th>Статус</th><th>Время</th><th>Объём</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo date('d.m.Y H:i:s', strtotime((string) $row['created_at'])); ?></td>
                    <td>
                        <span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($row['client_name']); ?></span>
                        <span class="block font-mono text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($row['key_prefix']); ?>…</span>
                    </td>
                    <td><span class="prx-badge prx-badge-info"><?php echo HTML::chars($scopes[$row['scope']]['title'] ?? $row['scope']); ?></span></td>
                    <td><span class="font-mono text-xs text-gray-700 dark:text-gray-200"><?php echo HTML::chars($row['method']); ?> <?php echo HTML::chars($row['path']); ?></span></td>
                    <td><span class="prx-badge <?php echo $status_badge((int) $row['status']); ?>"><span class="prx-dot"></span><?php echo (int) $row['status']; ?></span></td>
                    <td class="tabular-nums"><?php echo (int) $row['duration_ms']; ?> мс</td>
                    <td class="tabular-nums text-xs text-gray-500 dark:text-gray-400"><?php echo number_format((int) $row['response_bytes'] / 1024, 0, ',', ' '); ?> КБ</td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">Запросов за период нет.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php echo View::factory('partials/pagination', [
        'total'   => $total,
        'page'    => $page,
        'pages'   => $pages,
        'per'     => $per,
        'url_base'=> '/prx150_api_hub/usage',
        'params'  => $params,
    ]); ?>
</div>
