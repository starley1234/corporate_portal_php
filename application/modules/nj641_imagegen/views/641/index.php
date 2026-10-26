<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Генератор изображений (nj641_imagegen) — страница /imagegen.
 *
 * Режимы: по описанию, редактирование, мульти-референсы. Генерация серверная:
 * /imagegen/api/run ставит задачу канала nj641.generate (воркер + внешний
 * image-API, проверка видеопамяти), результат — картинка.
 */
$sizes         = isset($sizes) ? $sizes : [];
$default_size  = isset($default_size) ? $default_size : '1024';
$recent        = isset($recent) ? $recent : [];
$vram          = isset($vram) ? $vram : [];
$rate_text     = isset($rate_text) ? $rate_text : '5 генераций в минуту';
$max_input_mb  = isset($max_input_mb) ? $max_input_mb : '10';
$vram_check    = ! empty($vram['check']);
$vram_wait     = ! empty($vram['wait']);
// Гость видит плашку входа вместо формы: генерация — платная работа сервера
// (владелец, 08.10.2026). Справка о сервисе и «Как это работает» остаются.
$logged        = ! empty($logged);
?>
<input type="hidden" id="nj641-csrf" value="<?php echo HTML::chars(isset($csrf) ? $csrf : ''); ?>">
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">Генератор изображений</h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-wand-magic-sparkles text-[11px]"></i>Серверная генерация
        </span>
    </div>
</div>
<p class="mt-1.5 text-xs sm:text-sm text-gray-600 dark:text-gray-300">Картинки по описанию, редактирование и мульти-референсы. Задача уходит в очередь, рисует воркер на внешнем image-API (с проверкой видеопамяти); результат можно переиспользовать в презентациях и статьях.</p>

<?php if ( ! empty($can_review)): ?>
<div class="mt-3 flex flex-wrap items-center gap-2">
    <span class="inline-flex min-h-[36px] items-center gap-1.5 rounded-full border border-blue-600 bg-blue-600 px-3.5 text-sm font-semibold text-white shadow-xs">
        <i class="fas fa-wand-magic-sparkles text-[11px]"></i>Генератор
    </span>
    <a href="/imagegen/gallery" class="n2j-btn-secondary n2j-btn-sm">
        <i class="fas fa-images text-blue-500"></i>Изображения
        <?php if ( ! empty($review_count)): ?>
        <span class="rounded-full bg-amber-100 px-1.5 text-sm font-bold text-amber-700 dark:bg-amber-950/60 dark:text-amber-300" title="На модерации"><?php echo (int) $review_count; ?></span>
        <?php endif; ?>
    </a>
</div>
<?php endif; ?>

