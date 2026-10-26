<?php defined('SYSPATH') or die('No direct script access.');
/**
 * /trends/<code> — страница обзора (ПУБЛИЧНЫЙ дайджест).
 *
 * Подача — «как статья», чтобы обзор было удобно ЧИТАТЬ (владелец, 26.09:
 * «должен быть ближе к статье»): крупный заголовок, лид, разделы-позиции с
 * якорями, оглавление в правой колонке, читаемые абзацы (leading-relaxed)
 * вместо мелких подписей в узкой сетке.
 *
 * Дизайн — строго по эталону /pages/required_design_sample:
 *   • шапка: хлебные крошки → h1 + чипы в ОДНОМ flex-ряду → описание ниже;
 *   • рабочая область 2/3 + 1/3 (md:grid md:grid-cols-3), блок-компаньон в
 *     правой колонке `hidden md:block` — правило анти-пустоты;
 *   • карточки rounded-xl, отступы p-4 → sm:p-6/p-7, кнопки/поля rounded-lg
 *     min-h-[44px]; статья — по образцу секции «Статья» эталона;
 *   • статус-ряд «спиннер + название + секундомер» НЕДЕЛИМЫЙ (без
 *     justify-between), действие — отдельной строкой.
 *
 * Служебное:
 *   • «Модель» показывает только администратор (служебная информация);
 *   • удаление обзора — маленькая кнопка-иконка (корзина) рядом с «Новый
 *     обзор», в два клика (защита от случайного нажатия);
 *   • пустой обзор («ничего не нашлось») получает полнозенную кнопку
 *     «Удалить обзор» — чтобы легко убрать мусор из списка.
 *   • «Собрать заново» открывает ФОРМУ нового обзора (/trends/new) с прежними
 *     параметрами: период, число позиций, звёзды, язык и фокус приходят
 *     в адресе, контроллер их проверяет и подставляет (владелец 15.10.2026:
 *     «/trends — перечень обзоров, форму — на другую страницу»).
 */
$st_ru = ['new' => 'новый', 'running' => 'в работе', 'done' => 'готово',
          'error' => 'ошибка', 'cancelled' => 'остановлено'];
$badge = [
    'new'       => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    'running'   => 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300',
    'done'      => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300',
    'error'     => 'bg-rose-100 text-rose-700 dark:bg-rose-900/50 dark:text-rose-300',
    'cancelled' => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
];
$lang_ru = ['python' => 'Python', 'typescript' => 'TypeScript', 'javascript' => 'JavaScript', 'rust' => 'Rust', 'go' => 'Go', 'c++' => 'C++', 'java' => 'Java', 'zig' => 'Zig', 'swift' => 'Swift', 'kotlin' => 'Kotlin'];
$period_ru = [1 => 'сутки', 2 => '2 дня', 3 => '3 дня', 4 => '4 дня', 5 => '5 дней', 6 => '6 дней', 7 => 'неделя'];
$is_live = in_array($r['status'], ['new', 'running'], TRUE);
// Готовый опубликованный обзор читают все. Снятый с публикации — только
// администратор, как и незавершённую сборку.
$published = isset($published) ? (bool) $published : Service_Nj593_Trends::is_published($r);
$is_done = (string) $r['status'] === 'done';
$is_admin = ! empty($is_admin);      // модель показываем только администратору
// полный URL — через URL::site (учитывает базовый адрес сайта и прокси)
$share_url = URL::site('trends/'.$r['code'], TRUE);
$has_items = ! empty($items);

// «Собрать заново»: та же настройка, но новой датой. Пустые значения не тащим.
$again_params = array_filter([
    'period'    => (int) $r['period_days'] > 0 ? (int) $r['period_days'] : '',
    'count'     => (int) $r['item_count'] > 0 ? (int) $r['item_count'] : '',
    'min_stars' => (int) $r['min_stars'] > 0 ? (int) $r['min_stars'] : '',
    'language'  => trim((string) $r['language']),
    'focus'     => trim((string) ($r['focus'] ?? '')),
], function ($v) { return $v !== '' AND $v !== NULL; });
$again_url = '/trends/new'.($again_params ? '?'.http_build_query($again_params) : '');

