<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds flex min-h-[calc(100vh-4rem)] items-center justify-center px-3.5 py-10 sm:px-6">
    <div class="prx-card w-full max-w-md space-y-8 sm:p-8">
        <div>
            <h1 class="prx-h1 mt-6 text-center">
                Вход в систему
            </h1>
            <p class="mt-2 text-center text-sm text-gray-500">Доступ предоставляется администратором организации.</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="prx-flash prx-flash-err" role="alert">
                <div class="flex items-start gap-3">
                    <div class="shrink-0">
                        <i class="fas fa-circle-exclamation text-rose-500"></i>
                    </div>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <p class="text-sm text-rose-700 dark:text-rose-400"><?php echo HTML::chars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form class="mt-8 space-y-6" action="/auth/login" method="POST">
            <input type="hidden" name="csrf" value="<?= HTML::chars(Security::token()) ?>">
            <div class="space-y-4">
                <div>
                    <label for="username" class="prx-label mb-1.5">Имя пользователя</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-gray-400"></i>
                        </div>
                        <input id="username" name="username" type="text" required
                            class="prx-input pl-10"
                            placeholder="Введите логин">
                    </div>
                </div>
                <div>
                    <label for="password" class="prx-label mb-1.5">Пароль</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input id="password" name="password" type="password" required
                            class="prx-input pl-10"
                            placeholder="••••••••">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <input id="remember" name="remember" type="checkbox"
                        class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
                    <label for="remember" class="ml-2 block text-sm text-gray-900 dark:text-gray-300">
                        Запомнить меня
                    </label>
                </div>

                <div class="text-sm">
                    <a href="/auth/forgot_password" class="prx-link">
                        Забыли пароль?
                    </a>
                </div>
            </div>

            <div>
                <button type="submit" class="prx-btn-cta w-full">
                    <i class="fas fa-right-to-bracket"></i><span>Войти</span>
                </button>
            </div>
        </form>



    </div>
</div>
