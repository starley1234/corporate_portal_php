<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /scout — старт разведки контрагента + мои разведки.
 */

$status_ru = ['running' => 'идёт', 'done' => 'готово', 'error' => 'ошибка'];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-3 text-xs block">
        <?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <div class="mb-4">
        <h1 class="n2j-h1"><i class="fas fa-user-secret mr-2 text-blue-500"></i>Разведка контрагента</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Один вход — полный контур: справочник (ЕГРЮЛ/ЕГРИП), сайт компании, наблюдение за рисками, AI-досье с отзывами и первое письмо по фактам.</p>
    </div>

    <?php if ($logged): ?>
    <!-- Быстрая проверка: одна строка → карточка. Работает и без входа. -->
    <form method="get" action="/check" class="mb-4 rounded-xl border-2 border-dashed border-blue-300/80 bg-blue-50/40 p-4 dark:border-blue-700/60 dark:bg-blue-950/20">
        <label for="scout-quick" class="block text-xs font-semibold text-blue-800 sm:text-sm dark:text-blue-200">
            <i class="fas fa-bolt mr-1.5"></i>Быстрая проверка — карточка сразу
        </label>
        <div class="mt-1.5 flex flex-col gap-2.5 sm:flex-row">
            <input id="scout-quick" type="text" name="q" required autocomplete="off" placeholder="Компания, ИНН/ОГРН, сайт или ссылка на вакансию"
                   class="n2j-input w-full">
            <button type="submit" class="n2j-btn-process shrink-0">
                <i class="fas fa-magnifying-glass mr-2"></i>Проверить
            </button>
        </div>
        <p class="n2j-hint mt-2">Реквизиты ЕГРЮЛ/ЕГРИП, вакансии компании и сигналы — без входа и без ожидания. Полный маршрут (сайт, наблюдение, досье, письмо) — ниже.</p>
    </form>
    <?php else: ?>
    <!-- Гостю: та же быстрая проверка, но отдельной публичной страницей /check —
         разведка целиком (сайт, наблюдение, досье, письмо) только по аккаунту. -->
    <a href="/check" class="mb-4 flex items-center gap-3 rounded-xl border-2 border-dashed border-blue-300/80 bg-blue-50/40 p-4 transition hover:border-blue-400 hover:bg-blue-50/70 dark:border-blue-700/60 dark:bg-blue-950/20 dark:hover:border-blue-600">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/80 text-blue-600 shadow-xs dark:bg-gray-900/60 dark:text-blue-300">
            <i class="fas fa-bolt" aria-hidden="true"></i>
        </span>
        <span class="min-w-0">
            <span class="block text-sm font-bold text-gray-900 dark:text-white">Быстрая проверка — карточка сразу, без входа</span>
            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">Реквизиты ЕГРЮЛ/ЕГРИП, вакансии компании и сигналы — на отдельной странице /check. Полный маршрут разведки открывается по аккаунту.</span>
        </span>
        <i class="fas fa-arrow-right ml-auto shrink-0 text-blue-500" aria-hidden="true"></i>
    </a>
    <?php endif; ?>

    <div class="gap-4 space-y-4 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Левая часть: создание ============ -->
        <div class="space-y-4 md:col-span-2">
            <div class="n2j-card">

                <?php if ( ! $logged): ?>
                <?php echo Gate::wall('scout', [
                    'icon'   => 'fas fa-user-secret',
                    'title'  => 'Разведка контрагента — по бесплатному аккаунту',
                    'text'   => 'Маршрут читает сайт компании, реестр и новости — это работа агента и очереди задач. Аккаунт отделяет людей от ботов, а ваши разведки привязывает к вам: карта шагов открывается с любого устройства.',
                    'points' => [
                        'Карточку контрагента из реестра: ИНН, ОГРН, статус, руководитель',
                        'Профиль сайта и AI-досье с реальными фактами и отзывами',
                        'Наблюдение за рисками: суды, банкротство, резкие новости',
                        'Готовое первое письмо по фактам — когда укажете свой оффер',
                    ],
                    'stats'  => ['Beta, бесплатно', 'обычно 2–4 минуты'],
                    'cta'    => 'Войти и начать разведку',
                    'note'   => 'Быстрая проверка (реквизиты и вакансии) работает и без входа — отдельной страницей /check. Разведку видите только вы.',
                ]); ?>
                <?php else: ?>
                <form method="post" action="/scout" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div>
                        <label class="n2j-label">Контрагент <span class="text-rose-500">*</span></label>
                        <input type="text" name="query" required maxlength="300" placeholder="Домен, название, ИНН или ОГРН"
                               value="<?php echo HTML::chars((string) Arr::get($_GET, 'q', '')); ?>"
                               class="n2j-input mt-1 w-full">
                        <p class="n2j-hint">Локальные адреса и ссылки-агрегаторы не принимаем — только публичные сайты и настоящие реквизиты.</p>
                    </div>
                    <button type="submit" class="n2j-btn-process w-full">
                        <i class="fas fa-satellite-dish mr-1"></i>Начать разведку
                    </button>
                    <p class="n2j-hint">Мы не собираем данные сами — запускаем только ваши же сервисы: справочник, сайт-сканер, сторожа, досье. Всё ссылается на один маршрут.</p>
                </form>
                <?php endif; ?>
            </div>

            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-route mr-1.5 text-gray-400"></i>Маршрут проверки</p>
                <ol class="mt-2 space-y-2">
                    <?php foreach ($steps as $key => $s): ?>
                    <li class="flex gap-2 text-xs text-gray-600 dark:text-gray-300">
                        <span class="mt-0.5 shrink-0"><i class="<?php echo HTML::chars($s['icon']); ?> text-blue-500"></i></span>
                        <span><b><?php echo HTML::chars($s['title']); ?></b> — <?php echo HTML::chars($s['desc']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <p class="n2j-hint mt-3">Письмо не стартует автоматически — для него нужен ваш оффер. Остальное запускается сразу.</p>
            </div>
        </div>

        <!-- ============ Правая часть: мои разведки ============ -->
        <div class="space-y-4">
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-clock-rotate-left mr-1.5 text-gray-400"></i>Мои разведки</p>

                <?php if ( ! $logged): ?>
                <p class="n2j-hint mt-2">Войдите, чтобы видеть свои маршруты.</p>
                <?php elseif (empty($my)): ?>
                <p class="n2j-hint mt-2">Пока нет ни одной разведки.</p>
                <?php else: ?>
                <ul class="mt-2 space-y-1.5">
                    <?php foreach ($my as $r): ?>
                    <li class="rounded-xl border border-gray-100 px-3 py-2 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <a href="/scout/<?php echo HTML::chars($r['code']); ?>" class="font-mono text-xs font-bold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($r['code']); ?></a>
                            <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(($r['company'] !== '' ? $r['company'] : $r['query'])); ?></span>
                            <span class="text-xs text-gray-400"><?php echo HTML::chars((string) $r['created_at']); ?></span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
