<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Карточка спецификации prx400: дерево позиций (BOM) с цветовой индикацией
 * качества, фильтр спорных, поиск кода в НСИ, правка/добавление/разделение/
 * удаление строк, пакетное подтверждение, клавиатура, журнал, экспорт.
 *
 * Переменные: $spec (status/error — действующие, с учётом очереди), $rows,
 * $files, $duplicates, $edits, $actor_names, $templates, $parse_modes,
 * $catalog_count, $catalog_provider, $catalog_writable, $bulk_verify_min,
 * $job_stage, $math, $file_ok, $can_edit, $filter, $filters, $csrf, $flash.
 */
$mode_ru = ['ocr' => 'Точный OCR (без LLM)', 'ocr_vision' => 'OCR + Vision (фолбэк)', 'vision' => 'Vision (по страницам)'];
$rows_editable = $can_edit && in_array($spec['status'], ['review', 'done'], TRUE);
$pipeline_actions = $can_edit && in_array($spec['status'], ['failed', 'queued', 'running'], TRUE);
$spec_id = (int) $spec['id'];
$multi_file = count($files) > 1;
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
$issues = count(array_filter($rows, static fn ($r) => (string) $r['status'] !== 'ok' && ! $r['verified']));
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
        'actions' => array_merge(
            ! empty($file_ok) && ! $multi_file ? [['label' => 'Исходный PDF', 'href' => '/prx400_specflow/file/'.$spec_id, 'icon' => 'fa-file-pdf', 'role' => 'secondary']] : [],
            in_array($spec['status'], ['review', 'done'], TRUE) ? [
                ['label' => 'XLSX', 'href' => '/prx400_specflow/export/'.$spec_id.'?format=xlsx', 'icon' => 'fa-file-excel', 'role' => 'secondary'],
                ['label' => 'JSON для ERP', 'href' => '/prx400_specflow/export/'.$spec_id, 'icon' => 'fa-file-export', 'role' => 'secondary'],
                ['label' => 'CSV', 'href' => '/prx400_specflow/export/'.$spec_id.'?format=csv', 'icon' => 'fa-file-csv', 'role' => 'secondary'],
            ] : []
        ),
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
        <span class="prx-count">Режим: <?php echo HTML::chars($mode_ru[(string) $spec['parse_mode']] ?? (string) $spec['parse_mode']); ?></span>
<?php if ($spec['status'] === 'failed'): ?>
        <span class="block w-full text-sm text-rose-600 dark:text-rose-400"><i class="fas fa-circle-exclamation mr-1"></i><?php echo HTML::chars(Prx400_Messages::text((string) $spec['error'])); ?></span>
<?php endif; ?>
<?php if ($pipeline_actions AND empty($file_ok)): ?>
        <span class="block w-full text-sm text-gray-600 dark:text-gray-300"><i class="fas fa-circle-info mr-1 text-gray-400"></i>Исходного PDF нет в хранилище (файл до перехода на prx212 или удалён). Перезалейте его здесь — карточка и её история сохранятся:</span>
        <form method="post" action="/prx400_specflow/reupload/<?php echo (int) $spec['id']; ?>" enctype="multipart/form-data" class="mt-2 flex w-full flex-wrap items-center gap-2">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <input type="file" name="pdf[]" multiple accept="application/pdf,.pdf" required
                   class="prx-input max-w-[320px] file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white">
            <select name="parse_mode" class="prx-input max-w-[220px]">
<?php foreach ($parse_modes as $mode): ?>
                <option value="<?php echo HTML::chars($mode); ?>"<?php echo $spec['parse_mode'] === $mode ? ' selected' : ''; ?>><?php echo HTML::chars($mode_ru[$mode] ?? $mode); ?></option>
<?php endforeach; ?>
            </select>
            <select name="template_id" class="prx-input max-w-[240px]">
                <option value="">Шаблон: авто (по якорям)</option>
<?php foreach ($templates as $t): ?>
                <option value="<?php echo HTML::chars($t['id']); ?>"><?php echo HTML::chars($t['title']); ?></option>
<?php endforeach; ?>
            </select>
            <button class="prx-btn-cta prx-btn-sm" type="submit"><i class="fas fa-upload"></i>Перезалить и разобрать</button>
        </form>
<?php endif; ?>
<?php if ($spec['status'] === 'review'): ?>
        <span class="text-sm text-gray-500 dark:text-gray-400">Осталось спорных позиций: <a class="prx-link" href="/prx400_specflow/spec/<?php echo (int) $spec['id']; ?>?filter=disputed#positions"><?php echo (int) $issues; ?></a>. Подтвердите их (клавиши j/k/v) и завершите проверку.</span>
<?php endif; ?>
        <span class="ml-auto flex gap-2">
<?php if ($pipeline_actions AND ! empty($file_ok)): ?>
            <form method="post" action="/prx400_specflow/launch/<?php echo (int) $spec['id']; ?>" class="inline flex flex-wrap items-center gap-2"><input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <select name="parse_mode" class="prx-input max-w-[200px]">
<?php foreach ($parse_modes as $mode): ?>
                    <option value="<?php echo HTML::chars($mode); ?>"<?php echo $spec['parse_mode'] === $mode ? ' selected' : ''; ?>><?php echo HTML::chars($mode_ru[$mode] ?? $mode); ?></option>
