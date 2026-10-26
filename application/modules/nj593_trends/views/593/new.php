<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /trends/new — ФОРМА нового обзора (отдельная страница).
 *
 * Владелец (15.10.2026): «/trends — пускай будет перечень обзоров хорошо
 * оформленный, а страницу — форму для нового обзора сделай другую». Форма
 * переехала со списка сюда, на свой адрес: список обзоров больше не тонет в
 * полях, а на форму ведут понятные кнопки («Новый обзор» на /trends, «Собрать
 * заново» на странице обзора и в каталоге).
 *
 * Адрес можно открыть с параметрами (?period=3&count=10&min_stars=30&
 * language=rust&focus=...): контроллер их проверит и подставит — «Собрать
 * заново» открывает форму уже заполненной.
 *
 * Дизайн — по эталону /pages/required_design_sample: крошки, h1, сетка 2/3+1/3
 * (компаньон `hidden md:block`), карточки rounded-xl p-4 → sm:p-6, поля
 * rounded-lg min-h-[44px], главная кнопка одна.
 *
 * @var array  $form      значения формы (rdate, period, count, min_stars, language, focus)
 * @var array  $languages языки фильтра GitHub ('' — любой)
 * @var bool   $logged    вошёл ли человек
 * @var string $csrf      CSRF-токен формы
 * @var string $flash     сообщение о прошлом действии
 */
$periods = [1 => 'последние сутки', 2 => 'последние 2 дня', 3 => 'последние 3 дня', 7 => 'последнюю неделю'];

