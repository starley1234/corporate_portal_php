<?php defined('SYSPATH') or die('No direct script access.');
/**
 * /privacy — политика конфиденциальности (по факту сайта: ubid для гостей,
 * сессия, логи IP, содержимое прогонов, LLM-провайдеры, удаление владельцем).
 * Дизайн — по эталону /pages/required_design_sample (радиусы: карточки xl,
 * бейджи md/full; p-4 → sm:p-6; контейнер px-3.5 pt-4 pb-16). Меню раздела —
 * в чипах шапки и левой панели «Навигация» (Controller_Pages::before),
 * в рабочей области меню нет.
 */
$ds_bc  = 'Сервисы';
$ds_cur = 'Конфиденциальность';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <header class="n2j-page-head">
        <nav class="n2j-crumbs overflow-x-auto whitespace-nowrap" aria-label="Хлебные крошки">
            <a href="/"><?php echo HTML::chars($ds_bc); ?></a>
            <span class="n2j-crumbs-sep">/</span>
            <span class="n2j-crumbs-current" aria-current="page"><?php echo HTML::chars($ds_cur); ?></span>
        </nav>
        <h1 class="n2j-h1 mt-2.5"><?php echo HTML::chars($ds_cur); ?></h1>
        <p class="n2j-lead mt-2">Коротко: собираем только то, что нужно для работы сервисов; рекламы нет, данные не продаём.</p>
    </header>

    <div class="mt-6 space-y-5">
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">1. Какие данные мы обрабатываем</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span><span class="font-semibold text-gray-800 dark:text-gray-200">Аккаунт:</span> логин, e-mail, хеш пароля (пароль в открытом виде не хранится).</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span><span class="font-semibold text-gray-800 dark:text-gray-200">Технические:</span> IP-адрес, User-Agent, время запросов — в журналах безопасности.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span><span class="font-semibold text-gray-800 dark:text-gray-200">Cookie:</span> сессия входа; <code class="rounded-md bg-gray-100 px-1 font-mono text-xs dark:bg-gray-700">ubid</code> — анонимный идентификатор гостя (сервисы без регистрации); настройки интерфейса (тёмная тема).</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span><span class="font-semibold text-gray-800 dark:text-gray-200">Содержимое прогонов:</span> то, что вы присылаете (URL, пожелания, данные компании), и то, что создано (профили, письма, варианты, документы), включая служебные поля.</span></li>
                </ul>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">2. Зачем это нужно</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>выполнить вашу задачу: очередь воркеров, статусы, «Мои прогоны»;</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>безопасность: защита от перебора, злоупотреблений и аварий;</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>память интерфейса: тема, настройки формы.</span></li>
                </ul>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">3. Кто ещё видит данные</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Для генерации текстов и разборов содержимое задачи получают LLM-провайдеры (например, OpenRouter) и модели на нашем собственном сервере — только для выполнения вашей задачи.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Никакой сторонней рекламы и трекеров; данные не продаются.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Данные могут раскрываться только по требованию закона.</span></li>
                </ul>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">4. Хранение и удаление</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Прогоны хранятся, пока вы не удалите их сами: кнопка «Удалить» в списках «Мои …» стирает запись и файлы полностью.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Файлы вариантов и документов лежат в закрытых хранилищах сайта (не в публичной папке).</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Журналы безопасности храним ограниченное время.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Удалить аккаунт и связанные данные — запрос через форму поддержки.</span></li>
                </ul>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">5. Cookie и ваши права</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Cookie можно отключить в браузере, но сломаются вход, «Мои прогоны» и часть анонимных сервисов.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Вы вправе узнать, исправить и удалить свои данные: профиль и кнопки удаления в сервисах либо запрос в поддержку.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Политика может меняться; актуальная версия — всегда на этой странице.</span></li>
                </ul>
            </div>
        </section>
        <p class="text-xs text-gray-400">Обновлено: 21.09.2026.</p>
    </div>
</div>
