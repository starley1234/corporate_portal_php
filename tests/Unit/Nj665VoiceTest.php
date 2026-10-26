<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj665_Voice (nj665_voice — озвучивание текста).
 *
 * Запуск: php tests/mini_runner.php Nj665Voice
 *
 * Проверяются только чистые функции: разбор ответа синтеза, определение
 * формата по содержимому, разбор каталога моделей и границы параметров.
 * Всё, что ходит в сеть и в БД, оставлено стенду
 * (.tools/nj665_voice_stand.php) — там те же проверки работают без PHP в
 * системе и без PostgreSQL.
 */
class Nj665VoiceTest extends TestCase
{
	// --- текст ---------------------------------------------------------------

	public function test_clean_text_keeps_paragraphs()
	{
		$this->assertSame("а\n\nб", Service_Nj665_Voice::clean_text("а\r\n\r\n\r\nб"));
	}

	public function test_clean_text_drops_control_chars()
	{
		$this->assertSame('аб', Service_Nj665_Voice::clean_text("а\x00\x1Bб"));
	}

	public function test_excerpt_is_one_line()
	{
		$this->assertSame('первая вторая', Service_Nj665_Voice::excerpt("первая\nвторая"));
	}

	public function test_title_strips_markdown()
	{
		$this->assertSame('Заголовок текста', Service_Nj665_Voice::title_from_text('# Заголовок *текста*'));
	}

	// --- границы параметров ---------------------------------------------------

	public function test_speed_is_clamped_to_config()
	{
		$min = (float) Service_Nj665_Voice::limits('speed_min', 0.5);
		$max = (float) Service_Nj665_Voice::limits('speed_max', 2.0);

		$this->assertSame($min, Service_Nj665_Voice::clamp_speed(0.1));
		$this->assertSame($max, Service_Nj665_Voice::clamp_speed(10));
		$this->assertSame(1.25, Service_Nj665_Voice::clamp_speed(1.25));
	}

	public function test_format_falls_back_to_allowed()
	{
		$this->assertSame('wav', Service_Nj665_Voice::pick_format('wav', ['wav', 'mp3']));
		$this->assertSame('flac', Service_Nj665_Voice::pick_format('mp4', ['flac', 'aac']));
		$this->assertSame('mp3', Service_Nj665_Voice::pick_format('', []));
	}

	public function test_pcm_is_not_playable()
	{
		// В v1 формат по умолчанию — pcm, у него нет контейнера: браузер
		// такой файл не проиграет, и страница обязана об этом сказать.
		$this->assertFalse(Service_Nj665_Voice::is_playable('pcm'));
		$this->assertTrue(Service_Nj665_Voice::is_playable('mp3'));
		$this->assertSame('application/octet-stream', Service_Nj665_Voice::media_type('pcm'));
		$this->assertSame('audio/mpeg', Service_Nj665_Voice::media_type('mp3'));
	}

	// --- формат по содержимому ------------------------------------------------

	public function test_sniff_format_recognises_containers()
	{
		$this->assertSame('mp3', Service_Nj665_Voice::sniff_format('ID3'.str_repeat("\0", 60)));
		$this->assertSame('mp3', Service_Nj665_Voice::sniff_format("\xFF\xFB".str_repeat("\0", 60)));
		$this->assertSame('wav', Service_Nj665_Voice::sniff_format('RIFF____WAVE'.str_repeat("\0", 40)));
		$this->assertSame('flac', Service_Nj665_Voice::sniff_format('fLaC'.str_repeat("\0", 40)));
		$this->assertSame('opus', Service_Nj665_Voice::sniff_format('OggS'.str_repeat("\0", 20).'OpusHead'));
		$this->assertSame('aac', Service_Nj665_Voice::sniff_format('____ftyp'.str_repeat("\0", 40)));
	}

	public function test_sniff_format_returns_null_on_junk()
	{
		$this->assertNull(Service_Nj665_Voice::sniff_format(''));
		$this->assertNull(Service_Nj665_Voice::sniff_format('abc'));
		$this->assertNull(Service_Nj665_Voice::sniff_format('{"detail":"нет ключа"}'));
	}

	// --- разбор ответа синтеза -------------------------------------------------

	private function mp3(): string
	{
		return 'ID3'.str_repeat("\0", 60);
	}

	public function test_response_may_be_raw_stream()
	{
		$res = Service_Nj665_Voice::audio_from_response($this->mp3(), 'audio/mpeg', 'mp3');

		$this->assertSame($this->mp3(), $res['bytes']);
		$this->assertSame('mp3', $res['format']);
		$this->assertSame('', $res['error']);
	}

	public function test_response_may_be_json_with_base64()
	{
		$body = json_encode(['audio' => base64_encode($this->mp3())]);
		$res  = Service_Nj665_Voice::audio_from_response($body, 'application/json', 'mp3');

		$this->assertSame($this->mp3(), $res['bytes']);
		$this->assertSame('', $res['url']);
	}

	public function test_response_may_be_json_with_nested_base64()
	{
		$body = json_encode(['data' => ['audio_base64' => base64_encode($this->mp3())]]);
		$res  = Service_Nj665_Voice::audio_from_response($body, 'application/json', 'mp3');

		$this->assertSame($this->mp3(), $res['bytes']);
	}

	public function test_response_may_be_json_with_url()
	{
		$body = json_encode(['url' => 'https://voice.example.ru/audio/abcd.mp3']);
		$res  = Service_Nj665_Voice::audio_from_response($body, 'application/json', 'mp3');

		$this->assertSame('https://voice.example.ru/audio/abcd.mp3', $res['url']);
		$this->assertSame('', $res['bytes']);
		$this->assertSame('mp3', $res['format']);
	}

