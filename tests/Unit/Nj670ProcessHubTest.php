<?php

use PHPUnit\Framework\TestCase;

/**
 * ProcessHub (nj670): чистые проверки без БД и сети.
 *
 * Здесь закреплены три места, где ошибка стоит дорого и не видна глазом:
 *   · правила синтеза (роль/срок/ожидание из строки регламента);
 *   · майнинг (русский CSV, транслит кодов, минуты вместо секунд);
 *   · ГОСТ-документ (DOCX собирается собственным ZIP-писателем, если ext/zip
 *     на хосте не собран).
 */
class Nj670ProcessHubTest extends TestCase
{
	protected function _ontology()
	{
		$role = ['code' => 'lawyer', 'name' => 'Юрист'];
		$initiator = ['code' => 'initiator', 'name' => 'Инициатор'];
		$system = ['code' => '1c', 'name' => '1С'];
		$artifact = ['code' => 'memo', 'name' => 'Служебная записка'];

		return [
			'list' => [
				'role'     => [$role, $initiator],
				'system'   => [$system],
				'artifact' => [$artifact],
			],
			'by_code' => [
				'role'     => ['lawyer' => $role, 'initiator' => $initiator],
				'system'   => ['1c' => $system],
				'artifact' => ['memo' => $artifact],
			],
			'by_name' => [
				'role'     => ['юрист' => 'lawyer', 'инициатор' => 'initiator'],
				'system'   => ['1с' => '1c'],
				'artifact' => ['служебная записка' => 'memo'],
			],
		];
	}

	public function test_rules_synthesis_links_start_end_and_reads_durations()
	{
		$text = "Инициатор создаёт служебную записку в 1С, 60 мин\n"
			."Юрист проверяет проект договора, ожидание 2 ч\n"
			."Бухгалтер оплачивает счёт";

		$build = Service_Nj670_Synthesis::from_text($text, $this->_ontology(), ['title' => 'Закупка']);

		$this->assertTrue($build['ok'], (string) Arr::get($build, 'error', ''));
		$this->assertSame(3, $build['steps']);

		$graph = $build['graph'];
		$nodes = [];
		foreach ($graph['nodes'] as $node)
		{
			$nodes[$node['id']] = $node;
		}

		$this->assertArrayHasKey('start', $nodes);
		$this->assertArrayHasKey('end', $nodes);
		$this->assertSame('lawyer', $nodes['s2']['role_code']);
		$this->assertSame(18, $nodes['s2']['duration_minutes']);
		$this->assertSame(120, $nodes['s2']['wait_time_minutes']);
		$this->assertSame('initiator', $nodes['s1']['role_code']);
		$this->assertSame('1c', $nodes['s1']['system_code']);
		$this->assertSame('memo', $nodes['s1']['input_code']);

		$from = [];
		foreach ($graph['edges'] as $edge)
		{
			$from[] = $edge['from'].'>'.$edge['to'];
		}

		$this->assertContains('start>s1', $from);
		$this->assertContains('s3>end', $from);
	}

	public function test_copilot_keeps_manual_layout_and_anchors_new_node()
	{
		$current = [
			'lanes' => [['id' => 'lane_lawyer', 'title' => 'Юрист', 'role_code' => 'lawyer']],
			'nodes' => [
				['id' => 'start', 'step_id' => 'start', 'type' => 'start', 'title' => 'Начало', 'x' => 54, 'y' => 135],
				['id' => 'legal', 'step_id' => 'legal', 'type' => 'user_task', 'title' => 'Проверка',
					'role_code' => 'lawyer', 'x' => 322, 'y' => 200, 'duration_minutes' => 30],
				['id' => 'end', 'step_id' => 'end', 'type' => 'end', 'title' => 'Конец', 'x' => 900, 'y' => 135],
			],
			'edges' => [
				['from' => 'start', 'to' => 'legal', 'type' => 'sequence'],
				['from' => 'legal', 'to' => 'end', 'type' => 'sequence'],
			],
			'meta' => ['title' => 'Закупка', 'mode' => 'plan'],
		];

		$patch = [
			'meta' => ['source' => 'llm'],
			'nodes' => [
				['id' => 'security', 'step_id' => 'security', 'type' => 'user_task',
					'title' => 'Проверка СБ', 'role_code' => 'lawyer', 'duration_minutes' => 45],
			],
			'edges' => [
				['from' => 'legal', 'to' => 'security', 'type' => 'sequence'],
				['from' => 'security', 'to' => 'end', 'type' => 'sequence'],
			],
		];

		$merged = Service_Nj670_Synthesis::merge_copilot($current, $patch, 'legal', $this->_ontology());
		$by_id = [];

		foreach ($merged['nodes'] as $node)
		{
			$by_id[$node['id']] = $node;
		}

		// Ручная раскладка не должна «уехать» после ответа модели.
		$this->assertSame(322, (int) $by_id['legal']['x']);
		$this->assertSame(200, (int) $by_id['legal']['y']);
		$this->assertSame(54, (int) $by_id['start']['x']);
		$this->assertSame(900, (int) $by_id['end']['x']);

		// Новый шаг получил координаты рядом с выбранным, а не 0,0.
		$this->assertGreaterThan(322, (int) $by_id['security']['x']);
		$this->assertSame('llm', $merged['meta']['source']);
		$this->assertCount(4, $merged['edges']);
	}

	public function test_metrics_use_calendar_sla_for_bottleneck_share()
	{
		$graph = Service_Nj670_Graph::normalize([
			'nodes' => [
				['id' => 'start', 'step_id' => 'start', 'type' => 'start', 'title' => 'Начало'],
				['id' => 'security', 'step_id' => 'security', 'type' => 'user_task',
					'title' => 'Проверка СБ', 'role_code' => 'lawyer',
					'duration_minutes' => 120, 'wait_time_minutes' => 600],
				['id' => 'end', 'step_id' => 'end', 'type' => 'end', 'title' => 'Конец'],
			],
			'edges' => [
				['from' => 'start', 'to' => 'security', 'type' => 'sequence'],
				['from' => 'security', 'to' => 'end', 'type' => 'sequence'],
			],
			'meta' => ['elapsed_minutes' => 1000, 'mode' => 'fact'],
		], $this->_ontology());

		$metrics = Service_Nj670_Graph::metrics($graph, $this->_ontology());

		// Срок — календарный (meta), а не сумма работ: у факта ветки идут
		// параллельно, и «3 ч работы» не должны превращаться в «3 ч срока».
		$this->assertSame(1000, $metrics['elapsed_minutes']);
		$this->assertSame(120, $metrics['work_minutes']);
		$this->assertSame(600, $metrics['wait_minutes']);
		$this->assertSame('security', $metrics['bottleneck']['step_id']);
		$this->assertSame(0.6, $metrics['bottleneck']['share']);
		$this->assertGreaterThan(0, $metrics['cost_rub']);
	}

	public function test_raci_marks_performer_owner_and_neighbours()
	{
		$graph = Service_Nj670_Graph::normalize([
			'nodes' => [
				['id' => 'start', 'step_id' => 'start', 'type' => 'start', 'title' => 'Начало'],
				['id' => 'memo', 'step_id' => 'memo', 'type' => 'user_task', 'title' => 'Записка',
					'role_code' => 'initiator'],
				['id' => 'legal', 'step_id' => 'legal', 'type' => 'user_task', 'title' => 'Проверка',
					'role_code' => 'lawyer'],
				['id' => 'end', 'step_id' => 'end', 'type' => 'end', 'title' => 'Конец'],
			],
			'edges' => [
				['from' => 'start', 'to' => 'memo', 'type' => 'sequence'],
				['from' => 'memo', 'to' => 'legal', 'type' => 'sequence'],
				['from' => 'legal', 'to' => 'end', 'type' => 'sequence'],
			],
		], $this->_ontology());

		$raci = Service_Nj670_Graph::raci($graph, 'lawyer', $this->_ontology());
		$columns = [];

		foreach ($raci['columns'] as $column)
		{
			$columns[] = $column['code'];
		}

		$this->assertContains('initiator', $columns);
		$this->assertContains('lawyer', $columns);
		$this->assertCount(2, $raci['rows']);

		$cells = [];
		foreach ($raci['rows'] as $row)
		{
			$cells[$row['step_id']] = $row['cells'];
		}

		$this->assertSame('R', $cells['memo']['initiator']);
		$this->assertSame('A', $cells['memo']['lawyer']);
		$this->assertSame('R/A', $cells['legal']['lawyer']);
	}