<?php endforeach; ?>
                </select>
                <select name="template_id" class="prx-input max-w-[240px]"><option value="">Шаблон: авто (по якорям)</option><?php foreach ($templates as $t): ?><option value="<?php echo HTML::chars($t['id']); ?>"><?php echo HTML::chars($t['title']); ?></option><?php endforeach; ?></select>
                <button class="prx-btn-secondary prx-btn-sm" type="submit"<?php echo $spec['status'] !== 'failed' ? ' data-prx-confirm="Текущая задача разбора будет отменена и поставлена заново." data-prx-title="Перезапустить разбор?" data-prx-ok="Перезапустить"' : ''; ?>><i class="fas fa-rotate-right"></i><?php echo $spec['status'] === 'failed' ? 'Повторить разбор' : 'Перезапустить'; ?></button>
            </form>
<?php elseif ($can_edit AND $spec['status'] === 'review'): ?>
            <form method="post" action="/prx400_specflow/finish/<?php echo (int) $spec['id']; ?>" class="inline flex items-center gap-2"><input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><?php if ($issues > 0): ?><label class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300"><input type="checkbox" name="accept_issues" value="1" class="accent-emerald-600">принимаю с расхождениями (<?php echo (int) $issues; ?>)</label><?php endif; ?><button class="prx-btn-cta prx-btn-sm" type="submit"><i class="fas fa-check-double"></i>Завершить проверку</button></form>
<?php endif; ?>
        </span>
    </div>

<?php if ($can_edit AND in_array($spec['status'], ['review', 'done'], TRUE) AND ! empty($file_ok)): ?>
    <details class="mt-3 text-sm">
        <summary class="cursor-pointer text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"><i class="fas fa-rotate-right mr-1"></i>Разобрать заново другим режимом или шаблоном</summary>
        <form method="post" action="/prx400_specflow/launch/<?php echo $spec_id; ?>" class="mt-2 flex flex-wrap items-center gap-2">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <select name="parse_mode" class="prx-input max-w-[220px]">
<?php foreach ($parse_modes as $mode): ?>
                <option value="<?php echo HTML::chars($mode); ?>"<?php echo $spec['parse_mode'] === $mode ? ' selected' : ''; ?>><?php echo HTML::chars($mode_ru[$mode] ?? $mode); ?></option>
<?php endforeach; ?>
            </select>
            <select name="template_id" class="prx-input max-w-[240px]"><option value="">Шаблон: авто (по якорям)</option><?php foreach ($templates as $t): ?><option value="<?php echo HTML::chars($t['id']); ?>"<?php echo (string) $spec['template_id'] === (string) $t['id'] ? ' selected' : ''; ?>><?php echo HTML::chars($t['title']); ?></option><?php endforeach; ?></select>
            <button class="prx-btn-secondary prx-btn-sm" type="submit" data-prx-confirm="Позиции будут распознаны заново. Подтверждения и ручные коды перенесутся на те же строки; ручные строки сохранятся." data-prx-title="Разобрать заново?" data-prx-ok="Разобрать"><i class="fas fa-rotate-right"></i>Разобрать заново</button>
        </form>
    </details>
<?php endif; ?>

<?php if ( ! empty($duplicates)): ?>
    <div class="prx-flash prx-flash-info mt-4" role="note">
        <i class="fas fa-copy"></i>
        <span>Эти PDF уже загружались: <?php foreach ($duplicates as $i => $d): ?><?php echo $i > 0 ? ', ' : ''; ?><a class="prx-link" href="/prx400_specflow/spec/<?php echo (int) $d['spec_id']; ?>">№<?php echo (int) $d['spec_id']; ?></a> (<?php echo HTML::chars($d['original_name']); ?>, <?php echo date('d.m.Y', strtotime($d['created_at'])); ?>)<?php endforeach; ?>. Повторная загрузка допустима — проверьте, не дубль ли это.</span>
    </div>
<?php endif; ?>

<?php if ($multi_file): ?>
    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-copy mr-1.5 text-gray-400"></i>Файлы спецификации <span class="prx-count ml-1"><?php echo count($files); ?></span></h2>
        <ol class="mt-2 space-y-1 text-sm">
<?php foreach ($files as $f): ?>
            <li><span class="prx-count mr-1"><?php echo (int) $f['file_no']; ?></span><a class="prx-link" href="/prx400_specflow/file/<?php echo $spec_id; ?>?n=<?php echo (int) $f['file_no']; ?>" target="_blank" rel="noopener"><?php echo HTML::chars($f['original_name']); ?></a> <span class="text-xs text-gray-400"><?php echo number_format($f['size_bytes'] / 1048576, 1, ',', ' '); ?> МБ</span></li>
<?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

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
<?php if ( ! empty($m['via'])): ?>
            <span>Источник строк: <strong><?php echo ['vision' => 'vision', 'mixed' => 'OCR и vision (по файлам)'][$m['via']] ?? 'OCR/текстовый слой'; ?></strong></span>
<?php endif; ?>
        </div>
