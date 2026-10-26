<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds flex min-h-[calc(100vh-4rem)] items-center justify-center px-3.5 py-10 sm:px-6">
    <div class="n2j-card w-full max-w-md space-y-8 sm:p-8">
        <div>
            <div class="mx-auto h-12 w-12 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center">
                <i class="fas fa-key text-blue-600 dark:text-blue-400 text-2xl"></i>
            </div>
            <h1 class="n2j-h1 mt-6 text-center">
                Восстановление пароля
            </h1>
            <p class="mt-2 text-center text-sm text-gray-600 dark:text-gray-400">
                Вспомнили пароль?
                <a href="/auth/login" class="n2j-link">
                    Вернуться ко входу
                </a>
            </p>
        </div>

        <?php if ($success): ?>
            <div class="n2j-flash n2j-flash-ok block" role="status">
                Инструкции по сбросу пароля отправлены на ваш email адрес.
            </div>
        <?php else: ?>
            <?php if ($errors): ?>
                <div class="n2j-flash n2j-flash-err block" role="alert">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo HTML::chars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form class="mt-8 space-y-6" action="/auth/forgot_password" method="POST">
                <div>
                    <label for="email" class="n2j-label mb-1.5">Email адрес</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400 text-sm"></i>
                        </div>
                        <input id="email" name="email" type="email" required 
                            class="n2j-input pl-10" 
                            placeholder="example@mail.ru">
                    </div>
                </div>

                <div>
                    <button type="submit" class="n2j-btn-cta w-full">
                        Сбросить пароль
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>