<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj600/admin/owner/<key> — карточка владельца: предпочтения (разделы, фразы,
 * источники), последние действия (события, задачи, входы), рекомендации с
 * причинами и адресные правила администратора.
 */

$objects  = isset($objects) ? (array) $objects : [];
$tokens   = isset($tokens) ? (array) $tokens : ['channels' => [], 'days' => [], 'total' => 0, 'jobs' => 0, 'errors' => 0];
$failures = isset($failures) ? (array) $failures : [];
$links    = isset($links) ? (array) $links : [];
$budget   = isset($budget) ? (array) $budget : [];
$n        = function ($v) { return number_format((float) $v, 0, ',', ' '); };

$fmt = function ($ts, $f = 'd.m.y H:i') {
    $ts = (string) $ts;

    if ($ts === '' OR $ts === '0')
    {
        return '—';
    }

    try
    {
        return HTML::chars(Date::formatted_time(ctype_digit($ts) ? '@'.(int) $ts : $ts, $f));
    }
    catch (Throwable $e)
    {
        return HTML::chars($ts);
    }
};

$section_title = function ($section) use ($catalog) {
    return isset($catalog[$section]) ? (string) $catalog[$section]['title'] : (string) $section;
};

$source_badge = function ($source) {
    static $cls = ['rule' => 'n2j-badge-info', 'covisit' => 'n2j-badge-ok', 'group' => 'n2j-badge-wait', 'manual' => 'n2j-badge-run'];

    return '<span class="n2j-badge '.($cls[$source] ?? 'n2j-badge-wait').'">'.HTML::chars(Service_Nj600_Reco::source_label($source)).'</span>';
};

$status_badge = function ($s) {
    static $cls = ['done' => 'n2j-badge-ok', 'failed' => 'n2j-badge-err', 'processing' => 'n2j-badge-run', 'pending' => 'n2j-badge-wait', 'cancelled' => 'n2j-badge-wait'];
    static $ru  = ['done' => 'готово', 'failed' => 'ошибка', 'processing' => 'идёт', 'pending' => 'в очереди', 'cancelled' => 'отменена'];

    return '<span class="n2j-badge '.($cls[$s] ?? 'n2j-badge-wait').'">'.HTML::chars($ru[$s] ?? $s).'</span>';
};

