<?php

use PHPUnit\Framework\TestCase;

/**
 * NJ-662 «Диалог двух ИИ»: логика диалога без базы и без сети.
 *
 * Модуль держится на четырёх обещаниях, и все четыре проверяются здесь:
 *   1. «число обменов, контекст, суммаризация и бюджет поиска — по границам
 *      конфига» (normalize_settings): иначе форма молча писала бы в базу
 *      значение, которое сервис потом не выполнит;
 *   2. «статус-ряд одинаков на странице и в опросе» (progress): по нему
 *      владелец понимает, идёт ли диалог, — вторая форма ответа разошлась бы;
 *   3. «материалы режутся по длине и подбираются по словам» (keywords,
 *      excerpt, clean_text): сюда попадает пользовательский текст;
 *   4. «внутренние адреса читать нельзя» (fetch_page): страницу по адресу
 *      добавляет человек, а сервер стоит в интернете.
 */
class Nj662DialogTest extends TestCase
{
	/** Диалог для проверок статус-ряда. */
	private function dialog(array $over = []): array
	{
		return $over + [
			'id'            => 7,
			'topic'         => 'Как проверить спрос на услугу без вложений',
			'brief'         => '',
			'a_name'        => 'Структура',
			'b_name'        => 'Импульс',
			'a_system'      => 'Ты — Структура.',
			'b_system'      => 'Ты — Импульс.',
			'a_model'       => '',
			'b_model'       => '',
			'pairs'         => 5,
			'context_pairs' => 3,
			'summarize'     => TRUE,
			'ddg'           => 2,
			'status'        => 'running',
			'stage'         => 'Ищу в интернете',
			'stage_note'    => 'ищу «спрос на услугу»',
			'turn'          => 2,
			'searches_used' => 1,
			'tokens_total'  => 3200,
			'error'         => '',
			'started_at'    => date('Y-m-d H:i:s', time() - 95),
			'finished_at'   => NULL,
			'is_public'     => FALSE,
		];
	}

	// ------------------------------------------------------------------ формы

	public function test_settings_are_clamped_to_config_limits(): void
	{
		$s = Service_Nj662_Dialog::normalize_settings([
			'pairs'         => 99,
			'context_pairs' => 99,
			'ddg'           => 99,
			'summarize'     => 'on',
		]);

		$this->assertSame((int) Service_Nj662_Dialog::limits('pairs_max', 12), $s['pairs']);
		$this->assertSame((int) Service_Nj662_Dialog::limits('context_pairs_max', 10), $s['context_pairs']);
		$this->assertSame((int) Service_Nj662_Dialog::limits('ddg_max', 5), $s['ddg']);
		$this->assertTrue($s['summarize']);
	}

	public function test_defaults_of_new_dialog_are_five_pairs(): void
	{
		$s = Service_Nj662_Dialog::normalize_settings([]);

		$this->assertSame(5, $s['pairs'], 'умолчание числа пар — 5 (слово владельца)');
		$this->assertSame(0, $s['ddg'], 'по умолчанию агенты в интернет не выходят');
		$this->assertTrue($s['summarize']);
	}

	public function test_negative_and_rubbish_values_do_not_break(): void
	{
		$s = Service_Nj662_Dialog::normalize_settings([
			'pairs' => -3, 'context_pairs' => '-7', 'ddg' => 'нет', 'summarize' => '',
		]);

		$this->assertSame(1, $s['pairs']);
		$this->assertSame(0, $s['context_pairs']);
		$this->assertSame(0, $s['ddg']);
		$this->assertFalse($s['summarize']);
	}

	public function test_bool_from_checkbox_and_words(): void
	{
		$this->assertTrue(Service_Nj662_Dialog::as_bool('on'));
		$this->assertTrue(Service_Nj662_Dialog::as_bool(TRUE));
		$this->assertTrue(Service_Nj662_Dialog::as_bool('1'));
		$this->assertFalse(Service_Nj662_Dialog::as_bool('0'));
		$this->assertFalse(Service_Nj662_Dialog::as_bool(''));
		$this->assertFalse(Service_Nj662_Dialog::as_bool(NULL));
	}

