<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Process Mining Lite: журнал событий → схема «Факт».
 *
 * Вход — выгрузка из учётной системы: Case_ID, Activity, Timestamp, Resource.
 * Шага «магия» тут нет: группируем по кейсу, сортируем по времени, считаем
 * частоты переходов и средние паузы. Что получилось — то и есть As-Is.
 *
 * CSV разбираем сами (работает без воркера). XLSX — это ZIP: при наличии
 * ZipArchive читаем им, иначе разбираем локальные заголовки и zlib сами
 * (закрытый хост без ext/zip). Ошибку просим пересохранить в CSV только если
 * файл действительно нечитаем (пароль, повреждение).
 */
class Service_Nj670_Mining extends Service_Nj670_Repo
{
	const MAX_ROWS = 80000;

	/**
	 * @param  string $bytes содержимое файла
	 * @param  string $kind  csv|xlsx
	 * @param  array  $ontology индекс справочников
	 * @return array  ok|error + graph, metrics, stats
	 */
	public static function analyze($bytes, $kind, array $ontology = [])
	{
		$kind = $kind === 'xlsx' ? 'xlsx' : 'csv';

		if ( ! is_string($bytes) OR strlen($bytes) === 0)
		{
			return ['ok' => FALSE, 'error' => 'Файл пуст — разбирать нечего.'];
		}

		if ($kind === 'xlsx')
		{
			$rows = self::_xlsx_rows($bytes);

			if ( ! Arr::get($rows, 'ok'))
			{
				return $rows;
			}

			$rows = $rows['rows'];
			$delimiter = '';
		}
		else
		{
			$text = self::_to_utf8($bytes);
			$delimiter = self::_delimiter($text);
			$rows = self::_csv_rows($text, $delimiter);
		}

		if (count($rows) < 2)
		{
			return ['ok' => FALSE, 'error' => 'В таблице нет строк с данными: нужна шапка и хотя бы одно событие.'];
		}

		$header = array_shift($rows);
		$map = self::_map_header($header);

		// -1, а не «ложно»: колонка в нулевой позиции — законная (0 == false
		// давало «не нашёл Case_ID» на самом обычном файле).
		if ($map['case'] < 0 OR $map['activity'] < 0 OR $map['timestamp'] < 0)
		{
			return [
				'ok'    => FALSE,
				'error' => 'Не нашёл колонки Case_ID / Activity / Timestamp. Первая строка должна быть шапкой. Найдено колонок: '.count($header).'.',
			];
		}

		$events = [];
		$skipped = 0;

		foreach ($rows as $row)
		{
			if (count($events) >= self::MAX_ROWS)
			{
				break;
			}

			if ( ! is_array($row) OR count(array_filter($row, 'strlen')) === 0)
			{
				continue;
			}

			$case = trim((string) self::_cell($row, $map['case']));
			$activity = trim((string) self::_cell($row, $map['activity']));
			$stamp = self::_timestamp(self::_cell($row, $map['timestamp']));

			if ($case === '' OR $activity === '' OR $stamp === NULL)
			{
				$skipped++;
				continue;
			}

			$resource = $map['resource'] >= 0 ? trim((string) self::_cell($row, $map['resource'])) : '';
			$duration = $map['duration'] >= 0 ? (int) self::_cell($row, $map['duration']) : 0;

			$events[] = [
				'case'     => $case,
				'activity' => UTF8::substr($activity, 0, 160),
				'time'     => $stamp,
				'resource' => UTF8::substr($resource, 0, 80),
				'minutes'  => max(0, min(100000, $duration)),
			];
		}

		if (count($events) < 2)
		{
			return ['ok' => FALSE, 'error' => 'После разбора осталось меньше двух событий. Проверьте формат даты (например, 2026-09-01 10:15).'];
		}

		return self::_graph_from_events($events, $ontology, $skipped);
	}

	// ------------------------------------------------------------------ разбор

	protected static function _to_utf8($bytes)
	{
		if (substr($bytes, 0, 3) === "\xEF\xBB\xBF")
		{
			$bytes = substr($bytes, 3);
		}

		if (mb_check_encoding($bytes, 'UTF-8'))
		{
			return $bytes;
		}

		$converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $bytes);

