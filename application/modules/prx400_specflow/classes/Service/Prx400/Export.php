<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Экспорт проверенной спецификации: JSON по схеме приёмника (meta + positions
 * + audit) и CSV для быстрой ручной выгрузки. Только детерминированные
 * преобразования записанных строк; артефакты верификации — в audit.
 */
class Service_Prx400_Export {

    /** JSON для ERP/НСИ (схема — см. спецификацию модуля, output_json_mapping). */
    public static function json(array $spec, array $rows): array
    {
        $positions = [];
        $audit = ['auto_matched' => 0, 'confirmed_by_operator' => 0, 'new_positions' => 0, 'corrections' => []];

        foreach ($rows as $r)
        {
            $match = is_array($r['match'] ?? NULL) ? $r['match'] : json_decode((string) ($r['match'] ?? 'null'), TRUE);
            $matched_code = (string) ($match['code'] ?? '');
            $flags = is_array($r['flags'] ?? NULL) ? $r['flags'] : json_decode((string) ($r['flags'] ?? '[]'), TRUE);

            if ($matched_code !== '' AND ! empty($match['manual']))
            {
                $audit['confirmed_by_operator']++;
                $audit['corrections'][] = ['seq' => (int) $r['seq'], 'code' => $matched_code, 'score' => $match['score'] ?? NULL];
            }
            elseif ($matched_code !== '')
            {
                $audit['auto_matched']++;
            }
            elseif ((string) $r['status'] === 'new')
            {
                $audit['new_positions']++;
            }

            $positions[] = [
                'line_number' => $r['pos'],
                'raw_text' => (string) $r['raw_name'],
                'code_doc' => (string) $r['code_doc'],
                'catalog_id' => (int) ($match['catalog_id'] ?? 0) ?: NULL,
                'catalog_code' => $matched_code !== '' ? $matched_code : NULL,
                'standard_name' => (string) ($match['name'] ?? ''),
                'match_score' => isset($match['score']) ? (float) $match['score'] : NULL,
                'quantity' => $r['qty'] !== NULL ? (float) $r['qty'] : NULL,
                'unit' => (string) $r['unit'],
                'okei_unit_code' => (string) ($match['okei_code'] ?? ''),
                'unit_price' => $r['unit_price'] !== NULL ? (float) $r['unit_price'] : NULL,
                'total_price' => $r['total_price'] !== NULL ? (float) $r['total_price'] : NULL,
                'verification_status' => in_array($r['verified'] ?? NULL, ['t', TRUE, '1'], TRUE) ? 'verified' : (string) $r['status'],
                'warnings' => array_values(array_filter(array_map(
                    static function ($f): string {
                        return is_array($f) ? (string) ($f['type'] ?? '') : '';
                    }, (array) $flags), static fn (string $w): bool => $w !== '')),
            ];
        }

        return [
            'meta' => [
                'template_id' => (string) $spec['template_id'],
                'specification_code' => (string) ($spec['spec_number'] ?: 'spec-'.$spec['id']),
                'department' => (string) $spec['department'],
                'original_name' => (string) $spec['original_name'],
                'pages' => (int) $spec['pages'],
                'text_sha256' => (string) $spec['text_sha256'],
                'processed_timestamp' => date('c', strtotime((string) $spec['updated_at'])),
            ],
            'positions' => $positions,
            'audit' => $audit,
        ];
    }

    /** CSV (BOM): позиция; наименование; марка/ГОСТ; кол-во; ед.; цена; сумма; код НСИ. */
    public static function csv(array $spec, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM для Excel/WPS
        fputcsv($out, ['№', 'Наименование', 'Тип, марка', 'Кол-во', 'Ед.', 'Цена', 'Сумма', 'Код НСИ', 'Статус'], ';');
        $status_ru = ['ok' => 'совпало', 'review' => 'на подтверждении', 'new' => 'новая позиция', 'error' => 'ошибка'];
        foreach ($rows as $r)
        {
            $match = is_array($r['match'] ?? NULL) ? $r['match'] : json_decode((string) ($r['match'] ?? 'null'), TRUE);
            fputcsv($out, [
                $r['pos'], $r['raw_name'], $r['code_doc'],
                $r['qty'], $r['unit'], $r['unit_price'], $r['total_price'],
                (string) ($match['code'] ?? ''),
                $status_ru[(string) $r['status']] ?? (string) $r['status'],
            ], ';');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
