<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /trends — ПЕРЕЧЕНЬ обзоров.
 *
 * Владелец (15.10.2026): «/trends — пускай будет перечень обзоров хорошо
 * оформленный, а страницу — форму для нового обзора сделай другую». Поэтому
 * формы здесь больше нет: страница отвечает на один вопрос — «что у меня уже
 * собрано и что с этим делать». Форма живёт на /trends/new, на неё ведут
 * кнопки «Новый обзор» (шапка), «Собрать первый обзор» (пустой список) и
 * «Собрать заново» (страница обзора).
 *
 * Каждый обзор — карточка, а не строка плотной таблицы: дата, статус, тема,
 * лид, репозитории внутри и действия (открыть, снять/вернуть публикацию,
 * удалить в два клика). Поиск по коду, теме и названиям репозиториев, выбора
 * «строк на странице» и пагинация — общий partials/pagination.
 *
 * Дизайн — по эталону /pages/required_design_sample: контейнер px-3.5 pt-4
 * pb-16, карточки rounded-xl, кнопки/поля rounded-lg min-h-[44px], статусы
 * словами, тёмная тема.
 *
 * @var array  $my            обзоры текущей страницы (r.*, items_ready)
 * @var array  $latest        свежая публикация каталога (или NULL)
 * @var int    $catalog_total сколько обзоров в открытом каталоге
 * @var bool   $logged        вошёл ли человек
 * @var string $csrf          CSRF-токен для форм публикации и удаления
 * @var string $flash         сообщение о прошлом действии
 * @var string $q             поисковый запрос
 * @var int    $page,$pages,$total,$per  постраничный вывод
 * @var array  $per_options   варианты «строк на странице»
 */

// Статус — точкой и словом (плотность по эталону, без «тяжёлых» бейджей)
$st_dot = [
    'new'       => ['новый',       'text-gray-600 dark:text-gray-300',       'bg-gray-400'],
    'running'   => ['в работе',    'text-amber-700 dark:text-amber-400',     'bg-amber-500 animate-pulse'],
    'done'      => ['готово',      'text-emerald-700 dark:text-emerald-400', 'bg-emerald-500'],
    'error'     => ['ошибка',      'text-rose-700 dark:text-rose-400',         'bg-rose-500'],
    'cancelled' => ['остановлено', 'text-gray-500 dark:text-gray-400',       'bg-gray-400'],
];

$period_ru = [1 => 'сутки', 2 => '2 дня', 3 => '3 дня', 4 => '4 дня', 5 => '5 дней', 6 => '6 дней', 7 => 'неделя'];
$lang_ru   = ['python' => 'Python', 'typescript' => 'TypeScript', 'javascript' => 'JavaScript', 'rust' => 'Rust',
    'go' => 'Go', 'c++' => 'C++', 'java' => 'Java', 'zig' => 'Zig', 'swift' => 'Swift', 'kotlin' => 'Kotlin'];

$new_url = isset($new_url) ? (string) $new_url : '/trends/new';

// Лид карточки — не вся простыня: 240 знаков хватает, чтобы вспомнить тему.
$excerpt = function ($text, $len = 240) {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

    if ($text === '')
    {
        return '';
    }

    return mb_strlen($text) > $len ? mb_substr($text, 0, $len).'…' : $text;
};

