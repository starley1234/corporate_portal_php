<?php

use PHPUnit\Framework\TestCase;

/**
 * Prx_Spreadsheet без базы: CSV-диалекты (разделители, BOM, Windows-1251),
 * XLSX как пишет Excel (общие строки, inlineStr, разреженные ячейки, лист
 * не первый в архиве) и враждебные файлы (DOCTYPE, zip-бомба, потолки).
 *
 * XLSX собираются в тесте своим мини-ZIP — php-zip не нужен (как и читателю).
 */
class PrxSpreadsheetTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        $this->files = [];
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'prxss');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }

    /** Мини-ZIP: [имя => содержимое]; $lie — подменить заявленный размер (бомба). */
    private static function zip(array $parts, bool $deflate = TRUE, ?int $lie = NULL): string
    {
        $body = '';
        $cd = '';
        foreach ($parts as $name => $data)
        {
            $packed = $deflate ? gzdeflate($data) : $data;
            $method = $deflate ? 8 : 0;
            $usize = $lie ?? strlen($data);
            $offset = strlen($body);
            $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0, crc32($data), strlen($packed), $usize, strlen($name), 0).$name.$packed;
            $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, 0, 0, crc32($data), strlen($packed), $usize,
                strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }

        return $body.$cd.pack('VvvvvVVv', 0x06054b50, 0, 0, count($parts), count($parts), strlen($cd), strlen($body), 0);
    }

    private static function xlsx(string $sheet_xml, ?string $shared = NULL, array $extra = []): string
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            // Лист «НСИ» лежит в sheet2.xml — читатель обязан идти по workbook/rels, а не брать sheet1.
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="НСИ" sheetId="1" r:id="rId7"/><sheet name="Прочее" sheetId="2" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId7" Type="worksheet" Target="/xl/worksheets/sheet2.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="'.$ns.'"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>не тот лист</t></is></c></row></sheetData></worksheet>',
            'xl/worksheets/sheet2.xml' => $sheet_xml,
        ];
        if ($shared !== NULL) $parts['xl/sharedStrings.xml'] = $shared;

        return self::zip($extra + $parts);
    }

    private function expectCode(string $code, callable $read): void
    {
        try {
            $read();
            $this->fail('ожидалась ошибка '.$code);
        } catch (Prx_DomainError $e) {
            $this->assertSame($code, $e->getMessage());
        }
    }

    public function test_csv_dialects_bom_and_cp1251(): void
    {
        $semicolon = $this->file("\xEF\xBB\xBFКод;Наименование;Ед. изм.\r\nA-1;\"Болт; М12\";шт.\r\n\r\n;;\r\nA-2;Гайка\r\n");
        $this->assertSame([1 => ['Код', 'Наименование', 'Ед. изм.'], 2 => ['A-1', 'Болт; М12', 'шт.'], 5 => ['A-2', 'Гайка']],
            Prx_Spreadsheet::read($semicolon, 10));

        $comma = $this->file("code,name\nA-1,\"Bolt, M12\"\n");
        $this->assertSame([1 => ['code', 'name'], 2 => ['A-1', 'Bolt, M12']], Prx_Spreadsheet::read($comma, 10));

        $tab = $this->file("code\tname\nA-1\tBolt\n");
        $this->assertSame([2 => ['A-1', 'Bolt']], array_slice(Prx_Spreadsheet::read($tab, 10), 1, NULL, TRUE));

        $cp1251 = $this->file(mb_convert_encoding("Код;Наименование\nК-1;Шайба\n", 'Windows-1251', 'UTF-8'));
        $this->assertSame([1 => ['Код', 'Наименование'], 2 => ['К-1', 'Шайба']], Prx_Spreadsheet::read($cp1251, 10));
    }

    public function test_csv_row_ceiling_counts_data_rows_only(): void
    {
        $path = $this->file("code;name\nA;1\nB;2\nC;3\n");
        $this->assertCount(4, Prx_Spreadsheet::read($path, 3));
        $this->expectCode('spreadsheet_too_many_rows', fn () => Prx_Spreadsheet::read($path, 2));
        $this->expectCode('spreadsheet_empty', fn () => Prx_Spreadsheet::read($this->file(''), 2));
        $this->expectCode('spreadsheet_empty', fn () => Prx_Spreadsheet::read($this->file("\n;;\n"), 2));
        $this->expectCode('spreadsheet_format', fn () => Prx_Spreadsheet::read($this->file("\xD0\xCF\x11\xE0\0\0legacy xls"), 2));
        $this->expectCode('spreadsheet_too_many_columns', fn () => Prx_Spreadsheet::read($this->file(implode(';', range(1, 70))."\n"), 2));
    }

    public function test_xlsx_like_excel_writes_it(): void
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $shared = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="'.$ns.'" count="5" uniqueCount="5">'
            .'<si><t>Код</t></si><si><t>Наименование</t></si>'
            .'<si><r><rPr><b/></rPr><t>Болт </t></r><r><t xml:space="preserve">М12 &amp; гайка</t></r><rPh sb="0" eb="1"><t>ФОНЕТИКА</t></rPh></si>'
            .'<si/><si><t>ОКЕИ</t></si></sst>';
        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="'.$ns.'"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1" t="s"><v>4</v></c></row>'
            .'<row r="2"><c r="A2"><v>1001</v></c><c r="B2" t="s"><v>2</v></c><c r="C2" s="3"/><c r="D2" t="str"><f>"0"&amp;"06"</f><v>006</v></c></row>'
            .'<row r="3"/>'
            .'<row r="7"><c r="A7" t="inlineStr"><is><t>NSI-7</t></is></c><c r="B7" t="b"><v>1</v></c><c r="E7" t="s"><v>3</v></c></row>'
            .'<row r="8"><c r="A8" t="s"><v>3</v></c></row>'
            .'</sheetData></worksheet>';

        $rows = Prx_Spreadsheet::read($this->file(self::xlsx($sheet, $shared)), 10);
        $this->assertSame([
            1 => ['Код', 'Наименование', '', 'ОКЕИ'],
            2 => ['1001', 'Болт М12 & гайка', '', '006'],
            7 => ['NSI-7', '1'],
        ], $rows);
    }

    public function test_xlsx_stored_without_compression_and_without_workbook_rels(): void
    {
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row><c t="inlineStr"><is><t>code</t></is></c><c t="inlineStr"><is><t>name</t></is></c></row>'
            .'<row><c><v>5</v></c><c t="inlineStr"><is><t>x</t></is></c></row></sheetData></worksheet>';
        $path = $this->file(self::zip(['xl/worksheets/sheet1.xml' => $sheet], FALSE));
        $this->assertSame([1 => ['code', 'name'], 2 => ['5', 'x']], Prx_Spreadsheet::read($path, 10));
    }

    public function test_hostile_xlsx_is_rejected(): void
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><worksheet xmlns="'.$ns.'"><sheetData>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>&e;</t></is></c></row></sheetData></worksheet>';
        $this->expectCode('spreadsheet_format', fn () => Prx_Spreadsheet::read($this->file(self::xlsx($xxe)), 10));

        // Бомба: каталог заявляет 100 байт, распаковка даёт мегабайт.
        $bomb = self::zip(['xl/worksheets/sheet1.xml' => str_repeat('A', 1 << 20)], TRUE, 100);
        $this->expectCode('spreadsheet_format', fn () => Prx_Spreadsheet::read($this->file($bomb), 10));

        $huge = self::zip(['xl/worksheets/sheet1.xml' => 'x'], TRUE, Prx_Spreadsheet::MAX_PART_BYTES + 1);
        $this->expectCode('spreadsheet_too_large', fn () => Prx_Spreadsheet::read($this->file($huge), 10));

        $wide = '<?xml version="1.0"?><worksheet xmlns="'.$ns.'"><sheetData><row r="1"><c r="ZZ1"><v>1</v></c></row></sheetData></worksheet>';
        $this->expectCode('spreadsheet_too_many_columns', fn () => Prx_Spreadsheet::read($this->file(self::xlsx($wide)), 10));

        $many = '<?xml version="1.0"?><worksheet xmlns="'.$ns.'"><sheetData>';
        for ($i = 1; $i <= 6; $i++) $many .= '<row r="'.$i.'"><c r="A'.$i.'"><v>'.$i.'</v></c></row>';
        $many .= '</sheetData></worksheet>';
        $this->expectCode('spreadsheet_too_many_rows', fn () => Prx_Spreadsheet::read($this->file(self::xlsx($many)), 4));

        $this->expectCode('spreadsheet_format', fn () => Prx_Spreadsheet::read($this->file("PK\x03\x04обрезанный архив"), 10));
        $this->expectCode('spreadsheet_format', fn () => Prx_Spreadsheet::read($this->file(self::zip(['readme.txt' => 'not a workbook'])), 10));
    }
}
