<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx400_specflow/quality — качество разбора в бою (то же, что prx400:eval):
 * исходы спецификаций, откуда коды НСИ, замены автокодов оператором, какие
 * поля правят и кандидаты в НСИ (позиции без кода, повторяющиеся в разных
 * спецификациях). Только чтение; данные — по всем владельцам (prx400.view).
 *
 * Переменные: $days, $report (Service_Prx400_Quality::report), $can_edit, $catalog_writable.
 */
$rows = $report['rows'];
$rates = $report['rates'];
$pct = static fn ($v): string => number_format(100 * (float) $v, 1, ',', ' ').' %';
$total_specs = array_sum(array_column($report['specs'], 'n'));
$status_ru = ['queued' => 'в очереди', 'running' => 'разбирается', 'review' => 'на проверке', 'done' => 'готово', 'failed' => 'ошибка'];
$field_ru = ['raw_name' => 'наименование', 'code_doc' => 'обозначение', 'qty' => 'количество', 'unit' => 'единица', 'section' => 'раздел',
    'unit_price' => 'цена', 'total_price' => 'сумма', 'code' => 'код НСИ', 'verified' => 'подтверждение', 'split' => 'разделение',
    'row_add' => 'строка добавлена', 'row_delete' => 'строка удалена', 'bulk_verify' => 'пакетное подтверждение',
    'rematch' => 'пересопоставление с НСИ', 'catalog_add' => 'новая позиция НСИ'];
$metrics = [
    ['Строк разобрано', number_format((int) ($rows['total'] ?? 0), 0, ',', ' '), 'во всех спецификациях периода', 'fa-list-ol'],
    ['Код НСИ без человека', $pct($rates['auto_coded']), 'обозначение, память или нечёткий поиск', 'fa-wand-magic-sparkles'],
    ['Автокод заменён оператором', $pct($rates['auto_code_overridden']), 'главный сигнал ошибок сопоставления', 'fa-arrow-right-arrow-left'],
    ['Без кода НСИ', $pct($rates['without_code']), 'кандидаты в справочник — ниже', 'fa-circle-question'],
];
$via = [
    ['по обозначению', (int) ($rows['via_code'] ?? 0)],
    ['память сопоставлений', (int) ($rows['via_memory'] ?? 0)],
    ['нечёткий поиск', (int) ($rows['via_fuzzy'] ?? 0)],
    ['вручную', (int) ($rows['via_manual'] ?? 0)],
    ['без кода', (int) ($rows['without_code'] ?? 0)],
];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Оцифровка спецификаций', '/prx400_specflow'], ['Качество']],
        'title' => 'Качество разбора',
        'pill' => ['prx400 · в бою', 'fa-chart-line'],
        'lead' => 'Как часто код НСИ находится без человека, где операторы поправляют разбор и какие позиции стоит завести в справочник. То же в консоли: bin/console prx400:eval --days='.(int) $days.'.',
    ]); ?>

    <nav class="prx-seg mt-5 w-fit max-w-full flex-wrap gap-1" aria-label="Период">
        <?php foreach ([7 => '7 дней', 30 => '30 дней', 90 => '90 дней', 365 => 'год'] as $d => $label): ?>
            <a href="/prx400_specflow/quality?days=<?php echo $d; ?>" class="prx-seg-item<?php echo $d === $days ? ' is-active' : ''; ?>"<?php echo $d === $days ? ' aria-current="page"' : ''; ?>><?php echo HTML::chars($label); ?></a>
        <?php endforeach; ?>
    </nav>

<?php if ((int) ($rows['total'] ?? 0) === 0 AND $total_specs === 0): ?>
    <div class="prx-card mt-5">
        <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">За период спецификаций не было — загрузите PDF на странице <a class="prx-link" href="/prx400_specflow">Спецификации</a>.</p>
    </div>
<?php else: ?>
    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?php foreach ($metrics as [$label, $value, $hint, $icon]): ?>
        <section class="prx-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas <?php echo $icon; ?> mr-1.5 text-gray-400"></i><?php echo HTML::chars($label); ?></p>
            <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($value); ?></p>
            <p class="prx-hint mt-1"><?php echo HTML::chars($hint); ?></p>
        </section>
        <?php endforeach; ?>
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <section class="prx-card overflow-x-auto p-0">
            <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
                <h2 class="prx-h3">Откуда коды НСИ</h2>
            </div>
            <table class="prx-table">
                <thead><tr><th>Источник кода</th><th class="text-right">Строк</th><th class="text-right">Доля</th></tr></thead>
                <tbody>
                <?php foreach ($via as [$label, $n]): ?>
                    <tr><td class="text-sm"><?php echo HTML::chars($label); ?></td><td class="text-right text-sm"><?php echo $n; ?></td>
                        <td class="text-right text-sm text-gray-500 dark:text-gray-400"><?php echo $pct($n / max(1, (int) ($rows['total'] ?? 0))); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="prx-hint px-4 py-3">Подтверждено операторами: <?php echo $pct($rates['verified']); ?>; совпало без правок: <?php echo $pct($rates['auto_ok']); ?>; строк с ошибкой арифметики: <?php echo (int) ($rows['math_errors'] ?? 0); ?>.</p>
        </section>

        <section class="prx-card overflow-x-auto p-0">
            <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
                <h2 class="prx-h3">Исходы спецификаций <span class="prx-count ml-1"><?php echo (int) $total_specs; ?></span></h2>
            </div>
            <table class="prx-table">
                <thead><tr><th>Статус</th><th>Код ошибки</th><th class="text-right">Спецификаций</th></tr></thead>
                <tbody>
                <?php foreach ($report['specs'] as $s): ?>
                    <tr><td class="text-sm"><?php echo HTML::chars($status_ru[$s['status']] ?? $s['status']); ?></td>
                        <td class="font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo $s['error'] !== '' ? HTML::chars($s['error']) : '—'; ?></td>
                        <td class="text-right text-sm"><?php echo (int) $s['n']; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <section class="prx-card mt-5 overflow-x-auto p-0">
        <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
            <h2 class="prx-h3">Кандидаты в НСИ <span class="prx-count ml-1"><?php echo count($report['catalog_gaps']); ?></span></h2>
            <p class="prx-hint mt-1">Позиции без кода, которые повторяются в разных спецификациях: заведите их в справочник — следующие разборы сопоставят их сами.</p>
        </div>
