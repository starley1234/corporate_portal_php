<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: /cad/<code> — одна деталь.
 *
 * Владелец (27.09.2026): «ракурсы не показывай. Страницу сделай ещё компактнее и
 * удобней». Поэтому здесь нет ни ленты ракурсов движка, ни миниатюр в истории:
 * смотреть деталь нужно в 3D (`643/viewer`), а PNG-ракурсы остались только как
 * превью в списках (/cad и /cad/admin).
 *
 * Порядок экрана: шапка с ревизиями и живым рядом → две колонки. Слева 2/3:
 * 3D-просмотр вместе с метриками (габарит, объём, тела, границы), размеры,
 * свёрнутая проверка движка, задание и код. Справа 1/3: файлы, история (любая
 * ревизия открывается кликом), правка словами, действия, расход.
 *
 * Ревизии: ?rev=N показывает файлы, 3D и данные ИМЕННО выбранной ревизии.
 */
$p          = isset($p) ? (array) $p : [];
$models     = isset($models) ? (array) $models : [];
$params     = isset($params) ? (array) $params : [];
$revisions  = isset($revisions) ? (array) $revisions : [];
$usage      = isset($usage) ? (array) $usage : [];
$report     = (isset($report) AND is_array($report)) ? $report : [];
$cmp_rev    = isset($cmp_rev) ? (int) $cmp_rev : 0;
$cmp_row    = (isset($cmp_row) AND is_array($cmp_row)) ? $cmp_row : NULL;
$cmp_models = (isset($cmp_models) AND is_array($cmp_models)) ? $cmp_models : [];
$live       = isset($live) ? $live : NULL;
$last       = isset($last) ? $last : NULL;
$can_edit   = ! empty($can_edit);
$is_admin   = ! empty($is_admin);
$csrf       = isset($csrf) ? (string) $csrf : '';
$flash      = isset($flash) ? trim((string) $flash) : '';

$code        = (string) ($p['code'] ?? '');
$sel_rev     = isset($sel_rev) ? (int) $sel_rev : (int) ($p['revision'] ?? 1);
$sel_row     = isset($sel_row) ? $sel_row : NULL;
$latest_rev  = isset($latest_rev) ? (int) $latest_rev : $sel_rev;
$is_latest   = ! empty($is_latest);

$card  = 'rounded-xl border border-gray-200 bg-white p-3 shadow-xs dark:border-gray-700/80 dark:bg-gray-800';
$link  = 'font-semibold text-blue-600 hover:underline dark:text-blue-400';

$status = (string) ($p['status'] ?? 'queued');
$st_ru = [
    'queued'    => 'в очереди',
    'running'   => 'собирается',
    'done'      => 'готова',
    'failed'    => 'ошибка',
    'cancelled' => 'остановлено',
];
$st_badge = [
    'queued'    => 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    'running'   => 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
    'done'      => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    'failed'    => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
    'cancelled' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
];

// Отчёт движка: габариты, объём, тела, стресс-тест, предупреждения
$geo      = (isset($report['geometry']) AND is_array($report['geometry'])) ? $report['geometry'] : [];
$stress   = (isset($report['stress']) AND is_array($report['stress'])) ? $report['stress'] : [];
$warns    = (isset($report['warnings']) AND is_array($report['warnings'])) ? $report['warnings'] : [];
$engine   = (isset($report['engine']) AND is_array($report['engine'])) ? $report['engine'] : [];
$attempts = (isset($report['attempts']) AND is_array($report['attempts'])) ? $report['attempts'] : [];
$bbox     = (isset($geo['bbox']) AND is_array($geo['bbox'])) ? $geo['bbox'] : [];
$cases    = (isset($stress['cases']) AND is_array($stress['cases'])) ? $stress['cases'] : [];

$case_bad = 0;

foreach ($cases as $c)
{
    if (is_array($c) AND empty($c['ok']))
    {
        $case_bad++;
    }
}

// Воркер проверяет код модели у себя (синтаксис, алгебру, совпадение со схемой) и
// выравнивает схему по коду — что нашёл, показываем прямо здесь: владелец должен
// видеть, где модель ошиблась, а не узнавать об этом только по «сбоям границ».
$code_notes   = (isset($report['code_notes']) AND is_array($report['code_notes'])) ? $report['code_notes'] : [];
$schema_notes = (isset($report['schema_notes']) AND is_array($report['schema_notes'])) ? $report['schema_notes'] : [];

// Размеры, названные в описании, и габарит сборки: если они разошлись, владелец
// должен видеть это на странице, а не мерить деталь мышкой в просмотрщике.
$size_notes   = (isset($report['size_notes']) AND is_array($report['size_notes'])) ? $report['size_notes'] : [];

$fixed = 0;

foreach ($attempts as $a)
{
    if (is_array($a) AND ! empty($a['fix_prompt']))
    {
        $fixed++;
    }
}

// Число для человека: 60 → «60», 60,5 → «60,5», «—» для пустого.
// Нули режем ТОЛЬКО у дробной части — у целых «10» хвостовой ноль не трогаем.
$fmt = function ($v) {
    if ($v === NULL OR $v === '')
    {
        return '—';
    }

    $n = (float) $v;
    $s = (abs($n - round($n)) < 0.005)
        ? number_format($n, 0, ',', ' ')
        : number_format($n, 2, ',', ' ');

    if (strpos($s, ',') !== FALSE)
    {
        $s = rtrim(rtrim($s, '0'), ',');
    }

    return $s;
};

// Метрики идут прямо под холст (одним взглядом: что за деталь по размерам).
$dims = ($bbox
    ? $fmt($bbox[0] ?? NULL).' × '.$fmt($bbox[1] ?? NULL).' × '.$fmt($bbox[2] ?? NULL).' мм'
    : '—');

