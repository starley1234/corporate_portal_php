<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx595: последняя активность системы — очередь задач с меткой посетителя
 * Visitor::uid(): 'u:<id>' у зарегистрированных, 'g:<ubid>' у гостей.
 */

$who = function ($vid) {
    $vid = (string) $vid;

    if ($vid === '' OR $vid === 'anon')
    {
        return '<span class="text-gray-400">—</span>';
    }

    if (strpos($vid, 'u:') === 0)
    {
        $id = (int) substr($vid, 2);

        return '<a href="/prx595/admin/user/'.$id.'" class="font-mono text-xs font-bold text-blue-600 hover:underline dark:text-blue-400">u:'.$id.'</a>';
    }


    return '<span class="prx-count font-mono" title="Служебный инициатор">'.HTML::chars($vid).'</span>';
};

$status_badge = function ($st) {
    $map = ['done' => 'prx-badge-ok',
            'failed' => 'prx-badge-err',
            'processing' => 'prx-badge-run',
            'pending' => 'prx-badge-wait',
            'cancelled' => 'prx-badge-wait'];

    $labels = [
        'done' => __('Done'),
        'failed' => __('Failed'),
        'processing' => __('Processing'),
        'pending' => __('Pending'),
        'cancelled' => __('Cancelled'),
    ];
    $c = $map[$st] ?? 'prx-badge-wait';
    $label = $labels[$st] ?? (string) $st;

    return '<span class="prx-badge uppercase '.$c.'"><span class="prx-dot"></span>'.HTML::chars($label).'</span>';
};
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/prx595_useradmin" class="transition hover:text-blue-600 dark:hover:text-blue-400">Пользователи</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Активность</span>
    </nav>
    <h1 class="prx-h1 mt-2"><i class="fas fa-chart-line mr-2 text-blue-500"></i>Активность системы</h1>
    <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Все задачи платформы проходят через общую очередь: каждая помечена посетителем — <span class="font-mono">u:&lt;id&gt;</span> у зарегистрированных, <span class="font-mono">g:&lt;ubid&gt;</span> у гостей.</p>

    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="prx-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Задач за 24 ч</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($kpi['d1'] ?? 0); ?></p>
        </div>
        <div class="prx-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">От зарегистрированных</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($kpi['d1_users'] ?? 0); ?></p>
        </div>
        <div class="prx-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">От гостей (ubid)</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($kpi['d1_guests'] ?? 0); ?></p>
        </div>
        <div class="prx-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Уникальных посетителей</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($kpi['d1_visitors'] ?? 0); ?></p>
        </div>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Лента задач (2/3) ============ -->
        <div class="min-w-0 md:col-span-2">
            <div class="prx-card p-0 overflow-x-auto">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h2 class="prx-h3">Последние задачи</h2>
                    <span class="prx-count"><?php echo count($jobs); ?></span>
                </div>
                <table class="prx-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pl-4 pr-3 sm:pl-5">Когда</th>
                            <th class="py-2.5 px-3">Канал</th>
                            <th class="py-2.5 px-3">Статус</th>
                            <th class="py-2.5 px-3">Кто</th>
                            <th class="py-2.5 pr-4 text-right sm:pr-5">Длит.</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                        <?php foreach ($jobs as $j): $sec = ($j['finished_at'] AND $j['created_at']) ? max(1, (int) round((strtotime((string) $j['finished_at']) - strtotime((string) $j['created_at'])))) : 0; ?>
                        <tr class="cursor-pointer transition-colors hover:bg-blue-50/60 dark:hover:bg-blue-950/20" title="Открыть задачу в мониторе воркеров" onclick="location.href='/prx120/monitor?job=<?php echo (int) $j['id']; ?>'">
                            <td class="py-2.5 pl-4 pr-3 text-xs text-gray-500 sm:pl-5"><a href="/prx120/monitor?job=<?php echo (int) $j['id']; ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars(Date::formatted_time((string) $j['created_at'], 'd.m H:i')); ?></a></td>
                            <td class="px-3 py-2.5 font-mono text-xs font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $j['channel']); ?></td>
                            <td class="px-3 py-2.5"><?php echo $status_badge($j['status']); ?></td>
                            <td class="px-3 py-2.5"><?php echo $who($j['visitor_id']); ?></td>
                            <td class="py-2.5 pr-4 text-right font-mono text-xs text-gray-400 sm:pr-5"><?php echo $sec ? $sec.' с' : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($jobs)): ?>
                        <tr><td colspan="5" class="py-6 pl-4 text-center text-sm text-gray-400 sm:pl-5">Задач пока нет.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============ Разрезы (1/3) ============ -->
        <div class="min-w-0 space-y-5 md:col-span-1">
            <div class="prx-card">
                <h3 class="prx-h3 border-b border-gray-100 pb-3 dark:border-gray-700">Каналы за 24 часа</h3>
                <ul class="mt-2.5 space-y-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                    <?php foreach ($channels as $c): ?>
                    <li class="flex items-center justify-between gap-2">
                        <span class="min-w-0 truncate font-mono text-xs" title="<?php echo HTML::chars($c['channel']); ?>"><?php echo HTML::chars((string) $c['channel']); ?></span>
                        <span class="shrink-0 font-mono"><?php echo (int) $c['c']; ?><?php echo (int) $c['guests'] ? ' <span class="text-gray-400">· гост. '.(int) $c['guests'].'</span>' : ''; ?></span>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($channels)): ?><li class="text-gray-400">За сутки задач не было.</li><?php endif; ?>
                </ul>
            </div>

            <div class="prx-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Новые пользователи</h3>
                <ul class="mt-2.5 space-y-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                    <?php foreach ($new_users as $n): ?>
                    <li class="flex items-center justify-between gap-2">
                        <a href="/prx595/admin/user/<?php echo (int) $n['id']; ?>" class="min-w-0 truncate font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($n['username']); ?></a>
                        <span class="shrink-0 text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $n['created_at'], 'd.m.y')); ?></span>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($new_users)): ?><li class="text-gray-400">Пока никого.</li><?php endif; ?>
                </ul>
            </div>

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-cookie-bite text-amber-500"></i>
                    <h3 class="text-sm font-bold">Про гостей</h3>
                </div>
                <p class="mt-2 text-xs text-gray-600 sm:text-sm dark:text-gray-300">Гостям при первом действии ставится постоянная кука <span class="font-mono text-xs">ubid</span> (32 символа, живёт год) — это и есть метка <span class="font-mono text-xs">g:…</span> в ленте. Она не персональные данные и не IP: связать разные браузеры по ней нельзя.</p>
            </div>
        </div>
    </div>
</div>
