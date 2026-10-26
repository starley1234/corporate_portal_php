<?php

use PHPUnit\Framework\TestCase;
/**
 * Юнит-тесты Service_Nj509_Password (nj509_password).
 *
 * ГСЧ не детерминированный — проверяем свойства: длины, алфавиты,
 * гарантированное вхождение наборов, исключение похожих, границы.
 */
class PasswordServiceTest extends TestCase {

    public function test_defaults()
    {
        $r = Service_Nj509_Password::generate();
        $this->assertCount(1, $r['items']);
        $this->assertSame(16, strlen($r['items'][0]));
        $this->assertGreaterThan(0, $r['entropy']);
    }

    public function test_length_and_count()
    {
        $r = Service_Nj509_Password::generate(['length' => 32, 'count' => 5]);
        $this->assertCount(5, $r['items']);
        foreach ($r['items'] as $pw)
        {
            $this->assertSame(32, strlen($pw));
        }
    }

    public function test_charset_subset()
    {
        $r = Service_Nj509_Password::generate(['length' => 64, 'digits' => FALSE]);
        foreach (str_split($r['items'][0]) as $char)
        {
            $this->assertNotFalse(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', $char),
                "символ «{$char}» вне разрешённого алфавита");
        }
    }

    public function test_each_set_present()
    {
        // при 4 наборах и длине 32 каждый набор обязан встретиться
        $r = Service_Nj509_Password::generate(['length' => 32, 'symbols' => TRUE]);

        $this->assertTrue((bool) preg_match('/[A-Z]/', $r['items'][0]), 'нет A-Z');
        $this->assertTrue((bool) preg_match('/[a-z]/', $r['items'][0]), 'нет a-z');
        $this->assertTrue((bool) preg_match('/[0-9]/', $r['items'][0]), 'нет 0-9');
        $this->assertTrue((bool) preg_match('/[!@#\$%\^&\*\(\)\-_\=\+\[\]\{\};:,\.\?\/~]/', $r['items'][0]), 'нет символов');
    }

    public function test_exclude_ambiguous()
    {
        for ($i = 0; $i < 20; $i++)
        {
            $r = Service_Nj509_Password::generate(['length' => 48, 'exclude_ambiguous' => TRUE]);
            foreach (str_split($r['items'][0]) as $char)
            {
                $this->assertFalse(strpos('0O1lI|`\'"', $char),
                    "похожий символ «{$char}» не исключён");
            }
        }
    }

    public function test_invalid_options()
    {
        $this->assertSame(NULL, Service_Nj509_Password::generate(['length' => 5]));
        $this->assertSame(NULL, Service_Nj509_Password::generate(['length' => 97]));
        $this->assertSame(NULL, Service_Nj509_Password::generate([
            'upper' => FALSE, 'lower' => FALSE, 'digits' => FALSE, 'symbols' => FALSE,
        ]));
    }

    public function test_strength()
    {
        $this->assertSame('слабый',  Service_Nj509_Password::strength(30)[0]);
        $this->assertSame('средний', Service_Nj509_Password::strength(50)[0]);
        $this->assertSame('крепкий', Service_Nj509_Password::strength(70)[0]);
        $this->assertSame('бронированный', Service_Nj509_Password::strength(100)[0]);
    }

    public function test_entropy_matches_pool()
    {
        // 16 символов, 62 символа пула → 16*log2(62) ≈ 95.3
        $r = Service_Nj509_Password::generate(['length' => 16]);
        $this->assertEqualsWithDelta(95.3, $r['entropy'], 0.2);
        $this->assertSame(62, $r['pool_size']);
    }
}