$metrics = [
    ['Габарит', $dims],
    ['Объём', $fmt($geo['volume'] ?? NULL).' мм³'],
    ['Тел', (string) (int) ($geo['solids'] ?? 0)],
    ['Границы размеров', $cases ? ($case_bad === 0 ? 'все ок ('.count($cases).')' : 'сбоев: '.$case_bad) : 'не проверялись'],
];

// Проверку движка показываем только когда есть о чём сказать: на удачной детали
// она молчит (иначе страница пухла от пустых карточек — владелец просил компактнее).
$check_worthy = ($warns OR $case_bad > 0 OR count($attempts) > 1 OR $fixed > 0
    OR $code_notes OR $schema_notes OR $size_notes);

// Ссылка «сравнить ревизии»: ?rev=N&cmp=M — что изменилось между двумя ревизиями.
$rev_cmp_url = function ($rev, $cmp) use ($code) {
    return '/cad/'.urlencode($code).'?rev='.(int) $rev.'&cmp='.(int) $cmp;
};

// Ссылка «показать ревизию»: последняя — без параметра, остальные ?rev=N
$rev_url = function ($rev) use ($code, $latest_rev) {
    return (int) $rev === (int) $latest_rev
        ? '/cad/'.urlencode($code)
        : '/cad/'.urlencode($code).'?rev='.(int) $rev;
};

// Метрики ревизии для сравнения: всё из её отчёта (report пишет воркер),
// плюс число файлов модели — чтобы «что изменилось» отвечало без ИИ.
$cmp_metrics = function ($row) use ($fmt) {
    $rep = (isset($row['report']) AND is_array($row['report']))
        ? $row['report'] : json_decode((string) ($row['report'] ?? ''), TRUE);
    $rep = is_array($rep) ? $rep : [];

    $geo   = (isset($rep['geometry']) AND is_array($rep['geometry'])) ? $rep['geometry'] : [];
    $eng   = (isset($rep['engine']) AND is_array($rep['engine'])) ? $rep['engine'] : [];
    $cases = (isset($rep['stress']['cases']) AND is_array($rep['stress']['cases'])) ? $rep['stress']['cases'] : [];
    $warns = (isset($rep['warnings']) AND is_array($rep['warnings'])) ? $rep['warnings'] : [];
    $bb    = (isset($geo['bbox']) AND is_array($geo['bbox'])) ? $geo['bbox'] : [];
    $bad   = 0;

    foreach ($cases as $c)
    {
        if (is_array($c) AND empty($c['ok']))
        {
            $bad++;
        }
    }

    return [
        'dims'   => $bb ? $fmt($bb[0] ?? NULL).' × '.$fmt($bb[1] ?? NULL).' × '.$fmt($bb[2] ?? NULL) : '—',
        'volume' => isset($geo['volume']) ? (float) $geo['volume'] : NULL,
        'solids' => isset($geo['solids']) ? (int) $geo['solids'] : NULL,
        'warns'  => count($warns),
        'bad'    => $bad,
        'ms'     => isset($eng['duration_ms']) ? (int) $eng['duration_ms'] : NULL,
    ];
};

// Прирост/убыль числом («+5», «−3») — молчим, когда не изменилось.
$cmp_delta = function ($a, $b, $unit = '') use ($fmt) {
    if ($a === NULL OR $b === NULL)
    {
        return ['', ''];
    }

    $d = $b - $a;

    if (abs($d) < 1e-9)
    {
        return ['', ''];
    }

    $txt = ($d > 0 ? '+' : '−').$fmt(abs($d)).($unit !== '' ? ' '.$unit : '');
    $pct = ($a != 0) ? ' ('.($d > 0 ? '+' : '−').$fmt(abs(round($d / abs($a) * 100, 1))).' %)' : '';

    return [$txt.$pct, $d > 0 ? 'up' : 'down'];
};

// Размер файла по-человечески: «12 КБ», «1,2 МБ».
$fsize = function ($b) {
    $b = (int) $b;

    return $b >= 1048576
        ? number_format($b / 1048576, 1, ',', ' ').' МБ'
        : max(1, (int) round($b / 1024)).' КБ';
};