	// ------------------------------------------------------------------ текст

	public function test_clean_text_cuts_length_and_control_characters(): void
	{
		$this->assertSame('а б', Service_Nj662_Dialog::clean_text("  а\x07 б\r\n\r\n\r\n"));
		$this->assertSame('тема…', Service_Nj662_Dialog::clean_text('тема и продолжение', 5));
	}

	public function test_clean_name_falls_back_and_cuts(): void
	{
		$this->assertSame('Импульс', Service_Nj662_Dialog::clean_name('   ', 'Импульс'));
		$this->assertSame('Структура', Service_Nj662_Dialog::clean_name("Структура\n", ''));
		$this->assertLessThanOrEqual(60, mb_strlen(Service_Nj662_Dialog::clean_name(str_repeat('я', 200), '')));
	}

	public function test_excerpt_and_keywords(): void
	{
		$this->assertSame('раз два', Service_Nj662_Dialog::excerpt("раз  два\n", 40));
		$this->assertSame('раз два три…', Service_Nj662_Dialog::excerpt('раз два три четыре', 11));

		// Короткие слова («как») и повторы отбрасываются: иначе подбор материалов
		// цеплялся бы за «что», «или» и повторённое слово.
		$this->assertSame(['проверить', 'спрос', 'услугу'],
			Service_Nj662_Dialog::keywords('Как проверить спрос на услугу, спрос!'));
		$this->assertSame([], Service_Nj662_Dialog::keywords('и в на не'));
		$this->assertCount(2, Service_Nj662_Dialog::keywords('один два три четыре', 2));
	}

	// -------------------------------------------------------------- статус-ряд

	public function test_progress_of_running_dialog(): void
	{
		$p = Service_Nj662_Dialog::progress($this->dialog());

		$this->assertTrue($p['live']);
		$this->assertSame('running', $p['status']);
		$this->assertSame('идёт диалог', $p['status_word']);
		$this->assertSame('live', $p['tone']);
		$this->assertSame('Ищу в интернете', $p['stage']);
		$this->assertSame(1, $p['stage_index']);
		$this->assertSame('Обмен 2 из 5', $p['turn_word']);
		$this->assertStringContainsString('поиск: 1 из 2', $p['counters_text']);
		$this->assertStringContainsString('токенов: 3200', $p['counters_text']);
		$this->assertGreaterThanOrEqual(90, $p['elapsed_sec']);
		$this->assertStringContainsString('мин', $p['elapsed_text']);
		$this->assertSame('Структура × Импульс', $p['title']);
	}

	public function test_progress_of_finished_dialog_has_no_ticker(): void
	{
		$finished = $this->dialog([
			'status'      => 'done',
			'finished_at' => date('Y-m-d H:i:s', time() - 10),
			'stage'       => 'Диалог завершён',
		]);

		$p = Service_Nj662_Dialog::progress($finished);

		$this->assertFalse($p['live']);
		$this->assertSame('ok', $p['tone']);
		$this->assertSame('диалог завершён', $p['status_word']);

		// Секундомер останавливается на finished_at: диалог шёл 95 − 10 секунд,
		// а не «до текущего момента» (иначе, постояв открытой, страница врала бы).
		$this->assertEqualsWithDelta(85, $p['elapsed_sec'], 5, 'секундомер идёт до finished_at');
	}

	public function test_progress_of_failed_and_paused(): void
	{
		$this->assertSame('error', Service_Nj662_Dialog::progress($this->dialog(['status' => 'failed']))['tone']);
		$this->assertSame('warn', Service_Nj662_Dialog::progress($this->dialog(['status' => 'paused']))['tone']);
		$this->assertSame('plain', Service_Nj662_Dialog::progress($this->dialog(['status' => 'draft']))['tone']);
		$this->assertNull(Service_Nj662_Dialog::progress(NULL));
	}

