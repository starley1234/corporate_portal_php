<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Матчинг позиций с НСИ (Confidence Scoring) — чистые функции.
 *
 * Нормализация «грязных» описаний: регистр, ё→е, пунктуация, технические
 * обозначения (кириллица/латиница в марках «М16»/«M16», «40х40х4»/«40×40×4»,
 * десятичная запятая) → скор: 60% последовательная похожесть (mb-Левенштейн)
 * + 40% перекрытие слов (Жаккар). Сильный сигнал — обозначение строки
 * совпало с кодом позиции или найдено в её наименовании. Пороги из
 * config/prx400_specflow.php: >= auto_accept — привязка автоматически,
 * >= manual_review — на подтверждение с подсказками top-K, ниже — «новая».
 * Оркестровка партии (источник НСИ, память решений) — Service_Prx400_Matcher.
 */
final class Service_Prx400_Match {

    public static function thresholds(): array
    {
        return Service_Prx400_Limits::match();
    }

    /**
     * Источник кандидатов для партии строк (совместимость): тот же, что у
     * Service_Prx400_Matcher — через подключённый провайдер справочника.
     *
     * @return callable(string): array кандидаты для строки
     */
    public static function catalog_source(): callable
    {
        return Service_Prx400_Catalog::provider()->batch();
    }

    /** Кириллические двойники латиницы — в марках и обозначениях («М16», «С235»). */
    private const HOMOGLYPHS = ['а' => 'a', 'в' => 'b', 'е' => 'e', 'к' => 'k', 'м' => 'm', 'н' => 'h',
        'о' => 'o', 'р' => 'p', 'с' => 'c', 'т' => 't', 'у' => 'y', 'х' => 'x'];

    /**
     * Написания слова запроса для поиска в справочнике: как набрано, латиницей
     * и кириллицей (марки «М16х60» в НСИ бывают и так, и так).
     *
     * @return string[]
     */
    public static function search_forms(string $word): array
    {
        $word = mb_strtolower(trim($word));
        if ($word === '') return [];

        return array_values(array_unique([$word, strtr($word, self::HOMOGLYPHS), strtr($word, array_flip(self::HOMOGLYPHS))]));
    }

    /** Нормализованная строка для сравнения. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ё' => 'е', '×' => 'x', 'ø' => 'd', '⌀' => 'd', '²' => '2', '³' => '3']);
        // Размеры «40 х 40 х 4», «2*1,5» → 40x40x4, 2x1.5; десятичная запятая → точка.
        $text = preg_replace('~(?<=\d)\s*[xх*]\s*(?=\d)~u', 'x', $text) ?? '';
        $text = preg_replace('~(?<=\d),(?=\d)~u', '.', $text) ?? '';
        $text = preg_replace('~[^\pL\d\s.]+~u', ' ', $text) ?? '';
        $text = preg_replace('~(?<!\d)\.|\.(?!\d)~u', ' ', $text) ?? '';
        // Слово с цифрой — марка/типоразмер: кириллические двойники → латиница.
        $text = preg_replace_callback('~\S*\d\S*~u', static fn (array $m): string => strtr($m[0], self::HOMOGLYPHS), $text) ?? '';
        $text = preg_replace('~\s+~u', ' ', $text) ?? '';

        return trim($text);
    }

    /** Ключ обозначения/кода: только буквы и цифры, двойники → латиница («КБ-20.01» ≡ «кб2001»). */
    public static function code_key(string $code): string
    {
        $code = strtr(mb_strtolower(trim($code)), ['ё' => 'е'] + self::HOMOGLYPHS);

        return preg_replace('~[^\pL\d]+~u', '', $code) ?? '';
    }

    /** Ключ памяти сопоставлений: [наименование, обозначение] в нормальной форме. */
    public static function memory_key(string $name, string $code_doc): array
    {
        return [mb_substr(self::normalize($name), 0, 2000), mb_substr(self::code_key($code_doc), 0, 255)];
    }