<?php if (empty($report['catalog_gaps'])): ?>
        <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Все позиции периода получили код НСИ.</p>
<?php else: ?>
        <table class="prx-table">
            <thead><tr><th>Наименование</th><th>Ед.</th><th class="text-right">Спецификаций</th><th class="text-right">Строк</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($report['catalog_gaps'] as $g): ?>
                <tr>
                    <td class="max-w-[420px]"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white" title="<?php echo HTML::chars($g['name']); ?>"><?php echo HTML::chars($g['name']); ?></span></td>
                    <td class="text-sm"><?php echo HTML::chars($g['unit']); ?></td>
                    <td class="text-right text-sm"><?php echo (int) $g['specs']; ?></td>
                    <td class="text-right text-sm"><?php echo (int) $g['rows']; ?></td>
                    <td class="whitespace-nowrap text-right">
                        <a class="prx-btn-secondary prx-btn-sm" href="/prx400_specflow/catalog?q=<?php echo rawurlencode(mb_substr($g['name'], 0, 200)); ?>" title="Проверить, нет ли позиции в НСИ под другим названием"><i class="fas fa-magnifying-glass"></i><span>В НСИ</span></a>
                        <a class="prx-btn-secondary prx-btn-sm" href="/prx400_specflow/spec/<?php echo (int) $g['last_spec_id']; ?>" title="Последняя спецификация с этой позицией"><i class="fas fa-file-pdf"></i><span>Пример</span></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( ! $catalog_writable): ?>
        <p class="prx-hint px-4 py-3">Подключён внешний справочник: позиции заводятся в нём (MDM/1С), портал подхватит их при следующем разборе или по «Пересопоставить с НСИ».</p>
        <?php elseif ( ! $can_edit): ?>
        <p class="prx-hint px-4 py-3">Заводить позиции в НСИ может оператор с правом prx400.run.</p>
        <?php endif; ?>
<?php endif; ?>
    </section>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <section class="prx-card overflow-x-auto p-0">
            <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
                <h2 class="prx-h3">Что правят операторы</h2>
                <p class="prx-hint mt-1">Частые правки полей — ошибки распознавания: повод поправить шаблон разбора.</p>
            </div>
<?php if (empty($report['edited_fields'])): ?>
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Правок за период нет.</p>
<?php else: ?>
            <table class="prx-table">
                <thead><tr><th>Действие</th><th class="text-right">Правок</th><th class="text-right">Спецификаций</th></tr></thead>
                <tbody>
                <?php foreach ($report['edited_fields'] as $f): ?>
                    <tr><td class="text-sm"><?php echo HTML::chars($field_ru[$f['field']] ?? $f['field']); ?></td>
                        <td class="text-right text-sm"><?php echo (int) $f['edits']; ?></td><td class="text-right text-sm"><?php echo (int) $f['specs']; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
<?php endif; ?>
        </section>

        <section class="prx-card overflow-x-auto p-0">
            <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
                <h2 class="prx-h3">Замены кода НСИ</h2>
                <p class="prx-hint mt-1">Какой автокод на какой меняют чаще всего — кандидаты в синонимы и память сопоставлений.</p>
            </div>
<?php if (empty($report['code_changes'])): ?>
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Замен кода за период нет.</p>
<?php else: ?>
            <table class="prx-table">
                <thead><tr><th>Было</th><th>Стало</th><th class="text-right">Раз</th></tr></thead>
                <tbody>
                <?php foreach ($report['code_changes'] as $c): ?>
                    <tr><td class="font-mono text-xs"><?php echo $c['from'] !== '' ? HTML::chars($c['from']) : '—'; ?></td>
                        <td class="font-mono text-xs"><?php echo $c['to'] !== '' ? HTML::chars($c['to']) : '—'; ?></td>
                        <td class="text-right text-sm"><?php echo (int) $c['n']; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
<?php endif; ?>
        </section>
    </div>
<?php endif; ?>
</div>
