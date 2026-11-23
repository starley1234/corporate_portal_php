<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx115/index — «Семантика»: коллекции, живой статус индексации, проверка
 * эмбеддинг-сервера, тестовый поиск и очередь канала semantic.index.
 *
 * Действия — ajax через /prx115/api/*: кнопка «Проверить», тестовый поиск,
 * «Повторить» задачу. Статус-ряд индексации — общий partials/live_status
 * (этапы, секундомер, «нет пульса», авто-перезагрузка на финале).
 */

$collections = (array) ($panel['collections'] ?? []);
$totals      = (array) ($panel['totals'] ?? []);
$queue       = (array) ($panel['queue'] ?? []);
$health      = (array) ($panel['health'] ?? []);
$jobs        = (array) ($queue['jobs'] ?? []);
$panel_error = (string) ($panel['error'] ?? '');

$n     = function ($v) { return number_format((float) $v, 0, ',', ' '); };
$when  = function ($ts) { return $ts ? Date::formatted_time((string) $ts, 'd.m.y H:i') : '—'; };

$status_ru = [
    'pending'    => ['Ожидает', 'prx-badge-wait'],
    'processing' => ['Выполняется', 'prx-badge-run'],
    'done'       => ['Готово', 'prx-badge-ok'],
    'failed'     => ['Ошибка', 'prx-badge-err'],
    'cancelled'  => ['Отменена', 'prx-badge-wait'],
];

$engine = (string) ($health['engine'] ?? '');
$busy   = (int) ($queue['pending'] ?? 0) + (int) ($queue['processing'] ?? 0);

$searches    = (array) ($panel['searches'] ?? []);
$search_stat = (array) ($panel['search_stats'] ?? []);

