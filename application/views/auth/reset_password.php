<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds flex min-h-[calc(100vh-4rem)] items-center justify-center px-3.5 py-10 sm:px-6">
    <div class="prx-card w-full max-w-md space-y-8 sm:p-8">
        <div>
            <div class="mx-auto h-12 w-12 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center">
                <i class="fas fa-key text-blue-600 dark:text-blue-400 text-2xl"></i>
            </div>
            <h1 class="prx-h1 mt-6 text-center">
                Новый пароль
            </h1>
            <p class="mt-2 text-center text-sm text-gray-600 dark:text-gray-400">
                Придумайте сложный пароль для защиты вашего аккаунта
            </p>
        </div>

        <?php if ($errors): ?>
            <div class="prx-flash prx-flash-err block" role="alert">
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo HTML::chars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form class="mt-8 space-y-6" action="" method="POST">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
            <div class="space-y-4">
                <div>
                    <label for="password" class="prx-label mb-1.5">Новый пароль</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400 text-sm"></i>
                        </div>
                        <input id="password" name="password" type="password" required
                            autocomplete="new-password"
                            class="prx-input pl-10"
                            placeholder="••••••••">
                    </div>
                </div>

                <div>
                    <label for="password_confirm" class="prx-label mb-1.5">Подтвердите пароль</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-shield-halved text-gray-400 text-sm"></i>
                        </div>
                        <input id="password_confirm" name="password_confirm" type="password" required
                            autocomplete="new-password"
                            class="prx-input pl-10"
                            placeholder="••••••••">
                    </div>
                </div>
            </div>

            <div>
                <button type="submit" class="prx-btn-cta w-full">
                    Обновить пароль
                </button>
            </div>
        </form>
    </div>
</div>
