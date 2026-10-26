<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prl-ds flex min-h-[calc(100vh-4rem)] items-center justify-center px-3.5 py-10 sm:px-6">
    <div class="prl-card w-full max-w-md space-y-8 sm:p-8 text-center">
        <div>
            <div class="mx-auto h-16 w-16 bg-emerald-100 dark:bg-emerald-900/30 rounded-full flex items-center justify-center mb-6">
                <i class="fas fa-paper-plane text-emerald-600 dark:text-emerald-400 text-2xl"></i>
            </div>
            <h1 class="prl-h1">
                Почти готово!
            </h1>
            <p class="mt-4 text-gray-600 dark:text-gray-400">
                Мы отправили письмо для подтверждения регистрации на адрес:
            </p>
            <p class="mt-2 text-lg font-semibold text-blue-600 dark:text-blue-400">
                <?php echo HTML::chars($email); ?>
            </p>
        </div>

        <div class="prl-flash prl-flash-info block">
            <p>Пожалуйста, проверьте вашу почту и перейдите по ссылке в письме для активации аккаунта. Если письма нет, проверьте папку «Спам».</p>
        </div>

        <div class="pt-4">
            <a href="/auth/login" class="prl-btn-cta">
                Перейти к входу
                <i class="fas fa-arrow-right text-sm"></i>
            </a>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-500">
            Возникли проблемы? Напишите нам на <a href="mailto:info@mlcode.ru" class="underline hover:text-gray-700 dark:hover:text-gray-300">info@mlcode.ru</a>
        </p>
    </div>
</div>