    /** Синонимы единиц измерения → каноническая форма (сверка с единицей НСИ). */
    private const UNITS = [
        'шт' => ['шт', 'штук', 'штука', 'штуки', 'pcs', 'pc', 'ea'],
        'компл' => ['компл', 'комплект', 'кт', 'к т', 'set'],
        'м' => ['м', 'мп', 'м п', 'пм', 'пог м', 'пог', 'метр', 'm'],
        'м2' => ['м2', 'кв м', 'm2'],
        'м3' => ['м3', 'куб м', 'm3'],
        'кг' => ['кг', 'килограмм', 'kg'],
        'т' => ['т', 'тн', 'тонна', 't'],
        'л' => ['л', 'литр', 'l'],
        'уп' => ['уп', 'упак', 'упаковка'],
        'пар' => ['пар', 'пара'],
    ];

    /** Каноническая единица; неизвестная — как есть (нормализованная), пустая — ''. */
    public static function unit_key(string $unit): string
    {
        $u = mb_strtolower(trim($unit));
        $u = strtr($u, ['²' => '2', '³' => '3', '.' => ' ', '-' => ' ']);
        $u = trim(preg_replace('~\s+~u', ' ', $u) ?? '');
        foreach (self::UNITS as $canon => $synonyms)
        {
            if (in_array($u, $synonyms, TRUE)) return $canon;
        }

        return $u;
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
     *
     * via: code — обозначение строки равно коду позиции; fuzzy — похожесть
     * (с бонусом, если обозначение найдено в наименовании/ключевых словах).
     */
    public static function apply(string $name, string $code_doc, array $catalog): array
    {
        $t = self::thresholds();
        $query = self::query($name, $code_doc);
        $doc_key = self::code_key($code_doc);
        $strong = mb_strlen($doc_key) >= 3 && preg_match('~\d~', $doc_key);

        $scored = [];
        foreach ($catalog as $item)
        {
            $score = max(
                self::score($query, (string) $item['name']),
                self::score($query, (string) $item['name'].' '.(string) ($item['keywords'] ?? ''))
            );
            $via = 'fuzzy';
            if ($strong AND $doc_key === self::code_key((string) $item['code']))
            {
                $score = 1.0;
                $via = 'code';
            }
            elseif ($strong AND mb_strlen($doc_key) >= 4
                AND str_contains(self::code_key((string) $item['name'].' '.(string) ($item['keywords'] ?? '')), $doc_key))
            {
                $score = round(min(0.99, $score + 0.15), 4);
            }
            $scored[] = [
                'catalog_id' => (int) ($item['id'] ?? 0),
                'code'       => (string) $item['code'],
                'name'       => (string) $item['name'],
                'unit'       => (string) ($item['unit'] ?? ''),
                'okei_code'  => (string) ($item['okei_code'] ?? ''),
                'score'      => $score,
                'via'        => $via,
            ];
        }
        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['code']] <=> [$a['score'], $b['code']]);

        $top = array_slice($scored, 0, $t['top_k']);
        $best_score = $top[0]['score'] ?? 0.0;
        $candidates = array_map(static fn (array $m): array => [
            'catalog_id' => $m['catalog_id'], 'code' => $m['code'], 'name' => $m['name'], 'unit' => $m['unit'], 'score' => $m['score'],
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

    /** match JSONB из позиции справочника (ручной выбор, память, API). */
    public static function from_item(array $item, string $via, bool $manual, array $alternatives = []): array
    {
        return [
            'catalog_id' => (int) ($item['id'] ?? 0), 'code' => (string) $item['code'],
            'name' => (string) $item['name'], 'unit' => (string) ($item['unit'] ?? ''),
            'okei_code' => (string) ($item['okei_code'] ?? ''), 'score' => 1.0,
            'via' => $via, 'manual' => $manual, 'alternatives' => array_values($alternatives),
        ];
    }

    /**
     * Статус строки из её данных — единственное правило для результата
     * воркера, правки оператора и снятия отметки «Проверено»:
     *  error  — не сошлась арифметика строки;
     *  ok     — код НСИ привязан (ручной — всегда; авто с дробными штуками
     *           или расхождением единицы с НСИ — review);
     *  review — лучший кандидат выше порога подтверждения;
     *  new    — кандидатов выше порога нет (заведение в НСИ).
     */
    public static function row_status(array $flags, ?array $match): string
    {
        $types = array_map(static fn ($f): string => is_array($f) ? (string) ($f['type'] ?? '') : '', $flags);
        if (in_array('math_row', $types, TRUE)) return 'error';

        if ( ! empty($match['code']))
        {
            if ( ! empty($match['manual'])) return 'ok';
            return array_intersect(['unit_fractional', 'unit_mismatch'], $types) ? 'review' : 'ok';
        }

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
