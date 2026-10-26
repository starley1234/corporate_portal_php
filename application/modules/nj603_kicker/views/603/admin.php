<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj603/admin — «Пиналка»: одно расписание на все модули.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dashboard" class="transition hover:text-blue-600 dark:hover:text-blue-400">Кабинет</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Пиналка · управление</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-shoe-prints mr-2 text-blue-500"></i>Пиналка
        </h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-clock text-[11px]"></i>Планировщик
        </span>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">
        Один cron каждые 10 минут — вместо десяти хронов под каждый модуль. Пиналка смотрит конфиг
        (config/nj603_kicker.php), созревшее расписание выполняет сразу же (прямой вызов сервиса модуля),
        а в очередь nj120 уходит только реальная работа — статья, радар, камеры. Дубликат не ставится.
    </p>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <div class="n2j-card p-0 md:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                <div class="flex items-center gap-2.5">
                    <h2 class="n2j-h3">Расписания (конфиг)</h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($schedules); ?></span>
                </div>
                <form method="post" action="/nj603/admin">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="run">
                    <button type="submit" class="n2j-btn-process">
                        <i class="fas fa-bolt"></i>Проверить сейчас
                    </button>
                </form>
            </div>

            <div class="overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
                <table class="n2j-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pr-3 pl-4 sm:pl-5">Расписание</th>
                            <th class="py-2.5 px-3">Каждые</th>
                            <th class="py-2.5 px-3">Цель</th>
                            <th class="py-2.5 px-3">Последний пинок</th>
                            <th class="py-2.5 pr-4 pl-3 text-right sm:pr-5">Действие</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                        <?php foreach ($schedules as $s): ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="py-2.5 pr-3 pl-4 sm:pl-5">
                                <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($s['label']); ?></span>
                                <?php if ( ! $s['enabled']): ?><span class="n2j-badge n2j-badge-wait ml-1.5">выключено</span><?php endif; ?>
                            </td>
                            <td class="py-2.5 px-3 text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($s['every']); ?></td>
                            <td class="py-2.5 px-3 text-xs">
                                <a href="<?php echo HTML::chars($s['url']); ?>" class="font-mono text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($s['url']); ?></a>
                            </td>
                            <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400">
                                <?php if ($s['last_run'] !== ''): ?>
                                <?php echo HTML::chars(Date::formatted_time($s['last_run'], 'd.m.y H:i')); ?>
                                · срабатываний: <?php echo (int) $s['runs']; ?>
                                <?php if ($s['last_status'] !== ''): ?>
                                <span class="ml-1 rounded-md px-1.5 py-0.5 font-semibold <?php echo $s['last_status'] === 'ok' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300'; ?>"><?php echo HTML::chars($s['last_status']); ?></span>
                                <?php if ($s['last_error'] !== ''): ?><span class="ml-1 text-rose-500" title="<?php echo HTML::chars($s['last_error']); ?>"><?php echo HTML::chars(mb_substr($s['last_error'], 0, 40)); ?></span><?php endif; ?>
                                <?php endif; ?>
                                <?php else: ?><span class="text-gray-400">ещё не запускалось</span><?php endif; ?>
                            </td>
                            <td class="py-2.5 pr-4 pl-3 text-right sm:pr-5">
                                <form method="post" action="/nj603/admin" class="inline">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <input type="hidden" name="act" value="poke">
                                    <input type="hidden" name="name" value="<?php echo HTML::chars($s['name']); ?>">
                                    <button type="submit" class="n2j-btn-secondary n2j-btn-sm">
                                        Пнуть
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ( ! $schedules): ?>
                        <tr><td colspan="5" class="py-4 pl-4 text-xs text-gray-400 sm:pl-5">Расписаний нет — добавьте в config/nj603_kicker.php.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-5">
            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 sm:p-5 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">Как подключить cron</h3>
                </div>
                <p class="mt-2.5 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                    Одна строка на всё (CLI):
                </p>
                <pre class="mt-1.5 overflow-x-auto rounded-lg bg-gray-900 p-2.5 font-mono text-xs text-gray-100">*/10 * * * * php /path/to/index.php --task=Nj603:Kick</pre>
                <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                    Или без CLI (токен <?php echo $token_set ? 'задан в .env' : 'НЕ задан — добавьте NJ603_KICK_TOKEN в .env'; ?>):
                </p>
                <pre class="mt-1.5 overflow-x-auto rounded-lg bg-gray-900 p-2.5 font-mono text-xs text-gray-100">*/10 * * * * curl -fsS "https://n2j.ru/nj603/kick/&lt;токен&gt;"</pre>
                <ul class="mt-2.5 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Созревшее расписание выполняется сразу — лишней задачи-пинка в очереди нет.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Новое расписание: скопируйте блок в конфиге — БД и меню сами подхватят.</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('603/script'); ?>
