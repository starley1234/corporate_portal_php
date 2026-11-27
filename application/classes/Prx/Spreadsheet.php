<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Чтение табличных файлов для импорта: CSV и XLSX → строки ячеек-строк.
 *
 *   $rows = Prx_Spreadsheet::read($path, 20000);   // [1 => ['code', 'name'], 2 => ['A-1', 'Болт'], …]
 *
 * Ключ — номер строки в файле (запись CSV / номер строки листа), чтобы ошибки
 * импорта указывали туда, где их видит человек; пустые строки пропускаются.
 *
 * Зачем свой: в проекте нет PhpSpreadsheet, а php-zip есть не везде (песочница,
 * часть хостингов). XLSX — это ZIP с XML: читаем центральный каталог ZIP сами,
 * распаковываем zlib-ом только нужные части (workbook, sharedStrings, первый
 * лист) и идём по XML потоковым XMLReader.
 *
 * Защита (файл приходит от пользователя):
 *  - ZIP: потолок числа записей и распакованного размера части (zip-бомба),
 *    только методы stored/deflate, без ZIP64 и шифрования;
 *  - XML: DOCTYPE запрещён (XXE и «миллиард смехов»), сеть отключена;
 *  - потолок строк и столбцов; пустые строки пропускаются;
 *  - CSV: разделитель ; , или табуляция по первой строке, BOM снимается,
 *    не-UTF-8 читается как Windows-1251 (выгрузки 1С и Excel «CSV»).
 *
 * Ошибки — Prx_DomainError с машинным кодом (spreadsheet_*), текст — у модуля.
 * Формулы не вычисляются: берётся сохранённое Excel значение (<v>).
 */
final class Prx_Spreadsheet
{
    public const MAX_COLUMNS = 64;
    public const MAX_PART_BYTES = 64 * 1024 * 1024;
    public const MAX_ZIP_ENTRIES = 4096;

    /** @return array<int, string[]> номер строки => ячейки; первая — заголовок */
    public static function read(string $path, int $max_rows): array
    {
        if ( ! is_file($path) OR ! is_readable($path)) throw new Prx_DomainError('spreadsheet_unreadable', 400);
        $head = (string) file_get_contents($path, FALSE, NULL, 0, 4);
        if ($head === '') throw new Prx_DomainError('spreadsheet_empty', 400);

        $rows = $head === "PK\x03\x04" ? self::_xlsx($path, $max_rows) : self::_csv($path, $max_rows);
        if ($rows === []) throw new Prx_DomainError('spreadsheet_empty', 400);

        return $rows;
    }

    // ------------------------------------------------------------------
    // CSV
    // ------------------------------------------------------------------

