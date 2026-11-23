<?php defined('SYSPATH') or die('No direct script access.');

/**
 * События портала: сводка → как идёт событие → журнал с фильтрами.
 *
 * Переменные: $summary, $switches, $prefixes, $titles, $result, $filters.
 */
$badge = function (bool $on, string $onText, string $offText): string {
    return $on
        ? '<span class="prx-badge prx-badge-ok"><span class="prx-dot"></span>'.HTML::chars($onText).'</span>'
        : '<span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>'.HTML::chars($offText).'</span>';
};

$count = function (string $text): string {
    return '<span class="prx-count">'.HTML::chars($text).'</span>';
};

$cards = [
    ['За 24 часа', $summary['day'], 'fa-clock', 'событий в журнале'],
    ['За 7 дней', $summary['week'], 'fa-calendar-week', 'событий в журнале'],
    ['Издатели', $summary['publishers'], 'fa-cube', 'модулей публиковали события'],
    ['Подписки', $summary['active'], 'fa-user-tag', 'активных · в уведомления: '.$summary['notifying']],
];

$steps = [
    ['fa-cube', 'Модуль', 'Код публикует событие: <code>Prx_Event::publish(\'prx120.job_done\', [...])</code> или legacy <code>Event::fire()</code>.',
        $count('за неделю: '.$summary['week'])],
    ['fa-bolt', 'Шина prx208', 'Слушатели в коде получают payload; каждое событие остаётся в журнале <code>prx_208_events</code>.',
        $badge($switches['journal'], 'журнал: вкл', 'журнал: выкл')],
    ['fa-bell', 'Уведомления prx597', 'Мост срабатывает по флагу payload (<code>target_user_id</code>); DB-подписки — отдельный переключатель.',
        $badge($switches['bridge'], 'мост: вкл', 'мост: выкл').' '.$badge($switches['db_subs'], 'DB-подписки: вкл', 'DB-подписки: выкл')],
    ['fa-broom', 'Хранение', 'Старше 90 дней — убирает контракт cleanup манифеста (/prx100/cleanup). Всего в журнале: '.$summary['total'].'.',
        $count('всего: '.$summary['total'])],
];

$prefix_options = ['' => 'Все издатели'];
foreach ($prefixes as $p)
{
    $label = (string) $p['prefix'];
    if (isset($titles[$label]) AND $titles[$label] !== '')
    {
        $label .= ' · '.$titles[$label];
    }
    $prefix_options[(string) $p['prefix']] = $label.' ('.(int) $p['n'].')';
}

$per_options = Service_Prx208_Journal::PER_OPTIONS;
$base_params = ['prefix' => $filters['prefix'], 'q' => $filters['q'], 'initiator' => $filters['initiator']];
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Администрирование', '/admin'], ['События']],
        'title'   => 'События',
        'pill'    => ['prx208 · шина', 'fa-bolt'],
        'lead'    => 'Журнал доменных событий: кто что опубликовал, кто это слушает и уходит ли событие в уведомления. Только чтение.',
        'actions' => [['label' => 'Подписки', 'href' => '/prx208_events/list_subscribers', 'icon' => 'fa-user-tag', 'role' => 'secondary']],
    ]); ?>

    <div class="mt-6 grid grid-cols-2 gap-3 xl:grid-cols-4">
        <?php foreach ($cards as $card): ?>
        <div class="prx-card">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas <?php echo HTML::chars($card[2]); ?> mr-1.5 text-gray-400" aria-hidden="true"></i><?php echo HTML::chars($card[0]); ?></p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $card[1]; ?></p>
            <p class="prx-hint mt-1"><?php echo HTML::chars($card[3]); ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <section class="prx-card mt-6" aria-labelledby="prx208-flow">
        <h2 id="prx208-flow" class="prx-h3">Как идёт событие</h2>
        <ol class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <?php foreach ($steps as $i => $step): ?>
            <li class="relative rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas <?php echo HTML::chars($step[0]); ?> mr-1.5 text-blue-600 dark:text-blue-400" aria-hidden="true"></i><?php echo (int) $i + 1; ?>. <?php echo HTML::chars($step[1]); ?></span>
                    <?php echo $step[3]; ?>
                </div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300"><?php echo $step[2]; ?></p>
                <?php if ($i < count($steps) - 1): ?><i class="fas fa-arrow-right absolute -right-3 top-1/2 hidden -translate-y-1/2 text-gray-300 dark:text-gray-600 xl:block" aria-hidden="true"></i><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <?php echo View::factory('partials/filters', [
        'id'     => 'events',
        'action' => '/prx208_events',
        'fields' => [
            ['type' => 'select', 'name' => 'prefix', 'label' => 'Издатель', 'value' => $filters['prefix'], 'options' => $prefix_options],
            ['type' => 'search', 'name' => 'q', 'label' => 'Имя события', 'placeholder' => 'job_done, file_uploaded…', 'value' => $filters['q']],
            ['type' => 'text', 'name' => 'initiator', 'label' => 'Инициатор (user_id)', 'placeholder' => 'любой', 'value' => $filters['initiator'] > 0 ? (string) $filters['initiator'] : ''],
        ],
        'reset'  => '/prx208_events',
    ]); ?>

    <section class="prx-card mt-4 overflow-x-auto p-0" aria-label="Журнал событий">
        <table class="prx-table min-w-[860px]">
            <thead><tr><th>Когда</th><th>Событие</th><th>Модуль</th><th>Инициатор</th><th>Payload</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr class="align-top">
                    <td class="whitespace-nowrap text-gray-500 dark:text-gray-400"><?php echo HTML::chars($row['when']); ?></td>
                    <td><span class="prx-badge prx-badge-info"><span class="prx-dot"></span><?php echo HTML::chars($row['prefix']); ?></span> <code class="font-mono text-xs text-gray-800 dark:text-gray-100"><?php echo HTML::chars($row['action']); ?></code></td>
                    <td class="text-gray-700 dark:text-gray-200"><?php echo HTML::chars($row['module_title'] !== '' ? $row['module_title'] : '—'); ?></td>
                    <td class="font-mono text-xs text-gray-600 dark:text-gray-300"><?php echo $row['initiator_id'] === NULL ? '<span class="text-gray-400">система</span>' : '#'.(int) $row['initiator_id']; ?></td>
                    <td class="max-w-xs">
                        <?php if ($row['payload_keys']): ?>
                            <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(implode(', ', $row['payload_keys'])); ?></span>
                        <?php else: ?>
                            <span class="text-xs text-gray-400">пусто</span>
                        <?php endif; ?>
                        <details class="mt-1">
                            <summary class="cursor-pointer text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">JSON</summary>
                            <pre class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-900 p-3 text-xs leading-4 text-gray-100"><?php echo HTML::chars($row['payload_text']); ?></pre>
                        </details>
                    </td>
                    <td class="text-right"><a class="prx-btn-secondary prx-btn-sm" href="/prx208_events/view_event/<?php echo (int) $row['id']; ?>">Открыть</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($result['rows'])): ?>
                <tr><td colspan="6" class="text-center text-gray-500 dark:text-gray-400"><?php echo $summary['total'] === 0 ? 'Журнал пуст: события ещё не публиковались или журнал выключен в config/prx208_events.php.' : 'События по фильтру не найдены.'; ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php echo View::factory('partials/pagination', [
        'total'   => $result['total'],
        'page'    => $result['page'],
        'pages'   => $result['pages'],
        'per'     => $result['per'],
        'url_base' => '/prx208_events',
        'params'  => $base_params,
        'per_options' => $per_options,
    ]); ?>
</div>