	public function test_status_words_are_russian(): void
	{
		$this->assertSame('черновик', Service_Nj662_Dialog::status_word('draft'));
		$this->assertSame('подбираю агентов', Service_Nj662_Dialog::status_word('quick'));
		$this->assertSame('пауза', Service_Nj662_Dialog::status_word('paused'));
		$this->assertSame('ошибка', Service_Nj662_Dialog::status_word('failed'));
	}

	/**
	 * «Стоп» — свой статус, а не пауза: владелец снял задание с очереди, и
	 * в сводке это видно по подписи, а не по заметке этапа (09.10.2026).
	 */
	public function test_stopped_status_is_its_own_word(): void
	{
		$this->assertSame('остановлен', Service_Nj662_Dialog::status_word('stopped'));
		$this->assertSame('warn', Service_Nj662_Dialog::tone('stopped'));

		$stopped = Service_Nj662_Dialog::progress($this->dialog([
			'status'    => 'stopped',
			'stage'     => 'Стоп',
			'stage_note' => 'остановлено владельцем, задание снято с очереди',
		]));

		$this->assertFalse($stopped['live'], 'остановленный диалог не «идёт»');
		$this->assertSame('остановлен', $stopped['status_word']);
		$this->assertSame('warn', $stopped['tone']);
		$this->assertSame('Обмен 2 из 5', $stopped['turn_word'],
			'счётчик обменов не обнуляется — ни пауза, ни стоп');
	}

	/**
	 * Своя реплика: план разговора. Ответ на вопрос владельца «обменов в
	 * разговоре — считается от начала или от продолжения»: ОТ НАЧАЛА.
	 */
	public function test_say_plan_continues_the_counter_from_the_start(): void
	{
		// Диалог на паузе, сделано 2 обмена из 5 — продолжение не обнуляет счёт.
		$plan = Service_Nj662_Dialog::say_plan(['turn' => 2, 'pairs' => 5], 1);

		$this->assertSame(2, $plan['turn']);
		$this->assertSame(6, $plan['pairs_new'], 'план продлевается на обмен');
		$this->assertSame(1, $plan['add']);
		$this->assertSame(3, $plan['left'], 'оставалось обменов');
		$this->assertStringContainsString('обмен 2 из 5 → 6', $plan['text']);

		// Середина разговора: план не исчерпан, добавлять не просили — не продлеваем.
		$plain = Service_Nj662_Dialog::say_plan(['turn' => 2, 'pairs' => 5], 0);

		$this->assertSame(5, $plain['pairs_new']);
		$this->assertSame(0, $plain['add']);
		$this->assertFalse($plain['capped']);

		// План исчерпан (turn = pairs): продолжать нечем — удлиняем на обмен
		// даже без просьбы, иначе «продолжить» молча не сработало бы.
		$exhausted = Service_Nj662_Dialog::say_plan(['turn' => 5, 'pairs' => 5], 0);

		$this->assertSame(6, $exhausted['pairs_new'], 'исчерпанный план продлевается сам');

		// Предел конфига — потолок: выше него план не растёт, и это видно.
		$max = (int) Service_Nj662_Dialog::limits('pairs_max', 12);
		$capped = Service_Nj662_Dialog::say_plan(['turn' => $max, 'pairs' => $max], 3);

		$this->assertSame($max, $capped['pairs_new']);
		$this->assertTrue($capped['capped'], 'упёрлись в предел — продолжать нечего');

		// Мусор на входе не ломает счёт.
		$rubbish = Service_Nj662_Dialog::say_plan(['turn' => '-4', 'pairs' => 'нет'], 'много');

		$this->assertSame(0, $rubbish['turn']);
		$this->assertSame(0, $rubbish['pairs']);
		$this->assertSame(1, $rubbish['pairs_new'], 'из нуля план поднимается до одного обмена');
	}