<?php foreach ((array) ($m['warnings'] ?? []) as $warning): ?>
        <p class="mt-2 text-sm text-amber-700 dark:text-amber-400"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo HTML::chars(Prx400_Messages::warning((string) $warning)); ?></p>
<?php endforeach; ?>
    </section>

<?php
$filter_ru = ['all' => 'Все', 'disputed' => 'Спорные', 'ok' => 'Совпали', 'review' => 'На подтверждении', 'new' => 'Новые', 'error' => 'Ошибки', 'verified' => 'Подтверждены'];
$filter_counts = [];
foreach ($filters as $fk) $filter_counts[$fk] = count(array_filter($rows, static fn (array $r): bool => Service_Prx400_Rows::passes($r, $fk)));
$visible = array_values(array_filter($rows, static fn (array $r): bool => Service_Prx400_Rows::passes($r, $filter)));
$has_children = [];
foreach ($rows as $r) if ($r['parent_id'] !== NULL) $has_children[(int) $r['parent_id']] = TRUE;
$via_ru = ['memory' => 'из памяти', 'code' => 'по обозначению', 'manual' => 'вручную', 'fuzzy' => 'похожее'];
$origin_ru = ['manual' => 'добавлена вручную', 'split' => 'часть разделённой строки'];
$num = static fn ($v, int $dec = 2): string => $v === NULL ? '—' : number_format((float) $v, $dec, ',', ' ');
$qty_txt = static fn ($v): string => $v === NULL ? '—' : rtrim(rtrim(number_format((float) $v, 4, ',', ' '), '0'), ',');
$back = '<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'"><input type="hidden" name="back_spec" value="'.$spec_id.'"><input type="hidden" name="back_filter" value="'.HTML::chars($filter).'">';
$pos_label = static fn (array $r): string => $r['pos_path'] !== '' ? $r['pos_path'] : ($r['pos'] !== NULL ? (string) $r['pos'] : '—');
?>
    <section class="prx-card mt-5 overflow-x-auto p-0" id="positions">
        <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
            <h2 class="prx-h3">Позиции <span class="prx-count ml-1"><?php echo count($rows); ?></span>
                <span class="ml-2 text-xs font-normal text-gray-400">зелёный — найдено в НСИ · жёлтый — низкая уверенность · красный — новая позиция или ошибка</span>
            </h2>
<?php if ( ! empty($rows)): ?>
            <nav class="mt-3 flex flex-wrap gap-1.5" aria-label="Фильтр позиций">
<?php foreach ($filters as $fk): if ($fk !== 'all' AND $filter_counts[$fk] === 0 AND $filter !== $fk) continue; ?>
                <a href="/prx400_specflow/spec/<?php echo $spec_id; ?><?php echo $fk !== 'all' ? '?filter='.$fk : ''; ?>#positions" class="<?php echo $filter === $fk ? 'prx-btn-process' : 'prx-btn-secondary'; ?> prx-btn-sm"<?php echo $filter === $fk ? ' aria-current="true"' : ''; ?>><?php echo HTML::chars($filter_ru[$fk] ?? $fk); ?> <span class="tabular-nums opacity-70"><?php echo (int) $filter_counts[$fk]; ?></span></a>
<?php endforeach; ?>
            </nav>
<?php endif; ?>
<?php if ($rows_editable): ?>
            <div class="mt-3 flex flex-wrap items-start gap-3">
<?php if ($filter_counts['ok'] > 0): ?>
                <form method="post" action="/prx400_specflow/bulk_verify/<?php echo $spec_id; ?>" class="flex flex-wrap items-center gap-2 text-sm">
                    <?php echo $back; ?>
                    <span class="text-gray-600 dark:text-gray-300">Подтвердить все совпадения с уверенностью от</span>
                    <input name="min_score" value="<?php echo HTML::chars(rtrim(rtrim(number_format((float) $bulk_verify_min, 2, '.', ''), '0'), '.')); ?>" inputmode="decimal" class="prx-input w-20" aria-label="Порог уверенности (0..1)">
                    <button class="prx-btn-secondary prx-btn-sm" type="submit" data-prx-confirm="Строки «совпало с НСИ» с уверенностью не ниже порога будут отмечены проверенными. Отметку можно снять по строке." data-prx-title="Подтвердить пачкой?" data-prx-ok="Подтвердить"><i class="fas fa-check-double"></i>Подтвердить</button>
                </form>
