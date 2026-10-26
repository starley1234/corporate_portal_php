<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj642_vacancies: /vacancies — мониторы вакансий и лента находок.
 *
 * Порядок экрана: (1) что это и форма монитора, (2) мои мониторы с живым
 * статус-рядом, (3) лента вакансий с чипами статусов, поиском и «Строк:».
 * Ничего лишнего: владелец требует «эстетично, лаконично, понятно».
 */
$items   = isset($items) ? (array) $items : [];
$watches = isset($watches) ? (array) $watches : [];
$live    = isset($live) ? (array) $live : [];
$last    = isset($last) ? (array) $last : [];
$hints   = isset($hints) ? (array) $hints : [];
$counts  = isset($counts) ? (array) $counts : ['active' => 0, 'new' => 0, 'saved' => 0, 'gone' => 0];
$f       = isset($f) ? (array) $f : ['status' => 'active', 'watch' => '', 'company' => '', 'city' => '',
                                     'direction' => '', 'q' => '', 'page' => 1, 'per' => 20, 'total' => 0, 'pages' => 1];
$csrf    = isset($csrf) ? (string) $csrf : '';
$flash   = isset($flash) ? trim((string) $flash) : '';
$signed  = ! empty($signed);

$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800';

// Ссылка с сохранением фильтров (образец /nj593/admin и /nj602/admin).
$nj642_qs = function (array $over) use ($f) {
    $qs = array_filter(array_merge([
        'status'    => (string) $f['status'],
        'watch'     => (string) $f['watch'],
        'company'   => (string) $f['company'],
        'city'      => (string) $f['city'],
        'direction' => (string) $f['direction'],
        'q'         => (string) $f['q'],
        'per'       => (int) $f['per'],
        'page'      => (int) $f['page'],
    ], $over), function ($v) {
        return (string) $v !== '' AND (string) $v !== '0';
    });

    return '/vacancies'.($qs ? '?'.http_build_query($qs) : '');
};

$chips = [
    'active' => ['Активные',  (int) $counts['active']],
    'new'    => ['Новые',     (int) $counts['new']],
    'saved'  => ['Избранное', (int) $counts['saved']],
    'gone'   => ['Закрытые',  (int) $counts['gone']],
];

