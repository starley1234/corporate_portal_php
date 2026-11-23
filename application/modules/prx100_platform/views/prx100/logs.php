<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Логи платформы»: источник → фильтры → счётчики по уровням → лента записей;
 * справа — где ещё живут логи и статусы платформы.
 *
 * Переменные: $source, $result, $days, $day, $threshold, $q, $limit, $sources, $now.
 */
$badge = function (string $level): string {
    $map = [
        'EMERGENCY' => 'prx-badge-err', 'ALERT' => 'prx-badge-err', 'CRITICAL' => 'prx-badge-err', 'ERROR' => 'prx-badge-err',
        'WARNING'   => 'prx-badge-run',
        'NOTICE'    => 'prx-badge-info',
    ];

    return '<span class="prx-badge uppercase '.($map[$level] ?? 'prx-badge-wait').'"><span class="prx-dot"></span>'.HTML::chars($level).'</span>';
};

$fmt_size = function (int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', ' ').' МБ' : number_format($bytes / 1024, 0, ',', ' ').' КБ';
};

$when = function (?int $ts): string {
    return $ts === NULL ? '—' : date('d.m.Y H:i', $ts);
};

$url = function (array $override) use ($source, $day, $threshold, $q, $limit): string {
    return '/prx100/logs?'.http_build_query(array_filter(array_merge([
        'source' => $source, 'day' => $day, 'level' => $threshold, 'q' => $q, 'limit' => $limit,
    ], $override), 'strlen'));
};

