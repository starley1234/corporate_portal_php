<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prl595: список пользователей (поиск, роли, входы) + создание.
 */

$role_badge = function ($name) {
    return '<span class="prl-count uppercase">'.HTML::chars($name).'</span>';
};
?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dashboard" class="transition hover:text-blue-600 dark:hover:text-blue-400">Дашборд</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Пользователи и роли</span>
    </nav>
    <h1 class="prl-h1 mt-2"><i class="fas fa-users mr-2 text-blue-500"></i>Пользователи</h1>

    <?php if ((string) $flash !== ''): ?>
    <div class="prl-flash prl-flash-info mt-3" role="status">
        <i class="fas fa-circle-info shrink-0"></i><span><?php echo HTML::chars($flash); ?></span>
    </div>
    <?php endif; ?>

    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="prl-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Пользователей</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format($total, 0, ',', ' '); ?></p>
        </div>
        <div class="prl-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Найдено по запросу</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo count($rows); ?></p>
        </div>
        <div class="prl-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Страница</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) $page; ?></p>
            <?php if ($total > $per): ?><p class="prl-hint">по <?php echo (int) $per; ?> на странице</p><?php endif; ?>
        </div>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Таблица пользователей (2/3) ============ -->
        <div class="min-w-0 md:col-span-2">
            <form method="get" action="/prl595/admin" class="prl-card">
                <label for="prl595-q" class="prl-label">Поиск по email или имени</label>
                <div class="mt-1.5 flex flex-col gap-2 sm:flex-row">
                    <input type="text" id="prl595-q" name="q" value="<?php echo HTML::chars($q); ?>" placeholder="напр. «иван» или «@mail.ru»"
                           class="prl-input w-full">
                    <button type="submit" class="prl-btn-process"><i class="fas fa-magnifying-glass"></i>Найти</button>
                </div>
            </form>

            <div class="prl-card p-0 mt-4 overflow-x-auto">
                <table class="prl-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pl-4 pr-3 sm:pl-5">ID</th>
                            <th class="py-2.5 px-3">Пользователь</th>
                            <th class="py-2.5 px-3">Роли</th>
                            <th class="py-2.5 px-3">Входов</th>
                            <th class="py-2.5 px-3">Был</th>
                            <th class="py-2.5 pr-4 text-right sm:pr-5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                        <?php foreach ($rows as $u): $roles = trim((string) $u['roles'], '{}'); ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="py-2.5 pl-4 pr-3 font-mono text-xs text-gray-400 sm:pl-5"><?php echo (int) $u['id']; ?></td>
                            <td class="max-w-[16rem] truncate px-3 py-2.5">
                                <span class="block truncate font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($u['username']); ?></span>
                                <span class="block truncate text-xs text-gray-400"><?php echo HTML::chars($u['email']); ?></span>
                            </td>
                            <td class="px-3 py-2.5">
                                <?php if ($roles === ''): ?><span class="text-xs text-rose-400">вход закрыт</span>
                                <?php else: foreach (explode(',', $roles) as $rn) echo $role_badge(trim($rn)); endif; ?>
                            </td>
                            <td class="px-3 py-2.5 font-mono text-xs"><?php echo (int) $u['logins']; ?></td>
                            <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400"><?php $ll = (string) ($u['last_login'] ?? ''); echo ($ll !== '' AND $ll !== '0') ? HTML::chars(Date::formatted_time(ctype_digit($ll) ? '@'.(int) $ll : $ll, 'd.m.y H:i')) : '—'; ?></td>
                            <td class="py-2.5 pr-4 text-right sm:pr-5">
                                <span class="inline-flex items-center gap-1.5">
                                    <a href="/prl595/admin/user/<?php echo (int) $u['id']; ?>" class="prl-btn-secondary prl-btn-sm">открыть</a>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="py-6 pl-4 text-center text-sm text-gray-400 sm:pl-5">Никого не найдено.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total > $per): ?>
            <div class="mt-3 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <?php if ($page > 1): ?><a class="prl-btn-secondary" href="/prl595/admin?<?php echo http_build_query(['q' => $q, 'page' => $page - 1]); ?>">← новее</a><?php else: ?><span></span><?php endif; ?>
                <span>стр. <?php echo (int) $page; ?> из <?php echo (int) ceil($total / $per); ?></span>
                <?php if ($page * $per < $total): ?><a class="prl-btn-secondary" href="/prl595/admin?<?php echo http_build_query(['q' => $q, 'page' => $page + 1]); ?>">старее →</a><?php else: ?><span></span><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ============ Создание (1/3) ============ -->
        <div class="min-w-0 space-y-5 md:col-span-1">
            <div class="prl-card">
                <h3 class="prl-h3 border-b border-gray-100 pb-3 dark:border-gray-700"><i class="fas fa-user-plus mr-1.5 text-blue-500"></i>Создать пользователя</h3>
                <form method="post" action="/prl595/admin/create" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div>
                        <label for="prl595-email" class="prl-label">Email</label>
                        <input type="email" id="prl595-email" name="email" required autocomplete="off" placeholder="user@example.ru"
                               class="prl-input mt-1 w-full">
                    </div>
                    <div>
                        <label for="prl595-username" class="prl-label">Имя пользователя</label>
                        <input type="text" id="prl595-username" name="username" required autocomplete="off" placeholder="ivanov"
                               class="prl-input mt-1 w-full">
                    </div>
                    <div>
                        <label for="prl595-pass" class="prl-label">Пароль <span class="font-normal text-gray-400">(мин. 6)</span></label>
                        <input type="text" id="prl595-pass" name="password" required autocomplete="off" placeholder="…" data-lpignore="true" data-1p-ignore="true"
                               class="prl-input mt-1 w-full">
                    </div>
                    <label class="flex min-h-[36px] cursor-pointer items-center gap-2.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                        <input type="checkbox" name="as_admin" value="1" class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">сразу роль admin
                    </label>
                    <button type="submit" class="prl-btn-cta w-full">Создать</button>
                    <p class="prl-hint">Роль login выдаётся автоматически — без неё вход закрыт.</p>
                </form>
            </div>

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">Как устроены роли</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><b>login</b> — доступ к сервисам. Сняли роль — пользователь заблокирован (схему ядра не меняем).</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><b>admin</b> — управление модулями, дашборд, пользователи.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Свои роли — на вкладке «Роли»: менеджер, наблюдатель и т.п.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Себе нельзя снять admin — защита от потери доступа.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><i class="fas fa-brain text-blue-500"></i> рядом с пользователем — его предпочтения, последние действия и что ему порекомендует платформа.</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>
