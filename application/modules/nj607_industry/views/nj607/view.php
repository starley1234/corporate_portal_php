<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj607_industry: просмотр отчёта (Markdown-лайт, паттерн nj602 — без библиотек).
 * Эталон N2J: breadcrumbs, h1+бейдж, карточка статуса (неразрывный
 * «спиннер+статус+таймер»), живой прогресс без перезагрузки страницы.
 */
$inline = function ($s) {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('~\*\*(.+?)\*\*~s', '<strong class="font-semibold text-gray-900 dark:text-white">$1</strong>', $s);
    $s = preg_replace('~`([^`]+)`~', '<code class="rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-[0.85em] text-blue-700 dark:bg-gray-900 dark:text-blue-300">$1</code>', $s);

    return $s;
};

$md = function ($src) use ($inline) {
    $html = '';
    $par  = [];
    $ul   = FALSE;
    $flush = function () use (&$par, &$html, $inline) {
        if ($par)
        {
            $html .= '<p class="mt-4 leading-relaxed">'.implode('<br>', array_map($inline, $par)).'</p>';
            $par = [];
        }
    };
    $close_ul = function () use (&$ul, &$html) {
        if ($ul)
        {
            $html .= '</ul>';
            $ul = FALSE;
        }
    };

    foreach (preg_split('~\r?\n~', (string) $src) as $ln)
    {
        $t = trim($ln);

        if ($t === '')
        {
            $flush();
            $close_ul();
            continue;
        }

        if (preg_match('~^###\s+(.+)$~', $t, $m))
        {
            $flush();
            $close_ul();
            $html .= '<h3 class="mt-6 text-base font-bold text-gray-900 sm:text-lg dark:text-white">'.$inline($m[1]).'</h3>';
            continue;
        }

        if (preg_match('~^##\s+(.+)$~', $t, $m))
        {
            $flush();
            $close_ul();
            $html .= '<h2 class="mt-7 border-b border-gray-100 pb-2 text-lg font-bold text-gray-900 sm:text-xl dark:border-gray-700/60 dark:text-white">'.$inline($m[1]).'</h2>';
            continue;
        }

        if (preg_match('~^[-*]\s+(.+)$~', $t, $m))
        {
            $flush();

            if ( ! $ul)
            {
                $html .= '<ul class="mt-3 list-disc space-y-1.5 pl-5">';
                $ul = TRUE;
            }

            $html .= '<li>'.$inline($m[1]).'</li>';
            continue;
        }

        $par[] = $t;
    }

    $flush();
    $close_ul();

    return $html;
};

