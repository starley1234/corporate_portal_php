<?php

use PHPUnit\Framework\TestCase;

/**
 * NJ-662: список LLM из конфига и «своя модель».
 *
 * Владелец (08.10.2026): «список LLM в конфиге, вида
 * openrouter::модель; TOOLFORGE::модель» — и рядом возможность назвать свою
 * модель. Значит, разбор строки конфига и разбор выбора из формы — это
 * отдельные обещания, и оба проверяются здесь: если строка конфига читается
 * криво, интерфейс предлагает битые спецификации; если выбор из формы — на
 * сайт уехало бы «custom» вместо модели.
 */
class Nj662ModelsTest extends TestCase
{
	public function test_parse_the_owners_syntax(): void
	{
		$offers = Service_Nj662_Models::parse(
			'openrouter::google/gemini-3.1-flash-lite; openrouter::google/gemini-2.5-flash-lite; '
			.'TOOLFORGE::unsloth/qwen3.5-9b');

		$this->assertCount(3, $offers);
		$this->assertSame('openrouter::google/gemini-3.1-flash-lite', $offers[0]['spec']);
		$this->assertSame('openrouter', $offers[0]['provider']);
		$this->assertSame('google/gemini-3.1-flash-lite', $offers[0]['model']);
		// В подписи списка остаётся вендор: «google/gemini-…» отличается от
		// «google/gemini-…-lite» только хвостом, и вендор тут — часть имени.
		$this->assertSame('google/gemini-3.1-flash-lite (openrouter)', $offers[0]['label']);
		$this->assertSame('TOOLFORGE::unsloth/qwen3.5-9b', $offers[2]['spec']);
		$this->assertSame('unsloth/qwen3.5-9b (TOOLFORGE)', $offers[2]['label']);
	}

	public function test_parse_tolerates_newlines_commas_and_duplicates(): void
	{
		$offers = Service_Nj662_Models::parse("local::qwen\n, local::qwen | TOOLFORGE::qwen3; ; ");

		$this->assertCount(2, $offers, 'повторы и пустые элементы выбрасываются');
		$this->assertSame('local::qwen', $offers[0]['spec']);
		$this->assertSame('TOOLFORGE::qwen3', $offers[1]['spec']);
	}

	public function test_parse_without_provider_keeps_model_alone(): void
	{
		$offers = Service_Nj662_Models::parse('qwen3-coder-30b-a3b-instruct');

		$this->assertCount(1, $offers);
		$this->assertSame('', $offers[0]['provider']);
		$this->assertSame('qwen3-coder-30b-a3b-instruct', $offers[0]['spec']);
		$this->assertSame('qwen3-coder-30b-a3b-instruct', $offers[0]['label']);
	}

	public function test_parse_skips_broken_entries(): void
	{
		$offers = Service_Nj662_Models::parse('openrouter::; ::модель; TOOLFORGE::ok');

		// «openrouter::» без модели — мусор, выброшен. «::модель» читается как
		// имя без провайдера (провайдера выберет воркер): такую запись не
		// выбрасываем — она рабочая, просто короткая.
		$this->assertCount(2, $offers);
		$this->assertSame('модель', $offers[0]['spec']);
		$this->assertSame('', $offers[0]['provider']);
		$this->assertSame('TOOLFORGE::ok', $offers[1]['spec']);
	}

	public function test_config_offers_are_the_module_config_and_include_local_provider(): void
	{
		$offers = Service_Nj662_Models::offers();

		$this->assertNotEmpty($offers, 'список моделей задан в config/nj662_dialog.php');
		$this->assertSame(Service_Nj662_Models::default_spec(), $offers[0]['spec'],
			'умолчание диалога — первая модель списка');

		$providers = array_map(function ($o) { return mb_strtoupper($o['provider']); }, $offers);
		$this->assertContains('OPENROUTER', $providers);
		$this->assertContains('TOOLFORGE', $providers, 'своя модель воркера — в том же списке');
	}

	public function test_clean_custom_validates_shape(): void
	{
		$ok = Service_Nj662_Models::clean_custom(' TOOLFORGE :: qwen/qwen3.5-9b ');
		$this->assertSame('TOOLFORGE::qwen/qwen3.5-9b', $ok['spec']);
		$this->assertSame('', $ok['error']);

		$this->assertNotSame('', Service_Nj662_Models::clean_custom('openrouter::')['error']);
		$this->assertNotSame('', Service_Nj662_Models::clean_custom('::модель')['error']);
		$this->assertNotSame('', Service_Nj662_Models::clean_custom(str_repeat('a', 400))['error']);

		$empty = Service_Nj662_Models::clean_custom('   ');
		$this->assertSame('', $empty['spec']);
		$this->assertSame('', $empty['error'], 'пустая строка — это «модель воркера по умолчанию»');
	}

	public function test_from_form_prefers_custom_field(): void
	{
		$res = Service_Nj662_Models::from_form([
			'model'        => 'custom',
			'model_custom' => 'TOOLFORGE::qwen/qwen3.5-9b',
		]);

		$this->assertSame('TOOLFORGE::qwen/qwen3.5-9b', $res['spec']);

		$res = Service_Nj662_Models::from_form([
			'model'        => 'openrouter::google/gemini-2.5-flash-lite',
			'model_custom' => '',
		]);

		$this->assertSame('openrouter::google/gemini-2.5-flash-lite', $res['spec']);
	}

	public function test_from_form_with_custom_but_empty_field_means_default_model(): void
	{
		$res = Service_Nj662_Models::from_form(['model' => 'custom', 'model_custom' => '']);

		$this->assertSame('', $res['spec']);
		$this->assertSame('', $res['error']);
	}

	public function test_provider_hint_is_a_hint_not_a_ban(): void
	{
		$this->assertSame('провайдер по умолчанию воркера', Service_Nj662_Models::provider_hint(''));

		// Подсказка — это подсказка, а не запрет: провайдера может знать только
		// воркер (workers/.env сайт не видит), поэтому формулировка нейтральная.
		$hint = Service_Nj662_Models::provider_hint('openrouter');

		$this->assertContains($hint, [
			'провайдер объявлен на сайте',
			'провайдера нет среди конфигов сайта — проверит воркер',
		]);
	}
}
