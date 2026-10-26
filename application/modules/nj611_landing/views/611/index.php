<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /s — индекс групп сервисов: подборки инструментов по задачам.
 * Полная карта: каждая группа — карточка с составом; ниже — служебные
 * страницы платформы и SEO-абзац.
 */
$tool_count = 0;
foreach ($groups as $g) { $tool_count += count($g['modules']); }
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="mb-8 max-w-3xl">
        <h1 class="n2j-h1">Подберите сервис под свою задачу</h1>
        <p class="mt-3 text-base leading-relaxed text-gray-600 dark:text-gray-300">
            <?php echo $tool_count; ?> инструментов N2J собраны по задачам: найти клиентов, узнать всё о компании,
            подготовить документы, наполнить блог. На каждой странице — короткое «что вы получите»,
            быстрый старт прямо в деле и ответы на частые вопросы.
        </p>
        <div class="mt-4 flex flex-wrap gap-x-5 gap-y-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400">
            <span><i class="fas fa-circle-check mr-1.5 text-emerald-500"></i>Бесплатно в бете</span>
            <span><i class="fas fa-circle-check mr-1.5 text-emerald-500"></i>Работает в браузере</span>
            <span><i class="fas fa-circle-check mr-1.5 text-emerald-500"></i>Без установки и без карты</span>
        </div>
    </div>

    <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($groups as $g): ?>
        <a href="<?php echo HTML::chars($g['href']); ?>"
           class="n2j-card group flex flex-col transition-all hover:-translate-y-1 hover:border-blue-400 hover:shadow-sm dark:hover:border-blue-600">
            <div class="flex items-center gap-3">
                <span class="rounded-xl bg-blue-50 p-2.5 text-blue-600 font-bold text-lg dark:bg-blue-950/80 dark:text-blue-400"><i class="<?php echo HTML::chars($g['icon']); ?>"></i></span>
                <div class="min-w-0">
                    <h2 class="n2j-h3"><?php echo HTML::chars($g['title']); ?></h2>
                    <p class="n2j-hint"><?php echo count($g['modules']); ?> <?php echo count($g['modules']) === 1 ? 'инструмент' : (count($g['modules']) < 5 ? 'инструмента' : 'инструментов'); ?></p>
                </div>
            </div>
            <p class="mt-3 line-clamp-3 flex-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400"><?php echo HTML::chars($g['promise']); ?></p>
            <ul class="mt-4 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                <?php foreach (array_slice($g['modules'], 0, 4) as $m): ?>
                <li class="truncate"><i class="<?php echo HTML::chars($m['icon']); ?> mr-2 w-4 text-blue-400"></i><?php echo HTML::chars($m['title']); ?></li>
                <?php endforeach; ?>
                <?php if (count($g['modules']) > 4): ?>
                <li class="text-xs font-semibold text-gray-400">и ещё <?php echo count($g['modules']) - 4; ?>…</li>
                <?php endif; ?>
            </ul>
            <span class="mt-4 inline-flex items-center gap-1 text-sm font-bold text-blue-600 dark:text-blue-400">Открыть подборку <i class="fas fa-arrow-right text-xs transition-transform group-hover:translate-x-0.5"></i></span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ( ! empty($extras)): ?>
    <h2 class="n2j-h3 mt-10">Справка и новости платформы</h2>
    <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($extras as $e): ?>
        <a href="<?php echo HTML::chars($e['href']); ?>"
           class="n2j-card group flex items-start gap-3 transition-all hover:border-gray-300 hover:shadow-sm dark:hover:border-gray-500">
            <span class="rounded-lg bg-gray-50 p-2 text-sm font-bold text-gray-500 dark:bg-gray-700/60 dark:text-gray-300"><i class="<?php echo HTML::chars($e['icon']); ?>"></i></span>
            <span class="min-w-0">
                <span class="block font-bold text-gray-800 group-hover:text-blue-600 dark:text-gray-100"><?php echo HTML::chars($e['title']); ?></span>
                <span class="mt-0.5 block text-xs leading-relaxed text-gray-400"><?php echo HTML::chars($e['short']); ?></span>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mt-10 max-w-3xl rounded-xl border border-gray-100 bg-gray-50/60 p-5 text-sm leading-relaxed text-gray-500 dark:border-gray-700/60 dark:bg-gray-800/40 dark:text-gray-400">
        <p><b class="text-gray-700 dark:text-gray-200">Как пользоваться подборками.</b> Выберите задачу — внутри короткое описание того, что получите, и быстрый старт: форма целевого сервиса уже на странице, поля подставятся автоматически. Бесплатный аккаунт нужен там, где результаты надо сохранять: лента радара, наблюдения за контрагентами, документы организации. Мелкие инструменты — калькуляторы, пароли, опросы — работают и без входа.</p>
    </div>

</div>
