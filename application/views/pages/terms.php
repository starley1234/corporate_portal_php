<?php defined('SYSPATH') or die('No direct script access.');
/**
 * /terms — условия использования (по факту сайта: аккаунт, ИИ-результаты
 * «как есть», открытые источники, лимиты воркеров).
 * Дизайн — по эталону /pages/required_design_sample (радиусы: карточки xl,
 * бейджи md/full; p-4 → sm:p-6; контейнер px-3.5 pt-4 pb-16). Меню раздела —
 * в чипах шапки и левой панели «Навигация» (Controller_Pages::before),
 * в рабочей области меню нет.
 */
$ds_bc  = 'Сервисы';
$ds_cur = 'Условия использования';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <header class="n2j-page-head">
        <nav class="n2j-crumbs overflow-x-auto whitespace-nowrap" aria-label="Хлебные крошки">
            <a href="/"><?php echo HTML::chars($ds_bc); ?></a>
            <span class="n2j-crumbs-sep">/</span>
            <span class="n2j-crumbs-current" aria-current="page"><?php echo HTML::chars($ds_cur); ?></span>
        </nav>
        <h1 class="n2j-h1 mt-2.5"><?php echo HTML::chars($ds_cur); ?></h1>
        <p class="n2j-lead mt-2">Используя сайт <?php echo HTML::chars($site_name); ?>, вы соглашаетесь с этими условиями — постарались без канцелярита.</p>
    </header>

    <div class="mt-6 space-y-5">
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">1. Аккаунт</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Часть сервисов работает без регистрации; для долгих задач (3D, документы, дизайн, письма) нужен аккаунт — история и «Мои прогоны» привязаны к нему.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Вы отвечаете за сохранность пароля и за действия под своим аккаунтом.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Один человек — один аккаунт; «пачки» аккаунтов для обхода лимитов запрещены.</span></li>
                </ul>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">2. Что вы нам присылаете</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Присылая URL, тексты, данные компании (ОГРН/ИНН) или пожелания, вы подтверждаете, что вправе ими распоряжаться.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Запрещены противоправный контент и задачи, нарушающие закон.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Нельзя нагружать сервис автоматизацией сверх лимитов (число прогонов, вариантов, длина данных).</span></li>
                </ul>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">3. Результаты ИИ — «как есть»</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Тексты, документы, профили, письма и варианты дизайна готовят языковые модели — они могут ошибаться, проверяйте важное.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Результаты не являются юридической, финансовой или иной профессиональной консультацией.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>«Документы организации» собирает сведения из открытых источников по ОГРН/ИНН: сверяйте с первоисточниками (ЕГРЮЛ/ЕГРИП ФНС России) — сайт не официальный источник.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Какая модель выполнила работу, видно в карточке прогона; подмены модели не скрываются.</span></li>
                </ul>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">4. Прогоны, лимиты и доступность</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Свои прогоны вы удаляете сами — кнопка «Удалить» в списках «Мои …» (подтверждение в два клика).</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Мы можем менять лимиты, чистить зависшие задачи и останавливать злоупотребления.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Сервисы предоставляются «как есть»: возможны паузы на обслуживание; о сбоях сообщаем текстом ошибки прямо в интерфейсе.</span></li>
                </ul>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">5. Данные и изменения</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Обработка данных описана в <a href="/privacy" class="font-semibold text-blue-600 transition hover:text-blue-500 dark:text-blue-400">Политике конфиденциальности</a>.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Условия могут меняться; актуальная версия — всегда на этой странице.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Вопросы и обращения — через форму поддержки.</span></li>
                </ul>
            </div>
        </section>
        <p class="text-xs text-gray-400">Обновлено: 21.09.2026.</p>
    </div>
</div>
