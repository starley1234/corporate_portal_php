<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <label for="nj620-token" class="n2j-label">JWT-токен</label>
        <textarea id="nj620-token" spellcheck="false" autocomplete="off"
                  placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.…"
                  class="n2j-textarea mt-2 min-h-[140px] w-full font-mono leading-relaxed"
        ><?php echo HTML::chars($token); ?></textarea>
        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" id="nj620-demo" class="n2j-btn-secondary">
                <i class="fas fa-wand-magic-sparkles mr-1"></i>Демо-токен
            </button>
            <button type="button" id="nj620-clear" class="n2j-btn-secondary">
                <i class="fas fa-eraser mr-1"></i>Очистить
            </button>
        </div>
    </div>

    <div id="nj620-out" class="mt-4 space-y-4"></div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Структура JWT</p>
        <p class="mt-3 font-mono text-xs leading-relaxed text-gray-600 dark:text-gray-300">
            <b>header</b>.<b>payload</b>.<b>signature</b>
        </p>
        <p class="n2j-hint mt-2">
            header и payload — JSON в base64url. Декодер раскрывает их содержимое и
            переводит exp/iat/nbf в даты. Подпись не проверяется.
        </p>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Безопасно ли вставлять токен?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: разбор происходит локально в браузере, данные никуда не отправляются.
                    Но не вставляйте токены в общедоступные места и логи.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Проверяет ли сервис подпись?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет. Это дебаг-инструмент для просмотра содержимого токена, а не валидатор подписи.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('620/script'); ?>
