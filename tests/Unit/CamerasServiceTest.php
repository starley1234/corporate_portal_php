<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты сервиса «Камеры цеха» NJ-640 (чистые функции, без БД).
 *
 * Зоны: темы/пресеты, парсинг схемы, валидация вердикта, разбор
 * data-URL, дедупликационный ключ события и разбор вердикта в событие.
 * Работает в mini_runner (никакого PostgreSQL не требуется).
 */
class CamerasServiceTest extends TestCase {

    protected function _service_exists(): bool
    {
        return class_exists('Service_Nj640_Cameras');
    }

    protected function _assertService()
    {
        $this->assertTrue($this->_service_exists(), 'Модуль nj640_cameras не подключён в bootstrap.php');
    }

    // ------------------------------------------------------------------ темы

    public function test_topics_cover_three_entities()
    {
        $this->_assertService();

        foreach (['discipline', 'ppe', 'equipment'] as $topic)
        {
            $this->assertArrayHasKey($topic, Service_Nj640_Cameras::topics(),
                "нет темы $topic");
        }
    }

    public function test_topic_default_schema_required_verdict()
    {
        $this->_assertService();

        foreach (['discipline', 'ppe', 'equipment'] as $topic)
        {
            $schema = Service_Nj640_Cameras::topic_default_schema($topic);

            $this->assertNotNull($schema, "нет схемы темы $topic");
            $this->assertContains('verdict', (array) ($schema['required'] ?? []),
                "схема $topic требует поле verdict");
        }
    }

    public function test_topic_icon_for_nonempty()
    {
        $this->_assertService();

        $this->assertSame('fa-user-clock', Service_Nj640_Cameras::topic_icon('discipline'));
        $this->assertSame('fa-hard-hat', Service_Nj640_Cameras::topic_icon('ppe'));
        $this->assertSame('fa-industry', Service_Nj640_Cameras::topic_icon('equipment'));
        $this->assertSame('fa-user-clock', Service_Nj640_Cameras::topic_icon('unknown'));
    }

    // ------------------------------------------------------------- JSON-схема

    public function test_parse_schema_empty_is_ok_null()
    {
        $this->_assertService();

        $r = Service_Nj640_Cameras::parse_schema('');

        $this->assertTrue($r['ok'], 'пустая схема должна быть ok');
        $this->assertNull($r['schema'], 'пустая схема → NULL');
    }

    public function test_parse_schema_object()
    {
        $this->_assertService();

        $r = Service_Nj640_Cameras::parse_schema('{"type":"object","required":["verdict"],"properties":{"verdict":{"type":"string"}}}');

        $this->assertTrue($r['ok'], 'валидная схема должна парситься');
        $this->assertSame('object', $r['schema']['type']);
        $this->assertContains('verdict', $r['schema']['required']);
    }

    public function test_parse_schema_invalid_json()
    {
        $this->_assertService();

        $r = Service_Nj640_Cameras::parse_schema('{это не json');

        $this->assertFalse($r['ok'], 'кривой JSON должен отбиваться');
        $this->assertNotEmpty($r['error']);
    }

    public function test_parse_schema_unsupported_top_type()
    {
        $this->_assertService();

        $r = Service_Nj640_Cameras::parse_schema('{"type":"blob"}');

        $this->assertFalse($r['ok'], 'неподдерживаемый верхний type должен отбиваться');
    }

    // -------------------------------------------------------------- валидация

    public function test_validate_missing_required()
    {
        $this->_assertService();

        $schema = ['type' => 'object', 'required' => ['verdict'], 'properties' => ['verdict' => ['type' => 'string']]];

        [$ok, , $errors] = Service_Nj640_Cameras::validate_verdict(['violations' => []], $schema);

        $this->assertFalse($ok, 'отсутствие обязательного поля — ошибка');
        $this->assertNotEmpty($errors);
    }

    public function test_validate_type_mismatch()
    {
        $this->_assertService();

        $schema = ['type' => 'object', 'properties' => ['confidence' => ['type' => 'number']]];

        [$ok, , $errors] = Service_Nj640_Cameras::validate_verdict(['confidence' => 'высокая'], $schema);

        $this->assertFalse($ok, 'несовпадение типа — ошибка');
        $this->assertNotEmpty($errors);
    }

    public function test_validate_ok_ppe()
    {
        $this->_assertService();

        $schema = Service_Nj640_Cameras::topic_default_schema('ppe');

        [$ok, $verdict, $errors] = Service_Nj640_Cameras::validate_verdict([
            'verdict' => 'violation',
            'violations' => ['нет каски', 'нет жилета'],
            'confidence' => 0.93,
        ], $schema);

        $this->assertTrue($ok, 'валидный вердикт СИЗ должен пройти');
        $this->assertSame([], $errors);
        $this->assertSame('violation', $verdict['verdict']);
    }

    public function test_validate_additional_properties_false()
    {
        $this->_assertService();

        $schema = ['type' => 'object', 'properties' => ['verdict' => ['type' => 'string']], 'additionalProperties' => FALSE];

        [$ok, , $errors] = Service_Nj640_Cameras::validate_verdict(['verdict' => 'ok', 'лишнее' => 1], $schema);

        $this->assertFalse($ok, 'лишнее поле при additionalProperties=false — ошибка');
        $this->assertNotEmpty($errors);
    }

    public function test_validate_without_schema_is_lax()
    {
        $this->_assertService();

        [$ok, $verdict, $errors] = Service_Nj640_Cameras::validate_verdict(['verdict' => 'ok'], NULL);

        $this->assertTrue($ok, 'без схемы вердикт проходит');
        $this->assertSame([], $errors);
        $this->assertSame('ok', $verdict['verdict']);
    }