<?php endif; ?>
                <details class="text-sm">
                    <summary class="prx-btn-secondary prx-btn-sm cursor-pointer list-none"><i class="fas fa-plus"></i>Добавить строку</summary>
                    <form method="post" action="/prx400_specflow/row_add/<?php echo $spec_id; ?>" class="mt-2 grid max-w-3xl gap-2 rounded-lg border border-gray-200 bg-white p-3 sm:grid-cols-6 dark:border-gray-700 dark:bg-gray-900">
                        <?php echo $back; ?>
                        <label class="sm:col-span-1"><span class="prx-label">№ (1.2.3)</span><input class="prx-input mt-1 w-full" name="pos_path" placeholder="—"></label>
                        <label class="sm:col-span-5"><span class="prx-label">Наименование *</span><input class="prx-input mt-1 w-full" name="raw_name" required></label>
                        <label class="sm:col-span-2"><span class="prx-label">Тип, марка</span><input class="prx-input mt-1 w-full" name="code_doc"></label>
                        <label><span class="prx-label">Кол-во</span><input class="prx-input mt-1 w-full" name="qty" inputmode="decimal"></label>
                        <label><span class="prx-label">Ед.</span><input class="prx-input mt-1 w-full" name="unit" placeholder="шт."></label>
                        <label><span class="prx-label">Цена</span><input class="prx-input mt-1 w-full" name="unit_price" inputmode="decimal"></label>
                        <label><span class="prx-label">Сумма</span><input class="prx-input mt-1 w-full" name="total_price" inputmode="decimal"></label>
                        <label class="sm:col-span-4"><span class="prx-label">Вставить</span>
                            <select name="after_row_id" class="prx-input mt-1 w-full"><option value="">в конец</option><?php foreach ($rows as $r): ?><option value="<?php echo (int) $r['id']; ?>">после <?php echo HTML::chars(Service_Prx400_Rows::label($r)); ?></option><?php endforeach; ?></select></label>
                        <div class="flex items-end sm:col-span-2"><button class="prx-btn-cta w-full justify-center" type="submit"><i class="fas fa-plus"></i>Добавить</button></div>
                    </form>
                </details>
                <span class="ml-auto self-center text-xs text-gray-400" title="Клавиши работают, когда курсор не в поле ввода"><kbd>j</kbd>/<kbd>k</kbd> — следующая/предыдущая спорная · <kbd>v</kbd> — подтвердить · <kbd>/</kbd> — найти в НСИ · <kbd>e</kbd> — исправить</span>
            </div>
<?php endif; ?>
        </div>
<?php if (empty($rows)): ?>
        <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Позиций пока нет — разбор ещё не завершён или не нашёл таблицу.</p>
<?php elseif (empty($visible)): ?>
        <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">В этом фильтре строк нет. <a class="prx-link" href="/prx400_specflow/spec/<?php echo $spec_id; ?>#positions">Показать все</a></p>
<?php else: ?>
        <table class="prx-table" data-prx400-rows>
            <thead><tr><th>№</th><th>Наименование</th><th>Тип, марка</th><th>Кол-во</th><th>Ед.</th><th>Цена</th><th>Сумма</th><th>НСИ</th><th></th></tr></thead>
            <tbody>
<?php foreach ($visible as $r):
    $rid = (int) $r['id'];
    $rmatch = is_array($r['match']) ? $r['match'] : [];
    $code = (string) ($rmatch['code'] ?? '');
    $pending = (string) $r['status'] !== 'ok' && ! $r['verified'];
    $flag_types = array_map(static fn ($f): string => (string) ($f['type'] ?? ''), (array) $r['flags']);
    $row_error = (bool) array_intersect($flag_types, ['math_row', 'unit_fractional', 'unit_mismatch']);
    $level = min(8, (int) $r['level']); ?>
            <tr id="row-<?php echo $rid; ?>" data-prx400-row data-pending="<?php echo $pending ? '1' : '0'; ?>" tabindex="-1" class="<?php echo HTML::chars($r['verified'] ? 'border-l-4 border-emerald-400' : ($row_style[(string) $r['status']] ?? '')); ?> focus:outline-none">
                <td class="whitespace-nowrap font-mono tabular-nums"><?php echo HTML::chars($pos_label($r)); ?>
<?php foreach ((array) $r['flags'] as $f): $ft = (string) ($f['type'] ?? ''); if ($ft === 'gap'): ?>
                    <i class="fas fa-triangle-exclamation text-amber-500" title="Пропуск нумерации: после позиции <?php echo HTML::chars((string) ($f['after_pos'] ?? '')); ?>"></i>
<?php elseif ($ft === 'unit_fractional'): ?>
                    <i class="fas fa-triangle-exclamation text-amber-500" title="Дробное количество для штучной единицы"></i>
<?php elseif ($ft === 'unit_mismatch'): ?>
                    <i class="fas fa-ruler text-amber-500" title="Единица строки не совпадает с единицей позиции НСИ<?php echo isset($f['expected']) ? ' ('.HTML::chars((string) $f['expected']).')' : ''; ?>"></i>
<?php endif; endforeach; ?>
<?php if ($r['page'] !== NULL AND ! empty($file_ok)): ?>
                    <a class="block text-[11px] font-sans text-gray-400 hover:text-blue-600" href="/prx400_specflow/file/<?php echo $spec_id; ?>?n=<?php echo (int) $r['file_no']; ?>#page=<?php echo (int) $r['page']; ?>" target="_blank" rel="noopener" title="Открыть страницу в PDF"><?php echo $multi_file ? 'ф.'.(int) $r['file_no'].' · ' : ''; ?>стр. <?php echo (int) $r['page']; ?></a>
<?php endif; ?>
                </td>
                <td class="max-w-[360px]" style="padding-left: <?php echo number_format(0.75 + 1.25 * $level, 2, '.', ''); ?>rem">
                    <span class="block text-sm text-gray-900 dark:text-white"><?php echo $level > 0 ? '<span class="mr-1 text-gray-300 dark:text-gray-600">└</span>' : ''; ?><?php echo isset($has_children[$rid]) ? '<i class="fas fa-cubes mr-1 text-xs text-gray-400" title="Сборка: в закупочную ведомость идут её составные части"></i>' : ''; ?><?php echo HTML::chars((string) $r['raw_name']); ?></span>