/* Ячейка журнала «Нашлось»: попадания, «пусто» и «ошибка» — три разных случая. */
$found_html = function (array $s_row) {
    if ($s_row['error'] !== '')
    {
        return '<span class="prx-badge prx-badge-err" title="'.HTML::chars($s_row['error']).'"><span class="prx-dot"></span>ошибка</span>';
    }

    if ((int) $s_row['results'] === 0)
    {
        return '<span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>пусто</span>';
    }

    $html = '<span class="font-semibold text-gray-900 dark:text-white">'.(int) $s_row['results'].'</span>'
          . '<span class="text-gray-400">('.((int) $s_row['vector_hits']).' по смыслу';

    if ((int) $s_row['text_hits'] > 0)
    {
        $html .= ', '.((int) $s_row['text_hits']).' по словам';
    }

    return $html.')</span>';
};
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dashboard" class="transition hover:text-emerald-600 dark:hover:text-emerald-400">Кабинет</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Семантика · поиск и векторы</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2">
        <h1 class="prx-h1">
            <i class="fas fa-magnifying-glass-chart mr-2 text-emerald-600 dark:text-emerald-400"></i>Семантический поиск
        </h1>
        <span class="prx-count">Векторы платформы</span>
        <?php if ($engine !== ''): ?>
        <span class="prx-count font-mono" title="Как ищет PostgreSQL: pgvector (&lt;=&gt;) или косинусная функция prx_115_vec_dist">
            <?php echo HTML::chars($engine === 'pgvector' ? 'pgvector · HNSW' : 'real[] · косинус'); ?>
        </span>
        <?php endif; ?>
        <?php if ($busy > 0): ?>
        <span class="prx-badge prx-badge-info" id="prx115-busy"><span class="prx-dot"></span>в очереди: <?php echo (int) $busy; ?></span>
        <?php endif; ?>
    </div>

    <p class="prx-hint mt-1.5 max-w-3xl">
        Единое хранилище векторов: модули ставят тексты в индексацию (очередь <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-sm dark:bg-gray-900">semantic.index</code>),
        воркер считает эмбеддинги и пишет их в PostgreSQL, поиск — один вызов сервера плюс KNN.
    </p>

    <?php if ($panel_error !== ''): ?>
    <div class="prx-flash prx-flash-err mt-4 font-semibold block">
        Панель собрана частично: <?php echo HTML::chars($panel_error); ?>
    </div>
    <?php endif; ?>

    <?php /* 4 метрики: 2×2 на телефоне, 4 в ряд на широком экране. */ ?>
    <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="prx-card p-3.5">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Коллекции</p>
            <p class="mt-0.5 text-2xl font-black text-gray-900 dark:text-white" id="prx115-m-collections"><?php echo $n($totals['collections'] ?? 0); ?></p>
            <p class="mt-0.5 text-sm text-gray-400">типов сущностей</p>
        </div>
        <div class="prx-card p-3.5">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Векторы</p>
            <p class="mt-0.5 text-2xl font-black text-gray-900 dark:text-white" id="prx115-m-vectors"><?php echo $n($totals['vectors'] ?? 0); ?></p>
            <p class="mt-0.5 text-sm text-gray-400">проиндексировано</p>
        </div>
        <div class="prx-card p-3.5">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">За 24 ч</p>
            <p class="mt-0.5 text-2xl font-black text-gray-900 dark:text-white" id="prx115-m-done"><?php echo $n($queue['done_24h'] ?? 0); ?></p>
            <p class="mt-0.5 text-sm text-gray-400">индексаций готово</p>
        </div>
        <div class="prx-card p-3.5">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Ошибки</p>
            <p class="mt-0.5 text-2xl font-black <?php echo (int) ($queue['failed_24h'] ?? 0) > 0 ? 'text-rose-500' : 'text-gray-900 dark:text-white'; ?>" id="prx115-m-failed"><?php echo $n($queue['failed_24h'] ?? 0); ?></p>
            <p class="mt-0.5 text-sm text-gray-400">за 24 ч</p>
        </div>
    </div>

    <div class="mt-5 gap-5 space-y-5 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <div class="space-y-5 md:col-span-2">

            <?php /* Живой статус последней задачи канала: идёт / Готово / Ошибка. */ ?>
            <?php if ( ! empty($queue['ok'])): ?>
            <div class="prx-card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="prx-h3">Индексация</h2>
                    <a href="/prx120/monitor" class="text-sm font-semibold text-emerald-600 hover:underline dark:text-emerald-400">Монитор воркеров →</a>
                </div>
                <?php echo View::factory('partials/live_status', [
                    'url'   => '/prx115/api/status',
                    'title' => 'Индексация векторов',
                    'note'  => 'Считает воркер: вектор на текст через эмбеддинг-сервер. Этапы видно здесь, без перезагрузки.',
                ]); ?>
            </div>
            <?php else: ?>
            <div class="prx-flash prx-flash-warn block">
                Очередь prx120 не подключена — задачи индексации ставить некуда. Проверьте модуль `prx120_jobs`.
            </div>
            <?php endif; ?>

            <?php /* Коллекции: что и сколько проиндексировано. */ ?>
            <div class="prx-card p-0">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
                    <div class="flex items-center gap-2">
                        <h2 class="prx-h3">Коллекции</h2>
                        <span class="prx-count"><?php echo count($collections); ?></span>
                    </div>
                    <button type="button" id="prx115-refresh" class="prx-btn-secondary prx-btn-sm">
                        <i class="fas fa-rotate"></i>Обновить
                    </button>
                </div>
                <div class="overflow-x-auto prx-scrollbar" style="scrollbar-width:thin">
                    <table class="prx-table">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                                <th class="py-2.5 pr-3 pl-4">Коллекция</th>
                                <th class="py-2.5 px-3">Векторов</th>
                                <th class="py-2.5 px-3">Сущностей</th>
                                <th class="py-2.5 px-3">Модель</th>
                                <th class="py-2.5 pr-4 pl-3">Обновлено</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60" id="prx115-collections">
                            <?php foreach ($collections as $c): ?>
                            <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                                <td class="py-2.5 pr-3 pl-4">
                                    <span class="font-mono font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($c['name']); ?></span>
                                    <?php if ($c['description'] !== ''): ?>
                                    <span class="block text-sm text-gray-400"><?php echo HTML::chars($c['description']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3 font-semibold <?php echo $c['vectors'] > 0 ? 'text-gray-900 dark:text-white' : 'text-amber-600 dark:text-amber-400'; ?>"><?php echo $n($c['vectors']); ?></td>
                                <td class="py-2.5 px-3 text-gray-600 dark:text-gray-300"><?php echo $n($c['entities']); ?></td>
                                <td class="py-2.5 px-3 font-mono text-gray-500 dark:text-gray-400"><?php echo HTML::chars($c['model'] !== '' ? $c['model'] : '—'); ?><?php if ((int) $c['dims'] > 0): ?><span class="text-gray-400"> · <?php echo (int) $c['dims']; ?></span><?php endif; ?></td>
                                <td class="py-2.5 pr-4 pl-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($when($c['updated_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if ( ! $collections): ?>
                            <tr><td colspan="5" class="py-4 pl-4 text-sm text-gray-400">Векторов пока нет: модули ещё не ставили тексты в индексацию.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php /* Покрытие источников: что ДОЛЖНО быть в индексе и чего не хватает.
                     Источники описаны в application/config/prx115.php → sources,
                     поэтому новый модуль попадает сюда без правки этого вида.
                     Кнопка «Догнать» ставит в очередь только то, что изменилось
                     (сравнение по хешу текста) — токены не жгутся зря. */ ?>
            <div class="prx-card p-0">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
                    <div class="flex items-center gap-2">
                        <h2 class="prx-h3">Источники индексации</h2>
                        <span id="prx115-sources" class="prx-count"><?php echo count($health['coverage'] ?? []); ?></span>
                        <?php if (isset($health['coverage_min']) AND $health['coverage_min'] !== NULL): ?>
                        <?php $cmin = (float) $health['coverage_min']; ?>
                        <span class="text-sm font-semibold <?php echo $cmin >= 99.5 ? 'text-emerald-600 dark:text-emerald-400' : ($cmin >= 90 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400'); ?>">
                            покрытие <?php echo number_format($cmin, 1, ',', ' '); ?> %
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="prx115-catch-all" class="prx-btn-process prx-btn-sm">
                            <i class="fas fa-arrows-rotate"></i>Догнать
                        </button>
                        <button type="button" id="prx115-prune" class="prx-btn-danger prx-btn-sm">
                            <i class="fas fa-broom"></i>Убрать мусор
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto prx-scrollbar" style="scrollbar-width:thin">
                    <table class="prx-table">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                                <th class="py-2.5 pr-3 pl-4">Источник</th>
                                <th class="py-2.5 px-3">Записей</th>
                                <th class="py-2.5 px-3">Векторов</th>
                                <th class="py-2.5 px-3">Актуальных</th>
                                <th class="py-2.5 px-3" title="нет вектора / текст изменился">Отстают</th>
                                <th class="py-2.5 px-3" title="вектор есть, записи в источнике уже нет">Мусор</th>
                                <th class="py-2.5 px-3">Покрытие</th>
                                <th class="py-2.5 pr-4 pl-3 text-right">Действие</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60" id="prx115-coverage">
                            <?php foreach (($health['coverage'] ?? []) as $src): ?>
                            <?php
                                $pct = $src['coverage'] === NULL ? NULL : (float) $src['coverage'];
                                $lag = (int) $src['missing'] + (int) $src['stale'];
                                $pct_color = $pct === NULL ? 'text-gray-400' : ($pct >= 99.5 ? 'text-emerald-600 dark:text-emerald-400' : ($pct >= 90 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400'));
                            ?>
                            <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                                <td class="py-2.5 pr-3 pl-4">
                                    <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($src['title']); ?></span>
                                    <span class="block font-mono text-sm text-gray-400"><?php echo HTML::chars($src['collection']); ?> / <?php echo HTML::chars($src['entity']); ?></span>
                                    <?php if ($src['error'] !== ''): ?>
                                    <span class="block max-w-[26rem] text-sm text-rose-500"><?php echo HTML::chars($src['error']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3 text-gray-600 dark:text-gray-300"><?php echo $n($src['total']); ?></td>
                                <td class="py-2.5 px-3 text-gray-600 dark:text-gray-300"><?php echo $n($src['have']); ?></td>
                                <td class="py-2.5 px-3 font-semibold text-gray-900 dark:text-white"><?php echo $n($src['fresh']); ?></td>
                                <td class="py-2.5 px-3 <?php echo $lag > 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-gray-400'; ?>"><?php echo $n($src['missing']); ?> / <?php echo $n($src['stale']); ?></td>
                                <td class="py-2.5 px-3 <?php echo (int) $src['orphans'] > 0 ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-gray-400'; ?>"><?php echo $n($src['orphans']); ?></td>
                                <td class="py-2.5 px-3 font-bold <?php echo $pct_color; ?>"><?php echo $pct === NULL ? '—' : number_format($pct, 1, ',', ' ').' %'; ?></td>
                                <td class="py-2.5 pr-4 pl-3 text-right">
                                    <?php if ($lag > 0): ?>
                                    <button type="button" class="prx-btn-process prx-btn-sm prx115-catch" data-collection="<?php echo HTML::chars($src['collection']); ?>">Догнать</button>
                                    <?php else: ?>
                                    <span class="text-sm text-emerald-600 dark:text-emerald-400">актуально</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ( ! ($health['coverage'] ?? [])): ?>
                <p class="px-4 py-3 text-sm text-gray-400" id="prx115-coverage-empty">
                    Источники не описаны. Добавьте их в <code class="rounded bg-gray-100 px-1 py-0.5 font-mono dark:bg-gray-900">application/config/prx115.php</code> → <code class="rounded bg-gray-100 px-1 py-0.5 font-mono dark:bg-gray-900">sources</code>: SQL даёт id, текст и метаданные, а модуль сам скажет, всё ли проиндексировано.
                </p>
                <?php else: ?>
                <p class="hidden px-4 py-3 text-sm text-gray-400" id="prx115-coverage-empty">Источники не описаны.</p>
                <?php endif; ?>
                <p class="border-t border-gray-100 px-4 py-2.5 text-sm text-gray-500 dark:border-gray-700/60 dark:text-gray-400" id="prx115-cov-status">
                    «Записей» — сколько текстов в источнике; «Актуальных» — у скольких вектор совпадает с текущим текстом.
                </p>
            </div>

            <?php /* Тестовый поиск: главная проверка «поиск возвращает результаты». */ ?>
            <div class="prx-card">
                <h2 class="prx-h3">Проверка поиска</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                    Запрос уходит на эмбеддинг-сервер и ищется KNN по выбранной коллекции — так же, как это делают модули.
                    <?php $sc = $health['search'] ?? []; ?>
                    Порог расстояния <?php echo HTML::chars((string) ($sc['max_distance'] ?? '—')); ?>,
                    <?php echo ! empty($sc['hybrid']) ? 'если векторный поиск пуст — фолбэк по словам' : 'без текстового фолбэка'; ?>.
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <select id="prx115-collection" class="prx-input prx-select h-10 min-w-[12rem] text-sm min-h-0">
                        <?php foreach ($collections as $c): ?>
                        <option value="<?php echo HTML::chars($c['name']); ?>"><?php echo HTML::chars($c['name']); ?> (<?php echo $n($c['vectors']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="prx115-query" placeholder="например: бюджет на квартал" class="prx-input h-10 min-w-[14rem] flex-1 text-sm min-h-0">
                    <button type="button" id="prx115-search" class="prx-btn-process">
                        <i class="fas fa-magnifying-glass"></i>Найти
                    </button>
                </div>

                <div id="prx115-search-status" class="mt-2.5 text-sm text-gray-500 dark:text-gray-400"></div>
                <div id="prx115-results" class="mt-2.5 space-y-2"></div>
            </div>

            <?php /* Журнал поисков: видно, ЧТО искали и ЧТО нашлось — «поиск реально находит?» */ ?>
            <div class="prx-card p-0">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
                    <div class="flex items-center gap-2">
                        <h2 class="prx-h3">Поисковые запросы</h2>
                        <span id="prx115-searches-total" class="prx-count <?php echo (int) ($search_stat['total'] ?? 0) > 0 ? '' : 'hidden'; ?>" title="За последние 24 часа">
                            за сутки: <?php echo $n($search_stat['total'] ?? 0); ?>
                        </span>
                    </div>
                    <span id="prx115-searches-note" class="text-sm text-gray-500 dark:text-gray-400"><?php
                        if ((int) ($search_stat['total'] ?? 0) > 0)
                        {
                            echo 'пустых: <b>'.$n($search_stat['empty'] ?? 0).'</b> · '
                               . 'ошибок: <b class="'.((int) ($search_stat['errors'] ?? 0) > 0 ? 'text-rose-500' : '').'">'.$n($search_stat['errors'] ?? 0).'</b> · '
                               . 'из кэша: <b>'.$n($search_stat['cached'] ?? 0).'</b> · '
                               . 'среднее: <b>'.$n($search_stat['avg_ms'] ?? 0).'</b> мс';
                        }
                    ?></span>
                </div>

                <div class="overflow-x-auto prx-scrollbar" style="scrollbar-width:thin">
                    <table class="prx-table">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                                <th class="py-2.5 pr-3 pl-4">Когда</th>
                                <th class="py-2.5 px-3">Запрос</th>
                                <th class="py-2.5 px-3">Коллекция</th>
                                <th class="py-2.5 px-3">Нашлось</th>
                                <th class="py-2.5 pr-4 pl-3">Время</th>
                            </tr>
                        </thead>
                        <tbody id="prx115-searches" class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                            <?php foreach ($searches as $s_row): ?>
                            <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                                <td class="py-2.5 pr-3 pl-4 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($when($s_row['created_at'])); ?></td>
                                <td class="max-w-[22rem] truncate py-2.5 px-3 text-gray-800 dark:text-gray-200" title="<?php echo HTML::chars($s_row['query']); ?>"><?php echo HTML::chars($s_row['query']); ?></td>
                                <td class="py-2.5 px-3 font-mono text-gray-600 dark:text-gray-300"><?php echo HTML::chars($s_row['collection']); ?></td>
                                <td class="py-2.5 px-3"><?php echo $found_html($s_row); ?></td>
                                <td class="py-2.5 pr-4 pl-3 text-gray-500 dark:text-gray-400">
                                    <?php if ($s_row['cached']): ?>
                                    <span title="Вектор запроса взят из кэша — эмбеддинг-сервер не вызывался">из кэша</span>
                                    <?php else: ?>
                                    <?php echo (int) $s_row['ms']; ?> мс
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p id="prx115-searches-empty" class="<?php echo $searches ? 'hidden' : ''; ?> px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                        Запросов ещё не было. Проверьте поиск выше — он делает ровно то, что делают модули,
                        и запись появится здесь.
                    </p>
                </div>
            </div>

            <?php /* Очередь канала: последние задачи + «Повторить». */ ?>
            <?php if ( ! empty($jobs)): ?>
            <div class="prx-card p-0">
                <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
                    <h2 class="prx-h3">Последние задачи канала</h2>
                    <span class="prx-count"><?php echo count($jobs); ?></span>
                </div>
                <div class="overflow-x-auto prx-scrollbar" style="scrollbar-width:thin">
                    <table class="prx-table">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                                <th class="py-2.5 pr-3 pl-4">Задача</th>
                                <th class="py-2.5 px-3">Статус</th>
                                <th class="py-2.5 px-3">Что индексировали</th>
                                <th class="py-2.5 px-3">Попытки</th>
                                <th class="py-2.5 pr-4 pl-3 text-right">Действие</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                            <?php foreach ($jobs as $j): ?>
                            <?php $st = $status_ru[$j['status']] ?? [$j['status'], 'prx-badge-wait']; ?>
                            <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                                <td class="py-2.5 pr-3 pl-4">
                                    <a href="/prx120/task/<?php echo (int) $j['id']; ?>" class="font-mono font-semibold text-emerald-600 hover:underline dark:text-emerald-400">#<?php echo (int) $j['id']; ?></a>
                                    <span class="block text-sm text-gray-400"><?php echo HTML::chars($when($j['finished_at'] !== '' ? $j['finished_at'] : $j['created_at'])); ?></span>
                                </td>
                                <td class="py-2.5 px-3">
                                    <span class="prx-badge <?php echo $st[1]; ?>"><span class="prx-dot"></span><?php echo HTML::chars($st[0]); ?></span>
                                    <?php if ($j['error'] !== ''): ?>
                                    <span class="block max-w-[22rem] truncate text-sm text-rose-500" title="<?php echo HTML::chars($j['error']); ?>"><?php echo HTML::chars($j['error']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3 text-gray-600 dark:text-gray-300">
                                    <span class="font-mono"><?php echo HTML::chars($j['collection']); ?></span>
                                    <span class="text-gray-400">/ <?php echo HTML::chars($j['entity']); ?></span>
                                    <span class="text-gray-400">· <?php echo (int) $j['items']; ?> шт.</span>
                                </td>
                                <td class="py-2.5 px-3 text-gray-500 dark:text-gray-400"><?php echo (int) $j['attempts']; ?>/<?php echo (int) $j['max']; ?></td>
                                <td class="py-2.5 pr-4 pl-3 text-right">
                                    <?php if (in_array($j['status'], ['failed', 'cancelled'], TRUE)): ?>
                                    <button type="button" class="prx-btn-secondary prx-btn-sm prx115-retry" data-id="<?php echo (int) $j['id']; ?>">
                                        Повторить
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php /* Правая колонка: состояние сервисов и что делать, если пусто. */ ?>
        <div class="space-y-5">
            <?php /* Проблемы модуля человеческим языком: сервер, таблицы, покрытие,
                     мусор. Панель должна отвечать не только «сколько», но и «что
                     делать» — иначе владельцу не с чем идти. */ ?>
            <?php if ( ! empty($health['problems'])): ?>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40">
                <h2 class="prx-h3 flex items-center gap-2 text-amber-900 dark:text-amber-200">
                    <i class="fas fa-triangle-exclamation"></i>Что требует внимания
                </h2>
                <ul class="mt-2 space-y-1.5 text-sm text-amber-900 dark:text-amber-100">
                    <?php foreach ($health['problems'] as $problem): ?>
                    <li class="flex gap-2"><span class="text-amber-500">•</span><span><?php echo HTML::chars($problem); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="prx-card">
                <h2 class="prx-h3">Эмбеддинг-сервер</h2>
                <dl class="mt-2.5 space-y-1.5 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-400">Модель</dt>
                        <dd class="font-mono text-gray-700 dark:text-gray-300"><?php echo HTML::chars(($health['embed_model'] ?? '') !== '' ? $health['embed_model'] : '—'); ?></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-400">Размерность</dt>
                        <dd class="text-gray-700 dark:text-gray-300"><?php echo (int) ($health['embed_dims'] ?? 0); ?></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-400">Адрес</dt>
                        <dd class="truncate font-mono text-gray-700 dark:text-gray-300" title="<?php echo HTML::chars((string) ($health['embed_url'] ?? '')); ?>">
                            <?php echo HTML::chars(preg_replace('~^(https?://[^/]+).*$~', '$1', (string) ($health['embed_url'] ?? '')) ?: '—'); ?>
                        </dd>
                    </div>
                </dl>

                <button type="button" id="prx115-check" class="prx-btn-process mt-3 w-full">
                    <i class="fas fa-plug-circle-check"></i>Проверить связь
                </button>
                <div id="prx115-check-status" class="mt-2 text-sm text-gray-500 dark:text-gray-400"></div>
            </div>

            <div class="prx-card">
                <h2 class="prx-h3">Запись векторов</h2>
                <?php if ((int) ($health['bridge_used'] ?? 0) > 0): ?>
                <p class="prx-flash prx-flash-warn mt-2 block">
                    За 7 дней <b><?php echo (int) ($health['bridge_used'] ?? 0); ?></b> задач завершились <b>без индексации</b>: воркер работает в HTTP-режиме и не мог писать в базу.
                    Нажмите «Повторить» у такой задачи — теперь векторы доедут через сайт.
                </p>
                <?php else: ?>
                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">Воркер пишет векторы в PostgreSQL напрямую (режим pg) — самый быстрый путь.</p>
                <?php endif; ?>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Без прямого доступа к базе воркер присылает готовые векторы на сайт
                    (<code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-sm dark:bg-gray-900">POST /prx115/api/vectors</code>) — поиск работает в обоих режимах.
                </p>
            </div>

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 dark:border-gray-700/60 dark:bg-gray-800/40">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">Если поиск пустой</h3>
                </div>
                <ul class="mt-2.5 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <li>• «Векторов 0»: модуль-владелец не ставил тексты в индексацию — он вызывает <code class="font-mono">index_texts()</code>.</li>
                    <li>• «Ошибка» в очереди: смотрите карточку задачи — этап, текст отказа, «Повторить».</li>
                    <li>• «Проверить связь» отвечает ошибкой: эмбеддинг-сервер недоступен или модель не та.</li>
                    <li>• Векторы есть, а результаты нерелевантны: проверьте модель и размерность коллекции — они не должны смешиваться.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>

<script>
/* Действия панели: проверка сервера, тестовый поиск, повтор задачи, обновление
   метрик. Всё — ajax, без перезагрузки страницы (владелец: «ряд появляется сразу»). */
(function () {
    var csrf = <?php echo json_encode((string) $csrf); ?>;

    function api(path, opts) {
        return fetch(path, Object.assign({credentials: 'same-origin', headers: {'X-CSRF-Token': csrf}}, opts || {}))
            .then(function (r) { return r.json().catch(function () { return {ok: false, error: 'Ответ не JSON (HTTP ' + r.status + ')'}; }); });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    /* 1. Проверка эмбеддинг-сервера. */
    var check = document.getElementById('prx115-check');
    var checkOut = document.getElementById('prx115-check-status');

    if (check) {
        check.addEventListener('click', function () {
            check.disabled = true;
            checkOut.className = 'mt-2 text-sm text-gray-500 dark:text-gray-400';
            checkOut.textContent = 'Проверяю эмбеддинг-сервер…';

            api('/prx115/api/check', {method: 'POST'}).then(function (j) {
                var c = (j && j.check) || {};

                if (j && j.ok) {
                    checkOut.className = 'mt-2 text-sm font-semibold text-emerald-600 dark:text-emerald-400';
                    checkOut.textContent = 'Работает: ' + c.dims + ' измерений за ' + c.ms + ' мс' + (c.error ? ' — ' + c.error : '');
                } else {
                    checkOut.className = 'mt-2 text-sm text-rose-600 dark:text-rose-400';
                    checkOut.textContent = (c.error || (j && j.error) || 'Не ответил') + (c.ms ? ' (' + c.ms + ' мс)' : '');
                }
            }).catch(function (e) {
                checkOut.className = 'mt-2 text-sm text-rose-600 dark:text-rose-400';
                checkOut.textContent = 'Сбой запроса: ' + e;
            }).then(function () { check.disabled = false; });
        });
    }

    /* 2. Тестовый поиск. */
    var go = document.getElementById('prx115-search');
    var out = document.getElementById('prx115-results');
    var status = document.getElementById('prx115-search-status');
    var query = document.getElementById('prx115-query');
    var collection = document.getElementById('prx115-collection');

    function runSearch() {
        var q = (query.value || '').trim();
        var c = collection ? collection.value : '';

        if (!q || !c) {
            status.textContent = 'Укажите коллекцию и запрос.';
            return;
        }

        go.disabled = true;
        status.textContent = 'Ищу «' + q + '» в ' + c + '…';
        out.innerHTML = '';

        api('/prx115/api/search?q=' + encodeURIComponent(q) + '&collection=' + encodeURIComponent(c) + '&limit=10').then(function (j) {
            var rows = (j && j.results) || [];

            if (!j || !j.ok) {
                status.className = 'mt-2.5 text-sm text-rose-600 dark:text-rose-400';
                status.textContent = (j && j.error) || 'Поиск не удался';
                reloadSearches();   // ошибка тоже записана в журнал
                return;
            }

            var by_words = rows.length && rows[0].match === 'text';

            status.className = 'mt-2.5 text-sm text-gray-500 dark:text-gray-400';
            status.textContent = rows.length
                ? ('Нашлось ' + rows.length + ' — ' + (by_words
                    ? 'векторный порог не прошёл, показаны совпадения по словам'
                    : 'ближайшее: ' + Number(rows[0].distance).toFixed(4)))
                : 'Ничего не нашлось: в этой коллекции нет похожих текстов.';

            rows.forEach(function (r) {
                var d = document.createElement('div');
                d.className = 'rounded-lg border border-gray-200 bg-gray-50/60 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900/40';
                var chip = r.match === 'text'
                    ? '<span class="prx-count shrink-0">по словам</span>'
                    : '<span class="prx-count shrink-0">вектор</span>';

                d.innerHTML = '<div class="flex items-baseline justify-between gap-3">'
                    + '<span class="font-mono text-gray-500 dark:text-gray-400">' + esc(r.entity) + ' / ' + esc(r.entity_id) + '</span>'
                    + '<span class="flex shrink-0 items-baseline gap-2">' + chip
                    + (r.match === 'text' ? '' : '<span class="font-semibold text-emerald-600 dark:text-emerald-400">' + Number(r.distance).toFixed(4) + '</span>')
                    + '</span>'
                    + '</div><div class="mt-1 text-gray-700 dark:text-gray-300">' + esc((r.content || '').slice(0, 240)) + '</div>';
                out.appendChild(d);
            });

            reloadSearches();       // строка журнала появилась прямо сейчас
        }).catch(function (e) {
            status.className = 'mt-2.5 text-sm text-rose-600 dark:text-rose-400';
            status.textContent = 'Сбой запроса: ' + e;
        }).then(function () { go.disabled = false; });
    }

    if (go) {
        go.addEventListener('click', runSearch);
        query.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); runSearch(); } });
    }

    /* 2б. Журнал поисков: строки поиска появляются сразу после ответа,
       а «Обновить» перечитывает и таблицу, и сводку за сутки. */
    function searchRow(r) {
        var tr = document.createElement('tr');
        tr.className = 'transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25';

        var found;

        if (r.error) {
            found = '<span class="prx-badge prx-badge-err" title="' + esc(r.error) + '"><span class="prx-dot"></span>ошибка</span>';
        } else if (Number(r.results) === 0) {
            found = '<span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>пусто</span>';
        } else {
            found = '<span class="font-semibold text-gray-900 dark:text-white">' + num(r.results) + '</span>'
                + '<span class="text-gray-400">(' + num(r.vector_hits) + ' по смыслу'
                + (Number(r.text_hits) > 0 ? ', ' + num(r.text_hits) + ' по словам' : '') + ')</span>';
        }

        tr.innerHTML = '<td class="py-2.5 pr-3 pl-4 text-gray-500 dark:text-gray-400">' + esc((r.created_at || '').slice(0, 16)) + '</td>'
            + '<td class="max-w-[22rem] truncate py-2.5 px-3 text-gray-800 dark:text-gray-200" title="' + esc(r.query) + '">' + esc(r.query) + '</td>'
            + '<td class="py-2.5 px-3 font-mono text-gray-600 dark:text-gray-300">' + esc(r.collection) + '</td>'
            + '<td class="py-2.5 px-3">' + found + '</td>'
            + '<td class="py-2.5 pr-4 pl-3 text-gray-500 dark:text-gray-400">' + (r.cached ? 'из кэша' : num(r.ms) + ' мс') + '</td>';

        return tr;
    }

    function renderSearches(list, stat) {
        var body = document.getElementById('prx115-searches');
        if (!body) return;

        body.innerHTML = '';
        (list || []).forEach(function (r) { body.appendChild(searchRow(r)); });

        var empty = document.getElementById('prx115-searches-empty');
        if (empty) empty.classList.toggle('hidden', (list || []).length > 0);

        stat = stat || {};

        var total = document.getElementById('prx115-searches-total');
        if (total) {
            total.classList.toggle('hidden', Number(stat.total || 0) === 0);
            total.textContent = 'за сутки: ' + num(stat.total);
        }

        var note = document.getElementById('prx115-searches-note');
        if (note) {
            note.innerHTML = Number(stat.total || 0) > 0
                ? ('пустых: <b>' + num(stat.empty) + '</b>'
                    + ' · ошибок: <b' + (Number(stat.errors) > 0 ? ' class="text-rose-500"' : '') + '>' + num(stat.errors) + '</b>'
                    + ' · из кэша: <b>' + num(stat.cached) + '</b>'
                    + ' · среднее: <b>' + num(stat.avg_ms) + '</b> мс')
                : '';
        }
    }

    function reloadSearches() {
        return api('/prx115/api/panel').then(function (j) {
            if (j && j.ok && j.panel) renderSearches(j.panel.searches, j.panel.search_stats);
        });
    }

    /* 3. Повтор задачи канала. */
    document.querySelectorAll('.prx115-retry').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = 'Ставлю…';

            api('/prx115/api/retry', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({id: parseInt(btn.dataset.id, 10)})
            }).then(function (j) {
                if (j && j.ok) {
                    btn.textContent = '#' + j.job_id + ' в очереди';
                    btn.className = btn.className.replace('text-gray-700', 'text-emerald-600');
                } else {
                    btn.disabled = false;
                    btn.textContent = (j && j.error) ? j.error.slice(0, 40) : 'Не получилось';
                }
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = 'Не получилось';
            });
        });
    });

    /* 4. Обновление метрик и таблицы коллекций — без перезагрузки страницы. */
    var refresh = document.getElementById('prx115-refresh');

    function num(v) { return Number(v || 0).toLocaleString('ru-RU'); }

    function collectionRow(c) {
        var tr = document.createElement('tr');
        tr.className = 'transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25';
        tr.innerHTML = '<td class="py-2.5 pr-3 pl-4"><span class="font-mono font-semibold text-gray-900 dark:text-white">' + esc(c.name) + '</span>'
            + (c.description ? '<span class="block text-sm text-gray-400">' + esc(c.description) + '</span>' : '')
            + '</td>'
            + '<td class="py-2.5 px-3 font-semibold ' + (c.vectors > 0 ? 'text-gray-900 dark:text-white' : 'text-amber-600 dark:text-amber-400') + '">' + num(c.vectors) + '</td>'
            + '<td class="py-2.5 px-3 text-gray-600 dark:text-gray-300">' + num(c.entities) + '</td>'
            + '<td class="py-2.5 px-3 font-mono text-gray-500 dark:text-gray-400">' + esc(c.model || '—') + (c.dims > 0 ? ' · ' + c.dims : '') + '</td>'
            + '<td class="py-2.5 pr-4 pl-3 text-gray-500 dark:text-gray-400">' + esc((c.updated_at || '').slice(0, 16)) + '</td>';
        return tr;
    }

    /* 5. Покрытие источников: сколько текстов ДОЛЖНО быть в индексе и чего не
          хватает. «Догнать» ставит в очередь только изменившееся — список
          считает сайт по хешу текста, лишних вызовов модели нет. */
    var covStatus = document.getElementById('prx115-cov-status');

    function covColor(pct) {
        if (pct === null) return 'text-gray-400';
        if (pct >= 99.5) return 'text-emerald-600 dark:text-emerald-400';
        if (pct >= 90) return 'text-amber-600 dark:text-amber-400';
        return 'text-rose-600 dark:text-rose-400';
    }

    function coverageRow(s) {
        var pct = (s.coverage === null || s.coverage === undefined) ? null : Number(s.coverage);
        var lag = Number(s.missing || 0) + Number(s.stale || 0);
        var tr = document.createElement('tr');
        tr.className = 'transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25';
        tr.innerHTML = '<td class="py-2.5 pr-3 pl-4"><span class="font-semibold text-gray-900 dark:text-white">' + esc(s.title) + '</span>'
            + '<span class="block font-mono text-sm text-gray-400">' + esc(s.collection) + ' / ' + esc(s.entity) + '</span>'
            + (s.error ? '<span class="block text-sm text-rose-500">' + esc(s.error) + '</span>' : '')
            + '</td>'
            + '<td class="py-2.5 px-3 text-gray-600 dark:text-gray-300">' + num(s.total) + '</td>'
            + '<td class="py-2.5 px-3 text-gray-600 dark:text-gray-300">' + num(s.have) + '</td>'
            + '<td class="py-2.5 px-3 font-semibold text-gray-900 dark:text-white">' + num(s.fresh) + '</td>'
            + '<td class="py-2.5 px-3 ' + (lag > 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-gray-400') + '">' + num(s.missing) + ' / ' + num(s.stale) + '</td>'
            + '<td class="py-2.5 px-3 ' + (Number(s.orphans) > 0 ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-gray-400') + '">' + num(s.orphans) + '</td>'
            + '<td class="py-2.5 px-3 font-bold ' + covColor(pct) + '">' + (pct === null ? '—' : pct.toFixed(1).replace('.', ',') + ' %') + '</td>'
            + '<td class="py-2.5 pr-4 pl-3 text-right">' + (lag > 0
                ? '<button type="button" class="prx-btn-process prx-btn-sm prx115-catch" data-collection="' + esc(s.collection) + '">Догнать</button>'
                : '<span class="text-sm text-emerald-600 dark:text-emerald-400">актуально</span>')
            + '</td>';
        return tr;
    }

    function renderCoverage(list) {
        var body = document.getElementById('prx115-coverage');
        var empty = document.getElementById('prx115-coverage-empty');
        var badge = document.getElementById('prx115-sources');

        if (!body) return;

        body.innerHTML = '';
        (list || []).forEach(function (s) { body.appendChild(coverageRow(s)); });
        if (empty) empty.className = (list || []).length ? 'hidden px-4 py-3 text-sm text-gray-400' : 'px-4 py-3 text-sm text-gray-400';
        if (badge) badge.textContent = (list || []).length;

        bindCatch();
    }

    /* «Догнать» — по одному источнику (в строке) или по всем (кнопка в шапке). */
    function catchUp(collection, btn, done) {
        if (btn) { btn.disabled = true; btn.textContent = 'Ставлю…'; }

        api('/prx115/api/reindex', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify(collection ? {collection: collection, limit: 200} : {limit: 200})
        }).then(function (j) {
            if (!j || !j.ok) {
                if (btn) { btn.disabled = false; btn.textContent = 'Догнать'; }
                if (covStatus) covStatus.textContent = 'Не получилось: ' + ((j && j.error) || 'сбой запроса');
                return;
            }

            if (btn) btn.textContent = j.queued > 0 ? ('поставлено: ' + num(j.queued)) : 'догонять нечего';

            if (covStatus) {
                var broken = (j.sources || []).filter(function (s) { return s.error; });

                covStatus.textContent = (collection ? ('«' + collection + '»: ') : '')
                    + 'отставших текстов ' + num(j.items) + ', поставлено в очередь ' + num(j.queued)
                    + ', уже актуальны ' + num(j.skipped) + ', задач ' + num(j.jobs) + '.'
                    + (broken.length
                        ? ' Не удалось проверить источники (' + broken.length + '): '
                          + broken.map(function (s) { return s.title + ' — ' + s.error; }).join('; ')
                        : '');
            }

            if (done) done();
        }).catch(function (e) {
            if (btn) { btn.disabled = false; btn.textContent = 'Догнать'; }
            if (covStatus) covStatus.textContent = 'Сбой запроса: ' + e;
        });
    }

    function bindCatch() {
        document.querySelectorAll('.prx115-catch').forEach(function (btn) {
            btn.addEventListener('click', function () { catchUp(btn.dataset.collection, btn); });
        });
    }

    var catchAll = document.getElementById('prx115-catch-all');

    if (catchAll) {
        catchAll.addEventListener('click', function () { catchUp('', catchAll, loadCoverage); });
    }

    /* «Убрать мусор»: векторы записей, которых в источнике больше нет. */
    var prune = document.getElementById('prx115-prune');

    if (prune) {
        prune.addEventListener('click', function () {
            prune.disabled = true;

            api('/prx115/api/prune', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({older_days: 7})
            }).then(function (j) {
                if (covStatus) {
                    covStatus.textContent = (j && j.ok)
                        ? ('Удалено мусорных векторов: ' + num(j.deleted) + '.')
                        : ('Не получилось: ' + ((j && j.error) || 'сбой запроса'));
                }
                loadCoverage();
            }).catch(function (e) {
                if (covStatus) covStatus.textContent = 'Сбой запроса: ' + e;
            }).then(function () { prune.disabled = false; });
        });
    }

    function loadCoverage() {
        api('/prx115/api/coverage').then(function (j) {
            if (j && j.ok) renderCoverage(j.sources);
        }).catch(function () { /* сеть — остаётся то, что отрисовал сервер */ });
    }

    /* 6. Обновление метрик, коллекций и покрытия — без перезагрузки страницы. */
    var refresh = document.getElementById('prx115-refresh');

    function renderPanel(p) {
        var t = p.totals || {};
        var q = p.queue || {};

        document.getElementById('prx115-m-collections').textContent = num(t.collections);
        document.getElementById('prx115-m-vectors').textContent = num(t.vectors);
        document.getElementById('prx115-m-done').textContent = num(q.done_24h);
        document.getElementById('prx115-m-failed').textContent = num(q.failed_24h);

        var body = document.getElementById('prx115-collections');
        body.innerHTML = '';

        (p.collections || []).forEach(function (c) { body.appendChild(collectionRow(c)); });

        if (!(p.collections || []).length) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="5" class="py-4 pl-4 text-sm text-gray-400">Векторов пока нет: модули ещё не ставили тексты в индексацию.</td>';
            body.appendChild(tr);
        }

        renderCoverage(((p.health || {}).coverage) || []);
        renderSearches(p.searches, p.search_stats);
    }

    if (refresh) {
        refresh.addEventListener('click', function () {
            refresh.disabled = true;

            api('/prx115/api/panel').then(function (j) {
                if (!j || !j.ok) return;
                renderPanel(j.panel || {});
            }).catch(function () { /* сеть — оставляем прежние числа */ })
              .then(function () { refresh.disabled = false; });
        });
    }

    bindCatch();
})();
</script>
