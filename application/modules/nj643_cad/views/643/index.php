<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: /cad — описание детали и мои детали.
 *
 * Порядок экрана: (1) что это и форма, (2) мои детали карточками с превью,
 * (3) поиск/чипы/«Строк:». Форма занимает 2/3 ширины, рядом — блок-компаньон
 * с тем, как это работает (правило анти-пустоты дизайн-контракта).
 */
$items  = isset($items) ? (array) $items : [];
$live   = isset($live) ? (array) $live : [];
$last   = isset($last) ? (array) $last : [];
$counts = isset($counts) ? (array) $counts : [];
$f      = isset($f) ? (array) $f : ['status' => '', 'q' => '', 'page' => 1, 'per' => 12, 'total' => 0, 'pages' => 1];
$csrf   = isset($csrf) ? (string) $csrf : '';
$flash  = isset($flash) ? trim((string) $flash) : '';
$signed = ! empty($signed);
$is_admin = ! empty($is_admin);

$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800';

$nj643_qs = function (array $over) use ($f) {
    $qs = array_filter(array_merge([
        'status' => (string) $f['status'],
        'q'      => (string) $f['q'],
        'per'    => (int) $f['per'],
        'page'   => (int) $f['page'],
    ], $over), function ($v) {
        return (string) $v !== '' AND (string) $v !== '0';
    });

    return '/cad'.($qs ? '?'.http_build_query($qs) : '');
};

$chips = [
    ''        => ['Все', (int) array_sum($counts)],
    'done'    => ['Готовые', (int) ($counts['done'] ?? 0)],
    'running' => ['Собираются', (int) ($counts['running'] ?? 0) + (int) ($counts['queued'] ?? 0)],
    'failed'  => ['С ошибкой', (int) ($counts['failed'] ?? 0)],
];