<?php if ( ! empty($r['section'])): ?><span class="text-xs text-gray-400"><?php echo HTML::chars((string) $r['section']); ?></span><?php endif; ?>
<?php if (isset($origin_ru[$r['origin']])): ?><span class="ml-1 prx-count text-[11px]"><?php echo HTML::chars($origin_ru[$r['origin']]); ?></span><?php endif; ?>
                </td>
                <td class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $r['code_doc']); ?></td>
                <td class="tabular-nums"><?php echo $qty_txt($r['qty']); ?></td>
                <td><?php echo HTML::chars((string) $r['unit']); ?></td>
                <td class="tabular-nums"><?php echo $num($r['unit_price']); ?></td>
                <td class="tabular-nums"><?php echo $num($r['total_price']); ?>
<?php foreach ((array) $r['flags'] as $f): if ((string) ($f['type'] ?? '') === 'math_row'): ?>
                    <i class="fas fa-circle-exclamation text-rose-500" title="Ожидалось <?php echo $num($f['expected'] ?? NULL); ?>"></i>
<?php endif; endforeach; ?>
                </td>
                <td class="min-w-[180px]">
<?php if ($code !== ''): $via = (string) ($rmatch['via'] ?? (empty($rmatch['manual']) ? 'fuzzy' : 'manual')); ?>
                    <span class="prx-badge prx-badge-ok"><span class="prx-dot"></span><?php echo HTML::chars($code); ?></span>
                    <span class="block text-xs text-gray-400"><?php echo HTML::chars((string) ($rmatch['name'] ?? '')); ?> · <?php echo round(100 * (float) ($rmatch['score'] ?? 0)); ?>% · <?php echo HTML::chars($via_ru[$via] ?? $via); ?></span>
<?php if ($rows_editable AND ! empty($rmatch['manual'])): ?>
                    <form method="post" action="/prx400_specflow/code/<?php echo $rid; ?>" class="inline"><?php echo $back; ?><input type="hidden" name="clear" value="1"><button type="submit" class="text-xs text-gray-400 hover:text-rose-600 hover:underline" title="Снять ручной код и забыть решение — строка будет сопоставлена заново">снять код</button></form>
<?php endif; ?>
<?php else: ?>
<?php if ((string) $r['status'] === 'review'): ?>
                    <span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>подтвердите</span>
<?php elseif ((string) $r['status'] === 'error'): ?>
                    <span class="prx-badge prx-badge-err"><span class="prx-dot"></span>ошибка</span>
<?php else: ?>
                    <span class="prx-badge prx-badge-err"><span class="prx-dot"></span>новая</span>
<?php endif; ?>
<?php endif; ?>
<?php $alts = array_values(array_filter((array) ($rmatch['alternatives'] ?? []), static fn ($a): bool => is_array($a) && (string) ($a['code'] ?? '') !== '' && (string) $a['code'] !== $code)); if ($alts): ?>
                    <span class="mt-1 block text-xs text-gray-400"><?php echo $code !== '' ? 'другие' : 'похожие'; ?>: <?php foreach (array_slice($alts, 0, 3) as $alt): ?><?php if ( ! $rows_editable): ?><span class="font-mono" title="<?php echo HTML::chars((string) $alt['name']); ?> (<?php echo round(100 * (float) $alt['score']); ?>%)"><?php echo HTML::chars((string) $alt['code']); ?></span> <?php continue; endif; ?><form method="post" action="/prx400_specflow/code/<?php echo $rid; ?>" class="inline"><?php echo $back; ?><input type="hidden" name="code" value="<?php echo HTML::chars((string) $alt['code']); ?>"><button type="submit" class="font-mono text-blue-600 hover:underline dark:text-blue-400" title="Назначить: <?php echo HTML::chars((string) $alt['name']); ?> (<?php echo round(100 * (float) $alt['score']); ?>%)"><?php echo HTML::chars((string) $alt['code']); ?></button></form> <?php endforeach; ?></span>
<?php endif; ?>
<?php if ($rows_editable): ?>
                    <details class="mt-1 text-xs" data-prx400-search-box>
                        <summary class="cursor-pointer text-blue-600 hover:underline dark:text-blue-400"><i class="fas fa-magnifying-glass mr-0.5"></i>найти в НСИ</summary>
                        <form method="post" action="/prx400_specflow/code/<?php echo $rid; ?>" class="mt-1 w-72">
                            <?php echo $back; ?>
                            <input type="search" name="code" autocomplete="off" required class="prx-input w-full text-xs" placeholder="наименование или код, Enter — назначить код" data-prx400-search data-seed="<?php echo HTML::chars(mb_substr((string) $r['raw_name'], 0, 80)); ?>">
                            <div class="mt-1 max-h-64 space-y-0.5 overflow-y-auto" data-prx400-results></div>
                        </form>
