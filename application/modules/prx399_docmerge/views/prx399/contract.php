<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Карточка пакета prx399: пайплайн → файлы цепочки → карточка сделки →
 * итоговая редакция (diff «было/стало») → матрица рисков → паспорт сделки.
 *
 * Diff-семантика узла (node.status): base — без изменений; added — новый
 * пункт из ДС; changed — текст заменён ДС; annulled — исключён ДС (история
 * текста сохранена в node.history, ссылка на такой пункт = битая ссылка).
 */

$node_badges = [
    'base' => ['prx-badge-wait', 'Исходный договор', 'fa-file-lines'],
    'added' => ['prx-badge-ok', 'добавлено', 'fa-plus'],
    'changed' => ['prx-badge-run', 'изменено', 'fa-pen'],
    'annulled' => ['prx-badge-err', 'исключено', 'fa-eraser'],
];
$severity_badges = ['high' => 'prx-badge-err', 'medium' => 'prx-badge-run', 'low' => 'prx-badge-wait'];
$severity_ru = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
$ds_badges = ['base' => 'prx-badge-info', 'ds' => 'prx-badge-wait'];
$by_file = [];
foreach ($deltas as $delta)
{
    $by_file[(int) $delta['file_id']][] = $delta;
}
$changes = 0;
foreach ($nodes as $node)
{
    if (in_array($node['status'], ['added', 'changed', 'annulled'], TRUE)) $changes++;
}
// Пайплайн в работе: статусы обновляются автоматически (эталон — монитор,
// «спиннер, этап и время», без полос прогресса).
$pipeline_pending = in_array($contract['status'], ['parsing', 'auditing'], TRUE)
    || count(array_filter($files, static fn ($f) => in_array($f['status'], ['queued', 'working'], TRUE))) > 0;
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

<?php
$contract_actions = [];
if ($can_run AND in_array($contract['status'], ['draft', 'failed'], TRUE))
{
    $contract_actions[] = ['html' => '<form method="post" action="/prx399_docmerge/launch/'.$contract['id'].'" class="inline"><input type="hidden" name="csrf" value="'.HTML::chars($csrf).'"><button type="submit" class="prx-btn-cta prx-btn-sm"><i class="fas fa-play mr-1.5"></i>Запустить пайплайн</button></form>'];
}
if ( ! empty($contract['doc_tree']))
{
    // Word — конечный документ; Markdown — для дальнейшей обработки; TXT — плоский
    $contract_actions[] = ['label' => 'Word (.docx)', 'href' => '/prx399_docmerge/export/'.$contract['id'].'?format=docx', 'icon' => 'fa-file-word', 'role' => 'process'];
    $contract_actions[] = ['label' => 'Markdown', 'href' => '/prx399_docmerge/export/'.$contract['id'].'?format=md', 'icon' => 'fa-file-code', 'role' => 'secondary'];
    $contract_actions[] = ['label' => 'TXT', 'href' => '/prx399_docmerge/export/'.$contract['id'], 'icon' => 'fa-file-lines', 'role' => 'secondary'];
}
if ($can_run AND ! empty($contract['doc_tree']) AND in_array($contract['status'], ['audited', 'failed'], TRUE))
{
    $contract_actions[] = ['html' => '<form method="post" action="/prx399_docmerge/rebuild/'.$contract['id'].'" class="inline"><input type="hidden" name="csrf" value="'.HTML::chars($csrf).'"><button type="submit" class="prx-btn-secondary prx-btn-sm"><i class="fas fa-rotate mr-1.5"></i>Пересобрать редакцию</button></form>'];
}
$contract_actions[] = ['html' => '<form method="post" action="/prx399_docmerge/delete/'.$contract['id'].'" class="inline"><input type="hidden" name="csrf" value="'.HTML::chars($csrf).'"><button type="submit" class="prx-btn-danger prx-btn-sm" data-prx-confirm="Пакет, файлы и результаты будут удалены безвозвратно." data-prx-title="Удалить пакет?" data-prx-ok="Удалить"><i class="fas fa-trash mr-1.5"></i>Удалить</button></form>'];
echo View::factory('partials/page_header', [
    'crumbs'  => [['Слияние договоров', '/prx399_docmerge'], [$contract['title']]],
    'title'   => $contract['title'],
    'pill'    => [$statuses[$contract['status']][0] ?? $contract['status'], $statuses[$contract['status']][1] ?? 'fa-file-contract'],
    'actions' => $contract_actions,
    'lead'    => 'Фокус аудита: '.HTML::chars($focus[$contract['focus']] ?? $contract['focus']).'. Пайплайн: распознавание → дельты ДС (модель) → детерминированная сборка (код) → риск-аудит (Map-Reduce).',
]); ?>