	public function test_pair_and_elapsed_words(): void
	{
		$this->assertSame('Обмен 3 из 5', Service_Nj662_Dialog::pair_word(3, 5));
		// Счётчики видны на странице и в ленте: «1 обмен», «2 обмена», «5 обменов».
		$this->assertSame('обмен', Service_Nj662_Dialog::plural(1, 'обмен', 'обмена', 'обменов'));
		$this->assertSame('обмена', Service_Nj662_Dialog::plural(2, 'обмен', 'обмена', 'обменов'));
		$this->assertSame('обменов', Service_Nj662_Dialog::plural(5, 'обмен', 'обмена', 'обменов'));
		$this->assertSame('обменов', Service_Nj662_Dialog::plural(11, 'обмен', 'обмена', 'обменов'));
		$this->assertSame('обмен', Service_Nj662_Dialog::plural(21, 'обмен', 'обмена', 'обменов'));
		$this->assertSame('0 с', Service_Nj662_Dialog::elapsed_text(0));
		$this->assertSame('45 с', Service_Nj662_Dialog::elapsed_text(45));
		$this->assertSame('2 мин 5 с', Service_Nj662_Dialog::elapsed_text(125));
		$this->assertSame('2 ч 3 мин', Service_Nj662_Dialog::elapsed_text(7380));
	}

	// -------------------------------------------------------------- интернет

	public function test_ddg_labels_explain_the_budget(): void
	{
		$this->assertSame('не выходят в интернет', Service_Nj662_Dialog::ddg_label(0));
		$this->assertSame('один поиск за диалог', Service_Nj662_Dialog::ddg_label(1));
		$this->assertSame('поисков за диалог: 3', Service_Nj662_Dialog::ddg_label(3));
		$this->assertStringContainsString('много', Service_Nj662_Dialog::ddg_label(5));
	}

	// ------------------------------------------------------- чтение страницы

	public function test_fetch_page_refuses_local_addresses(): void
	{
		foreach (['http://127.0.0.1/admin', 'http://localhost:8080/', 'http://192.168.0.1/',
			'http://10.0.0.5/x', 'https://169.254.169.254/latest/meta-data/'] as $url)
		{
			$res = Service_Nj662_Dialog::fetch_page($url);

			$this->assertArrayHasKey('error', $res, 'внутренний адрес обязан быть отклонён: '.$url);
		}
	}

	public function test_fetch_page_wants_http_address(): void
	{
		$this->assertArrayHasKey('error', Service_Nj662_Dialog::fetch_page('ftp://example.com/a'));
		$this->assertArrayHasKey('error', Service_Nj662_Dialog::fetch_page('пример'));
	}

	// -------------------------------------------------------------- владение

	public function test_owns_checks_owner_and_admin(): void
	{
		$dialog = ['user_id' => 5];

		$this->assertTrue(Service_Nj662_Dialog::owns($dialog, 5));
		$this->assertFalse(Service_Nj662_Dialog::owns($dialog, 6));
		$this->assertTrue(Service_Nj662_Dialog::owns($dialog, 6, TRUE), 'админ видит чужие диалоги');
		$this->assertFalse(Service_Nj662_Dialog::owns(['user_id' => 0], 0), 'гость не владеет ничем');
		$this->assertFalse(Service_Nj662_Dialog::owns(['user_id' => 0], 1));
	}

	// -------------------------------------------------------- подсказки роли

	public function test_role_default_substitutes_topic(): void
	{
		$a = Service_Nj662_Dialog::role_default('a', 'учёт заявок');
		$b = Service_Nj662_Dialog::role_default('b', 'учёт заявок');

		$this->assertStringContainsString('учёт заявок', $a, 'тема подставлена в характер');
		$this->assertStringNotContainsString('{topic}', $a);
		$this->assertStringContainsString('шаги', $a, 'первый участник держит структуру');
		$this->assertStringContainsString('да, и…', $b, 'формула из наработок сохранена');
	}

	public function test_samples_and_roles_are_configured(): void
	{
		$this->assertNotEmpty(Service_Nj662_Dialog::samples(), 'подсказки тем для быстрого старта');
		$this->assertNotEmpty(Arr::path(Service_Nj662_Dialog::roles(), 'a.name', ''));
		$this->assertNotEmpty(Arr::path(Service_Nj662_Dialog::roles(), 'b.name', ''));
	}
}
