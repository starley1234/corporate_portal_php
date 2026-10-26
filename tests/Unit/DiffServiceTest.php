<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj649_Diff (nj649_diff — сравнение текстов).
 *
 * Запуск: php tests/mini_runner.php DiffService
 */
class DiffServiceTest extends TestCase {

    protected function _types(array $rows)
    {
        return array_column($rows, 'type');
    }

    public function test_identical_texts()
    {
        $r = Service_Nj649_Diff::compare("раз\nдва\nтри", "раз\nдва\nтри");

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['stats']['identical']);
        $this->assertSame(3, $r['stats']['same']);
        $this->assertSame(100.0, $r['stats']['similarity']);
        $this->assertSame('', $r['unified'], 'Без изменений заплатка пустая');
    }

    public function test_changed_line_is_shown_as_a_pair()
    {
        $r = Service_Nj649_Diff::compare("раз\nдва\nтри", "раз\nДВАдцать\nтри");

        $this->assertSame(['eq', 'mod', 'eq'], $this->_types($r['rows']));
        $this->assertSame(1, $r['stats']['changed']);
        $this->assertSame(0, $r['stats']['added']);
        $this->assertSame(0, $r['stats']['removed']);
        $this->assertSame(2, $r['rows'][1]['ln']);
        $this->assertSame(2, $r['rows'][1]['rn']);
    }

    public function test_added_and_removed_lines()
    {
        $added = Service_Nj649_Diff::compare("раз\nтри", "раз\nдва\nтри");
        $this->assertSame(['eq', 'ins', 'eq'], $this->_types($added['rows']));
        $this->assertSame(1, $added['stats']['added']);

        $removed = Service_Nj649_Diff::compare("раз\nдва\nтри", "раз\nтри");
        $this->assertSame(['eq', 'del', 'eq'], $this->_types($removed['rows']));
        $this->assertSame(1, $removed['stats']['removed']);
    }

    public function test_options_relax_the_comparison()
    {
        $a = "Договор\n  поставки  товара";
        $b = "договор\nпоставки товара";

        $strict = Service_Nj649_Diff::compare($a, $b);
        $this->assertFalse($strict['stats']['identical'], 'Без послаблений это разные строки');

        $relaxed = Service_Nj649_Diff::compare($a, $b, ['case' => TRUE, 'space' => TRUE]);
        $this->assertTrue($relaxed['stats']['identical'], 'С послаблениями — один и тот же текст');

        // Показываем ВСЕГДА исходную строку, а не нормализованный ключ
        $this->assertSame('Договор', $relaxed['rows'][0]['left']);
        $this->assertSame('договор', $relaxed['rows'][0]['right']);
    }

    public function test_empty_lines_can_be_skipped()
    {
        $a = "раз\n\n\nдва";
        $b = "раз\nдва";

        $this->assertFalse(Service_Nj649_Diff::compare($a, $b)['stats']['identical']);
        $this->assertTrue(Service_Nj649_Diff::compare($a, $b, ['empty' => TRUE])['stats']['identical']);
    }

    public function test_word_level_highlight_marks_only_the_difference()
    {
        list($left, $right) = Service_Nj649_Diff::words_diff('цена 100 рублей', 'цена 200 рублей');

        $this->assertSame([['eq', 'цена '], ['del', '100'], ['eq', ' рублей']], $left);
        $this->assertSame([['eq', 'цена '], ['ins', '200'], ['eq', ' рублей']], $right);
    }

    public function test_rows_carry_word_parts_when_asked()
    {
        $r = Service_Nj649_Diff::compare('цена 100 рублей', 'цена 200 рублей', ['words' => TRUE]);

        $this->assertSame('mod', $r['rows'][0]['type']);
        $this->assertTrue(isset($r['rows'][0]['left_parts']), 'Подсветка слов должна попасть в строку');

        $plain = Service_Nj649_Diff::compare('цена 100 рублей', 'цена 200 рублей');
        $this->assertFalse(isset($plain['rows'][0]['left_parts']), 'Без галочки слов не считаем');
    }

    public function test_unified_diff_is_a_real_patch()
    {
        $r = Service_Nj649_Diff::compare("раз\nдва\nтри", "раз\nдва с половиной\nтри");
        $lines = explode("\n", $r['unified']);

        $this->assertSame('--- было', $lines[0]);
        $this->assertSame('+++ стало', $lines[1]);
        $this->assertMatchesRegularExpression('/^@@ -\d+,\d+ \+\d+,\d+ @@$/', $lines[2]);
        $this->assertTrue(in_array('-два', $lines, TRUE));
        $this->assertTrue(in_array('+два с половиной', $lines, TRUE));
        $this->assertTrue(in_array(' раз', $lines, TRUE), 'Контекст идёт с пробелом в начале');
    }

    public function test_long_document_uses_anchors_and_stays_exact()
    {
        // 300 строк, одна вставка в середине: полный перебор тут не нужен,
        // задачу решают якоря — и результат обязан остаться точным
        $a = [];

        for ($i = 1; $i <= 300; $i++)
        {
            $a[] = 'строка номер '.$i;
        }

        $b = $a;
        array_splice($b, 150, 0, ['вставленная строка']);

        $r = Service_Nj649_Diff::compare(implode("\n", $a), implode("\n", $b));

        $this->assertSame(1, $r['stats']['added']);
        $this->assertSame(0, $r['stats']['removed']);
        $this->assertSame(0, $r['stats']['changed']);
        $this->assertSame(300, $r['stats']['same']);
    }

    public function test_limits_are_refused_honestly()
    {
        $huge = str_repeat("строка\n", Service_Nj649_Diff::MAX_LINES + 10);
        $r = Service_Nj649_Diff::compare($huge, 'раз');

        $this->assertFalse($r['ok']);
        $this->assertTrue(mb_strpos($r['error'], 'строк') !== FALSE, 'Причина отказа названа');

        $long = str_repeat('x', Service_Nj649_Diff::MAX_CHARS + 1);
        $this->assertFalse(Service_Nj649_Diff::compare($long, 'раз')['ok']);
    }

    public function test_empty_input_is_not_an_error()
    {
        $r = Service_Nj649_Diff::compare('', "раз\nдва");

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['stats']['added']);
        $this->assertSame(0, $r['stats']['lines_a']);
    }
}
