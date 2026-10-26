<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj642_vacancies: /vacancies/<code> — ОДИН монитор вакансий.
 *
 * Живой статус-ряд (эталон /trends/<code>), итог последнего сбора, компании
 * монитора и его вакансии с историей.
 */
$w       = (array) (isset($w) ? $w : []);
$items   = isset($items) ? (array) $items : [];
$stats   = isset($stats) ? (array) $stats : ['total' => 0, 'fresh' => 0, 'active' => 0, 'gone' => 0, 'saved' => 0];
$live    = isset($live) ? $live : NULL;
$last    = isset($last) ? $last : NULL;
$feed    = isset($feed) ? (array) $feed : ['status' => 'active', 'q' => '', 'page' => 1, 'per' => 20, 'total' => 0, 'pages' => 1];
$comps   = isset($company_list) ? (array) $company_list : [];
$csrf    = isset($csrf) ? (string) $csrf : '';
$flash   = isset($flash) ? trim((string) $flash) : '';

$code    = (string) ($w['code'] ?? '');
$on      = ($w['enabled'] === TRUE OR $w['enabled'] === 1 OR $w['enabled'] === '1' OR $w['enabled'] === 't' OR $w['enabled'] === 'true');
$notify  = ($w['notify'] === TRUE OR $w['notify'] === 1 OR $w['notify'] === '1' OR $w['notify'] === 't' OR $w['notify'] === 'true');
// Авто-режим (решение сервиса, не «есть направление»): сайты работодателей
// подбирает сам сервис — см. Service_Nj642_Vacancies::is_auto().
$auto    = Service_Nj642_Vacancies::is_auto($w);
$card    = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800';

