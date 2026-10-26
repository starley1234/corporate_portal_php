<?php defined('SYSPATH') or die('No direct script access.');

/**
 * CSV ↔ JSON (модуль NJ-632).
 *
 * Честный CSV с кавычками и экранированием: первая строка — заголовки.
 * JSON на входе — массив однородных объектов (или массив массивов).
 */
class Service_Nj632_Csv {

    /** Допустимые разделители. */
    public static function delimiters(): array
    {
        return [
            ',' => 'запятая (,)',
            ';' => 'точка с запятой (;)',
            "\t" => 'табуляция',
            '|' => 'вертикальная черта (|)',
        ];
    }

    /** CSV → массив строк (с учётом кавычек). */
    public static function parse(string $csv, string $delimiter = ','): array
    {
        $rows  = [];
        $line  = '';
        $field = '';
        $in_quotes = false;
        $len = strlen($csv);

        for ($i = 0; $i < $len; $i++)
        {
            $ch = $csv[$i];

            if ($in_quotes)
            {
                if ($ch === '"')
                {
                    if ($i + 1 < $len AND $csv[$i + 1] === '"')
                    {
                        $field .= '"';
                        $i++;
                    }
                    else
                    {
                        $in_quotes = false;
                    }
                }
                else
                {
                    $field .= $ch;
                }
            }
            else
            {
                if ($ch === '"')
                {
                    $in_quotes = true;
                }
                elseif ($ch === $delimiter)
                {
                    $line .= self::_field($field)."\x1F";
                    $field = '';
                }
                elseif ($ch === "\n" OR $ch === "\r")
                {
                    if ($ch === "\r" AND $i + 1 < $len AND $csv[$i + 1] === "\n")
                    {
                        $i++;
                    }

                    $line .= self::_field($field)."\x1F";
                    $rows[] = self::_split_row($line);
                    $line = '';
                    $field = '';
                }
                else
                {
                    $field .= $ch;
                }
            }
        }

        // финальная строка без переноса
        if ($field !== '' OR $line !== '')
        {
            $line .= self::_field($field)."\x1F";
            $rows[] = self::_split_row($line);
        }

        return $rows;
    }

    /** CSV → JSON (массив объектов с заголовками), либо NULL при ошибке. */
    public static function to_json(string $csv, string $delimiter = ','): ?string
    {
        $rows = self::parse($csv, $delimiter);

        if (empty($rows))
        {
            return NULL;
        }

        $header = array_map('trim', $rows[0]);
        $out    = [];

        for ($i = 1; $i < count($rows); $i++)
        {
            $row = [];

            foreach ($header as $j => $key)
            {
                $value = $rows[$i][$j] ?? '';
                $row[$key !== '' ? $key : 'col'.($j + 1)] = $value;
            }

            $out[] = $row;
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** JSON → CSV (массив объектов или массивов), NULL при ошибке. */
    public static function from_json(string $json, string $delimiter = ','): ?string
    {
        $data = json_decode($json, true);

        if ( ! is_array($data) OR empty($data))
        {
            return NULL;
        }

        $flat = self::_flat($data);

        if ($flat === NULL)
        {
            return NULL;
        }

        $header = array_unique(array_keys($flat[0]));
        $out    = [implode($delimiter, array_map([self::class, '_cell'], $header))];

        foreach ($flat as $row)
        {
            $line = [];

            foreach ($header as $k)
            {
                $line[] = self::_cell($row[$k] ?? '');
            }

            $out[] = implode($delimiter, $line);
        }

        return implode("\n", $out)."\n";
    }

    /** Заголовки и строки в единый формат (список ассоциативных массивов). */
    protected static function _flat(array $data): ?array
    {
        if (array_is_list($data))
        {
            if (isset($data[0]) AND is_array($data[0]))
            {
                $flat = [];

                foreach ($data as $row)
                {
                    if ( ! is_array($row))
                    {
                        return NULL;
                    }

                    if (array_is_list($row))
                    {
                        $assoc = [];
                        foreach (array_values($row) as $i => $v)
                        {
                            $assoc['col'.($i + 1)] = $v;
                        }
                        $flat[] = $assoc;
                    }
                    else
                    {
                        $flat[] = $row;
                    }
                }

                return $flat;
            }

            return NULL;
        }

        return [$data];
    }

    /** Ячейка CSV с кавычками по RFC 4180. */
    protected static function _cell($value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        if (strpos($value, '"') !== false OR strpos($value, ',') !== false OR strpos($value, ";") !== false OR strpos($value, "\n") !== false)
        {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    protected static function _field(string $field): string
    {
        return rtrim($field);
    }

    /** Разбор строки с разделителем \x1F обратно в массив. */
    protected static function _split_row(string $line): array
    {
        if ($line === '')
        {
            return [];
        }

        return explode("\x1F", rtrim($line, "\x1F"));
    }
}