// Заготовки типовых деталей: клик подставляет описание целиком (текст в title).
// Типовые формы движок собирает почти без промахов — меньше провалов и токенов.
$examples = [
    'Фланец'        => 'Фланец Ø80×8 мм: центральное отверстие Ø20, четыре отверстия Ø6,5 под винт М6 по окружности Ø60',
    'Кронштейн'     => 'Кронштейн для полки 200×60×4 мм с двумя отверстиями под винт М4 и рёбром жёсткости по центру',
    'Втулка'        => 'Втулка 20×12×30 мм: наружный Ø20, внутренний Ø12, фаски 1 мм по торцам',
    'Переходник'    => 'Переходник 1/2" на шланг 12 мм: конус, буртик и шестигранник под ключ 24',
    'Корпус датчика'=> 'Корпус датчика 60×40×25 мм со стенкой 2 мм, крышкой и четырьмя стойками под саморез',
    'Заглушка'      => 'Заглушка трубы Ø32 мм: юбка высотой 12 мм, дно 3 мм, внутри кольцевой выступ 1 мм',
];
?>
<div class="n2j-ds w-full space-y-5 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <header>
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/services" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сервисы</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-semibold text-gray-700 dark:text-gray-300">CAD-детали</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
            <h1 class="n2j-h1">
                <i class="fas fa-cube mr-2 text-blue-500"></i>Детали по описанию
            </h1>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
                <i class="fas fa-gears text-[11px]"></i>STEP · STL · GLB
            </span>
            <?php if ( ! empty($is_admin)): ?>
            <a href="/cad/admin" class="n2j-btn-secondary n2j-btn-sm">
                <i class="fas fa-lock text-[11px]"></i>Управление
            </a>
            <?php endif; ?>
        </div>
        <p class="mt-1.5 max-w-3xl text-sm leading-relaxed text-gray-600 dark:text-gray-300">
            Опишите деталь словами — ИИ напишет параметрическую модель (build123d), движок проверит геометрию
            и границы параметров и соберит STEP, STL, GLB и ракурсы. Размеры потом можно крутить ползунками
            и пересобирать, а правки просить словами.
        </p>
    </header>

    <?php if ( ! $signed): ?>
    <div class="<?php echo $card; ?>">
        <p class="text-sm text-gray-700 dark:text-gray-200">
            Сервис для зарегистрированных: <a href="/auth/login?return_url=cad" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">войдите</a>,
            чтобы описать деталь и получить файлы моделей.
        </p>
    </div>
    <?php else: ?>

    <div class="gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- 1. Описание детали (2/3) -->
        <div class="<?php echo $card; ?> md:col-span-2">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="n2j-h3">Что нужно изготовить</h2>
            </div>

            <form method="post" action="/cad" class="mt-4 space-y-4" data-nj643-create>
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="create">

                <div>
                    <label for="nj643-prompt" class="n2j-label">
                        Описание детали <span class="font-normal text-gray-400">— назначение и ключевые размеры</span>
                    </label>
                    <textarea id="nj643-prompt" name="prompt" rows="4" required maxlength="4000"
                              placeholder="Например: уголок 40×40×4 мм, длина 120 мм, четыре отверстия Ø5 под винт М4 с отступом 12 мм от краёв"
                              class="n2j-textarea mt-1.5 min-h-[110px] w-full"></textarea>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Единицы — миллиметры. Чем конкретнее размеры и назначение, тем лучше модель.</p>
                </div>

                <div class="mt-3">
                    <span class="text-sm font-semibold text-gray-600 dark:text-gray-300">Заготовки <span class="font-normal text-gray-400">— с чего начать, текст подставится в описание</span></span>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2" data-nj643-examples>
                        <?php foreach ($examples as $name => $ex): ?>
                        <button type="button" data-nj643-example="<?php echo HTML::chars($ex); ?>" title="<?php echo HTML::chars($ex); ?>"
                                class="n2j-btn-secondary n2j-btn-sm">
                            <i class="fas fa-cube text-[10px] text-blue-400"></i><?php echo HTML::chars($name); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <span class="block text-sm font-semibold text-gray-800 dark:text-gray-200">Что собрать</span>
                        <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-gray-700 dark:text-gray-200">
                            <?php foreach ([['step', 'STEP (в CAD)'], ['stl', 'STL (печать)'], ['glb', 'GLB (просмотр)'], ['views', 'ракурсы']] as $ex): ?>
                            <label class="inline-flex items-center gap-1.5">
                                <input type="checkbox" name="exports[]" value="<?php echo $ex[0]; ?>" checked class="h-4 w-4 rounded border-gray-300 text-blue-600">
                                <?php echo HTML::chars($ex[1]); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <label for="nj643-deflection" class="n2j-label">
                            Точность сетки <span class="font-normal text-gray-400">— меньше точнее и дольше</span>
                        </label>
                        <select id="nj643-deflection" name="deflection" class="n2j-input n2j-select mt-1.5 w-full cursor-pointer">
                            <option value="0.05">черновая — 0.05 мм</option>
                            <option value="0.025" selected>обычная — 0.025 мм</option>
                            <option value="0.01">тонкая — 0.01 мм</option>
                        </select>
                    </div>
                </div>

                <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                    <input type="checkbox" name="stress" value="1" checked class="h-4 w-4 rounded border-gray-300 text-blue-600">
                    Проверять крайние значения параметров (модель не должна ломаться на min/max)
                </label>

                <div>
                    <button type="submit" class="n2j-btn-process">
                        <i class="fas fa-cube text-sm"></i>Собрать деталь
                    </button>
                    <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">Обычно занимает 1–3 минуты: покажем этапы прямо на странице детали.</p>
                </div>
            </form>
        </div>

        <!-- Блок-компаньон (1/3) -->
        <div class="space-y-4">
            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3">Как это работает</h2>
                <ol class="mt-2.5 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2"><span class="font-bold text-blue-500">1</span><span>ИИ пишет параметрический код модели: ключевые размеры становятся параметрами.</span></li>
                    <li class="flex gap-2"><span class="font-bold text-blue-500">2</span><span>Движок <span class="font-mono text-[13px]">build123d</span> собирает геометрию и проверяет её: тело должно быть цельным.</span></li>
                    <li class="flex gap-2"><span class="font-bold text-blue-500">3</span><span>Крайние значения параметров прогоняются стресс-тестом — видно, где модель ещё ломается.</span></li>
                    <li class="flex gap-2"><span class="font-bold text-blue-500">4</span><span>Получаете STEP, STL, GLB и ракурсы; размеры крутятся ползунками с пересборкой.</span></li>
                </ol>
                <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                    Файлы хранятся на сайте: ссылкой на деталь можно поделиться, заберёт файлы тот, кто её открыл.
                </p>
            </div>
        </div>
    </div>

    <!-- 2. Мои детали -->
    <section class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="n2j-h3">Мои детали</h2>
            <div class="flex flex-wrap items-center gap-2">
                <?php foreach ($chips as $key => $chip): ?>
                <a href="<?php echo HTML::chars($nj643_qs(['status' => $key, 'page' => 1])); ?>"
                   class="n2j-chip<?php echo ((string) $f['status'] === (string) $key) ? ' is-on' : ''; ?>">
                    <?php echo HTML::chars($chip[0]); ?> <span class="text-gray-400"><?php echo (int) $chip[1]; ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="get" action="/cad" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="status" value="<?php echo HTML::chars((string) $f['status']); ?>">
            <input type="search" name="q" value="<?php echo HTML::chars((string) $f['q']); ?>" placeholder="Поиск по названию и описанию"
                   class="n2j-input min-w-[200px] flex-1">
            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-600 dark:text-gray-300">
                Строк:
                <select name="per" data-nj643-per class="n2j-input n2j-select w-auto">
                    <?php foreach ([12, 24, 48] as $n): ?>
                    <option value="<?php echo $n; ?>" <?php echo ((int) $f['per'] === $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="n2j-btn-secondary">
                <i class="fas fa-magnifying-glass text-[11px]"></i>Найти
            </button>
        </form>

        <?php if ( ! $items): ?>
        <div class="<?php echo $card; ?>">
            <p class="text-sm text-gray-700 dark:text-gray-200">
                <?php if ((int) $f['total'] === 0 AND (string) $f['q'] === '' AND (string) $f['status'] === ''): ?>
                Пока пусто: опишите первую деталь в форме выше — она появится здесь со статусом и файлами.
                <?php else: ?>
                Под эти условия ничего не нашлось. <a href="/cad" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Сбросить фильтры</a>
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <?php foreach ($items as $item): ?>
            <?php echo View::factory('643/part_card', [
                'p'    => $item,
                'live' => $live[(string) $item['code']] ?? NULL,
                'last' => $last[(string) $item['code']] ?? NULL,
                'csrf' => $csrf,
            ]); ?>
            <?php endforeach; ?>
        </div>

        <?php $pages = max(1, (int) $f['pages']); $page = max(1, (int) $f['page']); ?>
        <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
            <span>Всего: <?php echo (int) $f['total']; ?></span>
            <?php if ($pages > 1): ?>
            <span class="ml-auto flex flex-wrap items-center gap-1.5">
                <?php if ($page > 1): ?>
                <a href="<?php echo HTML::chars($nj643_qs(['page' => $page - 1])); ?>" class="n2j-btn-secondary n2j-btn-sm">Назад</a>
                <?php endif; ?>
                <span class="px-1">стр. <?php echo $page; ?> из <?php echo $pages; ?></span>
                <?php if ($page < $pages): ?>
                <a href="<?php echo HTML::chars($nj643_qs(['page' => $page + 1])); ?>" class="n2j-btn-secondary n2j-btn-sm">Вперёд</a>
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php endif; ?>
</div>

<?php echo View::factory('643/script', ['csrf' => $csrf, 'scope' => 'index']); ?>
