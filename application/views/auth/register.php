<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds flex min-h-[calc(100vh-4rem)] items-center justify-center px-3.5 py-10 sm:px-6">
    <div class="n2j-card w-full max-w-md space-y-8 sm:p-8">
        <div>
            <div class="mx-auto h-12 w-12 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center">
                <i class="fas fa-user-plus text-blue-600 dark:text-blue-400 text-2xl"></i>
            </div>
            <h1 class="n2j-h1 mt-6 text-center">
                Создать аккаунт
            </h1>
            <p class="mt-2 text-center text-sm text-gray-600 dark:text-gray-400">
                Уже есть профиль?
                <a href="/auth/login" class="n2j-link">
                    Войти в систему
                </a>
            </p>
        </div>

        <?php if ($errors): ?>
            <div class="n2j-flash n2j-flash-err block" role="alert">
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo HTML::chars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form class="mt-8 space-y-6" action="/auth/register" method="POST">
            <div class="space-y-4">
                <div>
                    <label for="username" class="n2j-label mb-1.5">Имя пользователя</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-gray-400 text-sm"></i>
                        </div>
                        <input id="username" name="username" type="text" autocomplete="off" required 
                            value="<?php echo HTML::chars(Arr::get($post, 'username')); ?>"
                            autocomplete="off"
                            class="n2j-input pl-10" 
                            placeholder="ivan_ivanov">
                    </div>
                </div>

                <div>
                    <label for="email" class="n2j-label mb-1.5">Email адрес</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400 text-sm"></i>
                        </div>
                        <input id="email" name="email" type="email" required 
                            value="<?php echo HTML::chars(Arr::get($post, 'email')); ?>"
                            autocomplete="off"
                            class="n2j-input pl-10" 
                            placeholder="example@mail.ru">
                    </div>
                </div>

                <div>
                    <label for="password" class="n2j-label mb-1.5">Пароль</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400 text-sm"></i>
                        </div>
                        <input id="password" name="password" type="password" required 
                            autocomplete="new-password"
                            class="n2j-input pl-10" 
                            placeholder="••••••••">
                    </div>
                </div>

                <div>
                    <label for="password_confirm" class="n2j-label mb-1.5">Подтверждение пароля</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-shield-halved text-gray-400 text-sm"></i>
                        </div>
                        <input id="password_confirm" name="password_confirm" type="password" required 
                            autocomplete="new-password"
                            class="n2j-input pl-10" 
                            placeholder="••••••••">
                    </div>
                </div>
            </div>

            <div class="flex items-start">
                <div class="flex items-center h-5">
                    <input id="terms" name="terms" type="checkbox" required class="h-4 w-4 rounded border-gray-300 accent-blue-600 dark:border-gray-600">
                </div>
                <div class="ml-3 text-sm">
                    <label for="terms" class="text-gray-600 dark:text-gray-400">
                        Я согласен с <a href="/terms" class="n2j-link">условиями использования</a> и <a href="/privacy" class="n2j-link">политикой конфиденциальности</a>
                    </label>
                </div>
            </div>

            <div>
                <button type="submit" class="n2j-btn-cta w-full">
                    Зарегистрироваться
                </button>
            </div>
        </form>
    </div>
</div>