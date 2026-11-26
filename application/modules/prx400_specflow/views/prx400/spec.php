<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Карточка спецификации prx400: таблица позиций с цветовой индикацией
 * качества (зелёный/жёлтый/красный), математический отчёт, верификация
 * оператора, экспорт.
 *
 * Переменные: $spec, $rows, $catalog_count, $job, $math, $csrf, $flash.
 */
$row_status_ru = [
    'ok' => 'совпало с НСИ',
    'review' => 'на подтверждении',
    'new' => 'новая позиция',
    'error' => 'ошибка арифметики',
];
$spec_status_ru = [
    'queued' => ['prx-badge-wait', 'в очереди'],
    'running' => ['prx-badge-run', 'разбор идёт'],
    'review' => ['prx-badge-info', 'на проверке'],
    'done' => ['prx-badge-ok', 'готово'],
    'failed' => ['prx-badge-err', 'ошибка'],
];
list($badge, $status_label) = $spec_status_ru[(string) $spec['status']] ?? ['prx-badge-wait', (string) $spec['status']];
$issues = count(array_filter($rows, static fn ($r) => (string) $r['status'] !== 'ok' && (string) $r['verified'] !== 't'));
$row_style = [
    'ok' => 'border-l-4 border-emerald-400',
    'review' => 'border-l-4 border-amber-400 bg-amber-50/40 dark:bg-amber-950/20',
    'new' => 'border-l-4 border-rose-400 bg-rose-50/40 dark:bg-rose-950/20',
    'error' => 'border-l-4 border-rose-500 bg-rose-50/60 dark:bg-rose-950/30',
];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Оцифровка спецификаций', '/prx400_specflow'], [$spec['original_name']]],
        'title' => 'Спецификация №'.(int) $spec['id'],
        'pill' => ['prx400 · карточка спецификации', 'fa-table-list'],
        'lead' => HTML::chars((string) $spec['template_title']).($spec['spec_number'] ? ' · шифр '.HTML::chars((string) $spec['spec_number']) : '').($spec['department'] ? ' · '.HTML::chars((string) $spec['department']) : ''),
        'actions' => [
            ['label' => 'Исходный PDF', 'href' => '/prx400_specflow/file/'.(int) $spec['id'], 'icon' => 'fa-file-pdf', 'role' => 'secondary'],
            ['label' => 'JSON для ERP', 'href' => '/prx400_specflow/export/'.(int) $spec['id'], 'icon' => 'fa-file-export', 'role' => 'secondary'],
            ['label' => 'CSV', 'href' => '/prx400_specflow/export/'.(int) $spec['id'].'?format=csv', 'icon' => 'fa-file-csv', 'role' => 'secondary'],
        ],
    ]); ?>

    <?php echo View::factory('partials/flash', ['flash' => $flash]); ?>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <span class="<?php echo HTML::chars($badge); ?>"><span class="prx-dot"></span><?php echo HTML::chars($status_label); ?></span>
<?php if (in_array($spec['status'], ['queued', 'running'], TRUE)): ?>
        <span class="prx-flash prx-flash-info mt-0 inline-flex items-center gap-2"><i class="fas fa-circle-notch fa-spin"></i>Воркер разбирает документ — статусы на <?php echo HTML::chars('/prx120/monitor'); ?></span>
<?php endif; ?>
<?php if ($spec['status'] === 'failed'): ?>
        <span class="text-sm text-rose-600 dark:text-rose-400"><?php echo HTML::chars((string) $spec['error']); ?></span>
<?php endif; ?>
<?php if ($spec['status'] === 'review'): ?>
        <span class="text-sm text-gray-500 dark:text-gray-400">Осталось спорных позиций: <?php echo (int) $issues; ?>. Подтвердите их и завершите проверку.</span>
<?php endif; ?>
        <span class="ml-auto flex gap-2">
<?php if ($spec['status'] === 'failed'): ?>
            <form method="post" action="/prx400_specflow/launch/<?php echo (int) $spec['id']; ?>" class="inline"><input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><button class="prx-btn-secondary prx-btn-sm" type="submit"><i class="fas fa-rotate-right"></i>Повторить разбор</button></form>
<?php elseif ($spec['status'] === 'review'): ?>
            <form method="post" action="/prx400_specflow/finish/<?php echo (int) $spec['id']; ?>" class="inline"><input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><button class="prx-btn-cta prx-btn-sm" type="submit"><i class="fas fa-check-double"></i>Завершить проверку</button></form>
<?php endif; ?>
        </span>
    </div>

<?php $m = is_array($math) ? $math : []; ?>
    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-scale-balanced mr-1.5 text-gray-400"></i>Математический контроль</h2>
        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
            <span>Строк: <strong><?php echo (int) count($rows); ?></strong></span>
            <span>Арифметика строк: <?php echo empty($m['row_errors']) ? '<strong class="text-emerald-600 dark:text-emerald-400">сошлась</strong>' : '<strong class="text-rose-600 dark:text-rose-400">расхождений: '.count($m['row_errors']).'</strong>'; ?></span>
            <span>Пропуски нумерации: <?php echo empty($m['gaps']) ? '<strong>нет</strong>' : '<strong class="text-amber-600 dark:text-amber-400">'.count($m['gaps']).'</strong>'; ?></span>
<?php if (isset($m['totals']) && $m['totals']['ok'] !== NULL): ?>
            <span>Итого по документу: <?php echo $m['totals']['ok'] ? '<strong class="text-emerald-600 dark:text-emerald-400">сходится</strong>' : '<strong class="text-rose-600 dark:text-rose-400">не сходится</strong> ('.(float) $m['totals']['computed'].' против '.(float) $m['totals']['declared'].')'; ?></span>
