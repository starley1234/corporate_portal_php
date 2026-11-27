<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Матчинг позиций с НСИ (Confidence Scoring).
 *
 * Нормализация «грязных» описаний (регистр, ё→е, пунктуация, ГОСТ-суффиксы)
 * → скор: 60% последовательная похожесть (mb-Левенштейн) + 40% перекрытие
 * слов (Жаккар). Пороги из config/prx400_specflow.php: >= auto_accept —
 * привязка кода автоматически, >= manual_review — на подтверждение с
 * подсказками top-K, ниже — «новая позиция».
 */
final class Service_Prx400_Match {

    public static function thresholds(): array
    {
        return Service_Prx400_Limits::match();
    }

    /**
     * Источник кандидатов для партии строк: небольшой справочник — весь в
     * памяти (точный перебор), большой — предотбор pg_trgm на каждую строку.
     * Без pg_trgm большой справочник честно перебирается целиком (+ запись в журнал).
     *
     * @return callable(string): array кандидаты для строки
     */
    public static function catalog_source(): callable
    {
        $t = self::thresholds();
        $count = Model_Prx400_Catalog::count();
        if ($count <= $t['full_scan_max'] OR ! Model_Prx400_Catalog::trigram_available())
        {
            if ($count > $t['full_scan_max'])
            {
                Log_Plain::event(Log::WARNING, 'prx400 stage=match full_scan catalog=:n (pg_trgm недоступен)', [':n' => $count]);
            }
            $all = Model_Prx400_Catalog::all();

            return static fn (string $query): array => $all;
        }

        return static fn (string $query): array => Model_Prx400_Catalog::candidates($query, $t['candidates']);
    }

    /** Нормализованная строка для сравнения. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ё' => 'е']);
        $text = preg_replace('~[^\pL\d\s]+~u', ' ', $text) ?? '';
        $text = preg_replace('~\s+~u', ' ', $text) ?? '';

        return trim($text);
    }

    /** Скор похожести 0..1: Левенштейн по последовательности + Жаккар по словам. */
    public static function score(string $a, string $b): float
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);
        if ($na === '' OR $nb === '')
        {
            return 0.0;
        }
        if ($na === $nb)
        {
            return 1.0;
        }

        $lev = 1.0 - self::_mb_levenshtein($na, $nb) / max(mb_strlen($na), mb_strlen($nb));

        $ta = array_unique(explode(' ', $na));
        $tb = array_unique(explode(' ', $nb));
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));
        $jac = $union > 0 ? $inter / $union : 0.0;

        return round(0.6 * max(0.0, $lev) + 0.4 * $jac, 4);
    }

    /** Строка запроса к НСИ: наименование + марка/обозначение. */
    public static function query(string $name, string $code_doc): string
    {
        $name = trim($name);
        $code_doc = trim(preg_replace('~\s+~u', ' ', $code_doc) ?? '');
        // Обозначение уже внутри наименования — не удваиваем вес его слов.
        if ($code_doc === '' OR mb_stripos($name, $code_doc) !== FALSE) return $name;

        return trim($name.' '.$code_doc);
    }

    /**
     * Сопоставить строку со справочником: match JSONB + статус по порогам.
     * Каталог пуст → «новая позиция» без подсказок (честно).
     */
    public static function apply(string $name, string $code_doc, array $catalog): array
    {
        $t = self::thresholds();
        $query = self::query($name, $code_doc);

        $scored = [];
        foreach ($catalog as $item)
        {
            $score = max(
                self::score($query, (string) $item['name']),
                self::score($query, (string) $item['name'].' '.(string) ($item['keywords'] ?? ''))
            );
            $scored[] = [
                'catalog_id' => (int) $item['id'],
                'code'       => (string) $item['code'],
                'name'       => (string) $item['name'],
                'unit'       => (string) $item['unit'],
                'okei_code'  => (string) ($item['okei_code'] ?? ''),
                'score'      => $score,
            ];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $top = array_slice($scored, 0, $t['top_k']);
        $best_score = $top[0]['score'] ?? 0.0;
        $candidates = array_map(static fn (array $m): array => [
            'catalog_id' => $m['catalog_id'], 'code' => $m['code'], 'name' => $m['name'], 'score' => $m['score'],
        ], $top);

        if ($best_score >= $t['auto_accept'])
        {
            $status = 'ok';
            $match = $top[0] + ['alternatives' => array_slice($candidates, 1)];
        }
        elseif ($best_score >= $t['manual_review'])
        {
            $status = 'review';
            $match = ['alternatives' => $candidates];
        }
        else
        {
            $status = 'new';
            $match = ['alternatives' => $candidates, 'score' => round($best_score, 4)];
        }

        return ['status' => $status, 'match' => $match];
    }

    /**
     * Статус строки из её данных — единственное правило для результата
     * воркера, правки оператора и снятия отметки «Проверено»:
     *  error  — не сошлась арифметика строки;
     *  ok     — код НСИ привязан (с дробным количеством штучных — review);
     *  review — лучший кандидат выше порога подтверждения;
     *  new    — кандидатов выше порога нет (заведение в НСИ).
     */
    public static function row_status(array $flags, ?array $match): string
    {
        $types = array_map(static fn ($f): string => is_array($f) ? (string) ($f['type'] ?? '') : '', $flags);
        if (in_array('math_row', $types, TRUE)) return 'error';

        $fractional = in_array('unit_fractional', $types, TRUE);
        if ( ! empty($match['code'])) return $fractional ? 'review' : 'ok';

        $best = 0.0;
        foreach ((array) ($match['alternatives'] ?? []) as $alt)
        {
            $best = max($best, (float) ($alt['score'] ?? 0));
        }

        return $best >= self::thresholds()['manual_review'] ? 'review' : 'new';
    }

    /** mb-Левенштейн (стандартный levenshtein() не безопасен для UTF-8). */
    protected static function _mb_levenshtein(string $a, string $b): int
    {
        $a = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($b);
        if ($n === 0) return count($a);
        $prev = range(0, $n);
        foreach ($a as $i => $ca)
        {
            $cur = [$i + 1];
            foreach ($b as $j => $cb)
            {
                $cur[$j + 1] = min($prev[$j] + ($ca === $cb ? 0 : 1), $prev[$j + 1] + 1, $cur[$j] + 1);
            }
            $prev = $cur;
        }

        return (int) $prev[$n];
    }
}