// «1 позиция / 2 позиции / 8 позиций»
$plural = function ($n) {
    $n = (int) $n;
    $n10 = $n % 10;
    $n100 = $n % 100;

    if ($n10 === 1 AND $n100 !== 11)
    {
        return $n.' позиция';
    }

    if ($n10 >= 2 AND $n10 <= 4 AND ($n100 < 12 OR $n100 > 14))
    {
        return $n.' позиции';
    }

    return $n.' позиций';
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

    <!-- ============ Шапка: крошки, h1 и главное действие ============ -->
    <header>
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сервисы</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <a href="/trends" class="transition hover:text-blue-600 dark:hover:text-blue-400">Тренды GitHub</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-medium text-gray-700 dark:text-gray-200">Мои обзоры</span>
        </nav>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
            <div class="min-w-0">
                <h1 class="n2j-h1">Мои обзоры</h1>
                <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Собственные обзоры трендов GitHub: статус сборки, публикация в открытом каталоге, повтор с теми же настройками и удаление. <a href="/trends" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Открытый каталог опубликованных обзоров</a> — отдельная страница.</p>
            </div>
            <a href="<?php echo $logged ? '/trends/new' : '/auth/login?return_url=trends/new'; ?>"
               class="n2j-btn-process shrink-0">
                <i class="fas fa-wand-magic-sparkles text-[13px]" aria-hidden="true"></i><?php echo $logged ? 'Новый обзор' : 'Войти и собрать обзор'; ?>
            </a>
        </div>
    </header>

    <?php
    // Дайджест последней публикации + вход в открытый каталог обзоров
    // (владелец, 08.10.2026: «главная модуля должна ссылаться на каталог или
    // показывать свежую публикацию со ссылкой на каталог»).
    $latest        = isset($latest) ? $latest : NULL;
    $catalog_total = isset($catalog_total) ? (int) $catalog_total : 0;
    ?>
    <div class="n2j-card mt-5">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <i class="fas fa-book-open text-base text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
            <h2 class="n2j-h3">Каталог опубликованных обзоров</h2>
            <span class="n2j-badge n2j-badge-wait"><?php echo $catalog_total; ?></span>
            <a href="/trends" class="ml-auto inline-flex min-h-[36px] items-center gap-1.5 text-xs font-semibold text-blue-600 transition hover:underline dark:text-blue-400">
                Все обзоры<i class="fas fa-arrow-right text-[11px]" aria-hidden="true"></i>
            </a>
        </div>

        <?php if ($latest !== NULL): ?>
        <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-700/60">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Свежая публикация</p>
            <a href="/trends/<?php echo HTML::chars($latest['code']); ?>" class="mt-0.5 block text-sm font-bold text-gray-900 transition hover:text-blue-600 hover:underline sm:text-base dark:text-white dark:hover:text-blue-400">
                <?php echo HTML::chars($latest['title'] !== '' ? $latest['title'] : 'Обзор за '.date('d.m.Y', strtotime((string) $latest['rdate']))); ?>
            </a>
            <p class="n2j-hint">
                <?php echo HTML::chars(date('d.m.Y', strtotime((string) $latest['rdate']))); ?>
                · позиций: <?php echo (int) $latest['items_ready']; ?>
                <?php if (trim((string) $latest['summary']) !== ''): ?>
                · <?php echo HTML::chars(mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $latest['summary'])), 0, 180)); ?><?php echo mb_strlen(trim((string) $latest['summary'])) > 180 ? '…' : ''; ?>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============ Перечень обзоров ============ -->
    <section class="n2j-card p-0 mt-6 scroll-mt-6" id="my">

        <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 md:flex-row md:items-center md:justify-between dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Мои обзоры</h2>
                <?php if ($logged): ?>
                <span class="n2j-badge n2j-badge-wait"><?php echo (int) $total; ?></span>
                <?php endif; ?>
            </div>

            <?php if ($logged): ?>
            <form method="get" action="/trends/mine" class="flex w-full items-center gap-2 md:w-auto" role="search">
                <label for="nj593-q" class="sr-only">Поиск по обзорам</label>
                <input id="nj593-q" type="search" name="q" value="<?php echo HTML::chars($q); ?>" autocomplete="off"
                       placeholder="Поиск: код, тема, репозиторий"
                       class="n2j-input w-full min-w-0 md:w-72">
                <button type="submit" class="n2j-btn-process shrink-0">
                    <i class="fas fa-magnifying-glass text-[11px]" aria-hidden="true"></i>Найти
                </button>
                <?php if ($q !== ''): ?>
                <a href="/trends/mine" class="n2j-btn-secondary n2j-btn-sm shrink-0">Сбросить</a>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>

        <?php if ( ! $logged): ?>
        <div class="px-4 py-8 text-center sm:px-6">
            <i class="fas fa-user-lock text-2xl text-gray-300 dark:text-gray-600" aria-hidden="true"></i>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Войдите, чтобы видеть свои обзоры. Готовые обзоры доступны всем по прямой ссылке.</p>
            <a href="/auth/login?return_url=trends" class="n2j-btn-process mt-3">Войти</a>
        </div>
        <?php elseif (empty($my)): ?>
        <div class="px-4 py-8 text-center sm:px-6">
            <?php if ($q !== ''): ?>
            <i class="fas fa-magnifying-glass text-2xl text-gray-300 dark:text-gray-600" aria-hidden="true"></i>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                По запросу «<span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($q); ?></span>» ничего не найдено.
            </p>
            <a href="/trends/mine" class="n2j-btn-secondary mt-3">Показать все обзоры</a>
            <?php else: ?>
            <i class="fas fa-fire text-2xl text-gray-300 dark:text-gray-600" aria-hidden="true"></i>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Пока ни одного обзора. Первый соберётся за 2–4 минуты — и сразу станет публичной страницей.</p>
            <a href="/trends/new" class="n2j-btn-process mt-3">
                <i class="fas fa-wand-magic-sparkles text-[13px]" aria-hidden="true"></i>Собрать первый обзор
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>

        <div class="divide-y divide-gray-100 dark:divide-gray-700/60">
            <?php foreach ($my as $row):
                $dot         = $st_dot[$row['status']] ?? $st_dot['new'];
                $items_ready = (int) $row['items_ready'];
                $is_done     = $row['status'] === 'done';
                $is_pub      = $is_done ? Service_Nj593_Trends::is_published($row) : FALSE;
                $title       = trim((string) $row['title']);
                $lead        = $excerpt($row['summary']);
                $period      = (int) $row['period_days'];
                $lang        = trim((string) $row['language']);
                $focus       = trim((string) $row['focus']);
                $reason      = trim((string) $row['error']);
            ?>
            <article class="p-4 transition-colors hover:bg-gray-50/70 sm:p-5 dark:hover:bg-gray-700/20">

                <!-- мета-строка: дата, статус, код, публикация -->
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-2 text-xs">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 font-semibold text-white shadow-xs">
                        <i class="fas fa-calendar text-[11px]" aria-hidden="true"></i><time datetime="<?php echo HTML::chars(date('Y-m-d', strtotime((string) $row['rdate']))); ?>"><?php echo HTML::chars(date('d.m.Y', strtotime((string) $row['rdate']))); ?></time>
                    </span>
                    <span class="inline-flex items-center gap-1.5 font-semibold <?php echo $dot[1]; ?>">
                        <span class="h-2 w-2 rounded-full <?php echo $dot[2]; ?>"></span><?php echo $dot[0]; ?>
                    </span>
                    <?php if ($is_done AND ! $is_pub): ?>
                    <span class="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300" title="Обзор не виден в открытом каталоге">снят с публикации</span>
                    <?php endif; ?>
                    <a href="/trends/<?php echo HTML::chars($row['code']); ?>" class="font-mono font-bold text-blue-600 hover:underline dark:text-blue-400" title="Постоянная ссылка на обзор"><?php echo HTML::chars($row['code']); ?></a>
                </div>

                <h3 class="n2j-h3 mt-2.5">
                    <a href="/trends/<?php echo HTML::chars($row['code']); ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400">
                        <?php echo HTML::chars($title !== '' ? $title : 'Обзор трендов GitHub за '.date('d.m.Y', strtotime((string) $row['rdate']))); ?>
                    </a>
                </h3>

                <?php if ($lead !== ''): ?>
                <p class="mt-1.5 max-w-4xl text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($lead); ?></p>
                <?php elseif ($focus !== ''): ?>
                <p class="mt-1.5 max-w-4xl text-sm leading-relaxed text-gray-600 dark:text-gray-300">Фокус: <?php echo HTML::chars($focus); ?></p>
                <?php endif; ?>

                <?php // Своими словами — что и как собирали: период, звёзды, язык ?>
                <p class="n2j-hint mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <span><i class="fas fa-layer-group mr-1 text-[11px]"></i><?php echo $plural($items_ready); ?><?php echo $is_done ? '' : ' из '.$plural((int) $row['item_count']); ?></span>
                    <span><i class="fas fa-calendar-check mr-1 text-[11px]"></i>за <?php echo HTML::chars($period_ru[$period] ?? ($period.' дн.')); ?></span>
                    <span><i class="fas fa-star mr-1 text-[11px]"></i>от <?php echo (int) $row['min_stars']; ?> звёзд</span>
                    <?php if ($lang !== ''): ?>
                    <span><i class="fas fa-code mr-1 text-[11px]"></i><?php echo HTML::chars($lang_ru[mb_strtolower($lang)] ?? $lang); ?></span>
                    <?php endif; ?>
                    <span class="text-gray-400 dark:text-gray-500">создан <?php echo HTML::chars(date('d.m.Y, H:i', strtotime((string) $row['created_at']))); ?></span>
                </p>

                <?php // Отказ сборки виден прямо в списке: причина, а не только слово «ошибка» ?>
                <?php if ($reason !== '' AND in_array($row['status'], ['error', 'cancelled'], TRUE)): ?>
                <p class="mt-2 rounded-lg bg-rose-50/70 px-3 py-2 text-xs text-rose-800 dark:bg-rose-950/30 dark:text-rose-300">
                    <i class="fas fa-circle-exclamation mr-1 text-[11px]"></i><?php echo HTML::chars($excerpt($reason, 200)); ?>
                </p>
                <?php endif; ?>

                <div class="mt-3.5 flex flex-wrap items-center gap-2">
                    <a href="/trends/<?php echo HTML::chars($row['code']); ?>" class="n2j-btn-secondary n2j-btn-sm">Открыть</a>

                    <?php // публикация: одна кнопка-переключатель в два клика (готовые обзоры) ?>
                    <?php if ($is_done): ?>
                    <form method="post" action="/trends/<?php echo HTML::chars($row['code']); ?>/publish" class="inline-flex">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="published" value="<?php echo $is_pub ? '0' : '1'; ?>">
                        <button data-n2j-arm="<?php echo $is_pub ? 'Точно снять?' : 'Точно вернуть?'; ?>" type="submit" title="<?php echo $is_pub ? 'Обзор исчезнет из открытого каталога и с страницы обзора для всех, кроме вас. Вернуть можно той же кнопкой.' : 'Обзор снова появится в открытом каталоге и станет доступен всем по ссылке.'; ?>"
                                class="n2j-btn-secondary n2j-btn-sm">
                            <?php echo $is_pub ? 'Снять' : 'Вернуть'; ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php // удаление — иконкой-корзиной в два клика (не нажимается случайно) ?>
                    <?php if ($row['status'] !== 'running'): ?>
                    <form method="post" action="/trends/<?php echo HTML::chars($row['code']); ?>/delete" class="inline-flex">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <button data-n2j-confirm="Удалить обзор <?php echo HTML::chars($row['code']); ?> полностью? Позиции будут потеряны. Отменить нельзя." data-n2j-title="Удалить обзор?" type="submit" title="Удалить обзор" aria-label="Удалить обзор"
                                class="n2j-btn-icon-danger">
                            <i class="fas fa-trash-can text-sm"></i>
                        </button>
                    </form>
                    <?php else: ?>
                    <button type="button" disabled class="n2j-btn-icon cursor-not-allowed" title="Обзор собирается — остановите или дождитесь">
                        <i class="fas fa-trash-can text-sm"></i>
                    </button>
                    <?php endif; ?>
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
            'url_base'    => '/trends/mine',
            'params'      => ($q !== '' ? ['q' => $q] : []),
            'anchor'      => 'my',
        ]); ?>
        <?php endif; ?>
    </section>
</div>

<?php // двухшаговое удаление, публикация и флеш — инлайн-скрипт модуля
echo View::factory('593/script');
