<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Путеводитель: обзор сервисов по разделам (автосборка из манифестов nj110).
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Путеводитель</span>
    </nav>
    <h1 class="n2j-h1 mt-2"><i class="fas fa-map-location-dot mr-2 text-blue-500"></i>Путеводитель по платформе</h1>
    <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Что умеет N2J.ru и как этим пользоваться. Разделы собраны автоматически из описаний сервисов — документация живёт вместе с платформой. Сервисов: <?php echo (int) $total; ?>.</p>

    <?php $card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs transition hover:border-blue-300 hover:shadow-sm dark:border-gray-700/80 dark:bg-gray-800 dark:hover:border-blue-700'; ?>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Разделы сервисов (2/3) ============ -->
        <div class="min-w-0 space-y-6 md:col-span-2">
            <?php foreach ($groups as $group => $items): ?>
            <section>
                <h2 class="n2j-h3 border-b border-gray-100 pb-3"><?php echo HTML::chars((string) $group); ?></h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <?php foreach ($items as $m): ?>
                    <a href="/guide/<?php echo HTML::chars($m['slug']); ?>" class="<?php echo $card; ?>">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300"><i class="<?php echo HTML::chars((string) $m['icon']); ?>"></i></span>
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-bold text-gray-900 dark:text-white">
                                    <?php echo HTML::chars((string) $m['title']); ?>
                                    <?php if ( ! empty($m['presented'])): ?><span class="n2j-badge n2j-badge-ok uppercase">инструкция</span><?php endif; ?>
                                </p>
                                <p class="mt-1 text-xs text-gray-600 sm:text-sm dark:text-gray-300"><?php echo HTML::chars((string) ($m['tagline'] ?: $m['short'])); ?></p>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
        </div>

        <!-- ============ Компаньон (1/3) ============ -->
        <div class="hidden space-y-5 md:block">
            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-compass text-blue-500"></i>
                    <h3 class="text-sm font-bold">Как освоить за 15 минут</h3>
                </div>
                <ol class="mt-2.5 list-decimal space-y-2 pl-5 text-xs text-gray-600 dark:text-gray-300">
                    <li>Пробегитесь по карточкам разделов — отметьте, что нужно вам.</li>
                    <li>Откройте 2–3 инструкции — там шаги, схемы и советы.</li>
                    <li>Попробуйте на своей задаче: расчёты выполняются сразу, подготовка материалов ИИ может занять больше времени.</li>
                </ol>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Частые вопросы</h3>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><b>Нужна ли регистрация?</b> Часть сервисов — нет; для хранения истории и тяжёлых задач — да.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><b>Откуда берутся данные?</b> Из открытых источников и ваших запросов; официальные реквизиты — из ЕГРЮЛ/ЕГРИП.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><b>Где мои результаты?</b> На странице сервиса (список «моих» записей) и в «Моих задачах».</span></li>
                </ul>
            </div>

            <?php if ($is_admin): ?>
            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-code mr-1.5 text-gray-400"></i>Для разработчика</h3>
                <p class="mt-2 text-xs text-gray-600 sm:text-sm dark:text-gray-300">Автособираемая документация модулей — README, AGENTS, MCP, API: <a href="/guide/dev" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">/guide/dev</a>.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
