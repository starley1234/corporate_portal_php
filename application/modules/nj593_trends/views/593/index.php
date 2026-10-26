<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /trends — открытый каталог опубликованных обзоров (главная страница модуля).
 *
 * Владелец (08.10.2026): «отдельная страница каталога опубликованных обзоров —
 * для людей и СЕО: читаемый список, свежие сверху, пагинация, поиск».
 * Владелец (16.10.2026): «вместо /trends показывай содержимое /trends/catalog,
 * /trends/catalog — убери». Поэтому это содержимое живёт на /trends, а
 * собственные обзоры переехали на /trends/mine.
 *
 * Как читается страница:
 *   · шапка объясняет, что здесь лежит, и даёт переход к форме нового обзора
 *     (/trends/new — форма живёт на своём адресе, владелец 15.10.2026);
 *   · поиск — GET-форма: ссылку на найденное можно передать и запомнить;
 *   · каждый обзор — самостоятельная статья: дата, охват, язык, звёзды, код
 *     обзора, заголовок-ссылка, лид и НАЗВАНИЯ первых репозиториев (по одному
 *     заголовку не видно, что внутри, а «где был этот репозиторий» — самый
 *     частый вопрос к каталогу);
 *   · список закрывает общая пагинация платформы (partials/pagination):
 *     «Показано X–Y из Z», выбор 10/20/50 строк, страницы по кругу.
 *
 * Дизайн — по эталону /pages/required_design_sample: карточки rounded-xl p-4 →
 * sm:p-6, поля и кнопки rounded-lg min-h-[44px], бейджи rounded-md/rounded-full,
 * полос хода нет. Служебного здесь нет ничего: страница публичная и индексируется.
 *
 * @var array  $rows        обзоры текущей страницы (id, code, rdate, title, summary, top_names…)
 * @var string $q           поисковый запрос ('' — весь каталог)
 * @var int    $page        текущая страница
 * @var int    $pages       всего страниц
 * @var int    $total       всего обзоров в каталоге (с учётом поиска)
 * @var int    $per         строк на странице
 * @var array  $per_options варианты «строк на странице»
 * @var bool   $is_admin    доступ к сборке и управлению обзорами
 * @var string $flash       сообщение о прошлом действии
 */

$is_admin = ! empty($is_admin);

$period_ru = [1 => 'сутки', 2 => '2 дня', 3 => '3 дня', 4 => '4 дня', 5 => '5 дней', 6 => '6 дней', 7 => 'неделя'];
$lang_ru   = ['python' => 'Python', 'typescript' => 'TypeScript', 'javascript' => 'JavaScript', 'rust' => 'Rust',
    'go' => 'Go', 'c++' => 'C++', 'java' => 'Java', 'zig' => 'Zig', 'swift' => 'Swift', 'kotlin' => 'Kotlin'];

