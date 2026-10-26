<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds flex min-h-[calc(100vh-4rem)] items-center justify-center px-3.5 py-10 sm:px-6">
    <div class="n2j-card w-full max-w-md space-y-8 sm:p-8">
        <div>
            <h1 class="n2j-h1 mt-6 text-center">
                Вход в систему
            </h1>
            <p class="mt-2 text-center text-sm text-gray-600 dark:text-gray-400">
                Или
                <a href="/auth/register" class="n2j-link">
                    зарегистрируйте новый аккаунт
                </a>
            </p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="n2j-flash n2j-flash-err" role="alert">
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
            <div class="space-y-4">
                <div>
                    <label for="username" class="n2j-label mb-1.5">Имя пользователя</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-gray-400"></i>
                        </div>
                        <input id="username" name="username" type="text" required 
                            class="n2j-input pl-10" 
                            placeholder="Введите логин">
                    </div>
                </div>
                <div>
                    <label for="password" class="n2j-label mb-1.5">Пароль</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input id="password" name="password" type="password" required 
                            class="n2j-input pl-10" 
                            placeholder="••••••••">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <input id="remember" name="remember" type="checkbox" 
                        class="h-4 w-4 rounded border-gray-300 accent-blue-600 dark:border-gray-600">
                    <label for="remember" class="ml-2 block text-sm text-gray-900 dark:text-gray-300">
                        Запомнить меня
                    </label>
                </div>

                <div class="text-sm">
                    <a href="/auth/forgot_password" class="n2j-link">
                        Забыли пароль?
                    </a>
                </div>
            </div>

            <div>
                <button type="submit" class="n2j-btn-cta w-full">
                    <i class="fas fa-right-to-bracket"></i><span>Войти</span>
                </button>
            </div>
        </form>

        <?php if (class_exists('Service_Nj659_Yandexauth')): ?>
            <div class="mt-6">
                <div class="relative" aria-hidden="true">
                    <div class="absolute inset-0 flex items-center"><span class="w-full border-t border-gray-200 dark:border-gray-700"></span></div>
                    <div class="relative flex justify-center text-xs uppercase tracking-wide"><span class="bg-white px-2 text-gray-400 dark:bg-gray-800 dark:text-gray-500">или</span></div>
                </div>
                <?php echo (new Service_Nj659_Yandexauth)->login_button('Войти через Яндекс',
                    'group relative mt-4 flex w-full justify-center rounded-lg border border-transparent bg-[#fc3f1d] px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition-all duration-200 hover:bg-[#e03a15] focus:outline-none focus:ring-2 focus:ring-[#fc3f1d] focus:ring-offset-2'); ?>
                <p class="mt-3 text-center text-xs leading-relaxed text-gray-400 dark:text-gray-500">Первый вход создаст аккаунт с вашим email из Яндекса.</p>
            </div>
        <?php endif; ?>

    </div>
</div>