<?php if ($catalog_writable AND $code === ''): ?>
                        <details class="mt-1">
                            <summary class="cursor-pointer text-gray-500 hover:underline">нет в справочнике — создать позицию</summary>
                            <form method="post" action="/prx400_specflow/catalog_item/<?php echo $rid; ?>" class="mt-1 w-72 space-y-1.5">
                                <?php echo $back; ?>
                                <input class="prx-input w-full text-xs" name="code" required placeholder="код НСИ *">
                                <input class="prx-input w-full text-xs" name="name" value="<?php echo HTML::chars((string) $r['raw_name']); ?>" required>
                                <input class="prx-input w-full text-xs" name="unit" value="<?php echo HTML::chars((string) $r['unit']); ?>" placeholder="ед.">
                                <button class="prx-btn-cta prx-btn-sm w-full justify-center" type="submit"><i class="fas fa-plus"></i>Создать и привязать</button>
                            </form>
                        </details>
<?php endif; ?>
                    </details>
<?php endif; ?>
                </td>
                <td class="text-right whitespace-nowrap">
<?php if ($r['verified']): ?>
                    <span class="mr-1 text-xs text-emerald-600 dark:text-emerald-400" title="Подтверждено <?php echo HTML::chars((string) ($actor_names[(int) ($r['verified_by'] ?? 0)] ?? '')); ?>"><i class="fas fa-circle-check"></i></span>
<?php endif; ?>
<?php if ($rows_editable): ?>
                    <form method="post" action="/prx400_specflow/verify/<?php echo $rid; ?>" class="inline"><?php echo $back; ?><input type="hidden" name="verified" value="<?php echo $r['verified'] ? '0' : '1'; ?>"><button type="submit" class="prx-btn-secondary prx-btn-sm" data-prx400-verify title="<?php echo $r['verified'] ? 'Снять отметку «Проверено»' : 'Подтвердить строку (v)'; ?>"<?php echo ! $r['verified'] && $row_error ? ' data-prx-confirm="Строка с ошибкой (арифметика/единицы). Подтвердить как есть?" data-prx-title="Подтвердить строку с ошибкой?" data-prx-ok="Подтвердить"' : ''; ?>><i class="fas <?php echo $r['verified'] ? 'fa-rotate-left' : 'fa-check'; ?>"></i><span class="sr-only"><?php echo $r['verified'] ? 'Снять отметку' : 'Подтвердить'; ?></span></button></form>
                    <details class="inline-block text-left" data-prx400-edit>
                        <summary class="prx-btn-secondary prx-btn-sm cursor-pointer list-none" title="Исправить, разделить, вставить, удалить (e)"><i class="fas fa-pencil"></i><span class="sr-only">Исправить</span></summary>
                        <div class="mt-2 w-80 space-y-3 whitespace-normal rounded-lg border border-gray-200 bg-white p-3 text-left dark:border-gray-700 dark:bg-gray-900">
                            <form method="post" action="/prx400_specflow/edit/<?php echo $rid; ?>" class="space-y-2">
                                <?php echo $back; ?>
                                <label class="block"><span class="prx-label">Наименование</span><textarea class="prx-input mt-1 w-full" name="raw_name" rows="2"><?php echo HTML::chars((string) $r['raw_name']); ?></textarea></label>
                                <label class="block"><span class="prx-label">Тип, марка</span><input class="prx-input mt-1 w-full" name="code_doc" value="<?php echo HTML::chars((string) $r['code_doc']); ?>"></label>
                                <div class="grid grid-cols-2 gap-2">
                                    <label><span class="prx-label">Кол-во</span><input class="prx-input mt-1 w-full" name="qty" inputmode="decimal" value="<?php echo $r['qty'] !== NULL ? HTML::chars((string) (float) $r['qty']) : ''; ?>" placeholder="—"></label>
                                    <label><span class="prx-label">Ед.</span><input class="prx-input mt-1 w-full" name="unit" value="<?php echo HTML::chars((string) $r['unit']); ?>"></label>
                                    <label><span class="prx-label">Цена</span><input class="prx-input mt-1 w-full" name="unit_price" inputmode="decimal" value="<?php echo $r['unit_price'] !== NULL ? HTML::chars((string) (float) $r['unit_price']) : ''; ?>" placeholder="—"></label>
                                    <label><span class="prx-label">Сумма</span><input class="prx-input mt-1 w-full" name="total_price" inputmode="decimal" value="<?php echo $r['total_price'] !== NULL ? HTML::chars((string) (float) $r['total_price']) : ''; ?>" placeholder="—"></label>
                                </div>
                                <label class="block"><span class="prx-label">Раздел</span><input class="prx-input mt-1 w-full" name="section" value="<?php echo HTML::chars((string) $r['section']); ?>"></label>
                                <button class="prx-btn-cta w-full justify-center" type="submit"><i class="fas fa-floppy-disk"></i>Сохранить</button>
                            </form>
<?php if ($r['qty'] !== NULL AND (float) $r['qty'] > 0): ?>
                            <form method="post" action="/prx400_specflow/split/<?php echo $rid; ?>" class="flex items-end gap-2 border-t border-gray-100 pt-3 dark:border-gray-700/60">
                                <?php echo $back; ?>
                                <label class="flex-1"><span class="prx-label">Разделить <?php echo $qty_txt($r['qty']); ?> на части</span><input class="prx-input mt-1 w-full" name="quantities" placeholder="например 2; 3" required></label>
                                <button class="prx-btn-secondary prx-btn-sm" type="submit"><i class="fas fa-code-branch"></i>Разделить</button>
                            </form>