$is_user = ($user !== NULL);
$name    = $is_user ? $user['username'] : $key;
$max_sec = $profile ? (int) max(1, (int) max($profile['sections'] ?: [1])) : 1;
$max_day = $days ? max(1, max($days)) : 1;
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dashboard" class="transition hover:text-blue-600 dark:hover:text-blue-400">Кабинет</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <?php if ($is_user): ?>
        <a href="/nj595/admin" class="transition hover:text-blue-600 dark:hover:text-blue-400">Пользователи</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <a href="/nj595/admin/user/<?php echo (int) $user['id']; ?>" class="transition hover:text-blue-600 dark:hover:text-blue-400"><?php echo HTML::chars($user['username']); ?></a>
        <?php else: ?>
        <a href="/nj600/admin" class="transition hover:text-blue-600 dark:hover:text-blue-400">Предпочтения</a>
        <?php endif; ?>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Центр контроля</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2.5">
            <h1 class="n2j-h1"><i class="fas fa-brain mr-2 text-blue-500"></i><?php echo HTML::chars($name); ?></h1>
            <span class="rounded-full bg-gray-100 px-2.5 py-1 font-mono text-xs font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300"><?php echo HTML::chars($key); ?></span>
            <?php if ( ! $is_user): ?><span class="n2j-badge n2j-badge-wait">гость (кука ubid)</span><?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if ($is_user): ?>
            <a href="/nj595/admin/user/<?php echo (int) $user['id']; ?>" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-user-gear mr-1.5 text-xs text-gray-400"></i>Карточка пользователя</a>
            <?php endif; ?>
            <a href="/nj600/admin/reco" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-wand-magic-sparkles mr-1.5 text-xs text-gray-400"></i>Правила рекомендаций</a>
        </div>
    </div>
    <?php if ($is_user): ?>
    <p class="n2j-hint mt-1.5"><?php echo HTML::chars($user['email']); ?> · входов <?php echo (int) $user['logins']; ?> · последний <?php echo $fmt($user['last_login'], 'd.m.Y H:i'); ?></p>
    <?php endif; ?>

    <?php if ((string) $flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <!-- KPI -->
    <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Страниц</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo $profile ? (int) $profile['pages_total'] : 0; ?></p>
            <p class="n2j-hint">поисков: <?php echo $profile ? (int) $profile['searches_total'] : 0; ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Разделов</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo $profile ? count($profile['sections']) : 0; ?></p>
            <p class="n2j-hint">уникальных интересов</p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Первый визит</p>
            <p class="mt-1 text-base font-black tracking-tight text-gray-900 sm:text-lg dark:text-white"><?php echo $profile ? $fmt($profile['first_seen'], 'd.m.Y') : '—'; ?></p>
            <p class="n2j-hint">последний: <?php echo $profile ? $fmt($profile['last_seen']) : '—'; ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">14 дней</p>
            <div class="mt-2 flex items-end gap-1" title="События по дням (точки, не полосы): больше точек — активнее день">
                <?php for ($i = 13; $i >= 0; $i--): $d = date('Y-m-d', strtotime('-'.$i.' days')); $c = (int) ($days[$d] ?? 0); $dots = $c > 0 ? max(1, (int) ceil(4 * $c / $max_day)) : 0; ?>
                <span class="flex flex-col gap-0.5" title="<?php echo HTML::chars($d.': '.$c); ?>">
                    <?php for ($k = 3; $k >= 0; $k--): ?><i class="block h-1.5 w-1.5 rounded-full <?php echo $k < $dots ? 'bg-blue-500' : 'bg-gray-200 dark:bg-gray-700'; ?>"></i><?php endfor; ?>
                </span>
                <?php endfor; ?>
            </div>
            <p class="n2j-hint">событий: <?php echo array_sum($days); ?></p>
        </div>
    </div>

    <?php // ===== Центр контроля: объекты по модулям, токены, сбои — одна страница вместо пяти URL (25.10.2026) ===== ?>
    <section class="mt-6" id="control">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="n2j-h2"><i class="fas fa-tower-observation mr-1.5 text-blue-500"></i>Что происходило</h2>
            <?php if ($links): ?>
            <span class="flex flex-wrap gap-1.5 text-xs">
                <?php foreach ($links as $l): ?>
                <a href="<?php echo HTML::chars($l['href']); ?>" class="n2j-chip"><i class="<?php echo HTML::chars($l['icon']); ?> mr-1 text-gray-400" aria-hidden="true"></i><?php echo HTML::chars($l['label']); ?></a>
                <?php endforeach; ?>
            </span>
            <?php endif; ?>
        </div>

        <div class="mt-3 grid gap-4 lg:grid-cols-3">
            <!-- Объекты по модулям -->
            <div class="n2j-card p-0 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <div class="flex items-center gap-2.5">
                        <h3 class="n2j-h3"><i class="fas fa-layer-group mr-1.5 text-blue-500"></i>Объекты по модулям</h3>
                        <span class="n2j-badge n2j-badge-wait"><?php echo array_sum(array_map(function ($o) { return (int) $o['total']; }, $objects)); ?></span>
                    </div>
                    <span class="text-xs text-gray-400">из контракта <span class="font-mono">recent</span> манифестов</span>
                </div>
                <?php if ( ! $objects): ?>
                <p class="n2j-hint px-4 py-4 sm:px-5"><?php echo $is_user ? 'Своих объектов в модулях пока нет.' : 'Гость без аккаунта — объекты по модулям привязаны к user_id.'; ?></p>
                <?php else: ?>
                <div class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    <?php foreach ($objects as $o): ?>
                    <div class="px-4 py-3 sm:px-5">
                        <div class="flex flex-wrap items-center gap-2">
                            <i class="<?php echo HTML::chars($o['icon']); ?> text-gray-400" aria-hidden="true"></i>
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($o['label']); ?></span>
                            <span class="text-xs text-gray-400"><?php echo (int) $o['total']; ?> шт.</span>
                        </div>
                        <ul class="mt-1.5 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
                            <?php foreach ($o['rows'] as $r): ?>
                            <li class="flex min-w-0 items-center gap-2">
                                <a href="<?php echo HTML::chars($r['href']); ?>" class="min-w-0 truncate font-semibold text-blue-600 hover:underline dark:text-blue-400" title="<?php echo HTML::chars((string) $r['t']); ?>"><?php echo HTML::chars((string) $r['t']); ?></a>
                                <?php if ((string) Arr::get($r, 'status', '') !== ''): ?><span class="shrink-0 text-gray-400"><?php echo HTML::chars((string) $r['status']); ?></span><?php endif; ?>
                                <span class="ml-auto shrink-0 text-gray-400"><?php echo $fmt($r['created_at'], 'd.m.y'); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Токены -->
            <div class="n2j-card p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h3 class="n2j-h3"><i class="fas fa-coins mr-1.5 text-blue-500"></i>Токены</h3>
                    <span class="text-xs text-gray-400">задач <?php echo $n($tokens['jobs']); ?> · ошибок <?php echo $n($tokens['errors']); ?></span>
                </div>
                <div class="px-4 py-3 sm:px-5">
                    <?php if ($budget): ?>
                    <?php echo View::factory('120/_budget', ['budget' => $budget, 'compact' => TRUE, 'admin_href' => '/nj120/budgets?key='.rawurlencode($key)])->render(); ?>
                    <hr class="my-3 border-gray-100 dark:border-gray-700/60">
                    <?php endif; ?>
                    <p class="text-2xl font-black tracking-tight text-gray-900 dark:text-white"><?php echo $n($tokens['total']); ?></p>
                    <p class="n2j-hint">за всё время (nj_120_usage, переживает уборку монитора)</p>
                    <?php if ($tokens['channels']): ?>
                    <ul class="mt-3 space-y-1 text-xs">
                        <?php foreach (array_slice($tokens['channels'], 0, 8) as $c): ?>
                        <li class="flex items-center gap-2">
                            <span class="min-w-0 truncate text-gray-700 dark:text-gray-200" title="<?php echo HTML::chars((string) $c['channel']); ?>"><?php echo HTML::chars((string) $c['title']); ?></span>
                            <span class="text-gray-400">×<?php echo (int) $c['jobs']; ?></span>
                            <span class="ml-auto font-semibold tabular-nums text-gray-800 dark:text-gray-200"><?php echo $n($c['total']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if ($tokens['days']): ?>
                    <p class="mt-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">30 дней</p>
                    <ul class="mt-1 max-h-40 space-y-0.5 overflow-auto text-xs">
                        <?php foreach ($tokens['days'] as $d): ?>
                        <li class="flex items-center gap-2 tabular-nums">
                            <span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $d['day']); ?></span>
                            <span class="text-gray-400">×<?php echo (int) $d['jobs']; ?></span>
                            <?php if ((int) $d['errors'] > 0): ?><span class="text-rose-600 dark:text-rose-400">сбоев <?php echo (int) $d['errors']; ?></span><?php endif; ?>
                            <span class="ml-auto font-semibold text-gray-800 dark:text-gray-200"><?php echo $n($d['tokens']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($failures): ?>
        <!-- Сбои -->
        <div class="n2j-card p-0 mt-4 overflow-x-auto">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                <div class="flex items-center gap-2.5">
                    <h3 class="n2j-h3"><i class="fas fa-triangle-exclamation mr-1.5 text-rose-500"></i>Сбои задач</h3>
                    <span class="n2j-badge n2j-badge-err"><?php echo count($failures); ?></span>
                </div>
                <span class="text-xs text-gray-400">что у человека не получилось — ближайший аналог жалобы</span>
            </div>
            <table class="n2j-table">
                <tbody class="divide-y divide-gray-100 text-xs text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                    <?php foreach ($failures as $f): ?>
                    <tr>
                        <td class="whitespace-nowrap py-2 pl-4 pr-3 text-gray-500 sm:pl-5"><a href="/nj120/monitor?job=<?php echo (int) $f['id']; ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo $fmt($f['created_at'], 'd.m H:i'); ?></a></td>
                        <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $f['channel']); ?></td>
                        <td class="px-3 py-2"><span class="n2j-badge n2j-badge-err"><?php echo HTML::chars((string) $f['status']); ?></span></td>
                        <td class="max-w-[28rem] truncate py-2 pr-4 sm:pr-5" title="<?php echo HTML::chars((string) $f['error']); ?>"><?php echo HTML::chars(UTF8::substr((string) $f['error'], 0, 140)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Главная колонка (2/3) ============ -->
        <div class="min-w-0 space-y-6 md:col-span-2">

            <!-- Рекомендации с причинами -->
            <div class="n2j-card p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <div class="flex items-center gap-2.5">
                        <h2 class="n2j-h3"><i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500"></i>Что система предложит</h2>
                        <span class="n2j-badge n2j-badge-wait"><?php echo count($reco); ?></span>
                    </div>
                    <span class="text-xs text-gray-400">просмотр администратора в журнал показов не пишется</span>
                </div>
                <?php if (empty($reco)): ?>
                <p class="px-4 py-5 text-sm text-gray-400 sm:px-5">Пока нечего предложить: нет сигналов интереса или всё уже посещено. Закрепите сервис вручную ниже.</p>
                <?php else: ?>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    <?php foreach ($reco as $r): ?>
                    <li class="flex flex-wrap items-start gap-3 px-4 py-3 sm:px-5">
                        <i class="<?php echo HTML::chars($r['icon']); ?> mt-1 w-5 text-center text-sm text-blue-500"></i>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="<?php echo HTML::chars($r['href']); ?>" class="font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"><?php echo HTML::chars($r['title']); ?></a>
                                <?php echo $source_badge($r['source']); ?>
                                <span class="font-mono text-xs text-gray-400" title="Вес в ранжировании"><?php echo (int) $r['score']; ?></span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400"><i class="fas fa-circle-question mr-1 text-gray-300"></i><?php echo HTML::chars($r['reason']); ?></p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-1.5">
                            <form method="post" action="/nj600/admin/owner_action/<?php echo rawurlencode($key); ?>">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="act" value="pin">
                                <input type="hidden" name="href" value="<?php echo HTML::chars($r['href']); ?>">
                                <button type="submit" class="n2j-btn-secondary n2j-btn-sm" title="Закрепить: показывать этому владельцу первым"><i class="fas fa-thumbtack text-xs"></i></button>
                            </form>
                            <?php if ($is_user): ?>
                            <form method="post" action="/nj600/admin/owner_action/<?php echo rawurlencode($key); ?>">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="act" value="notify">
                                <input type="hidden" name="href" value="<?php echo HTML::chars($r['href']); ?>">
                                <input type="hidden" name="title" value="<?php echo HTML::chars('Вам может пригодиться: '.$r['title']); ?>">
                                <input type="hidden" name="note" value="<?php echo HTML::chars($r['reason']); ?>">
                                <button type="submit" data-n2j-arm="Отправить?" class="n2j-btn-secondary n2j-btn-sm" title="Отправить уведомлением (колокольчик)"><i class="fas fa-bell text-xs"></i><span data-n2j-arm-label class="sr-only">Отправить</span></button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="/nj600/admin/owner_action/<?php echo rawurlencode($key); ?>">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="act" value="block">
                                <input type="hidden" name="href" value="<?php echo HTML::chars($r['href']); ?>">
                                <button type="submit" data-n2j-arm="Не советовать?" class="n2j-btn-danger-soft n2j-btn-sm" title="Не советовать этому владельцу"><i class="fas fa-ban text-xs"></i><span data-n2j-arm-label class="sr-only">Запретить</span></button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <!-- Ручное предложение -->
                <form method="post" action="/nj600/admin/owner_action/<?php echo rawurlencode($key); ?>" class="border-t border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Предложить вручную</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-5">
                        <select name="href" class="n2j-select sm:col-span-2">
                            <?php foreach ($catalog as $c): ?>
                            <option value="<?php echo HTML::chars($c['href']); ?>"><?php echo HTML::chars($c['title'].' — '.$c['href']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="note" placeholder="почему (увидит пользователь)" class="n2j-input sm:col-span-2" maxlength="300">
                        <div class="flex gap-1.5">
                            <button type="submit" name="act" value="pin" class="n2j-btn-process flex-1" title="Закрепить в рекомендациях"><i class="fas fa-thumbtack"></i>Закрепить</button>
                            <?php if ($is_user): ?>
                            <button type="submit" name="act" value="notify" class="n2j-btn-secondary" title="Отправить уведомлением"><i class="fas fa-bell"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Лента последних действий -->
            <div class="n2j-card p-0 overflow-x-auto">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <div class="flex items-center gap-2.5">
                        <h2 class="n2j-h3"><i class="fas fa-shoe-prints mr-1.5 text-blue-500"></i>Последние действия</h2>
                        <span class="n2j-badge n2j-badge-wait"><?php echo count($events); ?></span>
                    </div>
                    <span class="text-xs text-gray-400">сырые события, живут по TTL</span>
                </div>
                <table class="n2j-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pl-4 pr-3 sm:pl-5">Когда</th>
                            <th class="py-2.5 px-3">Что</th>
                            <th class="py-2.5 px-3">Адрес</th>
                            <th class="py-2.5 pr-4 sm:pr-5">Откуда</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                        <?php foreach ($events as $e): ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="py-2 pl-4 pr-3 text-xs text-gray-500 sm:pl-5"><?php echo $fmt($e['created_at'], 'd.m H:i'); ?></td>
                            <td class="px-3 py-2">
                                <?php if ((string) $e['action'] === 'search'): ?>
                                <span class="n2j-badge n2j-badge-info mr-1">поиск</span><span class="text-xs"><?php echo HTML::chars($e['phrase']); ?></span>
                                <?php else: ?>
                                <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($section_title((string) $e['section'])); ?></span>
                                <span class="ml-1 font-mono text-xs text-gray-400"><?php echo HTML::chars($e['section']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="max-w-[16rem] truncate px-3 py-2 font-mono text-xs text-gray-400" title="<?php echo HTML::chars($e['uri']); ?>"><?php echo HTML::chars($e['uri']); ?></td>
                            <td class="py-2 pr-4 text-xs text-gray-400 sm:pr-5">
                                <?php $src = trim(implode(' · ', array_filter([(string) $e['ref_host'], (string) $e['utm_source'], (string) $e['utm_campaign']]))); echo $src !== '' ? HTML::chars($src) : '—'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($events)): ?>
                        <tr><td colspan="4" class="py-6 pl-4 text-center text-sm text-gray-400 sm:pl-5">Свежих событий нет (сырьё старше TTL уже удалено — остался профиль).</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Задачи воркеров -->
            <div class="n2j-card p-0 overflow-x-auto">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <div class="flex items-center gap-2.5">
                        <h2 class="n2j-h3"><i class="fas fa-list-check mr-1.5 text-blue-500"></i>Задачи воркеров</h2>
                        <span class="n2j-badge n2j-badge-wait"><?php echo count($jobs); ?></span>
                    </div>
                    <span class="text-xs text-gray-400">что человек реально заказывал</span>
                </div>
                <table class="n2j-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pl-4 pr-3 sm:pl-5">Когда</th>
                            <th class="py-2.5 px-3">Канал</th>
                            <th class="py-2.5 px-3">Что</th>
                            <th class="py-2.5 pr-4 text-right sm:pr-5">Статус</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                        <?php foreach ($jobs as $j): $jt = class_exists('Service_Nj120_JobQueue') ? Service_Nj120_JobQueue::job_title_of($j['payload']) : ''; ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="py-2 pl-4 pr-3 text-xs text-gray-500 sm:pl-5"><a href="/nj120/monitor?job=<?php echo (int) $j['id']; ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo $fmt($j['created_at'], 'd.m H:i'); ?></a></td>
                            <td class="px-3 py-2 font-mono text-xs font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $j['channel']); ?></td>
                            <td class="max-w-[16rem] truncate px-3 py-2 text-xs" title="<?php echo HTML::chars($jt); ?>"><?php echo $jt !== '' ? HTML::chars($jt) : '<span class="text-gray-300">—</span>'; ?></td>
                            <td class="py-2 pr-4 text-right sm:pr-5"><?php echo $status_badge((string) $j['status']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($jobs)): ?>
                        <tr><td colspan="4" class="py-6 pl-4 text-center text-sm text-gray-400 sm:pl-5">Задач не заказывал.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============ Правая колонка (1/3) ============ -->
        <div class="min-w-0 space-y-5 md:col-span-1">

            <?php if ($profile AND $profile['llm_summary'] !== ''): ?>
            <div class="rounded-xl border border-blue-200/80 bg-blue-50/60 p-4 shadow-xs dark:border-blue-800/60 dark:bg-blue-950/30 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-robot text-blue-500"></i>
                    <h3 class="text-sm font-bold">ИИ-резюме</h3>
                </div>
                <p class="mt-2 whitespace-pre-line text-xs text-gray-700 sm:text-sm dark:text-gray-300"><?php echo HTML::chars($profile['llm_summary']); ?></p>
                <p class="n2j-hint mt-2 font-mono"><?php echo HTML::chars($profile['llm_model']); ?> · <?php echo $fmt($profile['llm_at'], 'd.m.y'); ?></p>
            </div>
            <?php endif; ?>

            <div class="n2j-card">
                <h3 class="n2j-h3 border-b border-gray-100 pb-3 dark:dark:border-gray-800">Интересы (разделы)</h3>
                <?php if ( ! $profile OR empty($profile['sections'])): ?>
                <p class="n2j-hint mt-2">Профиль ещё пуст.</p>
                <?php else: ?>
                <ul class="mt-2.5 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <?php foreach (array_slice($profile['sections'], 0, 15, TRUE) as $sec => $cnt): $dots = max(1, (int) ceil(5 * (int) $cnt / $max_sec)); ?>
                    <li class="flex items-center justify-between gap-2">
                        <span class="min-w-0 truncate">
                            <?php if (isset($catalog[$sec])): ?><a href="<?php echo HTML::chars($catalog[$sec]['href']); ?>" class="font-semibold hover:text-blue-600 dark:hover:text-blue-400"><?php echo HTML::chars($catalog[$sec]['title']); ?></a>
                            <?php else: ?><span class="font-semibold"><?php echo HTML::chars($sec); ?></span><?php endif; ?>
                            <span class="ml-1 font-mono text-xs text-gray-400"><?php echo HTML::chars($sec); ?></span>
                        </span>
                        <span class="flex shrink-0 items-center gap-1.5">
                            <span class="flex gap-0.5"><?php for ($k = 0; $k < 5; $k++): ?><i class="block h-1.5 w-1.5 rounded-full <?php echo $k < $dots ? 'bg-blue-500' : 'bg-gray-200 dark:bg-gray-700'; ?>"></i><?php endfor; ?></span>
                            <span class="w-8 text-right font-mono text-xs text-gray-400"><?php echo (int) $cnt; ?></span>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="n2j-card">
                <h3 class="n2j-h3 border-b border-gray-100 pb-3 dark:dark:border-gray-800">Поисковые фразы</h3>
                <ul class="mt-2.5 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <?php foreach (($profile ? $profile['phrases'] : []) as $p): ?>
                    <li class="flex items-center justify-between gap-2">
                        <span class="min-w-0 truncate" title="<?php echo HTML::chars((string) ($p[0] ?? '')); ?>"><?php echo HTML::chars((string) ($p[0] ?? '')); ?></span>
                        <span class="shrink-0 font-mono text-xs text-gray-400"><?php echo (int) ($p[1] ?? 0); ?></span>
                    </li>
                    <?php endforeach; ?>
                    <?php if ( ! $profile OR empty($profile['phrases'])): ?><li class="text-xs text-gray-400">Поиском не пользовался.</li><?php endif; ?>
                </ul>
            </div>

            <div class="n2j-card">
                <h3 class="n2j-h3 border-b border-gray-100 pb-3 dark:dark:border-gray-800">Откуда приходит</h3>
                <ul class="mt-2.5 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <?php foreach (($profile ? $profile['ref_hosts'] : []) as $h => $c): ?>
                    <li class="flex items-center justify-between gap-2"><span class="min-w-0 truncate font-mono text-xs"><?php echo HTML::chars($h); ?></span><span class="shrink-0 font-mono text-xs text-gray-400"><?php echo (int) $c; ?></span></li>
                    <?php endforeach; ?>
                    <?php if ( ! $profile OR empty($profile['ref_hosts'])): ?><li class="text-xs text-gray-400">Внешних переходов не было (прямые заходы).</li><?php endif; ?>
                </ul>
                <?php if ($profile AND ! empty($profile['utm_last'])): ?>
                <p class="n2j-hint mt-2 border-t border-gray-100 pt-2 dark:dark:border-gray-800">Последние utm: <?php foreach ($profile['utm_last'] as $k => $v) echo '<span class="font-mono">'.HTML::chars(str_replace('utm_', '', $k).'='.$v).'</span> '; ?></p>
                <?php endif; ?>
            </div>

            <?php if ( ! empty($logins)): ?>
            <div class="n2j-card">
                <h3 class="n2j-h3 border-b border-gray-100 pb-3 dark:dark:border-gray-800">Входы</h3>
                <ul class="mt-2.5 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($logins as $l): ?>
                    <li class="flex items-center justify-between gap-2">
                        <span><?php echo $fmt($l['created_at']); ?> <span class="text-gray-400">· <?php echo HTML::chars((string) $l['via']); ?></span></span>
                        <span class="font-mono text-gray-400"><?php echo HTML::chars((string) $l['ip']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="n2j-card">
                <h3 class="n2j-h3 border-b border-gray-100 pb-3 dark:dark:border-gray-800">Адресные правила</h3>
                <?php if (empty($rules)): ?>
                <p class="n2j-hint mt-2">Для этого владельца правил нет — работают общие (см. «Рекомендации»).</p>
                <?php else: ?>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($rules as $r): ?>
                    <li class="flex items-center justify-between gap-2">
                        <span class="min-w-0 truncate">
                            <span class="n2j-badge <?php echo $r['kind'] === 'block' ? 'n2j-badge-err' : 'n2j-badge-info'; ?> mr-1"><?php echo $r['kind'] === 'block' ? 'нельзя' : 'закреплено'; ?></span>
                            <span class="font-mono"><?php echo HTML::chars($r['href']); ?></span>
                            <?php if ( ! $r['enabled'] OR $r['enabled'] === 'f'): ?><span class="text-gray-400">(выкл.)</span><?php endif; ?>
                        </span>
                        <form method="post" action="/nj600/admin/rule_delete/<?php echo (int) $r['id']; ?>" class="shrink-0">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="back" value="/nj600/admin/owner/<?php echo HTML::chars(rawurlencode($key)); ?>">
                            <button type="submit" data-n2j-arm="Удалить?" class="n2j-btn-icon-danger" title="Удалить правило"><i class="fas fa-trash text-xs"></i><span data-n2j-arm-label class="sr-only">Удалить</span></button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="n2j-card">
                <h3 class="n2j-h3 border-b border-gray-100 pb-3 dark:dark:border-gray-800">Журнал рекомендаций</h3>
                <ul class="mt-2.5 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($reco_log as $l): ?>
                    <li class="flex items-center justify-between gap-2">
                        <span class="min-w-0 truncate"><span class="font-mono"><?php echo HTML::chars($l['href']); ?></span> <span class="text-gray-400">· <?php echo HTML::chars(Service_Nj600_Reco::source_label($l['source'])); ?></span></span>
                        <span class="shrink-0">
                            <?php if ($l['clicked_at']): ?><span class="n2j-badge n2j-badge-ok">клик</span>
                            <?php elseif ($l['dismissed_at']): ?><span class="n2j-badge n2j-badge-err">отказ</span>
                            <?php else: ?><span class="text-gray-400"><?php echo $fmt($l['shown_at'], 'd.m H:i'); ?></span><?php endif; ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($reco_log)): ?><li class="text-gray-400">Показов ещё не было.</li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
