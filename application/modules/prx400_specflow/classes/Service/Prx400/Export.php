<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Экспорт спецификации: JSON по схеме приёмника (meta + positions + audit),
 * CSV для быстрой ручной выгрузки, XLSX (позиции, закупочная ведомость,
 * сводка) и агрегат «к закупке» (одинаковые позиции сложены).
 * Только детерминированные преобразования записанных строк.
 *
 * Иерархия BOM: position — номер «1.2.3», level, parent_row_id. Агрегат
 * складывает листья дерева (узлы — сборки, их не закупают); режим
 * per_parent умножает количество на количества предков (BOM «на единицу
 * сборки»), as_is — берёт количество как в документе.
 */
final class Service_Prx400_Export {

    public const BOM_MODES = ['as_is', 'per_parent'];

    private const STATUS_RU = ['ok' => 'совпало', 'review' => 'на подтверждении', 'new' => 'новая позиция', 'error' => 'ошибка'];

    /** Позиция в схеме экспорта; $hitl — плюс кандидаты НСИ и флаги (API строк). */
    public static function position(array $r, bool $hitl = FALSE): array
    {
        $match = is_array($r['match'] ?? NULL) ? $r['match'] : [];
        $code = (string) ($match['code'] ?? '');
        $flags = is_array($r['flags'] ?? NULL) ? $r['flags'] : [];
        $out = [
            'row_id' => (int) ($r['id'] ?? 0),
            'line_number' => $r['pos'] ?? NULL,
            'position' => (string) ($r['pos_path'] ?? '') !== '' ? (string) $r['pos_path'] : NULL,
            'level' => (int) ($r['level'] ?? 0),
            'parent_row_id' => $r['parent_id'] ?? NULL,
            'file_no' => (int) ($r['file_no'] ?? 1),
            'page' => $r['page'] ?? NULL,
            'origin' => (string) ($r['origin'] ?? 'parsed'),
            'raw_text' => (string) $r['raw_name'],
            'code_doc' => (string) $r['code_doc'],
            'catalog_id' => (int) ($match['catalog_id'] ?? 0) ?: NULL,
            'catalog_code' => $code !== '' ? $code : NULL,
            'standard_name' => (string) ($match['name'] ?? ''),
            'match_score' => isset($match['score']) ? (float) $match['score'] : NULL,
            'match_via' => $code !== '' ? (string) ($match['via'] ?? (empty($match['manual']) ? 'fuzzy' : 'manual')) : NULL,
            'section' => (string) ($r['section'] ?? ''),
            'quantity' => $r['qty'] !== NULL ? (float) $r['qty'] : NULL,
            'unit' => (string) $r['unit'],
            'okei_unit_code' => (string) ($match['okei_code'] ?? ''),
            'unit_price' => $r['unit_price'] !== NULL ? (float) $r['unit_price'] : NULL,
            'total_price' => $r['total_price'] !== NULL ? (float) $r['total_price'] : NULL,
            'verification_status' => ! empty($r['verified']) ? 'verified' : (string) $r['status'],
            'warnings' => array_values(array_filter(array_map(
                static fn ($f): string => is_array($f) ? (string) ($f['type'] ?? '') : '', $flags), static fn (string $w): bool => $w !== '')),
        ];
        if ($hitl)
        {
            $out['status'] = (string) $r['status'];
            $out['verified'] = ! empty($r['verified']);
            $out['flags'] = array_values($flags);
            $out['candidates'] = array_map(static fn (array $a): array => [
                'catalog_code' => (string) ($a['code'] ?? ''), 'name' => (string) ($a['name'] ?? ''),
                'unit' => (string) ($a['unit'] ?? ''), 'score' => (float) ($a['score'] ?? 0),
            ], array_values(array_filter((array) ($match['alternatives'] ?? []), 'is_array')));
        }

        return $out;
    }

    /** JSON для ERP/НСИ (схема — см. README модуля, «Экспорт»). */
    public static function json(array $spec, array $rows): array
    {
        $audit = ['auto_matched' => 0, 'memory_matched' => 0, 'confirmed_by_operator' => 0, 'new_positions' => 0,
            'manual_rows' => 0, 'corrections' => []];

        foreach ($rows as $r)
        {
            $match = is_array($r['match'] ?? NULL) ? $r['match'] : [];
            $matched_code = (string) ($match['code'] ?? '');
            if ($matched_code !== '' AND ! empty($match['manual']))
            {
                $audit['confirmed_by_operator']++;
                $audit['corrections'][] = ['seq' => (int) $r['seq'], 'code' => $matched_code, 'score' => $match['score'] ?? NULL];
            }
            elseif ($matched_code !== '' AND ($match['via'] ?? '') === 'memory')
            {
                $audit['memory_matched']++;
            }
            elseif ($matched_code !== '')
            {
                $audit['auto_matched']++;
            }
            elseif ((string) $r['status'] === 'new')
            {
                $audit['new_positions']++;
            }
            if (($r['origin'] ?? 'parsed') !== 'parsed') $audit['manual_rows']++;
        }

        return [
            'meta' => self::meta($spec),
            'positions' => array_map(static fn (array $r): array => self::position($r), $rows),
            'audit' => $audit,
        ];
    }

