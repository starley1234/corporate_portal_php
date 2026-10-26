<?php

use PHPUnit\Framework\TestCase;
/**
 * Юнит-тесты dev-сервисов NJ-623–632 (roman, base, bytes, unicode, testdata,
 * checksum, color, ipcalc, text, csv).
 *
 * Запуск: php tests/mini_runner.php DevToolsServices
 */
class DevToolsServicesTest extends TestCase {

    // ---------------- римские числа ----------------

    public function test_roman_to()
    {
        $this->assertSame('MCMLXXXVII', Service_Nj623_Roman::to_roman(1987));
        $this->assertSame('MMXXVI',     Service_Nj623_Roman::to_roman(2026));
        $this->assertSame('I',          Service_Nj623_Roman::to_roman(1));
        $this->assertSame('MMMCMXCIX',  Service_Nj623_Roman::to_roman(3999));
        $this->assertNull(Service_Nj623_Roman::to_roman(0));
        $this->assertNull(Service_Nj623_Roman::to_roman(4000));
    }

    public function test_roman_from()
    {
        $this->assertSame(1987, Service_Nj623_Roman::from_roman('MCMLXXXVII'));
        $this->assertSame(14,   Service_Nj623_Roman::from_roman('XIV'));
        $this->assertSame(3999, Service_Nj623_Roman::from_roman('mmmcmxcix'));
        $this->assertNull(Service_Nj623_Roman::from_roman('IIII'));  // нестрогая запись
        $this->assertNull(Service_Nj623_Roman::from_roman(''));
    }

    public function test_roman_convert_auto()
    {
        $this->assertSame('to', Service_Nj623_Roman::convert('1987')['dir']);
        $this->assertSame('from', Service_Nj623_Roman::convert('XIV')['dir']);
        $this->assertNull(Service_Nj623_Roman::convert('abc'));
    }

    // ---------------- системы счисления ----------------

    public function test_base_convert()
    {
        $this->assertSame('11111111', Service_Nj624_Base::convert('ff', 16, 2));
        $this->assertSame('ff',       Service_Nj624_Base::convert('255', 10, 16));
        $this->assertSame('11',       Service_Nj624_Base::convert('3', 10, 2));
        $this->assertNull(Service_Nj624_Base::convert('2', 2, 10)); // «2» нет в двоичной
        $this->assertNull(Service_Nj624_Base::convert('ff', 10, 16));
    }

    public function test_base_bitop()
    {
        $this->assertSame('0',   Service_Nj624_Base::bitop('and', '12', '3')['dec']);
        $this->assertSame('15',  Service_Nj624_Base::bitop('or', '12', '3')['dec']);
        $this->assertSame('-1',  Service_Nj624_Base::bitop('not', '0', '0')['dec']);
        $this->assertSame('510', Service_Nj624_Base::bitop('shl', '255', '0')['dec']);
        $this->assertNull(Service_Nj624_Base::bitop('nope', '1', '2'));
    }

    // ---------------- единицы информации ----------------

    public function test_bytes_parse()
    {
        $this->assertEquals(500000000, Service_Nj625_Bytes::parse('500MB')['bytes']);
        $this->assertEquals(500000000, Service_Nj625_Bytes::parse('500 МБ')['bytes']);
        $this->assertEquals(1536,      Service_Nj625_Bytes::parse('1.5 KiB')['bytes']);
        $this->assertEquals(1,         Service_Nj625_Bytes::parse('8 bit')['bytes']);
        $this->assertNull(Service_Nj625_Bytes::parse('сто гигабайт'));
    }

    public function test_bytes_human()
    {
        $this->assertSame('476.84 MiB', Service_Nj625_Bytes::human(500000000));
    }

    // ---------------- unicode ----------------

    public function test_unicode_analyze()
    {
        $chars = Service_Nj626_Unicode::analyze('П');
        $this->assertSame(1055, $chars[0]['cp']);
        $this->assertSame('U+041F', $chars[0]['uplus']);
        $this->assertSame('D09F', $chars[0]['utf8']);
    }

    public function test_unicode_escapes()
    {
        $this->assertSame('\\u{1F44B}', Service_Nj626_Unicode::esc_u_braces(0x1F44B));
        $this->assertSame('\\uD83D\\uDC4B', Service_Nj626_Unicode::esc_utf16(0x1F44B));
        $decoded = Service_Nj626_Unicode::decode_escapes('\\u041F\\u0440');
        $this->assertSame('Пр', $decoded['text']);
    }

    // ---------------- тестовые данные ----------------

