<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Плашка согласия на cookie (выводится только пока нет куки согласия).
 * Дизайн — Tailwind сайта (светлая/тёмная тема). Контракт не менять:
 * #cookie-consent-popup / #cookie-consent-close + AJAX GET /cookie_consent/close.
 */
?>

<div id="cookie-consent-popup" style="display:none"
     class="fixed bottom-4 left-4 z-[9999] w-[calc(100%-2rem)] max-w-md rounded-2xl border border-gray-200 bg-white/95 p-4 shadow-xl backdrop-blur dark:border-gray-700 dark:bg-gray-800/95">

    <div class="flex items-start gap-3">
        <span class="mt-0.5 shrink-0 rounded-lg bg-blue-50 p-2 text-blue-600 dark:bg-blue-950 dark:text-blue-400">
            <i class="fas fa-cookie-bite text-sm"></i>
        </span>

        <div class="min-w-0 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
            <p class="font-semibold text-gray-900 dark:text-white">N2J.ru использует cookie</p>
            <p class="mt-1">
                Продолжая пользоваться сайтом, вы соглашаетесь на обработку cookie, IP-адреса
                и данных браузера — для аутентификации, аналитики и работы сервисов.
                Мы не продаём эти данные третьим лицам.
            </p>
            <p class="mt-1 text-gray-400 dark:text-gray-500">
                Не хотите — просто закройте сайт.
            </p>
        </div>
    </div>

    <div class="mt-3 flex justify-end">
        <button type="button" id="cookie-consent-close"
                class="n2j-btn-cta n2j-btn-sm">
            <i class="fas fa-check"></i>Понятно
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var popup = document.getElementById('cookie-consent-popup');
    var closeButton = document.getElementById('cookie-consent-close');

    if (popup) {
        setTimeout(function() {
            popup.style.display = 'block';
        }, 500);

        closeButton.addEventListener('click', function() {
            popup.style.display = 'none';

            var xhr = new XMLHttpRequest();
            xhr.open('GET', '/cookie_consent/close', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send();
        });
    }
});
</script>