$level_groups = [
    ['error', 'ERROR и выше', (int) $result['counts']['ERROR'] + (int) $result['counts']['CRITICAL'] + (int) $result['counts']['ALERT'] + (int) $result['counts']['EMERGENCY'], 'text-rose-600 dark:text-rose-400'],
    ['warning', 'WARNING и выше', (int) $result['counts']['WARNING'], 'text-amber-600 dark:text-amber-400'],
    ['notice', 'NOTICE', (int) $result['counts']['NOTICE'], 'text-blue-600 dark:text-blue-400'],
    ['', 'INFO', (int) $result['counts']['INFO'], 'text-gray-900 dark:text-white'],
    ['', 'DEBUG', (int) $result['counts']['DEBUG'], 'text-gray-500 dark:text-gray-400'],
];
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Администрирование', '/admin'], ['Хвост ошибок', '/prx100/errors'], ['Логи платформы']],
        'title'   => 'Логи платформы',
        'pill'    => ['prx100 · только чтение', 'fa-terminal'],
        'lead'    => 'Журнал сайта и php-лог Kohana: по дням, уровням и подстроке. На момент: '.$now.'. Ошибки модулей и очереди — в «Хвосте ошибок».',
        'actions' => [
            ['label' => 'Обновить', 'href' => $url([]), 'icon' => 'fa-rotate', 'role' => 'secondary'],
            ['label' => 'Хвост ошибок', 'href' => '/prx100/errors', 'icon' => 'fa-heart-crack', 'role' => 'secondary'],
        ],
    ]); ?>

    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
        <div class="prx-seg" role="tablist" aria-label="Источник логов">
            <a class="prx-seg-item<?php echo $source === 'site' ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $source === 'site' ? 'true' : 'false'; ?>" href="<?php echo HTML::chars($url(['source' => 'site', 'day' => ''])); ?>"><i class="fas fa-file-lines mr-1.5"></i>Журнал сайта · prx-site.log</a>
            <a class="prx-seg-item<?php echo $source === 'kohana' ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $source === 'kohana' ? 'true' : 'false'; ?>" href="<?php echo HTML::chars($url(['source' => 'kohana'])); ?>"><i class="fas fa-code mr-1.5"></i>Php-лог Kohana · по дням</a>
        </div>
        <p class="prx-hint">
            <?php if ($source === 'site'): ?>
                <?php echo $result['exists'] ? 'Файл '.HTML::chars(basename($result['file'])).' · '.$fmt_size((int) $result['size']).' · изменён '.$when($result['modified']).' · резервных копий: '.count($result['backups']) : 'Файла ещё нет: Log_Plain пишет при первом событии.'; ?>
            <?php else: ?>
                Дни с php-логом: <?php echo count($days); ?> · <?php echo $result['exists'] ? HTML::chars(str_replace(APPPATH, 'application/', $result['file'])).' · '.$fmt_size((int) $result['size']) : 'за этот день файла нет'; ?>
            <?php endif; ?>
        </p>
    </div>

    <?php echo View::factory('partials/filters', [
        'id'     => 'logs',
        'action' => '/prx100/logs',
        'fields' => array_values(array_filter([
            $source === 'kohana'
                ? ['type' => 'select', 'name' => 'day', 'label' => 'День', 'value' => $day, 'options' => $days ? array_column($days, 'label', 'day') : [$day => $day]]
                : NULL,
            ['type' => 'select', 'name' => 'level', 'label' => 'Уровень', 'value' => $threshold, 'options' => ['' => 'Все', 'notice' => 'NOTICE и выше', 'warning' => 'WARNING и выше', 'error' => 'ERROR и выше']],
            ['type' => 'search', 'name' => 'q', 'label' => 'Подстрока', 'placeholder' => 'run_id, класс исключения, модуль…', 'value' => $q],
            ['type' => 'select', 'name' => 'limit', 'label' => 'Строк', 'value' => (string) $limit, 'options' => [50 => '50', 200 => '200', 500 => '500', 1000 => '1000']],
        ])),
        'submit' => 'Применить',
    ]); ?>

    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
        <?php foreach ($level_groups as $g): ?>
        <a href="<?php echo HTML::chars($url(['level' => $g[0]])); ?>" class="prx-card transition hover:border-blue-400 dark:hover:border-blue-700">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?php echo HTML::chars($g[1]); ?></p>
            <p class="mt-1 text-2xl font-bold tabular-nums <?php echo $g[3]; ?>"><?php echo (int) $g[2]; ?></p>
        </a>
        <?php endforeach; ?>
    </div>
    <p class="prx-hint mt-2">Просмотрено записей: <?php echo (int) $result['scanned']; ?> · подходит под фильтр: <?php echo (int) $result['matched']; ?><?php echo $result['scanned'] > 0 && $result['matched'] > $result['shown'] ? ' · показано свежих: '.$result['shown'] : ''; ?></p>

    <div class="mt-5 gap-6 space-y-6 lg:grid lg:grid-cols-3 lg:items-start lg:space-y-0">
        <section class="prx-card lg:col-span-2" aria-label="Записи лога">
            <h2 class="prx-h3"><i class="fas fa-stream mr-1.5 text-gray-400" aria-hidden="true"></i>Записи <span class="prx-count ml-1"><?php echo (int) $result['shown']; ?></span></h2>
            <?php if (empty($result['entries'])): ?>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400"><i class="fas fa-circle-check mr-1 text-emerald-500"></i><?php echo $result['scanned'] === 0 ? 'Записей в этом источнике нет.' : 'Под фильтр ничего не попало.'; ?></p>
            <?php else: ?>
                <ul class="mt-3 space-y-2">
                    <?php foreach ($result['entries'] as $e): ?>
                    <li class="rounded-lg border border-gray-100 p-3 dark:border-gray-700/60">
                        <div class="flex flex-wrap items-center gap-2">
                            <?php echo $badge($e['level']); ?>
                            <span class="font-mono text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($e['time']); ?></span>
                        </div>
                        <p class="mt-1.5 break-words font-mono text-xs leading-5 text-gray-700 dark:text-gray-200"><?php echo HTML::chars($e['msg']); ?></p>
                        <?php if ($e['trace']): ?>
                        <details class="mt-1.5">
                            <summary class="cursor-pointer text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">трассировка (<?php echo count($e['trace']); ?>)</summary>
                            <pre class="mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-900 p-3 text-xs leading-4 text-gray-100"><?php echo HTML::chars(implode("\n", $e['trace'])); ?></pre>
                        </details>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($result['matched'] > $result['shown']): ?>
                    <a class="prx-btn-secondary prx-btn-sm mt-3" href="<?php echo HTML::chars($url(['limit' => min(1000, $limit * 2)])); ?>">Показать ещё (до <?php echo min(1000, $limit * 2); ?>)</a>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <aside class="space-y-4">
            <section class="prx-card" aria-labelledby="prx100-log-sources">
                <h2 id="prx100-log-sources" class="prx-h3">Где что пишется</h2>
                <dl class="mt-3 space-y-3">
                    <?php foreach ($sources as $s): ?>
                    <div>
                        <dt class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                            <i class="<?php echo HTML::chars($s['icon']); ?> w-4 text-gray-400" aria-hidden="true"></i>
                            <?php if ( ! empty($s['href'])): ?><a class="prx-link" href="<?php echo HTML::chars($s['href']); ?>"><?php echo HTML::chars($s['title']); ?></a><?php else: ?><?php echo HTML::chars($s['title']); ?><?php endif; ?>
                            <?php if ($s['key'] === 'site' OR $s['key'] === 'kohana' OR $s['key'] === 'workers'): ?>
                                <?php if ($s['exists']): ?><span class="prx-badge prx-badge-ok"><span class="prx-dot"></span><?php echo $fmt_size((int) $s['size']); ?></span><?php else: ?><span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>нет файла</span><?php endif; ?>
                            <?php endif; ?>
                        </dt>
                        <dd class="mt-0.5 pl-6"><code class="font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($s['path']); ?></code><p class="prx-hint mt-0.5"><?php echo HTML::chars($s['note']); ?></p></dd>
                    </div>
                    <?php endforeach; ?>
                </dl>
            </section>
            <section class="prx-card">
                <h2 class="prx-h3">Правила</h2>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <li><i class="fas fa-bolt mr-1.5 w-4 text-gray-400" aria-hidden="true"></i>Срочное — <code>Log_Plain::event()</code>: пишет на диск сразу, а не в конце запроса.</li>
                    <li><i class="fas fa-user-shield mr-1.5 w-4 text-gray-400" aria-hidden="true"></i>Без секретов, приватного контента и идентификаторов анонимных обращений.</li>
                    <li><i class="fas fa-rotate mr-1.5 w-4 text-gray-400" aria-hidden="true"></i>Журнал сайта ротируется по 5 МБ (.1 … .5); старые php-логи — <a class="prx-link" href="/prx100/cleanup">чистка</a>.</li>
                </ul>
            </section>
        </aside>
    </div>
</div>