<?php endif; ?>
                            <form method="post" action="/prx400_specflow/row_add/<?php echo $spec_id; ?>" class="space-y-2 border-t border-gray-100 pt-3 dark:border-gray-700/60">
                                <?php echo $back; ?>
                                <input type="hidden" name="<?php echo isset($has_children[$rid]) ? 'parent_row_id' : 'after_row_id'; ?>" value="<?php echo $rid; ?>">
                                <span class="prx-label"><?php echo isset($has_children[$rid]) ? 'Добавить в эту сборку' : 'Вставить строку после этой'; ?></span>
                                <input class="prx-input w-full" name="raw_name" placeholder="наименование *" required>
                                <div class="grid grid-cols-3 gap-2"><input class="prx-input" name="pos_path" placeholder="№"><input class="prx-input" name="qty" placeholder="кол-во" inputmode="decimal"><input class="prx-input" name="unit" placeholder="ед."></div>
                                <button class="prx-btn-secondary prx-btn-sm w-full justify-center" type="submit"><i class="fas fa-plus"></i>Вставить</button>
                            </form>
<?php if ( ! isset($has_children[$rid])): ?>
                            <form method="post" action="/prx400_specflow/row_delete/<?php echo $rid; ?>" class="border-t border-gray-100 pt-3 dark:border-gray-700/60"><?php echo $back; ?><button type="submit" class="prx-btn-danger prx-btn-sm w-full justify-center" data-prx-confirm="Строка исчезнет из спецификации и выгрузки; запись останется в журнале правок." data-prx-title="Удалить строку?" data-prx-ok="Удалить"><i class="fas fa-trash"></i>Удалить строку</button></form>
<?php endif; ?>
                        </div>
                    </details>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php endif; ?>
    </section>

<?php if ( ! empty($edits)): ?>
<?php $field_ru = ['raw_name' => 'наименование', 'code_doc' => 'тип, марка', 'qty' => 'кол-во', 'unit' => 'ед.', 'unit_price' => 'цена',
    'total_price' => 'сумма', 'section' => 'раздел', 'code' => 'код НСИ', 'verified' => 'проверено', 'row_add' => 'строка добавлена',
    'row_delete' => 'строка удалена', 'split' => 'разделение', 'bulk_verify' => 'пакетное подтверждение', 'catalog_add' => 'новая позиция НСИ',
    'finish' => 'проверка завершена', 'relaunch' => 'разобрано заново']; ?>
    <section class="prx-card mt-5 overflow-x-auto p-0">
        <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
            <h2 class="prx-h3"><i class="fas fa-clock-rotate-left mr-1.5 text-gray-400"></i>Журнал правок <span class="prx-count ml-1"><?php echo count($edits); ?></span></h2>
        </div>
        <table class="prx-table">
            <thead><tr><th>Когда</th><th>Кто</th><th>Позиция</th><th>Действие</th><th>Было</th><th>Стало</th></tr></thead>
            <tbody>
<?php foreach ($edits as $e): $actor = (int) ($e['actor_id'] ?? 0);
    $label = (string) ($e['row_label'] ?? '') !== '' ? (string) $e['row_label'] : Service_Prx400_Rows::label(array_merge($e, ['raw_name' => ''])); ?>
            <tr>
                <td class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400"><?php echo date('d.m.Y H:i', strtotime((string) $e['created_at'])); ?></td>
                <td class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400"><?php echo $actor !== 0 ? HTML::chars((string) ($actor_names[$actor] ?? '#'.$actor)) : '—'; ?></td>
                <td class="max-w-[240px] truncate font-mono text-xs tabular-nums"><?php if ($e['row_id'] !== NULL): ?><a class="prx-link" href="#row-<?php echo (int) $e['row_id']; ?>"><?php echo HTML::chars(rtrim($label, ' ·')); ?></a><?php else: ?><span class="text-gray-400" title="<?php echo HTML::chars((string) ($e['row_label'] ?? '')); ?>"><?php echo HTML::chars((string) ($e['row_label'] ?? '') ?: '—'); ?></span><?php endif; ?></td>
                <td class="text-xs"><?php echo HTML::chars($field_ru[(string) $e['field']] ?? (string) $e['field']); ?></td>
                <td class="max-w-[260px] text-xs text-gray-500 line-through dark:text-gray-400"><?php echo HTML::chars((string) $e['old_value']); ?></td>
                <td class="max-w-[260px] text-xs text-gray-900 dark:text-white"><?php echo HTML::chars((string) $e['new_value']); ?></td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<?php if ($catalog_count === 0): ?>
    <p class="prx-hint mt-4">Справочник НСИ пуст — все позиции помечены «новая». <a href="/prx400_specflow/catalog" class="prx-link">Наполните справочник</a> — следующие разборы привяжут коды автоматически, а здесь код можно назначить вручную.</p>
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
})();

