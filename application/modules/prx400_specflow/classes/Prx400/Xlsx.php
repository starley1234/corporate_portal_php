<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Минимальная запись XLSX (Office Open XML) без php-zip и PhpSpreadsheet:
 * ZIP собирается вручную (deflate zlib-ом), строки — inlineStr, числа — <v>.
 * Первая строка листа — заголовок (жирный, закреплён). Формулы не пишутся:
 * текст из скана остаётся текстом. Обратное чтение — Prx_Spreadsheet::read.
 *
 *   $bytes = Prx400_Xlsx::build(['Позиции' => [['№', 'Наименование'], [1, 'Болт']]]);
 */
final class Prx400_Xlsx
{
    /** @param array<string, array<int, array>> $sheets имя листа → строки ячеек (string|int|float|null) */
    public static function build(array $sheets): string
    {
        if ($sheets === []) throw new InvalidArgumentException('prx400 xlsx: нет листов');
        $names = [];
        $parts = [];
        $i = 0;
        foreach ($sheets as $name => $rows)
        {
            $i++;
            $names[$i] = self::sheet_name((string) $name, $names);
            $parts['xl/worksheets/sheet'.$i.'.xml'] = self::sheet($rows);
        }

        $sheet_xml = $rels = $types = '';
        foreach ($names as $n => $name)
        {
            $sheet_xml .= '<sheet name="'.self::esc($name).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $styles_id = count($names) + 1;
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";

        $files = [
            '[Content_Types].xml' => $head.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .$types.'</Types>',
            '_rels/.rels' => $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => $head.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets>'.$sheet_xml.'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .$rels.'<Relationship Id="rId'.$styles_id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => $head.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
                .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                .'</styleSheet>',
        ] + $parts;

        return self::zip($files);
    }

    private static function sheet(array $rows): string
    {
        $xml = '';
        $widths = [];
        foreach (array_values($rows) as $r => $cells)
        {
            $xml .= '<row r="'.($r + 1).'">';
            foreach (array_values((array) $cells) as $c => $value)
            {
                $ref = self::column($c).($r + 1);
                $style = $r === 0 ? ' s="1"' : '';
                if ($value === NULL OR $value === '') continue;
                if ((is_int($value) OR is_float($value)) AND is_finite((float) $value))
                {
                    $xml .= '<c r="'.$ref.'"'.$style.'><v>'.rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.').'</v></c>';
                    $len = 10;
                }
                else
                {
                    $text = self::clean((string) $value);
                    $xml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.self::esc($text).'</t></is></c>';
                    $len = min(60, mb_strlen($text));
                }
                $widths[$c] = max($widths[$c] ?? 6, $len + 2);
            }
            $xml .= '</row>';
        }
        $cols = '';
        foreach ($widths as $c => $w) $cols .= '<col min="'.($c + 1).'" max="'.($c + 1).'" width="'.$w.'" customWidth="1"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.$xml.'</sheetData></worksheet>';
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) $name = chr(65 + ($n - 1) % 26).$name;

        return $name;
    }

    /** Имя листа: до 31 символа, без []:*?/\, уникальное. */
    private static function sheet_name(string $name, array $taken): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name)) ?: 'Лист';
        $base = mb_substr($name, 0, 28);
        $candidate = mb_substr($name, 0, 31);
        for ($n = 2; in_array($candidate, $taken, TRUE); $n++) $candidate = $base.' '.$n;

        return $candidate;
    }

    /** Символы, недопустимые в XML 1.0, — вон (OCR приносит управляющие). */
    private static function clean(string $text): string
    {
        $text = mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return mb_substr(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text) ?? '', 0, 32000);
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** ZIP (deflate) из [имя => содержимое]. */
    private static function zip(array $files): string
    {
        $data = '';
        $central = '';
        $time = 0;
        $date = (1 << 5) | 1 | ((2020 - 1980) << 9); // фиксированная дата: одинаковый вход — одинаковый файл
        foreach ($files as $name => $content)
        {
            $deflated = gzdeflate($content, 6);
            if ($deflated === FALSE) throw new RuntimeException('prx400 xlsx: deflate');
            $crc = crc32($content);
            $offset = strlen($data);
            $header = pack('vvvvvVVVvv', 20, 0x0800, 8, $time, $date, $crc, strlen($deflated), strlen($content), strlen($name), 0);
            $data .= "PK\x03\x04".$header.$name.$deflated;
            $central .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 8, $time, $date, $crc, strlen($deflated), strlen($content),
                strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }

        return $data.$central."PK\x05\x06".pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }
}
