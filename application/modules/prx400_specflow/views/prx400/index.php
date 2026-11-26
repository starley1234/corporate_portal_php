<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx400_specflow — список спецификаций + загрузка PDF.
 *
 * Переменные: $specs, $templates, $flash, $csrf.
 */
$status_ru = [
    'queued' => ['prx-badge-wait', 'в очереди'],
    'running' => ['prx-badge-run', 'разбор идёт'],
    'review' => ['prx-badge-info', 'на проверке'],
    'done' => ['prx-badge-ok', 'готово'],
    'failed' => ['prx-badge-err', 'ошибка'],
];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Оцифровка спецификаций']],
        'title' => 'Оцифровка спецификаций',
        'pill' => ['prx400 · спецификации и BOM', 'fa-table-list'],
        'lead' => 'Скан спецификации или ведомости → семантический шаблон → проверенная таблица позиций с математикой → коды НСИ → JSON для ERP.',
    ]); ?>

    <?php echo View::factory('partials/flash', ['flash' => $flash]); ?>

    <div class="prx-seg mt-5" role="tablist" aria-label="Разделы оцифровки спецификаций">
        <a class="prx-seg-item is-active" role="tab" aria-selected="true" href="/prx400_specflow"><i class="fas fa-file-pdf mr-1.5"></i>Спецификации <span class="prx-count"><?php echo count($specs); ?></span></a>
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx400_specflow/catalog"><i class="fas fa-book mr-1.5"></i>Справочник НСИ <span class="prx-count"><?php echo (int) $catalog_count; ?></span></a>
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx400_specflow/templates"><i class="fas fa-layer-group mr-1.5"></i>Шаблоны</a>
    </div>

    <div class="mt-5 gap-5 space-y-5 lg:grid lg:grid-cols-3 lg:items-start lg:space-y-0">
        <section class="prx-card lg:col-span-2">
            <h2 class="prx-h3"><i class="fas fa-file-pdf mr-1.5 text-gray-400"></i>Загрузить спецификацию</h2>
            <form method="post" action="/prx400_specflow" enctype="multipart/form-data" class="mt-4 space-y-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <label class="block">
                    <span class="prx-label">PDF со спецификацией (текстовый слой или скан)</span>
                    <input type="file" name="pdf" accept="application/pdf,.pdf" required
                           class="prx-input mt-1 w-full file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white">
                </label>
                <fieldset class="block">
                    <span class="prx-label">Режим разбора</span>
                    <div class="mt-1 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                        <label class="flex items-start gap-2"><input type="radio" name="parse_mode" value="ocr" checked class="mt-1 accent-emerald-600"><span><strong>Точный OCR</strong> — детерминированный разбор текстового слоя, без LLM.</span></label>
                        <label class="flex items-start gap-2"><input type="radio" name="parse_mode" value="ocr_vision" class="mt-1 accent-emerald-600"><span><strong>OCR + Vision</strong> — OCR, а при пустом результате автоматический vision-разбор страниц.</span></label>
                        <label class="flex items-start gap-2"><input type="radio" name="parse_mode" value="vision" class="mt-1 accent-emerald-600"><span><strong>Vision</strong> — распознавание страниц LLM по изображениям (для сканов, где OCR даёт «результат 0»).</span></label>
                    </div>
                </fieldset>
                <p class="prx-hint">Шаблон разбора подбирается автоматически по семантическим якорям. Математика строк и итогов сверяется до передачи в ERP. Библиотека шаблонов: <?php foreach ($templates as $t): ?><span class="prx-count mr-1"><?php echo HTML::chars($t['title']); ?></span><?php endforeach; ?></p>
                <button class="prx-btn-cta" type="submit"><i class="fas fa-upload"></i>Загрузить и разобрать</button>
            </form>
        </section>

        <aside class="prx-card">
            <h2 class="prx-h3">Как это работает</h2>
            <ol class="mt-3 space-y-2.5 text-sm text-gray-600 dark:text-gray-300">
                <li class="flex gap-2"><span class="prx-count">1</span><span>Загрузка PDF — разбор идёт в очереди воркеров.</span></li>
                <li class="flex gap-2"><span class="prx-count">2</span><span>Воркер подбирает шаблон, склеивает многострочные наименования, сверяет арифметику.</span></li>
                <li class="flex gap-2"><span class="prx-count">3</span><span>Позиции сопоставляются с НСИ: зелёные — автоматически, жёлтые — на ваше подтверждение, красные — новая позиция или ошибка.</span></li>
                <li class="flex gap-2"><span class="prx-count">4</span><span>Подтвердите спорные строки и выгрузите JSON (или CSV) для ERP.</span></li>
            </ol>
        </aside>
    </div>

    <section class="prx-card mt-5 overflow-x-auto p-0">
        <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
            <h2 class="prx-h3">Спецификации <span class="prx-count ml-1"><?php echo count($specs); ?></span></h2>
        </div>
<?php if (empty($specs)): ?>
        <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Пока пусто. Загрузите первый PDF — и таблица появится здесь. Перед разбором наполните <a href="/prx400_specflow/catalog" class="prx-link">справочник НСИ</a> — иначе все позиции будут помечены «новая».</p>
<?php else: ?>
        <table class="prx-table">
            <thead><tr><th>Файл</th><th>Владелец</th><th>Шаблон</th><th>№</th><th>Статус</th><th>Позиции</th><th>Обновлена</th><th></th></tr></thead>
            <tbody>
<?php foreach ($specs as $s): list($badge, $label) = $status_ru[(string) $s['status']] ?? ['prx-badge-wait', $s['status']]; ?>
            <tr>
                <td class="max-w-[280px]"><span class="block truncate font-semibold text-gray-900 dark:text-white" title="<?php echo HTML::chars($s['original_name']); ?>"><?php echo HTML::chars($s['original_name']); ?></span></td>
                <td class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $s['owner_name']); ?></td>
                <td class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $s['template_title']); ?></td>
                <td class="font-mono text-xs"><?php echo HTML::chars((string) $s['spec_number']); ?></td>
                <td><span class="<?php echo HTML::chars($badge); ?>"><span class="prx-dot"></span><?php echo HTML::chars($label); ?></span></td>
                <td class="tabular-nums"><?php echo (int) $s['rows_total']; ?><?php echo (int) $s['rows_issue'] > 0 ? ' <span class="text-amber-600 dark:text-amber-400">('.(int) $s['rows_issue'].' спор.)</span>' : ''; ?></td>
                <td class="text-xs text-gray-500 dark:text-gray-400"><?php echo date('d.m.Y H:i', strtotime((string) $s['updated_at'])); ?></td>
                <td class="text-right whitespace-nowrap">
                    <a href="/prx400_specflow/spec/<?php echo (int) $s['id']; ?>" class="prx-btn-secondary prx-btn-sm">Открыть</a>
                    <form method="post" action="/prx400_specflow/delete/<?php echo (int) $s['id']; ?>" class="inline"><input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><button type="submit" class="prx-btn-danger prx-btn-sm" data-prx-confirm="Спецификация, её позиции и исходный файл будут удалены безвозвратно." data-prx-title="Удалить спецификацию?" data-prx-ok="Удалить"><i class="fas fa-trash"></i><span class="sr-only">Удалить</span></button></form>
                </td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php endif; ?>
    </section>
</div>