$nj642_qs = function (array $over) use ($feed) {
    $qs = array_filter(array_merge([
        'status' => (string) $feed['status'],
        'q'      => (string) $feed['q'],
        'per'    => (int) $feed['per'],
        'page'   => (int) $feed['page'],
    ], $over), function ($v) {
        return (string) $v !== '' AND (string) $v !== '0';
    });

    return '/vacancies/'.urlencode((string) ($feed['watch'] ?? '')).($qs ? '?'.http_build_query($qs) : '');
};

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
        <nav class="flex items-center text-sm text-gray-500 dark:text-gray-400">
            <a href="/vacancies" class="hover:text-blue-600 dark:hover:text-blue-400">Вакансии</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-mono font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($code); ?></span>
        </nav>

        <div class="mt-2 flex flex-wrap items-center gap-2.5">
            <h1 class="n2j-h1"><?php echo HTML::chars((string) $w['title']); ?></h1>
            <?php if ((string) $w['city'] !== ''): ?>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-100/90 px-3 py-1 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"><i class="fas fa-location-dot text-[11px]"></i><?php echo HTML::chars((string) $w['city']); ?></span>
            <?php endif; ?>
            <?php if ( ! $on): ?>
            <span class="n2j-badge n2j-badge-run">на паузе</span>
            <?php endif; ?>
            <?php if ($live): ?>
            <span class="rounded-full px-3 py-1 text-xs font-bold <?php echo $st_badge[(string) $live['status']] ?? $st_badge['queued']; ?>"><?php echo $st_ru[(string) $live['status']] ?? (string) $live['status']; ?></span>
            <?php elseif ($last): ?>
            <span class="rounded-full px-3 py-1 text-xs font-bold <?php echo $st_badge[(string) $last['status']] ?? $st_badge['done']; ?>"><?php echo $st_ru[(string) $last['status']] ?? (string) $last['status']; ?></span>
            <?php endif; ?>
            <?php if ($notify): ?>
            <span class="rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-600 dark:border-gray-700 dark:text-gray-300" title="О новых подходящих вакансиях приходит письмо"><i class="fas fa-envelope mr-1 text-[11px] text-gray-400"></i>письмо о новых</span>
            <?php endif; ?>
        </div>

        <p class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
            <?php if ((string) $w['direction'] !== ''): ?><span><i class="fas fa-briefcase mr-1 text-[11px] text-gray-400"></i><?php echo HTML::chars((string) $w['direction']); ?></span><?php endif; ?>
            <span>компаний: <?php echo count($comps); ?></span>
            <span>вакансий: <?php echo (int) $stats['total']; ?><?php if ((int) $stats['fresh'] > 0): ?> · новых <?php echo (int) $stats['fresh']; ?><?php endif; ?></span>
            <?php if ((int) $stats['gone'] > 0): ?><span class="text-gray-500 dark:text-gray-400">закрыто: <?php echo (int) $stats['gone']; ?></span><?php endif; ?>
        </p>
    </header>

    <section class="<?php echo $card; ?>">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" data-nj642-run data-code="<?php echo HTML::chars($code); ?>" data-csrf="<?php echo HTML::chars($csrf); ?>"
                    class="n2j-btn-process">
                <i class="fas fa-bolt text-sm"></i>Собрать сейчас
            </button>

            <form method="post" class="inline-flex">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="toggle">
                <input type="hidden" name="code" value="<?php echo HTML::chars($code); ?>">
                <input type="hidden" name="enabled" value="<?php echo $on ? '0' : '1'; ?>">
                <button type="submit" class="n2j-btn-secondary">
                    <i class="fas <?php echo $on ? 'fa-pause' : 'fa-play'; ?> text-[11px]"></i><?php echo $on ? 'Пауза' : 'Возобновить'; ?>
                </button>
            </form>

            <?php if ($live): ?>
            <form method="post" class="inline-flex">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="stop_run">
                <input type="hidden" name="code" value="<?php echo HTML::chars($code); ?>">
                <button type="submit" class="n2j-btn-danger">
                    <i class="fas fa-stop text-[11px]"></i>Остановить
                </button>
            </form>
            <?php endif; ?>

            <span class="ml-auto"></span>

            <form method="post" class="inline-flex">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="code" value="<?php echo HTML::chars($code); ?>">
                <button data-n2j-confirm="Собранные вакансии останутся в общей истории." data-n2j-title="Удалить монитор?" type="submit" title="Удалить монитор" aria-label="Удалить монитор"
                        class="n2j-btn-icon-danger">
                    <i class="fas fa-trash-can"></i>
                </button>
            </form>
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
                    'note'    => 'Смотрим страницы «Карьера» и «Вакансии» — страница обновится сама',
                    'steps'   => [],
                    'stop'    => '',
                ]); ?>
            </template>
            <?php if ($live): ?>
            <?php echo View::factory('partials/live_status', [
                'url'     => '/vacancies/api/status/'.urlencode($code),
                'title'   => 'Собираем вакансии',
                'status'  => (string) $live['status'],
                'stage'   => (string) $live['stage'],
                'elapsed' => (int) $live['elapsed_sec'],
                'silent'  => $live['silent_sec'],
                'note'    => 'Смотрим страницы «Карьера» и «Вакансии» — страница обновится сама',
                'steps'   => (array) ($live['stages'] ?? []),
            ]); ?>
            <?php elseif ($last): ?>
            <div data-nj642-last>
                <?php echo View::factory('partials/last_status', [
                    'status'      => (string) $last['status'],
                    'finished_at' => (string) ($last['finished_at'] ?? ''),
                    'error'       => (string) ($last['error'] ?? ''),
                    'found'       => Service_Nj642_Vacancies::found_since((int) $w['id'], (string) ($last['started_at'] ?? '')),
                    'found_label' => 'вакансий',
                    'steps'       => (array) ($last['stages'] ?? []),
                    'elapsed'     => (int) ($last['elapsed_sec'] ?? 0),
                ]); ?>
            </div>
            <?php else: ?>
            <p class="text-sm text-gray-500 dark:text-gray-400">Сбор ещё не запускался — нажмите «Собрать сейчас».</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="<?php echo $card; ?>">
        <h2 class="n2j-h3 flex flex-wrap items-center gap-2">
            За кем следим
            <span class="n2j-badge n2j-badge-wait"><?php echo count($comps); ?></span>
        </h2>

        <?php if (empty($comps)): ?>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Сайты работодателей ещё подбираются: монитор ищет компании по городу и направлению сам —
            по карте отрасли и открытым источникам. Нажмите «Собрать сейчас», чтобы не ждать расписания.
        </p>
        <?php else: ?>
        <ul class="mt-2 space-y-1.5">
            <?php foreach ($comps as $c): ?>
            <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-sm">
                <i class="fas fa-building text-[11px] text-gray-400"></i>
                <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $c['name']); ?></span>
                <?php if ((string) $c['domain'] !== ''): ?>
                <a href="https://<?php echo HTML::chars((string) $c['domain']); ?>" target="_blank" rel="noopener nofollow" class="font-mono text-xs text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $c['domain']); ?></a>
                <?php endif; ?>
                <?php if ( ! empty($c['auto'])): ?>
                <span class="n2j-badge n2j-badge-info"
                      title="<?php echo HTML::chars(trim(($c['source'] ?? 'нашлась сама').' · '.($c['why'] ?? '').' · '.($c['found_at'] ?? ''), ' ·')); ?>">
                    нашлась сама<?php if (trim((string) ($c['source'] ?? '')) !== ''): ?>: <?php echo HTML::chars((string) $c['source']); ?><?php endif; ?>
                </span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="n2j-hint mt-2">
            Компании с сайтами сервис подбирает сам<?php if ($auto): ?> — по городу и направлению<?php endif; ?>; список дополняется с каждым сбором.
        </p>
        <?php endif; ?>
    </section>

    <section class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="n2j-h3">Вакансии монитора</h2>
            <div class="flex flex-wrap items-center gap-2">
                <?php
                $statuses = [
                    'active' => 'Активные',
                    'new'    => 'Новые',
                    'saved'  => 'Избранное',
                    'gone'   => 'Закрытые',
                ];
                foreach ($statuses as $key => $label): ?>
                <a href="<?php echo HTML::chars($nj642_qs(['status' => $key, 'page' => 1])); ?>"
                   class="n2j-chip<?php echo ((string) $feed['status'] === $key) ? ' is-on' : ''; ?>">
                    <?php echo HTML::chars($label); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ( ! $items): ?>
        <div class="<?php echo $card; ?>">
            <p class="text-sm text-gray-700 dark:text-gray-200">По этому фильтру вакансий нет. Сбор смотрит страницы «Карьера», «Вакансии» и «О компании» — если вакансий там нет, лента останется пустой.</p>
        </div>
        <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($items as $v): ?>
            <?php echo View::factory('642/vacancy', ['v' => $v, 'csrf' => $csrf]); ?>
            <?php endforeach; ?>
        </div>

        <?php $pages = max(1, (int) $feed['pages']); $page = max(1, (int) $feed['page']); ?>
        <?php if ($pages > 1): ?>
        <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
            <span>Всего: <?php echo (int) $feed['total']; ?></span>
            <span class="ml-auto flex items-center gap-1.5">
                <?php if ($page > 1): ?>
                <a href="<?php echo HTML::chars($nj642_qs(['page' => $page - 1])); ?>" class="n2j-btn-secondary n2j-btn-sm">Назад</a>
                <?php endif; ?>
                <span class="px-1">стр. <?php echo $page; ?> из <?php echo $pages; ?></span>
                <?php if ($page < $pages): ?>
                <a href="<?php echo HTML::chars($nj642_qs(['page' => $page + 1])); ?>" class="n2j-btn-secondary n2j-btn-sm">Вперёд</a>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<?php echo View::factory('642/script', ['csrf' => $csrf]); ?>