$act_ru = ['generate' => 'сборка по описанию', 'revise' => 'правка словами', 'rebuild' => 'пересборка по параметрам'];
$sel_status = $sel_row ? (string) $sel_row['status'] : $status;
?>
<div class="n2j-ds w-full space-y-3 px-3.5 pt-3.5 pb-14 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <header>
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/cad" class="transition hover:text-blue-600 dark:hover:text-blue-400">CAD-детали</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-semibold text-gray-700 dark:text-gray-300"><?php echo HTML::chars(mb_substr((string) $p['title'], 0, 60)); ?></span>
        </nav>

        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
            <h1 class="n2j-h1"><?php echo HTML::chars((string) $p['title']); ?></h1>
            <span class="rounded-full px-2.5 py-0.5 text-xs font-bold <?php echo $st_badge[$status] ?? $st_badge['queued']; ?>"><?php echo HTML::chars($st_ru[$status] ?? $status); ?></span>
            <button type="button" data-nj643-copy="<?php echo HTML::chars('/cad/'.$code); ?>" class="rounded-md bg-gray-100 px-2 py-0.5 font-mono text-[13px] text-gray-600 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300" title="Скопировать ссылку на деталь">/cad/<?php echo HTML::chars($code); ?></button>
            <span class="text-sm text-gray-400">создана <?php echo HTML::chars((string) $p['created_at']); ?></span>
            <button type="button" data-nj643-print title="Печать карточки детали (или «Сохранить в PDF»)"
                    class="n2j-btn-secondary n2j-btn-sm">
                <i class="fas fa-print text-[11px]"></i> Печать
            </button>

            <?php if ($revisions): ?>
            <div class="flex flex-wrap items-center gap-1.5 md:ml-2">
                <span class="text-sm text-gray-400 dark:text-gray-500">Ревизии:</span>
                <?php foreach ($revisions as $r): $rv = (int) $r['rev']; ?>
                <a href="<?php echo HTML::chars($rev_url($rv)); ?>"
                   class="n2j-chip<?php echo $rv === $sel_rev ? ' is-on' : ''; ?>"
                   title="<?php echo HTML::chars($act_ru[(string) $r['action']] ?? (string) $r['action']); ?><?php echo (int) $r['files_n'] > 0 ? ', файлов: '.(int) $r['files_n'] : ''; ?>">
                    <?php echo $rv; ?><?php if ((int) $r['files_n'] > 0): ?><span class="text-gray-400"> · <?php echo (int) $r['files_n']; ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
                <?php if ( ! $is_latest): ?>
                <a href="<?php echo HTML::chars($rev_url($latest_rev)); ?>" class="text-sm <?php echo $link; ?>">к последней (<?php echo $latest_rev; ?>)</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($is_admin): ?>
            <a href="/cad/admin" class="n2j-btn-secondary n2j-btn-sm ml-auto">
                <i class="fas fa-lock text-[11px]"></i> Управление
            </a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Живой ряд сборки / плашка итога -->
    <div data-nj643-live-host>
        <?php if ($live): ?>
        <?php echo View::factory('partials/live_status', [
            'url'     => '/cad/api/status/'.urlencode($code),
            'title'   => 'Собираю деталь',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) ($live['stages'] ?? []),
            'note'    => 'Файлы и просмотр появятся здесь — страница обновится сама',
            'stop'    => $can_edit
                ? '<form method="post" class="shrink-0">'
                    .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                    .'<input type="hidden" name="act" value="stop_run">'
                    .'<button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950/40"><i class="fas fa-stop text-[11px]"></i>Остановить</button>'
                    .'</form>'
                : '',
        ]); ?>
        <?php else: ?>
        <template data-n2j-live-tpl="<?php echo HTML::chars($code); ?>">
            <?php echo View::factory('partials/live_status', [
                'url'     => '/cad/api/status/'.urlencode($code),
                'title'   => 'Собираю деталь',
                'status'  => 'queued',
                'stage'   => '',
                'elapsed' => 0,
                'silent'  => NULL,
                'steps'   => [],
                'note'    => 'Файлы и просмотр появятся здесь — страница обновится сама',
                'stop'    => '',
            ]); ?>
        </template>
        <?php endif; ?>

        <?php if ($last AND ! $live): ?>
        <?php echo View::factory('partials/last_status', [
            'status'      => (string) $last['status'],
            'finished_at' => (string) $last['finished_at'],
            'error'       => (string) $last['error'],
            'found'       => (int) ($p['files_count'] ?? 0),
            'found_label' => 'файлов',
            'steps'       => (array) ($last['stages'] ?? []),
            'elapsed'     => (int) $last['elapsed_sec'],
        ]); ?>
        <?php endif; ?>
    </div>

    <?php if ($status === 'failed' AND trim((string) $p['error']) !== ''): ?>
    <div class="rounded-xl border border-rose-200 bg-rose-50/70 p-3 dark:border-rose-800 dark:bg-rose-950/30">
        <p class="text-sm font-bold text-rose-700 dark:text-rose-300"><i class="fas fa-triangle-exclamation mr-1.5"></i>Модель не собралась</p>
        <p class="mt-1 text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo nl2br(HTML::chars((string) $p['error'])); ?></p>
        <?php if ($can_edit): ?>
        <form method="post" class="mt-2 inline-flex" data-nj643-ajax="again">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <input type="hidden" name="act" value="again">
            <button type="submit" class="n2j-btn-process n2j-btn-sm"><i class="fas fa-rotate text-[11px]"></i>Попробовать снова</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($sel_row AND ! $is_latest): ?>
    <!-- Что за ревизия показана: иначе «смотрю ревизию 1» читается как поломка -->
    <div class="rounded-xl border border-amber-200 bg-amber-50/70 px-3.5 py-2 text-sm text-gray-700 dark:border-amber-800 dark:bg-amber-950/20 dark:text-gray-200">
        Показана <span class="font-bold">ревизия <?php echo $sel_rev; ?></span> — <?php echo HTML::chars($act_ru[(string) $sel_row['action']] ?? (string) $sel_row['action']); ?>,
        <?php echo HTML::chars((string) $sel_row['created_at']); ?>:
        <span class="font-semibold <?php echo $sel_status === 'done' ? 'text-emerald-600 dark:text-emerald-400' : ($sel_status === 'failed' ? 'text-rose-600 dark:text-rose-400' : ''); ?>"><?php echo HTML::chars($st_ru[$sel_status] ?? $sel_status); ?></span><?php echo trim((string) $sel_row['instruction']) !== '' ? ' · «'.HTML::chars(mb_substr((string) $sel_row['instruction'], 0, 90)).'»' : ''; ?>.
        <a href="<?php echo HTML::chars($rev_url($latest_rev)); ?>" class="<?php echo $link; ?>">Вернуться к последней</a>
    </div>
    <?php endif; ?>

    <div class="gap-3 space-y-3 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- Левая колонка: просмотр с метриками, размеры, проверка, задание -->
        <div class="space-y-3 md:col-span-2">

            <!-- 3D (ракурсы движка на странице не показываем — владелец, 27.09) -->
            <?php echo View::factory('643/viewer', [
                'models'  => $models,
                'code'    => $code,
                'rev'     => $sel_rev,
                'card'    => $card,
                'metrics' => $metrics,
            ]); ?>

            <?php
            // Масса: объём деталь уже знает, плотность выбирает человек. Перед
            // печатью и заказом это первый вопрос, а считать объём × плотность
            // в голове никто не хочет. Плотности — г/см³.
            $mats = [
                'Пластик PLA'      => 1.24,
                'Пластик PETG'     => 1.27,
                'Пластик ABS'      => 1.04,
                'Нейлон PA12'      => 1.14,
                'Алюминий'         => 2.70,
                'Сталь'            => 7.85,
                'Латунь'           => 8.50,
            ];
            $vol = isset($geo['volume']) ? (float) $geo['volume'] : 0;
            ?>
            <?php if ($cmp_row): ?>
            <?php
            $m_from = $cmp_metrics($cmp_row);
            $m_to   = $cmp_metrics($sel_row ?: $p);
            $rows   = [
                ['Габарит, мм', $m_from['dims'], $m_to['dims'], '', ''],
            ];
            $dd = $cmp_delta($m_from['volume'], $m_to['volume'], 'мм³');
            $rows[] = ['Объём, мм³', $fmt($m_from['volume']), $fmt($m_to['volume']), $dd[0], $dd[1]];
            $dd = $cmp_delta($m_from['solids'], $m_to['solids']);
            $rows[] = ['Тел', $m_from['solids'] !== NULL ? (string) $m_from['solids'] : '—', $m_to['solids'] !== NULL ? (string) $m_to['solids'] : '—', $dd[0], $dd[1]];
            $rows[] = ['Файлов модели', (string) count($cmp_models), (string) count($models), '', ''];
            $dd = $cmp_delta($m_from['bad'], $m_to['bad']);
            $rows[] = ['Сбоев границ', (string) $m_from['bad'], (string) $m_to['bad'], $dd[0], $dd[1]];
            $dd = $cmp_delta($m_from['warns'], $m_to['warns']);
            $rows[] = ['Предупреждений', (string) $m_from['warns'], (string) $m_to['warns'], $dd[0], $dd[1]];
            $dd = $cmp_delta($m_from['ms'], $m_to['ms'], 'с');
            $rows[] = ['Сборка', $m_from['ms'] !== NULL ? round($m_from['ms'] / 1000, 1).' с' : '—', $m_to['ms'] !== NULL ? round($m_to['ms'] / 1000, 1).' с' : '—', $dd[0], $dd[1]];

            $code_a = (string) ($cmp_row['code_py'] ?? '');
            $code_b = (string) ($sel_row['code_py'] ?? '');
            ?>
            <section class="<?php echo $card; ?>" data-nj643-compare>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="n2j-h3">Что изменилось</h2>
                    <span class="rounded-md bg-gray-100 px-2 py-0.5 text-sm font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">ревизия <?php echo (int) $cmp_rev; ?> → <?php echo (int) $sel_rev; ?></span>
                    <a href="<?php echo HTML::chars($rev_url($sel_rev)); ?>" class="<?php echo $link; ?> ml-auto text-sm">убрать сравнение</a>
                </div>

                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">
                    Ревизия <?php echo (int) $cmp_rev; ?>:
                    «<?php echo HTML::chars(mb_substr(trim((string) ($cmp_row['instruction'] ?? '')), 0, 120) ?: ($act_ru[(string) $cmp_row['action']] ?? (string) $cmp_row['action'])); ?>» →
                    ревизия <?php echo (int) $sel_rev; ?>:
                    «<?php echo HTML::chars(mb_substr(trim((string) ($sel_row['instruction'] ?? '')), 0, 120) ?: ($act_ru[(string) ($sel_row['action'] ?? '')] ?? (string) ($sel_row['action'] ?? ''))); ?>»
                </p>

                <div class="mt-2 overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
                    <table class="n2j-table">
                        <thead>
                            <tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
                                <th class="py-1.5 pr-3">Показатель</th>
                                <th class="py-1.5 px-3">Ревизия <?php echo (int) $cmp_rev; ?></th>
                                <th class="py-1.5 px-3">Ревизия <?php echo (int) $sel_rev; ?></th>
                                <th class="py-1.5 pl-3">Изменилось</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                            <tr class="border-b border-gray-50 dark:border-gray-700/40">
                                <td class="py-1.5 pr-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($row[0]); ?></td>
                                <td class="py-1.5 px-3 text-gray-600 dark:text-gray-300"><?php echo HTML::chars($row[1]); ?></td>
                                <td class="py-1.5 px-3 font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($row[2]); ?></td>
                                <td class="py-1.5 pl-3 font-semibold <?php echo $row[4] === 'up' ? 'text-amber-600 dark:text-amber-400' : ($row[4] === 'down' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-300 dark:text-gray-600'); ?>"><?php echo $row[3] !== '' ? HTML::chars($row[3]) : '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (trim($code_a) !== '' AND trim($code_b) !== ''): ?>
                <details class="mt-2 border-t border-gray-100 pt-1.5 dark:border-gray-700/60">
                    <summary class="cursor-pointer text-sm font-bold text-gray-900 dark:text-white">Код модели: чем отличается</summary>
                    <div data-nj643-diff class="mt-1.5">
                        <pre id="nj643-diff-a" class="hidden"><?php echo HTML::chars($code_a); ?></pre>
                        <pre id="nj643-diff-b" class="hidden"><?php echo HTML::chars($code_b); ?></pre>
                        <div data-nj643-diff-out class="max-h-80 overflow-auto rounded-lg bg-gray-900 p-3 font-mono text-[13px] leading-relaxed text-gray-100">Считаю отличия…</div>
                    </div>
                </details>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($vol > 0): ?>
            <section class="<?php echo $card; ?>" data-nj643-mass data-volume="<?php echo $vol; ?>">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="n2j-h3">Материал и масса</h2>
                    <span class="text-sm text-gray-400">объём детали <?php echo number_format($vol, 0, ',', ' '); ?> мм³</span>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                        Материал
                        <select data-nj643-material class="n2j-input n2j-select cursor-pointer py-1.5 text-base min-h-0 w-auto">
                            <?php foreach ($mats as $mname => $mdens): ?>
                            <option value="<?php echo (float) $mdens; ?>"><?php echo HTML::chars($mname); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="text-sm text-gray-700 dark:text-gray-200">
                        Масса: <span class="font-bold text-gray-900 dark:text-white" data-nj643-mass-out>—</span>
                    </p>
                </div>
                <p class="mt-1.5 text-sm text-gray-400 dark:text-gray-500">Прикидка по объёму и плотности материала — для выбора печати, литья или металла.</p>
            </section>
            <?php endif; ?>

            <!-- Размеры (актуальны только для последней ревизии: код в детали) -->
            <?php if ($params): ?>
            <section class="<?php echo $card; ?>" data-nj643-params data-code="<?php echo HTML::chars($code); ?>" data-csrf="<?php echo HTML::chars($csrf); ?>">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="n2j-h3">Размеры</h2>
                    <span class="text-sm text-gray-400">крутите и пересобирайте — код модели тот же</span>
                </div>

                <form method="post" class="mt-2 grid gap-x-3 gap-y-2 sm:grid-cols-2" data-nj643-rebuild>
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="rebuild">

                    <?php
                    // Ползунок — чтобы «поискать на глаз», поле справа — чтобы задать
                    // ровно 120 мм: ползунком точное значение не выставить (шаг 0.5–1).
                    // Оба поля синхронизирует script.php, data-default — значения,
                    // которые написал ИИ (кнопка «вернуть как собрал ИИ»).
                    $pmax = function ($item) {
                        return (float) ($item['max'] !== NULL ? $item['max'] : ($item['value'] > 0 ? $item['value'] * 2 : 100));
                    };
                    ?>
                    <?php foreach ($params as $item): ?>
                    <div>
                        <label class="flex items-baseline justify-between gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                            <span><?php echo HTML::chars((string) $item['label']); ?></span>
                            <span class="inline-flex items-baseline gap-1">
                                <input type="number" inputmode="decimal"
                                       data-nj643-num="<?php echo HTML::chars($item['name']); ?>"
                                       min="<?php echo (float) ($item['min'] !== NULL ? $item['min'] : 0); ?>"
                                       max="<?php echo $pmax($item); ?>"
                                       step="<?php echo (float) $item['step']; ?>"
                                       value="<?php echo (float) $item['value']; ?>"
                                       <?php echo $can_edit ? '' : 'readonly'; ?>
                                       class="n2j-input w-20 py-0.5 text-right font-mono text-sm font-bold min-h-0">
                                <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $item['unit']); ?></span>
                            </span>
                        </label>
                        <input type="range" name="params[<?php echo HTML::chars($item['name']); ?>]"
                               data-nj643-range="<?php echo HTML::chars($item['name']); ?>"
                               data-unit="<?php echo HTML::chars((string) $item['unit']); ?>"
                               data-default="<?php echo (float) $item['default']; ?>"
                               min="<?php echo (float) ($item['min'] !== NULL ? $item['min'] : 0); ?>"
                               max="<?php echo $pmax($item); ?>"
                               step="<?php echo (float) $item['step']; ?>"
                               value="<?php echo (float) $item['value']; ?>"
                               class="mt-0.5 h-2 w-full cursor-pointer appearance-none rounded-full bg-gray-200 accent-blue-600 dark:bg-gray-700">
                        <div class="flex justify-between text-sm text-gray-400">
                            <span><?php echo (float) ($item['min'] !== NULL ? $item['min'] : 0); ?></span>
                            <span><?php echo (float) ($item['max'] !== NULL ? $item['max'] : '—'); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="sm:col-span-2 flex flex-wrap items-center gap-2">
                        <?php if ($can_edit): ?>
                        <button type="submit" class="n2j-btn-process n2j-btn-sm">
                            <i class="fas fa-rotate text-[11px]"></i>Пересобрать с этими размерами
                        </button>
                        <button type="button" data-nj643-reset title="Вернуть размеры, которые написал ИИ"
                                class="n2j-btn-secondary n2j-btn-sm">
                            <i class="fas fa-arrow-rotate-left text-[11px]"></i>Вернуть размеры ИИ
                        </button>
                        <?php else: ?>
                        <p class="text-sm text-gray-400">Менять размеры может владелец детали.</p>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
            <?php endif; ?>

            <!-- Проверка движка: показываем только при замечаниях (иначе молчит) -->
            <?php if ($check_worthy): ?>
            <section class="<?php echo $card; ?>">
                <details <?php echo $case_bad > 0 ? 'open' : ''; ?>>
                    <summary class="cursor-pointer text-base font-bold text-gray-900 dark:text-white">
                        Замечания проверки
                        <span class="ml-1 font-normal text-gray-400"><?php
                            $bits = [];
                            if ($case_bad > 0) { $bits[] = 'границы размеров: '.$case_bad.' сбоев'; }
                            if ($warns) { $bits[] = 'предупреждений: '.count($warns); }
                            if (count($attempts) > 1) { $bits[] = 'попыток сборки: '.count($attempts); }
                            echo HTML::chars(implode(' · ', $bits) ?: 'всё в порядке');
                        ?></span>
                    </summary>

                    <?php if ($case_bad > 0): ?>
                    <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50/70 p-2.5 dark:border-amber-800 dark:bg-amber-950/20">
                        <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">
                            <?php echo $case_bad; ?> крайних значений параметров ломают модель — при печати/заказе это может выстрелить.
                        </p>
                        <ul class="mt-1 space-y-0.5 text-sm text-gray-600 dark:text-gray-300">
                            <?php $shown_c = 0; foreach ($cases as $c): if ( ! is_array($c) OR ! empty($c['ok'])) continue; if ($shown_c++ >= 3) break; ?>
                            <li>· <?php echo HTML::chars(json_encode($c['params'] ?? [], JSON_UNESCAPED_UNICODE)); ?> → <?php echo HTML::chars(mb_substr((string) ($c['error'] ?? 'ошибка'), 0, 140)); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($can_edit): ?>
                        <form method="post" class="mt-2 inline-flex" data-nj643-ajax="fix_limits">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="act" value="fix_limits">
                            <button type="submit" class="n2j-btn-process n2j-btn-sm">
                                <i class="fas fa-screwdriver-wrench text-[11px]"></i>Попросить ИИ починить границы
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($warns): ?>
                    <ul class="mt-2 space-y-0.5 text-sm text-gray-600 dark:text-gray-300">
                        <?php foreach (array_slice($warns, 0, 5) as $w): ?>
                        <li>· <?php echo HTML::chars(is_array($w) ? (string) ($w['message'] ?? json_encode($w, JSON_UNESCAPED_UNICODE)) : (string) $w); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if ($size_notes): ?>
                    <p class="mt-2 text-sm font-semibold text-amber-700 dark:text-amber-400">Размеры из описания не совпали с габаритом</p>
                    <ul class="mt-0.5 space-y-0.5 text-sm text-gray-500 dark:text-gray-400">
                        <?php foreach (array_slice($size_notes, 0, 3) as $n): ?>
                        <li>· <?php echo HTML::chars(is_array($n) ? json_encode($n, JSON_UNESCAPED_UNICODE) : (string) $n); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if ($code_notes): ?>
                    <p class="mt-2 text-sm font-semibold text-gray-600 dark:text-gray-300">Замечания к коду модели</p>
                    <ul class="mt-0.5 space-y-0.5 text-sm text-gray-500 dark:text-gray-400">
                        <?php foreach (array_slice($code_notes, 0, 5) as $n): ?>
                        <li>· <?php echo HTML::chars(is_array($n) ? json_encode($n, JSON_UNESCAPED_UNICODE) : (string) $n); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if ($schema_notes): ?>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Схему поправили по коду: <?php echo HTML::chars(implode('; ', array_slice($schema_notes, 0, 4))); ?>.
                    </p>
                    <?php endif; ?>

                    <p class="mt-2 text-sm text-gray-400 dark:text-gray-500">
                        Попыток сборки: <?php echo max(1, count($attempts)); ?><?php echo $fixed > 0 ? ', из них с правкой кода ИИ: '.$fixed : ''; ?><?php
                        echo ($engine AND isset($engine['version'])) ? ' · внешний CAD-движок версии '.HTML::chars((string) $engine['version']) : '';
                        echo ($engine AND isset($engine['duration_ms'])) ? ', сборка '.round((int) $engine['duration_ms'] / 1000, 1).' с' : '';
                        ?>.
                    </p>
                </details>
            </section>
            <?php endif; ?>

            <!-- Задание и код модели: одним блоком, код свёрнут -->
            <section class="<?php echo $card; ?>">
                <details <?php echo ( ! $params AND ! $models) ? 'open' : ''; ?>>
                    <summary class="cursor-pointer text-base font-bold text-gray-900 dark:text-white">Что просили</summary>
                    <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $p['prompt']); ?></p>

                    <?php
                    // Модель пишет summary/notes/checks, и мы за них платим токенами —
                    // значит их надо показывать, а не держать в отчёте мёртвым грузом
                    // (владелец спросил, куда уходят токены на короткий код).
                    $sum  = trim((string) ($report['summary'] ?? ''));
                    $n_notes  = (isset($report['notes']) AND is_array($report['notes'])) ? $report['notes'] : [];
                    $n_checks = (isset($report['checks']) AND is_array($report['checks'])) ? $report['checks'] : [];
                    ?>
                    <?php if ($sum !== ''): ?>
                    <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">Как задумано: <?php echo HTML::chars($sum); ?></p>
                    <?php endif; ?>

                    <?php if ($n_notes): ?>
                    <p class="mt-2 text-sm font-semibold text-gray-600 dark:text-gray-300">Что учтено при построении</p>
                    <ul class="mt-0.5 space-y-0.5 text-sm text-gray-500 dark:text-gray-400">
                        <?php foreach (array_slice($n_notes, 0, 4) as $n): ?>
                        <li>· <?php echo HTML::chars(is_array($n) ? json_encode($n, JSON_UNESCAPED_UNICODE) : (string) $n); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if ($n_checks): ?>
                    <p class="mt-2 text-sm font-semibold text-gray-600 dark:text-gray-300">Проверить перед печатью или заказом</p>
                    <ul class="mt-0.5 space-y-0.5 text-sm text-gray-500 dark:text-gray-400">
                        <?php foreach (array_slice($n_checks, 0, 4) as $n): ?>
                        <li>· <?php echo HTML::chars(is_array($n) ? json_encode($n, JSON_UNESCAPED_UNICODE) : (string) $n); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </details>

                <?php if (trim((string) $p['code_py']) !== ''): ?>
                <details class="mt-1.5 border-t border-gray-100 pt-1.5 dark:border-gray-700/60">
                    <summary class="cursor-pointer text-sm font-bold text-gray-900 dark:text-white">Код модели (build123d)</summary>
                    <div class="mt-1.5 flex items-center gap-2">
                        <button type="button" data-nj643-copy-text="nj643-code" class="n2j-btn-secondary n2j-btn-sm">
                            <i class="fas fa-copy text-[11px]"></i>Скопировать
                        </button>
                        <span class="text-sm text-gray-400">этот код можно править вручную и запускать у себя</span>
                    </div>
                    <pre id="nj643-code" class="mt-1.5 max-h-72 overflow-auto rounded-lg bg-gray-900 p-3 text-[13px] leading-relaxed text-gray-100"><?php echo HTML::chars((string) $p['code_py']); ?></pre>
                </details>
                <?php endif; ?>
            </section>
        </div>

        <!-- Правая колонка: файлы, история, правка, действия -->
        <div class="space-y-3">

            <!-- Файлы выбранной ревизии -->
            <section class="<?php echo $card; ?>">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="n2j-h3">Файлы</h2>
                    <span class="rounded-md bg-gray-100 px-2 py-0.5 text-sm font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">ревизия <?php echo $sel_rev; ?></span>
                </div>

                <?php if ( ! $models): ?>
                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">
                    <?php if ($is_latest): ?>
                    Файлов пока нет — они появятся после успешной сборки.
                    <?php else: ?>
                    У этой ревизии файлов нет (сборка не удалась или их ещё не собирали).
                    <?php endif; ?>
                </p>
                <?php else: ?>
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    <?php foreach ($models as $f): ?>
                    <a href="/cad/file/<?php echo (int) $f['id']; ?>?download=1"
                       class="n2j-btn-secondary n2j-btn-sm"
                       title="Скачать <?php echo HTML::chars(strtoupper((string) $f['kind'])); ?> за ревизию <?php echo (int) $sel_rev; ?>">
                        <?php echo strtoupper(HTML::chars((string) $f['kind'])); ?>
                        <span class="font-normal text-gray-400"><?php echo HTML::chars($fsize((int) $f['size'])); ?></span>
                        <i class="fas fa-download text-[10px] text-gray-400"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
                <p class="mt-1.5 text-sm text-gray-400 dark:text-gray-500">STL — печать, STEP — CAD и заказ, GLB — просмотр.</p>

                <?php
                // Скачать деталь одним архивом (владелец, 28.09.2026): «Когда я качаю
                // деталь, то нифига не понятно». В архиве — файлы ревизии, код модели
                // и README с габаритом, размерами и объяснением, зачем каждый файл.
                ?>
                <a href="/cad/archive/<?php echo HTML::chars($code); ?><?php echo $is_latest ? '' : '?rev='.(int) $sel_rev; ?>"
                   class="n2j-btn-process mt-2 w-full"
                   title="Скачать одним архивом: <?php echo strtoupper(HTML::chars(implode(', ', array_map(function ($f) { return (string) $f['kind']; }, $models)))); ?>, код модели и README.txt">
                    <i class="fas fa-file-zipper text-[12px]"></i>Скачать архивом
                </a>
                <p class="mt-1.5 text-sm text-gray-400 dark:text-gray-500">
                    В архиве файлы этой ревизии, код модели и README с размерами — этого хватает,
                    чтобы отдать деталь подрядчику без ссылки на сайт.
                </p>

                <?php endif; ?>
            </section>

            <!-- История: любая ревизия открывается кликом (миниатюр нет) -->
            <?php if ($revisions): ?>
            <section class="<?php echo $card; ?>">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="n2j-h3">История</h2>
                    <span class="text-sm text-gray-400">всего: <?php echo count($revisions); ?></span>
                    <?php if ($can_edit AND count($revisions) > 1): ?>
                    <span class="text-sm text-gray-400">· удалить можно любую, кроме последней</span>
                    <?php endif; ?>
                </div>
                <ul class="mt-1 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($revisions as $r):
                        $rs = (string) $r['status'];
                        $rv = (int) $r['rev'];
                    ?>
                    <li class="py-1.5">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
                            <a href="<?php echo HTML::chars($rev_url($rv)); ?>" class="font-semibold <?php echo $rv === $sel_rev ? 'text-blue-600 dark:text-blue-400' : 'text-gray-800 hover:text-blue-600 dark:text-gray-200 dark:hover:text-blue-400'; ?>">
                                <?php echo $rv === $sel_rev ? '▸ ' : ''; ?>ревизия <?php echo $rv; ?>
                            </a>
                            <span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($act_ru[(string) $r['action']] ?? (string) $r['action']); ?></span>
                            <span class="rounded-full px-2 py-0.5 text-xs font-bold <?php echo $st_badge[$rs] ?? $st_badge['queued']; ?>"><?php echo HTML::chars($st_ru[$rs] ?? $rs); ?></span>
                            <?php if ((int) $r['files_n'] > 0): ?>
                            <span class="text-gray-400">файлов: <?php echo (int) $r['files_n']; ?></span>
                            <?php endif; ?>
                            <span class="ml-auto flex items-center gap-1">
                                <?php if ($rv > 1): ?>
                                <a href="<?php echo HTML::chars($rev_cmp_url($rv, $rv - 1)); ?>"
                                   title="Сравнить с предыдущей ревизией: что изменилось в размерах и коде"
                                   class="text-gray-400 transition hover:text-blue-600 dark:hover:text-blue-400">
                                    <i class="fas fa-code-compare text-[11px]"></i> сравнить
                                </a>
                                <?php endif; ?>
                                <span class="text-gray-400"><?php echo HTML::chars((string) $r['created_at']); ?></span>

                                <?php
                                // Удаление ОДНОЙ ревизии (владелец, 27.09.2026). Последнюю
                                // не показываем: она — текущее состояние детали, и
                                // сервис её тоже не даст удалить.
                                if ($can_edit AND $rv !== $latest_rev):
                                ?>
                                <form method="post" class="inline-flex">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <input type="hidden" name="act" value="delete_rev">
                                    <input type="hidden" name="rev" value="<?php echo $rv; ?>">
                                    <button data-n2j-arm="Удалить?" type="submit" class="n2j-btn-icon-danger h-6 w-6"
                                            title="Удалить ревизию <?php echo $rv; ?> (файлы уйдут с диска)">
                                        <i class="fas fa-trash-can text-[11px]"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (trim((string) $r['instruction']) !== ''): ?>
                        <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $r['instruction'], 0, 160)); ?></p>
                        <?php endif; ?>
                        <?php if (trim((string) $r['error']) !== ''): ?>
                        <p class="text-sm text-rose-600 dark:text-rose-400"><?php echo HTML::chars(mb_substr((string) $r['error'], 0, 180)); ?></p>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <!-- Правка словами -->
            <?php if ($can_edit): ?>
            <section class="<?php echo $card; ?>">
                <h2 class="n2j-h3">Что поправить</h2>
                <form method="post" class="mt-1.5 space-y-2" data-nj643-revise>
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="revise">
                    <textarea name="instruction" rows="2" required maxlength="600" placeholder="Например: увеличь отверстия до Ø6 и добавь фаску 1 мм по верхней кромке"
                              class="n2j-textarea w-full"></textarea>
                    <button type="submit" class="n2j-btn-process n2j-btn-sm w-full">
                        <i class="fas fa-pen-ruler text-[11px]"></i>Поправить и пересобрать
                    </button>
                    <p class="text-sm text-gray-400">ИИ изменит код модели, движок перепроверит геометрию и границы размеров. Получится новая ревизия.</p>
                </form>
            </section>

            <section class="<?php echo $card; ?>">
                <h2 class="n2j-h3">Действия</h2>
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <form method="post" class="inline-flex" data-nj643-ajax="again">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="again">
                        <button type="submit" class="n2j-btn-secondary n2j-btn-sm">
                            <i class="fas fa-rotate text-[11px]"></i>Собрать заново
                        </button>
                    </form>
                    <?php if (trim((string) $p['code_py']) !== ''): ?>
                    <form method="post" class="inline-flex">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="copy">
                        <button type="submit" title="Новая деталь из того же кода модели: правки не заденут эту деталь, токены ИИ не тратятся"
                                class="n2j-btn-secondary n2j-btn-sm">
                            <i class="fas fa-clone text-[11px]"></i>Сделать по образцу
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="post" class="inline-flex">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="delete">
                        <button data-n2j-arm="Точно удалить?" type="submit" class="n2j-btn-danger n2j-btn-sm">
                            <i class="fas fa-trash-can text-[11px]"></i>Удалить деталь
                        </button>
                    </form>
                </div>
            </section>
            <?php endif; ?>

            <!-- Расход (владельцу и управлению) -->
            <?php if ($usage): ?>
            <section class="<?php echo $card; ?>">
                <details>
                    <summary class="cursor-pointer text-sm font-bold text-gray-900 dark:text-white">Расход ИИ по этой детали</summary>
                    <ul class="mt-1.5 space-y-1.5 text-sm text-gray-500 dark:text-gray-400">
                        <?php foreach ($usage as $u): ?>
                        <li>
                            <p><?php echo HTML::chars((string) $u['phase']); ?> · <?php echo HTML::chars((string) $u['model']); ?> · <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $u['prompt_tokens']; ?>+<?php echo (int) $u['completion_tokens']; ?></span> токенов · <?php echo round((int) $u['duration_ms'] / 1000, 1); ?> с</p>
                            <?php if (trim((string) ($u['note'] ?? '')) !== ''): ?>
                            <p class="text-sm text-gray-400 dark:text-gray-500"><?php echo HTML::chars((string) $u['note']); ?></p>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </section>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Печатная карточка: на экране лежит за кадром, при печати — вместо страницы.
     Нужна для цеха: деталь заказывают по бумажке, а не по ссылке. Значения
     берём те же, что на странице: габарит, объём, размеры, файлы. -->