// Лид обзора в списке — не вся простыня: 320 знаков хватает, чтобы понять тему.
$excerpt = function ($text, $len = 320) {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

    if ($text === '')
    {
        return '';
    }

    return mb_strlen($text) > $len ? mb_substr($text, 0, $len).'…' : $text;
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-5 flex items-center gap-3 text-xs" data-flash>
        <i class="fas fa-circle-info shrink-0 text-base text-blue-600 dark:text-blue-400"></i>
        <span class="flex-1"><?php echo HTML::chars($flash); ?></span>
        <button type="button" class="n2j-btn-icon shrink-0 h-7 w-7" data-flash-close aria-label="Закрыть"><i class="fas fa-xmark"></i></button>
    </div>
    <?php endif; ?>

    <!-- ============ Шапка каталога ============ -->
    <header>
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сервисы</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-medium text-gray-700 dark:text-gray-200">Тренды GitHub</span>
        </nav>

        <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
            <h1 class="n2j-h1">Обзоры трендов GitHub</h1>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-100/90 px-3 py-1 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <i class="fas fa-book-open text-[11px]"></i>опубликовано: <?php echo (int) $total; ?>
            </span>
        </div>

        <p class="mt-1.5 max-w-4xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">
            Что нового и интересного появилось в open source: обзоры по дням — с датой, охватом, числом позиций и названиями репозиториев. Свежие сверху, искать можно по теме, репозиторию или коду обзора.
        </p>

        <?php if ($is_admin): ?>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <a href="/trends/new" class="n2j-btn-process">
                <i class="fas fa-wand-magic-sparkles text-[13px]"></i>Собрать обзор
            </a>
            <a href="/trends/mine" class="n2j-btn-secondary">
                Мои обзоры
            </a>
        </div>
        <?php endif; ?>
    </header>

    <!-- ============ Поиск по каталогу ============ -->
    <form method="get" action="/trends" class="mt-5 flex flex-wrap items-center gap-2" role="search">
        <label for="nj593-cat-q" class="sr-only">Поиск по обзорам</label>
        <div class="relative min-w-0 flex-1 sm:max-w-md">
            <i class="fas fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-gray-400" aria-hidden="true"></i>
            <input id="nj593-cat-q" type="search" name="q" value="<?php echo HTML::chars($q); ?>" autocomplete="off"
                   placeholder="Поиск: тема, репозиторий, код обзора"
                   class="n2j-input w-full pl-9">
        </div>
        <button type="submit" class="n2j-btn-process">
            <i class="fas fa-magnifying-glass text-[11px]"></i>Найти
        </button>
        <?php if ($q !== ''): ?>
        <a href="/trends" class="n2j-btn-secondary">
            Сбросить
        </a>
        <?php endif; ?>
    </form>

    <!-- ============ Список обзоров ============ -->
    <div class="n2j-card p-0 mt-5">

        <?php if (empty($rows)): ?>
        <div class="px-4 py-8 text-center sm:px-6">
            <i class="fas fa-book-open text-2xl text-gray-300 dark:text-gray-600" aria-hidden="true"></i>
            <?php if ($q !== ''): ?>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                По запросу «<span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($q); ?></span>» опубликованных обзоров нет.
            </p>
            <a href="/trends" class="n2j-btn-secondary mt-3">Показать все обзоры</a>
            <?php else: ?>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                Опубликованных обзоров пока нет — первый появится после сборки.
            </p>
            <?php if ($is_admin): ?>
            <a href="/trends/new" class="n2j-btn-process mt-3">Собрать обзор</a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>

        <div class="divide-y divide-gray-100 dark:divide-gray-700/60">
            <?php foreach ($rows as $row):
                $items_ready = (int) $row['items_ready'];
                $top_names   = trim((string) ($row['top_names'] ?? ''));
                $lead        = $excerpt($row['summary']);
                $title       = trim((string) $row['title']);
                $period      = (int) $row['period_days'];
                $lang        = trim((string) $row['language']);

                // «1 позиция / 2 позиции / 8 позиций» — счётчик читается словами
                $n10 = $items_ready % 10;
                $n100 = $items_ready % 100;

                if ($n10 === 1 AND $n100 !== 11)
                {
                    $items_word = 'позиция';
                }
                elseif ($n10 >= 2 AND $n10 <= 4 AND ($n100 < 12 OR $n100 > 14))
                {
                    $items_word = 'позиции';
                }
                else
                {
                    $items_word = 'позиций';
                }
            ?>
            <article class="p-4 transition-colors hover:bg-gray-50/70 sm:p-6 dark:hover:bg-gray-700/20">

                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-2 text-xs">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 font-semibold text-white shadow-xs">
                        <i class="fas fa-calendar text-[11px]" aria-hidden="true"></i><time datetime="<?php echo HTML::chars(date('Y-m-d', strtotime((string) $row['rdate']))); ?>"><?php echo HTML::chars(date('d.m.Y', strtotime((string) $row['rdate']))); ?></time>
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-gray-100/90 px-3 py-1 font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        за <?php echo HTML::chars($period_ru[$period] ?? ($period.' дн.')); ?>
                    </span>
                    <?php if ($lang !== ''): ?>
                    <span class="inline-flex items-center rounded-md bg-gray-100 px-2.5 py-1 font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300"><?php echo HTML::chars($lang_ru[mb_strtolower($lang)] ?? $lang); ?></span>
                    <?php endif; ?>
                    <span class="inline-flex items-center rounded-md bg-gray-100 px-2.5 py-1 font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">от <?php echo (int) $row['min_stars']; ?> звёзд</span>
                    <a href="/trends/<?php echo HTML::chars($row['code']); ?>" class="font-mono font-bold text-blue-600 hover:underline dark:text-blue-400" title="Постоянная ссылка на обзор"><?php echo HTML::chars($row['code']); ?></a>
                </div>

                <h2 class="n2j-h3 mt-2.5">
                    <a href="/trends/<?php echo HTML::chars($row['code']); ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400">
                        <?php echo HTML::chars($title !== '' ? $title : 'Обзор трендов GitHub за '.date('d.m.Y', strtotime((string) $row['rdate']))); ?>
                    </a>
                </h2>

                <?php if ($lead !== ''): ?>
                <p class="mt-2 max-w-4xl text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($lead); ?></p>
                <?php endif; ?>

                <?php if ($top_names !== ''): ?>
                <p class="n2j-hint mt-3 flex flex-wrap items-baseline gap-1.5">
                    <span class="shrink-0 font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">В обзоре</span>
                    <span class="font-mono text-gray-600 dark:text-gray-300"><?php echo HTML::chars($top_names); ?></span>
                    <?php if ($items_ready > 3): ?>
                    <span class="text-gray-400">и ещё <?php echo (int) ($items_ready - 3); ?></span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>

                <div class="mt-3.5 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
                    <a href="/trends/<?php echo HTML::chars($row['code']); ?>" class="inline-flex min-h-[36px] items-center gap-1.5 font-semibold text-blue-600 transition hover:underline dark:text-blue-400">
                        Читать обзор<i class="fas fa-arrow-right text-[11px]" aria-hidden="true"></i>
                    </a>
                    <span class="text-gray-400 dark:text-gray-500"><?php echo (int) $items_ready; ?> <?php echo $items_word; ?> в обзоре</span>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php echo View::factory('partials/pagination', [
            'total'       => $total,
            'page'        => $page,
            'pages'       => $pages,
            'per'         => $per,
            'per_options' => $per_options,
            'url_base'    => '/trends',
            'params'      => ($q !== '' ? ['q' => $q] : []),
            'anchor'      => '',
        ]); ?>

        <?php endif; ?>
    </div>
</div>

<?php // закрытие флеша — тот же инлайн-скрипт модуля (список обзоров умеет больше, странице каталога лишнее не мешает)
echo View::factory('593/script');
