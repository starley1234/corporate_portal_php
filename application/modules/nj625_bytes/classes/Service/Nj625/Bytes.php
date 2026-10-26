<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Единицы информации (модуль NJ-625) — биты, байты, двоичные и десятичные
 * кратные (КиБ/МиБ/ГиБ = 1024, КБ/МБ/ГБ = 1000).
 *
 * Внутренняя валюта — байты (float). Результат всегда полная таблица, чтобы
 * пользователь сам выбрал нужную трактовку (как в «данных» ОС и на упаковке).
 */
class Service_Nj625_Bytes {

    /** @var array<string,array{float,string}> единицы: множитель в байтах + подпись */
    const UNITS = [
        'bit'  => [0.125, 'бит'],
        'B'    => [1,     'байт'],
        'KiB'  => [1024,                    'кибибайт (1024)'],
        'KB'   => [1000,                    'килобайт (1000)'],
        'MiB'  => [1048576,                 'мебибайт (1024²)'],
        'MB'   => [1000000,                 'мегабайт (1000²)'],
        'GiB'  => [1073741824,              'гибибайт (1024³)'],
        'GB'   => [1000000000,              'гигабайт (1000³)'],
        'TiB'  => [1099511627776,           'тебибайт (1024⁴)'],
        'TB'   => [1000000000000,           'терабайт (1000⁴)'],
    ];

    /** Список единиц для UI/API. */
    public static function units(): array
    {
        return self::UNITS;
    }

    /** Парсинг «число + единица» → байты, либо NULL. */
    public static function parse(string $value): ?array
    {
        $value = trim($value);

        if ($value === '' OR ! preg_match('/^(-?\d+(?:[.,]\d+)?)\s*([a-zа-яё]+)$/iu', $value, $m))
        {
            return NULL;
        }

        $amount = (float) str_replace(',', '.', $m[1]);
        $unit   = self::_unit_code($m[2]);

        if ($unit === NULL OR $amount < 0)
        {
            return NULL;
        }

        return ['amount' => $amount, 'unit' => $unit, 'bytes' => $amount * self::UNITS[$unit][0]];
    }

    protected static function _unit_code(string $raw): ?string
    {
        $raw = function_exists('mb_strtolower')
            ? mb_strtolower(trim($raw), 'UTF-8')
            : strtolower(trim($raw));

        $aliases = [
            'bit' => 'bit', 'бит' => 'bit', 'b' => 'bit',
            'byte' => 'B', 'байт' => 'B',
            'kib' => 'KiB', 'киб' => 'KiB', 'кибибайт' => 'KiB',
            'kb' => 'KB', 'кб' => 'KB', 'кбайт' => 'KB', 'килобайт' => 'KB',
            'mib' => 'MiB', 'миб' => 'MiB', 'мебибайт' => 'MiB',
            'mb' => 'MB', 'мб' => 'MB', 'мбайт' => 'MB', 'мегабайт' => 'MB',
            'gib' => 'GiB', 'гиб' => 'GiB', 'гибибайт' => 'GiB',
            'gb' => 'GB', 'гб' => 'GB', 'гбайт' => 'GB', 'гигабайт' => 'GB',
            'tib' => 'TiB', 'тиб' => 'TiB', 'тебибайт' => 'TiB',
            'tb' => 'TB', 'тб' => 'TB', 'тбайт' => 'TB', 'терабайт' => 'TB',
        ];

        return $aliases[$raw] ?? NULL;
    }

    /** Таблица перевода: каждое название → строка значения. */
    public static function table(float $bytes): array
    {
        $out = [];

        foreach (self::UNITS as $code => $u)
        {
            $out[$code] = self::_fmt($bytes / $u[0]);
        }

        return $out;
    }

    /** Умное «читаемое» значение: подбирается крупнейшая единица без дробной каши. */
    public static function human(float $bytes): string
    {
        foreach (['TiB', 'GiB', 'MiB', 'KiB'] as $code)
        {
            if ($bytes >= self::UNITS[$code][0])
            {
                return self::_fmt($bytes / self::UNITS[$code][0]).' '.$code;
            }
        }

        return self::_fmt($bytes).' байт';
    }

    protected static function _fmt(float $n): string
    {
        if (abs($n) >= 100)
        {
            $s = number_format($n, max(0, 2), '.', ' ');
        }
        elseif (abs($n) >= 1)
        {
            $s = number_format($n, 3, '.', ' ');
        }
        else
        {
            $s = rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');
        }

        return $s;
    }
}