	public function test_response_without_audio_is_an_error()
	{
		$res = Service_Nj665_Voice::audio_from_response(json_encode(['detail' => 'нет ключа']),
			'application/json', 'mp3');

		$this->assertSame('', $res['bytes']);
		$this->assertNotSame('', $res['error']);
	}

	public function test_empty_response_is_an_error()
	{
		$res = Service_Nj665_Voice::audio_from_response('', 'audio/mpeg', 'mp3');

		$this->assertSame('', $res['bytes']);
		$this->assertNotSame('', $res['error']);
	}

	public function test_declared_format_loses_to_real_bytes()
	{
		// Сервер игнорирует response_format и шлёт wav на запрос pcm:
		// хранить и отдавать файл надо по факту, иначе браузер не проиграет.
		$wav = 'RIFF____WAVE'.str_repeat("\0", 40);
		$res = Service_Nj665_Voice::audio_from_response($wav, 'application/octet-stream', 'pcm');

		$this->assertSame('wav', $res['format']);
		$this->assertSame($wav, $res['bytes']);
	}

	// --- каталог моделей --------------------------------------------------------

	public function test_parse_models_reads_openrouter_shape()
	{
		$models = Service_Nj665_Voice::parse_models(['data' => [[
			'id'                => 'k2-fsa/omnivoice',
			'supported_voices'  => ['alloy', 'echo'],
			'supported_formats' => ['mp3', 'wav', 'pcm'],
		]]]);

		$this->assertCount(1, $models);
		$this->assertSame('k2-fsa/omnivoice', $models[0]['id']);
		$this->assertSame(['alloy', 'echo'], $models[0]['voices']);
		$this->assertSame(['mp3', 'wav', 'pcm'], $models[0]['formats']);
		$this->assertTrue($models[0]['speech']);
	}

	public function test_parse_models_tolerates_empty_documented_response()
	{
		// В спеке 200-й ответ /api/v1/models описан как пустой {} — падать
		// на нём нельзя, но и каталога не будет.
		$this->assertSame([], Service_Nj665_Voice::parse_models([]));
		$this->assertSame([], Service_Nj665_Voice::parse_models(['unexpected' => 1]));
		$this->assertSame([], Service_Nj665_Voice::parse_models('нет'));
	}

	public function test_parse_models_reads_voices_as_objects()
	{
		$models = Service_Nj665_Voice::parse_models([
			'data' => [['id' => 'x', 'supported_voices' => [['id' => 'v1'], ['name' => 'v2']]]],
		]);

		$this->assertSame(['v1', 'v2'], $models[0]['voices']);
	}

	// --- образцы голоса и отдача файла -------------------------------------------

	public function test_references_keep_only_http_urls_and_text()
	{
		$refs = Service_Nj665_Voice::clean_references([
			'https://example.ru/voice.mp3',
			['text' => 'говори тише'],
			['url' => 'ftp://example.ru/x.mp3'],
			['text' => 'второй'],
			['text' => 'третий'],
			['text' => 'четвёртый'],
		]);

		$this->assertCount(3, $refs);
		$this->assertSame('input_audio', $refs[0]['type']);
		$this->assertSame('text', $refs[1]['type']);
		$this->assertSame('второй', $refs[2]['text']);
	}

	public function test_download_name_has_ascii_and_utf8_parts()
	{
		$head = Service_Nj665_Voice::content_disposition(
			['code' => 'abcd2345xyzw', 'format' => 'mp3', 'title' => 'Привет мир'], TRUE);

		$this->assertMatchesRegularExpression('~^attachment;~', $head);
		$this->assertMatchesRegularExpression('~filename="[a-z0-9-]+\.mp3"~', $head);
		$this->assertMatchesRegularExpression("~filename\\*=UTF-8''~", $head);
	}

	public function test_code_is_short_and_unambiguous()
	{
		$code = Service_Nj665_Voice::code_new();

		$this->assertSame(12, strlen($code));
		$this->assertMatchesRegularExpression('~^[23456789a-z]{12}$~', $code);
	}

	public function test_bad_code_is_rejected()
	{
		$this->assertNull(Service_Nj665_Voice::speech(''));
		$this->assertNull(Service_Nj665_Voice::speech('../../etc/passwd'));
	}

	// --- удаление -----------------------------------------------------------------

	public function test_owner_is_recognised_by_user_or_visitor()
	{
		$row = ['id' => 7, 'code' => 'abcd2345xyzw', 'user_id' => 5, 'visitor_uid' => 'visitor-1'];

		$this->assertTrue(Service_Nj665_Voice::owns($row, 5, ''));
		$this->assertTrue(Service_Nj665_Voice::owns($row, 0, 'visitor-1'));
		$this->assertFalse(Service_Nj665_Voice::owns($row, 9, ''));
		$this->assertFalse(Service_Nj665_Voice::owns($row, 0, 'visitor-2'));
		$this->assertFalse(Service_Nj665_Voice::owns($row, 0, ''));
	}

	public function test_guest_speech_is_not_deletable_by_another_browser()
	{
		// Запись гостя привязана к браузеру: чужой visitor_uid права не даёт.
		$row = ['id' => 8, 'code' => 'abcd2345xyz1', 'user_id' => 0, 'visitor_uid' => 'v-9'];

		$this->assertTrue(Service_Nj665_Voice::owns($row, 0, 'v-9'));
		$this->assertFalse(Service_Nj665_Voice::owns($row, 0, 'v-10'));
	}

	public function test_unknown_speech_is_not_deletable()
	{
		// Удаление обязано отказывать, а не падать: кода в журнале нет.
		$res = Service_Nj665_Voice::delete('nosuchcode1234', 5, 'visitor-1');

		$this->assertFalse($res['ok']);
		$this->assertNotEmpty($res['error']);
	}
}