// --- Оглавление статьи и оценка времени чтения -----------------------------
// (ставим якоря на позиции: длинный обзор удобнее листать, чем скроллить)
$toc = [];
$read_chars = 0;

foreach ($items as $i => $it)
{
    $text = trim((string) $it['title'].' '.(string) $it['what'].' '.(string) $it['why'].' '
        .(string) $it['use_for'].' '.(string) ($it['apply'] ?? ''));
    $read_chars += function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    $toc[] = [
        'n'     => $i + 1,
        'title' => (string) ($it['title'] !== '' ? $it['title'] : $it['full_name']),
    ];
}

$read_min = $read_chars > 0 ? max(1, (int) round($read_chars / 1300)) : 0;

// Русское склонение слова «позиция» для мета-строки статьи (0 позиций, 1 позиция…)
$n_items = count($items);
$n10 = $n_items % 10;
$n100 = $n_items % 100;

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
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-n593-code="<?php echo HTML::chars($r['code']); ?>" data-n593-status="<?php echo HTML::chars($r['status']); ?>">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-5 flex items-center gap-3 text-xs" data-flash>
        <i class="fas fa-circle-info shrink-0 text-base text-blue-600 dark:text-blue-400"></i>
        <span class="flex-1"><?php echo HTML::chars($flash); ?></span>
        <button type="button" class="n2j-btn-icon shrink-0 h-7 w-7" data-flash-close aria-label="Закрыть"><i class="fas fa-xmark"></i></button>
    </div>
    <?php endif; ?>

    <!-- ============ Шапка: крошки, h1 + чипы одной строкой ============ -->
    <header>
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сервисы</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <a href="/trends" class="transition hover:text-blue-600 dark:hover:text-blue-400">Тренды GitHub</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-mono font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($r['code']); ?></span>
        </nav>

        <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
            <h1 class="n2j-h1"><?php echo HTML::chars($r['title'] !== '' ? $r['title'] : 'Обзор трендов GitHub за '.($period_ru[(int) $r['period_days']] ?? 'сутки')); ?></h1>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
                <i class="fas fa-calendar text-[11px]"></i><?php echo HTML::chars(date('d.m.Y', strtotime((string) $r['rdate']))); ?>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-100/90 px-3 py-1 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                за <?php echo HTML::chars($period_ru[(int) $r['period_days']] ?? ((int) $r['period_days'].' дн.')); ?>
            </span>
            <span class="rounded-full px-2.5 py-1 text-xs font-bold <?php echo $badge[$r['status']] ?? $badge['new']; ?>" data-n593-badge><?php echo $st_ru[$r['status']] ?? (string) $r['status']; ?></span>

            <?php // Публикация — статусной точкой и словом (эталон, правило 4): видно,
                  // читает ли обзор кто-то кроме владельца. ?>
            <?php if ($is_done): ?>
            <span class="n2j-badge <?php echo $published ? 'n2j-badge-ok' : 'n2j-badge-run'; ?>" data-n593-published="<?php echo $published ? '1' : '0'; ?>">
                <span class="h-1.5 w-1.5 rounded-full <?php echo $published ? 'bg-emerald-500' : 'bg-amber-500'; ?>"></span><?php echo $published ? 'опубликован' : 'снят с публикации'; ?>
            </span>
            <?php endif; ?>

            <?php // «Новый обзор» и «Управление» живут в чипах ВЕРХНЕГО header шаблона
                  // (module_nav), здесь только то, что относится к самому обзору.
                  // Удаление — маленькая иконка-корзина (в два клика): не бросается
                  // в глаза и не нажимается случайно. ?>
            <?php if ($is_admin AND $is_done): ?>
            <form method="post" action="/trends/<?php echo HTML::chars($r['code']); ?>/publish" class="inline-flex">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="published" value="<?php echo $published ? '0' : '1'; ?>">
                <button data-n2j-arm="<?php echo $published ? 'Точно снять?' : 'Точно вернуть?'; ?>" type="submit" title="<?php echo $published
                      ? 'Обзор исчезнет из открытого каталога, а страница обзора станет доступна только вам. Вернуть можно той же кнопкой.'
                      : 'Обзор снова появится в открытом каталоге и станет доступен всем по ссылке.'; ?>"
                        class="n2j-btn-secondary n2j-btn-sm">
                    <i class="fas <?php echo $published ? 'fa-eye-slash' : 'fa-eye'; ?> text-[11px]" aria-hidden="true"></i><?php echo $published ? 'Снять с публикации' : 'Вернуть в каталог'; ?>
                </button>
            </form>
            <?php endif; ?>

            <?php if ($is_admin AND $r['status'] !== 'running'): ?>
            <form method="post" action="/trends/<?php echo HTML::chars($r['code']); ?>/delete" class="inline-flex">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <button data-n2j-confirm="Удалить обзор <?php echo HTML::chars($r['code']); ?> полностью? Позиции будут потеряны. Отменить нельзя." data-n2j-title="Удалить обзор?" type="submit" title="Удалить обзор" aria-label="Удалить обзор"
                        class="n2j-btn-icon-danger">
                    <i class="fas fa-trash-can text-xs"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>

        <?php // Для незавершённых/остановленных обзоров summary — это объяснение процесса.
              // В готовом обзоре тот же текст идёт лидом ВНУТРИ статьи (см. ниже). ?>
        <?php if ((string) $r['summary'] !== '' AND $r['status'] !== 'done'): ?>
        <p class="mt-1.5 max-w-4xl text-xs text-gray-600 sm:text-sm dark:text-gray-300"><?php echo HTML::chars($r['summary']); ?></p>
        <?php endif; ?>
    </header>

    <!-- ============ Рабочая область: 2/3 + 1/3 ============ -->
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ---------- Левая колонка (2/3): процесс, статья, позиции ---------- -->
        <div class="space-y-5 md:col-span-2">

            <!-- Сборка: неразрывный статус-ряд (спиннер + название + таймер) -->
            <?php if ($is_live): ?>
            <div class="n2j-card" data-n593-running>
                <div class="flex flex-wrap items-center gap-2.5">
                    <i class="fas fa-spinner n2j-spin text-base text-blue-600 dark:text-blue-400" data-n593-spin></i>
                    <h2 class="n2j-h3">Собираем обзор</h2>
                    <span data-n593-elapsed class="n2j-badge n2j-badge-info font-mono tabular-nums" style="min-width:4.2em;text-align:center"><?php $el = max(0, (int) ($elapsed ?? 0)); echo floor($el / 60).':'.str_pad((string) ($el % 60), 2, '0', STR_PAD_LEFT); ?></span>
                </div>
                <div class="mt-3.5 space-y-2">
                    <p class="truncate text-xs font-medium text-gray-600 dark:text-gray-300" data-n593-progress><?php echo HTML::chars($r['progress'] !== '' ? $r['progress'] : 'Ставим задачу в очередь…'); ?></p>
                    <p class="n2j-hint">Позиций готово: <span data-n593-ready><?php echo count($items); ?></span> из <?php echo (int) $r['item_count']; ?>. Страница обновится сама.</p>
                </div>
                <?php if ($is_admin): ?>
                <div class="mt-3.5 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3.5 dark:border-gray-700/60">
                    <button type="button"
                            class="n2j-btn-danger"
                            data-n593-cancel data-n2j-arm="Точно прервать?"
                            data-code="<?php echo HTML::chars($r['code']); ?>"
                            data-csrf="<?php echo HTML::chars($csrf); ?>"
                            title="Остановить сборку: задача снимется, собранные позиции останутся">
                        <i class="fas fa-stop text-[11px]"></i><span data-n593-cancel-label data-n2j-arm-label>Прервать сборку</span>
                    </button>
                    <span class="text-xs text-gray-400">Собранные позиции останутся на странице.</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Остановлено вручную (или воркер не отвечал) -->
            <?php if ($r['status'] === 'cancelled'): ?>
            <div class="rounded-xl border border-gray-300/80 bg-gray-50/90 p-4 shadow-xs dark:border-gray-600/80 dark:bg-gray-800/60 sm:p-5">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-circle-stop shrink-0 text-base text-gray-500 dark:text-gray-400"></i>
                    <h2 class="n2j-h3">Сборка остановлена</h2>
                </div>
                <p class="mt-2 break-words text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $r['error']); ?></p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <a href="<?php echo HTML::chars($again_url); ?>" class="n2j-btn-process">Собрать заново</a>
                    <a href="/trends/mine" class="n2j-btn-secondary">Мои обзоры</a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Ошибка: полный текст причины -->
            <?php if ($r['status'] === 'error'): ?>
            <div class="rounded-xl border border-rose-300/80 bg-rose-50/90 p-4 shadow-xs dark:border-rose-800/80 dark:bg-rose-950/40 sm:p-5">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-circle-exclamation shrink-0 text-base text-rose-600 dark:text-rose-400"></i>
                    <h2 class="text-sm font-bold text-rose-900 dark:text-rose-200 sm:text-base">Сборка не удалась</h2>
                </div>
                <p class="mt-2 break-words text-xs text-rose-800 dark:text-rose-300"><?php echo HTML::chars((string) $r['error']); ?></p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <a href="<?php echo HTML::chars($again_url); ?>" class="n2j-btn-process">Собрать заново</a>
                    <button type="button" class="n2j-btn-secondary" onclick="location.reload()">Обновить статус</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============ Готовый обзор — статьёй ============ -->
            <?php if ($r['status'] === 'done'): ?>
            <article class="n2j-card sm:p-7">

                <!-- Мета-строка статьи: сколько позиций и сколько читать -->
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-layer-group text-[11px]"></i><?php echo (int) $n_items; ?> <?php echo $items_word; ?></span>
                    <?php if ($read_min > 0): ?>
                    <span class="text-gray-300 dark:text-gray-600">•</span>
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-clock text-[11px]"></i>~<?php echo (int) $read_min; ?> мин чтения</span>
                    <?php endif; ?>
                    <span class="text-gray-300 dark:text-gray-600">•</span>
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-calendar-check text-[11px]"></i>обзор за <?php echo HTML::chars($period_ru[(int) $r['period_days']] ?? ((int) $r['period_days'].' дн.')); ?></span>
                </div>

                <!-- Лид: вступление обзора (или объяснение, почему позиций нет) -->
                <?php if ((string) $r['summary'] !== ''): ?>
                <p class="mt-3 text-base font-medium leading-relaxed text-gray-900 sm:text-lg dark:text-gray-100"><?php echo HTML::chars((string) $r['summary']); ?></p>
                <?php endif; ?>

                <?php if ( ! $has_items): ?>
                <!-- Пустой обзор: сразу даём убрать его одним движением.
                     Владелец: «ниже этой надписи как раз полнозенная кнопка
                     Удалить — чтобы легко избавиться от мусора». -->
                <div class="mt-4 space-y-2">
                    <?php if ($is_admin): ?>
                    <form method="post" action="/trends/<?php echo HTML::chars($r['code']); ?>/delete">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <button data-n2j-confirm="Удалить обзор <?php echo HTML::chars($r['code']); ?> полностью? Отменить нельзя." data-n2j-title="Удалить обзор?" type="submit" class="n2j-btn-danger w-full">
                            <i class="fas fa-trash-can text-xs"></i>Удалить обзор
                        </button>
                    </form>
                    <a href="<?php echo HTML::chars($again_url); ?>" class="n2j-btn-process w-full">
                        <i class="fas fa-rotate text-xs"></i>Собрать заново
                    </a>
                    <?php endif; ?>
                    <p class="n2j-hint pt-0.5">
                        <i class="fas fa-magnifying-glass mr-1 text-[11px]"></i>Подходящих репозиториев не нашлось. Помогает шире период, меньше минимум звёзд или другой фокус.
                    </p>
                </div>
                <?php endif; ?>

                <?php if ( ! empty($r['takeaways'])): ?>
                <!-- Что взять в работу: практические выводы обзора -->
                <div class="mt-4 rounded-xl border border-emerald-200/80 bg-emerald-50/80 p-4 dark:border-emerald-800/60 dark:bg-emerald-950/30 sm:p-5">
                    <p class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-300"><i class="fas fa-lightbulb text-[13px]"></i>Что взять в работу</p>
                    <div class="mt-2.5 space-y-1.5 text-sm leading-relaxed text-emerald-900 dark:text-emerald-100"><?php echo nl2br(HTML::chars((string) $r['takeaways'])); ?></div>
                </div>
                <?php endif; ?>

                <!-- Позиции обзора: разделы статьи с якорями для оглавления -->
                <?php foreach ($items as $i => $it):
                    $n = $i + 1;
                    $topics = array_filter(array_map('trim', explode(',', (string) $it['topics'])));
                    $repo_url = $it['url'] !== '' ? $it['url'] : 'https://github.com/'.$it['full_name']; ?>
                <section id="pos-<?php echo (int) $n; ?>" class="mt-5 border-t border-gray-100 pt-5 scroll-mt-24 dark:border-gray-700/60 sm:mt-6 sm:pt-6">

                    <!-- Строка факта: номер · репозиторий · метрики GitHub -->
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-blue-600 text-xs font-bold text-white"><?php echo (int) $n; ?></span>
                        <a href="<?php echo HTML::chars($repo_url); ?>" target="_blank" rel="noopener noreferrer" class="min-w-0 truncate font-mono text-xs font-bold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($it['full_name']); ?></a>
                        <span class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-gray-100/90 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300" title="Звёзды"><i class="fas fa-star text-[10px] text-amber-500"></i><?php echo number_format((int) $it['stars'], 0, ',', ' '); ?></span>
                        <span class="hidden items-center gap-1 rounded-full border border-gray-200 bg-gray-100/90 px-2 py-0.5 text-xs font-semibold text-gray-700 sm:inline-flex dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300" title="Форки"><i class="fas fa-code-fork text-[10px]"></i><?php echo number_format((int) $it['forks'], 0, ',', ' '); ?></span>
                        <?php if ((string) $it['language'] !== ''): ?>
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-100/90 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"><span class="h-2 w-2 rounded-full bg-blue-500"></span><?php echo HTML::chars($lang_ru[$it['language']] ?? $it['language']); ?></span>
                        <?php endif; ?>
                        <?php if ($it['gh_created_at'] !== NULL): ?>
                        <span class="text-xs text-gray-400" title="Репозиторий создан">создан <?php echo HTML::chars(date('d.m.Y', strtotime((string) $it['gh_created_at']))); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Заголовок раздела: человеческое название от ИИ -->
                    <?php if ((string) $it['title'] !== ''): ?>
                    <h2 class="n2j-h3 mt-2.5"><?php echo HTML::chars($it['title']); ?></h2>
                    <?php endif; ?>

                    <!-- Что это: основной абзац статьи -->
                    <?php if ((string) $it['what'] !== ''): ?>
                    <p class="mt-2 text-sm leading-relaxed text-gray-700 sm:text-base dark:text-gray-300"><?php echo HTML::chars($it['what']); ?></p>
                    <?php elseif ((string) $it['description'] !== ''): ?>
                    <p class="mt-2 text-sm leading-relaxed text-gray-700 sm:text-base dark:text-gray-300"><?php echo HTML::chars($it['description']); ?></p>
                    <?php endif; ?>

                    <!-- Почему в обзоре / кому пригодится: два пояснения рядом -->
                    <?php if ((string) $it['why'] !== '' OR (string) $it['use_for'] !== ''): ?>
                    <div class="mt-3.5 grid gap-3 sm:grid-cols-2">
                        <?php if ((string) $it['why'] !== ''): ?>
                        <div class="rounded-lg border border-gray-100 bg-gray-50/70 p-3.5 dark:border-gray-700/50 dark:bg-gray-900/30">
                            <p class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400"><i class="fas fa-circle-info text-[11px] text-blue-500"></i>Почему в обзоре</p>
                            <p class="mt-1.5 text-xs leading-relaxed text-gray-700 sm:text-sm dark:text-gray-300"><?php echo HTML::chars($it['why']); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ((string) $it['use_for'] !== ''): ?>
                        <div class="rounded-lg border border-gray-100 bg-gray-50/70 p-3.5 dark:border-gray-700/50 dark:bg-gray-900/30">
                            <p class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400"><i class="fas fa-users text-[11px] text-blue-500"></i>Кому пригодится</p>
                            <p class="mt-1.5 text-xs leading-relaxed text-gray-700 sm:text-sm dark:text-gray-300"><?php echo HTML::chars($it['use_for']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php // «Как применить» — самое интересное в позиции (владелец:
                          // «куда дел — верни!»). Блок показываем ВСЕГДА: если ИИ
                          // строку не дал (старые обзоры до появления поля), вместо
                          // пустоты — честная практическая подсказка. ?>
                    <?php $apply_txt = trim((string) ($it['apply'] ?? '')); ?>
                    <?php if ($apply_txt === ''): ?>
                    <?php $apply_txt = 'Откройте README репозитория и попробуйте на небольшой задаче — так польза для вашего проекта видна за 10–20 минут.'; ?>
                    <?php endif; ?>
                    <div class="mt-3 flex items-start gap-2.5 rounded-lg border border-gray-100 bg-gray-50/70 p-3.5 dark:border-gray-700/50 dark:bg-gray-900/30">
                        <i class="fas fa-wand-magic-sparkles mt-0.5 shrink-0 text-xs text-gray-400 dark:text-gray-500"></i>
                        <p class="text-xs leading-relaxed text-gray-700 sm:text-sm dark:text-gray-300"><span class="font-bold text-gray-900 dark:text-white">Как применить: </span><?php echo HTML::chars($apply_txt); ?></p>
                    </div>

                    <?php if ( ! empty($topics)): ?>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <?php foreach ($topics as $tp): ?>
                        <span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars($tp); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </section>
                <?php endforeach; ?>
            </article>
            <?php endif; ?>

            <!-- Позиции ещё не пришли (сборка идёт) -->
            <?php if (empty($items) AND $is_live): ?>
            <div class="n2j-card">
                <p class="text-sm text-gray-400">Позиции появятся здесь по мере разбора — не уходите далеко.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ---------- Правая колонка (1/3): блок-компаньон, на мобильных скрыт ---------- -->
        <div class="hidden space-y-5 md:block">

            <!-- Сводка обзора -->
            <div class="n2j-card">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Обзор</h3>
                <div class="mt-3 space-y-2 text-xs">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-600 dark:text-gray-300">Позиций</span>
                        <span class="font-semibold text-gray-900 dark:text-white"><?php echo count($items); ?> из <?php echo (int) $r['item_count']; ?></span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-600 dark:text-gray-300">Статус</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-bold <?php echo $badge[$r['status']] ?? $badge['new']; ?>"><?php echo $st_ru[$r['status']] ?? (string) $r['status']; ?></span>
                    </div>
                    <?php /* Честность данных: если модель молчала, обзор собран
                     * по данным GitHub — это видно сразу, а не только в тексте
                     * сводки (владелец: «где включается эвристика?»). */ ?>
                    <?php if ( ! empty($ai_offline)): ?>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-600 dark:text-gray-300">Разбор</span>
                        <span class="n2j-badge n2j-badge-run" title="Модель не ответила — позиции отобраны из данных GitHub по звёздам и свежести">без модели</span>
                    </div>
                    <?php endif; ?>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-600 dark:text-gray-300">Создано за</span>
                        <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($period_ru[(int) $r['period_days']] ?? ((int) $r['period_days'].' дн.')); ?></span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-600 dark:text-gray-300">Дата</span>
                        <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars(date('d.m.Y', strtotime((string) $r['rdate']))); ?></span>
                    </div>
                    <?php // Модель — служебная информация: показываем ТОЛЬКО администратору ?>
                    <?php if ($is_admin AND (string) ($r['model_used'] ?? '') !== ''): ?>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-600 dark:text-gray-300">Модель</span>
                        <span class="truncate font-mono text-xs text-gray-700 dark:text-gray-300" title="<?php echo HTML::chars((string) $r['model_used']); ?>"><?php echo HTML::chars((string) $r['model_used']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-600 dark:text-gray-300">Код</span>
                        <span class="font-mono text-xs text-gray-700 dark:text-gray-300"><?php echo HTML::chars($r['code']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Содержание обзора: по-статейному — навигация по позициям -->
            <?php if ( ! empty($toc)): ?>
            <div class="n2j-card">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-list-ul text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold">Содержание</h3>
                </div>
                <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($toc as $t): ?>
                    <li class="flex gap-2">
                        <span class="shrink-0 font-mono font-bold text-blue-600 dark:text-blue-400"><?php echo (int) $t['n']; ?>.</span>
                        <a href="#pos-<?php echo (int) $t['n']; ?>" class="line-clamp-2 hover:text-blue-600 dark:hover:text-blue-400"><?php echo HTML::chars($t['title']); ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Ссылка на обзор (публичный дайджест) -->
            <div class="n2j-card">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Поделиться</h3>
                <p class="n2j-hint mt-2">Обзор публичный — доступен всем по ссылке.</p>
                <div class="mt-3 flex gap-2">
                    <input type="text" readonly value="<?php echo HTML::chars($share_url); ?>" data-n593-share
                           class="n2j-input min-h-[44px] w-full min-w-0 py-2 font-mono text-xs">
                    <button type="button" data-n593-copy="<?php echo HTML::chars($share_url); ?>"
                            class="n2j-btn-secondary shrink-0"
                            title="Скопировать ссылку"><i class="fas fa-copy"></i></button>
                </div>
            </div>

            <!-- Как отбираем (анти-пустота: объясняем правила дайджеста) -->
            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fab fa-github text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold">Как отбираем</h3>
                </div>
                <ul class="mt-3 space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Только свежее: репозитории, созданные за выбранный период — не «вечные звёзды».</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Читаем README сами — не только описание из двух строк.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Факты (звёзды, язык, даты) — из GitHub API, тексты — ИИ, без выдумок.</span></li>
                </ul>
            </div>

            <!-- Управление: только администратору. Удаление — иконкой в шапке,
                 чтобы большая кнопка не попадалась под руку. -->
            <?php if ($is_admin): ?>
            <div class="n2j-card">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Управление обзором</h3>
                <div class="mt-3 space-y-2">
                    <a href="<?php echo HTML::chars($again_url); ?>" class="n2j-btn-secondary w-full">
                        <i class="fas fa-plus text-[11px]"></i>Новый обзор
                    </a>
                    <p class="n2j-hint">
                        Удалить обзор — корзиной <i class="fas fa-trash-can text-[10px]"></i> в шапке страницы: подтверждается вторым кликом.
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php // Инлайн-скрипт: спиннер + секундомер + поллинг статуса + прерывание
echo View::factory('593/script');