<div class="n2j-print-area n2j-print-only print:px-[10mm]" aria-hidden="true">
    <?php /* design-audit: allow h1 — печатная карточка: размеры для бумаги заданы инлайн */ ?>
    <h1 style="margin:0 0 2px;font-size:20px;font-weight:800"><?php echo HTML::chars((string) $p['title']); ?></h1>
    <p style="margin:0 0 10px;font-size:12px;color:#555">
        <?php echo HTML::chars($code); ?> · создана <?php echo HTML::chars((string) $p['created_at']); ?> ·
        ревизия <?php echo (int) $sel_rev; ?> · <?php echo HTML::chars($st_ru[$status] ?? $status); ?>
    </p>

    <table cellpadding="4" style="border-collapse:collapse;font-size:12px">
        <tr>
            <td style="border:1px solid #bbb"><b>Габарит</b></td>
            <td style="border:1px solid #bbb"><?php echo $dims; ?></td>
            <td style="border:1px solid #bbb"><b>Объём</b></td>
            <td style="border:1px solid #bbb"><?php echo $vol > 0 ? number_format($vol, 0, ',', ' ').' мм³' : '—'; ?></td>
        </tr>
        <tr>
            <td style="border:1px solid #bbb"><b>Тел</b></td>
            <td style="border:1px solid #bbb"><?php echo (int) ($geo['solids'] ?? 0); ?></td>
            <td style="border:1px solid #bbb"><b>Файлов</b></td>
            <td style="border:1px solid #bbb"><?php echo (int) count($models); ?></td>
        </tr>
    </table>

    <?php if ($params): ?>
    <h2 style="margin:12px 0 4px;font-size:14px">Размеры</h2>
    <table cellpadding="4" style="border-collapse:collapse;font-size:12px">
        <?php foreach ($params as $item): ?>
        <tr>
            <td style="border:1px solid #bbb"><?php echo HTML::chars((string) $item['label']); ?></td>
            <td style="border:1px solid #bbb"><b><?php echo rtrim(rtrim(number_format((float) $item['value'], 2, '.', ''), '0'), '.'); ?> <?php echo HTML::chars((string) $item['unit']); ?></b></td>
            <td style="border:1px solid #bbb;color:#666"><?php echo (float) ($item['min'] !== NULL ? $item['min'] : 0); ?>…<?php echo (float) ($item['max'] !== NULL ? $item['max'] : '—'); ?> <?php echo HTML::chars((string) $item['unit']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if ($models): ?>
    <h2 style="margin:12px 0 4px;font-size:14px">Файлы</h2>
    <p style="margin:0;font-size:12px">
        <?php $fl = []; foreach ($models as $f) { $fl[] = strtoupper((string) $f['kind']); } echo HTML::chars(implode(' · ', $fl)); ?>
    </p>
    <?php endif; ?>

    <p style="margin:12px 0 0;font-size:12px;color:#666">
        Ссылка на деталь: /cad/<?php echo HTML::chars($code); ?> · n2j.ru — CAD-детали по описанию
    </p>
</div>

<?php echo View::factory('643/script', ['csrf' => $csrf, 'scope' => 'part', 'code' => $code]); ?>
