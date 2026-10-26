<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj664_Decode (nj664_jsondecode — декодер JSON).
 *
 * Запуск: php tests/mini_runner.php JsonDecodeService
 */
class JsonDecodeServiceTest extends TestCase {

    public function test_plain_json_is_not_touched()
    {
        $r = Service_Nj664_Decode::decode('{"a":1,"b":[1,2]}');

        $this->assertTrue($r['ok']);
        $this->assertSame(Service_Nj664_Decode::MODE_RAW, $r['mode']);
        $this->assertSame(2, $r['data']['b'][1]);
    }

    public function test_escaped_json()
    {
        // Самый частый вид: JSON, завёрнутый в строку лога или ответа API
        $r = Service_Nj664_Decode::decode('{\"InsuranceCompanies\":{\"source\":\"investopedia.com\"}}');

        $this->assertTrue($r['ok']);
        $this->assertSame(Service_Nj664_Decode::MODE_SLASHES, $r['mode']);
        $this->assertSame('investopedia.com', $r['data']['InsuranceCompanies']['source']);
    }

    public function test_url_encoded_json()
    {
        $r = Service_Nj664_Decode::decode('%7B%22a%22%3A1%7D');

        $this->assertTrue($r['ok']);
        $this->assertSame(Service_Nj664_Decode::MODE_URL, $r['mode']);
        $this->assertSame(1, $r['data']['a']);
    }

    public function test_html_entities_json()
    {
        $r = Service_Nj664_Decode::decode('{&quot;a&quot;:1}');

        $this->assertTrue($r['ok']);
        $this->assertSame(Service_Nj664_Decode::MODE_HTML, $r['mode']);
        $this->assertSame(1, $r['data']['a']);
    }

    public function test_quoted_json()
    {
        $r = Service_Nj664_Decode::decode("'{\"a\":1}'");

        $this->assertTrue($r['ok']);
        $this->assertSame(Service_Nj664_Decode::MODE_QUOTED, $r['mode']);
    }

    public function test_backslash_in_data_survives()
    {
        // Честный JSON с путём Windows: догадки не должны его портить
        $r = Service_Nj664_Decode::decode('{"path":"C:\\\\temp"}');

        $this->assertTrue($r['ok']);
        $this->assertSame('C:\\temp', $r['data']['path']);
    }

    public function test_unicode_is_kept_alive()
    {
        $r = Service_Nj664_Decode::decode('{"name":"Хрущёв"}');

        $this->assertSame("{\n    \"name\": \"Хрущёв\"\n}", Service_Nj664_Decode::beautify($r['data']));
    }

    public function test_empty_input()
    {
        $r = Service_Nj664_Decode::decode('');

        $this->assertFalse($r['ok']);
        $this->assertNotSame('', $r['error']);
    }

    public function test_broken_json_has_position()
    {
        $r = Service_Nj664_Decode::decode("{\n  \"a\": [1, 2}\n]");

        $this->assertFalse($r['ok']);
        $this->assertSame(2, $r['line']);
        $this->assertGreaterThan(0, $r['col']);
    }

    public function test_too_big_input()
    {
        $r = Service_Nj664_Decode::decode(str_repeat('a', 3 * 1024 * 1024));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('МБ', $r['error']);
    }

    public function test_beautify_and_minify()
    {
        $data = json_decode('{"a":1,"b":"два"}', TRUE);

        $this->assertStringContainsString("\n    \"a\": 1", Service_Nj664_Decode::beautify($data));
        $this->assertSame('{"a":1,"b":"два"}', Service_Nj664_Decode::minify($data));
    }

    public function test_php_array_matches_print_r()
    {
        $data = json_decode('{"No":"1","Name":"Berkshire","nested":{"x":true,"y":null,"z":1.5}}', TRUE);

        // print_r() ставит в конце перевод строки — в выводе на странице он лишний
        $this->assertSame(print_r($data, TRUE), Service_Nj664_Decode::php_array($data)."\n");
    }

    public function test_php_array_of_deep_structure()
    {
        $deep = json_decode('{"a":{"b":[1,{"c":"d"}]},"e":[]}', TRUE);

        $this->assertSame(print_r($deep, TRUE), Service_Nj664_Decode::php_array($deep)."\n");
        $this->assertSame("Array\n(\n)", Service_Nj664_Decode::php_array([]));
    }

    public function test_php_code_is_ready_to_paste()
    {
        $data = json_decode('{"Name":"Berkshire"}', TRUE);
        $code = Service_Nj664_Decode::php_code($data);

        $this->assertStringStartsWith('<?php', $code);
        $this->assertStringContainsString('json_decode($json, TRUE)', $code);
        $this->assertStringContainsString('json_last_error()', $code);
        $this->assertStringContainsString('[Name] => Berkshire', $code);
    }

    public function test_stats()
    {
        $list = Service_Nj664_Decode::decode('[1,2,3]');
        $this->assertSame('массив (список)', Service_Nj664_Decode::stats($list['data'])['type']);
        $this->assertSame(4, Service_Nj664_Decode::stats($list['data'])['nodes']);
        $this->assertSame(1, Service_Nj664_Decode::stats($list['data'])['depth']);

        $object = Service_Nj664_Decode::decode('{"a":{"b":{"c":1}}}');
        $this->assertSame('объект', Service_Nj664_Decode::stats($object['data'])['type']);
        $this->assertSame(3, Service_Nj664_Decode::stats($object['data'])['depth']);
        $this->assertSame(3, Service_Nj664_Decode::stats($object['data'])['keys']);

        $number = Service_Nj664_Decode::decode('42');
        $this->assertSame('целое число', Service_Nj664_Decode::stats($number['data'])['type']);
    }

    public function test_sample_is_decodable()
    {
        $r = Service_Nj664_Decode::decode(Service_Nj664_Decode::sample());

        $this->assertTrue($r['ok']);
        $this->assertCount(2, $r['data']['InsuranceCompanies']['top']);
    }

    public function test_mode_label()
    {
        $this->assertSame('как есть — это уже JSON', Service_Nj664_Decode::mode_label(Service_Nj664_Decode::MODE_RAW));
        $this->assertNotSame('', Service_Nj664_Decode::mode_label('unknown'));
    }
}
