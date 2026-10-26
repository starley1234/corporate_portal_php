<?php /* страница ошибки — компоненты v6; узкая колонка по центру (flex, без mx-auto) */ ?>
<div class="n2j-ds flex w-full justify-center px-3.5 pt-10 pb-16 sm:px-6 lg:px-8">
<div class="w-full max-w-2xl text-center">
    <div class="mb-8">
        <div class="inline-flex items-center justify-center w-24 h-24 bg-rose-100 dark:bg-rose-900/30 rounded-full mb-6">
            <i class="fas fa-shield text-4xl text-rose-600 dark:text-rose-400"></i>
        </div>
        <h1 class="n2j-h1 mb-4">Доступ ограничен</h1>
        <p class="text-lg text-gray-600 dark:text-gray-400">К сожалению, у вас нет прав для просмотра этой страницы или выполнения этого действия.</p>
    </div>

    <?php if (isset($message) && $message): ?>
        <div class="n2j-flash n2j-flash-warn mb-8 block text-left">
            <span class="font-bold">Причина:</span> <?php echo HTML::chars($message); ?>
        </div>
    <?php endif; ?>

    <div class="n2j-card mb-8 text-left">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-4">Возможные причины:</h3>
        <ul class="space-y-3">
            <li class="flex items-start">
                <i class="fas fa-circle-info mt-1 mr-3 text-blue-500 shrink-0"></i>
                <span class="text-gray-700 dark:text-gray-300">Требуются специальные разрешения для доступа к ресурсу.</span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-user-slash mt-1 mr-3 text-blue-500 shrink-0"></i>
                <span class="text-gray-700 dark:text-gray-300">Вы не авторизованы или уровень доступа вашей учетной записи недостаточен.</span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-link mt-1 mr-3 text-blue-500 shrink-0"></i>
                <span class="text-gray-700 dark:text-gray-300">Введен некорректный адрес страницы.</span>
            </li>
        </ul>
    </div>

    <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
        <a href="<?php echo URL::base(); ?>" class="n2j-btn-cta w-full sm:w-auto">
            <i class="fas fa-home"></i>На главную
        </a>
        
        <?php if ( ! Auth::instance()->logged_in()): ?>
            <a href="<?php echo URL::site('auth/login'); ?>" class="n2j-btn-secondary w-full sm:w-auto">
                <i class="fas fa-sign-in-alt"></i>Войти в систему
            </a>
        <?php endif; ?>
        
        <a href="<?php echo URL::site('contact'); ?>" class="n2j-btn-secondary w-full sm:w-auto">
            <i class="fas fa-envelope"></i>Поддержка
        </a>
    </div>

    <p class="mt-12 text-sm text-gray-500 dark:text-gray-500">
        Если вы считаете, что это ошибка, пожалуйста, свяжитесь с администратором сайта.
    </p>
</div>
</div>