<?php if ( ! empty($contract['error'])): ?>
    <div class="prx-flash prx-flash-err mt-4" role="alert"><i class="fas fa-circle-exclamation"></i><span><?php echo HTML::chars($contract['error']); ?></span></div>
<?php endif; ?>

    <!-- ==== 0. Индикация работы пайплайна (эталон: спиннер, этап, время) ==== -->
    <div id="prx399-pipeline" class="prx-flash prx-flash-info mt-4<?php echo $pipeline_pending ? '' : ' hidden'; ?>" role="status">
        <i class="fas fa-circle-notch fa-spin"></i>
        <span>Пайплайн идёт: распознавание и дельты ДС в очереди воркера — статусы обновляются автоматически. Время: <span data-prx-clock class="font-mono tabular-nums">0:00</span></span>
    </div>

    <!-- ==== 1. Файлы цепочки ==== -->
    <div id="prx399-files" class="prx-card mt-6">
        <div class="prx-card-head"><h2 class="prx-h3">Файлы и порядок применения ДС</h2></div>
        <table class="prx-table w-full">
            <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col">Роль</th>
                <th scope="col">Файл</th>
                <th scope="col">Стр.</th>
                <th scope="col">Статус</th>
                <th scope="col">Дельт</th>
                <th scope="col"><span class="sr-only">Действия</span></th>
            </tr>
            </thead>
            <tbody>
<?php foreach ($files as $file): $file_id = (int) $file['id']; ?>
            <tr>
                <td class="font-mono"><?php echo $file['kind'] === 'ds' ? 'ДС №'.(int) $file['ordinal'] : '—'; ?></td>
                <td><span class="prx-badge <?php echo $ds_badges[$file['kind']] ?? 'prx-badge-wait'; ?>"><?php echo $file['kind'] === 'base' ? 'Базовый' : 'ДС'; ?></span></td>
                <td class="max-w-xs truncate" title="<?php echo HTML::chars($file['original_name']); ?>"><?php echo HTML::chars($file['original_name']); ?></td>
                <td><?php echo $file['pages'] !== NULL ? (int) $file['pages'] : '—'; ?></td>
                <td><span class="prx-badge <?php echo $file_statuses[$file['status']][1] ?? 'prx-badge-wait'; ?>"><span class="prx-dot"></span><?php echo HTML::chars($file_statuses[$file['status']][0] ?? $file['status']); ?></span></td>
                <td><?php echo isset($by_file[$file_id]) ? count($by_file[$file_id]) : '—'; ?></td>
                <td class="text-right whitespace-nowrap">
<?php if ($can_run AND $file['kind'] === 'ds' AND in_array($contract['status'], ['draft', 'failed'], TRUE)): ?>
                    <form method="post" action="/prx399_docmerge/move/<?php echo $file_id; ?>" class="inline">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="dir" value="up">
                        <button type="submit" class="prx-btn-secondary prx-btn-sm" title="Раньше"><i class="fas fa-arrow-up text-xs"></i></button>
                    </form>
                    <form method="post" action="/prx399_docmerge/move/<?php echo $file_id; ?>" class="inline">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="dir" value="down">
                        <button type="submit" class="prx-btn-secondary prx-btn-sm" title="Позже"><i class="fas fa-arrow-down text-xs"></i></button>
                    </form>
<?php endif; ?>
<?php if ($can_run AND $file['status'] === 'failed'): ?>
                    <form method="post" action="/prx399_docmerge/retry/<?php echo $file_id; ?>" class="inline">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <button type="submit" class="prx-btn-secondary prx-btn-sm"><i class="fas fa-rotate-right text-xs"></i>повторить</button>
                    </form>
<?php endif; ?>
                </td>
            </tr>
<?php if ( ! empty($file['error'])): ?>
            <tr><td colspan="7" class="text-xs text-red-600 dark:text-red-400"><?php echo HTML::chars($file['error']); ?></td></tr>
