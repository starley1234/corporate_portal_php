<?php

use PHPUnit\Framework\TestCase;

/**
 * Контракт nj657_alice: чистые функции протокола Алисы (без БД).
 * Серверная часть (вебхук, журнал, тестовая консоль) — /alice/admin/test.
 */
class AliceServiceTest extends TestCase {

	/** Типовой запрос Алисы для остальных проверок. */
	private function request($command, $is_new)
	{
		return [
			'meta' => ['locale' => 'ru-RU', 'timezone' => 'Europe/Moscow', 'interfaces' => ['screen' => []]],
			'request' => [
				'command' => $command,
				'original_utterance' => $command,
				'type' => 'SimpleUtterance',
				'markup' => ['dangerous_context' => FALSE],
				'payload' => [],
			],
			'session' => [
				'message_id' => 7,
				'session_id' => 'sess-1',
				'skill_id' => 'abc-123',
				'user_id' => 'legacy-user',
				'user' => ['user_id' => 'persistent-user'],
				'new' => $is_new,
			],
			'version' => '1.0',
		];
	}

	public function test_extract_request_normalizes_protocol()
	{
		$x = Service_Nj657_Alice::extract_request($this->request('привет', TRUE));

		$this->assertTrue($x['ok']);
		$this->assertSame('sess-1', $x['session']['session_id']);
		$this->assertSame(7, $x['session']['message_id']);
		$this->assertSame('abc-123', $x['session']['skill_id']);
		$this->assertTrue($x['session']['is_new']);
		$this->assertTrue($x['session']['has_user']);
		$this->assertTrue($x['request']['has_screen']);
		$this->assertFalse($x['request']['dangerous']);
		$this->assertSame('SimpleUtterance', $x['request']['type']);
		// Ключ пользователя — хеш постоянного идентификатора, не он сам.
		$this->assertSame(Service_Nj657_Alice::user_key('persistent-user'), $x['session']['user_key']);
		$this->assertStringNotContainsString('persistent-user', $x['session']['user_key']);
	}

	public function test_extract_request_rejects_garbage()
	{
		$this->assertFalse(Service_Nj657_Alice::extract_request(NULL)['ok']);
		$this->assertFalse(Service_Nj657_Alice::extract_request(['request' => []])['ok']);
		$this->assertFalse(Service_Nj657_Alice::extract_request(['session' => [], 'request' => 'x'])['ok']);
	}

	public function test_user_key_is_stable_hash()
	{
		$this->assertSame(Service_Nj657_Alice::user_key('u1'), Service_Nj657_Alice::user_key('u1'));
		$this->assertNotSame(Service_Nj657_Alice::user_key('u1'), Service_Nj657_Alice::user_key('u2'));
		$this->assertSame('', Service_Nj657_Alice::user_key(''));
		$this->assertSame(32, strlen(Service_Nj657_Alice::user_key('u1')));
	}

	public function test_route_intent_scenarios()
	{
		$config = Service_Nj657_Alice::config();
		$skill = [];

		// Новая сессия без слов — приветствие.
		$this->assertSame('welcome', Service_Nj657_Alice::route_intent('', TRUE, $skill, $config));
		// Пустая реплика в разговоре — помощь.
		$this->assertSame('help', Service_Nj657_Alice::route_intent('', FALSE, $skill, $config));
		// Словари сценариев.
		$this->assertSame('help', Service_Nj657_Alice::route_intent('Помощь!', FALSE, $skill, $config));
		$this->assertSame('exit', Service_Nj657_Alice::route_intent('стоп.', FALSE, $skill, $config));
		// Приветствие словом — только в новой сессии, дальше это вопрос ИИ.
		$this->assertSame('welcome', Service_Nj657_Alice::route_intent('Привет', TRUE, $skill, $config));
		$this->assertSame('llm', Service_Nj657_Alice::route_intent('Привет', FALSE, $skill, $config));
		// Всё остальное — модели.
		$this->assertSame('llm', Service_Nj657_Alice::route_intent('Что такое любовь?', TRUE, $skill, $config));
	}

