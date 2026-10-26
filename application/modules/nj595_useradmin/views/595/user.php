<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj595: карточка пользователя — данные, пароль, роли (login = доступ).
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/nj595/admin" class="transition hover:text-blue-600 dark:hover:text-blue-400">Пользователи</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-mono font-medium text-gray-700 dark:text-gray-200">#<?php echo (int) $u->id; ?></span>
    </nav>
    <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
        <h1 class="n2j-h1"><i class="fas fa-user-gear mr-2 text-blue-500"></i><?php echo HTML::chars($u->username); ?></h1>
        <div class="flex flex-wrap gap-2">
            <a href="/nj600/admin/owner/u:<?php echo (int) $u->id; ?>" class="n2j-btn-process"><i class="fas fa-brain"></i>Предпочтения и действия</a>
            <a href="/nj595/admin" class="n2j-btn-secondary"><i class="fas fa-arrow-left text-xs text-gray-400"></i>К списку</a>
        </div>
    </div>

    <?php if ((string) $flash !== ''): ?>
    <div class="mt-3 flex items-center gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs font-semibold sm:text-sm dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300">
        <i class="fas fa-circle-info shrink-0"></i><span><?php echo HTML::chars($flash); ?></span>
    </div>
    <?php endif; ?>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Форма (2/3) ============ -->
        <div class="min-w-0 md:col-span-2">
            <form method="post" action="/nj595/admin/save/<?php echo (int) $u->id; ?>" class="n2j-card">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

                <h2 class="n2j-h3 border-b border-gray-100 pb-3">Профиль</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="u-email" class="n2j-label">Email</label>
                        <input type="email" id="u-email" name="email" value="<?php echo HTML::chars($u->email); ?>" required autocomplete="off"
                               class="n2j-input mt-1 w-full">
                    </div>
                    <div>
                        <label for="u-username" class="n2j-label">Имя пользователя</label>
                        <input type="text" id="u-username" name="username" value="<?php echo HTML::chars($u->username); ?>" required autocomplete="off"
                               class="n2j-input mt-1 w-full">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="u-pass" class="n2j-label">Новый пароль <span class="font-normal text-gray-400">(пусто — не менять)</span></label>
                        <input type="text" id="u-pass" name="password" autocomplete="off" placeholder="минимум 6 символов" data-lpignore="true" data-1p-ignore="true"
                               class="n2j-input mt-1 w-full">
                    </div>
                </div>

                <h2 class="n2j-h3 mt-6 border-b border-gray-100 pb-3">Роли</h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <?php foreach ($roles as $r):
                        $name = (string) $r['name'];
                        $checked = isset($mine[(int) $r['id']]);
                        $is_login = ($name === 'login');
                    ?>
                    <label class="flex min-h-[44px] cursor-pointer items-start gap-2.5 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50/70 dark:border-gray-700 dark:hover:bg-gray-700/25">
                        <input type="checkbox" name="roles[]" value="<?php echo HTML::chars($name); ?>" <?php echo $checked ? 'checked' : ''; ?> class="mt-0.5 h-4 w-4 rounded accent-blue-600">
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($name); ?><?php echo $is_login ? ' <span class="text-xs font-normal text-emerald-600 dark:text-emerald-400">— вход разрешён</span>' : ''; ?></span>
                            <span class="block truncate text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $r['description']); ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php if ((int) $u->id === $me_id): ?>
                <p class="n2j-flash n2j-flash-warn mt-3 text-xs block"><i class="fas fa-triangle-exclamation mr-1.5"></i>Это ваш аккаунт: роль admin снять нельзя, иначе потеряете доступ.</p>
                <?php endif; ?>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button type="submit" class="n2j-btn-cta">Сохранить</button>
                    <span class="text-xs text-gray-400">сняли роль login — пользователь больше не войдёт; вернуть можно в любой момент</span>
                </div>
            </form>
        </div>

        <!-- ============ Сводка (1/3) ============ -->
        <div class="hidden space-y-5 md:block">
            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Сводка</h3>
                <dl class="mt-2 space-y-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">ID</dt><dd class="font-mono"><?php echo (int) $u->id; ?></dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Входов всего</dt><dd class="font-mono"><?php echo (int) $u->logins; ?></dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Последний вход</dt><dd><?php $ll = (string) $u->last_login; echo ($ll !== '' AND $ll !== '0') ? HTML::chars(Date::formatted_time(ctype_digit($ll) ? '@'.(int) $ll : $ll, 'd.m.Y H:i')) : '—'; ?></dd></div>
                </dl>
                <p class="n2j-hint mt-2 border-t border-gray-100 pt-2">Пароль хранится хэшем драйвера Auth — прочитать его нельзя, только заменить.</p>
            </div>

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-chart-line text-blue-500"></i>
                    <h3 class="text-sm font-bold">Активность</h3>
                </div>
                <p class="mt-2 text-xs text-gray-600 sm:text-sm dark:text-gray-300">Предпочтения, последние действия, задачи и рекомендации — на <a href="/nj600/admin/owner/u:<?php echo (int) $u->id; ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">карточке владельца</a> (<span class="font-mono text-xs">u:<?php echo (int) $u->id; ?></span>). Общая лента задач — в <a href="/nj595/admin/activity" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">«Активности»</a>.</p>
            </div>
        </div>
    </div>
</div>