<?php endif; ?>
<?php $prx_job = $jobs[$file_id] ?? NULL; ?>
<?php if (in_array($file['status'], ['queued', 'working'], TRUE)): ?>
<?php if ((string) ($prx_job['status'] ?? '') === 'processing'): ?>
            <tr><td colspan="7" class="text-xs text-blue-600 dark:text-blue-400"><i class="fas fa-circle-notch fa-spin mr-1"></i>Воркер работает: <?php echo (string) ($prx_job['stage'] ?? '') !== '' ? HTML::chars((string) $prx_job['stage']) : 'задача взята'; ?> · попытка <?php echo (int) ($prx_job['attempts'] ?? 0); ?>/<?php echo (int) ($prx_job['max_attempts'] ?? 1); ?></td></tr>
<?php else: ?>
            <tr><td colspan="7" class="text-xs text-gray-400"><i class="fas fa-hourglass-half mr-1"></i>Задача в очереди воркера — заберут по мере освобождения<?php echo (int) ($prx_job['attempts'] ?? 0) > 0 ? ' · попытка '.(int) $prx_job['attempts'].'/'.(int) ($prx_job['max_attempts'] ?? 1) : ''; ?></td></tr>
<?php endif; ?>
<?php endif; ?>
<?php $ast = $file['kind'] === 'base' && isset($file['ast']) && is_string($file['ast']) ? json_decode($file['ast'], TRUE) : NULL; ?>
<?php if (isset($ast['stats']['nodes']) AND (int) $ast['stats']['nodes'] > 0): ?>
            <tr><td colspan="7" class="text-xs text-gray-400">Распознавание: <?php echo (int) $ast['stats']['nodes']; ?> узлов<?php if ( ! empty($ast['stats']['unrecognized'])): ?> · <span class="text-rose-600 dark:text-rose-400" title="Строки, похожие на нумерованные пункты, но не попавшие в дерево — проверьте качество скана">не распознаны: <?php echo HTML::chars(implode(', ', array_map('strval', array_slice((array) $ast['stats']['unrecognized'], 0, 4)))); ?></span><?php endif; ?><?php if ( ! empty($ast['stats']['duplicates'])): ?> · <span class="text-amber-600 dark:text-amber-400">дубли якорей: <?php echo HTML::chars(implode(', ', array_map('strval', array_slice((array) $ast['stats']['duplicates'], 0, 4)))); ?></span><?php endif; ?></td></tr>
<?php endif; ?>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ==== 1b. Дельты ДС: ревью side-by-side «инструкции ↔ текст ДС» (ADR-003) ==== -->
<?php $op_ru = ['replace' => 'замена', 'add' => 'дополнение', 'annul' => 'исключение'];
$op_badges = ['replace' => 'prx-badge-run', 'add' => 'prx-badge-ok', 'annul' => 'prx-badge-err'];
$ds_files = array_filter($files, static fn ($f) => $f['kind'] === 'ds');
?>
<?php foreach ($files as $ds_file): ?>
<?php if ($ds_file['kind'] !== 'ds') { continue; }
$ds_id = (int) $ds_file['id'];
$ds_rows = $by_file[$ds_id] ?? [];
$ds_text = (string) ($ds_file['text'] ?? '');
$ds_in_build = count(array_filter($ds_rows, static fn ($d) => (string) ($d['included'] ?? 'yes') === 'yes'));
?>
    <div class="prx-card mt-6">
        <div class="prx-card-head">
            <h2 class="prx-h3">ДС №<?php echo (int) $ds_file['ordinal']; ?> — <?php echo HTML::chars((string) $ds_file['original_name']); ?></h2>
            <span class="prx-count"><?php echo count($ds_rows); ?> дельт · <?php echo $ds_in_build; ?> в сборке<?php echo (string) $ds_file['status'] === 'failed' ? ' · файл не разобран' : ''; ?></span>
        </div>
<?php if (empty($ds_rows) AND $ds_text === ''): ?>
        <p class="prx-hint px-4 py-3">Инструкции появятся после разбора файла воркером.</p>
<?php else: ?>
        <div class="grid gap-5 p-4 xl:grid-cols-2">
            <!-- Инструкции -->
            <div class="min-w-0">
<?php if (empty($ds_rows)): ?>
                <p class="prx-hint">Дельт нет — модель не нашла правок в этом ДС.</p>
<?php else: ?>
                <table class="prx-table w-full">
                    <thead>
                    <tr><th scope="col">Операция</th><th scope="col">Пункт</th><th scope="col">Суть</th><th scope="col">Увер.</th><th scope="col">Гард</th><th scope="col"><span class="sr-only">Состав</span></th></tr>
                    </thead>
                    <tbody>
