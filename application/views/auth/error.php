<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prl-ds flex min-h-[calc(100vh-4rem)] items-center justify-center px-3.5 py-10 sm:px-6">
    <div class="prl-card w-full max-w-md space-y-8 sm:p-8 text-center">
        <div>
            <div class="mx-auto h-16 w-16 bg-rose-100 dark:bg-rose-900/30 rounded-full flex items-center justify-center mb-6">
                <i class="fas fa-exclamation-triangle text-rose-600 dark:text-rose-400 text-2xl"></i>
            </div>
            <h1 class="prl-h1">
                Произошла ошибка
            </h1>
            <p class="mt-4 text-gray-600 dark:text-gray-400">
                <?php echo HTML::chars($message); ?>
            </p>
        </div>

        <div class="pt-4">
            <a href="/auth/login" class="prl-btn-cta">
                Вернуться к входу
                <i class="fas fa-rotate-left text-sm"></i>
            </a>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-500">
            Если вы считаете, что это ошибка, пожалуйста, свяжитесь с поддержкой: <a href="mailto:info@mlcode.ru" class="underline hover:text-gray-700 dark:hover:text-gray-300">info@mlcode.ru</a>
        </p>
    </div>
</div>