	public function test_mining_transliterates_russian_activities_and_counts_minutes()
	{
		$csv = "Case_ID;Activity;Timestamp;Resource\n"
			."C1;Заявка;2026-09-01 09:00;Инициатор\n"
			."C1;Проверка юриста;2026-09-02 09:00;Юрист\n"
			."C1;Оплата;2026-09-03 09:00;Бухгалтер\n"
			."C2;Заявка;2026-09-01 10:00;Инициатор\n"
			."C2;Оплата;2026-09-02 10:00;Бухгалтер\n";

		$mined = Service_Nj670_Mining::analyze($csv, 'csv', $this->_ontology());

		$this->assertTrue($mined['ok'], (string) Arr::get($mined, 'error', ''));
		$this->assertSame(2, $mined['stats']['cases']);
		$this->assertSame(5, $mined['stats']['events']);
		$this->assertSame(3, $mined['stats']['activities']);
		$this->assertSame(3, $mined['stats']['transitions']);

		// Срок — в минутах, а не в секундах: 1 сутки = 1440.
		$this->assertSame(1440, $mined['stats']['median_minutes']);

		$step_ids = [];
		foreach ($mined['graph']['nodes'] as $node)
		{
			$step_ids[] = $node['step_id'];
		}

		// Кириллица в кодах недопустима: сайт вырезает её из id узла.
		$this->assertContains('zayavka', $step_ids);
		$this->assertContains('proverka-yurista', $step_ids);
		$this->assertContains('oplata', $step_ids);
		$this->assertNotContains('заявка', $step_ids);
	}

	public function test_xlsx_journal_is_read_without_zip_extension()
	{
		// XLSX — обычный ZIP, а ext/zip на закрытом хосте может отсутствовать.
		// Читаем локальные заголовки и inline-строки без PhpSpreadsheet.
		$bytes = $this->_xlsx([
			['Case_ID', 'Activity', 'Timestamp', 'Resource'],
			['A-1', 'Заявка создана', '2026-05-01 09:00', 'Инициатор'],
			['A-1', 'Проверка юриста', '2026-05-01 11:00', 'Юрист'],
			['A-2', 'Заявка создана', '2026-05-01 10:00', 'Инициатор'],
			['A-2', 'Проверка юриста', '2026-05-01 12:00', 'Юрист'],
		]);

		$mined = Service_Nj670_Mining::analyze($bytes, 'xlsx', $this->_ontology());

		$this->assertTrue($mined['ok'], (string) Arr::get($mined, 'error', ''));
		$this->assertSame(2, $mined['stats']['cases']);
		$this->assertSame(4, $mined['stats']['events']);
		$this->assertSame(1, $mined['stats']['transitions']);
		$this->assertSame(120, $mined['stats']['median_minutes']);
	}

	/**
	 * Минимальный XLSX: один лист, строки inlineStr, ZIP без сжатия.
	 */
	protected function _xlsx(array $rows)
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

		foreach ($rows as $r => $cells)
		{
			$xml .= '<row r="'.($r + 1).'">';

			foreach ($cells as $c => $value)
			{
				$ref = chr(65 + $c).($r + 1);
				$xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'
					.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
					.'</t></is></c>';
			}

			$xml .= '</row>';
		}

		$xml .= '</sheetData></worksheet>';

