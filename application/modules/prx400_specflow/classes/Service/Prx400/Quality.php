<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Качество разбора в бою за N дней — для prx400:eval и страницы
 * /prx400_specflow/quality: исходы спецификаций, доля строк с кодом НСИ без
 * человека и откуда код, замены автокодов оператором (ошибки матчинга), какие
 * поля правят (ошибки распознавания), кандидаты в НСИ.
 *
 * Данные по всем владельцам — только для UI с prx400.view (администратор),
 * не для API-ключа (ключ видит лишь свои записи).
 */
final class Service_Prx400_Quality
{
    public static function report(int $days): array
    {
        $q = Model_Prx400_Edit::quality($days);
        $journal = Model_Prx400_Edit::analytics($days);
        $rows = $q['rows'];
        $total = max(1, (int) ($rows['total'] ?? 0));
        $auto = (int) ($rows['via_code'] ?? 0) + (int) ($rows['via_memory'] ?? 0) + (int) ($rows['via_fuzzy'] ?? 0);
        $share = static fn (int $n): float => round($n / $total, 4);

        return [
            'days' => $days,
            'specs' => $q['specs'],
            'rows' => $rows,
            'rates' => [
                'auto_coded' => $share($auto),
                'auto_ok' => $share((int) ($rows['auto_ok'] ?? 0)),
                'operator_coded' => $share((int) ($rows['via_manual'] ?? 0)),
                'without_code' => $share((int) ($rows['without_code'] ?? 0)),
                'verified' => $share((int) ($rows['verified'] ?? 0)),
                'auto_code_overridden' => $auto > 0 ? round($q['auto_code_overridden'] / $auto, 4) : 0.0,
            ],
            'edited_fields' => array_map(static fn (array $f): array => ['field' => (string) $f['field'], 'edits' => (int) $f['n'], 'specs' => (int) $f['specs']], $journal['fields']),
            'code_changes' => array_map(static fn (array $c): array => ['from' => (string) $c['old_code'], 'to' => (string) $c['new_code'], 'n' => (int) $c['n']], $journal['code_changes']),
            'catalog_gaps' => Model_Prx400_Edit::catalog_gaps($days),
        ];
    }
}
