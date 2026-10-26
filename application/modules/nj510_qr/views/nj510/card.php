<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-qrcode" aria-hidden="true"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white"><a href="/qr">Генератор QR-кодов</a></h2>
        <span class="n2j-badge n2j-badge-ok ml-auto">бесплатно</span>
    </div>
    <p class="n2j-hint mt-2">Красивые коды с вашим логотипом. Содержимое не покидает браузер.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/qr" class="hover:text-blue-600 dark:hover:text-blue-400">→ Сделать QR-код с логотипом</a></li>
        <li><a href="/qr?mode=batch" class="hover:text-blue-600 dark:hover:text-blue-400">→ До 200 кодов одним ZIP-архивом</a></li>
        <li><a href="/qr?type=wifi" class="hover:text-blue-600 dark:hover:text-blue-400">→ Подключение к Wi-Fi без ввода пароля</a></li>
        <li><a href="/qr?type=vcard" class="hover:text-blue-600 dark:hover:text-blue-400">→ Визитка для сохранения контакта</a></li>
    </ul>
</div>