<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

    <!-- ============================ форма (2/3) ============================ -->
    <div class="space-y-6 md:col-span-2">

        <?php if ( ! $logged): ?>
        <?php echo Gate::wall('imagegen', [
            'icon'   => 'fas fa-wand-magic-sparkles',
            'title'  => 'Генератор изображений — по бесплатному аккаунту',
            'text'   => 'Рисует сервер: каждая картинка занимает очередь и видеопамять. Аккаунт отделяет людей от ботов, а историю генераций делает вашей — она открывается с любого устройства.',
            'points' => [
                'Картинки по описанию: «филин из граней кристалла, минимализм»',
                'Редактирование по картинке и мульти-референсы — до 3 входов',
                'Размеры от 512 × 512 до 1280 × 1280, seed для повторяемости',
                'Готовый файл: скачать или переиспользовать в статьях и презентациях',
            ],
            'stats'  => ['Beta, бесплатно', 'Лимит: '.$rate_text],
            'cta'    => 'Войти и сгенерировать картинку',
            'note'   => 'История хранится у вас в аккаунте, картинки — файлами в хранилище сайта. Ничего не публикуется само: пока не отправите в библиотеку, картинку видите только вы.',
        ]); ?>

        <section class="n2j-card">
            <h2 class="n2j-h3">Три режима</h2>
            <ul class="mt-3 space-y-2.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                <li class="flex gap-2.5"><i class="fas fa-feather-pointed mt-1 text-blue-500" aria-hidden="true"></i>
                    <span><span class="font-semibold text-gray-900 dark:text-white">По описанию.</span> Чем больше деталей в описании — тем точнее картинка; «что исключить» убирает водяные знаки, текст и лишние детали.</span></li>
                <li class="flex gap-2.5"><i class="fas fa-pen-to-square mt-1 text-blue-500" aria-hidden="true"></i>
                    <span><span class="font-semibold text-gray-900 dark:text-white">Редактировать.</span> Приложите картинку и напишите, что изменить; сила правки — от 0.1 (чуть-чуть) до 1.0 (почти заново).</span></li>
                <li class="flex gap-2.5"><i class="fas fa-layer-group mt-1 text-blue-500" aria-hidden="true"></i>
                    <span><span class="font-semibold text-gray-900 dark:text-white">Мульти-референсы.</span> До трёх картинок задают вид и стиль — модель соберёт новое изображение по ним.</span></li>
            </ul>
        </section>
        <?php else: ?>
        <div class="n2j-card">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="n2j-h3">Создать изображение</h2>
            </div>

            <div class="mt-4 space-y-4 sm:mt-5">

                <div>
                    <label class="n2j-label">Режим</label>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        <button type="button" data-mode="generate"
                                class="n2j-btn-secondary n2j-btn-sm nj641-mode">
                            <i class="fas fa-feather-pointed text-blue-500"></i> По описанию</button>
                        <button type="button" data-mode="edit"
                                class="n2j-btn-secondary n2j-btn-sm nj641-mode">
                            <i class="fas fa-pen-to-square text-blue-500"></i> Редактировать</button>
                        <button type="button" data-mode="multi-reference"
                                class="n2j-btn-secondary n2j-btn-sm nj641-mode">
                            <i class="fas fa-layer-group text-blue-500"></i> Мульти-референсы</button>
                    </div>
                </div>

                <div>
                    <label for="nj641-prompt" class="n2j-label">Описание</label>
                    <textarea id="nj641-prompt" rows="3" placeholder="Например: логотип стартапа — филин из граней кристалла, тёмно-синий, минимализм"
                              autocomplete="off" spellcheck="false"
                              class="n2j-textarea mt-1.5 min-h-[44px] w-full leading-relaxed"></textarea>
                </div>

                <div>
                    <label for="nj641-negative" class="n2j-label">Что исключить <span class="font-normal text-gray-400">(необязательно)</span></label>
                    <input id="nj641-negative" type="text" placeholder="водяные знаки, текст, мутация" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>

                <div class="grid gap-4 sm:grid-cols-3 sm:gap-5">
                    <div>
                        <label for="nj641-size" class="n2j-label">Размер</label>
                        <select id="nj641-size" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                            <?php foreach ($sizes as $px => $dim): ?>
                            <option value="<?php echo (int) $px; ?>"<?php echo ((int) $px === (int) $default_size) ? ' selected' : ''; ?>><?php echo (int) $dim[0]; ?> × <?php echo (int) $dim[1]; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="nj641-seed" class="n2j-label">Seed <span class="font-normal text-gray-400">(0 — случайный)</span></label>
                        <input id="nj641-seed" type="number" min="0" max="2147483647" value="0" autocomplete="off"
                               class="n2j-input mt-1.5 w-full">
                    </div>
                    <div id="nj641-strength-wrap" class="hidden">
                        <label for="nj641-strength" class="n2j-label">Сила правки <span id="nj641-strength-val" class="font-normal text-gray-400">0.7</span> <span class="font-normal text-gray-400">— сервер правки её не использует</span></label>
                        <input id="nj641-strength" type="range" min="0.1" max="1.0" step="0.05" value="0.7"
                               class="mt-3.5 w-full accent-blue-600">
                    </div>
                </div>

                <div id="nj641-image-wrap" class="hidden">
                    <label for="nj641-photo" class="n2j-label">Изображения <span class="font-normal text-gray-400">(1 для правки, до 3 референсов)</span></label>
                    <input id="nj641-photo" type="file" accept="image/*" multiple
                           class="mt-1.5 w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 file:mr-3 file:rounded-md file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                    <p class="n2j-hint">jpg/png/webp/bmp/gif, до <?php echo HTML::chars($max_input_mb); ?> МБ каждый.</p>
                </div>

                <div class="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center">
                    <button type="button" id="nj641-go"
                            class="n2j-btn-process w-full sm:w-auto">
                        <i class="fas fa-wand-magic-sparkles"></i> Сгенерировать
                    </button>
                    <p class="n2j-hint text-center sm:text-left">Лимит: <?php echo HTML::chars($rate_text); ?></p>
                </div>

                <!-- Статус-ряд (контракт /trends): что делает воркер, сколько
                     идёт по факту и кнопка «Остановить», если затянулось. -->
                <div id="nj641-status" class="hidden flex-wrap items-center gap-x-3 gap-y-1.5 rounded-lg border border-gray-200 bg-gray-50/70 px-3.5 py-2.5 text-sm text-gray-600 dark:border-gray-700/70 dark:bg-gray-900/40 dark:text-gray-300">
                    <span class="inline-flex items-center gap-2">
                        <i class="fas fa-spinner n2j-spin text-sm text-blue-500"></i>
                        <span id="nj641-status-text">Генерирую…</span>
                    </span>
                    <span id="nj641-stage" class="truncate text-gray-500 dark:text-gray-400"></span>
                    <span id="nj641-timer" class="font-mono text-sm text-gray-400 dark:text-gray-500"></span>
                    <button type="button" id="nj641-stop"
                            class="n2j-btn-danger n2j-btn-sm ml-auto">
                        <i class="fas fa-stop"></i> Остановить
                    </button>
                </div>
                <p id="nj641-warn" class="hidden text-sm text-amber-600 dark:text-amber-400"></p>

                <div id="nj641-error" class="n2j-flash n2j-flash-err hidden block"></div>

                <div id="nj641-result" class="hidden rounded-lg border border-blue-200 bg-blue-50/60 px-3.5 py-3 dark:border-blue-900/60 dark:bg-blue-950/30">
                    <div class="flex items-center justify-between gap-3">
                        <p id="nj641-result-meta" class="text-xs font-semibold text-blue-800 dark:text-blue-300"></p>
                        <a id="nj641-result-dl" href="#" download="imagegen.png" class="n2j-btn-process n2j-btn-sm">Скачать</a>
                    </div>
                    <img id="nj641-result-img" alt="Результат генерации" class="mt-2.5 w-full rounded-lg border border-blue-200/60 bg-white dark:border-blue-900/60 dark:bg-gray-900">
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================ компаньон (1/3) ============================ -->
    <div class="hidden space-y-5 md:block">
        <div class="n2j-card">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Как это работает</h3>
            <ul class="mt-3 space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Запрос → очередь nj120 → воркер nj641.generate → внешний image-API → картинка.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Воркер проверяет видеопамять (GET /vram) и при нехватке ждёт — на сервере память могут занимать другие процессы.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Один и тот же seed даёт ту же картинку; «по описанию» сложнее — больше деталей в описании.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Картинка сохраняется файлом (<code class="font-mono">storage/nj641</code>) и отдаётся ссылкой <code class="font-mono">/imagegen/file/…</code> — её переиспользуют другие сервисы.</span></li>
            </ul>
        </div>

        <?php if ($logged): ?>
        <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-5 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40">
            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                <i class="fas fa-cloud-arrow-up text-blue-600 dark:text-blue-400"></i>
                <h3 class="text-sm font-bold">Серверная генерация</h3>
            </div>
            <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                Рисует воркер на сервере, а не браузер. Адрес генерации (image-API) задаётся на воркере — <code class="font-mono">NJ641_IMAGE_API</code>, приём результата — <code class="font-mono">NJ641_API_BASE</code>. Проверка видеопамяти: <span class="font-semibold"><?php echo $vram_check ? 'вкл.' : 'выкл.'; ?> · ожидание при нехватке — <?php echo $vram_wait ? 'вкл.' : 'выкл.'; ?></span>.
            </p>
            <?php if (empty($vram['backend_set'])): ?>
            <p class="n2j-flash n2j-flash-warn mt-2 block">
                Бекенд пока не подключён: на сайте переменная <code class="font-mono">NJ641_IMAGE_API</code> пуста (это нормально — она нужна воркеру). Пока не зададите адрес image-API на воркере, генерация будет падать с «внешний API недоступен».
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="n2j-card">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Из других сервисов</h3>
            <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                Презентации, статьи и посты ставят генерацию через
                <code class="font-mono">Service_Nj641_Imagegen::enqueue()</code> и забирают картинку
                <code class="font-mono">result_of()</code> — без дублирования логики.
            </p>
        </div>

        <?php if ($logged): ?>
        <div class="n2j-card">
            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">История</h3>
            <?php if ( ! $recent): ?>
            <p class="n2j-hint mt-3">Пока ничего не генерировали.</p>
            <?php else: ?>
            <ul class="mt-3 space-y-1">
                <?php foreach ($recent as $r): ?>
                <li class="flex items-center gap-1">
                    <button type="button" data-run="<?php echo (int) $r['id']; ?>"
                            data-prompt="<?php echo HTML::chars((string) $r['prompt']); ?>"
                            data-mode="<?php echo HTML::chars((string) $r['mode']); ?>"
                            data-width="<?php echo (int) $r['width']; ?>"
                            class="nj641-history flex min-w-0 flex-1 items-center gap-2 rounded-lg border border-transparent px-1.5 py-1.5 text-left transition hover:border-gray-200 hover:bg-white dark:hover:border-gray-700/60 dark:hover:bg-gray-800/60">
                        <?php if ( ! empty($r['image_url'])): ?>
                        <img src="<?php echo HTML::chars($r['image_url']); ?>" alt="" loading="lazy"
                             class="h-10 w-10 shrink-0 rounded-md border border-gray-200 object-cover dark:border-gray-700/60">
                        <?php else: ?>
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-gray-100 <?php echo empty($r['has_image']) ? 'text-gray-400 dark:text-gray-600' : 'text-blue-400'; ?> dark:bg-gray-900">
                            <i class="fas <?php echo empty($r['has_image']) ? 'fa-triangle-exclamation' : 'fa-image'; ?>"></i>
                        </span>
                        <?php endif; ?>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2 text-xs text-gray-400 dark:text-gray-500">
                                <span class="font-mono">#<?php echo (int) $r['id']; ?></span>
                                <span class="truncate"><?php echo HTML::chars($r['mode']); ?></span>
                                <?php if (empty($r['has_image'])): ?>
                                <span class="n2j-badge n2j-badge-err shrink-0">ошибка</span>
                                <?php endif; ?>
                                <?php if ( ! empty($r['created_at'])): ?>
                                <span class="ml-auto shrink-0 whitespace-nowrap"><?php echo date('d.m H:i', strtotime((string) $r['created_at'])); ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="mt-0.5 block truncate text-sm text-gray-600 dark:text-gray-300"><?php echo HTML::chars($r['prompt']); ?></span>
                        </span>
                    </button>
                    <button type="button" data-n2j-arm="Удалить?" data-run="<?php echo (int) $r['id']; ?>" title="Удалить из истории" aria-label="Удалить"
                            class="n2j-btn-icon-danger nj641-del shrink-0"><i class="fas fa-xmark"></i></button>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php if ($logged): ?>
<?php echo View::factory('641/script'); ?>
<?php endif; ?>
