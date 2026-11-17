<?php /* страница ошибки — компоненты v6; узкая колонка по центру (flex, без mx-auto) */ ?>
<div class="prx-ds flex w-full justify-center px-3.5 pt-10 pb-16 sm:px-6 lg:px-8">
<div class="w-full max-w-2xl text-center">
    <div class="mb-8">
        <div class="inline-flex items-center justify-center w-24 h-24 bg-blue-100 dark:bg-blue-900/30 rounded-full mb-6">
            <i class="fas fa-search text-4xl text-blue-600 dark:text-blue-400"></i>
        </div>
        <h1 class="prx-h1 mb-4">Страница не найдена</h1>
        <p class="text-lg text-gray-600 dark:text-gray-400">К сожалению, запрашиваемая вами страница не существует или была перемещена по новому адресу.</p>
    </div>

    <?php if (isset($message) && $message): ?>
        <div class="prx-flash prx-flash-warn mb-8 block text-left">
            <span class="font-bold">Детали:</span> <?php echo HTML::chars($message); ?>
        </div>
    <?php endif; ?>

    <div class="prx-card mb-8 text-left">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-4">Что могло произойти:</h3>
        <ul class="space-y-3">
            <li class="flex items-start">
                <i class="fas fa-circle-question mt-1 mr-3 text-blue-500 shrink-0"></i>
                <span class="text-gray-700 dark:text-gray-300">В адресе допущена опечатка или ошибка.</span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-link-slash mt-1 mr-3 text-blue-500 shrink-0"></i>
                <span class="text-gray-700 dark:text-gray-300">Ссылка, по которой вы перешли, устарела или ведет на удаленный контент.</span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-arrows-rotate mt-1 mr-3 text-blue-500 shrink-0"></i>
                <span class="text-gray-700 dark:text-gray-300">Страница была перемещена в другой раздел сайта.</span>
            </li>
        </ul>
    </div>

    <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
        <a href="<?php echo URL::base(); ?>" class="prx-btn-cta w-full sm:w-auto">
            <i class="fas fa-home"></i>На главную
        </a>

        <button onclick="history.back();" class="prx-btn-secondary w-full sm:w-auto">
            <i class="fas fa-arrow-left"></i>Вернуться назад
        </button>

        <a href="<?php echo URL::site('contact'); ?>" class="prx-btn-secondary w-full sm:w-auto">
            <i class="fas fa-envelope"></i>Сообщить об ошибке
        </a>
    </div>

    <p class="mt-12 text-sm text-gray-500 dark:text-gray-500">
        Код ошибки: 404 Not Found
    </p>
</div>
</div>