(function () {
    'use strict';
    // POST-формы карточки: кнопка занята на время запроса — двойной запуск исключён.
    document.querySelectorAll('form[method="post"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn && window.PRX) PRX.busy(btn, true);
        });
    });

    // Поиск кода в НСИ по строке: подсказки из /prx400_specflow/catalog_search,
    // клик — назначить код (форма code/<row>); Enter — назначить введённый код.
    function bindSearch(box) {
        var input = box.querySelector('[data-prx400-search]');
        var results = box.querySelector('[data-prx400-results]');
        if (!input || !results) return;
        var timer = null, seq = 0;
        function render(items, error) {
            results.replaceChildren();
            if (error) {
                var p = document.createElement('p');
                p.className = 'text-rose-600';
                p.textContent = error;
                results.appendChild(p);
                return;
            }
            if (!items.length) {
                var empty = document.createElement('p');
                empty.className = 'text-gray-400';
                empty.textContent = input.value.trim().length < 2 ? 'Введите хотя бы 2 символа' : 'Ничего не нашлось';
                results.appendChild(empty);
                return;
            }
            items.forEach(function (item) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'block w-full rounded px-1.5 py-1 text-left hover:bg-blue-50 dark:hover:bg-blue-950/40';
                var code = document.createElement('span');
                code.className = 'font-mono text-blue-600 dark:text-blue-400';
                code.textContent = item.code;
                var name = document.createElement('span');
                name.className = 'ml-1 text-gray-700 dark:text-gray-200';
                name.textContent = item.name + (item.unit ? ' · ' + item.unit : '') + ' · ' + Math.round(100 * (item.score || 0)) + '%';
                b.appendChild(code);
                b.appendChild(name);
                b.addEventListener('click', function () {
                    input.value = item.code;
                    input.form.requestSubmit ? input.form.requestSubmit() : input.form.submit();
                });
                results.appendChild(b);
            });
        }
        function search() {
            var q = input.value.trim();
            var my = ++seq;
            if (q.length < 2) { render([]); return; }
            fetch('/prx400_specflow/catalog_search?q=' + encodeURIComponent(q), { cache: 'no-store', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (my !== seq) return;
                    render(data.items || [], data.error ? data.error.message : null);
                })
                .catch(function () { if (my === seq) render([], 'Справочник не ответил — попробуйте ещё раз'); });
        }
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 250); });
        box.addEventListener('toggle', function () {
            if (!box.open) return;
            if (!input.value && input.getAttribute('data-seed')) input.value = input.getAttribute('data-seed');
            input.focus();
            input.select();
            search();
        });
    }
    document.querySelectorAll('[data-prx400-search-box]').forEach(bindSearch);

    // Клавиатура: j/k — следующая/предыдущая спорная строка (нет спорных — любая),
    // v — подтвердить текущую, / — найти в НСИ, e — исправить, Esc — закрыть.
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-prx400-row]'));
    if (!rows.length) return;
    var current = null;
    function focusRow(row) {
        if (current) current.classList.remove('ring-2', 'ring-blue-400');
        current = row;
        row.classList.add('ring-2', 'ring-blue-400');
        row.focus({ preventScroll: true });
        row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
    function step(dir) {
        var pool = rows.filter(function (r) { return r.getAttribute('data-pending') === '1'; });
        if (!pool.length) pool = rows;
        var idx = current ? rows.indexOf(current) : (dir > 0 ? -1 : rows.length);
        for (var i = idx + dir; i >= 0 && i < rows.length; i += dir) {
            if (pool.indexOf(rows[i]) !== -1) { focusRow(rows[i]); return; }
        }
        focusRow(dir > 0 ? pool[0] : pool[pool.length - 1]);
    }
    if (location.hash.indexOf('#row-') === 0) {
        var target = document.getElementById(location.hash.slice(1));
        if (target && rows.indexOf(target) !== -1) focusRow(target);
    }
    document.addEventListener('keydown', function (ev) {
        if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
        var tag = (ev.target && ev.target.tagName) || '';
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (ev.target && ev.target.isContentEditable)) {
            if (ev.key === 'Escape' && ev.target.closest('details')) { ev.target.closest('details').open = false; if (current) current.focus(); }
            return;
        }
        if (ev.key === 'j' || ev.key === 'ArrowDown' && ev.shiftKey) { ev.preventDefault(); step(1); }
        else if (ev.key === 'k' || ev.key === 'ArrowUp' && ev.shiftKey) { ev.preventDefault(); step(-1); }
        else if (current && ev.key === 'v') { var b = current.querySelector('[data-prx400-verify]'); if (b) { ev.preventDefault(); b.click(); } }
        else if (current && ev.key === '/') { var s = current.querySelector('[data-prx400-search-box]'); if (s) { ev.preventDefault(); s.open = true; } }
        else if (current && ev.key === 'e') { var d = current.querySelector('[data-prx400-edit]'); if (d) { ev.preventDefault(); d.open = true; var f = d.querySelector('textarea, input:not([type=hidden])'); if (f) f.focus(); } }
        else if (ev.key === 'Escape') { document.querySelectorAll('[data-prx400-row] details[open]').forEach(function (d) { d.open = false; }); }
    });
})();
</script>
