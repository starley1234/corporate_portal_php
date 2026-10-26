<?php

use PHPUnit\Framework\TestCase;
/**
 * Юнит-тесты входной валидации «Разведки контрагента» (nj635_scout::parse)
 * и реестра типов глобального поиска (nj100_search::sources/normalize_filter).
 *
 * Чистые функции без БД — проверяем детерминированные контракты разбора входа
 * и фильтрации: домен/ИНН/ОГРН/название, SSRF-гард доменов, валидные типы.
 */
class ScoutSearchServicesTest extends TestCase
{
    // ------------------------- nj635 parse -------------------------

    public function test_parse_rejects_empty()
    {
        $r = Service_Nj635_Scout::parse('');
        $this->assertTrue(isset($r['error']), 'пустой вход должен быть отвергнут');
    }

    public function test_parse_domain()
    {
        $r = Service_Nj635_Scout::parse('Romashka.ru');
        $this->assertSame('domain', $r['kind']);
        $this->assertSame('romashka.ru', $r['domain']);
    }

    public function test_parse_domain_with_scheme()
    {
        $r = Service_Nj635_Scout::parse('https://tinkoff.ru/career');
        $this->assertSame('domain', $r['kind']);
        $this->assertSame('tinkoff.ru', $r['domain']);
    }

    public function test_parse_inn()
    {
        $r = Service_Nj635_Scout::parse('7707083893');
        $this->assertSame('inn', $r['kind']);
        $this->assertSame('7707083893', $r['inn']);
    }

    public function test_parse_ogrn()
    {
        $r = Service_Nj635_Scout::parse('1027700132195');
        $this->assertSame('ogrn', $r['kind']);
        $this->assertSame('1027700132195', $r['ogrn']);
    }

    public function test_parse_name()
    {
        $r = Service_Nj635_Scout::parse('ООО «Ромашка»');
        $this->assertSame('name', $r['kind']);
        $this->assertSame('ООО «Ромашка»', $r['company']);
    }

    public function test_parse_rejects_bad_number_length()
    {
        // 9 цифр — не ИНН и не ОГРН, но числовой ввод
        $r = Service_Nj635_Scout::parse('123456789');
        $this->assertTrue(isset($r['error']), 'число неверной длины должно быть отвергнуто явно');
    }

    public function test_parse_blocks_localhost()
    {
        $r = Service_Nj635_Scout::parse('localhost');
        $this->assertTrue(isset($r['error']), 'localhost должен блокироваться (SSRF-гард)');
    }

    public function test_parse_blocks_private_ip()
    {
        $r = Service_Nj635_Scout::parse('192.168.1.10');
        $this->assertTrue(isset($r['error']), 'приватный IP должен блокироваться (SSRF-гард)');
    }

    public function test_parse_blocks_metadata_ip()
    {
        // 169.254.169.254 — metadata-адрес провайдеров
        $r = Service_Nj635_Scout::parse('169.254.169.254');
        $this->assertTrue(isset($r['error']), 'metadata IP должен блокироваться');
    }

    public function test_parse_short_name_rejected()
    {
        $r = Service_Nj635_Scout::parse('АБ');
        $this->assertTrue(isset($r['error']), 'название короче 3 символов должно быть отвергнуто');
    }

    // ------------------------- nj100 sources / filter -------------------------

    public function test_sources_contains_all_types()
    {
        $s = Service_Nj100_Search::sources();
        foreach (['orgdocs', 'profile', 'model', 'kb', 'org', 'job',
                  'letter', 'dossier', 'subject'] as $type)
        {
            $this->assertArrayHasKey($type, $s, 'тип '.$type.' должен быть в реестре');
        }
    }

    public function test_normalize_filter_all_when_empty()
    {
        $f = Service_Nj100_Search::normalize_filter(NULL);
        $this->assertSame(count(Service_Nj100_Search::sources()), count($f));
        $this->assertTrue(isset($f['org']), '«всё» должно включать org');
    }

    public function test_normalize_filter_drops_unknown_types()
    {
        $f = Service_Nj100_Search::normalize_filter(['org', 'no_such_type']);
        $this->assertTrue(isset($f['org']));
        $this->assertFalse(isset($f['no_such_type']), 'неизвестный тип должен отбрасываться');
        $this->assertSame(1, count($f));
    }

    public function test_normalize_filter_all_unknown_falls_back_to_all()
    {
        $f = Service_Nj100_Search::normalize_filter(['zzz']);
        $this->assertSame(count(Service_Nj100_Search::sources()), count($f),
            'сплошь неизвестные типы = «всё»');
    }
}
