<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Текст-инструменты (модуль NJ-631) — быстрые операции над строками.
 *
 * Каждый инструмент — чистая функция: регистр, статистика, дубликаты,
 * сортировка, обрезка строк и поиск/замена. Работает с UTF-8 (mbstring),
 * для строк — один список на строку.
 */
class Service_Nj631_Text {

    /** Инструменты для UI/API. */
    public static function tools(): array
    {
        return [
            'stats'    => ['Статистика', 'символы, слова, строки, время чтения'],
            'upper'    => ['В ВЕРХНИЙ регистр', 'все буквы заглавными'],
            'lower'    => ['в нижний регистр', 'все буквы строчными'],
            'title'    => ['Как В Предложении', 'первая буква каждого предложения'],
            'dedupe'   => ['Убрать дубликаты строк', 'с сохранением порядка'],
            'sort'     => ['Сортировать строки', 'по алфавиту'],
            'trim'     => ['Обрезать строки', 'убрать лишние пробелы в краях'],
            'replace'  => ['Заменить текст', 'найти и заменить подстроку'],
        ];
    }

    /** Главная точка: инструмент по коду. */
    public static function apply(string $tool, string $text, array $params = []): ?array
    {
        switch ($tool)
        {
            case 'stats':   return self::stats($text);
            case 'upper':   return ['result' => self::_upper($text)];
            case 'lower':   return ['result' => self::_lower($text)];
            case 'title':   return ['result' => self::_title($text)];
            case 'dedupe':  return ['result' => self::_dedupe($text)];
            case 'sort':    return ['result' => self::_sort($text), 'lines' => self::_line_count($text)];
            case 'trim':    return ['result' => self::_trim($text)];
            case 'replace': return ['result' => self::_replace($text, $params['find'] ?? '', $params['replace'] ?? ''), 'count' => substr_count($text, $params['find'] ?? '')];
            default: return NULL;
        }
    }

    /** Статистика текста. */
    public static function stats(string $text): array
    {
        $chars  = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $spaces = mb_substr_count($text, ' ');

        $words_raw = preg_split('/[\s]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $words = $words_raw ? count($words_raw) : 0;

        $lines = self::_line_count($text);

        // средняя скорость чтения ~200 слов/мин
        $reading = max(1, (int) round($words / 200 * 60));

        return [
            'chars'   => $chars,
            'chars_ns' => $chars - $spaces, // без пробелов
            'words'   => $words,
            'lines'   => $lines,
            'reading_s' => $reading,
        ];
    }

    /** Время чтения в человеческом виде. */
    public static function reading_human(int $seconds): string
    {
        if ($seconds < 60)
        {
            return 'меньше минуты';
        }

        $min = (int) round($seconds / 60);

        return $min.' мин';
    }

    protected static function _line_count(string $text): int
    {
        return $text === '' ? 0 : substr_count($text, "\n") + 1;
    }

    protected static function _upper(string $text): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    }

    protected static function _lower(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    protected static function _title(string $text): string
    {
        if (function_exists('mb_convert_case'))
        {
            return mb_convert_case(mb_strtolower($text, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }

        return ucwords($text);
    }

    protected static function _dedupe(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $seen  = [];
        $out   = [];

        foreach ($lines as $line)
        {
            if (trim($line) !== '' && isset($seen[$line]))
            {
                continue;
            }

            if (trim($line) !== '')
            {
                $seen[$line] = true;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    protected static function _sort(string $text): string
    {
        $lines = preg_split('/\R/', $text, -1, PREG_SPLIT_NO_EMPTY);
        sort($lines, SORT_FLAG_CASE | SORT_STRING);

        return implode("\n", $lines);
    }

    protected static function _trim(string $text): string
    {
        return implode("\n", array_filter(preg_split('/\R/', $text), function ($l) {
            return trim($l) !== '';
        }));
    }

    protected static function _replace(string $text, string $find, string $replace): string
    {
        if ($find === '')
        {
            return $text;
        }

        return str_replace($find, $replace, $text);
    }
}