<?php foreach ($ds_rows as $delta): ?>
                    <tr<?php echo (string) ($delta['included'] ?? 'yes') === 'no' ? ' class="opacity-60"' : ''; ?>>
                        <td><span class="prx-badge <?php echo $op_badges[$delta['op']] ?? 'prx-badge-wait'; ?>"><span class="prx-dot"></span><?php echo $op_ru[$delta['op']] ?? $delta['op']; ?></span></td>
                        <td class="font-mono"><?php echo HTML::chars((string) $delta['anchor']); ?></td>
                        <td class="max-w-sm"><?php echo $delta['note'] !== NULL ? HTML::chars((string) $delta['note']) : '<span class="text-gray-400">—</span>'; ?></td>
                        <td class="whitespace-nowrap"><?php echo $delta['confidence'] !== NULL ? (int) round($delta['confidence'] * 100).'%' : '—'; ?></td>
                        <td><?php echo (string) $delta['body_in_ds'] === 'no'
                            ? '<span class="prx-badge prx-badge-err" title="Текст дельты не найден в ДС дословно — модель пересказала"><span class="prx-dot"></span>пересказ</span>'
                            : ((string) $delta['body_in_ds'] === 'yes'
                                ? '<span class="prx-badge prx-badge-ok" title="Текст дельты дословно найден в ДС"><span class="prx-dot"></span>дословно</span>'
                                : '<span class="text-gray-400">—</span>'); ?></td>
                        <td class="text-right whitespace-nowrap">
<?php if ($can_run AND in_array($contract['status'], ['audited', 'failed'], TRUE)): ?>
<?php $included_now = (string) ($delta['included'] ?? 'yes') === 'yes'; ?>
                            <form method="post" action="/prx399_docmerge/delta/<?php echo (int) $delta['id']; ?>" class="inline">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="included" value="<?php echo $included_now ? 'no' : 'yes'; ?>">
                                <button type="submit" class="prx-btn-secondary prx-btn-sm"><?php echo $included_now ? 'исключить' : 'вернуть'; ?></button>
                            </form>
<?php elseif ((string) ($delta['included'] ?? 'yes') === 'no'): ?>
                            <span class="text-xs text-gray-400">исключена</span>
<?php endif; ?>
                        </td>
                    </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
                <p class="prx-hint mt-2">Состав сборки решает человек: исключённая дельта не попадёт в редакцию при «Пересобрать редакцию».</p>
<?php endif; ?>
            </div>
            <!-- Текст ДС -->
            <div class="min-w-0">
                <h3 class="prx-h3 mb-1.5 text-sm text-gray-500 dark:text-gray-400">Текст ДС</h3>
<?php if ($ds_text !== ''): ?>
                <div class="max-h-[70vh] overflow-y-auto whitespace-pre-line rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs leading-relaxed text-gray-700 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-200"><?php echo HTML::chars($ds_text); ?></div>
<?php else: ?>
                <p class="prx-hint">Текст появится после следующего прогона пайплайна (для сканов текст извлекает воркер).</p>
<?php endif; ?>
            </div>
        </div>
<?php endif; ?>
    </div>
<?php endforeach; ?>

    <!-- ==== 2. Паспорт сделки + карточка контекста ==== -->
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <div class="prx-card md:col-span-2">
            <div class="prx-card-head"><h2 class="prx-h3">Паспорт сделки (Executive Summary)</h2></div>
<?php if ( ! empty($contract['summary'])): ?>
            <p class="whitespace-pre-line text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $contract['summary']); ?></p>
<?php else: ?>
            <p class="prx-hint">Появится после риск-аудита: итоговая цена, актуальный график оплат, финальные сроки — с учётом всех ДС.</p>
<?php endif; ?>
        </div>
        <div class="prx-card md:col-span-1">
            <div class="prx-card-head"><h2 class="prx-h3">Карточка сделки (Scratchpad)</h2></div>
<?php if ( ! empty($card)): ?>
            <dl class="space-y-1.5 text-xs">
<?php foreach (['subject' => 'Предмет', 'amount' => 'Сумма', 'start_date' => 'Начало', 'end_date' => 'Окончание'] as $key => $label): ?>
<?php if ( ! empty($card[$key])): ?>
                <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400"><?php echo $label; ?></dt><dd class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars((string) $card[$key]); ?></dd></div>