$form = (array) $form + ['rdate' => date('Y-m-d'), 'period' => 1, 'count' => 8, 'min_stars' => 50, 'language' => '', 'focus' => ''];
$disabled = $logged ? '' : 'disabled';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-5 flex items-center gap-3 text-xs" data-flash>
        <i class="fas fa-circle-info shrink-0 text-base text-blue-600 dark:text-blue-400"></i>
        <span class="flex-1"><?php echo HTML::chars($flash); ?></span>
        <button type="button" class="n2j-btn-icon shrink-0 h-7 w-7" data-flash-close aria-label="Закрыть"><i class="fas fa-xmark"></i></button>
    </div>
    <?php endif; ?>

    <!-- ============ Шапка: крошки, h1, описание ============ -->
    <header>
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сервисы</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <a href="/trends" class="transition hover:text-blue-600 dark:hover:text-blue-400">Тренды GitHub</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-medium text-gray-700 dark:text-gray-200">Новый обзор</span>
        </nav>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
            <div class="min-w-0">
                <h1 class="n2j-h1">Новый обзор</h1>
                <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Выберите, что смотреть на GitHub. Воркер соберёт свежие репозитории, прочитает их README, а ИИ объяснит по-русски: что это, почему в обзоре и кому пригодится.</p>
            </div>
            <a href="/trends" class="n2j-btn-secondary shrink-0">
                <i class="fas fa-list-ul text-[13px]" aria-hidden="true"></i>Мои обзоры
            </a>
        </div>
    </header>

    <!-- Рабочая область: 2/3 форма + 1/3 компаньон (на мобильных компаньон скрыт) -->
    <div class="mt-5 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- Форма (2/3) -->
        <div class="n2j-card md:col-span-2">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="n2j-h3">Параметры обзора</h2>
            </div>

            <?php if ( ! $logged): ?>
            <div class="n2j-flash n2j-flash-warn mt-4 text-xs block">
                Обзор собирается для вашего аккаунта. <a href="/auth/login?return_url=trends/new" class="font-semibold underline">Войдите</a> — и форма заработает.
            </div>
            <?php endif; ?>

            <form method="post" action="/trends/new" class="mt-4 space-y-4 sm:mt-5 sm:space-y-5">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

                <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                    <div>
                        <label for="tr-date" class="n2j-label">Дата обзора</label>
                        <input id="tr-date" name="rdate" type="date" value="<?php echo HTML::chars($form['rdate']); ?>" <?php echo $disabled; ?>
                               class="n2j-input mt-1.5 w-full disabled:bg-gray-50 disabled:text-gray-400">
                        <p class="n2j-hint">«Свежесть» считается от этой даты.</p>
                    </div>
                    <div>
                        <label for="tr-period" class="n2j-label">Репозитории созданы за</label>
                        <select id="tr-period" name="period" <?php echo $disabled; ?>
                                class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:disabled:bg-gray-800">
                            <?php foreach ($periods as $days => $label): ?>
                            <option value="<?php echo (int) $days; ?>" <?php echo (int) $form['period'] === (int) $days ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-3 sm:gap-5">
                    <div>
                        <label for="tr-count" class="n2j-label">Позиций в обзоре</label>
                        <select id="tr-count" name="count" <?php echo $disabled; ?>
                                class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:disabled:bg-gray-800">
                            <?php for ($n = 3; $n <= 12; $n++): ?>
                            <option value="<?php echo $n; ?>" <?php echo (int) $form['count'] === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="tr-stars" class="n2j-label">Минимум звёзд</label>
                        <input id="tr-stars" name="min_stars" type="number" min="10" max="100000" step="10" value="<?php echo (int) $form['min_stars']; ?>" <?php echo $disabled; ?>
                               class="n2j-input mt-1.5 w-full disabled:bg-gray-50">
                    </div>
                    <div>
                        <label for="tr-lang" class="n2j-label">Язык</label>
                        <select id="tr-lang" name="language" <?php echo $disabled; ?>
                                class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:disabled:bg-gray-800">
                            <?php foreach ($languages as $lang): ?>
                            <option value="<?php echo HTML::chars($lang); ?>" <?php echo (string) $form['language'] === (string) $lang ? 'selected' : ''; ?>><?php echo $lang === '' ? 'любой' : HTML::chars($lang); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="tr-focus" class="n2j-label">
                        Что считать интересным <span class="font-normal text-gray-400">— необязательно</span>
                    </label>
                    <textarea id="tr-focus" name="focus" rows="3" <?php echo $disabled; ?>
                              placeholder="Например: инструменты для LLM-агентов, что-то для embedded, без форков"
                              class="n2j-textarea mt-1.5 min-h-[44px] w-full disabled:bg-gray-50"><?php echo HTML::chars($form['focus']); ?></textarea>
                </div>

                <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:items-center">
                    <button type="submit" <?php echo $disabled; ?>
                            class="n2j-btn-process w-full sm:w-auto">
                        <i class="fas fa-wand-magic-sparkles mr-2 text-[13px]" aria-hidden="true"></i>Собрать обзор
                    </button>
                    <p class="n2j-hint text-center sm:ml-auto sm:text-right">Обычно 2–4 минуты. Ход сборки виден на странице обзора.</p>
                </div>
            </form>
        </div>

        <!-- Компаньон (1/3): на мобильных скрыт -->
        <div class="hidden space-y-5 md:block">
            <div class="n2j-card">
                <div class="flex items-center gap-2.5">
                    <i class="fab fa-github text-base text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">Как отбираем</h3>
                </div>
                <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Только свежее: репозитории, созданные за выбранный период — не «вечные звёзды».</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>Читаем README сами — не только описание из двух строк.</span></li>
                    <li class="flex gap-2"><span class="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span><span>ИИ объясняет: что это, чем интересно и кому пригодится. Факты — из GitHub API, без выдумок.</span></li>
                </ul>
            </div>
            <div class="n2j-card">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-share-nodes text-base text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">Что получится</h3>
                </div>
                <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">Готовый обзор — публичная страница со своей ссылкой и раздел «Что взять в работу». Он попадает в открытый каталог; снять с публикации можно в любой момент одной кнопкой.</p>
            </div>
            <div class="n2j-card">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-calendar-day text-base text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">Ежедневно — автоматически</h3>
                </div>
                <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">«Обзор дня» можно ставить на расписание: таймер на сервере воркера раз в сутки вызывает /trends/api/create (токен воркера).</p>
            </div>
        </div>
    </div>
</div>

<?php // флеш и прочие общие мелочи — инлайн-скрипт модуля
echo View::factory('593/script');