	public function test_question_text_strips_activation_name()
	{
		$skill = ['activation_names' => "Мой диалог\nмой навык"];

		$x = Service_Nj657_Alice::extract_request($this->request('Мой диалог скажи привет', TRUE));
		$this->assertSame('скажи привет', Service_Nj657_Alice::question_text($x, $skill));

		// Вне новой сессии имя не отрезается.
		$y = Service_Nj657_Alice::extract_request($this->request('Мой диалог скажи привет', FALSE));
		$this->assertSame('Мой диалог скажи привет', Service_Nj657_Alice::question_text($y, $skill));

		// ButtonPressed без команды берёт команду из payload кнопки.
		$data = $this->request('', FALSE);
		$data['request']['type'] = 'ButtonPressed';
		$data['request']['payload'] = ['command' => 'помощь'];
		$z = Service_Nj657_Alice::extract_request($data);
		$this->assertSame('помощь', Service_Nj657_Alice::question_text($z, $skill));
	}

	public function test_build_response_protocol_shape()
	{
		$response = Service_Nj657_Alice::build_response(
			['session_id' => 's1', 'message_id' => 3, 'user_id' => 'u1'],
			'Ответ',
			'Отв+ет',
			[['title' => 'Дальше', 'payload' => ['command' => 'Дальше'], 'hide' => TRUE]],
			TRUE
		);

		$this->assertSame('1.0', $response['version']);
		$this->assertSame('s1', $response['session']['session_id']);
		$this->assertSame(3, $response['session']['message_id']);
		$this->assertSame('u1', $response['session']['user_id']);
		$this->assertSame('Ответ', $response['response']['text']);
		$this->assertSame('Отв+ет', $response['response']['tts']);
		$this->assertTrue($response['response']['end_session']);
		$this->assertSame('Дальше', $response['response']['buttons'][0]['title']);
		$this->assertTrue($response['response']['buttons'][0]['hide']);
	}

	/**
	 * PostgreSQL BOOLEAN приходит из драйвера строкой 't'/'f': пустой каст
	 * (bool) превращает 'f' в TRUE, и навык закрывал сессию после первого
	 * ответа. Регрессия бага 06.10.2026.
	 */
	public function test_truthy_postgres_boolean_strings()
	{
		$this->assertFalse(Service_Nj657_Alice::truthy('f'));
		$this->assertFalse(Service_Nj657_Alice::truthy('false'));
		$this->assertFalse(Service_Nj657_Alice::truthy(FALSE));
		$this->assertFalse(Service_Nj657_Alice::truthy('0'));
		$this->assertFalse(Service_Nj657_Alice::truthy(0));
		$this->assertFalse(Service_Nj657_Alice::truthy(NULL));
		$this->assertFalse(Service_Nj657_Alice::truthy(''));

		$this->assertTrue(Service_Nj657_Alice::truthy('t'));
		$this->assertTrue(Service_Nj657_Alice::truthy('true'));
		$this->assertTrue(Service_Nj657_Alice::truthy(TRUE));
		$this->assertTrue(Service_Nj657_Alice::truthy('1'));
		$this->assertTrue(Service_Nj657_Alice::truthy(1));
	}

	public function test_buttons_parse_and_limits()
	{
		$skill = ['buttons_text' => "  Первый  \n\nВторой\n   \nТретий\nЧетвёртый\nПятый\nШестой"];

		$this->assertSame(
			['Первый', 'Второй', 'Третий', 'Четвёртый', 'Пятый'],
			Service_Nj657_Alice::parse_buttons($skill['buttons_text'])
		);

		$buttons = Service_Nj657_Alice::response_buttons($skill);
		$this->assertCount(5, $buttons);
		$this->assertSame('Первый', $buttons[0]['title']);
		$this->assertSame('Первый', $buttons[0]['payload']['command']);
		$this->assertTrue($buttons[0]['hide']);

		// Кнопки — только экранным устройствам.
		$x = Service_Nj657_Alice::extract_request($this->request('вопрос', FALSE));
		$this->assertSame($buttons, Service_Nj657_Alice::buttons_for($skill, $x));

		$voice = $this->request('вопрос', FALSE);
		$voice['meta']['interfaces'] = [];
		$y = Service_Nj657_Alice::extract_request($voice);
		$this->assertSame([], Service_Nj657_Alice::buttons_for($skill, $y));
	}