<?php endif; ?>
<?php endforeach; ?>
<?php if ( ! empty($card['parties']) AND is_array($card['parties'])): ?>
                <div><dt class="text-gray-500 dark:text-gray-400">Стороны</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100">
<?php foreach ($card['parties'] as $party): ?>
                    <span class="block truncate" title="<?php echo HTML::chars(is_array($party) ? (string) ($party['name'] ?? '') : (string) $party); ?>"><?php echo HTML::chars(is_array($party) ? (string) ($party['name'] ?? '') : (string) $party); ?></span>
<?php endforeach; ?>
                </dd></div>
<?php endif; ?>
            </dl>
<?php else: ?>
            <p class="prx-hint">Формируется из преамбулы базового договора: 200–300 токенов контекста, которые модель «помнит» на любой странице.</p>
<?php endif; ?>
        </div>
    </div>

    <!-- ==== 3. Матрица рисков ==== -->
    <div class="prx-card mt-6">
        <div class="prx-card-head">
            <h2 class="prx-h3">Матрица рисков</h2>
            <span class="prx-count"><?php echo count($risks); ?> выявлено<?php echo $changes ? ' · '.$changes.' правок в редакции' : ''; ?></span>
        </div>
<?php if (empty($risks)): ?>
        <p class="prx-hint">Риски появятся после сборки (статические коллизии) и риск-аудита (LLM).</p>
<?php else: ?>
        <table class="prx-table w-full">
            <thead>
            <tr><th scope="col">Критичность</th><th scope="col">Блок</th><th scope="col">Проблема</th><th scope="col">Рекомендация</th><th scope="col">Источник</th></tr>
            </thead>
            <tbody>
<?php foreach ($risks as $risk): ?>
            <tr>
                <td><span class="prx-badge <?php echo $severity_badges[$risk['severity']] ?? 'prx-badge-wait'; ?>"><span class="prx-dot"></span><?php echo $severity_ru[$risk['severity']] ?? $risk['severity']; ?></span></td>
                <td class="text-xs"><?php echo HTML::chars((string) ($risk['area'] ?? '—')); ?><?php if ( ! empty($risk['chunk_path'])): $anchors = preg_split('~\s*;\s*~u', trim((string) $risk['chunk_path']), -1, PREG_SPLIT_NO_EMPTY) ?: []; ?> <span class="font-mono text-xs"><?php echo implode('; ', array_map(static function ($anchor) use ($contract) {
                        return '<a class="text-blue-600 hover:underline dark:text-blue-400" title="К пункту в редакции" href="/prx399_docmerge/contract/'.(int) $contract['id'].'#node-'.str_replace('.', '-', $anchor).'">'.HTML::chars($anchor).'</a>';
                    }, $anchors)); ?></span>
<?php endif; ?></td>
                <td class="max-w-md"><span class="font-semibold"><?php echo HTML::chars((string) $risk['title']); ?></span><?php if ( ! empty($risk['detail'])): ?><span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $risk['detail']); ?></span><?php endif; ?></td>
                <td class="max-w-xs text-xs"><?php echo HTML::chars((string) ($risk['recommendation'] ?? '—')); ?></td>
                <td class="text-xs"><?php echo $risk['source'] === 'static' ? 'статика' : 'ИИ'; ?></td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php endif; ?>
    </div>

    <!-- ==== 4. Итоговая редакция с diff ==== -->
<?php if ( ! empty($nodes)): ?>
    <div class="prx-card mt-6">
        <div class="prx-card-head">
            <h2 class="prx-h3">Актуальная редакция</h2>
            <nav class="prx-seg" aria-label="Фильтр изменений">
                <a href="/prx399_docmerge/contract/<?php echo (int) $contract['id']; ?>" class="prx-seg-item<?php echo $show === 'all' ? ' is-active' : ''; ?>">Весь текст</a>
                <a href="/prx399_docmerge/contract/<?php echo (int) $contract['id']; ?>?show=changes" class="prx-seg-item<?php echo $show === 'changes' ? ' is-active' : ''; ?>">Только правки (<?php echo $changes; ?>)</a>
            </nav>
        </div>