    /**
     * Закупочная ведомость: одинаковые позиции сложены. Ключ — код НСИ, без
     * кода — нормализованные наименование + обозначение + единица.
     */
    public static function aggregate(array $rows, string $bom_mode = 'as_is'): array
    {
        if ( ! in_array($bom_mode, self::BOM_MODES, TRUE)) throw new Prx400_Error('invalid_bom_mode', 400);
        $by_id = [];
        $parents = [];
        foreach ($rows as $r)
        {
            $by_id[(int) ($r['id'] ?? 0)] = $r;
            if (($r['parent_id'] ?? NULL) !== NULL) $parents[(int) $r['parent_id']] = TRUE;
        }

        $groups = [];
        foreach ($rows as $r)
        {
            if (isset($parents[(int) ($r['id'] ?? 0)])) continue; // сборка — её состав уже в листьях
            $qty = $r['qty'] !== NULL ? (float) $r['qty'] : NULL;
            if ($bom_mode === 'per_parent' AND $qty !== NULL)
            {
                $seen = [];
                for ($p = $r['parent_id'] ?? NULL; $p !== NULL AND isset($by_id[$p]) AND ! isset($seen[$p]); $p = $by_id[$p]['parent_id'] ?? NULL)
                {
                    $seen[$p] = TRUE;
                    if ($by_id[$p]['qty'] !== NULL) $qty *= (float) $by_id[$p]['qty'];
                }
            }
            $match = is_array($r['match'] ?? NULL) ? $r['match'] : [];
            $code = (string) ($match['code'] ?? '');
            $key = $code !== '' ? 'code:'.$code
                : 'name:'.Service_Prx400_Match::normalize((string) $r['raw_name']).'|'.Service_Prx400_Match::code_key((string) $r['code_doc'])
                    .'|'.Service_Prx400_Match::unit_key((string) $r['unit']);
            $g = $groups[$key] ?? [
                'catalog_code' => $code !== '' ? $code : NULL,
                'name' => $code !== '' ? (string) ($match['name'] ?? $r['raw_name']) : (string) $r['raw_name'],
                'code_doc' => (string) $r['code_doc'],
                'unit' => $code !== '' && (string) ($match['unit'] ?? '') !== '' ? (string) $match['unit'] : (string) $r['unit'],
                'okei_unit_code' => (string) ($match['okei_code'] ?? ''),
                'quantity' => 0.0, 'quantity_complete' => TRUE, 'total_price' => 0.0, 'total_complete' => TRUE,
                'rows' => 0, 'positions' => [], 'verified' => TRUE, 'units' => [],
            ];
            if ($qty === NULL) $g['quantity_complete'] = FALSE; else $g['quantity'] += $qty;
            if ($r['total_price'] === NULL) $g['total_complete'] = FALSE; else $g['total_price'] += (float) $r['total_price'];
            $g['rows']++;
            $g['positions'][] = Service_Prx400_Rows::label(array_merge($r, ['raw_name' => '']));
            $g['verified'] = $g['verified'] && ($r['status'] === 'ok' || ! empty($r['verified']));
            $g['units'][Service_Prx400_Match::unit_key((string) $r['unit'])] = TRUE;
            $groups[$key] = $g;
        }

        $out = [];
        foreach ($groups as $g)
        {
            $out[] = [
                'catalog_code' => $g['catalog_code'],
                'name' => $g['name'],
                'code_doc' => $g['code_doc'],
                'unit' => $g['unit'],
                'okei_unit_code' => $g['okei_unit_code'],
                'quantity' => $g['quantity_complete'] ? round($g['quantity'], 4) : NULL,
                'total_price' => $g['total_complete'] ? round($g['total_price'], 2) : NULL,
                'rows' => $g['rows'],
                'positions' => array_map(static fn (string $p): string => rtrim($p, ' ·'), $g['positions']),
                'verified' => $g['verified'],
                'warnings' => array_values(array_filter([
                    $g['quantity_complete'] ? '' : 'quantity_incomplete',
                    count($g['units']) > 1 ? 'mixed_units' : '',
                ])),
            ];
        }
        usort($out, static fn (array $a, array $b): int =>
            [$a['catalog_code'] === NULL, (string) $a['catalog_code'], $a['name']] <=> [$b['catalog_code'] === NULL, (string) $b['catalog_code'], $b['name']]);

        return $out;
    }

