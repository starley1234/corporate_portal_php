<?php

use PHPUnit\Framework\TestCase;

class TranslitServiceTest extends TestCase {

    public function test_passport_and_icao_transliteration()
    {
        $this->assertSame('Khrushchev', Service_Nj514_Translit::convert('Хрущёв', 'passport')['text']);
        $this->assertSame('PODIEEM', Service_Nj514_Translit::convert('ПОДЪЁМ', 'icao')['text']);
        $this->assertSame('IVANOV<IVAN', Service_Nj514_Translit::convert('Иванов Иван', 'icao')['text']);
    }

    public function test_gost_b_special_letters_and_reverse()
    {
        $this->assertSame('cena', Service_Nj514_Translit::convert('цена', 'gost_b')['text']);
        $this->assertSame("czar'", Service_Nj514_Translit::convert('царь', 'gost_b')['text']);
        $this->assertSame('щука', Service_Nj514_Translit::convert('shhuka', 'gost_b', 'to_cyrillic')['text']);
        $this->assertSame('Ёж', Service_Nj514_Translit::convert('Yozh', 'gost_b', 'to_cyrillic')['text']);
    }

    public function test_passport_reverse_reports_ambiguous_letters()
    {
        $result = Service_Nj514_Translit::convert('E I', 'passport', 'to_cyrillic');
        $this->assertSame('Е И', $result['text']);
        $this->assertCount(2, $result['ambiguities']);
        $this->assertSame(['е', 'ё', 'э'], $result['ambiguities'][0]['choices']);
        $this->assertSame(['и', 'й'], $result['ambiguities'][1]['choices']);
    }

    public function test_unmapped_text_and_limits()
    {
        $this->assertSame('Privet, 123!', Service_Nj514_Translit::convert('Privet, 123!', 'passport')['text']);
        $unicode = Service_Nj514_Translit::convert('😀 E', 'passport', 'to_cyrillic');
        $this->assertSame('😀 Е', $unicode['text']);
        $this->assertSame(3, $unicode['ambiguities'][0]['position']);
        $this->assertNull(Service_Nj514_Translit::convert('текст', 'unknown'));
        $this->assertNull(Service_Nj514_Translit::convert(str_repeat('я', 4001), 'passport'));
        $this->assertNull(Service_Nj514_Translit::convert(['text'], 'passport'));
    }
}
