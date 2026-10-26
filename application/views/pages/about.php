<?php defined('SYSPATH') or die('No direct script access.');
/**
 * /about — о сервисе N2J.ru. Список сервисов синхронизирован с манифестами
 * модулей (config/nj_module.php).
 * Дизайн — по эталону /pages/required_design_sample (радиусы: карточки xl,
 * бейджи md/full; p-4 → sm:p-6; контейнер px-3.5 pt-4 pb-16). Меню раздела —
 * в чипах шапки и левой панели «Навигация» (Controller_Pages::before),
 * в рабочей области меню нет.
 */
$ds_bc  = 'Сервисы';
$ds_cur = 'О сервисе';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <header class="n2j-page-head">
        <nav class="n2j-crumbs overflow-x-auto whitespace-nowrap" aria-label="Хлебные крошки">
            <a href="/"><?php echo HTML::chars($ds_bc); ?></a>
            <span class="n2j-crumbs-sep">/</span>
            <span class="n2j-crumbs-current" aria-current="page"><?php echo HTML::chars($ds_cur); ?></span>
        </nav>
        <h1 class="n2j-h1 mt-2.5"><?php echo HTML::chars($ds_cur); ?></h1>
        <p class="n2j-lead mt-2">Набор небольших прикладных сервисов: мгновенные считалки — в браузере, долгие задачи — ИИ-воркеры с живым прогрессом и честным статусом. По-русски, без рекламы; часть сервисов — без регистрации.</p>
    </header>

    <div class="mt-6 space-y-5">
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">Что внутри</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <div class="grid gap-2.5 sm:grid-cols-2">
                <a href="/vat" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-percent"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Калькулятор НДС</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">НДС 20/10% сверху, внутри и выделение</span></span>
                </a>
                <a href="/summa-propisyu" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-spell-check"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Сумма прописью</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">Число словами для документов и счетов</span></span>
                </a>
                <a href="/password" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-key"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Генератор паролей</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">Надёжные пароли с настраиваемой сложностью</span></span>
                </a>
                <a href="/poll" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-square-poll-horizontal"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Быстрый опрос</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">Опрос за 10 секунд и ссылка друзьям</span></span>
                </a>
                <a href="/jump" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-link"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Сократить ссылку</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">Короткая ссылка и статистика переходов</span></span>
                </a>
                <a href="/review" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-reply"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Отзыв 2 Ответ</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">Вежливый ответ на отзыв клиента</span></span>
                </a>
                <a href="/jobs" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-briefcase"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Поиск вакансий</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">Подбор вакансий и сопроводительные письма</span></span>
                </a>
                <a href="/3d" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-cube"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">3D-модель по тексту</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">STL/PNG по описанию: фикс-цикл и рендер</span></span>
                </a>
                <a href="/docs" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-file-lines"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Документы организации</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">Пакет DOCX/PDF/XLSX по ОГРН/ИНН</span></span>
                </a>
                <a href="/design" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-palette"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Дизайн страницы</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">Варианты улучшенного оформления по URL</span></span>
                </a>
                <a href="/company" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-building-user"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Профиль компании</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">Сфера, размер, контакты и ЛПР по сайту</span></span>
                </a>
                <a href="/coldmail" class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-envelope-open-text"></i></span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">Холодное письмо</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">Персонализированный первый абзац с крючком</span></span>
                </a>
                </div>
                <p class="mt-3 text-xs text-gray-400">Полный и всегда актуальный список — на главной и в левой панели «Навигация».</p>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">Как это устроено</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Каждый сервис — отдельный модуль: своя страница, история прогонов, админка.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Мгновенные инструменты (калькуляторы, генераторы) работают прямо в браузере.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Долгие задачи (3D, документы, дизайн, профили, письма) выполняют воркеры на отдельном сервере: задача встаёт в очередь, вы видите статус, промежуточный прогресс и секундомер, прогон можно остановить.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Тексты и разборы готовят языковые модели; какая модель выполнила работу — показываем в карточке прогона.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Свои прогоны вы удаляете сами — в два клика по кнопке «Удалить».</span></li>
                </ul>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800 sm:p-6">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">Принципы</h2>
            </div>
            <div class="mt-3.5 text-sm text-gray-600 dark:text-gray-300">
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Честность: ошибки — с полной причиной и кнопкой «Повторить», без «попробуйте ещё раз».</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Данные: не продаём и не передаём третьим лицам для рекламы — см. Политику конфиденциальности.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Единый внешний вид и поведение всех модулей закреплены в <a href="/pages/current_design_sample" class="font-semibold text-blue-600 transition hover:text-blue-500 dark:text-blue-400">дизайн-контракте</a>.</span></li>
                </ul>
            </div>
        </section>
        <p class="text-xs text-gray-400">Обновлено: 21.09.2026.</p>
    </div>
</div>