	public function test_clean_for_tts_strips_markdown()
	{
		$raw = "# Заголовок\n\n**Жирный** и `код`, ссылка [текст](https://example.ru/page) в тексте.\n- пункт списка\n1. нумерованный\nОбычная https://example.ru/x строка.";
		$tts = Service_Nj657_Alice::clean_for_tts($raw);

		$this->assertStringNotContainsString('**', $tts);
		$this->assertStringNotContainsString('`', $tts);
		$this->assertStringNotContainsString('https://', $tts);
		$this->assertStringNotContainsString('#', $tts);
		$this->assertStringNotContainsString('[', $tts);
		$this->assertStringNotContainsString('- пункт', $tts);
		$this->assertStringContainsString('Жирный', $tts);
		$this->assertStringContainsString('текст', $tts);
	}

	public function test_trim_answer_cuts_on_word_boundary()
	{
		$text = str_repeat('слово ', 399).'слово'; // без краевых пробелов

		$this->assertSame($text, Service_Nj657_Alice::trim_answer($text, 0)); // без лимита

		$cut = Service_Nj657_Alice::trim_answer($text, 100);
		$this->assertTrue(mb_strlen($cut) <= 101);
		$this->assertStringStartsWith('слово', $cut);
		$this->assertStringContainsString('…', $cut);

		// Одно длинное слово — режем жёстко, но не молча теряем текст.
		$long = str_repeat('а', 500);
		$cut2 = Service_Nj657_Alice::trim_answer($long, 100);
		$this->assertSame(101, mb_strlen($cut2));
	}

	public function test_parse_lines()
	{
		$this->assertSame(['a', 'b'], Service_Nj657_Alice::parse_lines("a\r\n\r\n  b \n"));
		$this->assertSame([], Service_Nj657_Alice::parse_lines(" \n "));
	}

	public function test_webhook_url_with_and_without_token()
	{
		// assertStringEndsWith из PHPUnit 9 в десятке убран — проверяем тем,
		// что есть всегда: хвост адреса и понятная причина в сообщении.
		$url = Service_Nj657_Alice::webhook_url();
		$this->assertTrue(str_ends_with($url, '/alice/webhook'), 'Вебхук без токена: '.$url);

		$url = Service_Nj657_Alice::webhook_url(['webhook_token' => 'tok1234567890']);
		$this->assertTrue(str_ends_with($url, '/alice/webhook/tok1234567890'), 'Вебхук с токеном: '.$url);
	}

	public function test_mqtt_topic_validation()
	{
		$this->assertTrue(Service_Nj657_Mqtt::topic_valid('hasp/esp32_ips_480x480/command/p1b1.text'));
		$this->assertTrue(Service_Nj657_Mqtt::topic_valid('home/display/1'));
		$this->assertFalse(Service_Nj657_Mqtt::topic_valid(''));
		$this->assertFalse(Service_Nj657_Mqtt::topic_valid('home/#'));
		$this->assertFalse(Service_Nj657_Mqtt::topic_valid('home/+'));
		$this->assertFalse(Service_Nj657_Mqtt::topic_valid('home with spaces'));
	}

	public function test_mqtt_packets_byte_exact()
	{
		// CONNECT без авторизации: заголовок 0x10, длина, «MQTT», уровень 4,
		// флаг clean session 0x02, keepalive 30, client id.
		$connect = Service_Nj657_Mqtt::build_connect_packet('testclient');
		$this->assertSame('101600044d5154540402001e000a74657374636c69656e74', bin2hex($connect));

		// CONNECT с логином и паролем: флаги 0x02|0x80|0x40 = 0xC2.
		$auth = Service_Nj657_Mqtt::build_connect_packet('nj657', 'u', 'p');
		$this->assertSame('101700044d51545404c2001e00056e6a363537000175000170', bin2hex($auth));

		// PUBLISH QoS 0: 0x30, длина, длина топика, топик, полезная нагрузка.
		$publish = Service_Nj657_Mqtt::build_publish_packet('a/b', 'hi');
		$this->assertSame('30070003612f626869', bin2hex($publish));

		// Длина > 127 кодируется двумя байтами (variable-length integer):
		// 3 байта топика + 200 байтов текста = 203 = 0xCB 0x01.
		$payload = str_repeat('x', 200);
		$big = Service_Nj657_Mqtt::build_publish_packet('t', $payload);
		$this->assertSame('30cb01', substr(bin2hex($big), 0, 6));
		$this->assertSame(200, strlen($payload));
	}
}