		return $this->_zip_store(['xl/worksheets/sheet1.xml' => $xml]);
	}

	protected function _zip_store(array $files)
	{
		$local = '';
		$central = '';

		foreach ($files as $name => $data)
		{
			$crc = crc32($data);
			$size = strlen($data);
			$offset = strlen($local);

			$local .= "PK\x03\x04".pack('vvvvvVVVvv', 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0)
				.$name.$data;

			$central .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0, $crc, $size, $size,
				strlen($name), 0, 0, 0, 0, 0, $offset).$name;
		}

		return $local.$central."PK\x05\x06".pack('vvvvVVv', 0, 0, count($files), count($files),
			strlen($central), strlen($local), 0);
	}

	public function test_mining_reports_missing_columns_in_plain_words()
	{
		$mined = Service_Nj670_Mining::analyze("A;B\n1;2\n", 'csv', []);

		$this->assertFalse($mined['ok']);
		$this->assertStringContainsString('Case_ID', $mined['error']);
	}

	public function test_docx_regulation_has_steps_raci_and_audit()
	{
		$document = [
			'org_name'     => 'ООО «Демо-Компания»',
			'title'        => 'Закупка ТМЦ',
			'version_tag'  => 'plan-v1',
			'kind_label'   => 'План (To-Be)',
			'status_label' => 'Утверждена',
			'code'         => 'procdemo23',
			'owner'        => 'Иванов И. И.',
			'department'   => 'Отдел закупок',
			'city'         => 'Москва',
			'year'         => '2026',
			'date_text'    => '19.10.2026',
			'gost'         => 'ГОСТ Р 7.0.97-2016',
			'approve_role' => 'Владелец процесса',
			'metrics'      => ['elapsed_minutes' => 1440, 'work_minutes' => 230, 'wait_minutes' => 1210, 'cost_rub' => 23525],
			'steps'        => [
				['n' => 1, 'role' => 'Юрист', 'input' => 'Проект договора', 'title' => 'Проверяет договор',
					'system' => '1С', 'output' => 'Виза', 'sla' => '18 мин работы + 2 ч ожидания'],
			],
			'raci'         => [
				'columns' => [['code' => 'lawyer', 'name' => 'Юрист']],
				'rows'    => [['step_id' => 'legal', 'title' => 'Проверяет договор', 'cells' => ['lawyer' => 'R/A']]],
			],
			'graph'        => [
				'lanes' => [
					['id' => 'lawyer', 'title' => 'Юрист', 'role_code' => 'lawyer'],
					['id' => 'accountant', 'title' => 'Бухгалтерия', 'role_code' => 'accountant'],
				],
				'nodes' => [
					['id' => 'start', 'step_id' => 'start', 'type' => 'start', 'title' => 'Начало', 'lane' => 'lawyer'],
					['id' => 'legal', 'step_id' => 'legal', 'type' => 'user_task', 'title' => 'Проверяет договор',
						'lane' => 'lawyer', 'duration_minutes' => 18, 'wait_time_minutes' => 120],
					['id' => 'pay', 'step_id' => 'pay', 'type' => 'user_task', 'title' => 'Оплачивает счёт',
						'lane' => 'accountant', 'duration_minutes' => 30, 'wait_time_minutes' => 0],
					['id' => 'end', 'step_id' => 'end', 'type' => 'end', 'title' => 'Конец', 'lane' => 'accountant'],
				],
			],
			'rules'        => [['code' => 'control_legal', 'name' => 'Виза юриста', 'note' => 'Обязательна']],
			'audit'        => [
				'summary'    => 'Факт дольше плана в 4,7 раза.',
				'risk_level' => 'critical',
				'impact_rub' => 18400,
				'findings'   => [
					['severity' => 'critical', 'title' => 'Telegram вместо 1С',
						'evidence' => '3600 мин ожидания', 'recommendation' => 'Запретить мессенджер', 'impact_rub' => 7200],
				],
			],
		];

		$bytes = Service_Nj670_Report::build_docx($document);

		$this->assertStringStartsWith('PK', $bytes);
		$this->assertGreaterThan(2000, strlen($bytes));

		// DOCX — ZIP: имена частей лежат в центральном каталоге, поэтому ищем
		// их в сыром архиве (ext/zip на закрытом хосте может отсутствовать).
		$this->assertStringContainsString('word/document.xml', $bytes);
		$this->assertStringContainsString('[Content_Types].xml', $bytes);

		// Разбираем собственный архив так же, как это сделает Word: по
		// локальным заголовкам. Если писатель соврёт в размерах/CRC, тест
		// упадёт здесь, а не в чужом офисе.
		$xml = $this->_unzip($bytes, 'word/document.xml');

		$this->assertStringContainsString('Проверяет договор', $xml);
		$this->assertStringContainsString('RACI', $xml);
		$this->assertStringContainsString('18 400', $xml);
		$this->assertStringContainsString('Telegram вместо 1С', $xml);

		// Титульный лист по ГОСТ: блок утверждения и подпись владельца.
		$this->assertStringContainsString('УТВЕРЖДАЮ', $xml);
		$this->assertStringContainsString('Владелец процесса', $xml);

		// Маршрут по дорожкам. Здесь однажды был баг приоритета `AND` и `?:`:
		// вместо названия дорожки в таблицу попадало «1» (TRUE в строку).
		$this->assertStringContainsString('3. Схема процесса', $xml);
		$this->assertStringContainsString('Юрист', $xml);
		$this->assertStringContainsString('Бухгалтерия', $xml);
		$this->assertStringNotContainsString('<w:t xml:space="preserve">1</w:t><w:t xml:space="preserve">Проверяет договор', $xml);

		// Разделы идут по порядку и не дублируют номера.
		$this->assertStringContainsString('4. Матрица RACI', $xml);
		$this->assertStringContainsString('5. Контрольные правила', $xml);
		$this->assertStringContainsString('6. Результаты аудита', $xml);

		$pdf = Service_Nj670_Report::build_pdf($document);

		if ($pdf !== '')
		{
			$this->assertStringStartsWith('%PDF', $pdf);
		}
	}

	/**
	 * Достать файл из ZIP по локальным заголовкам (без ext/zip).
	 */
	protected function _unzip($bytes, $want)
	{
		$offset = 0;
		$size = strlen($bytes);

		while ($offset + 30 <= $size AND substr($bytes, $offset, 4) === "PK\x03\x04")
		{
			$header = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen',
				substr($bytes, $offset + 4, 26));
			$name = substr($bytes, $offset + 30, $header['namelen']);
			$data = substr($bytes, $offset + 30 + $header['namelen'] + $header['extralen'], $header['csize']);

			if ($name === $want)
			{
				if ($header['method'] === 8)
				{
					$data = gzinflate($data);
				}

				$this->assertNotFalse($data, 'архив повреждён: '.$name);
				return $data;
			}

			$offset += 30 + $header['namelen'] + $header['extralen'] + $header['csize'];
		}

		$this->fail('в DOCX нет части '.$want);
	}

	public function test_fact_svg_thickens_edges_and_colours_wait_hotspots()
	{
		$graph = [
			'lanes' => [['id' => 'l', 'title' => 'Юрист', 'role_code' => 'lawyer']],
			'nodes' => [
				['id' => 'a', 'step_id' => 'a', 'type' => 'user_task', 'title' => 'A', 'lane' => 'l',
					'role_code' => 'lawyer', 'system_code' => '', 'duration_minutes' => 10, 'wait_time_minutes' => 0,
					'properties' => [], 'x' => 40, 'y' => 40, 'w' => 196, 'h' => 86],
				['id' => 'b', 'step_id' => 'b', 'type' => 'user_task', 'title' => 'B', 'lane' => 'l',
					'role_code' => 'lawyer', 'system_code' => '', 'duration_minutes' => 10, 'wait_time_minutes' => 0,
					'properties' => [], 'x' => 300, 'y' => 40, 'w' => 196, 'h' => 86],
				['id' => 'c', 'step_id' => 'c', 'type' => 'user_task', 'title' => 'C', 'lane' => 'l',
					'role_code' => 'lawyer', 'system_code' => '', 'duration_minutes' => 10, 'wait_time_minutes' => 0,
					'properties' => [], 'x' => 560, 'y' => 40, 'w' => 196, 'h' => 86],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'a', 'to' => 'b', 'type' => 'sequence', 'condition' => '', 'weight' => 40, 'avg_minutes' => 600],
				['id' => 'e2', 'from' => 'b', 'to' => 'c', 'type' => 'sequence', 'condition' => '', 'weight' => 5, 'avg_minutes' => 180],
			],
			'meta' => ['mode' => 'fact', 'elapsed_minutes' => 600, 'width' => 900, 'height' => 400],
		];

		$svg = Service_Nj670_Graph::svg($graph, ['mode' => 'fact']);

		// Горячий переход (10 ч ожидания) — приглушённый рубиновый и толще холодного.
		$this->assertMatchesRegularExpression('~stroke="#a85252" stroke-width="4\.4"~', $svg);
		$this->assertMatchesRegularExpression('~stroke="#9a7b3f" stroke-width="1\.6"~', $svg);
	}

	public function test_json_api_actions_are_real_methods()
	{
		// В Kohana параметр маршрута с именем «action» подменяет вызываемый
		// метод. Пока все действия жили в action_index, /process/api/save
		// отвечал 404 (и холст молча не сохранялся). Действия обязаны быть
		// объявлены методами — это и закрепляем.
		foreach (['status', 'save', 'synth', 'audit', 'report', 'delete', 'ontology'] as $action)
		{
			$this->assertTrue(
				method_exists('Controller_Nj670_Api', 'action_'.$action),
				'нет метода action_'.$action.' у JSON API'
			);
		}

		foreach (['context', 'graph', 'audit', 'report', 'fail', 'ontology', 'ontology_fail'] as $action)
		{
			$this->assertTrue(
				method_exists('Controller_Nj670_Worker', 'action_'.$action),
				'нет метода action_'.$action.' у приёмной воркера'
			);
		}
	}

	public function test_presence_prefers_worker_ping()
	{
		$now = 1_700_000_000;

		// Свежий пульс: воркер жив, даже если задач не было вовсе.
		$state = Service_Nj670_Process::presence_state(gmdate('c', $now - 60), 5, NULL, 60, $now);
		$this->assertTrue($state['online']);
		$this->assertSame('ping', $state['source']);
		$this->assertSame(1, $state['ping_age_minutes']);

		// Возраст считается целыми минутами: 5:59 — ещё в окне, 6:00 — уже нет.
		$this->assertTrue(Service_Nj670_Process::presence_state(gmdate('c', $now - 359), 5, NULL, 60, $now)['online']);
		$this->assertFalse(Service_Nj670_Process::presence_state(gmdate('c', $now - 360), 5, NULL, 60, $now)['online']);
	}

	public function test_presence_falls_back_to_job_heartbeat()
	{
		$now = 1_700_000_000;

		// Пульса нет, но воркер занят задачей — это тоже «на связи».
		$state = Service_Nj670_Process::presence_state(NULL, 5, gmdate('c', $now - 120), 60, $now);
		$this->assertTrue($state['online']);
		$this->assertSame('job', $state['source']);

		// Пульс устарел, задача ещё свежая — верим задаче.
		$state = Service_Nj670_Process::presence_state(gmdate('c', $now - 3600), 5, gmdate('c', $now - 600), 60, $now);
		$this->assertTrue($state['online']);
		$this->assertSame('job', $state['source']);
	}

	public function test_presence_offline_when_nothing_is_fresh()
	{
		$now = 1_700_000_000;
		$state = Service_Nj670_Process::presence_state(NULL, 5, NULL, 60, $now);

		$this->assertFalse($state['online']);
		$this->assertSame('none', $state['source']);
		$this->assertNull($state['last_ping_at']);

		$state = Service_Nj670_Process::presence_state(gmdate('c', $now - 900), 5, gmdate('c', $now - 7200), 60, $now);
		$this->assertFalse($state['online']);
		$this->assertSame(15, $state['ping_age_minutes']);
	}

	public function test_presence_tolerates_clock_skew()
	{
		$now = 1_700_000_000;

		// Пульс «из будущего» (часы воркера убежали) — свежий, не отрицательный.
		$state = Service_Nj670_Process::presence_state(gmdate('c', $now + 120), 5, NULL, 60, $now);
		$this->assertTrue($state['online']);
		$this->assertSame(0, $state['ping_age_minutes']);

		// Мусор вместо времени не должен ронять страницу.
		$state = Service_Nj670_Process::presence_state('не дата', 5, '', 60, $now);
		$this->assertFalse($state['online']);
		$this->assertNull($state['ping_age_minutes']);
	}

	public function test_title_from_text_uses_first_meaningful_line()
	{
		$this->assertSame('Инициатор создаёт заявку в 1С',
			Service_Nj670_Repo::title_from_text("\n\n1. Инициатор создаёт заявку в 1С.\n2. Руководитель согласует."));

		$this->assertSame('Закупка ТМЦ',
			Service_Nj670_Repo::title_from_text("- Закупка ТМЦ\nРуководитель согласует заявку"));

		$this->assertSame('Шаг', Service_Nj670_Repo::title_from_text('Шаг'));
	}

	public function test_title_from_text_truncates_long_first_sentence()
	{
		$long = 'Инициатор создаёт служебную записку в системе 1С и прикладывает коммерческое предложение поставщика, а затем отправляет всё руководителю подразделения на согласование';
		$title = Service_Nj670_Repo::title_from_text($long);

		$this->assertTrue(UTF8::strlen($title) <= 90, 'заголовок не длиннее 90 знаков');
		// «Инициатор создаёт служебную записку» — ровно 35 знаков, дальше пробел.
		$this->assertSame('Инициатор создаёт служебную записку', UTF8::substr($title, 0, 35));
		$this->assertMatchesRegularExpression('~[^\s.,;:—-]$~u', $title, 'без хвостовой пунктуации');
	}

	public function test_title_from_text_falls_back_to_name()
	{
		$this->assertSame('Договор поставки', Service_Nj670_Repo::title_from_text('', 'Договор поставки'));
		$this->assertTrue(strpos(Service_Nj670_Repo::title_from_text('   '), 'Процесс от ') === 0);
	}

	public function test_money_shows_kopecks_only_when_needed()
	{
		$this->assertSame('23 525 ₽', Service_Nj670_Repo::money(23525));
		$this->assertSame('137 387,50 ₽', Service_Nj670_Repo::money(137387.5));
		$this->assertSame('0 ₽', Service_Nj670_Repo::money(0));
	}

	public function test_duration_text_uses_working_days()
	{
		$this->assertSame('45 мин', Service_Nj670_Repo::minutes_text(45));
		$this->assertSame('2 ч 30 мин', Service_Nj670_Repo::minutes_text(150));
		$this->assertSame('14 раб. дн.', Service_Nj670_Repo::minutes_text(6720));
		$this->assertSame('7 раб. дн. 4 ч', Service_Nj670_Repo::minutes_text(3600));
	}

	public function test_bpmn_export_keeps_lanes_tasks_and_flow_conditions()
	{
		$process = ['code' => 'zakupki', 'title' => 'Закупки & контроль'];
		$version = ['id' => 1, 'kind' => 'plan'];
		$graph = [
			'lanes' => [
				['id' => 'buyer', 'title' => 'Закупщик'],
				['id' => 'lawyer', 'title' => 'Юрист'],
			],
			'nodes' => [
				['id' => 'start', 'type' => 'start', 'title' => 'Начало', 'lane' => 'buyer'],
				['id' => 'n1', 'type' => 'user_task', 'title' => 'Создать заявку', 'lane' => 'buyer'],
				['id' => 'g1', 'type' => 'gateway_xor', 'title' => 'Сумма > 500 000?', 'lane' => 'lawyer'],
				['id' => 'n2', 'type' => 'manual_task', 'title' => 'Проверить договор', 'lane' => 'lawyer'],
				['id' => 'end', 'type' => 'end', 'title' => 'Конец', 'lane' => 'lawyer'],
			],
			'edges' => [
				['from' => 'start', 'to' => 'n1'],
				['from' => 'n1', 'to' => 'g1'],
				['from' => 'g1', 'to' => 'n2', 'condition' => 'Да'],
				['from' => 'n2', 'to' => 'end'],
			],
		];

		$xml = Service_Nj670_Graph::bpmn_xml($process, $version, $graph);

		$this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
		$this->assertStringContainsString('xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"', $xml);
		$this->assertStringContainsString('<bpmn:laneSet', $xml);
		$this->assertStringContainsString('<bpmn:flowNodeRef>n1</bpmn:flowNodeRef>', $xml);
		$this->assertStringContainsString('<bpmn:userTask id="n1" name="Создать заявку"/>', $xml);
		$this->assertStringContainsString('<bpmn:exclusiveGateway id="g1"', $xml);
		$this->assertStringContainsString('<bpmn:sequenceFlow id="Flow_3"', $xml);
		$this->assertStringContainsString('name="Да"', $xml);
		// Спецсимволы не ломают XML.
		$this->assertStringContainsString('Закупки &amp; контроль', $xml);
		$this->assertStringContainsString('Сумма &gt; 500 000?', $xml);
	}

	public function test_bpmn_round_trip_keeps_lanes_and_conditions()
	{
		$process = ['code' => 'zakupki', 'title' => 'Закупки'];
		$graph = [
			'lanes' => [
				['id' => 'buyer', 'title' => 'Закупщик'],
				['id' => 'lawyer', 'title' => 'Юрист'],
			],
			'nodes' => [
				['id' => 'start', 'type' => 'start', 'title' => 'Начало', 'lane' => 'buyer'],
				['id' => 'n1', 'type' => 'user_task', 'title' => 'Создать заявку', 'lane' => 'buyer', 'x' => 200, 'y' => 60],
				['id' => 'g1', 'type' => 'gateway_xor', 'title' => 'Сумма?', 'lane' => 'lawyer', 'x' => 500, 'y' => 60],
				['id' => 'n2', 'type' => 'manual_task', 'title' => 'Проверить договор', 'lane' => 'lawyer', 'x' => 700, 'y' => 60],
				['id' => 'end', 'type' => 'end', 'title' => 'Конец', 'lane' => 'lawyer', 'x' => 950, 'y' => 60],
			],
			'edges' => [
				['from' => 'start', 'to' => 'n1'],
				['from' => 'n1', 'to' => 'g1'],
				['from' => 'g1', 'to' => 'n2', 'condition' => 'Да'],
				['from' => 'n2', 'to' => 'end'],
			],
		];

		$xml = Service_Nj670_Graph::bpmn_xml($process, ['id' => 1], $graph);
		$imported = Service_Nj670_Bpmn::xml_to_graph($xml, []);

		$this->assertTrue($imported['ok'], (string) Arr::get($imported, 'error'));
		$this->assertSame(5, $imported['stats']['nodes']);
		$this->assertSame(4, $imported['stats']['edges']);
		$this->assertSame(2, $imported['stats']['lanes']);

		$types = [];

		foreach ($imported['graph']['nodes'] as $node)
		{
			$types[$node['id']] = $node['type'];
		}

		$this->assertSame('start', $types['start']);
		$this->assertSame('user_task', $types['n1']);
		$this->assertSame('gateway_xor', $types['g1']);
		$this->assertSame('manual_task', $types['n2']);

		// Условие перехода не теряется при круге туда-обратно.
		$conditional = 0;

		foreach ($imported['graph']['edges'] as $edge)
		{
			if ($edge['from'] === 'g1' AND $edge['condition'] === 'Да')
			{
				$conditional++;
			}
		}

		$this->assertSame(1, $conditional);
	}

	public function test_bpmn_export_marks_call_activity_for_linked_process()
	{
		$graph = [
			'lanes' => [['id' => 'l', 'title' => 'Юрист']],
			'nodes' => [
				['id' => 'start', 'type' => 'start', 'title' => 'Старт', 'lane' => 'l'],
				['id' => 'call', 'type' => 'subprocess', 'title' => 'Согласовать', 'lane' => 'l',
					'properties' => ['process_code' => 'soglasovanie']],
				['id' => 'end', 'type' => 'end', 'title' => 'Конец', 'lane' => 'l'],
			],
			'edges' => [
				['from' => 'start', 'to' => 'call'],
				['from' => 'call', 'to' => 'end'],
			],
		];

		$xml = Service_Nj670_Graph::bpmn_xml(['code' => 'p', 'title' => 'P'], ['id' => 1], $graph);
		$this->assertStringContainsString('<bpmn:callActivity id="call" name="Согласовать" calledElement="soglasovanie"/>', $xml);

		$imported = Service_Nj670_Bpmn::xml_to_graph($xml, []);
		$this->assertTrue($imported['ok']);
		$this->assertSame('subprocess', $imported['graph']['nodes'][1]['type']);
		$this->assertSame('soglasovanie', $imported['graph']['nodes'][1]['properties']['process_code']);
	}

	public function test_bpmn_export_round_trips_reviewer_comments()
	{
		$graph = Service_Nj670_Graph::normalize([
			'lanes' => [['id' => 'lawyer', 'title' => 'Юрист']],
			'nodes' => [
				['id' => 'start', 'type' => 'start', 'title' => 'Старт', 'lane' => 'lawyer'],
				['id' => 'check', 'type' => 'user_task', 'title' => 'Проверить договор', 'lane' => 'lawyer',
					'properties' => ['comment' => 'Приложение не проверяют']],
			],
			'edges' => [['from' => 'start', 'to' => 'check']],
		]);

		$xml = Service_Nj670_Graph::bpmn_xml(
			['code' => 'abcd2345', 'title' => 'Закупка'],
			['version_tag' => 'v1'],
			$graph
		);

		$this->assertTrue(strpos($xml, '<bpmn:textAnnotation') !== FALSE, 'аннотация не выгружена');
		$this->assertTrue(strpos($xml, '<bpmn:association') !== FALSE, 'связь аннотации не выгружена');

		$back = Service_Nj670_Bpmn::xml_to_graph($xml, []);
		$this->assertTrue($back['ok']);

		$comments = [];
		foreach ($back['graph']['nodes'] as $node) { $comments[$node['id']] = (string) Arr::get($node['properties'], 'comment', ''); }
		$this->assertSame('Приложение не проверяют', $comments['check']);
	}

	public function test_bpmn_import_turns_text_annotations_into_node_comments()
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			.'<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL">'
			.'<bpmn:process id="p1" name="Закупка">'
			.'<bpmn:userTask id="task_1" name="Проверить договор"/>'
			.'<bpmn:userTask id="task_2" name="Оплатить"/>'
			.'<bpmn:textAnnotation id="note_1"><bpmn:text>Приложение к договору не проверяют</bpmn:text></bpmn:textAnnotation>'
			.'<bpmn:association id="a1" sourceRef="note_1" targetRef="task_1"/>'
			.'<bpmn:textAnnotation id="note_2"><bpmn:text>Висит без связи</bpmn:text></bpmn:textAnnotation>'
			.'<bpmn:association id="a2" sourceRef="note_2" targetRef="missing"/>'
			.'</bpmn:process></bpmn:definitions>';

		$imported = Service_Nj670_Bpmn::xml_to_graph($xml, []);

		$this->assertTrue($imported['ok'], (string) Arr::get($imported, 'error'));

		$by_id = [];
		foreach ($imported['graph']['nodes'] as $node) { $by_id[$node['id']] = $node; }

		$this->assertSame('Приложение к договору не проверяют', $by_id['task_1']['properties']['comment']);
		$this->assertArrayNotHasKey('comment', $by_id['task_2']['properties']);
		// Связь на несуществующий элемент не роняет импорт, но видна в warn.
		$this->assertTrue(count($imported['warnings']) > 0);
	}

	public function test_bpmn_import_reads_storm_layout_and_roles()
	{
		// Мини-файл в стиле Storm/Camunda: namespace-префиксы, bpmndi, условия.
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			.'<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"'
			.' xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI"'
			.' xmlns:dc="http://www.omg.org/spec/DD/20100524/DC"'
			.' xmlns:di="http://www.omg.org/spec/DD/20100524/DI">'
			.'<bpmn:process id="p1" name="Согласование заявки">'
			.'<bpmn:documentation>Заявка проходит юриста и бухгалтера</bpmn:documentation>'
			.'<bpmn:laneSet><bpmn:lane id="lane_lawyer" name="Юрист">'
			.'<bpmn:flowNodeRef>task_1</bpmn:flowNodeRef></bpmn:lane>'
			.'<bpmn:lane id="lane_acc" name="Бухгалтер"><bpmn:flowNodeRef>task_2</bpmn:flowNodeRef></bpmn:lane>'
			.'</bpmn:laneSet>'
			.'<bpmn:startEvent id="start_1" name="Заявка"/>'
			.'<bpmn:userTask id="task_1" name="Проверить договор"/>'
			.'<bpmn:manualTask id="task_2" name="Оплатить счёт"/>'
			.'<bpmn:exclusiveGateway id="gw_1" name="Сумма &gt; 500 000"/>'
			.'<bpmn:endEvent id="end_1" name="Готово"/>'
			.'<bpmn:sequenceFlow id="f1" sourceRef="start_1" targetRef="task_1"/>'
			.'<bpmn:sequenceFlow id="f2" sourceRef="task_1" targetRef="gw_1"/>'
			.'<bpmn:sequenceFlow id="f3" sourceRef="gw_1" targetRef="task_2">'
			.'<bpmn:conditionExpression>${sum &gt; 500000}</bpmn:conditionExpression>'
			.'</bpmn:sequenceFlow>'
			.'<bpmn:sequenceFlow id="f4" sourceRef="task_2" targetRef="end_1"/>'
			.'</bpmn:process>'
			.'<bpmndi:BPMNDiagram id="d1"><bpmndi:BPMNPlane id="pl1" bpmnElement="p1">'
			.'<bpmndi:BPMNShape id="s1" bpmnElement="task_1"><dc:Bounds x="220" y="140" width="196" height="86"/></bpmndi:BPMNShape>'
			.'<bpmndi:BPMNShape id="s2" bpmnElement="task_2"><dc:Bounds x="520" y="300" width="196" height="86"/></bpmndi:BPMNShape>'
			.'</bpmndi:BPMNPlane></bpmndi:BPMNDiagram>'
			.'</bpmn:definitions>';

		$ontology = $this->_ontology();
		$imported = Service_Nj670_Bpmn::xml_to_graph($xml, $ontology);

		$this->assertTrue($imported['ok'], (string) Arr::get($imported, 'error'));
		$this->assertSame('Согласование заявки', $imported['process']['title']);
		$this->assertSame(5, $imported['stats']['nodes']);
		$this->assertSame(4, $imported['stats']['edges']);
		$this->assertSame('bpmndi', $imported['stats']['layout']);

		// Роль дорожки сопоставлена со справочником, координаты взяты из DI.
		$by_id = [];

		foreach ($imported['graph']['nodes'] as $node)
		{
			$by_id[$node['id']] = $node;
		}

		$this->assertSame('lawyer', $by_id['task_1']['role_code']);
		$this->assertSame(220, (int) $by_id['task_1']['x']);
		$this->assertSame(140, (int) $by_id['task_1']['y']);
		$this->assertSame('', $by_id['task_2']['role_code'], 'бухгалтера нет в справочнике — остаётся подпись');
		$this->assertSame('Бухгалтер', $by_id['task_2']['properties']['role_label']);

		// Условие из conditionExpression стало условным переходом.
		$conditional = 0;

		foreach ($imported['graph']['edges'] as $edge)
		{
			if ($edge['from'] === 'gw_1')
			{
				$conditional++;
				$this->assertSame('conditional', $edge['type']);
				$this->assertStringContainsString('500000', $edge['condition']);
			}
		}

		$this->assertSame(1, $conditional);
	}

	public function test_bpmn_import_rejects_foreign_files_and_breaks_cycles()
	{
		$bad = Service_Nj670_Bpmn::xml_to_graph('<html><body>не BPMN</body></html>');
		$this->assertFalse($bad['ok']);
		$this->assertStringContainsString('BPMN', (string) $bad['error']);

		// Схема без старта/конца: импорт не падает, но предупреждает.
		$xml = '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL">'
			.'<bpmn:process id="p2" name="Без событий">'
			.'<bpmn:userTask id="a" name="A"/><bpmn:userTask id="b" name="B"/>'
			.'<bpmn:sequenceFlow id="f1" sourceRef="a" targetRef="b"/>'
			.'<bpmn:sequenceFlow id="f2" sourceRef="b" targetRef="a"/>'
			.'</bpmn:process></bpmn:definitions>';

		$imported = Service_Nj670_Bpmn::xml_to_graph($xml, []);

		$this->assertTrue($imported['ok']);
		$this->assertSame(2, $imported['stats']['nodes']);
		// Цикл из двух связей не должен превращаться в self-loop.
		foreach ($imported['graph']['edges'] as $edge)
		{
			$this->assertNotSame($edge['from'], $edge['to']);
		}
	}

	public function test_version_compare_marks_added_removed_and_changed()
	{
		$before = [
			'lanes' => [['id' => 'l', 'title' => 'Юрист']],
			'nodes' => [
				['id' => 'a', 'step_id' => 'a', 'type' => 'user_task', 'title' => 'Проверить договор',
					'role_code' => 'lawyer', 'duration_minutes' => 60, 'wait_time_minutes' => 0],
				['id' => 'b', 'step_id' => 'b', 'type' => 'user_task', 'title' => 'Оплатить',
					'role_code' => 'accountant', 'duration_minutes' => 30, 'wait_time_minutes' => 0],
				['id' => 'c', 'step_id' => 'c', 'type' => 'user_task', 'title' => 'Старый шаг',
					'role_code' => '', 'duration_minutes' => 10, 'wait_time_minutes' => 0],
			],
			'edges' => [
				['from' => 'a', 'to' => 'b'],
				['from' => 'b', 'to' => 'c'],
			],
		];

		$after = $before;
		// Шаг b поменял срок и название — это изменение, а не пересоздание.
		$after['nodes'][1]['title'] = 'Оплатить счёт';
		$after['nodes'][1]['duration_minutes'] = 45;
		// Шаг c убрали, добавили d и новую связь.
		unset($after['nodes'][2]);
		$after['nodes'][] = ['id' => 'd', 'step_id' => 'd', 'type' => 'user_task', 'title' => 'Проверка СБ',
			'role_code' => 'security', 'duration_minutes' => 120, 'wait_time_minutes' => 0];
		$after['edges'] = [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'd']];

		$diff = Service_Nj670_Compare::compare($before, $after);

		$this->assertSame(1, $diff['summary']['added']);
		$this->assertSame(1, $diff['summary']['removed']);
		$this->assertSame(1, $diff['summary']['changed']);
		$this->assertSame('Проверка СБ', $diff['added'][0]['title']);
		$this->assertSame('Старый шаг', $diff['removed'][0]['title']);
		$this->assertSame('Оплатить счёт', $diff['changed'][0]['title']);
		$this->assertContains('Название', $diff['changed'][0]['fields']);
		$this->assertContains('Работа', $diff['changed'][0]['fields']);
		$this->assertSame('45 мин', $diff['changed'][0]['after']['Работа']);
		$this->assertSame(1, $diff['summary']['edges_added']);
		$this->assertSame(1, $diff['summary']['edges_removed']);
		$this->assertSame(100, $diff['summary']['minutes_before']);
		$this->assertSame(225, $diff['summary']['minutes_after']);

		// Одинаковые схемы — пустая разница, страница не пугает ложными правками.
		$same = Service_Nj670_Compare::compare($before, $before);
		$this->assertTrue($same['summary']['is_empty']);
	}

	public function test_simulation_counts_load_queue_and_required_people()
	{
		$graph = [
			'nodes' => [
				['id' => 'a', 'type' => 'user_task', 'title' => 'Заявка', 'role_code' => 'buyer', 'duration_minutes' => 30],
				['id' => 'b', 'type' => 'user_task', 'title' => 'Сверка', 'role_code' => 'buyer', 'duration_minutes' => 30],
				['id' => 'c', 'type' => 'manual_task', 'title' => 'Договор', 'role_code' => 'lawyer', 'duration_minutes' => 120],
				['id' => 'd', 'type' => 'manual_task', 'title' => 'Без роли', 'duration_minutes' => 15],
			],
			'edges' => [
				['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'c'], ['from' => 'c', 'to' => 'd'],
			],
		];

		$index = $this->_ontology();

		$sim = Service_Nj670_Simulate::run($graph, $index, [
			'volume' => 100, 'workdays' => 21, 'workday_minutes' => 480,
			'people' => ['buyer' => 1, 'lawyer' => 2],
		]);

		$this->assertTrue($sim['ok']);
		$this->assertTrue($sim['feasible']);
		$this->assertSame(10080, $sim['capacity_month']);
		$this->assertSame(1, $sim['unassigned_steps']);
		$this->assertSame(15, $sim['unassigned_minutes']);

		$rows = [];
		foreach ($sim['roles'] as $row) { $rows[$row['code']] = $row; }

		$this->assertSame(60, $rows['buyer']['work']);
		$this->assertSame(2, $rows['buyer']['steps']);
		$this->assertTrue(abs(0.595 - $rows['buyer']['utilization']) < 0.001);
		$this->assertTrue(abs(88.2 - $rows['buyer']['queue']) < 0.2);
		$this->assertSame(1, $rows['buyer']['required']);
		$this->assertSame(168, $rows['buyer']['throughput']);

		$this->assertSame(2, $rows['lawyer']['people']);
		$this->assertSame(2, $rows['lawyer']['required']);
		$this->assertSame(168, $rows['lawyer']['throughput']);

		// Узкое место — юрист: у него та же загрузка, но очередь длиннее.
		$this->assertSame('lawyer', $sim['bottleneck']['code']);
		$this->assertSame(265, $sim['queue_minutes']);
		$this->assertSame(195 + 265, $sim['cycle_minutes']);
		$this->assertSame(168, $sim['throughput_max']);

		// Перегрузка: мощности не хватает, очередь не считается.
		$heavy = Service_Nj670_Simulate::run($graph, $index, [
			'volume' => 400, 'workdays' => 21, 'workday_minutes' => 480,
			'people' => ['buyer' => 1, 'lawyer' => 2],
		]);

		$this->assertFalse($heavy['feasible']);
		$this->assertNull($heavy['roles'][0]['queue']);
		$this->assertTrue($heavy['roles'][0]['required'] > $heavy['roles'][0]['people']);
		$this->assertTrue(count($heavy['warnings']) > 0);

		// Нулевая численность поднимается до одного человека, отрицательный
		// объём не превращает расчёт в деление на ноль.
		$safe = Service_Nj670_Simulate::run($graph, $index, [
			'volume' => -5, 'people' => ['buyer' => 0],
		]);
		$this->assertSame(1, $safe['volume']);
		$this->assertSame(1, $safe['roles'][0]['people']);
	}

	public function test_passport_architecture_and_coverage()
	{
		$plan = [
			'nodes' => [
				['id' => 's', 'type' => 'start', 'title' => 'Старт'],
				['id' => 'a', 'type' => 'user_task', 'title' => 'Заявка', 'role_code' => 'buyer',
					'system_code' => 'erp', 'input_code' => 'request_form', 'output_code' => 'request'],
				['id' => 'b', 'type' => 'user_task', 'title' => 'Проверка', 'role_code' => 'lawyer',
					'properties' => ['role_label' => 'Юрист', 'system_label' => 'СЭД', 'shadow' => TRUE,
						'overlay' => 'risk', 'comment' => 'Нет контроля приложения']],
				['id' => 'g', 'type' => 'gateway_xor', 'title' => 'Сумма?'],
			],
		];

		$fact = [
			'nodes' => [
				['id' => 'a', 'type' => 'user_task', 'title' => 'Заявка', 'role_code' => 'buyer',
					'system_code' => 'erp', 'input_code' => 'request_form'],
				['id' => 'c', 'type' => 'manual_task', 'title' => 'Звонок', 'role_code' => 'lawyer'],
			],
		];

		$index = [
			'by_code' => [
				'role' => [
					'buyer' => ['code' => 'buyer', 'name' => 'Закупщик'],
					'lawyer' => ['code' => 'lawyer', 'name' => 'Юрист'],
				],
				'system' => ['erp' => ['code' => 'erp', 'name' => '1С:ERP']],
				'artifact' => ['request_form' => ['code' => 'request_form', 'name' => 'Заявка на закупку']],
			],
		];

		$architecture = Service_Nj670_Process::architecture($plan, $fact, $index);

		// Роли отсортированы по частоте: у юриста 2 шага, у закупщика тоже 2,
		// поэтому порядок решает имя; проверяем состав и счётчики.
		$roles = [];
		foreach ($architecture['role'] as $row) { $roles[$row['code']] = $row; }
		$this->assertSame(1, $roles['buyer']['plan']);
		$this->assertSame(1, $roles['buyer']['fact']);
		$this->assertSame(2, $roles['lawyer']['plan'] + $roles['lawyer']['fact']);
		$this->assertSame('Закупщик', $roles['buyer']['name']);

		$this->assertSame('1С:ERP', $architecture['system'][0]['name']);
		$this->assertSame(1, $architecture['system'][0]['plan']);
		$this->assertSame(1, $architecture['system'][0]['fact']);

		$this->assertSame('Заявка на закупку', $architecture['artifact'][0]['name']);
		$this->assertSame(1, $architecture['artifact'][0]['in_plan']);
		$this->assertSame(1, $architecture['artifact'][0]['in_fact']);

		$coverage = Service_Nj670_Process::coverage($plan);
		$this->assertSame(2, $coverage['total']);
		$this->assertSame(2, $coverage['with_role']);
		$this->assertSame(1, $coverage['with_system']);
		$this->assertSame(1, $coverage['shadow']);
		$this->assertSame(1, $coverage['overlays']);
		$this->assertSame(1, $coverage['comments']);
		$this->assertSame(1.0, $coverage['role_share']);
		$this->assertSame(0.5, $coverage['system_share']);

		// Пустая схема не делит на ноль.
		$this->assertSame(0.0, Service_Nj670_Process::coverage([])['role_share']);
	}

	public function test_regulation_lists_reviewer_comments_only_for_marked_steps()
	{
		$document = [
			'steps' => [
				['n' => 1, 'title' => 'Проверить договор', 'overlay' => 'risk', 'comment' => 'Приложение не проверяют'],
				['n' => 2, 'title' => 'Оплатить', 'overlay' => '', 'comment' => ''],
				['n' => 3, 'title' => 'Согласовать', 'overlay' => 'control', 'comment' => ''],
			],
		];

		$rows = Service_Nj670_Report::comments_rows($document);

		$this->assertCount(2, $rows);
		$this->assertSame('1', $rows[0]['n']);
		$this->assertSame('Риск', $rows[0]['overlay']);
		$this->assertSame('Приложение не проверяют', $rows[0]['comment']);
		// Метка без текста — тоже повод показать строку: ревьюер пометил шаг.
		$this->assertSame('Контроль', $rows[1]['overlay']);

		$this->assertSame([
			'risk' => 'Риск',
			'control' => 'Контроль',
			'question' => 'Вопрос',
			'idea' => 'Идея',
		], Service_Nj670_Report::overlay_labels());
	}

	public function test_node_comment_and_overlay_survive_normalize()
	{
		$graph = Service_Nj670_Graph::normalize([
			'nodes' => [
				['id' => 'a', 'type' => 'user_task', 'title' => 'Проверить договор',
					'properties' => [
						'overlay' => 'risk',
						'comment' => '  Юрист смотрит только сумму, приложение не проверяют.  ',
						'overlay_by' => 'Аудитор',
					]],
				['id' => 'b', 'type' => 'user_task', 'title' => 'Оплатить',
					'properties' => ['overlay' => 'что-то-своё']],
				['id' => 'c', 'type' => 'manual_task', 'title' => 'Без оверлея'],
			],
			'edges' => [],
		], [], ['max_nodes' => 10, 'max_edges' => 10]);

		$by_id = [];
		foreach ($graph['nodes'] as $node)
		{
			$by_id[$node['id']] = $node;
		}

		$this->assertSame('risk', $by_id['a']['properties']['overlay']);
		$this->assertSame('Юрист смотрит только сумму, приложение не проверяют.', $by_id['a']['properties']['comment']);
		$this->assertSame('Аудитор', $by_id['a']['properties']['overlay_by']);

		// Неизвестный тип метки отбрасываем: холст не умеет его рисовать.
		$this->assertArrayNotHasKey('overlay', $by_id['b']['properties']);
		$this->assertArrayNotHasKey('overlay', $by_id['c']['properties']);

		$this->assertSame(['risk', 'control', 'question', 'idea'], Service_Nj670_Graph::overlays());
	}

	public function test_registry_filter_by_query_department_owner_and_state()
	{
		$rows = [
			['code' => 'a1b2c3d4', 'title' => 'Закупка ТМЦ', 'department' => 'Закупки',
				'owner_role_id' => 7, 'owner_role_name' => 'Директор', 'fact_minutes' => 120, 'audits_count' => 1],
			['code' => 'e5f6a7b8', 'title' => 'Найм в ИТ', 'department' => 'HR',
				'owner_role_id' => 9, 'owner_role_name' => 'HR-директор', 'fact_minutes' => 0, 'audits_count' => 0],
			['code' => 'c9d0e1f2', 'title' => 'Согласование договора', 'department' => 'Закупки',
				'owner_role_id' => 0, 'owner_role_name' => '', 'fact_minutes' => 60, 'audits_count' => 0],
		];

		$by_text = Service_Nj670_Process::filter($rows, ['q' => 'договор']);
		$this->assertSame(['c9d0e1f2'], array_column($by_text, 'code'));

		// Поиск смотрит и на код: администратор ищет процесс по ссылке.
		$by_code = Service_Nj670_Process::filter($rows, ['q' => 'e5f6']);
		$this->assertSame(['e5f6a7b8'], array_column($by_code, 'code'));

		$by_department = Service_Nj670_Process::filter($rows, ['department' => 'Закупки']);
		$this->assertCount(2, $by_department);

		$by_owner = Service_Nj670_Process::filter($rows, ['owner' => 7]);
		$this->assertSame(['a1b2c3d4'], array_column($by_owner, 'code'));

		$no_fact = Service_Nj670_Process::filter($rows, ['state' => 'no_fact']);
		$this->assertSame(['e5f6a7b8'], array_column($no_fact, 'code'));

		$no_owner = Service_Nj670_Process::filter($rows, ['state' => 'no_owner']);
		$this->assertSame(['c9d0e1f2'], array_column($no_owner, 'code'));

		$audited = Service_Nj670_Process::filter($rows, ['state' => 'audited']);
		$this->assertSame(['a1b2c3d4'], array_column($audited, 'code'));

		// Пустой фильтр ничего не теряет.
		$this->assertCount(3, Service_Nj670_Process::filter($rows, []));

		$facets = Service_Nj670_Process::facets($rows);
		$this->assertSame(['HR', 'Закупки'], $facets['departments']);
		$this->assertCount(2, $facets['owners']);
		$this->assertSame('Директор', $facets['owners'][7]);
		$this->assertSame('HR-директор', $facets['owners'][9]);
	}

	public function test_plantuml_export_has_lanes_steps_and_branches()
	{
		$graph = [
			'lanes' => [
				['id' => 'buyer', 'title' => 'Закупщик'],
				['id' => 'lawyer', 'title' => 'Юрист'],
			],
			'nodes' => [
				['id' => 'start', 'type' => 'start', 'title' => 'Старт', 'lane' => 'buyer'],
				['id' => 'n1', 'type' => 'user_task', 'title' => 'Создать заявку', 'lane' => 'buyer', 'duration_minutes' => 40],
				['id' => 'g1', 'type' => 'gateway_xor', 'title' => 'Сумма > 500 000', 'lane' => 'lawyer'],
				['id' => 'n2', 'type' => 'manual_task', 'title' => 'Проверить договор', 'lane' => 'lawyer', 'duration_minutes' => 90],
				['id' => 'end', 'type' => 'end', 'title' => 'Конец', 'lane' => 'lawyer'],
			],
			'edges' => [
				['from' => 'start', 'to' => 'n1'],
				['from' => 'n1', 'to' => 'g1'],
				['from' => 'g1', 'to' => 'n2', 'condition' => 'Да'],
				['from' => 'g1', 'to' => 'end', 'condition' => 'Нет'],
				['from' => 'n2', 'to' => 'end'],
			],
		];

		$puml = Service_Nj670_Graph::plantuml(['title' => 'Закупка ТМЦ'], $graph);

		$this->assertStringStartsWith('@startuml', $puml);
		$this->assertStringContainsString('@enduml', $puml);
		$this->assertStringContainsString('title Закупка ТМЦ', $puml);
		$this->assertStringContainsString('|Закупщик|', $puml);
		$this->assertStringContainsString('|Юрист|', $puml);
		$this->assertStringContainsString(':Создать заявку', $puml);
		$this->assertStringContainsString('if (Сумма > 500 000?) then (да)', $puml);
		$this->assertStringContainsString('else (Нет)', $puml);
		$this->assertStringContainsString('endif', $puml);

		// Несвязный шаг не пропадает молча.
		$graph['nodes'][] = ['id' => 'lost', 'type' => 'user_task', 'title' => 'Забытый шаг', 'lane' => 'buyer'];
		$puml = Service_Nj670_Graph::plantuml(['title' => 'Закупка'], $graph);
		$this->assertStringContainsString('Забытый шаг', $puml);
	}

	public function test_ontology_form_fields_reach_attributes()
	{
		// Форма шлёт ставку/описание верхним уровнем, без attributes[].
		$attributes = Service_Nj670_Ontology::attributes_from('role', [
			'rate_per_hour' => '1500',
			'notes'         => 'Согласует договоры',
			'code'          => 'lawyer',
		]);

		$this->assertSame(1500, $attributes['rate_per_hour']);
		$this->assertSame('Согласует договоры', $attributes['description']);

		// Вложенные attributes главнее плоских полей.
		$nested = Service_Nj670_Ontology::attributes_from('role', [
			'rate_per_hour' => '900',
			'attributes'    => ['rate_per_hour' => '1500'],
		]);

		$this->assertSame(1500, $nested['rate_per_hour']);

		// Служебные поля справочника не протекают в JSONB.
		$clean = Service_Nj670_Ontology::attributes_from('system', [
			'code' => '1c', 'name' => '1С', 'notes' => 'Учёт',
		]);

		$this->assertArrayNotHasKey('code', $clean);
		$this->assertArrayNotHasKey('name', $clean);
		$this->assertSame('Учёт', $clean['description']);
	}

	public function test_normalize_dedupes_ids_and_drops_dangling_edges()
	{
		$graph = Service_Nj670_Graph::normalize([
			'nodes' => [
				['id' => 'a', 'type' => 'user_task', 'title' => 'Первый'],
				['id' => 'a', 'type' => 'user_task', 'title' => 'Дубль'],
				['id' => 'b', 'type' => 'неизвестный', 'title' => 'Второй'],
			],
			'edges' => [
				['from' => 'a', 'to' => 'b'],
				['from' => 'a', 'to' => 'b'],
				['from' => 'b', 'to' => 'нет-такого'],
			],
		], [], ['max_nodes' => 10, 'max_edges' => 10]);

		$ids = [];
		foreach ($graph['nodes'] as $node)
		{
			$ids[] = $node['id'];
		}

		$this->assertContains('a', $ids);
		$this->assertContains('a_2', $ids);
		$this->assertContains('b', $ids);
		$this->assertSame(['a>b'], array_map(function ($edge) {
			return $edge['from'].'>'.$edge['to'];
		}, $graph['edges']));

		$types = [];
		foreach ($graph['nodes'] as $node)
		{
			$types[] = $node['type'];
		}

		// Неизвестный тип не роняет схему: он становится обычной задачей.
		$this->assertNotContains('неизвестный', $types);
	}

	public function test_mcp_tools_declare_real_handlers_and_schemas()
	{
		// Путь от файла теста, а не от каталога запуска: в php-wasm репозиторий
		// бывает смонтирован и в /repo, и в /app, и в произвольный корень.
		$decl = include dirname(__DIR__, 2).'/application/modules/nj670_processhub/config/mcp.php';

		$this->assertTrue(isset($decl['tools']) AND is_array($decl['tools']));

		$names = [];

		foreach ($decl['tools'] as $tool)
		{
			$name = (string) ($tool['name'] ?? '');

			$this->assertTrue($name !== '', 'у инструмента нет имени');
			$this->assertTrue(strpos($name, 'nj670_') === 0, 'имя без префикса модуля: '.$name);
			$this->assertTrue( ! isset($names[$name]), 'дубль инструмента: '.$name);
			$this->assertTrue((string) ($tool['description'] ?? '') !== '', 'нет описания: '.$name);
			$this->assertTrue(is_callable((string) ($tool['handler'] ?? '')), 'обработчик не вызывается: '.$name);
			$this->assertTrue((array) ($tool['inputSchema'] ?? []) !== [], 'нет схемы аргументов: '.$name);

			$names[$name] = TRUE;
		}

		// Минимальный набор, без которого агенту нечем заполнить модуль.
		foreach (['nj670_processes', 'nj670_process', 'nj670_create', 'nj670_save_graph',
			'nj670_ontology', 'nj670_ontology_upsert', 'nj670_status'] as $required)
		{
			$this->assertTrue(isset($names[$required]), 'нет инструмента '.$required);
		}
	}

	public function test_mcp_graph_argument_accepts_flat_nodes_and_json()
	{
		$flat = Nj670McpProbe::graph([
			'nodes' => [['id' => 'a', 'title' => 'Шаг']],
			'edges' => [['from' => 'a', 'to' => 'b']],
		]);

		$this->assertSame('a', $flat['nodes'][0]['id']);
		$this->assertSame('b', $flat['edges'][0]['to']);

		$json = Nj670McpProbe::graph([
			'graph' => json_encode(['nodes' => [['id' => 'x']], 'edges' => []]),
		]);

		$this->assertSame('x', $json['nodes'][0]['id']);
	}

	public function test_ontology_upsert_validates_before_touching_db()
	{
		$bad = Service_Nj670_Ontology::upsert(1, ['kind' => 'нет-такого', 'name' => 'X']);
		$this->assertFalse($bad['ok']);

		$empty = Service_Nj670_Ontology::upsert(1, ['kind' => 'role', 'name' => '']);
		$this->assertFalse($empty['ok']);
	}

	public function test_api_exposes_agent_actions()
	{
		foreach (['index', 'status', 'save', 'synth', 'audit', 'report', 'delete', 'ontology',
			'create', 'graph', 'dictionary', 'ontology_save'] as $action)
		{
			$this->assertTrue(method_exists('Controller_Nj670_Api', 'action_'.$action),
				'нет метода action_'.$action);
		}
	}
}

/** Доступ к protected-разбору аргументов MCP без БД. */
class Nj670McpProbe extends Service_Nj670_Mcp
{
	public static function graph(array $args)
	{
		return self::_graph_arg($args);
	}
}