    private static function _csv(string $path, int $max_rows): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === FALSE) throw new Prx_DomainError('spreadsheet_unreadable', 400);
        try {
            $first = (string) fgets($handle);
            if (str_contains($first, "\0")) throw new Prx_DomainError('spreadsheet_format', 415);
            $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
            arsort($counts);
            $delimiter = (string) array_key_first($counts);
            rewind($handle);

            $rows = [];
            $line = 0;
            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== FALSE)
            {
                $line++;
                if ($rows === [] AND isset($cells[0])) $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
                $cells = array_map(static function ($value): string {
                    $value = (string) $value;
                    if ( ! mb_check_encoding($value, 'UTF-8')) $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1251');
                    return trim($value);
                }, $cells);
                self::_push($rows, $cells, $max_rows, $line);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    // ------------------------------------------------------------------
    // XLSX
    // ------------------------------------------------------------------

    private static function _xlsx(string $path, int $max_rows): array
    {
        $zip = self::_zip_index($path);
        $sheet = self::_first_sheet($path, $zip);
        $strings = isset($zip['xl/sharedStrings.xml']) ? self::_shared_strings(self::_zip_part($path, $zip['xl/sharedStrings.xml'])) : [];

        $reader = self::_xml(self::_zip_part($path, $zip[$sheet]));
        $rows = [];
        $row = NULL;
        $next_row = 1;
        try {
            while ($reader->read())
            {
                if ($reader->nodeType === XMLReader::ELEMENT AND $reader->localName === 'row')
                {
                    $number = (int) $reader->getAttribute('r');
                    // Пропущенные номера строк в листе — пустые строки, их и так пропускаем.
                    $next_row = $number > 0 ? $number : $next_row;
                    $row = [];
                    if ($reader->isEmptyElement) { $row = NULL; $next_row++; }
                }
                elseif ($reader->nodeType === XMLReader::ELEMENT AND $reader->localName === 'c' AND $row !== NULL)
                {
                    $column = self::_column_index((string) $reader->getAttribute('r'), count($row));
                    if ($column >= self::MAX_COLUMNS) throw new Prx_DomainError('spreadsheet_too_many_columns', 413);
                    $value = self::_cell($reader, (string) $reader->getAttribute('t'), $strings);
                    while (count($row) < $column) $row[] = '';
                    $row[$column] = trim($value);
                }
                elseif ($reader->nodeType === XMLReader::END_ELEMENT AND $reader->localName === 'row' AND $row !== NULL)
                {
                    self::_push($rows, $row, $max_rows, $next_row);
                    $row = NULL;
                    $next_row++;
                }
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /** Значение ячейки: s — общая строка, inlineStr — <is>, b — 0/1, остальное — <v> как есть. */
    private static function _cell(XMLReader $reader, string $type, array $strings): string
    {
        if ($reader->isEmptyElement) return '';
        $depth = $reader->depth;
        $value = '';
        $inline = '';
        while ($reader->read() AND ! ($reader->nodeType === XMLReader::END_ELEMENT AND $reader->depth === $depth))
        {
            if ($reader->nodeType !== XMLReader::ELEMENT) continue;
            if ($reader->localName === 'v') $value = $reader->readString();
            elseif ($reader->localName === 't' AND $type === 'inlineStr') $inline .= $reader->readString();
        }
        if ($type === 's') return $strings[(int) $value] ?? '';
        if ($type === 'inlineStr') return $inline;

        return $value;
    }

    /** sharedStrings: <si> = <t> или набор <r><t>; фонетика <rPh> не берётся. */
    private static function _shared_strings(string $xml): array
    {
        $reader = self::_xml($xml);
        $out = [];
        $current = NULL;
        $skip_depth = NULL;
        try {
            while ($reader->read())
            {
                if ($reader->nodeType === XMLReader::ELEMENT)
                {
                    if ($reader->localName === 'si') { $current = ''; if ($reader->isEmptyElement) { $out[] = ''; $current = NULL; } }
                    elseif ($reader->localName === 'rPh' AND ! $reader->isEmptyElement) $skip_depth = $reader->depth;
                    elseif ($reader->localName === 't' AND $current !== NULL AND $skip_depth === NULL) $current .= $reader->readString();
                }
                elseif ($reader->nodeType === XMLReader::END_ELEMENT)
                {
                    if ($reader->localName === 'rPh' AND $skip_depth === $reader->depth) $skip_depth = NULL;
                    elseif ($reader->localName === 'si' AND $current !== NULL) { $out[] = $current; $current = NULL; }
                }
            }
        } finally {
            $reader->close();
        }

        return $out;
    }

    /** Путь первого листа по workbook.xml + rels; иначе xl/worksheets/sheet1.xml. */
    private static function _first_sheet(string $path, array $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';
        if (isset($zip['xl/workbook.xml'], $zip['xl/_rels/workbook.xml.rels']))
        {
            $rid = NULL;
            $reader = self::_xml(self::_zip_part($path, $zip['xl/workbook.xml']));
            while ($reader->read())
            {
                if ($reader->nodeType === XMLReader::ELEMENT AND $reader->localName === 'sheet')
                {
                    $rid = $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    break;
                }
            }
            $reader->close();
            if ($rid !== NULL)
            {
                $reader = self::_xml(self::_zip_part($path, $zip['xl/_rels/workbook.xml.rels']));
                while ($reader->read())
                {
                    if ($reader->nodeType === XMLReader::ELEMENT AND $reader->localName === 'Relationship' AND $reader->getAttribute('Id') === $rid)
                    {
                        $target = ltrim((string) $reader->getAttribute('Target'), '/');
                        $target = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                        // Только части внутри xl/ без подъёма по каталогам.
                        if ( ! str_contains($target, '..') AND isset($zip[$target])) { $reader->close(); return $target; }
                    }
                }
                $reader->close();
            }
        }
        if ( ! isset($zip[$fallback])) throw new Prx_DomainError('spreadsheet_format', 415);

        return $fallback;
    }

    /** «C12» → 2; без ссылки — следующая по порядку. */
    private static function _column_index(string $ref, int $next): int
    {
        if ( ! preg_match('/^([A-Z]{1,3})\d*$/', $ref, $m)) return $next;
        $index = 0;
        foreach (str_split($m[1]) as $letter) $index = $index * 26 + (ord($letter) - 64);

        return $index - 1;
    }

    private static function _xml(string $xml): XMLReader
    {
        if (stripos($xml, '<!DOCTYPE') !== FALSE OR stripos($xml, '<!ENTITY') !== FALSE)
        {
            throw new Prx_DomainError('spreadsheet_format', 415);
        }
        $reader = XMLReader::XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);
        if ( ! $reader instanceof XMLReader) throw new Prx_DomainError('spreadsheet_format', 415);

        return $reader;
    }

    // ------------------------------------------------------------------
    // ZIP (только чтение, только то, что нужно для XLSX)
    // ------------------------------------------------------------------

    /** @return array<string, array{method:int, csize:int, usize:int, offset:int, flags:int}> */
    private static function _zip_index(string $path): array
    {
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if ($size === FALSE OR $size < 22 OR $handle === FALSE) throw new Prx_DomainError('spreadsheet_format', 415);
        try {
            $tail_len = min($size, 65557);
            fseek($handle, $size - $tail_len);
            $tail = (string) fread($handle, $tail_len);
            $eocd = strrpos($tail, "PK\x05\x06");
            if ($eocd === FALSE OR strlen($tail) - $eocd < 22) throw new Prx_DomainError('spreadsheet_format', 415);
            $end = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_offset', substr($tail, $eocd + 4, 16));
            if ($end['entries'] === 0xFFFF OR $end['cd_offset'] === 0xFFFFFFFF) throw new Prx_DomainError('spreadsheet_format', 415); // ZIP64
            if ($end['entries'] > self::MAX_ZIP_ENTRIES) throw new Prx_DomainError('spreadsheet_too_large', 413);
            if ($end['cd_offset'] + $end['cd_size'] > $size) throw new Prx_DomainError('spreadsheet_format', 415);

            fseek($handle, $end['cd_offset']);
            $cd = (string) fread($handle, $end['cd_size']);
        } finally {
            fclose($handle);
        }

        $index = [];
        $pos = 0;
        for ($i = 0; $i < $end['entries']; $i++)
        {
            if (substr($cd, $pos, 4) !== "PK\x01\x02" OR strlen($cd) < $pos + 46) throw new Prx_DomainError('spreadsheet_format', 415);
            $e = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vname_len/vextra_len/vcomment_len/vdisk/vint_attr/Vext_attr/Voffset',
                substr($cd, $pos + 4, 42));
            $name = substr($cd, $pos + 46, $e['name_len']);
            $index[$name] = ['method' => $e['method'], 'csize' => $e['csize'], 'usize' => $e['usize'],
                'offset' => $e['offset'], 'flags' => $e['flags'], 'crc' => $e['crc']];
            $pos += 46 + $e['name_len'] + $e['extra_len'] + $e['comment_len'];
        }

        return $index;
    }

    private static function _zip_part(string $path, array $entry): string
    {
        if (($entry['flags'] & 0x1) !== 0) throw new Prx_DomainError('spreadsheet_format', 415); // зашифровано
        if ($entry['usize'] > self::MAX_PART_BYTES OR $entry['csize'] > self::MAX_PART_BYTES) throw new Prx_DomainError('spreadsheet_too_large', 413);

        $handle = fopen($path, 'rb');
        if ($handle === FALSE) throw new Prx_DomainError('spreadsheet_unreadable', 400);
        try {
            fseek($handle, $entry['offset']);
            $local = (string) fread($handle, 30);
            if (strlen($local) !== 30 OR substr($local, 0, 4) !== "PK\x03\x04") throw new Prx_DomainError('spreadsheet_format', 415);
            $h = unpack('vname_len/vextra_len', substr($local, 26, 4));
            fseek($handle, $entry['offset'] + 30 + $h['name_len'] + $h['extra_len']);
            $data = $entry['csize'] > 0 ? (string) fread($handle, $entry['csize']) : '';
        } finally {
            fclose($handle);
        }

        if ($entry['method'] === 0) $out = $data;
        // Потолок распаковки — заявленный размер (0 у gzinflate значит «без предела»).
        elseif ($entry['method'] === 8) $out = @gzinflate($data, max(1, $entry['usize']));
        else throw new Prx_DomainError('spreadsheet_format', 415);

        // Размер из каталога — граница доверия: распаковка не больше заявленного и потолка.
        if ( ! is_string($out) OR strlen($out) !== $entry['usize'] OR crc32($out) !== $entry['crc'])
        {
            throw new Prx_DomainError('spreadsheet_format', 415);
        }

        return $out;
    }

    // ------------------------------------------------------------------

    private static function _push(array &$rows, array $cells, int $max_rows, int $line): void
    {
        while ($cells !== [] AND end($cells) === '') array_pop($cells);
        if ($cells === []) return;
        if (count($cells) > self::MAX_COLUMNS) throw new Prx_DomainError('spreadsheet_too_many_columns', 413);
        if (count($rows) >= $max_rows + 1) throw new Prx_DomainError('spreadsheet_too_many_rows', 413, ['max_rows' => $max_rows]);
        $rows[$line] = array_values($cells);
    }
}