$status = (string) $r['status'];
$st_class = [
    'new'     => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    'running' => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    'done'    => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    'error'   => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
    'closed'  => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
][$status] ?? 'bg-gray-100 text-gray-500 dark:bg-gray-800';
$st_text = Service_Nj607_Industry::status_label($status);
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ( ! empty($_GET['flash'])): ?>
    <div class="n2j-flash n2j-flash-info mb-3 text-xs block">
        <?php echo HTML::chars((string) $_GET['flash']); ?>
    </div>
    <?php endif; ?>

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/industry" class="transition hover:text-blue-600 dark:hover:text-blue-400">Карта отрасли</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-mono"><?php echo HTML::chars($r['code']); ?></span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-map mr-2 text-blue-500"></i><?php echo HTML::chars((string) $r['industry']); ?><?php echo $r['region'] !== '' ? ' <span class="text-gray-400">·</span> '.HTML::chars($r['region']) : ''; ?>
        </h1>
        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-industry text-[11px]"></i><?php echo $r['kind'] === 'self' ? 'Авто-отчёт' : 'Ручная разведка'; ?>
        </span>
    </div>
    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 sm:text-sm dark:text-gray-400">
        <span class="inline-flex items-center gap-1.5"><i class="fas fa-code-branch text-xs"></i><span class="font-mono"><?php echo HTML::chars($r['code']); ?></span></span>
        <span>•</span>
        <span class="inline-flex items-center gap-1.5"><i class="fas fa-calendar-check text-xs"></i><?php echo HTML::chars(Date::formatted_time((string) $r['created_at'], 'd.m.Y H:i')); ?></span>
        <?php // Модель — только админу (правило N2J: пользователю она ничего не даёт) ?>
        <?php if ($is_admin AND (string) $r['model_used'] !== ''): ?>
        <span>•</span>
        <span class="inline-flex items-center gap-1.5"><i class="fas fa-microchip text-xs"></i><span class="font-mono"><?php echo HTML::chars((string) $r['model_used']); ?></span></span>
        <?php endif; ?>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- Отчёт (2/3) -->
        <div class="md:col-span-2" id="nj607-live">
            <?php if ($status === 'done' AND (string) $r['report_md'] !== ''): ?>
            <article class="n2j-card text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                <?php echo $md($r['report_md']); ?>
            </article>
            <?php elseif ($status === 'error'): ?>
            <div class="rounded-xl border border-rose-200 bg-rose-50/70 p-4 shadow-xs dark:border-rose-800/60 dark:bg-rose-950/30 sm:p-5">
                <div class="flex items-center gap-2 text-rose-800 dark:text-rose-200">
                    <i class="fas fa-triangle-exclamation"></i>
                    <h3 class="text-sm font-bold">Сбор не удался</h3>
                </div>
                <p class="mt-2 text-xs leading-relaxed text-rose-700 dark:text-rose-300"><?php echo HTML::chars((string) $r['error']); ?></p>
                <p class="mt-2 text-xs text-rose-600/80 dark:text-rose-400/80">Попросите администратора перезапустить сбор в <a href="/nj607/admin" class="font-semibold underline">админке карты отрасли</a>.</p>
            </div>
            <?php else: ?>
            <!-- Отчёта ещё нет: заглушка-конвейер, обновляется без перезагрузки -->
            <div class="n2j-card text-center sm:p-10">
                <span class="mx-auto flex items-center justify-center gap-2 text-gray-400">
                    <i class="fas fa-map text-2xl text-blue-500"></i>
                    <i class="fas fa-circle-notch n2j-live-spin text-xl text-blue-400"></i>
                </span>
                <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">Карта отрасли ещё собирается</p>
                <p class="n2j-hint">Воркер ищет производителей в открытых источниках, читает их сайты и сводит отчёт. Обычно 3–7 минут. Страница обновляется сама через AJAX — можно не перезагружать.</p>
            </div>
            <?php endif; ?>

            <?php
            // Сущности онтологии: из отчёта выделены компании, регионы и сегменты.
            // Отдельная страница сущности связывает их с другими модулями.
            $by_kind = [];

            foreach ((array) $entities as $en)
            {
                $by_kind[(string) $en['kind']][] = $en;
            }

            $kind_meta = Service_Nj607_Ontology::kinds();
            $onto      = (array) ($ontology ?? []);
            $can_fix   = ($is_admin OR ! empty($is_owner));
            ?>
            <?php if ($status === 'done'): ?>
            <div class="n2j-card mt-5">
                <div class="flex flex-wrap items-center gap-2.5">
                    <i class="fas fa-diagram-project text-base text-blue-600 dark:text-blue-400"></i>
                    <h2 class="n2j-h3">Онтология отчёта</h2>
                    <span class="n2j-badge n2j-badge-wait">сущностей: <?php echo count($entities); ?></span>
                    <?php if ( ! empty($entities)): ?>
                    <span class="n2j-badge n2j-badge-wait">связей: <?php echo (int) ($onto['links'] ?? 0); ?></span>
                    <?php endif; ?>
                    <?php if ($can_fix AND (string) $r['report_md'] !== ''): ?>
                    <form method="post" class="ml-auto">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
                        <input type="hidden" name="act" value="ontology_resync">
                        <button type="submit" title="Разобрать текст отчёта заново и обновить связи" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-rotate mr-1"></i>Разобрать заново</button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if ( ! empty($by_kind)): ?>
                <p class="n2j-hint">Участники рынка, регионы и сегменты — это ОБЩАЯ онтология модуля: одна сущность на все отчёты, с каждой видны связи с другими сервисами (профиль компании, организация, досье, документы).</p>

                <?php foreach (array_keys($kind_meta) as $k): if (empty($by_kind[$k])) continue; ?>
                <div class="mt-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400"><i class="<?php echo $kind_meta[$k]['icon']; ?> mr-1"></i><?php echo HTML::chars($kind_meta[$k]['label']); ?></p>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        <?php foreach ($by_kind[$k] as $en): ?>
                        <a href="/industry/entity/<?php echo rawurlencode((string) $en['slug']); ?>?kind=<?php echo rawurlencode((string) $en['kind']); ?>"
                           title="Открыть сущность: связи с другими модулями"
                           class="n2j-btn-secondary n2j-btn-sm"><?php echo HTML::chars((string) $en['title']); ?><?php if ((string) $en['domain'] !== ''): ?><span class="font-normal text-gray-400">· <?php echo HTML::chars((string) $en['domain']); ?></span><?php endif; ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <p class="n2j-flash n2j-flash-warn mt-2 text-xs block">
                    <i class="fas fa-circle-info mr-1"></i>Сущности пока не выделены.
                    <?php if ($can_fix AND (string) ($onto['reason'] ?? '') !== ''): ?>
                    <span class="block pt-1"><?php echo HTML::chars((string) $onto['reason']); ?></span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Сайдбар (1/3) -->
        <div class="space-y-5">

            <!-- Карточка статуса: НЕРАЗРЫВНЫЙ ряд «спиннер + статус + прогресс» -->
            <div class="n2j-card" id="nj607-status-card">
                <div class="flex items-center gap-2.5">
                    <?php if ($status === 'running' OR $status === 'new'): ?>
                    <i class="fas fa-circle-notch text-base text-blue-600 dark:text-blue-400"></i>
                    <?php elseif ($status === 'done'): ?>
                    <i class="fas fa-circle-check text-base text-emerald-600 dark:text-emerald-400"></i>
                    <?php elseif ($status === 'error'): ?>
                    <i class="fas fa-triangle-exclamation text-base text-rose-500"></i>
                    <?php else: ?>
                    <i class="fas fa-clock text-base text-gray-400"></i>
                    <?php endif; ?>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">Статус</h3>
                    <span class="rounded-md <?php echo $st_class; ?> px-2 py-0.5 text-xs font-bold" id="nj607-status-chip"><?php echo HTML::chars($st_text); ?></span>
                </div>

                <?php if (in_array($status, ['running', 'new'], TRUE)): ?>
                <?php /* Живой статус-ряд — ОБЩИЙ механизм (эталон /trends/<code>):
                 * спиннер, секундомер от фактического времени, этап воркера,
                 * «нет пульса», остановка. Раньше здесь был самодельный блок,
                 * который через 15 минут молча прекращал опрос («сбор
                 * затянулся») — вместо понятного финала. */ ?>
                <?php echo View::factory('partials/live_status', [
                    'url'     => '/industry/api/status/'.urlencode((string) $r['code']),
                    'title'   => 'Собираем карту отрасли',
                    'status'  => $status === 'new' ? 'queued' : 'running',
                    'stage'   => (string) $r['progress'],
                    'elapsed' => 0,
                    'silent'  => NULL,
                    'steps'   => [],
                    'note'    => 'Отчёт появится здесь — страница обновится сама',
                ]); ?>
                <?php endif; ?>

                <?php if ($status === 'done' AND $r['finished_at']): ?>
                <p class="n2j-hint mt-3">Готово: <?php echo HTML::chars(Date::formatted_time((string) $r['finished_at'], 'd.m.y H:i')); ?></p>
                <?php endif; ?>
            </div>

            <!-- Блок-компаньон: скрыт на мобильных (правило анти-пустоты) -->
            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">Что в отчёте</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Кто на рынке и чем занимается.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Что и как производят, сегменты и цены.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Каналы продаж и возможности входа.</span></li>
                </ul>
            </div>
        </div>
    </div>

    <?php if ($is_admin AND $r['kind'] === 'order'): ?>
    <form method="post" action="/nj607/admin" class="mt-5">
        <input type="hidden" name="csrf" value="<?php echo Security::token(); ?>">
        <input type="hidden" name="act" value="close">
        <input type="hidden" name="code" value="<?php echo HTML::chars($r['code']); ?>">
        <button data-n2j-arm="Закрыть заявку?" type="submit" class="n2j-btn-cta">Закрыть заявку</button>
    </form>
    <?php endif; ?>
</div>

<?php echo View::factory('partials/live_status_js'); ?>
