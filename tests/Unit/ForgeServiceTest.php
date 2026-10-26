<?php

use PHPUnit\Framework\TestCase;

/**
 * ERP-222 «Кузница систем»: оценка артефактов без базы.
 *
 * Модуль держится на двух обещаниях: «ценность артефакта считается из четырёх
 * осей по весам конфига» и «Критик меняет ценность разобранного, а не только
 * оставляет текст». Если ломается первое, каталог показывает случайные числа;
 * если второе — «эволюция» исчезает. Проверяем оба обещания здесь, без БД:
 * те же функции закрыты стендом .tools/erp222_forge_stand.php (он же идёт в CI).
 */
class ForgeServiceTest extends TestCase
{
	private function artifact(array $over = []): array
	{
		return $over + [
			'title'       => 'Спецификация учёта заявок сервисного центра',
			'summary'     => 'Сервисный центр принимает заявки и распределяет их по мастерам, учитывает статусы и сроки',
			'body'        => 'Цель, границы, сценарии, входы и выходы.',
			'scores'      => [],
		];
	}

	public function test_scale_reads_units_percents_and_rubbish(): void
	{
		$this->assertSame(0.8, Service_Erp222_Forge::unit_scale(0.8));
		$this->assertSame(0.8, Service_Erp222_Forge::unit_scale(80));
		$this->assertSame(0.75, Service_Erp222_Forge::unit_scale('0,75'));
		$this->assertSame(1.0, Service_Erp222_Forge::unit_scale(140));
		$this->assertSame(0.0, Service_Erp222_Forge::unit_scale(-5));
		$this->assertSame(0.5, Service_Erp222_Forge::unit_scale('нет'));
	}

	public function test_value_score_uses_config_weights_and_neutral_axes(): void
	{
		$this->assertSame(100, Service_Erp222_Forge::value_score([
			'usefulness' => 1, 'concreteness' => 1, 'novelty' => 1, 'feasibility' => 1,
		]));

		$this->assertSame(0, Service_Erp222_Forge::value_score([
			'usefulness' => 0, 'concreteness' => 0, 'novelty' => 0, 'feasibility' => 0,
		]));

		// Молчание модели не наказываем: оси без оценки — нейтральные 0.5.
		$this->assertSame(50, Service_Erp222_Forge::value_score([]));

		// Веса конфига: полезность 0.35, остальное по нулям.
		$this->assertSame(35, Service_Erp222_Forge::value_score([
			'usefulness' => 1, 'concreteness' => 0, 'novelty' => 0, 'feasibility' => 0,
		]));
	}

	public function test_novelty_and_fingerprint_catch_repeat_not_rephrasing_only(): void
	{
		$same = [$this->artifact()];

		$repeat = Service_Erp222_Forge::novelty(
			'Спецификация учёта заявок сервисного центра. Сервисный центр принимает заявки и распределяет их по мастерам',
			$same
		);
		$fresh = Service_Erp222_Forge::novelty(
			'Контракт API склада: приёмка, отгрузка, инвентаризация. Каждый метод с примером ответа и кодами ошибок',
			$same
		);

		$this->assertLessThan(0.5, $repeat);
		$this->assertGreaterThan(0.8, $fresh);
		$this->assertSame(1.0, Service_Erp222_Forge::novelty('что угодно', []));

		$this->assertSame(
			Service_Erp222_Forge::fingerprint('Спецификация   учёта', 'Кратко о  документе'),
			Service_Erp222_Forge::fingerprint('спецификация учёта', 'кратко о документе')
		);
		$this->assertNotSame(
			Service_Erp222_Forge::fingerprint('Спецификация', 'первый вариант'),
			Service_Erp222_Forge::fingerprint('Спецификация', 'второй вариант')
		);
	}

	public function test_artifact_normalize_falls_back_to_role_type_and_rejects_retelling(): void
	{
		$ok = Service_Erp222_Artifact::normalize([
			'type'       => 'ерунда',
			'title'      => "  Схема   данных  ",
			'summary'    => 'Таблицы заявок, мастеров и статусов',
			'body'       => str_repeat('CREATE TABLE orders (id bigserial PRIMARY KEY); ', 10),
			'metadata'   => ['inputs' => "заявки клиентов; справочник услуг", 'outputs' => ['таблица orders']],
			'scores'     => ['usefulness' => 90, 'concreteness' => '0,8'],
			'depends_on' => [3, '5', 0, -1],
			'sources'    => ['факт #12', ''],
		], [], ['schema', 'spec']);

		$this->assertTrue($ok['ok']);
		$this->assertSame('schema', $ok['artifact']['type']);
		$this->assertSame('Схема данных', $ok['artifact']['title']);
		$this->assertSame(['заявки клиентов', 'справочник услуг'], $ok['artifact']['metadata']['inputs']);
		$this->assertSame(0.9, $ok['artifact']['scores']['usefulness']);
		$this->assertSame(0.8, $ok['artifact']['scores']['concreteness']);
		$this->assertSame([3, 5], $ok['artifact']['depends_on']);
		$this->assertSame(['факт #12'], $ok['artifact']['sources']);

		$this->assertFalse(Service_Erp222_Artifact::normalize(['body' => str_repeat('x', 500)], [], ['spec'])['ok']);
		$this->assertFalse(Service_Erp222_Artifact::normalize(['title' => 'Т', 'body' => 'коротко'], [], ['spec'])['ok']);
	}

	public function test_critique_recalculates_the_reviewed_artifact_value(): void
	{
		$target = ['scores' => ['usefulness' => 0.9, 'concreteness' => 0.9, 'novelty' => 0.9, 'feasibility' => 0.9]];

		$strict = Service_Erp222_Artifact::critique_target($target, [
			'scores' => ['usefulness' => 0.3, 'concreteness' => 0.4, 'novelty' => 0.9, 'feasibility' => 0.2],
		]);

		$this->assertLessThan(60, $strict['value_score']);
		$this->assertSame(0.9, $strict['scores']['novelty']);   // о чём Критик молчал — не тронуто
		$this->assertSame(90, Service_Erp222_Forge::value_score($target['scores']));

		// Мягкая критика не сбрасывает артефакт в черновики.
		$soft = Service_Erp222_Artifact::critique_target($target, [
			'scores' => ['usefulness' => 0.8, 'concreteness' => 0.7, 'novelty' => 0.9, 'feasibility' => 0.8],
		]);

		$this->assertGreaterThan($strict['value_score'], $soft['value_score']);
		$this->assertGreaterThan(70, $soft['value_score']);
	}

	public function test_counters_text_speaks_in_numbers(): void
	{
		$this->assertSame('ставлю задачу в очередь…', Service_Erp222_Forge::counters_text([]));

		$this->assertSame('шаг 3/6 · артефактов: 2 · ценность: 120', Service_Erp222_Forge::counters_text([
			'steps_planned' => 6,
			'steps_done'    => 2,
			'artifacts_new' => 2,
			'stats'         => ['value_total' => 120],
		]));
	}
}