$st_ru = [
    'queued'    => 'в очереди',
    'running'   => 'идёт сбор',
    'done'      => 'готово',
    'failed'    => 'ошибка',
    'cancelled' => 'остановлено',
];
$st_badge = [
    'queued'    => 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    'running'   => 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    'done'      => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    'failed'    => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
    'cancelled' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
];
?>
<div class="n2j-ds w-full space-y-5 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <header>
        <h1 class="n2j-h1">Вакансии компаний</h1>
        <p class="mt-1.5 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
            Смотрим страницы «Карьера», «Вакансии» и «О компании» на сайтах работодателей. Видно, что открылось и что закрылось,
            а о новых подходящих вакансиях приходит письмо.
        </p>
    </header>

    <?php if ( ! $signed): ?>
    <div class="grid gap-5 lg:grid-cols-2">
        <?php echo Gate::wall('vacancies', [
            'icon'   => 'fas fa-briefcase',
            'title'  => 'Монитор вакансий — по бесплатному аккаунту',
            'text'   => 'Сервис сам находит страницы «Карьера», «Вакансии» и «О компании» на сайтах работодателей и следит за ними. Аккаунт нужен, чтобы подбор и рассылка не расходовались анонимами, а мониторы и избранное были вашими.',
            'points' => [
                'Монитор по городу и направлению: сайты работодателей сервис найдёт сам',
                'Видно, что открылось и что закрылось — сравнение с прошлым сбором',
                'Кнопка «Собрать сейчас» и расписание: свежие вакансии без ручной проверки',
                'Письмо о новых подходящих вакансиях и избранное в аккаунте',
            ],
            'stats'  => ['Beta, бесплатно', 'по расписанию и кнопкой'],
            'cta'    => 'Войти и завести монитор',
            'note'   => 'Только официальные сайты компаний — без агрегаторов и перепродажи баз.',
        ]); ?>

        <div class="space-y-5">
            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3"><i class="fas fa-route mr-1.5 text-blue-500"></i>Как работает монитор</h2>
                <ol class="mt-3 space-y-2.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2.5"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">1</span>
                        <span>Указываете город и направление — сервис подбирает сайты работодателей по карте отрасли.</span></li>
                    <li class="flex gap-2.5"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">2</span>
                        <span>Собирает вакансии с официальных страниц и сравнивает с прошлым сбором: открылось / закрылось.</span></li>
                    <li class="flex gap-2.5"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">3</span>
                        <span>Показывает ленту с чипами статусов, поиском и городами; подходящие — приходят письмом.</span></li>
                </ol>
                <p class="n2j-hint mt-3 border-t border-gray-100 pt-3">
                    Вакансии ищут для соискателя: смотрим только то, что компании опубликовали сами.
                </p>
            </div>

            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Что уже доступно без аккаунта</h2>
                <ul class="mt-3 space-y-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2.5"><i class="fas fa-check mt-1 text-emerald-500" aria-hidden="true"></i><span>Быстрая проверка компании по реестру и её вакансиям — страница <a href="/check" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">/check</a>.</span></li>
                    <li class="flex gap-2.5"><i class="fas fa-check mt-1 text-emerald-500" aria-hidden="true"></i><span>Каталог вакансий и карточки компаний — раздел <a href="/jobs" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">/jobs</a>.</span></li>
                    <li class="flex gap-2.5"><i class="fas fa-check mt-1 text-emerald-500" aria-hidden="true"></i><span>Реквизиты организаций из реестра — справочник <a href="/orgs" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">/orgs</a>.</span></li>
                </ul>
            </div>
        </div>
    </div>
    <?php else: ?>

    <!-- 1. Монитор: город, направление, компании -->
    <section class="<?php echo $card; ?>">
        <h2 class="n2j-h3"><i class="fas fa-plus-circle mr-1.5 text-blue-500"></i>Новый монитор вакансий</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Укажите город и направление — сайты работодателей сервис найдёт сам и покажет их в мониторе. Подходящие вакансии собираются автоматически: по расписанию и кнопкой «Собрать сейчас».</p>

        <form method="post" class="mt-3.5 space-y-3" data-nj642-create>
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <input type="hidden" name="act" value="create">

            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <label class="block">
                    <span class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-200">Город</span>
                    <input type="text" name="city" maxlength="120" placeholder="например Москва" required
                           class="n2j-input w-full">
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-200">Направление</span>
                    <input type="text" name="direction" maxlength="200" placeholder="например повар, сварщик, 1С" required
                           class="n2j-input w-full">
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-200">Ключевые слова <span class="font-normal text-gray-400">(необязательно)</span></span>
                    <input type="text" name="keywords" maxlength="300" placeholder="например ночные смены, водительские права"
                           class="n2j-input w-full">
                </label>
            </div>

            <details class="rounded-lg border border-gray-200 px-3 py-2.5 dark:border-gray-700">
                <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-200">
                    Свои компании <span class="font-normal text-gray-400">(необязательно — обычно не нужно)</span>
                </summary>
                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">
                    Сайты работодателей сервис подбирает сам: по карте отрасли и открытым источникам, только официальные сайты компаний.
                    Здесь можно закрепить конкретных работодателей — по одному в строке, можно «Название | сайт».
                </p>
                <div class="mt-2 space-y-2" data-nj642-rows>
                    <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="flex items-center gap-2">
                        <input type="text" name="companies[]" list="nj642-company-hints" data-nj642-row
                               placeholder="<?php echo $i === 0 ? 'Ромашка | romashka.ru' : 'ещё компания'; ?>"
                               class="n2j-input w-full">
                        <?php if ($i > 0): ?>
                        <button type="button" data-nj642-row-del title="Убрать строку" class="n2j-btn-icon-danger shrink-0"><i class="fas fa-xmark"></i></button>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
                <button type="button" data-nj642-row-add class="n2j-link mt-2 inline-flex items-center gap-1.5 text-sm"><i class="fas fa-plus text-[11px]"></i>ещё компания</button>

                <?php if ($hints): ?>
                <datalist id="nj642-company-hints">
                    <?php foreach ($hints as $h): ?>
                    <option value="<?php echo HTML::chars(trim(($h['name'] ?? '').' | '.($h['domain'] ?? ''), ' |')); ?>"><?php echo HTML::chars((string) ($h['from'] ?? '')); ?></option>
                    <?php endforeach; ?>
                </datalist>
                <p class="mt-1.5 text-sm text-gray-400 dark:text-gray-500">Подсказки — из ваших профилей компаний и прошлых сборов; сайт можно вписать вручную.</p>
                <?php endif; ?>
            </details>

            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="notify" value="1" checked class="h-4 w-4 rounded border-gray-300 text-blue-600">
                Присылать письмо о новых подходящих вакансиях
            </label>

            <div>
                <button type="submit" class="n2j-btn-process w-full sm:w-auto sm:justify-start">
                    <i class="fas fa-satellite-dish text-sm"></i>Следить за вакансиями
                </button>
            </div>
        </form>
    </section>

    <!-- 2. Мои мониторы -->
    <?php if ($watches): ?>
    <section class="space-y-3">
        <h2 class="n2j-h3">Мои мониторы <span class="font-normal text-gray-400">(<?php echo count($watches); ?>)</span></h2>

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <?php foreach ($watches as $w):
                $code   = (string) $w['code'];
                $on     = ($w['enabled'] === TRUE OR $w['enabled'] === 1 OR $w['enabled'] === '1' OR $w['enabled'] === 't' OR $w['enabled'] === 'true');
                $l      = $live[$code] ?? NULL;
                $done   = $last[$code] ?? NULL;
            ?>
            <div class="<?php echo $card; ?>" data-nj642-watch="<?php echo HTML::chars($code); ?>">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="/vacancies/<?php echo urlencode($code); ?>" class="text-base font-bold text-gray-900 hover:text-blue-600 hover:underline dark:text-white dark:hover:text-blue-400"><?php echo HTML::chars((string) $w['title']); ?></a>
                    <?php if ( ! $on): ?>
                    <span class="n2j-badge n2j-badge-run">на паузе</span>
                    <?php endif; ?>
                    <?php if ($l): ?>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-bold <?php echo $st_badge[(string) $l['status']] ?? $st_badge['queued']; ?>"><?php echo $st_ru[(string) $l['status']] ?? (string) $l['status']; ?></span>
                    <?php elseif ($done): ?>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-bold <?php echo $st_badge[(string) $done['status']] ?? $st_badge['done']; ?>" title="<?php echo HTML::chars((string) ($done['error'] ?? '')); ?>"><?php echo $st_ru[(string) $done['status']] ?? (string) $done['status']; ?></span>
                    <?php endif; ?>
                    <span class="rounded-full border border-gray-200 px-2.5 py-0.5 text-xs font-semibold text-gray-600 dark:border-gray-700 dark:text-gray-300">
                        вакансий: <?php echo (int) $w['total']; ?><?php if ((int) $w['fresh'] > 0): ?> · новых <?php echo (int) $w['fresh']; ?><?php endif; ?>
                    </span>
                </div>

                <p class="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                    <?php if ((string) $w['city'] !== ''): ?><span class=""><i class="fas fa-location-dot mr-1 text-[11px] text-gray-400"></i><?php echo HTML::chars((string) $w['city']); ?></span><?php endif; ?>
                    <?php if ((string) $w['direction'] !== ''): ?><span><i class="fas fa-briefcase mr-1 text-[11px] text-gray-400"></i><?php echo HTML::chars((string) $w['direction']); ?></span><?php endif; ?>
                    <span class="text-gray-500 dark:text-gray-400">компаний: <?php echo count(Service_Nj642_Vacancies::companies_of($w)); ?></span>
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" data-nj642-run data-code="<?php echo HTML::chars($code); ?>" data-csrf="<?php echo HTML::chars($csrf); ?>"
                            title="Запустить сбор сейчас, не дожидаясь расписания"
                            class="n2j-btn-process n2j-btn-sm">
                        <i class="fas fa-bolt text-[11px]"></i>Собрать
                    </button>
                    <a href="/vacancies/<?php echo urlencode($code); ?>" class="n2j-btn-secondary n2j-btn-sm">Открыть</a>
                </div>

                <div data-nj642-live-host class="mt-3 space-y-3">
                    <template data-n2j-live-tpl="<?php echo HTML::chars($code); ?>">
                        <?php echo View::factory('partials/live_status', [
                            'url'     => '/vacancies/api/status/'.urlencode($code),
                            'title'   => 'Собираем вакансии',
                            'status'  => 'queued',
                            'stage'   => '',
                            'elapsed' => 0,
                            'silent'  => NULL,
                            'note'    => 'Вакансии появятся в ленте ниже — страница обновится сама',
                            'steps'   => [],
                            'stop'    => '',
                        ]); ?>
                    </template>
                    <?php if ($l): ?>
                    <?php echo View::factory('partials/live_status', [
                        'url'     => '/vacancies/api/status/'.urlencode($code),
                        'title'   => 'Собираем вакансии',
                        'status'  => (string) $l['status'],
                        'stage'   => (string) $l['stage'],
                        'elapsed' => (int) $l['elapsed_sec'],
                        'silent'  => $l['silent_sec'],
                        'note'    => 'Вакансии появятся в ленте ниже — страница обновится сама',
                        'steps'   => (array) ($l['stages'] ?? []),
                        'stop'    => '<form method="post" class="inline-flex">'
                            .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                            .'<input type="hidden" name="act" value="stop_run">'
                            .'<input type="hidden" name="code" value="'.HTML::chars($code).'">'
                            .'<button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950/40"><i class="fas fa-stop text-[11px]"></i>Остановить</button>'
                            .'</form>',
                    ]); ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- 3. Лента вакансий -->
    <section class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="n2j-h3">Лента вакансий</h2>
            <div class="flex flex-wrap items-center gap-2" data-nj642-chips>
                <?php foreach ($chips as $key => $chip): ?>
                <a href="<?php echo HTML::chars($nj642_qs(['status' => $key, 'page' => 1])); ?>"
                   class="n2j-chip<?php echo ((string) $f['status'] === $key) ? ' is-on' : ''; ?>">
                    <?php echo HTML::chars($chip[0]); ?> <span class="text-gray-400"><?php echo $chip[1]; ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="get" action="/vacancies" class="flex flex-wrap items-center gap-2" data-nj642-filter>
            <input type="hidden" name="status" value="<?php echo HTML::chars((string) $f['status']); ?>">
            <input type="search" name="q" value="<?php echo HTML::chars((string) $f['q']); ?>" placeholder="Поиск: должность, компания, город"
                   class="n2j-input min-w-[220px] flex-1">
            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-600 dark:text-gray-300">
                Строк:
                <select name="per" data-nj642-per class="n2j-input n2j-select w-auto">
                    <?php foreach ([20, 50, 100] as $n): ?>
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
                <?php if ((int) $f['total'] === 0 AND (string) $f['q'] === '' AND (string) $f['status'] === 'active'): ?>
                Пока пусто. Заведите монитор и нажмите «Собрать» — вакансии появятся здесь; о новых подходящих придёт письмо.
                <?php else: ?>
                Под эти условия ничего не нашлось. <a href="/vacancies" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Сбросить фильтры</a>
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <div class="space-y-3" data-nj642-feed>
            <?php foreach ($items as $v): ?>
            <?php echo View::factory('642/vacancy', ['v' => $v, 'csrf' => $csrf]); ?>
            <?php endforeach; ?>
        </div>

        <?php $pages = max(1, (int) $f['pages']); $page = max(1, (int) $f['page']); ?>
        <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
            <span>Всего: <?php echo (int) $f['total']; ?></span>
            <?php if ($pages > 1): ?>
            <span class="ml-auto flex flex-wrap items-center gap-1.5">
                <?php if ($page > 1): ?>
                <a href="<?php echo HTML::chars($nj642_qs(['page' => $page - 1])); ?>" class="n2j-btn-secondary n2j-btn-sm">Назад</a>
                <?php endif; ?>
                <span class="px-1">стр. <?php echo $page; ?> из <?php echo $pages; ?></span>
                <?php if ($page < $pages): ?>
                <a href="<?php echo HTML::chars($nj642_qs(['page' => $page + 1])); ?>" class="n2j-btn-secondary n2j-btn-sm">Вперёд</a>
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php endif; ?>
</div>

<?php echo View::factory('642/script', ['csrf' => $csrf]); ?>
