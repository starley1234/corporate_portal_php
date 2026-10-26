<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Страница /veclab — «Лаборатория векторов» (nj653_veclab).
 *
 * Три вкладки: проверка модели, коллекция и поиск, сравнение пары.
 * Разметка здесь, вся работа — в views/653/script.php (ходит в /veclab/api/*).
 *
 * SSR отдаёт то, что уже известно без модели: паспорт последней проверки,
 * свою коллекцию и состояние хранилища. Полос прогресса нет (правило
 * платформы): ход работы показывает общий живой статус-ряд.
 *
 * @var bool   $logged   вошедший человек видит рабочую область, гость — плашку
 *                       входа и справку о сервисе (владелец, 08.10.2026)
 * @var array  $limits   пределы модуля
 * @var array  $scale    шкала словесных оценок близости
 * @var array  $stats    хранилище: pgvector, размерность, всего элементов
 * @var array  $items    своя коллекция (Service_Nj653_Veclab::hits)
 * @var int    $mine     сколько элементов у этого посетителя
 * @var array  $runs     последние прогоны посетителя
 * @var array  $probe    последняя удачная проверка модели (shape) или []
 * @var string $probe_at когда она закончилась
 * @var array  $samples  тексты для кнопки «наполнить примерами»
 */

$photo_mb  = (int) ($limits['photo_mb'] ?? 8);
$text_max  = (int) ($limits['text_max'] ?? 2000);
$items_max = (int) ($limits['items_max'] ?? 60);

$tabs = [
    'search'  => ['Коллекция и поиск', 'fas fa-magnifying-glass'],
    'compare' => ['Сравнение пары', 'fas fa-code-compare'],
    'probe'   => ['Проверка модели', 'fas fa-stethoscope'],
];

$bulk_max = (int) ($limits['bulk_max'] ?? 12);

/**
 * Поле для одного входа: текст + картинка (в сравнении их два).
 *
 * $bulk = TRUE добавляет вторую кнопку — выбрать сразу несколько картинок:
 * собирать коллекцию по одному файлу слишком долго, чтобы кто-то это доделал
 * до конца.
 */
$slot_field = function ($id, $title, $hint, $bulk = FALSE) use ($photo_mb, $text_max, $bulk_max) { ?>
    <div class="n2j-card">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300"><?php echo HTML::chars($title); ?></h3>

        <textarea id="<?php echo $id; ?>-text" rows="3" maxlength="<?php echo $text_max; ?>"
                  placeholder="<?php echo HTML::chars($hint); ?>"
                  class="n2j-textarea mt-2 w-full"></textarea>

        <div class="mt-2 flex flex-wrap items-center gap-2">
            <label for="<?php echo $id; ?>-file"
                   class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:border-blue-400 hover:text-blue-700 dark:border-gray-600 dark:text-gray-300">
                <input type="file" id="<?php echo $id; ?>-file" accept="image/*" class="hidden" data-nj653-file>
                <i class="fas fa-image"></i>картинка (до <?php echo $photo_mb; ?> МБ)
            </label>
            <span class="hidden items-center gap-2" id="<?php echo $id; ?>-preview">
                <img alt="Выбранная картинка" id="<?php echo $id; ?>-img"
                     class="h-10 w-10 rounded-lg border border-gray-200 object-cover dark:border-gray-700">
                <button type="button" id="<?php echo $id; ?>-clear"
                        class="n2j-btn-danger n2j-btn-sm">убрать</button>
            </span>

            <?php if ($bulk): ?>
            <label for="<?php echo $id; ?>-bulk"
                   class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:border-blue-400 hover:text-blue-700 dark:border-gray-600 dark:text-gray-300">
                <input type="file" id="<?php echo $id; ?>-bulk" accept="image/*" multiple class="hidden" data-nj653-file>
                <i class="fas fa-images"></i>сразу несколько (до <?php echo $bulk_max; ?>)
            </label>
            <?php endif; ?>
        </div>

        <?php if ($bulk): ?>
        <div class="mt-2 hidden flex-wrap items-center gap-2" id="<?php echo $id; ?>-bulk-box">
            <div class="flex flex-wrap gap-1.5" id="<?php echo $id; ?>-bulk-list"></div>
            <button type="button" id="<?php echo $id; ?>-bulk-clear"
                    class="n2j-btn-danger n2j-btn-sm">убрать все</button>
        </div>
        <?php endif; ?>
    </div>
<?php };
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="n2j-h1">Лаборатория векторов</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Мультимодальная модель эмбеддингов превращает и текст, и картинку в один и тот же
                набор чисел — вектор смысла. Если модель настоящая, подпись «рыжий кот» окажется
                рядом с фотографией кота, а не рядом со словом «декларация». Здесь это можно
                проверить руками: сложить свою коллекцию, поискать в ней обычными словами
                и посмотреть, насколько близки два входа.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <?php if ( ! empty($stats['ready'])): ?>
            <span class="rounded-full bg-blue-50 px-3 py-1 font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                <i class="fas fa-ruler-horizontal mr-1"></i><?php echo (int) $stats['dims']; ?> измерений
            </span>
            <span class="rounded-full bg-emerald-50 px-3 py-1 font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                <i class="fas fa-database mr-1"></i><?php echo (int) $stats['total']; ?> в хранилище
            </span>
            <?php else: ?>
            <span class="rounded-full bg-amber-50 px-3 py-1 font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                <i class="fas fa-triangle-exclamation mr-1"></i>таблицы не созданы
            </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($logged)): ?>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <?php echo Gate::wall('veclab', [
            'icon'   => 'fas fa-vector-square',
            'title'  => 'Лаборатория векторов — по аккаунту',
            'text'   => 'Коллекция векторов и прогоны привязаны к человеку: войдите — и те же картинки и тексты найдутся с любого устройства. Очередь эмбеддингов при этом не расходуется анонимами.',
            'points' => [
                'Своя коллекция: до '.$items_max.' картинок и текстов в одном пространстве',
                'Поиск по смыслу обычными словами — подпись находит свою картинку',
                'Сравнение пары входов: косинусная близость, а не «на глаз»',
                'Паспорт модели: формат запроса, размерность и время ответа',
            ],
            'stats'  => empty($stats['ready']) ? ['Beta, бесплатно'] : [
                'Beta, бесплатно',
                'модель '.(int) $stats['dims'].'-мерная',
            ],
            'cta'    => 'Войти и открыть лабораторию',
            'note'   => 'Коллекция и прогоны живут 30 дней, элементы удаляются по кнопке. Кадр удаляется сразу после подсчёта вектора: в хранилище остаётся только вектор и превью.',
        ]); ?>

        <section class="n2j-card">
            <h2 class="n2j-h3">Что здесь можно делать</h2>
            <ul class="mt-3 space-y-3 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                <li class="flex gap-2.5">
                    <i class="fas fa-magnifying-glass mt-1 text-blue-500" aria-hidden="true"></i>
                    <span><span class="font-semibold text-gray-900 dark:text-white">Коллекция и поиск.</span>
                        Сложите в коллекцию картинки и тексты и ищите в ней обычными словами:
                        результаты идут по близости смысла, а не по имени файла.</span>
                </li>
                <li class="flex gap-2.5">
                    <i class="fas fa-code-compare mt-1 text-blue-500" aria-hidden="true"></i>
                    <span><span class="font-semibold text-gray-900 dark:text-white">Сравнение пары.</span>
                        Два входа — текст, картинка или оба — и одно число: косинусная близость
                        из настоящих векторов модели (считает PostgreSQL, а не правила страницы).</span>
                </li>
                <li class="flex gap-2.5">
                    <i class="fas fa-stethoscope mt-1 text-blue-500" aria-hidden="true"></i>
                    <span><span class="font-semibold text-gray-900 dark:text-white">Проверка модели.</span>
                        Воркер перебирает форматы мультимодального запроса, замеряет размерность
                        и время ответа и проверяет, различает ли модель смысл текстов и картинок.</span>
                </li>
            </ul>
        </section>
    </div>

    <?php if ($scale): ?>
    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Как читать число</h2>
        <p class="n2j-hint max-w-3xl">
            Косинусная близость — это угол между двумя векторами: 1 — одно направление, 0 — ничего общего.
            Абсолютные значения у каждой модели свои, поэтому сравнивайте не с «идеальной единицей», а пары между собой.
        </p>
        <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
            <?php foreach ($scale as $step): ?>
            <li>· от <?php echo HTML::chars(number_format((float) $step['from'], 2, ',', ' ')); ?>
                — <?php echo HTML::chars((string) $step['title']); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($probe): ?>
    <section class="n2j-card mt-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="n2j-h3">Что известно о модели</h2>
            <?php if ($probe_at !== ''): ?>
            <span class="text-xs text-gray-400">проверено <?php echo HTML::chars(date('d.m.Y H:i', strtotime($probe_at))); ?></span>
            <?php endif; ?>
        </div>
        <?php $verdict = (array) ($probe['verdict'] ?? []); ?>
        <?php if ($verdict): ?>
        <p class="mt-2 text-sm font-semibold <?php echo empty($verdict['ok'])
                ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'; ?>">
            <?php echo HTML::chars((string) $verdict['title']); ?>
        </p>
        <?php endif; ?>
        <dl class="mt-3 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
            <div class="flex gap-2"><dt class="text-gray-400">модель</dt>
                <dd class="min-w-0 truncate font-mono text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $probe['model']); ?></dd></div>
            <div class="flex gap-2"><dt class="text-gray-400">размерность</dt>
                <dd class="font-mono text-gray-700 dark:text-gray-200"><?php echo (int) $probe['dims']; ?></dd></div>
            <div class="flex gap-2 sm:col-span-2"><dt class="shrink-0 text-gray-400">формат запроса</dt>
                <dd class="min-w-0 text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $probe['dialect_title']); ?></dd></div>
        </dl>
    </section>
    <?php endif; ?>

    <?php else: ?>

    <div class="mt-5 flex flex-wrap gap-2" id="nj653-tabs" role="tablist">
        <?php foreach ($tabs as $key => $tab): ?>
        <button type="button" data-tab="<?php echo $key; ?>"
                class="nj653-tab n2j-chip">
            <i class="<?php echo $tab[1]; ?> mr-1.5 text-xs"></i><?php echo $tab[0]; ?>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="mt-5 xl:flex xl:items-start xl:gap-10">
    <div class="min-w-0 flex-1">

        <!-- ===================== КОЛЛЕКЦИЯ И ПОИСК ===================== -->
        <section id="search" data-panel="search">

            <div class="grid gap-4 lg:grid-cols-2">
                <div>
                    <?php $slot_field('nj653-q', 'Запрос: чем ищем',
                        'Например: рыжий кот на подоконнике. Можно искать и картинкой.'); ?>
                    <button type="button" id="nj653-search-go"
                            class="n2j-btn-process mt-3 w-full">
                        <i class="fas fa-magnifying-glass"></i>Найти по смыслу
                    </button>
                    <p class="n2j-hint mt-2" id="nj653-search-say"></p>
                    <div id="nj653-search-live"></div>
                </div>

                <div>
                    <?php $slot_field('nj653-a', 'Новый элемент коллекции',
                        'Подпись или описание. Можно только картинку — модель поймёт её сама.',
                        TRUE); ?>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" id="nj653-add-go"
                                class="n2j-btn-cta flex-1">
                            <i class="fas fa-plus"></i>Добавить в коллекцию
                        </button>
                        <button type="button" id="nj653-seed-go"
                                class="n2j-btn-secondary">
                            <i class="fas fa-wand-magic-sparkles"></i>Примеры
                        </button>
                    </div>
                    <p class="n2j-hint mt-2" id="nj653-add-say"></p>
                    <div id="nj653-add-live"></div>
                </div>
            </div>

            <div id="nj653-hits" class="mt-4"></div>

            <section class="n2j-card mt-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="n2j-h3">Моя коллекция</h2>
                    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span id="nj653-mine-count"><?php echo (int) $mine; ?></span> из <?php echo $items_max; ?>
                        <button type="button" id="nj653-forget-all"
                                class="n2j-btn-secondary n2j-btn-sm">очистить</button>
                    </div>
                </div>
                <p class="n2j-hint">
                    Коллекция привязана к вашему браузеру: чужие элементы в поиск не попадают,
                    а ваши удаляются по кнопке и автоматически — через 30 дней.
                </p>

                <div id="nj653-items" class="mt-3 grid gap-2 sm:grid-cols-2">
                    <?php if ( ! $items): ?>
                    <p class="text-sm text-gray-500 dark:text-gray-400" data-nj653-empty>
                        Пока пусто. Добавьте картинку или текст — или нажмите «Примеры»,
                        и коллекция наполнится готовыми описаниями одним запросом к модели.
                    </p>
                    <?php endif; ?>
                    <?php foreach ($items as $item): ?>
                    <div class="flex items-start gap-3 rounded-lg border border-gray-200 p-2.5 dark:border-gray-700" data-nj653-item="<?php echo (int) $item['id']; ?>">
                        <?php if ($item['preview'] !== ''): ?>
                        <img src="<?php echo HTML::chars($item['preview']); ?>" alt="Элемент коллекции"
                             class="h-12 w-12 shrink-0 rounded-md border border-gray-200 object-cover dark:border-gray-700">
                        <?php else: ?>
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-400 dark:bg-gray-900">
                            <i class="fas <?php echo HTML::chars(Service_Nj653_Veclab::kind_icon($item['kind'])); ?>"></i>
                        </span>
                        <?php endif; ?>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($item['label']); ?></p>
                            <p class="n2j-hint"><?php echo HTML::chars(Service_Nj653_Veclab::kind_label($item['kind'])); ?></p>
                        </div>
                        <button type="button" data-nj653-forget="<?php echo (int) $item['id']; ?>"
                                class="n2j-btn-danger n2j-btn-sm shrink-0">убрать</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </section>

        <!-- ========================= СРАВНЕНИЕ ========================= -->
        <section data-panel="compare" class="hidden">
            <div class="grid gap-4 lg:grid-cols-2">
                <?php $slot_field('nj653-c1', 'Вход 1', 'Текст и/или картинка.'); ?>
                <?php $slot_field('nj653-c2', 'Вход 2', 'Текст и/или картинка.'); ?>
            </div>

            <button type="button" id="nj653-compare-go"
                    class="n2j-btn-process mt-3 w-full sm:w-auto">
                <i class="fas fa-code-compare"></i>Сравнить
            </button>
            <p class="n2j-hint mt-2" id="nj653-compare-say"></p>
            <div id="nj653-compare-live"></div>
            <div id="nj653-compare-out" class="mt-3"></div>

            <div class="n2j-card mt-4">
                <h2 class="n2j-h3">Как читать число</h2>
                <p class="n2j-hint">
                    Косинусная близость — это угол между двумя векторами: 1 — одно направление,
                    0 — ничего общего. Абсолютные значения у каждой модели свои, поэтому
                    сравнивайте не с «идеальной единицей», а пары между собой.
                </p>
                <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($scale as $step): ?>
                    <li>· от <?php echo HTML::chars(number_format((float) $step['from'], 2, ',', ' ')); ?>
                        — <?php echo HTML::chars((string) $step['title']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <!-- ====================== ПРОВЕРКА МОДЕЛИ ====================== -->
        <section data-panel="probe" class="hidden">
            <div class="n2j-card">
                <h2 class="n2j-h3">Проверка модели</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Воркер переберёт известные форматы мультимодального запроса, запомнит рабочий,
                    измерит размерность и время ответа, а потом задаст модели три вопроса:
                    повторяется ли вектор, различает ли она смысл текстов и находит ли подпись
                    свою картинку. Картинки для последней пробы рисуются прямо в воркере —
                    красный круг и синий квадрат, ошибиться негде.
                </p>
                <button type="button" id="nj653-probe-go"
                        class="n2j-btn-process mt-3">
                    <i class="fas fa-stethoscope"></i>Проверить модель
                </button>
                <p class="n2j-hint mt-2" id="nj653-probe-say"></p>
                <div id="nj653-probe-live"></div>
            </div>

            <div id="nj653-probe-out" class="mt-4"></div>

            <?php if ($probe): ?>
            <div id="nj653-probe-last" class="n2j-card mt-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="n2j-h3">Что известно о модели</h2>
                    <?php if ($probe_at !== ''): ?>
                    <span class="text-xs text-gray-400">проверено <?php echo HTML::chars(date('d.m.Y H:i', strtotime($probe_at))); ?></span>
                    <?php endif; ?>
                </div>
                <?php $verdict = (array) ($probe['verdict'] ?? []); ?>
                <?php if ($verdict): ?>
                <p class="mt-2 text-sm font-semibold <?php echo empty($verdict['ok'])
                        ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'; ?>">
                    <?php echo HTML::chars((string) $verdict['title']); ?>
                </p>
                <p class="n2j-hint"><?php echo HTML::chars((string) ($verdict['note'] ?? '')); ?></p>
                <?php endif; ?>
                <dl class="mt-3 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
                    <div class="flex gap-2"><dt class="text-gray-400">модель</dt>
                        <dd class="min-w-0 truncate font-mono text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $probe['model']); ?></dd></div>
                    <div class="flex gap-2"><dt class="text-gray-400">размерность</dt>
                        <dd class="font-mono text-gray-700 dark:text-gray-200"><?php echo (int) $probe['dims']; ?></dd></div>
                    <div class="flex gap-2 sm:col-span-2"><dt class="shrink-0 text-gray-400">формат запроса</dt>
                        <dd class="min-w-0 text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $probe['dialect_title']); ?></dd></div>
                </dl>
                <?php if ( ! empty($probe['checks'])): ?>
                <ul class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($probe['checks'] as $check): ?>
                    <li>
                        <?php
                        // Четыре исхода, а не два: прошло, прошло с оговоркой
                        // (вектор воспроизводится почти точно), провал,
                        // пропущено. Жёлтый значок бережёт человека от
                        // мысли, что рабочая модель сломана.
                        $icons = [
                            'ok'   => 'fa-circle-check text-emerald-500',
                            'warn' => 'fa-circle-exclamation text-amber-500',
                            'fail' => 'fa-circle-xmark text-rose-500',
                            'skip' => 'fa-circle-minus text-gray-300',
                        ];
                        $level = (string) ($check['level'] ?? 'skip');
                        ?>
                        <i class="fas <?php echo $icons[$level] ?? $icons['skip']; ?> mr-1"></i>
                        <?php echo HTML::chars((string) $check['title']); ?>
                        <?php if ($check['value'] !== NULL): ?>
                        <span class="font-mono text-gray-400"><?php echo HTML::chars(number_format((float) $check['value'], 4, ',', ' ')); ?></span>
                        <?php endif; ?>
                        <?php if ((string) $check['note'] !== ''): ?>
                        <span class="text-gray-400">— <?php echo HTML::chars((string) $check['note']); ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>

    </div>

    <aside class="mt-5 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
        <section class="n2j-card">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Хранилище</h2>
            <dl class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                <div class="flex justify-between gap-2"><dt>поиск</dt>
                    <dd class="font-semibold"><?php echo empty($stats['pgvector'])
                        ? 'перебор (real[])' : 'pgvector'; ?></dd></div>
                <div class="flex justify-between gap-2"><dt>размерность колонки</dt>
                    <dd class="font-mono"><?php echo (int) $stats['dims']; ?></dd></div>
                <div class="flex justify-between gap-2"><dt>всего элементов</dt>
                    <dd class="font-mono"><?php echo (int) $stats['total']; ?></dd></div>
                <?php if ((string) $stats['model'] !== ''): ?>
                <div class="flex justify-between gap-2"><dt>модель</dt>
                    <dd class="min-w-0 truncate font-mono"><?php echo HTML::chars((string) $stats['model']); ?></dd></div>
                <?php endif; ?>
            </dl>
            <?php if ((int) $stats['dims'] > 2000): ?>
            <p class="n2j-hint mt-2">
                Индекс HNSW на такой ширине вектора pgvector не строит — поиск идёт полным
                перебором. Для витрины на десятки элементов это незаметно.
            </p>
            <?php endif; ?>
        </section>

        <section class="n2j-card">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Что здесь честно</h2>
            <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                <li>· Близость считает PostgreSQL по настоящим векторам модели, а не правила на странице.</li>
                <li>· Формат мультимодального запроса у локальных серверов не стандартизован — воркер подбирает его перебором и показывает, какой сработал.</li>
                <li>· Коллекция и прогоны живут 30 дней: это витрина, а не хранилище.</li>
                <li>· Картинки видны только вам; кадр удаляется сразу после подсчёта вектора, остаётся лишь маленькое превью.</li>
            </ul>
        </section>

        <?php if ($runs): ?>
        <section class="n2j-card">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Мои прогоны</h2>
            <ul class="mt-2 space-y-1.5 text-xs">
                <?php foreach ($runs as $row): ?>
                <li class="flex items-start gap-2">
                    <i class="fas <?php echo (string) $row['status'] === 'done'
                        ? 'fa-circle-check text-emerald-500'
                        : ((string) $row['status'] === 'error' ? 'fa-circle-xmark text-rose-500' : 'fa-clock text-gray-300'); ?> mt-0.5"></i>
                    <?php if ((string) $row['mode'] === 'search'): ?>
                    <a href="/veclab/result/<?php echo (int) $row['id']; ?>" class="min-w-0 flex-1 text-gray-600 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400">
                    <?php else: ?>
                    <span class="min-w-0 flex-1 text-gray-600 dark:text-gray-300">
                    <?php endif; ?>
                        <?php echo HTML::chars(Service_Nj653_Veclab::mode_label($row['mode'])); ?>
                        <span class="text-gray-400">· <?php echo HTML::chars(date('d.m H:i', strtotime((string) $row['created_at']))); ?></span>
                        <?php if ((int) $row['elapsed_ms'] > 0): ?>
                        <span class="font-mono text-gray-400"><?php echo (int) round(((int) $row['elapsed_ms']) / 1000); ?> с</span>
                        <?php endif; ?>
                    <?php if ((string) $row['mode'] === 'search'): ?>
                    </a>
                    <?php else: ?>
                    </span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </aside>
    </div>
    <?php endif; ?>

</div>

<?php if ( ! empty($logged)): ?>
<?php /* Шаблон живого ряда: страница клонирует его на каждый прогон и
 * подставляет адрес статуса. Разметка общая — ряд выглядит так же, как в
 * остальных сервисах с воркером. */ ?>
<template id="nj653-live-tpl">
    <?php echo View::factory('partials/live_status', [
        'url'     => '/veclab/api/status',
        'title'   => 'Считаю векторы',
        'status'  => 'queued',
        'compact' => TRUE,
        'note'    => 'Задачу ведёт воркер: здесь видно этап и время работы.',
    ]); ?>
</template>

<?php echo View::factory('partials/live_status_js'); ?>
<?php echo View::factory('partials/photo_capture_js'); ?>
<?php echo View::factory('653/script', [
    'photo_mb'  => $photo_mb,
    'items_max' => $items_max,
    'bulk_max'  => $bulk_max,
]); ?>
<?php endif; ?>
