<?php

use PHPUnit\Framework\TestCase;
/**
 * Юнит-тесты движка наложения NJ-634 Annotate (общего для nj636/nj637/nj638).
 *
 * Round-trip: свой генератор делает PDF, наложение встраивает шрифт с текстом,
 * свой парсер читает текст знака назад (кириллица через ToUnicode).
 * Каждый тест НЕ зависит от pypdf: валидация происходит внутри PHP-стека,
 * чтобы раннер работал в закрытой сети без внешних библиотек.
 */
class WatermarkShareTest extends TestCase {

    protected function _menschen_pdf(): array
    {
        $lines = [];

        for ($i = 0; $i < 90; $i++)
        {
            $lines[] = 'Основной текст строка номер '.$i;
        }

        $doc = Service_Nj634_Txt2pdf::build(implode("\n", $lines), 'Документ теста');

        $this->assertTrue($doc['ok'] && ! empty($doc['pdf']), 'не удалось собрать исходный PDF');

        return $doc;
    }

    public function test_watermark_behind_keeps_text()
    {
        $doc = $this->_menschen_pdf();

        $wm = Service_Nj634_Annotate::watermark($doc['pdf'], 'КОПИЯ — внутренний', ['pages' => 'all']);

        $this->assertTrue($wm['ok'], 'наложение не удалось: '.($wm['error'] ?? ''));
        $this->assertNotEmpty($wm['pdf']);

        // парсер читает свой файл обратно: знак И исходный текст
        $parsed = Service_Nj634_Parser::text($wm['pdf']);

        $this->assertTrue($parsed['ok'], 'парсер не разобрал наложенный PDF: '.($parsed['error'] ?? ''));

        $this->assertTrue(strpos($parsed['text'], 'КОПИЯ') !== FALSE, 'текст знака не восстановился');
        $this->assertTrue(strpos($parsed['text'], 'Основной текст') !== FALSE, 'исходный текст потерян');

        $this->assertSame($doc['pages'], $wm['pages'], 'число страниц изменилось');
    }

    public function test_stamp_positions_and_markers()
    {
        $doc = $this->_menschen_pdf();

        $st = Service_Nj634_Annotate::stamp($doc['pdf'], 'Иванов, 23.09.2026', [
            'pos' => 'br',
            'underline' => TRUE,
            'pages' => 'first',
        ]);

        $this->assertTrue($st['ok'], 'отпечаток не удался: '.($st['error'] ?? ''));

        $parsed = Service_Nj634_Parser::text($st['pdf']);
        $this->assertTrue($parsed['ok']);
        $this->assertTrue(strpos($parsed['text'], 'Иванов') !== FALSE, 'отпечаток не наложен');
        $this->assertSame(1, $st['stamped_pages'], 'отпечаток должен быть на одной странице');
    }

    public function test_watermark_rejects_empty_text()
    {
        $doc = $this->_menschen_pdf();

        $r = Service_Nj634_Annotate::watermark($doc['pdf'], '   ');
        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['error']);
    }

    public function test_pages_range()
    {
        $doc = $this->_menschen_pdf();

        // документ из 2 страниц: диапазон 1-2 покрывает обе
        $r = Service_Nj634_Annotate::watermark($doc['pdf'], 'Только на 1-2', ['pages' => '1-2']);
        $this->assertTrue($r['ok'], 'наложение range не удалось: '.($r['error'] ?? ''));
        $this->assertSame(2, $r['stamped_pages'], 'ожидалось 2 отмеченных страницы');

        // диапазон за пределами честно обрезается до существующих страниц
        $r2 = Service_Nj634_Annotate::watermark($doc['pdf'], 'Только на 2-9', ['pages' => '2-9']);
        $this->assertTrue($r2['ok'], 'обрезание диапазона не удалось: '.($r2['error'] ?? ''));
        $this->assertSame($doc['pages'] - 1, $r2['stamped_pages'], 'диапазон должен обрезаться');
    }
}