<?php foreach ($nodes as $node): ?>
<?php $status = (string) $node['status'];
if ($show === 'changes' AND ! in_array($status, ['added', 'changed', 'annulled'], TRUE)) continue;
$badge = $node_badges[$status] ?? $node_badges['base'];
$depth = substr_count((string) $node['path'], '.');
$history = '';
foreach (($node['history'] ?? []) as $step)
{
    $history .= ($history === '' ? '' : ' → ').$step['rev'].' ('.$step['op'].')';
}
?>
            <div id="node-<?php echo str_replace('.', '-', (string) $node['path']); ?>" class="border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-800<?php echo $status === 'annulled' ? ' opacity-60' : ''; ?>" style="margin-left: <?php echo min($depth, 4) * 16; ?>px">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-xs font-bold text-gray-500"><?php echo HTML::chars((string) $node['path']); ?></span>
                    <span class="font-semibold text-gray-800 dark:text-gray-100<?php echo $status === 'annulled' ? ' line-through' : ''; ?>"><?php echo HTML::chars((string) $node['title']); ?></span>
                    <?php if ($status !== 'base'): ?><span class="prx-badge <?php echo $badge[0]; ?>"><span class="prx-dot"></span><i class="fas <?php echo $badge[2]; ?> mr-1 text-[10px]"></i><?php echo $badge[1]; ?> · <?php echo HTML::chars((string) $node['source']); ?></span><?php endif; ?>
                </div>
<?php if ((string) $node['text'] !== ''): ?>
                <p class="mt-1 whitespace-pre-line text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $node['text']); ?></p>
<?php endif; ?>
<?php if ($history !== ''): ?>
                <p class="mt-1 text-xs text-gray-400" title="История пункта по цепочке ДС">история: <?php echo HTML::chars($history); ?></p>
<?php endif; ?>
            </div>
<?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<button id="prx399-to-top" type="button" title="К началу документа"
        class="hidden fixed bottom-6 right-6 z-40 flex items-center gap-1 rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-md hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
    Вверх
</button>

<script>
// Индикация пайплайна: опрос карточки по эталону монитора (fetch + DOMParser
// + подмена блоков по id). Пока есть работа — освежаем статусы файлов каждые
// 5 секунд; когда работа кончилась — один полный reload, чтобы показать
// риски и diff. Спиннер/этап/время — без полос прогресса.
(function () {
    'use strict';
    var strip = document.getElementById('prx399-pipeline');
    if (!strip || strip.classList.contains('hidden')) return;

    var clock = strip.querySelector('[data-prx-clock]');
    if (clock && window.PRX) PRX.stopwatch(clock, 0);

    var busy = false;
    function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        fetch(location.pathname + location.search, { headers: { 'X-Prx399-Status': '1' }, cache: 'no-store' })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (html) {
                if (!html) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById('prx399-pipeline');
                if (fresh && fresh.classList.contains('hidden')) {
                    window.location.reload(); // один раз: риски и diff на странице
                    return;
                }
                ['prx399-pipeline', 'prx399-files'].forEach(function (id) {
                    var f = doc.getElementById(id), live = document.getElementById(id);
                    if (f && live) {
                        live.replaceChildren.apply(live, Array.prototype.slice.call(f.childNodes));
                        live.className = f.className;
                    }
                });
            })
            .catch(function () { /* сеть моргнула — в следующий тик */ })
            .finally(function () { busy = false; });
    }
    setInterval(refresh, 5000);
})();

// Составные якоря (#node-3-7;3-10;…): элемента с таким id нет — браузер
// молчит. Прыгаем к первому пункту из списка (в т.ч. старые скопированные
// ссылки).
var compound = location.hash.match(/^#node-([^;]+);/);
if (compound) {
    var first = document.getElementById('node-' + compound[1]);
    if (first) first.scrollIntoView();
}

// Кнопка «Вверх»: редакция бывает длинной — появляется после прокрутки.
var toTop = document.getElementById('prx399-to-top');
if (toTop) {
    var toggleTop = function () { toTop.classList.toggle('hidden', window.scrollY < 400); };
    window.addEventListener('scroll', toggleTop, { passive: true });
    toggleTop();
    toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
}

// POST-формы карточки (запуск/пересборка/повтор/порядок/удаление): кнопка
// занята на время запроса — двойной запуск пайплайна невозможен.
document.querySelectorAll('form[method="post"]').forEach(function (form) {
    form.addEventListener('submit', function () {
        var btn = form.querySelector('button[type="submit"]');
        if (btn && window.PRX) PRX.busy(btn, true);
    });
});
</script>
