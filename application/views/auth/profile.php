<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php /* Профиль — каркас эталона v6: шапка, вкладки .prl-seg, карточка без градиентов */ ?>
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Кабинет', '/dashboard'], ['Профиль']],
        'title'   => 'Профиль',
        'actions' => [['label' => 'Выйти', 'href' => '/auth/logout', 'icon' => 'fa-right-from-bracket', 'role' => 'secondary']],
    ]); ?>

    <div class="mt-6 max-w-4xl space-y-5">
    <nav class="prl-seg max-w-md" aria-label="Разделы профиля">
        <span class="prl-seg-item is-active flex-1 text-center" aria-current="page">Профиль и данные</span>
        <a href="/account/security" class="prl-seg-item flex-1 text-center">Безопасность</a>
    </nav>
    <div class="prl-card">
        <div>
            <div class="mb-8 flex items-center gap-4">
                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-blue-600 text-2xl font-bold text-white">
                    <?php echo HTML::chars(strtoupper(substr($user->username, 0, 1))); ?>
                </div>
                <div class="min-w-0">
                    <h2 class="prl-h2 truncate"><?php echo HTML::chars($user->username); ?></h2>
                    <p class="truncate text-gray-500 dark:text-gray-400"><?php echo HTML::chars($user->email); ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Account Info -->
                <div class="space-y-4">
                    <h3 class="prl-h3 border-b border-gray-100 pb-2 dark:border-gray-700">
                        Информация об аккаунте
                    </h3>

                    <div class="flex items-center p-3 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                        <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400 mr-4">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider font-bold">Дата регистрации</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                <?php echo date('d.m.Y', $user->last_login); // Предполагая наличие поля или используя last_login ?>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center p-3 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                        <div class="w-10 h-10 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center text-emerald-600 dark:text-emerald-400 mr-4">
                            <i class="fas fa-shield-halved"></i>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider font-bold">Статус Email</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                <?php if ($user->email_confirmed): ?>
                                    <span class="text-emerald-600 dark:text-emerald-400"><i class="fas fa-check-circle mr-1"></i> Подтвержден</span>
                                <?php else: ?>
                                    <span class="text-amber-600 dark:text-amber-400"><i class="fas fa-exclamation-triangle mr-1"></i> Ожидает подтверждения</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Security/Actions -->
                <div class="space-y-4">
                    <h3 class="prl-h3 border-b border-gray-100 pb-2 dark:border-gray-700">
                        Безопасность
                    </h3>

                    <div class="flex items-center p-3 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                        <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400 mr-4">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider font-bold">Последний вход</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                <?php echo $user->last_login ? date('d.m.Y H:i', $user->last_login) : 'Первый вход'; ?>
                            </p>
                        </div>
                    </div>

                    <a href="/auth/forgot_password" class="flex items-center p-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition-colors group">
                        <div class="w-10 h-10 rounded-lg bg-gray-200 dark:bg-gray-600 flex items-center justify-center text-gray-600 dark:text-gray-300 mr-4 group-hover:bg-blue-100 dark:group-hover:bg-blue-900/30 group-hover:text-blue-600 transition-colors">
                            <i class="fas fa-key"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Сменить пароль</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Запросить ссылку на сброс</p>
                        </div>
                        <i class="fas fa-chevron-right ml-auto text-gray-300 group-hover:text-blue-500 transition-colors"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