    public function test_testdata_valid_checks()
    {
        for ($i = 0; $i < 8; $i++)
        {
            $c = Service_Nj627_Testdata::company();
            $this->assertTrue(Service_Nj627_Testdata::validate_inn($c['inn']), 'ИНН должен проходить проверку');
            $this->assertTrue(Service_Nj627_Testdata::validate_ogrn($c['ogrn']), 'ОГРН должен проходить проверку');
            $this->assertSame(9, strlen($c['kpp']), 'КПП — 9 цифр');
            $this->assertSame(10, strlen($c['inn']), 'ИНН юрлица — 10 цифр');
        }
    }

    public function test_testdata_types()
    {
        $this->assertArrayHasKey('fio', Service_Nj627_Testdata::one('fio'));
        $this->assertArrayHasKey('phone', Service_Nj627_Testdata::one('phone'));
        $this->assertNull(Service_Nj627_Testdata::one('nope'));
    }

    // ---------------- контрольные цифры ----------------

    public function test_checksum_inn()
    {
        $this->assertTrue(Service_Nj628_Checksum::inn('7707083893')['valid']);
        $this->assertFalse(Service_Nj628_Checksum::inn('7707083894')['valid']);
    }

    public function test_checksum_luhn_ean()
    {
        $this->assertTrue(Service_Nj628_Checksum::luhn('4556 7375 8689 9855')['valid']);
        $this->assertTrue(Service_Nj628_Checksum::ean('4607086561966')['valid']);
        $this->assertFalse(Service_Nj628_Checksum::ean('4607086561963')['valid']);
    }

    public function test_checksum_crc32()
    {
        $r = Service_Nj628_Checksum::crc32('n2j.ru');
        $this->assertSame(8, strlen($r['crc']));
    }

    // ---------------- цвет ----------------

    public function test_color_parse_hex_rgb()
    {
        $this->assertSame([59, 130, 246], Service_Nj629_Color::parse('#3B82F6'));
        $this->assertSame([59, 130, 246], Service_Nj629_Color::parse('59,130,246'));
        $this->assertSame([255, 255, 255], Service_Nj629_Color::parse('#fff'));
        $this->assertNull(Service_Nj629_Color::parse('not a color'));
    }

    public function test_color_report()
    {
        $r = Service_Nj629_Color::report([59, 130, 246]);
        $this->assertSame('#3B82F6', $r['hex']);
        $this->assertSame([217, 91, 60], $r['hsl']);
        $this->assertCount(4, $r['shades']);
    }

    // ---------------- ipcalc ----------------

    public function test_ipcalc()
    {
        $r = Service_Nj630_Ipcalc::report('192.168.1.0/24');
        $this->assertSame('255.255.255.0', $r['mask']);
        $this->assertSame('192.168.1.0', $r['network']);
        $this->assertSame('192.168.1.255', $r['broadcast']);
        $this->assertSame(254, $r['hosts']);
        $this->assertTrue($r['is_private']);
        $this->assertSame('C', $r['class']);
    }

    public function test_ipcalc_invalid()
    {
        $this->assertNull(Service_Nj630_Ipcalc::report('999.1.1.1/24'));
    }

    // ---------------- текст ----------------

    public function test_text_stats()
    {
        $s = Service_Nj631_Text::stats('Привет мир');
        $this->assertSame(10, $s['chars']);
        $this->assertSame(2, $s['words']);
        $this->assertSame(1, $s['lines']);
    }

    public function test_text_apply()
    {
        $this->assertSame('ПРИВЕТ', Service_Nj631_Text::apply('upper', 'привет')['result']);
        $this->assertSame('привет', Service_Nj631_Text::apply('lower', 'ПРИВЕТ')['result']);
        $this->assertSame("a\nb\nc", Service_Nj631_Text::apply('dedupe', "a\na\nb\nc\nc")['result']);
        $this->assertSame("a\nb\nc", Service_Nj631_Text::apply('sort', "c\na\nb")['result']);
        $this->assertNull(Service_Nj631_Text::apply('nope', 'x'));
    }

    // ---------------- CSV ----------------

    public function test_csv_to_json()
    {
        $json = Service_Nj632_Csv::to_json("name,age\nИван,30");
        $this->assertSame([['name' => 'Иван', 'age' => '30']], json_decode($json, true));
    }

    public function test_csv_quotes()
    {
        // поле с запятой внутри кавычек
        $json = Service_Nj632_Csv::to_json("name,age\n\"Мария, Иванова\",25");
        $data = json_decode($json, true);
        $this->assertSame('Мария, Иванова', $data[0]['name']);
    }

    public function test_json_to_csv_roundtrip()
    {
        $csv = Service_Nj632_Csv::from_json('[{"name":"Иван","age":30}]');
        $this->assertSame("name,age\nИван,30\n", $csv);

        $back = Service_Nj632_Csv::to_json($csv);
        $this->assertSame('Иван', json_decode($back, true)[0]['name']);
    }
}
