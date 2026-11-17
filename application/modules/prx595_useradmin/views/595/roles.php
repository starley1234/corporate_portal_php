<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx595: роли — состав, создание, удаление неиспользуемых (2 клика).
 */
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/prx595/admin" class="transition hover:text-blue-600 dark:hover:text-blue-400">Пользователи</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Роли</span>
    </nav>
    <h1 class="prx-h1 mt-2"><i class="fas fa-user-tag mr-2 text-blue-500"></i>Роли</h1>

    <?php if ((string) $flash !== ''): ?>
    <div class="prx-flash prx-flash-info mt-3" role="status">
        <i class="fas fa-circle-info shrink-0"></i><span><?php echo HTML::chars($flash); ?></span>
    </div>
    <?php endif; ?>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Таблица ролей (2/3) ============ -->
        <div class="min-w-0 md:col-span-2">
            <div class="prx-card p-0 overflow-x-auto">
                <table class="prx-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pl-4 pr-3 sm:pl-5">Роль</th>
                            <th class="py-2.5 px-3">Описание</th>
                            <th class="py-2.5 px-3">Пользователей</th>
                            <th class="py-2.5 pr-4 text-right sm:pr-5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                        <?php foreach ($roles as $r): $core = in_array((string) $r['name'], ['login', 'admin'], TRUE); ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="py-2.5 pl-4 pr-3 sm:pl-5"><span class="prx-count uppercase"><?php echo HTML::chars($r['name']); ?></span><?php echo $core ? ' <span class="text-xs text-gray-400">системная</span>' : ''; ?></td>
                            <td class="max-w-[20rem] truncate px-3 py-2.5" title="<?php echo HTML::chars((string) $r['description']); ?>"><?php echo HTML::chars((string) $r['description']); ?></td>
                            <td class="px-3 py-2.5 font-mono"><?php echo (int) $r['users']; ?></td>
                            <td class="py-2.5 pr-4 text-right sm:pr-5">
                                <?php if ( ! $core): ?>
                                <form method="post" action="/prx595/admin/role_delete/<?php echo (int) $r['id']; ?>">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <button data-prx-arm="Удалить?" type="submit" class="prx-btn-danger prx-btn-sm">удалить</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="prx-hint mt-3">Удалить можно только роль без пользователей. Роли назначаются в карточке пользователя (вкладка «Пользователи»).</p>
        </div>

        <!-- ============ Создание роли (1/3) ============ -->
        <div class="min-w-0 space-y-5 md:col-span-1">
            <div class="prx-card">
                <h3 class="prx-h3 border-b border-gray-100 pb-3 dark:border-gray-700"><i class="fas fa-plus mr-1.5 text-blue-500"></i>Новая роль</h3>
                <form method="post" action="/prx595/admin/roles" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div>
                        <label for="role-name" class="prx-label">Имя (латиница, 2–32)</label>
                        <input type="text" id="role-name" name="name" required autocomplete="off" placeholder="manager"
                               class="prx-input mt-1 w-full">
                    </div>
                    <div>
                        <label for="role-desc" class="prx-label">Описание</label>
                        <input type="text" id="role-desc" name="description" autocomplete="off" placeholder="Управляет отзывами и вакансиями"
                               class="prx-input mt-1 w-full">
                    </div>
                    <button type="submit" class="prx-btn-cta w-full">Добавить роль</button>
                </form>
            </div>

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-circle-info text-blue-500"></i>
                    <h3 class="text-sm font-bold">Что дают роли</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><b>login</b> и <b>admin</b> — системные: их нельзя удалить или переименовать.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Пока свои роли влияют только на состав пользователей — административные разделы открывает роль admin.</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

