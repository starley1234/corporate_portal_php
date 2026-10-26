<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /design — создание прогона дизайна + мои прогоны (эталон 2/3 + 1/3).
 */

$status_ru = [
    'new'     => 'в очереди', 'running' => 'работает',
    'done'    => 'готово',    'error'   => 'ошибка',
];
$max_n = (int) ($limits['max_variants'] ?? 5);
$def_n = (int) ($limits['default_variants'] ?? 3);

$chip = function ($st) {
    if ($st === 'done')
    {
        return ['готово', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300', 'bg-emerald-500', ''];
    }

    if ($st === 'error')
    {
        return ['ошибка', 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300', 'bg-rose-500', ''];
    }

    return [$status_ru[$st] ?? $st, 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300', 'bg-amber-500', 'animate-pulse'];
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-4 flex items-center gap-3 text-xs">
        <i class="fas fa-circle-check shrink-0"></i><?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <header class="border-b border-transparent">
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сервисы</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-medium text-gray-700 dark:text-gray-200">Дизайн страницы</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
            <h1 class="n2j-h1">Дизайн страницы</h1>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
                <i class="fas fa-palette text-[11px]"></i>Дизайн
            </span>
        </div>
        <p class="mt-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">Пришлите адрес страницы — ИИ изучит её и подготовит несколько вариантов улучшенного оформления: композиция, палитра, типографика.</p>
    </header>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Форма (2/3) ============ -->
        <div class="n2j-card md:col-span-2">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="n2j-h3">Создать прогон</h2>
            </div>

            <?php if ( ! $logged): ?>
            <div class="mt-4">
                <?php echo Gate::wall('design', [
                    'icon'   => 'fas fa-palette',
                    'title'  => 'Дизайн страницы — по бесплатному аккаунту',
                    'text'   => 'Каждый прогон — это разбор живой страницы и несколько вариантов оформления: работу делает LLM, а варианты хранятся в аккаунте. Вход нужен, чтобы очередь не расходовалась анонимами, а прогоны открывались с любого устройства.',
                    'points' => [
                        'Разбор страницы: композиция, палитра, типографика',
                        'Несколько вариантов улучшенного оформления за прогон',
                        'Ваши пожелания словами учитываются при генерации',
                        'Мои прогоны и варианты — история в аккаунте',
                    ],
                    'stats'  => ['Beta, бесплатно', 'ссылка на страницу — всё, что нужно'],
                    'cta'    => 'Войти и создать прогон',
                    'note'   => 'Страница должна быть доступна из интернета без пароля — скачиваем её один раз, только чтобы разобрать структуру.',
                ]); ?>
            </div>
            <?php else: ?>
            <form method="post" action="/design" class="mt-4 space-y-4 sm:mt-5 sm:space-y-5">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

                <div>
                    <label for="nj590-url" class="n2j-label">
                        Адрес страницы (URL) <span class="font-normal text-gray-400">— обязательное</span>
                    </label>
                    <input id="nj590-url" name="url" type="url" required maxlength="2000" placeholder="https://example.com/page" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                    <p class="n2j-hint">Страница должна быть доступна из интернета без пароля. Мы скачиваем её один раз — только чтобы разобрать структуру.</p>
                </div>

                <?php if (Auth::instance()->logged_in('admin')): ?>
                <div>
                    <label for="nj590-model" class="n2j-label">
                        Модель <span class="font-normal text-gray-400">— только администратору</span>
                    </label>
                    <input id="nj590-model" name="model" type="text" list="n590-models" value="<?php echo HTML::chars($saved_model); ?>" maxlength="120"
                           placeholder="из настроек воркера" autocomplete="off"
                           class="n2j-input mt-1.5 min-h-[44px] w-full py-2.5 font-mono text-xs">
                    <datalist id="n590-models">
                        <option value="openrouter::google/gemini-3.8-flash:floor"></option>
                        <option value="openrouter::openai/gpt-5.6-luna"></option>
                        <option value="unsloth/gemma-4-12b-it"></option>
                    </datalist>
                    <p class="n2j-hint">Спека «провайдер::модель» или просто «модель». Выбор запоминается в куках браузера.</p>
                </div>
                <?php endif; ?>

                <div>
                    <label for="nj590-wishes" class="n2j-label">Пожелания к дизайну <span class="font-normal text-gray-400">(необязательно)</span></label>
                    <textarea id="nj590-wishes" name="wishes" rows="3" maxlength="<?php echo (int) ($limits['max_wishes_len'] ?? 4000); ?>" placeholder="Например: строго и делово, синяя палитра, крупная типографика, обязательный блок с ценами…"
                              class="n2j-textarea mt-1.5 min-h-[44px] w-full"></textarea>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                    <div>
                        <label for="nj590-n" class="n2j-label">Сколько вариантов</label>
                        <select id="nj590-n" name="variants" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                            <?php for ($i = 1; $i <= $max_n; $i++): ?>
                            <option value="<?php echo $i; ?>"<?php echo $i === $def_n ? ' selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <p class="n2j-hint">Каждый вариант — отдельная генерация: чем больше вариантов, тем дольше сборка (обычно несколько минут).</p>
                    </div>
                </div>

                <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:items-center">
                    <button type="submit" class="n2j-btn-cta w-full sm:w-auto">
                        <i class="fas fa-wand-magic-sparkles"></i>Создать прогон
                    </button>
                    <a href="#my-runs" class="n2j-btn-secondary w-full sm:w-auto">Мои прогоны</a>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <!-- ============ Компаньон (1/3, на мобильных скрыт) ============ -->
        <div class="space-y-5">
            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-circle-info text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold">Как это работает</h3>
                </div>
                <ol class="mt-2.5 list-decimal space-y-1.5 pl-4 text-xs text-gray-600 dark:text-gray-300">
                    <li>Вы присылаете URL страницы и (по желанию) пожелания.</li>
                    <li>Воркер бережно разбирает страницу: структура, тексты, палитра.</li>
                    <li>ИИ готовит N вариантов — каждый отдельной готовой HTML-страницей без внешних зависимостей.</li>
                    <li>Сравниваете варианты на странице прогона и выбираете лучший — выбор сохраняется.</li>
                </ol>
            </div>

            <div class="hidden rounded-xl border border-amber-200 bg-amber-50/80 p-4 shadow-xs md:block dark:border-amber-900/60 dark:bg-amber-950/30 sm:p-5">
                <div class="flex items-center gap-2 text-amber-950 dark:text-amber-200">
                    <i class="fas fa-lightbulb text-amber-600 dark:text-amber-400"></i>
                    <h3 class="text-sm font-bold">Советы</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-amber-900/90 dark:text-amber-200/90">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-amber-600">•</span><span>Указывайте прямую ссылку без авторизации и попапов.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-amber-600">•</span><span>Пожелания пишите словами: аудитория, тон, обязательные блоки.</span></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ============ Мои прогоны (таблица) ============ -->
    <?php // Таблица прогонов — только для вошедших: гостю пустая таблица ничего не
          // сообщает, а «Пока нет ни одного прогона» — неправда про его аккаунт. ?>
    <?php if ($logged): ?>
    <div id="my-runs" class="n2j-card p-0 mt-6 scroll-mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Мои прогоны</h2>
                <span class="n2j-badge n2j-badge-wait"><?php echo count($my); ?></span>
            </div>
            <span class="text-xs text-gray-400">Воркер скачает страницу и соберёт варианты — обычно несколько минут</span>
        </div>

        <div class="overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
            <table class="n2j-table whitespace-nowrap">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                        <th class="py-2.5 pr-3 pl-4 sm:pl-5">Код / Страница</th>
                        <th class="py-2.5 px-3">Статус</th>
                        <th class="py-2.5 px-3">Варианты</th>
                        <th class="py-2.5 px-3">Создан</th>
                        <th class="py-2.5 pr-4 pl-3 text-right sm:pr-5">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                    <?php foreach ($my as $r): [$stl, $stc, $dot, $pulse] = $chip($r['status']); ?>
                    <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                        <td class="max-w-[320px] py-2.5 pr-3 pl-4 sm:pl-5">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs font-bold text-blue-600 dark:text-blue-400"><?php echo HTML::chars($r['code']); ?></span>
                                <span class="truncate font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($r['host'].($r['title'] !== '' ? ' — '.$r['title'] : '')); ?></span>
                            </div>
                            <?php if ($r['status'] === 'error' AND $r['error']): ?>
                            <p class="mt-0.5 max-w-[300px] truncate text-xs text-rose-400" title="<?php echo HTML::chars($r['error']); ?>"><?php echo HTML::chars(mb_substr($r['error'], 0, 100)); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="py-2.5 px-3">
                            <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold <?php echo $stc; ?>">
                                <span class="h-2 w-2 rounded-full <?php echo $dot.$pulse; ?>"></span><?php echo HTML::chars($stl); ?>
                            </span>
                        </td>
                        <td class="py-2.5 px-3 text-xs text-gray-600 dark:text-gray-300"><?php echo (int) $r['variants_ready']; ?>/<?php echo (int) $r['variants_count']; ?></td>
                        <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $r['created_at'], 'd.m.y H:i')); ?></td>
                        <td class="py-2.5 pr-4 pl-3 text-right sm:pr-5">
                            <div class="inline-flex items-center justify-end gap-1.5">
                                <a href="/design/<?php echo HTML::chars($r['code']); ?>" class="n2j-btn-secondary n2j-btn-sm">Открыть</a>
                                <?php if (in_array($r['status'], ['new', 'running'], TRUE)): ?>
                                <form method="post" action="/design/<?php echo HTML::chars($r['code']); ?>/cancel">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <button data-n2j-arm="Остановить?" type="submit" class="n2j-btn-danger n2j-btn-sm"><span data-n2j-arm-label>Остановить</span></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($my)): ?>
                    <tr><td colspan="5" class="py-4 pl-4 text-xs text-gray-400 sm:pl-5">Пока нет ни одного прогона — создайте первый формой выше.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