		return $converted === FALSE ? mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251') : $converted;
	}

	protected static function _delimiter($text)
	{
		$line = strtok($text, "\r\n");
		$line = $line === FALSE ? '' : $line;

		$best = ';';
		$best_count = substr_count($line, ';');

		foreach ([',', "\t", '|'] as $candidate)
		{
			$count = substr_count($line, $candidate);

			if ($count > $best_count)
			{
				$best = $candidate;
				$best_count = $count;
			}
		}

		return $best;
	}

	protected static function _csv_rows($text, $delimiter)
	{
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, $text);
		rewind($stream);

		$rows = [];

		while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== FALSE)
		{
			if ($row === [NULL] OR (count($row) === 1 AND trim((string) $row[0]) === ''))
			{
				continue;
			}

			$rows[] = array_map(function ($cell) {
				return trim((string) $cell);
			}, $row);
		}

		fclose($stream);

		return $rows;
	}

	/**
	 * Достать файл из ZIP по локальному заголовку — без ext/zip.
	 * Сжатие deflate (method 8) разворачиваем через zlib.
	 */
	protected static function _zip_entry($bytes, $want)
	{
		$size = strlen($bytes);
		$offset = 0;

		while ($offset + 30 <= $size AND substr($bytes, $offset, 4) === "PK\x03\x04")
		{
			$h = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen',
				substr($bytes, $offset + 4, 26));

			$name = substr($bytes, $offset + 30, $h['namelen']);
			$data = substr($bytes, $offset + 30 + $h['namelen'] + $h['extralen'], $h['csize']);

			if ($name === $want)
			{
				if ((int) $h['method'] === 8)
				{
					$data = @gzinflate($data);
				}

				return $data === FALSE ? NULL : $data;
			}

			$offset += 30 + $h['namelen'] + $h['extralen'] + $h['csize'];
		}

		return NULL;
	}

	protected static function _xlsx_rows($bytes)
	{
		$sheet = NULL;
		$shared_xml = NULL;

		if (class_exists('ZipArchive'))
		{
			$tmp = tempnam(sys_get_temp_dir(), 'nj670x');
			file_put_contents($tmp, $bytes);

			$zip = new ZipArchive();
			$open = $zip->open($tmp);

			if ($open !== TRUE)
			{
				@unlink($tmp);

				return ['ok' => FALSE, 'error' => 'Не удалось открыть XLSX как архив.'];
			}

			$shared_xml = $zip->getFromName('xl/sharedStrings.xml');
			$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
			$zip->close();
			@unlink($tmp);
		}
		else
		{
			// ext/zip нет (закрытый хост): читаем локальные заголовки сами.
			// XLSX — обычный ZIP с deflate, а zlib в PHP почти всегда есть.
			$shared_xml = self::_zip_entry($bytes, 'xl/sharedStrings.xml');
			$sheet = self::_zip_entry($bytes, 'xl/worksheets/sheet1.xml');
		}

		if ($sheet === FALSE OR $sheet === NULL OR $sheet === '')
		{
			return ['ok' => FALSE, 'error' => 'В XLSX не найден первый лист. Пересохраните журнал в CSV, если файл защищён паролем.'];
		}

		$shared = [];

		if (is_string($shared_xml) AND $shared_xml !== '')
		{
			$doc = @simplexml_load_string($shared_xml);

			if ($doc)
			{
				foreach ($doc->si as $si)
				{
					$parts = '';
					foreach ($si->r as $run)
					{
						$parts .= (string) $run->t;
					}
					$shared[] = $parts !== '' ? $parts : (string) $si->t;
				}
			}
		}

		$doc = @simplexml_load_string($sheet);

		if ( ! $doc)
		{
			return ['ok' => FALSE, 'error' => 'Первый лист XLSX повреждён.'];
		}

		$rows = [];

		foreach ($doc->sheetData->row as $row)
		{
			$cells = [];

			foreach ($row->c as $c)
			{
				$ref = (string) $c['r'];
				$col = 0;
				$letters = preg_replace('~[^A-Z]~', '', strtoupper($ref));

				for ($i = 0; $i < strlen($letters); $i++)
				{
					$col = $col * 26 + (ord($letters[$i]) - 64);
				}

				$col = max(1, $col) - 1;
				$type = (string) $c['t'];
				$value = (string) $c->v;

				if ($type === 's')
				{
					$value = (string) Arr::get($shared, (int) $value, '');
				}
				elseif ($type === 'inlineStr')
				{
					$value = (string) $c->is->t;
				}

				$cells[$col] = $value;
			}

			if ( ! $cells)
			{
				continue;
			}

			$width = max(array_keys($cells)) + 1;
			$line = [];

			for ($i = 0; $i < $width; $i++)
			{
				$line[] = isset($cells[$i]) ? $cells[$i] : '';
			}

			$rows[] = $line;
		}

		return ['ok' => TRUE, 'rows' => $rows];
	}

	protected static function _map_header(array $header)
	{
		$aliases = [
			'case' => ['case_id', 'caseid', 'case', 'кейс', 'id_кейса', 'номер_заявки', 'заявка', 'case_no', 'process_id'],
			'activity' => ['activity', 'action', 'step', 'event', 'активность', 'операция', 'шаг', 'действие', 'этап'],
			'timestamp' => ['timestamp', 'time', 'datetime', 'date', 'дата', 'время', 'дата_время', 'дата_и_время', 'start_time'],
			'resource' => ['resource', 'user', 'role', 'actor', 'ресурс', 'исполнитель', 'роль', 'сотрудник', 'пользователь'],
			'duration' => ['duration', 'duration_minutes', 'minutes', 'длительность', 'минуты', 'мин'],
		];

		$map = ['case' => -1, 'activity' => -1, 'timestamp' => -1, 'resource' => -1, 'duration' => -1];

		foreach ($header as $index => $name)
		{
			$key = preg_replace('~[^a-zа-яё0-9]+~u', '_', mb_strtolower(trim((string) $name)));
			$key = trim((string) $key, '_');

			foreach ($aliases as $field => $list)
			{
				if ($map[$field] !== -1)
				{
					continue;
				}

				foreach ($list as $alias)
				{
					if ($key === $alias OR strpos($key, $alias) !== FALSE)
					{
						$map[$field] = (int) $index;
						break 2;
					}
				}
			}
		}

		return $map;
	}

	protected static function _cell(array $row, $index)
	{
		return $index >= 0 ? (string) Arr::get($row, (int) $index, '') : '';
	}

	protected static function _timestamp($value)
	{
		$value = trim((string) $value);

		if ($value === '')
		{
			return NULL;
		}

		if (is_numeric($value) AND (float) $value > 25569)
		{
			// Excel serial: дни от 1899-12-30.
			return (int) round(((float) $value - 25569) * 86400);
		}

		$value = str_replace(['T', 'Z'], [' ', ''], $value);
		$value = preg_replace('~\.\d+$~', '', $value);

		foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'd/m/Y H:i', 'd/m/Y', 'm/d/Y H:i', 'm/d/Y'] as $format)
		{
			$dt = DateTime::createFromFormat($format, $value);

			if ($dt !== FALSE)
			{
				$errors = DateTime::getLastErrors();
				$clean = TRUE;

				if (is_array($errors))
				{
					$clean = ((int) $errors['warning_count'] === 0 AND (int) $errors['error_count'] === 0);
				}

				if ($clean)
				{
					return $dt->getTimestamp();
				}
			}
		}

		$ts = strtotime($value);

		return $ts === FALSE ? NULL : $ts;
	}

	// ------------------------------------------------------------------ схема

	protected static function _graph_from_events(array $events, array $ontology, $skipped = 0)
	{
		$cases = [];

		foreach ($events as $event)
		{
			$cases[$event['case']][] = $event;
		}

		$activities = [];
		$transitions = [];
		$role_by_activity = [];
		$gaps = [];
		$elapsed = [];

		foreach ($cases as $case_id => $list)
		{
			usort($list, function ($a, $b) {
				return $a['time'] <=> $b['time'];
			});

			$first = $list[0]['time'];
			$last = $list[count($list) - 1]['time'];
			// В событиях время — секунды (таймстемпы), а метрики схемы живут
			// в минутах: без деления срок «14 дней» стал бы «336 часов».
			$elapsed[] = max(0, (int) round(($last - $first) / 60));

			$count = count($list);

			for ($i = 0; $i < $count; $i++)
			{
				$key = self::_slug($list[$i]['activity']);
				$activities[$key]['title'] = $list[$i]['activity'];
				$activities[$key]['count'] = (int) Arr::path($activities, $key.'.count', 0) + 1;
				$activities[$key]['sum_minutes'] = (int) Arr::path($activities, $key.'.sum_minutes', 0) + $list[$i]['minutes'];

				if ( ! empty($list[$i]['resource']))
				{
					$role_by_activity[$key][$list[$i]['resource']] = (int) Arr::get((array) Arr::get($role_by_activity, $key, []), $list[$i]['resource'], 0) + 1;
				}

				if ($i + 1 < $count)
				{
					$next = $list[$i + 1];
					$gap = max(0, (int) round(($next['time'] - $list[$i]['time']) / 60));
					$pair = $key.'>'.self::_slug($next['activity']);
					$transitions[$pair]['count'] = (int) Arr::path($transitions, $pair.'.count', 0) + 1;
					$transitions[$pair]['sum_minutes'] = (int) Arr::path($transitions, $pair.'.sum_minutes', 0) + $gap;

					// Пауза относится к текущей активности целиком, а не к каждому
					// исходящему переходу: иначе у шлюза ожидание удвоится.
					$gaps[$key]['sum'] = (int) Arr::path($gaps, $key.'.sum', 0) + $gap;
					$gaps[$key]['count'] = (int) Arr::path($gaps, $key.'.count', 0) + 1;
				}
			}
		}

		$nodes = [
			['id' => 'start', 'step_id' => 'start', 'type' => 'start', 'title' => 'Начало', 'lane' => ''],
		];

		$lanes = [];
		$lane_by_resource = [];

		foreach ($activities as $slug => $info)
		{
			$resource = '';
			$best = 0;

			foreach ((array) Arr::get($role_by_activity, $slug, []) as $name => $count)
			{
				if ($count > $best)
				{
					$best = $count;
					$resource = (string) $name;
				}
			}

			$role = self::_role_for($resource, $ontology);

			if ($role['code'] === '')
			{
				$role = ['code' => 'res_'.($resource !== '' ? self::_slug($resource) : 'general'), 'name' => $resource !== '' ? $resource : 'Без роли'];
			}

			if ( ! isset($lane_by_resource[$role['code']]))
			{
				$lane_by_resource[$role['code']] = TRUE;
				$lanes[] = [
					'id'        => 'lane_'.$role['code'],
					'title'     => $role['name'],
					'role_code' => $role['code'],
				];
			}

			$avg_event = (int) $info['count'] > 0 ? (int) $info['sum_minutes'] / (int) $info['count'] : 0;
			$avg_wait = (int) Arr::path($gaps, $slug.'.count', 0) > 0
				? (int) round((int) $gaps[$slug]['sum'] / (int) $gaps[$slug]['count']) : 0;

			$nodes[] = [
				'id'              => 'n_'.$slug,
				'step_id'         => $slug,
				'type'            => 'user_task',
				'title'           => (string) $info['title'],
				'lane'            => 'lane_'.$role['code'],
				'role_code'       => $role['code'],
				'duration_minutes'=> (int) round($avg_event),
				'wait_time_minutes' => $avg_wait,
				'properties'      => [
					'frequency' => (int) $info['count'],
					'resource'  => $resource,
				],
			];
		}

		$edges = [];

		foreach ($transitions as $pair => $info)
		{
			list($from, $to) = explode('>', $pair, 2);
			$avg_gap = (int) round($info['sum_minutes'] / max(1, $info['count']));

			$edges[] = [
				'from'     => 'n_'.$from,
				'to'       => 'n_'.$to,
				'type'     => 'sequence',
				'weight'   => (int) $info['count'],
				'avg_minutes' => $avg_gap,
				'properties' => ['count' => (int) $info['count']],
			];
		}

		// Один вход и один выход: журнал часто имеет несколько первых/последних
		// активностей, но на схеме начало должно быть точкой.
		$incoming = [];
		$outgoing = [];

		foreach ($edges as $edge)
		{
			$incoming[$edge['to']] = TRUE;
			$outgoing[$edge['from']] = TRUE;
		}

		foreach ($nodes as $node)
		{
			if ($node['id'] === 'start' OR $node['type'] === 'end')
			{
				continue;
			}

			if ( ! isset($incoming[$node['id']]))
			{
				$edges[] = ['from' => 'start', 'to' => $node['id'], 'type' => 'sequence', 'weight' => 1];
			}
		}

		$nodes[] = ['id' => 'end', 'step_id' => 'end', 'type' => 'end', 'title' => 'Конец', 'lane' => ''];
		$sink = [];

		foreach ($nodes as $node)
		{
			if ($node['id'] === 'end')
			{
				continue;
			}

			if ( ! isset($outgoing[$node['id']]) AND $node['id'] !== 'start')
			{
				$edges[] = ['from' => $node['id'], 'to' => 'end', 'type' => 'sequence', 'weight' => 1];
				$sink[] = $node['id'];
			}
		}

		rsort($elapsed);
		$median = $elapsed ? (int) $elapsed[(int) floor(count($elapsed) / 2)] : 0;

		$graph = [
			'lanes' => $lanes,
			'nodes' => $nodes,
			'edges' => $edges,
			'meta'  => [
				'title'   => 'Факт по журналу событий',
				'source'  => 'process-mining',
				'mode'    => 'fact',
				'elapsed_minutes' => $median,
				'note'    => 'Кейсов: '.count($cases).', событий: '.count($events).($skipped > 0 ? ', пропущено строк: '.$skipped : ''),
			],
		];

		$normalized = Service_Nj670_Graph::normalize($graph, $ontology, ['reflow' => TRUE, 'mode' => 'fact']);

		return [
			'ok'      => TRUE,
			'graph'   => $normalized,
			'metrics' => Service_Nj670_Graph::metrics($normalized, $ontology),
			'stats'   => [
				'cases'      => count($cases),
				'events'     => count($events),
				'activities' => count($activities),
				'transitions'=> count($transitions),
				'skipped'    => (int) $skipped,
				'median_minutes' => $median,
				'rows'       => count($events),
			],
		];
	}

	protected static function _slug($text)
	{
		// Кириллица в коде недопустима (Graph::_id_of вырезает её), поэтому
		// активности транслитерируются: «Проверка СБ» → proverka-sb. Хвост
		// md5 — только для письменностей, которые транслит не знает; он
		// детерминированный, иначе одна и та же активность получала бы новый
		// код при каждом разборе и переходы бы «рассыпались».
		$text = self::slug_code($text, 'step-'.substr(md5((string) $text), 0, 6));
		$text = trim(str_replace('_', '-', $text), '-');

		return UTF8::substr($text !== '' ? $text : 'step', 0, 40);
	}

	protected static function _role_for($name, array $ontology)
	{
		$name = trim((string) $name);

		if ($name === '')
		{
			return ['code' => '', 'name' => ''];
		}

		$by_name = (array) Arr::path($ontology, 'by_name.role', []);
		$key = mb_strtolower($name);

		if (isset($by_name[$key]))
		{
			$code = (string) $by_name[$key];
			$row = (array) Arr::path($ontology, 'by_code.role.'.$code, []);

			return ['code' => $code, 'name' => (string) Arr::get($row, 'name', $name)];
		}

		return ['code' => '', 'name' => $name];
	}

	/**
	 * Канва «Факт» из расчета: даёт контроллеру возможность показать итог без
	 * сохранения версии (предпросмотр перед импортом).
	 */
	public static function preview($bytes, $kind, $workspace_id)
	{
		$ontology = Service_Nj670_Ontology::index($workspace_id);

		return self::analyze($bytes, $kind, $ontology);
	}
}