    /** CSV (BOM): позиция; наименование; марка/ГОСТ; кол-во; ед.; цена; сумма; код НСИ; статус; уровень; файл; стр. */
    public static function csv(array $spec, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM для Excel/WPS
        foreach (self::table($rows) as $line)
        {
            fputcsv($out, array_map(static fn ($v) => is_string($v) ? self::cell($v) : $v, $line), ';', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** XLSX: «Позиции» (дерево отступами), «Закупка» (агрегат), «Сводка». */
    public static function xlsx(array $spec, array $rows, string $bom_mode = 'as_is'): string
    {
        $purchase = [['Код НСИ', 'Наименование', 'Обозначение', 'Ед.', 'ОКЕИ', 'Кол-во', 'Сумма', 'Строк', 'Позиции', 'Проверено', 'Замечания']];
        foreach (self::aggregate($rows, $bom_mode) as $g)
        {
            $purchase[] = [$g['catalog_code'], $g['name'], $g['code_doc'], $g['unit'], $g['okei_unit_code'], $g['quantity'],
                $g['total_price'], $g['rows'], implode(', ', $g['positions']), $g['verified'] ? 'да' : 'нет', implode(', ', $g['warnings'])];
        }
        $meta = self::meta($spec);
        $counts = array_count_values(array_map(static fn (array $r): string => ! empty($r['verified']) ? 'verified' : (string) $r['status'], $rows));
        $summary = [['Поле', 'Значение'],
            ['Спецификация', $meta['specification_code']], ['Файл', $meta['original_name']], ['Шаблон', $meta['template_id']],
            ['Подразделение', $meta['department']], ['Статус', $meta['status']], ['Страниц', $meta['pages']],
            ['Позиций', count($rows)], ['Подтверждено', $counts['verified'] ?? 0], ['Совпало с НСИ', $counts['ok'] ?? 0],
            ['На подтверждении', $counts['review'] ?? 0], ['Новых', $counts['new'] ?? 0], ['С ошибками', $counts['error'] ?? 0],
            ['Режим количества BOM', $bom_mode === 'per_parent' ? 'на единицу сборки (× предки)' : 'как в документе'],
            ['Выгружено', date('c')]];

        return Prx400_Xlsx::build(['Позиции' => self::table($rows, TRUE), 'Закупка' => $purchase, 'Сводка' => $summary]);
    }

    /**
     * Текст из скана не должен стать формулой Excel (=, +, -, @ в начале ячейки).
     * Обратное преобразование — Service_Prx400_CatalogImport::uncell().
     */
    public static function cell($value): string
    {
        $value = (string) $value;

        return preg_match('/\A[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private static function meta(array $spec): array
    {
        return [
            'spec_id' => (int) $spec['id'],
            'status' => (string) ($spec['status'] ?? ''),
            'template_id' => (string) $spec['template_id'],
            'specification_code' => (string) ($spec['spec_number'] ?: 'spec-'.$spec['id']),
            'department' => (string) $spec['department'],
            'original_name' => (string) $spec['original_name'],
            'pages' => (int) $spec['pages'],
            'parse_mode' => (string) ($spec['parse_mode'] ?? ''),
            'text_sha256' => (string) $spec['text_sha256'],
            'processed_timestamp' => date('c', strtotime((string) $spec['updated_at'])),
        ];
    }

    /** Таблица позиций (CSV и лист XLSX); $indent — отступ наименования по уровню. */
    private static function table(array $rows, bool $indent = FALSE): array
    {
        $out = [['№', 'Наименование', 'Тип, марка', 'Кол-во', 'Ед.', 'Цена', 'Сумма', 'Код НСИ', 'Статус', 'Уровень', 'Файл', 'Стр.', 'Раздел']];
        foreach ($rows as $r)
        {
            $match = is_array($r['match'] ?? NULL) ? $r['match'] : [];
            $name = (string) $r['raw_name'];
            if ($indent AND (int) ($r['level'] ?? 0) > 0) $name = str_repeat('    ', min(8, (int) $r['level'])).$name;
            $out[] = [
                (string) ($r['pos_path'] ?? '') !== '' ? (string) $r['pos_path'] : ($r['pos'] !== NULL ? (int) $r['pos'] : NULL),
                $name, (string) $r['code_doc'],
                $r['qty'] !== NULL ? (float) $r['qty'] : NULL, (string) $r['unit'],
                $r['unit_price'] !== NULL ? (float) $r['unit_price'] : NULL,
                $r['total_price'] !== NULL ? (float) $r['total_price'] : NULL,
                (string) ($match['code'] ?? ''),
                ! empty($r['verified']) ? 'проверено' : (self::STATUS_RU[(string) $r['status']] ?? (string) $r['status']),
                (int) ($r['level'] ?? 0), (int) ($r['file_no'] ?? 1), $r['page'] ?? NULL, (string) ($r['section'] ?? ''),
            ];
        }

        return $out;
    }
}
