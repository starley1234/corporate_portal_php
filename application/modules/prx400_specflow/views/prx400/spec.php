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

<?php $pipeline_pending = in_array($spec['status'], ['queued', 'running'], TRUE);
$pipeline_since = 0;
if ($pipeline_pending AND ! empty($spec['updated_at']))
{
    $pipeline_since = max(0, time() - strtotime((string) $spec['updated_at'])); // разбор стартовал при launch()
}
$pipeline_clock = sprintf('%d:%02d', intdiv($pipeline_since, 60), $pipeline_since % 60);
$job_stage_text = trim((string) ($job_stage['stage'] ?? ''));
?>
    <?php /* Полоса «Разбор идёт» — эталон prx399: спиннер, этап воркера и время,
         без полос прогресса; исчезает по завершении (и страница один раз
         перезагружается — JS ниже). */ ?>
    <div id="prx400-pipeline" class="prx-flash prx-flash-info mt-4<?php echo $pipeline_pending ? '' : ' hidden'; ?>" role="status">
        <i class="fas fa-circle-notch fa-spin"></i>
        <span>Разбор идёт: <?php echo $job_stage_text !== '' ? HTML::chars($job_stage_text) : 'задача в очереди воркера'; ?> — статусы обновляются автоматически. Время: <span data-prx-clock data-prx-from="<?php echo (int) $pipeline_since; ?>" class="font-mono tabular-nums"><?php echo $pipeline_clock; ?></span></span>
        <a href="/prx120/monitor" class="prx-link ml-2 text-sm">Монитор воркеров →</a>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <span class="<?php echo HTML::chars($badge); ?>"><span class="prx-dot"></span><?php echo HTML::chars($status_label); ?></span>
<?php if ($spec['status'] === 'failed'): ?>
        <span class="text-sm text-rose-600 dark:text-rose-400"><?php echo HTML::chars((string) $spec['error']); ?></span>
<?php if (strpos((string) $spec['error'], 'file_unavailable') !== FALSE): ?>
        <span class="block w-full text-sm text-gray-600 dark:text-gray-300"><i class="fas fa-circle-info mr-1 text-gray-400"></i>Исходный PDF не найден в хранилище<?php echo $file_ok ? '' : ' (путь записи: <code class="font-mono text-xs">'.HTML::chars((string) $spec['storage_path']).'</code>)' ?> — обычно это след перевыкатки кода со старым абсолютным путём. Перезалейте спецификацию на странице списка; повторный разбор станет доступен после перезаливки.</span>
<?php endif; endif; ?>
<?php if ($spec['status'] === 'review'): ?>
        <span class="text-sm text-gray-500 dark:text-gray-400">Осталось спорных позиций: <?php echo (int) $issues; ?>. Подтвердите их и завершите проверку.</span>
<?php endif; ?>
        <span class="ml-auto flex gap-2">
<?php if ($spec['status'] === 'failed'): ?>
            <?php if ( ! empty($file_ok)): ?><form method="post" action="/prx400_specflow/launch/<?php echo (int) $spec['id']; ?>" class="inline"><input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><button class="prx-btn-secondary prx-btn-sm" type="submit"><i class="fas fa-rotate-right"></i>Повторить разбор</button></form><?php endif; ?>
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

<script>
// Живая индикация разбора: опрос карточки каждые 5с (fetch + DOMParser +
// подмена полосы по id — эталон монитора/prx399). Пока идёт работа — освежаем
// этап и секундомер; когда работа кончилась — один полный reload, чтобы
// показать таблицу позиций. Вкладка в фоне и повторный тик не опрашиваются.
(function () {
    'use strict';
    var strip = document.getElementById('prx400-pipeline');
    if (!strip || strip.classList.contains('hidden')) return;

    var clock = strip.querySelector('[data-prx-clock]');
    if (clock && window.PRX) PRX.stopwatch(clock, parseInt(clock.getAttribute('data-prx-from') || '0', 10) || 0);

    var busy = false;
    function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        fetch(location.pathname + location.search, { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (html) {
                if (!html) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById('prx400-pipeline');
                if (!fresh) return;
                if (fresh.classList.contains('hidden')) {
                    window.location.reload(); // разбор кончился — показать результат целиком
                    return;
                }
                strip.replaceChildren.apply(strip, Array.prototype.slice.call(fresh.childNodes));
                strip.className = fresh.className;
                var c = strip.querySelector('[data-prx-clock]');
                if (c && window.PRX) PRX.stopwatch(c, parseInt(c.getAttribute('data-prx-from') || '0', 10) || 0);
            })
            .catch(function () { /* сеть моргнула — в следующий тик */ })
            .finally(function () { busy = false; });
    }
    setInterval(refresh, 5000);

    // POST-формы карточки (повтор разбора/верификация/завершение): кнопка
    // занята на время запроса — двойной запуск исключён.
    document.querySelectorAll('form[method="post"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn && window.PRX) PRX.busy(btn, true);
        });
    });
})();
</script>
