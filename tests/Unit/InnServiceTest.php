<?php

use PHPUnit\Framework\TestCase;

/** Проверка ИНН в nj517_inn; ни один тест не вызывает сеть/ФНС. */
class InnServiceTest extends TestCase {

    public function test_valid_legal_entity_inn()
    {
        $result = Service_Nj517_Inn::validate('7707083893');

        $this->assertTrue($result['valid']);
        $this->assertSame('7707083893', $result['inn']);
        $this->assertSame('organization', $result['type']);
        $this->assertSame('Юридическое лицо', $result['type_label']);
    }

    public function test_valid_person_inn()
    {
        $result = Service_Nj517_Inn::validate('500100732259');

        $this->assertTrue($result['valid']);
        $this->assertSame(12, $result['digits']);
        $this->assertSame('person', $result['type']);
        $this->assertSame('Физлицо или ИП', $result['type_label']);
    }

    public function test_separators_are_accepted_but_letters_are_not_silently_removed()
    {
        $this->assertSame('7707083893', Service_Nj517_Inn::validate('7707 083-893')['inn']);
        $this->assertSame('bad_format', Service_Nj517_Inn::validate('ИНН 7707083893')['error']);
    }

    public function test_bad_lengths_and_control_digits_are_rejected()
    {
        $this->assertSame('bad_length', Service_Nj517_Inn::validate('12345')['error']);
        $this->assertSame('bad_checksum', Service_Nj517_Inn::validate('7707083894')['error']);
        $this->assertSame('bad_checksum', Service_Nj517_Inn::validate('500100732260')['error']);
    }

    public function test_empty_and_oversized_input_are_rejected()
    {
        $this->assertSame('empty', Service_Nj517_Inn::validate('  ')['error']);
        $this->assertSame('bad_format', Service_Nj517_Inn::validate(str_repeat('1', 33))['error']);
        $this->assertSame('bad_format', Service_Nj517_Inn::validate(['7707083893'])['error']);
    }

    public function test_12_digit_inn_never_starts_remote_lookup()
    {
        $result = Service_Nj517_Inn::lookup('500100732259');

        $this->assertSame('personal_inn', $result['error']);
        $this->assertStringContainsString('только в браузере', $result['message']);
    }

    public function test_bad_checksum_never_starts_remote_lookup()
    {
        $result = Service_Nj517_Inn::lookup('7707083894');

        $this->assertSame('bad_checksum', $result['error']);
    }
}