<?php endif; ?>
        </div>
    </section>

    <section class="prx-card mt-5 overflow-x-auto p-0">
        <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
            <h2 class="prx-h3">Позиции <span class="prx-count ml-1"><?php echo count($rows); ?></span>
                <span class="ml-2 text-xs font-normal text-gray-400">зелёный — найдено в НСИ · жёлтый — низкая уверенность · красный — новая позиция или ошибка</span>
            </h2>
        </div>
<?php if (empty($rows)): ?>
        <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Позиций пока нет — разбор ещё не завершён или не нашёл таблицу.</p>
<?php else: ?>
        <table class="prx-table">
            <thead><tr><th>№</th><th>Наименование</th><th>Тип, марка</th><th>Кол-во</th><th>Ед.</th><th>Цена</th><th>Сумма</th><th>НСИ</th><th></th></tr></thead>
            <tbody>
<?php foreach ($rows as $r): $rmatch = is_array($r['match'] ?? NULL) ? $r['match'] : json_decode((string) ($r['match'] ?? 'null'), TRUE); ?>
            <tr class="<?php echo HTML::chars($row_style[(string) $r['status']] ?? ''); ?>">
                <td class="font-mono tabular-nums"><?php echo $r['pos'] !== NULL ? (int) $r['pos'] : '—'; ?>
<?php foreach ((array) ($r['flags'] ?? []) as $f): if ((string) $f['type'] === 'gap'): ?>
                    <i class="fas fa-triangle-exclamation text-amber-500" title="Пропуск нумерации: после позиции <?php echo (int) $f['after_pos']; ?>"></i>
<?php elseif ((string) $f['type'] === 'unit_fractional'): ?>
                    <i class="fas fa-triangle-exclamation text-amber-500" title="Дробное количество для штучной единицы"></i>
<?php endif; endforeach; ?>
                </td>
                <td class="max-w-[320px]"><span class="block text-sm text-gray-900 dark:text-white"><?php echo HTML::chars((string) $r['raw_name']); ?></span>
<?php if ( ! empty($r['section'])): ?><span class="text-xs text-gray-400"><?php echo HTML::chars((string) $r['section']); ?></span><?php endif; ?>
                </td>
                <td class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $r['code_doc']); ?></td>
                <td class="tabular-nums"><?php echo $r['qty'] !== NULL ? (float) $r['qty'] : '—'; ?></td>
                <td><?php echo HTML::chars((string) $r['unit']); ?></td>
                <td class="tabular-nums"><?php echo $r['unit_price'] !== NULL ? number_format((float) $r['unit_price'], 2, ',', ' ') : '—'; ?></td>
                <td class="tabular-nums"><?php echo $r['total_price'] !== NULL ? number_format((float) $r['total_price'], 2, ',', ' ') : '—'; ?>
<?php foreach ((array) ($r['flags'] ?? []) as $f): if ((string) $f['type'] === 'math_row'): ?>
                    <i class="fas fa-circle-exclamation text-rose-500" title="Ожидалось <?php echo (float) $f['expected']; ?>"></i>
<?php endif; endforeach; ?>
                </td>
                <td>
<?php $code = (string) ($rmatch['code'] ?? ''); if ($code !== ''): ?>
                    <span class="prx-badge prx-badge-ok"><span class="prx-dot"></span><?php echo HTML::chars($code); ?></span>
                    <span class="block text-xs text-gray-400"><?php echo HTML::chars((string) ($rmatch['name'] ?? '')); ?> · <?php echo round(100 * (float) ($rmatch['score'] ?? 0)); ?>%<?php echo ! empty($rmatch['manual']) ? ' · вручную' : ''; ?></span>
<?php else: ?>
                    <span class="prx-badge prx-badge-err"><span class="prx-dot"></span>новая</span>
<?php $alts = (array) ($rmatch['alternatives'] ?? []); if ($alts): ?>
                    <span class="mt-1 block text-xs text-gray-400">похожие: <?php foreach (array_slice($alts, 0, 3) as $alt): ?><form method="post" action="/prx400_specflow/verify/<?php echo (int) $r['id']; ?>" class="inline"><input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="code" value="<?php echo HTML::chars((string) $alt['code']); ?>"><button type="submit" class="font-mono text-blue-600 hover:underline dark:text-blue-400" title="Подтвердить: <?php echo HTML::chars((string) $alt['name']); ?> (<?php echo round(100 * (float) $alt['score']); ?>%)"><?php echo HTML::chars((string) $alt['code']); ?></button></form> <?php endforeach; ?></span>
<?php endif; endif; ?>
                </td>
                <td class="text-right">
<?php if ((string) $r['verified'] !== 't'): ?>
                    <form method="post" action="/prx400_specflow/verify/<?php echo (int) $r['id']; ?>" class="inline"><input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><button type="submit" class="prx-btn-secondary prx-btn-sm" title="Подтвердить строку"><i class="fas fa-check"></i><span class="sr-only">Подтвердить</span></button></form>
<?php else: ?>
                    <span class="prx-badge prx-badge-ok"><span class="prx-dot"></span>проверено</span>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php endif; ?>
    </section>

<?php if ($catalog_count === 0): ?>
    <p class="prx-hint mt-4">Справочник НСИ (prx_400_catalog) пуст — все позиции помечены «новая». Наполните справочник кодами номенклатуры, и матчинг включится на повторном разборе.</p>
<?php endif; ?>
</div>
