<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты сервиса контроля качества NJ-639 (чистые функции, без БД).
 *
 * Зоны: разбор выходной JSON-схемы, валидация ответа VLM по схеме,
 * разбор data-URL изображений. Работает в mini_runner (никаких внешних
 * библиотек и PostgreSQL не требуется).
 */
class QcServiceTest extends TestCase {

    protected function _service_exists(): bool
    {
        return class_exists('Service_Nj639_Qc');
    }

    protected function _assertService()
    {
        $this->assertTrue($this->_service_exists(), 'Модуль nj639_qc не подключён в bootstrap.php');
    }

    // ------------------------------------------------------------ JSON-схема

    public function test_parse_schema_empty_is_ok_null()
    {
        $this->_assertService();

        $r = Service_Nj639_Qc::parse_schema('');

        $this->assertTrue($r['ok'], 'пустая схема должна быть ok');
        $this->assertNull($r['schema'], 'пустая схема → NULL');
    }

    public function test_parse_schema_object()
    {
        $this->_assertService();

        $r = Service_Nj639_Qc::parse_schema('{"type":"object","required":["verdict"],"properties":{"verdict":{"type":"string"}}}');

        $this->assertTrue($r['ok'], 'валидная схема должна парситься');
        $this->assertSame('object', $r['schema']['type']);
        $this->assertContains('verdict', $r['schema']['required']);
    }

    public function test_parse_schema_invalid_json()
    {
        $this->_assertService();

        $r = Service_Nj639_Qc::parse_schema('{это не json');

        $this->assertFalse($r['ok'], 'кривой JSON должен отбиваться');
        $this->assertNotEmpty($r['error']);
    }

    public function test_parse_schema_unsupported_top_type()
    {
        $this->_assertService();

        $r = Service_Nj639_Qc::parse_schema('{"type":"blob"}');

        $this->assertFalse($r['ok'], 'неподдерживаемый верхний type должен отбиваться');
    }

    // ------------------------------------------------------------ валидация

    public function test_validate_missing_required()
    {
        $this->_assertService();

        $schema = ['type' => 'object', 'required' => ['verdict'], 'properties' => ['verdict' => ['type' => 'string']]];

        [$ok, $verdict, $errors] = Service_Nj639_Qc::validate_verdict(['defects' => []], $schema);

        $this->assertFalse($ok, 'отсутствие обязательного поля — ошибка');
        $this->assertNotEmpty($errors);
        $this->assertSame(['defects' => []], $verdict, 'вердикт должен вернуться нормализованным');
    }

    public function test_validate_type_mismatch()
    {
        $this->_assertService();

        $schema = ['type' => 'object', 'properties' => ['confidence' => ['type' => 'number']]];

        [$ok, , $errors] = Service_Nj639_Qc::validate_verdict(['confidence' => 'высокая'], $schema);

        $this->assertFalse($ok, 'несовпадение типа — ошибка');
        $this->assertNotEmpty($errors);
    }

    public function test_validate_ok()
    {
        $this->_assertService();

        $schema = ['type' => 'object', 'required' => ['verdict'], 'properties' => [
            'verdict' => ['type' => 'string'],
            'defects' => ['type' => 'array'],
            'confidence' => ['type' => 'number'],
        ]];

        [$ok, $verdict, $errors] = Service_Nj639_Qc::validate_verdict([
            'verdict' => 'годен',
            'defects' => [],
            'confidence' => 0.97,
        ], $schema);

        $this->assertTrue($ok, 'валидный вердикт должен пройти');
        $this->assertSame([], $errors);
        $this->assertSame('годен', $verdict['verdict']);
    }

    public function test_validate_additional_properties_false()
    {
        $this->_assertService();

        $schema = ['type' => 'object', 'properties' => ['verdict' => ['type' => 'string']], 'additionalProperties' => FALSE];

        [$ok, , $errors] = Service_Nj639_Qc::validate_verdict(['verdict' => 'годен', 'лишнее' => 1], $schema);

        $this->assertFalse($ok, 'лишнее поле при additionalProperties=false — ошибка');
        $this->assertNotEmpty($errors);
    }

    // ------------------------------------------------------------ data URL

    public function test_decode_data_url_jpeg()
    {
        $this->_assertService();

        // магия JPEG: FF D8 FF
        $bytes = "\xFF\xD8\xFF\xE0" . '00000000';
        $d = Service_Nj639_Qc::decode_data_url('data:image/jpeg;base64,' . base64_encode($bytes));

        $this->assertTrue($d['ok'], 'валидный data URL должен разбираться');
        $this->assertSame('image/jpeg', $d['media_type']);
        $this->assertSame($bytes, $d['bytes']);
    }

    public function test_decode_data_url_not_image()
    {
        $this->_assertService();

        $d = Service_Nj639_Qc::decode_data_url('data:text/plain;base64,' . base64_encode('hello'));

        $this->assertFalse($d['ok'], 'не-изображение должно отбиваться');
    }

    public function test_looks_like_image()
    {
        $this->_assertService();

        $this->assertTrue(Service_Nj639_Qc::looks_like_image("\xFF\xD8\xFF"), 'JPEG-магия');
        $this->assertTrue(Service_Nj639_Qc::looks_like_image("\x89PNG\r\n\x1A\n"), 'PNG-магия');
        $this->assertFalse(Service_Nj639_Qc::looks_like_image('не картинка'), 'текст — не картинка');
    }

    // ------------------------------------------------------- вспомогательные

    public function test_ext_for_media_type()
    {
        $this->_assertService();

        $this->assertSame('jpg', Service_Nj639_Qc::ext_for_media_type('image/jpeg'));
        $this->assertSame('png', Service_Nj639_Qc::ext_for_media_type('image/png'));
        $this->assertSame('webp', Service_Nj639_Qc::ext_for_media_type('image/webp'));
        $this->assertSame('jpg', Service_Nj639_Qc::ext_for_media_type('application/octet-stream'),
            'неизвестный тип → jpg по умолчанию');
    }

    public function test_schema_json_text()
    {
        $this->_assertService();

        $this->assertSame('', Service_Nj639_Qc::schema_json_text(NULL), 'пустая схема → пустая строка');
        $txt = Service_Nj639_Qc::schema_json_text(['type' => 'object']);
        $this->assertNotFalse(json_decode($txt), 'схема сериализуется в валидный JSON');
    }
}
