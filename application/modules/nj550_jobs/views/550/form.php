<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Работа 2 Оффер» (nj550_jobs) — форма поиска.
 * Предзаполняется из кук предпочтений; ниже — недавние поиски посетителя.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="gap-4 space-y-4 md:grid md:grid-cols-4 md:items-start md:space-y-0">
<div class="space-y-4 md:col-span-3">
    <div class="n2j-card">
        <div class="flex items-center gap-3">
            <h1 class="n2j-h1">Поиск вакансий</h1>
            <span class="n2j-badge n2j-badge-ok uppercase">ИИ</span>
        </div>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Город и направление — ИИ соберёт вакансии с сайтов работы и карьерных страниц компаний, отсеет мусор и подытожит.</p>

        <?php if ( ! empty($error)): ?>
            <div class="n2j-flash n2j-flash-err mt-4 block">
                <?php echo HTML::chars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/jobs" class="mt-5 space-y-5">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="nj550-city" class="n2j-label">Город</label>
                    <input id="nj550-city" name="city" type="text" maxlength="80" required list="nj550-cities"
                           value="<?php echo HTML::chars($city_val); ?>"
                           placeholder="Например, Казань"
                           class="n2j-input mt-2 w-full">
                    <datalist id="nj550-cities">
                        <?php foreach ($cities as $city): ?>
                            <option value="<?php echo HTML::chars($city); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label for="nj550-direction" class="n2j-label">Направление</label>
                    <input id="nj550-direction" name="direction" type="text" maxlength="200" required
                           value="<?php echo HTML::chars($direction_val); ?>"
                           placeholder="Должность или сфера: «повар», «1С программист», «продавец-кассир»"
                           class="n2j-input mt-2 w-full">
                </div>
            </div>

            <button type="submit"
                    class="n2j-btn-process <i class="fas fa-magnifying-glass mr-1"></i>Найти вакансии
            </button>
        </form>
    </div>

</div>

<aside class="space-y-4 md:col-span-1">
    <?php if ( ! empty($recent)): ?>
    <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-6 shadow-xs dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Вы искали</h2>
        <div class="mt-3 flex flex-wrap gap-2">
            <?php foreach ($recent as $r): ?>
                <a href="/jobs/<?php echo HTML::chars($r['code']); ?>"
                   class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs text-gray-600 transition hover:border-emerald-400 hover:text-emerald-600 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300">
                    <i class="fas fa-<?php echo $r['status'] === 'done' ? 'check text-emerald-500' : ($r['status'] === 'error' ? 'xmark text-rose-400' : 'circle-notch text-emerald-400 animate-spin'); ?>"></i>
                    <?php echo HTML::chars($r['direction']); ?> — <?php echo HTML::chars($r['city']); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">Город и направление запоминаются в вашем браузере — в следующий раз форма заполнится сама.</p>
    </div>
    <?php endif; ?>


    <!-- Как это работает -->
    <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-6 shadow-xs dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Как это работает</h2>
        <ol class="mt-3 list-inside list-decimal space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <li>Задаёте город и направление — запрос уходит в очередь фоновых воркеров.</li>
            <li>Воркер аккуратно (с паузами, чтобы не создавать нагрузку) ищет по открытым источникам: джоб-борды и сайты компаний.</li>
            <li>ИИ отбрасывает статьи и форумы, оставляет только вакансии и карьерные страницы, сортирует по релевантности и пишет краткое резюме рынка.</li>
            <li>Ссылкой на результаты можно поделиться, предпочтения запоминаются в вашем браузере.</li>
        </ol>
        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">Обычно готово за 20–60 секунд. Лимит — <?php echo (int) Kohana::$config->load('nj550_jobs.rate.per_hour'); ?> поисков в час.</p>
    </div>
</aside>
</div>
</div>
