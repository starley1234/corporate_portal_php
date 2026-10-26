<?php

use PHPUnit\Framework\TestCase;
/**
 * Юнит-тесты PDF-инструментов NJ-634 (парсер, генератор, вырезание страниц).
 *
 * Ключевой сценарий — round-trip: свой генератор делает PDF, свой парсер
 * читает его назад и вынимает текст (включая кириллицу через ToUnicode).
 *
 * Запуск: php tests/mini_runner.php PdfServices
 */
class PdfServicesTest extends TestCase {

    public function test_txt2pdf_roundtrip_ru()
    {
        $r = Service_Nj634_Txt2pdf::build("Привет, мир!\nВторая строка.", 'Заголовок');

        $this->assertTrue(isset($r['ok']) && $r['ok'], 'генерация не удалась: '.($r['error'] ?? ''));
        $this->assertGreaterThan(0, strlen($r['pdf']));

        // парсер должен разобрать свой же файл
        $parsed = Service_Nj634_Parser::text($r['pdf']);
        $this->assertTrue($parsed['ok'], 'парсер не разобрал свой PDF: '.($parsed['error'] ?? ''));
        $this->assertNotEmpty($parsed['text'], 'текст не извлечён');
        $this->assertStringContains($parsed['text'], 'Привет', 'кириллица не восстановилась');
        $this->assertStringContains($parsed['text'], 'Вторая', 'вторая строка не извлечена');
    }

    public function test_txt2pdf_pages()
    {
        $lines = [];
        for ($i = 0; $i < 200; $i++) $lines[] = 'Строка номер '.$i;
        $r = Service_Nj634_Txt2pdf::build(implode("\n", $lines), NULL);

        $this->assertTrue($r['ok'], 'генерация не удалась: '.($r['error'] ?? ''));
        $this->assertGreaterThan(1, $r['pages'], 'ожидалось несколько страниц');

        $meta = Service_Nj634_Parser::meta($r['pdf']);
        $this->assertTrue($meta['ok']);
        $this->assertSame($r['pages'], $meta['pages'], 'meta.pages != сгенерированным страницам');
    }

    public function test_meta_not_pdf()
    {
        $r = Service_Nj634_Parser::meta('GIF89a not a pdf');
        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['error']);
    }

    public function test_text_empty_detects_scan()
    {
        $r = Service_Nj634_Parser::text('GIF89a not a pdf');
        $this->assertFalse($r['ok']);
    }

    public function test_split_pages()
    {
        $lines = [];
        for ($i = 0; $i < 100; $i++) $lines[] = 'Строка '.$i;
        $doc = Service_Nj634_Txt2pdf::build(implode("\n", $lines), NULL);
        $this->assertTrue($doc['ok'] && $doc['pages'] >= 2, 'нужен документ из 2+ страниц');

        $split = Service_Nj634_SplitMerge::extract($doc['pdf'], 2, $doc['pages']);
        $this->assertTrue($split['ok'], 'split не удался: '.($split['error'] ?? ''));
        $this->assertSame($doc['pages'] - 1, $split['pages']);

        $parsed = Service_Nj634_Parser::meta($split['pdf']);
        $this->assertTrue($parsed['ok'], 'парсер не разобрал вырезанный PDF: '.($parsed['error'] ?? ''));
        $this->assertSame($split['pages'], $parsed['pages']);
    }

    public function test_split_range_out_of_bounds()
    {
        $r = Service_Nj634_Txt2pdf::build("одна строка", NULL);
        $this->assertTrue($r['ok']);

        $res = Service_Nj634_SplitMerge::extract($r['pdf'], 5, 9);
        $this->assertFalse($res['ok']);
        $this->assertNotEmpty($res['error']);
    }

    public function test_merge_select_reorder()
    {
        $lines = [];
        for ($i = 0; $i < 100; $i++) $lines[] = 'Строка '.$i;
        $doc = Service_Nj634_Txt2pdf::build(implode("\n", $lines), NULL);
        $this->assertTrue($doc['ok'] && $doc['pages'] >= 3);

        $m = Service_Nj634_SplitMerge::merge_select($doc['pdf'], [3, 1, 2]);
        $this->assertTrue($m['ok'], 'merge_select не удался: '.($m['error'] ?? ''));
        $this->assertSame(3, $m['pages']);

        $parsed = Service_Nj634_Parser::meta($m['pdf']);
        $this->assertTrue($parsed['ok'], 'парсер не разобрал склеенный PDF: '.($parsed['error'] ?? ''));
        $this->assertSame(3, $parsed['pages']);
    }

    public function test_merge_files()
    {
        $a = Service_Nj634_Txt2pdf::build("Первый файл.\nПервая страница.", 'A');
        $b = Service_Nj634_Txt2pdf::build("Второй файл.\nДругой текст.", 'B');
        $this->assertTrue($a['ok'] && $b['ok'], 'генерация для merge_files не удалась');

        $m = Service_Nj634_SplitMerge::merge_files([$a['pdf'], $b['pdf']]);
        $this->assertTrue($m['ok'], 'merge_files не удался: '.($m['error'] ?? ''));
        $this->assertSame(2, $m['pages'], 'ожидалось 2 страницы (по одной от каждого файла)');

        $merged_text = Service_Nj634_Parser::text($m['pdf']);
        $this->assertTrue($merged_text['ok'], 'парсер не разобрал merged PDF');
        $this->assertStringContains($merged_text['text'], 'Первый файл', 'текст первого файла потерян');
        $this->assertStringContains($merged_text['text'], 'Второй файл', 'текст второго файла потерян');
    }

    public function test_unsupported_reason_detects_known_limitations()
    {
        // зашифрованный
        $enc = "%PDF-1.4\n1 0 obj\n<< /Encrypt 10 0 R >>\nendobj\n";
        $this->assertNotNull(Service_Nj634_Parser::unsupported_reason($enc),
            'шифрование должно детектироваться');

        // ObjStm
        $objs = "%PDF-1.4\n1 0 obj\n<< /Type /ObjStm /N 1 >>\nstream\n...\nendstream\nendobj\n";
        $this->assertTrue(stripos((string) Service_Nj634_Parser::unsupported_reason($objs), 'ObjStm') !== FALSE,
            'объектный поток должен детектироваться');

        // обычный файл без ограничений — причина отсутствует
        $ok = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
        $this->assertNull(Service_Nj634_Parser::unsupported_reason($ok),
            'простой классический файл не должен иметь причину отказа');
    }

    public function test_merge_files_rejects_too_many()
    {
        $many = array_fill(0, 13, 'x');
        $r = Service_Nj634_SplitMerge::merge_files($many);
        $this->assertFalse($r['ok']);
    }

    /** Служебный помощник копирования — его нет в mini_runner, пишем напрямую. */
    protected function assertStringContains($haystack, $needle, string $message = ''): void
    {
        $this->assertTrue(
            is_string($haystack) AND mb_strpos($haystack, (string) $needle) !== FALSE,
            $message ?: 'подстрока не найдена: '.var_export($needle, TRUE)
        );
    }
}