    // -------------------------------------------------------------- data URL

    public function test_decode_data_url_jpeg()
    {
        $this->_assertService();

        $bytes = "\xFF\xD8\xFF\xE0" . '00000000';
        $d = Service_Nj640_Cameras::decode_data_url('data:image/jpeg;base64,' . base64_encode($bytes));

        $this->assertTrue($d['ok'], 'валидный data URL должен разбираться');
        $this->assertSame('image/jpeg', $d['media_type']);
        $this->assertSame($bytes, $d['bytes']);
    }

    public function test_decode_data_url_not_image()
    {
        $this->_assertService();

        $d = Service_Nj640_Cameras::decode_data_url('data:text/plain;base64,' . base64_encode('hello'));

        $this->assertFalse($d['ok'], 'не-изображение должно отбиваться');
    }

    public function test_looks_like_image()
    {
        $this->_assertService();

        $this->assertTrue(Service_Nj640_Cameras::looks_like_image("\xFF\xD8\xFF"), 'JPEG-магия');
        $this->assertTrue(Service_Nj640_Cameras::looks_like_image("\x89PNG\r\n\x1A\n"), 'PNG-магия');
        $this->assertFalse(Service_Nj640_Cameras::looks_like_image('не картинка'), 'текст — не картинка');
    }

    // ---------------------------------------------------------------- события

    public function test_event_key_stable_and_normalized()
    {
        $this->_assertService();

        $a = Service_Nj640_Cameras::event_key('ppe', 'violation', ['нет каски', 'нет жилета']);
        $b = Service_Nj640_Cameras::event_key('ppe', 'VIOLATION', [' Нет каски ']);

        $this->assertSame($a, $b, 'ключ дедупликации должен быть стабильным/нормализованным');
        $this->assertTrue(mb_strlen($a) <= 120, 'ключ вписывается в поле');
    }

    public function test_event_key_empty_verdict()
    {
        $this->_assertService();

        $key = Service_Nj640_Cameras::event_key('discipline', '', []);

        $this->assertSame('discipline:n/a', $key);
    }

    public function test_event_from_verdict_discipline_violation()
    {
        $this->_assertService();

        $event = Service_Nj640_Cameras::_event_from_verdict(
            'g:abc', 0, 7, 42, 'discipline',
            ['verdict' => 'violation', 'violations' => ['сон на посту']], TRUE);

        $this->assertNotNull($event, 'нарушение дисциплины → событие');
        $this->assertSame('major', $event['severity']);
        $this->assertSame('Нарушение трудовой дисциплины', $event['title']);
    }

    public function test_event_from_verdict_ppe_violation()
    {
        $this->_assertService();

        $event = Service_Nj640_Cameras::_event_from_verdict(
            'u:12', 3, 5, 43, 'ppe',
            ['verdict' => 'violation', 'violations' => ['нет каски']], TRUE);

        $this->assertNotNull($event, 'нарушение СИЗ → событие');
        $this->assertSame('major', $event['severity']);
        $this->assertSame('СИЗ: нарушение — нет каски', $event['title']);
    }

    public function test_event_from_verdict_equipment_idle()
    {
        $this->_assertService();

        $event = Service_Nj640_Cameras::_event_from_verdict(
            'g:abc', 4, 6, 44, 'equipment',
            ['verdict' => 'idle', 'equipment_state' => 'конвейер стоит'], TRUE);

        $this->assertNotNull($event, 'простой оборудования → событие');
        $this->assertSame('major', $event['severity']);
        $this->assertSame('Оборудование простаивает', $event['title']);
    }

    public function test_event_not_created_for_ok()
    {
        $this->_assertService();

        $event = Service_Nj640_Cameras::_event_from_verdict(
            'g:abc', 0, 5, 45, 'ppe',
            ['verdict' => 'ok', 'violations' => []], TRUE);

        $this->assertNull($event, 'нормальный кадр не создаёт событие');
    }

    public function test_event_not_created_when_schema_failed()
    {
        $this->_assertService();

        $event = Service_Nj640_Cameras::_event_from_verdict(
            'g:abc', 0, 5, 46, 'ppe',
            ['verdict' => 'violation', 'violations' => ['нет каски']], FALSE);

        $this->assertNull($event, 'непрошедшая схема не даёт событие');
    }

    // ---------------------------------------------------------------- summary

    public function test_verdict_summary_extracts_violations()
    {
        $this->_assertService();

        $s = Service_Nj640_Cameras::verdict_summary(['verdict' => 'violation', 'violations' => ['нет каски']]);

        $this->assertNotFalse(mb_strpos($s, 'нет каски'), 'сводка содержит нарушение');
    }

    public function test_verdict_summary_equipment_state()
    {
        $this->_assertService();

        $s = Service_Nj640_Cameras::verdict_summary(['verdict' => 'idle', 'equipment_state' => 'конвейер стоит']);

        $this->assertNotFalse(mb_strpos($s, 'конвейер стоит'), 'сводка содержит состояние оборудования');
    }

    // ------------------------------------------------------------ работа/часы

    public function test_camera_within_hours_no_window()
    {
        $this->_assertService();

        $this->assertTrue(Service_Nj640_Cameras::camera_within_hours(NULL), 'без окна — всегда внутри');
    }

    public function test_camera_within_hours_interval()
    {
        $this->_assertService();

        $this->assertTrue(Service_Nj640_Cameras::camera_within_hours([
            'start' => '00:00', 'end' => '23:59', 'zone' => 'Europe/Moscow',
        ]), 'полный день покрывает сейчас');
    }
}
