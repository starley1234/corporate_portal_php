<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Админка MCP Hub — четыре страницы вместо одной простыни.
 *
 * Было: одна страница на тысячу строк, где рядом жили сборка, смысловой индекс,
 * перевод на русский, спрос агентов, таблица карточек и журнал прогонов. В ней
 * нельзя было найти нужное, а каждый заход читал всю базу карточек.
 *
 * Стало: вкладки (`?tab=build|cards|quality|demand|runs`), у каждой свой смысл и
 * свои запросы. Оболочка (шапка, вкладки, показатели каталога) — общая, тело
 * вкладки лежит отдельным файлом `views/676/admin/<tab>.php`.
 *
 * Почему `?tab=` а не `/mcphub/admin/quality`: так же устроены админки других
 * модулей платформы (nj597, nj645), и старые ссылки `?run=N` / `?slug=...`
 * продолжают работать — они сами открывают нужную вкладку.
 *
 * Дизайн — эталон /pages/required_design_sample (v6):
 *   шапка partials/page_header · плитки показателей · рабочая область 2/3 + 1/3 ·
 *   статусы бейджами (.n2j-badge-*), вкладки — линиями снизу, без скриптов.
 */
$tab = (string) ($tab ?? 'build');

if ( ! in_array($tab, ['build', 'cards', 'quality', 'demand', 'runs'], TRUE))
{
    $tab = 'build';
}

$form = (array) ($form ?? []);
$error = (string) ($error ?? '');
$get = function ($key, $default = '') use ($form) { return $form[$key] ?? $default; };
// ВНИМАНИЕ: `AND` в PHP связывает СЛАБЕЕ присваивания — `$x = $a AND $b`
// записывает в $x только $a. Здесь это уже стоило бесконечной перезагрузки
// страницы: статус не проверялся вовсе, ряд «Собираем каталог» рисовался для
// завершённого прогона, а JS, увидев «готово», перезагружал страницу по кругу.
$run = $run ?? NULL;
$run_running = ($run AND $run['status'] === 'running');
$run_requested = (int) ($run_requested ?? 0);
$runs = (array) ($runs ?? []);
$stats = array_merge(
    ['servers' => 0, 'tools' => 0, 'operational' => 0, 'fresh' => 0, 'categories' => 0],
    (array) ($stats ?? [])
);
$semantic = (array) ($semantic ?? []);
$translate = (array) ($translate ?? []);
$calls_stats = (array) ($calls_stats ?? []);
$servers = (array) ($servers ?? []);

// Даты в базе — ISO с микросекундами и зоной; человеку нужны «23.10, 13:30».
$fmt_dt = function ($value) {
    $value = trim((string) $value);
    if ($value === '')
    {
        return '—';
    }
    $ts = strtotime($value);

    return $ts ? date('d.m.Y H:i', $ts) : $value;
};
// Длительность прогона: «4 мин 12 с» / «38 с». Пустая строка — прогон ещё идёт.
$fmt_dur = function ($from, $to) {
    if (empty($from) OR empty($to))
    {
        return '';
    }
    $seconds = strtotime((string) $to) - strtotime((string) $from);
    if ($seconds < 0)
    {
        return '';
    }
    if ($seconds < 60)
    {
        return $seconds.' с';
    }
    if ($seconds >= 3600)
    {
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $rest = $seconds % 60;

        return $hours.' ч'.($minutes > 0 ? ' '.$minutes.' мин' : '').(($minutes === 0 AND $rest > 0) ? ' '.$rest.' с' : '');
    }
    $minutes = (int) floor($seconds / 60);
    $rest = $seconds % 60;

    return $rest > 0 ? $minutes.' мин '.$rest.' с' : $minutes.' мин';
};

// Статус прогона → бейдж эталона: цвет только по смыслу.
$run_tones = [
    'running'   => ['run', 'идёт сбор', 'fa-circle-notch n2j-spin'],
    'done'      => ['ok', 'готово', 'fa-circle-check'],
    'fail'      => ['err', 'ошибка', 'fa-triangle-exclamation'],
    'cancelled' => ['wait', 'остановлен', 'fa-circle-stop'],
];
$run_state = $run ? ($run_tones[(string) $run['status']] ?? $run_tones['cancelled']) : NULL;
$run_duration = $run ? $fmt_dur($run['started_at'] ?? '', $run['finished_at'] ?? '') : '';

// Состояние карточки → бейдж (health_tone приходит из Service_Nj676_McpHub::shape).
$health_badges = ['emerald' => 'ok', 'amber' => 'run', 'rose' => 'err'];
// «Последняя сборка завершена» — только про свежий прогон: у старого из журнала
// та же фраза читалась бы как «только что закончилось».
$run_is_latest = ($runs === [] OR ($run AND (int) $runs[0]['id'] === (int) $run['id']));
$run_titles = [
    'done'      => $run_is_latest ? 'Последняя сборка завершена' : 'Сборка завершена',
    'fail'      => 'Сборка завершилась ошибкой',
    'cancelled' => 'Сборка остановлена',
];
// «Такого прогона нет» — только когда он правда не найден И показан другой.
$run_missing = ($run_requested > 0 AND $run AND (int) $run['id'] !== $run_requested);

// Вкладки: подпись, иконка, заголовок страницы, подзаголовок.
$tabs = [
    'build' => [
        'Сборка', 'fa-wand-magic-sparkles',
        'Автосборка каталога',
        'Одна кнопка — и воркер сам находит MCP-серверы на GitHub и в поиске, читает README, '
            .'проверяет удалённые адреса и заполняет каталог. Проверять карточки руками не нужно.',
    ],
    'cards' => [
        'Карточки', 'fa-layer-group',
        'Карточки каталога',
        'Что собрано и в каком состоянии: готовность к вызову, инструменты, схемы, происхождение. '
            .'Ручная правка — редкий путь, автосборка закрывает почти всё.',
    ],
    'quality' => [
        'Поиск и текст', 'fa-language',
        'Как карточки находятся и читаются',
        'Смысловой индекс (векторы) и русские названия с описаниями: две вещи, из-за которых '
            .'агент находит нужный сервер, а человек понимает, что это за сервер.',
    ],
    'demand' => [
        'Спрос агентов', 'fa-comments',
        'Что ищут агенты',
        'Запросы через шлюз, по которым ничего не нашлось. Из этих строк берутся темы автосборки: '
            .'каталог растёт под реальный спрос, а не под догадки.',
    ],
    'runs' => [
        'Прогоны', 'fa-clock-rotate-left',
        'Прогоны сборки',
        'Что сделал каждый прогон: найдено, добавлено, обновлено и пропущено — с причиной по '
            .'каждой карточке, без прокрутки логов воркера.',
    ],
];
$tab_meta = $tabs[$tab];

// Показатели каталога считаем одинаково с витриной и шлюзом: готовность — через
// readiness(), а не по одному health_status (иначе цифры расходятся).
$ready_count = 0;

try
{
    $ready_count = (int) Service_Nj676_McpHub::ready_stats()['servers'];
}
catch (Throwable $e)
{
    $ready_count = 0;
}

$calls_total = 0;
$calls_ok = 0;

foreach ($calls_stats as $row)
{
    $calls_total += (int) ($row['calls'] ?? 0);
    $calls_ok += (int) ($row['ok'] ?? 0);
}

$calls_rate = $calls_total > 0 ? (int) round(100 * $calls_ok / $calls_total) : NULL;

// Счётчики на вкладках: без них страница молчит о том, что там есть работа.
$tab_counts = [
    'cards' => (int) $stats['servers'],
    'quality' => (int) ($translate['pending'] ?? 0),
    'demand' => (int) ($demand_stats['empty'] ?? 0),
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Каталог MCP', '/mcphub'], ['Автосборка', '/mcphub/admin'], [$tab_meta[0]]],
        'title' => $tab_meta[2],
        'pill' => ['Только админ', 'fa-wand-magic-sparkles'],
        'actions' => [
            ['label' => 'Открыть каталог', 'href' => '/mcphub', 'icon' => 'fa-layer-group', 'role' => 'secondary'],
        ],
        'lead' => $tab_meta[3],
    ]); ?>

    <?php // Вкладки: обычные ссылки — работают без JS, запоминаются в закладках. ?>
    <nav class="mt-5 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-700"
         role="tablist" aria-label="Разделы админки MCP Hub">
        <?php foreach ($tabs as $key => $info): $is_on = ($key === $tab); ?>
            <a role="tab" href="/mcphub/admin?tab=<?php echo HTML::chars($key); ?>"
               <?php echo $is_on ? 'aria-current="page"' : ''; ?>
               class="inline-flex min-h-[40px] items-center gap-2 whitespace-nowrap border-b-2 px-3 text-sm font-semibold transition <?php echo $is_on
                    ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400'
                    : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'; ?>">
                <i class="fas <?php echo $info[1]; ?>" aria-hidden="true"></i><?php echo HTML::chars($info[0]); ?>
                <?php if ( ! empty($tab_counts[$key])): ?>
                    <span class="n2j-count"><?php echo (int) $tab_counts[$key]; ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($error !== ''): ?>
        <div class="n2j-flash n2j-flash-err mt-5" role="alert">
            <i class="fas fa-circle-exclamation shrink-0 text-lg" aria-hidden="true"></i>
            <span><?php echo HTML::chars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Показатели каталога: что уже собрано и что реально работает -->
    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="n2j-card">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Серверов</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $stats['servers']; ?></p>
            <p class="n2j-hint mt-0.5">новых за неделю: <?php echo (int) $stats['fresh']; ?> · категорий: <?php echo (int) $stats['categories']; ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Инструментов</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $stats['tools']; ?></p>
            <p class="n2j-hint mt-0.5">доступны через шлюз <span class="font-mono">/mcphub/mcp</span></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Готовы к вызову</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-700 dark:text-emerald-300"><?php echo (int) $ready_count; ?></p>
            <p class="n2j-hint mt-0.5">без ключа и установки, проверены вживую</p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Вызовов за 7 дней</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $calls_total; ?></p>
            <p class="n2j-hint mt-0.5">
                <?php if ($calls_rate === NULL): ?>
                    шлюзом пока не звали
                <?php else: ?>
                    успешных: <?php echo (int) $calls_rate; ?>%
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php
    // Тело вкладки — обычный include, а не View::factory: так стенд
    // (.tools/nj676_mcphub_stand.php) рендерит вкладки без всей платформы.
    $tab_file = __DIR__.'/admin/'.$tab.'.php';
    ?>
    <?php if (is_file($tab_file)): ?>
        <div class="mt-5">
            <?php include $tab_file; ?>
        </div>
    <?php endif; ?>
</div>
