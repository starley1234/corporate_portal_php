<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Регламент процесса: документ, файлы DOCX/PDF, скачивание.
 *
 * PHP собирает структуру документа (титул, шаги, RACI, риски) и отдаёт её
 * воркеру; воркер рисует схему и верстает файлы. Так шаблон один: веб-превью
 * и Word/PDF считаются из одного и того же массива, а не расходятся.
 */
class Service_Nj670_Report extends Service_Nj670_Repo
{
	public static function storage_dir()
	{
		$dir = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670'.DIRECTORY_SEPARATOR.'reports';

		if ( ! is_dir($dir))
		{
			@mkdir($dir, 0775, TRUE);
		}

		return $dir;
	}

	/**
	 * Структура документа для воркера и предпросмотра.
	 */
	public static function document(array $process, array $version, $audit = NULL)
	{
		$workspace = Service_Nj670_Process::workspace((int) $process['workspace_id']);
		$index = Service_Nj670_Ontology::index((int) $process['workspace_id']);
		$graph = Service_Nj670_Process::graph_of($version);
		$metrics = Service_Nj670_Graph::metrics($graph, $index);
		$owner_code = (string) Arr::get($process, 'owner_role_code', '');
		$raci = Service_Nj670_Graph::raci($graph, $owner_code, $index);

		$plan = Service_Nj670_Process::current_version($process, 'plan');
		$fact = Service_Nj670_Process::current_version($process, 'fact');
		$audit = $audit ? $audit : Service_Nj670_Process::latest_audit((int) $process['id'], TRUE);

		$steps = [];

		foreach ($graph['nodes'] as $node)
		{
			if (in_array($node['type'], ['start', 'end', 'gateway_xor', 'gateway_and'], TRUE))
			{
				continue;
			}

			$steps[] = [
				'n'      => count($steps) + 1,
				'step_id'=> $node['step_id'],
				'title'  => $node['title'],
				'type'   => Service_Nj670_Graph::node_type_label($node['type']),
				'role'   => self::_name($index, 'role', $node['role_code'],
					(string) Arr::get($node['properties'], 'role_label', '')),
				'input'  => self::_name($index, 'artifact', $node['input_code']),
				'system' => self::_name($index, 'system', $node['system_code'],
					(string) Arr::get($node['properties'], 'system_label', '')),
				'output' => self::_name($index, 'artifact', $node['output_code']),
				'sla'    => self::_sla($node),
				'duration' => (int) $node['duration_minutes'],
				'wait'   => (int) $node['wait_time_minutes'],
				// Оверлей и комментарий ревьюера: попадают в регламент, чтобы
				// замечание аудита не жило только в интерфейсе.
				'overlay' => (string) Arr::get($node['properties'], 'overlay', ''),
				'comment' => trim((string) Arr::get($node['properties'], 'comment', '')),
			];
		}

		$rules = [];

		foreach ((array) Arr::get($index['list'], 'rule', []) as $rule)
		{
			$rules[] = [
				'code' => (string) $rule['code'],
				'name' => (string) $rule['name'],
				'note' => (string) Arr::get($rule['attributes'], 'description', ''),
			];
		}

		$audit_data = NULL;

		if ($audit)
		{
			$audit_data = [
				'id'        => (int) $audit['id'],
				'status'    => (string) $audit['status'],
				'summary'   => (string) $audit['summary'],
				'risk_level'=> (string) $audit['risk_level'],
				'impact_rub'=> (float) $audit['total_impact_rub'],
				'mode'      => (string) $audit['mode'],
				'model'     => (string) $audit['model'],
				'findings'  => self::json_decode(Arr::get($audit, 'findings', [])),
			];
		}

		$org = trim((string) Arr::get($workspace, 'company_name', ''));

		if ($org === '')
		{
			$org = (string) Arr::get(self::branch('report'), 'org_fallback', 'ООО «Демо-Компания»');
		}

		$city = (string) Arr::get(self::branch('report'), 'city', 'Москва');
		$approve = (string) Arr::get(self::branch('report'), 'approve_role', 'Владелец процесса');

		return [
			'org_name'     => $org,
			'workspace'    => (string) Arr::get($workspace, 'title', ''),
			'title'        => (string) $process['title'],
			'code'         => (string) $process['code'],
			'process_id'   => (int) $process['id'],
			'version_id'   => (int) $version['id'],
			'version_tag'  => (string) $version['version_tag'],
			'kind'         => (string) $version['kind'],
			'kind_label'   => (string) $version['kind'] === 'fact' ? 'Факт (As-Is)' : 'План (To-Be)',
			'status'       => (string) $version['status'],
			'status_label' => (string) Arr::get(self::statuses(), (string) $version['status'], (string) $version['status']),
			'owner'        => (string) Arr::get($process, 'owner_role_name', $owner_code),
			'department'   => (string) $process['department'],
			'description'  => (string) $process['description'],
			'city'         => $city,
			'year'         => date('Y'),
			'date_text'    => date('d.m.Y'),
			'approve_role' => $approve,
			'gost'         => (string) Arr::get(self::branch('report'), 'format_gost', 'ГОСТ Р 7.0.97-2016'),
			'graph'        => $graph,
			'metrics'      => $metrics,
			'plan_metrics' => $plan ? Service_Nj670_Graph::metrics(Service_Nj670_Process::graph_of($plan), $index) : NULL,
			'fact_metrics' => $fact ? Service_Nj670_Graph::metrics(Service_Nj670_Process::graph_of($fact), $index) : NULL,
			'steps'        => $steps,
			'raci'         => $raci,
			'rules'        => $rules,
			'audit'        => $audit_data,
			'generated_at' => date('c'),
		];
	}

	/**
	 * Подписи аудит-оверлеев. Дублируются в регламенте и на странице аудита.
	 */
	public static function overlay_labels()
	{
		return [
			'risk'     => 'Риск',
			'control'  => 'Контроль',
			'question' => 'Вопрос',
			'idea'     => 'Идея',
		];
	}

	/**
	 * Строки раздела «Комментарии ревьюера»: только шаги, где есть что
	 * показать. Формат один для DOCX и PDF.
	 */
	public static function comments_rows(array $document)
	{
		$labels = self::overlay_labels();
		$rows = [];

		foreach ((array) Arr::get($document, 'steps', []) as $step)
		{
			$comment = trim((string) Arr::get($step, 'comment', ''));
			$overlay = (string) Arr::get($step, 'overlay', '');

			if ($comment === '' AND $overlay === '')
			{
				continue;
			}

			$rows[] = [
				'n'       => (string) Arr::get($step, 'n', ''),
				'title'   => (string) Arr::get($step, 'title', ''),
				'overlay' => (string) Arr::get($labels, $overlay, ''),
				'comment' => $comment,
			];
		}

		return $rows;
	}

	protected static function _name(array $index, $type, $code, $fallback = '')
	{
		$code = trim((string) $code);

		if ($code === '')
		{
			return $fallback !== '' ? $fallback : '—';
		}

		$by_code = (array) Arr::get($index['by_code'], $type, []);

		if (isset($by_code[$code]['name']))
		{
			return (string) $by_code[$code]['name'];
		}

		return $fallback !== '' ? $fallback : $code;
	}

	protected static function _sla(array $node)
	{
		$parts = [];

		if ((int) $node['duration_minutes'] > 0)
		{
			$parts[] = self::minutes_text((int) $node['duration_minutes']).' работы';
		}

		if ((int) $node['wait_time_minutes'] > 0)
		{
			$parts[] = self::minutes_text((int) $node['wait_time_minutes']).' ожидания';
		}

		return $parts ? implode(' + ', $parts) : '—';
	}

	public static function worker_context($report_id)
	{
		$report = Service_Nj670_Process::report($report_id);

		if ( ! $report)
		{
			throw new DomainException('Отчёт не найден.');
		}

		$version = Service_Nj670_Process::version((int) $report['version_id']);
		$process = Service_Nj670_Process::process_by_id((int) $report['process_id']);

		if ( ! $version OR ! $process)
		{
			throw new DomainException('Версия или процесс отчёта не найдены.');
		}

		$audit = (int) $report['audit_id'] > 0
			? Service_Nj670_Process::audit((int) $report['audit_id']) : NULL;

		return [
			'ok'       => TRUE,
			'report'   => ['id' => (int) $report['id']],
			'document' => self::document($process, $version, $audit),
			'model'    => Service_Nj670_Process::model(),
		];
	}

	/**
	 * Приём файлов от воркера: проверка сигнатур, запись, замена старых.
	 */
	public static function store_files($report_id, $docx, $pdf = '')
	{
		$report = Service_Nj670_Process::report($report_id);

		if ( ! $report)
		{
			return ['ok' => FALSE, 'error' => 'Отчёт не найден.'];
		}

		$max = (int) self::limits('max_report_bytes', 20971520);

		if ($docx === '' OR strlen($docx) > $max)
		{
			return ['ok' => FALSE, 'error' => 'DOCX пустой или больше лимита.'];
		}

		if (substr($docx, 0, 2) !== 'PK')
		{
			return ['ok' => FALSE, 'error' => 'DOCX не похож на ZIP-контейнер Word.'];
		}

		if ($pdf !== '' AND (substr($pdf, 0, 4) !== '%PDF' OR strlen($pdf) > $max))
		{
			return ['ok' => FALSE, 'error' => 'PDF не похож на PDF или больше лимита.'];
		}

		$dir = self::storage_dir().DIRECTORY_SEPARATOR.date('Ym');

		if ( ! is_dir($dir))
		{
			@mkdir($dir, 0775, TRUE);
		}

		$prefix = (int) $report_id.'-'.bin2hex(random_bytes(5));
		$docx_rel = date('Ym').'/'.$prefix.'.docx';
		$docx_full = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670'.DIRECTORY_SEPARATOR
			.'reports'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $docx_rel);

		if (file_put_contents($docx_full, $docx) === FALSE)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось записать DOCX.'];
		}

		@chmod($docx_full, 0664);

		$pdf_rel = '';
		$pdf_size = 0;

		if ($pdf !== '')
		{
			$pdf_rel = date('Ym').'/'.$prefix.'.pdf';
			$pdf_full = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670'.DIRECTORY_SEPARATOR
				.'reports'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $pdf_rel);

			if (file_put_contents($pdf_full, $pdf) !== FALSE)
			{
				@chmod($pdf_full, 0664);
				$pdf_size = strlen($pdf);
			}
			else
			{
				$pdf_rel = '';
			}
		}

		$old = self::_one('SELECT docx_path, pdf_path FROM nj_670_reports WHERE id = '.(int) $report_id);

		foreach (['docx' => 'docx_path', 'pdf' => 'pdf_path'] as $kind => $column)
		{
			$rel = (string) Arr::get((array) $old, $column, '');

			if ($rel !== '')
			{
				$full = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670'.DIRECTORY_SEPARATOR
					.'reports'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);

				if (is_file($full))
				{
					@unlink($full);
				}
			}
		}

		self::_update('nj_670_reports', [
			'docx_path' => $docx_rel,
			'docx_size' => strlen($docx),
			'pdf_path'  => $pdf_rel,
			'pdf_size'  => $pdf_size,
		], (int) $report_id);

		return ['ok' => TRUE, 'docx' => $docx_rel, 'pdf' => $pdf_rel];
	}

	public static function file($report_id, $kind)
	{
		$report = Service_Nj670_Process::report($report_id);

		if ( ! $report)
		{
			return NULL;
		}

		$kind = $kind === 'pdf' ? 'pdf' : 'docx';
		$column = $kind.'_path';
		$rel = (string) Arr::get($report, $column, '');

		if ($rel === '' OR strpos($rel, '..') !== FALSE)
		{
			return NULL;
		}

		$full = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670'.DIRECTORY_SEPARATOR
			.'reports'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);

		if ( ! is_file($full))
		{
			return NULL;
		}

		$process = Service_Nj670_Process::process_by_id((int) $report['process_id']);
		$slug = self::_file_slug($process, $report, $kind);

		return [
			'path' => $full,
			'name' => $slug,
			'size' => filesize($full),
			'kind' => $kind,
		];
	}

	protected static function _file_slug($process, $report, $kind)
	{
		$title = $process ? (string) $process['title'] : 'process';
		$title = str_replace(['/', '\\', '"', "\r", "\n"], ' ', $title);
		$title = trim(preg_replace('~\s+~u', ' ', $title));
		$title = UTF8::substr($title, 0, 80);

		return $title.' — регламент '.date('Y-m-d', strtotime((string) $report['created_at'])).'.'.$kind;
	}

	public static function delete($report_id)
	{
		$report = Service_Nj670_Process::report($report_id);

		if ( ! $report)
		{
			return ['ok' => FALSE, 'error' => 'Отчёт не найден.'];
		}

		foreach (['docx_path', 'pdf_path'] as $column)
		{
			$rel = (string) Arr::get($report, $column, '');

			if ($rel === '')
			{
				continue;
			}

			$full = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670'.DIRECTORY_SEPARATOR
				.'reports'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);

			if (is_file($full))
			{
				@unlink($full);
			}
		}

		self::_exec('DELETE FROM nj_670_reports WHERE id = '.(int) $report_id);

		return ['ok' => TRUE];
	}


	// ------------------------------------------------------- сборка без воркера

	/**
	 * Аварийная сборка на сайте: DOCX — чистым OOXML, PDF — через Txt2pdf
	 * модуля nj634. Нужна, когда очередь воркера недоступна, а регламент
	 * человеку нужен сейчас. Полная версия с векторной схемой — у воркера.
	 */
	public static function build_local($report_id)
	{
		$context = self::worker_context($report_id);

		if ( ! Arr::get($context, 'ok'))
		{
			return $context;
		}

		$document = $context['document'];
		$docx = self::build_docx($document);

		if ($docx === '')
		{
			return ['ok' => FALSE, 'error' => 'Не удалось собрать DOCX: проверьте расширение zip.'];
		}

		$pdf = self::build_pdf($document);
		$stored = self::store_files($report_id, $docx, $pdf);

		if ( ! Arr::get($stored, 'ok'))
		{
			return $stored;
		}

		self::_exec('UPDATE nj_670_reports SET status = \'done\', model = \'php-fallback\', error = \'\',
				updated_at = now() WHERE id = '.(int) $report_id);

		$report = Service_Nj670_Process::report($report_id);
		$process = Service_Nj670_Process::process_by_id((int) $report['process_id']);

		if ($process)
		{
			self::_event((int) $process['id'], 0, 'report.done',
				'Регламент собран на сайте (резервный режим): DOCX'.($pdf !== '' ? ' и PDF' : ''));

			self::_notify('nj670.report.done', [
				'user_id' => (int) $process['workspace_user_id'],
				'title'   => (string) $process['title'],
				'href'    => '/process/'.$process['code'].'/report',
			], ['self' => TRUE]);
		}

		return ['ok' => TRUE, 'report_id' => (int) $report_id, 'pdf' => $pdf !== '', 'engine' => 'php'];
	}

	/**
	 * Создать строку отчёта и сразу собрать её на сайте.
	 */
	public static function build_local_from($report_id, $process_id, $version_id, $audit_id = 0)
	{
		$report_id = (int) $report_id;

		if ($report_id <= 0)
		{
			$report_id = self::_insert('nj_670_reports', [
				'process_id' => (int) $process_id,
				'version_id' => (int) $version_id,
				'audit_id'   => (int) $audit_id > 0 ? (int) $audit_id : NULL,
				'status'     => 'queued',
				'created_by' => 0,
			]);
		}

		if ($report_id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось создать отчёт.'];
		}

		$done = self::build_local($report_id);

		if ( ! Arr::get($done, 'ok'))
		{
			self::_update('nj_670_reports', [
				'status' => 'error',
				'error'  => UTF8::substr((string) Arr::get($done, 'error', 'Ошибка сборки.'), 0, 1000),
			], $report_id);
		}

		return $done;
	}

	protected static function _x($text)
	{
		return htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	protected static function _docx_p($text, array $opts = [])
	{
		$align = (string) Arr::get($opts, 'align', '');
		$bold = ! empty($opts['bold']);
		$size = (int) Arr::get($opts, 'size', 24);
		$space_after = (int) Arr::get($opts, 'after', 120);
		$line = (int) Arr::get($opts, 'line', 276);
		$page_break = ! empty($opts['page_break']);

		$ppr = '<w:pPr>';

		if ($align !== '')
		{
			$ppr .= '<w:jc w:val="'.$align.'"/>';
		}

		$ppr .= '<w:spacing w:after="'.$space_after.'" w:line="'.$line.'" w:lineRule="auto"/>';
		$ppr .= $page_break ? '<w:pageBreakBefore/>' : '';
		$ppr .= '</w:pPr>';

		$runs = '';

		foreach (preg_split('~\n~', (string) $text) as $i => $chunk)
		{
			if ($i > 0)
			{
				$runs .= '<w:r><w:br/></w:r>';
			}

			$runs .= '<w:r><w:rPr>'
				.($bold ? '<w:b/>' : '')
				.'<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/>'
				.'<w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/></w:rPr>'
				.'<w:t xml:space="preserve">'.self::_x($chunk).'</w:t></w:r>';
		}

		return '<w:p>'.$ppr.$runs.'</w:p>';
	}

	protected static function _docx_cell($text, $width, array $opts = [])
	{
		$bold = ! empty($opts['bold']);
		$shade = (string) Arr::get($opts, 'shade', '');
		$size = (int) Arr::get($opts, 'size', 16);
		$align = (string) Arr::get($opts, 'align', 'left');

		$tcpr = '<w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/>'
			.($shade !== '' ? '<w:shd w:val="clear" w:color="auto" w:fill="'.$shade.'"/>' : '')
			.'<w:vAlign w:val="center"/></w:tcPr>';

		$paragraphs = '';

		foreach (preg_split('~\n~', (string) $text) as $line)
		{
			$paragraphs .= '<w:p><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/>'
				.'<w:jc w:val="'.$align.'"/></w:pPr>'
				.'<w:r><w:rPr>'.($bold ? '<w:b/>' : '')
				.'<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/>'
				.'<w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/></w:rPr>'
				.'<w:t xml:space="preserve">'.self::_x($line).'</w:t></w:r></w:p>';
		}

		return '<w:tc>'.$tcpr.$paragraphs.'</w:tc>';
	}

	protected static function _docx_table(array $rows, array $widths, $header = TRUE)
	{
		$total = array_sum($widths);
		$grid = '';

		foreach ($widths as $w)
		{
			$grid .= '<w:gridCol w:w="'.$w.'"/>';
		}

		$body = '';

		foreach ($rows as $r => $row)
		{
			$cells = '';

			foreach ($widths as $i => $w)
			{
				$cells .= self::_docx_cell(
					(string) Arr::get($row, $i, ''),
					$w,
					($header AND $r === 0)
						? ['bold' => TRUE, 'shade' => 'EDEDED', 'size' => 16, 'align' => 'center']
						: ['size' => 16]
				);
			}

			$body .= '<w:tr>'.$cells.'</w:tr>';
		}

		return '<w:tbl><w:tblPr><w:tblW w:w="'.$total.'" w:type="dxa"/>'
			.'<w:tblBorders>'
			.'<w:top w:val="single" w:sz="6" w:color="666666"/>'
			.'<w:left w:val="single" w:sz="6" w:color="666666"/>'
			.'<w:bottom w:val="single" w:sz="6" w:color="666666"/>'
			.'<w:right w:val="single" w:sz="6" w:color="666666"/>'
			.'<w:insideH w:val="single" w:sz="4" w:color="999999"/>'
			.'<w:insideV w:val="single" w:sz="4" w:color="999999"/>'
			.'</w:tblBorders></w:tblPr><w:tblGrid>'.$grid.'</w:tblGrid>'.$body.'</w:tbl>'
			.'<w:p><w:pPr><w:spacing w:after="0"/></w:pPr></w:p>';
	}

	/**
	 * Минимальный, но валидный DOCX: титул, таблица шагов, RACI, риски.
	 */
	public static function build_docx(array $document)
	{
		$title = (string) Arr::get($document, 'title', 'Регламент процесса');
		$org = (string) Arr::get($document, 'org_name', '');
		$version = (string) Arr::get($document, 'version_tag', '');
		$kind = (string) Arr::get($document, 'kind_label', '');
		$date = (string) Arr::get($document, 'date_text', date('d.m.Y'));
		$city = (string) Arr::get($document, 'city', '');

		$body = '';
		$year = (string) Arr::get($document, 'year', date('Y'));

		// Титульный лист: блок утверждения справа, затем шапка документа.
		$body .= self::_docx_p('УТВЕРЖДАЮ', ['align' => 'right', 'bold' => TRUE, 'size' => 22, 'after' => 40]);
		$body .= self::_docx_p((string) Arr::get($document, 'approve_role', 'Владелец процесса'), ['align' => 'right', 'size' => 22, 'after' => 40]);
		$body .= self::_docx_p('____________ / '.(string) Arr::get($document, 'owner', '________________'), ['align' => 'right', 'size' => 22, 'after' => 40]);
		$body .= self::_docx_p('«___» ____________ '.$year.' г.', ['align' => 'right', 'size' => 22, 'after' => 600]);
		$body .= self::_docx_p($org, ['align' => 'center', 'bold' => TRUE, 'size' => 28, 'after' => 600]);
		$body .= self::_docx_p('РЕГЛАМЕНТ БИЗНЕС-ПРОЦЕССА', ['align' => 'center', 'bold' => TRUE, 'size' => 30, 'after' => 160]);
		$body .= self::_docx_p('«'.$title.'»', ['align' => 'center', 'bold' => TRUE, 'size' => 32, 'after' => 480]);
		$body .= self::_docx_p('Версия: '.$version.' · '.$kind, ['align' => 'center', 'size' => 24, 'after' => 120]);
		$body .= self::_docx_p('Статус: '.(string) Arr::get($document, 'status_label', ''), ['align' => 'center', 'size' => 24, 'after' => 120]);
		$body .= self::_docx_p('Код процесса: '.(string) Arr::get($document, 'code', ''), ['align' => 'center', 'size' => 22, 'after' => 120]);
		$body .= self::_docx_p('Владелец: '.(string) Arr::get($document, 'owner', '—'), ['align' => 'center', 'size' => 22, 'after' => 900]);
		$body .= self::_docx_p($city.', '.(string) Arr::get($document, 'year', date('Y')), ['align' => 'center', 'size' => 24, 'after' => 0]);

		$body .= self::_docx_p('1. Общие сведения', ['bold' => TRUE, 'size' => 28, 'after' => 120, 'page_break' => TRUE]);
		$body .= self::_docx_p('Организация: '.$org, ['size' => 22, 'after' => 60]);
		$body .= self::_docx_p('Подразделение: '.(string) Arr::get($document, 'department', '—'), ['size' => 22, 'after' => 60]);
		$body .= self::_docx_p('Дата формирования: '.$date, ['size' => 22, 'after' => 60]);
		$body .= self::_docx_p('Формат: '.(string) Arr::get($document, 'gost', ''), ['size' => 22, 'after' => 120]);

		if (trim((string) Arr::get($document, 'description', '')) !== '')
		{
			$body .= self::_docx_p('Назначение процесса: '.(string) $document['description'], ['size' => 22, 'after' => 120]);
		}

		$metrics = (array) Arr::get($document, 'metrics', []);
		$body .= self::_docx_p('Расчётные показатели: срок — '.self::minutes_text((int) Arr::get($metrics, 'elapsed_minutes', 0))
			.', работа — '.self::minutes_text((int) Arr::get($metrics, 'work_minutes', 0))
			.', ожидание — '.self::minutes_text((int) Arr::get($metrics, 'wait_minutes', 0))
			.', стоимость — '.self::money((float) Arr::get($metrics, 'cost_rub', 0)).'.', ['size' => 22, 'after' => 120]);

		$body .= self::_docx_p('2. Операционная таблица шагов', ['bold' => TRUE, 'size' => 28, 'after' => 120]);

		$rows = [['№', 'Роль', 'Вход', 'Действие', 'Система', 'Выход', 'SLA']];

		foreach ((array) Arr::get($document, 'steps', []) as $step)
		{
			$rows[] = [
				(string) $step['n'],
				(string) $step['role'],
				(string) $step['input'],
				(string) $step['title'],
				(string) $step['system'],
				(string) $step['output'],
				(string) $step['sla'],
			];
		}

		$body .= self::_docx_table($rows, [420, 1350, 1350, 2500, 1350, 1350, 1300]);

		$comments = self::comments_rows($document);

		if ($comments)
		{
			$body .= self::_docx_p('2.1. Комментарии ревьюера', ['bold' => TRUE, 'size' => 26, 'after' => 100]);
			$comment_rows = [['№', 'Шаг', 'Метка', 'Комментарий']];

			foreach ($comments as $comment)
			{
				$comment_rows[] = [$comment['n'], $comment['title'], $comment['overlay'], $comment['comment']];
			}

			$body .= self::_docx_table($comment_rows, [420, 2600, 1400, 5180]);
		}

		// Маршрут по дорожкам: заменяет графическую схему в резервной сборке
		// на сайте (воркер прикладывает PNG отдельным блоком).
		$graph = (array) Arr::get($document, 'graph', []);
		$lane_titles = [];

		foreach ((array) Arr::get($graph, 'lanes', []) as $lane)
		{
			$lane_titles[(string) Arr::get($lane, 'id', '')] = (string) Arr::get($lane, 'title', '');
		}

		$type_labels = [
			'user_task'    => 'Действие',
			'service_task' => 'Автоматически',
			'manual_task'  => 'Вручную',
			'gateway_xor'  => 'Развилка XOR',
			'gateway_and'  => 'Развилка AND',
			'timer'        => 'Таймер',
			'subprocess'   => 'Подпроцесс',
		];

		$route_rows = [['Дорожка', 'Шаг', 'Тип', 'SLA']];

		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			$type = (string) Arr::get($node, 'type', '');

			if ( ! isset($type_labels[$type]))
			{
				continue;
			}

			$lane = (string) Arr::get($node, 'lane', '');
			$route_rows[] = [
				($lane !== '' AND isset($lane_titles[$lane])) ? $lane_titles[$lane] : '—',
				(string) Arr::get($node, 'title', ''),
				$type_labels[$type],
				self::_sla([
					'duration_minutes'  => (int) Arr::get($node, 'duration_minutes', 0),
					'wait_time_minutes' => (int) Arr::get($node, 'wait_time_minutes', 0),
				]),
			];
		}

		if (count($route_rows) > 1)
		{
			$body .= self::_docx_p('3. Схема процесса: маршрут по дорожкам', ['bold' => TRUE, 'size' => 28, 'after' => 120, 'page_break' => TRUE]);
			$body .= self::_docx_p('Табличная форма маршрута повторяет графическую BPMN-схему и читается в любом редакторе.', ['size' => 18, 'after' => 120]);
			$body .= self::_docx_table($route_rows, [2100, 4600, 1700, 2200]);
		}

		$body .= self::_docx_p('4. Матрица RACI', ['bold' => TRUE, 'size' => 28, 'after' => 120, 'page_break' => TRUE]);

		$raci = (array) Arr::get($document, 'raci', []);
		$columns = (array) Arr::get($raci, 'columns', []);
		$raci_rows = (array) Arr::get($raci, 'rows', []);

		if ($columns AND $raci_rows)
		{
			$head = ['Шаг'];
			$widths = [3300];

			foreach ($columns as $column)
			{
				$head[] = (string) Arr::get($column, 'name', '');
				$widths[] = 900;
			}

			$table = [$head];

			foreach ($raci_rows as $row)
			{
				$line = [(string) Arr::get($row, 'title', '')];

				foreach ($columns as $column)
				{
					$code = (string) Arr::get($column, 'code', '');
					$line[] = (string) Arr::get((array) Arr::get($row, 'cells', []), $code, '');
				}

				$table[] = $line;
			}

			// Слишком широкая матрица не влезает в портрет — режем колонки
			// до 7 ролей и предупреждаем текстом.
			if (count($widths) > 8)
			{
				$widths = array_slice($widths, 0, 8);
				$table = array_map(function ($line) {
					return array_slice($line, 0, 8);
				}, $table);
				$body .= self::_docx_p('В документе показаны первые 7 ролей; полная матрица — в системе.', ['size' => 18, 'after' => 60]);
			}

			$body .= self::_docx_table($table, $widths);
		}
		else
		{
			$body .= self::_docx_p('Роли в схеме не заданы — матрица не строится.', ['size' => 22, 'after' => 120]);
		}

		if ((array) Arr::get($document, 'rules', []))
		{
			$body .= self::_docx_p('5. Контрольные правила', ['bold' => TRUE, 'size' => 28, 'after' => 120]);
			$rule_rows = [['Код', 'Правило', 'Комментарий']];

			foreach ((array) $document['rules'] as $rule)
			{
				$rule_rows[] = [(string) $rule['code'], (string) $rule['name'], (string) $rule['note']];
			}

			$body .= self::_docx_table($rule_rows, [1500, 3200, 4900]);
		}

		$audit = (array) Arr::get($document, 'audit', []);

		if ($audit)
		{
			$body .= self::_docx_p('6. Результаты аудита «План ↔ Факт»', ['bold' => TRUE, 'size' => 28, 'after' => 120, 'page_break' => TRUE]);
			$body .= self::_docx_p((string) Arr::get($audit, 'summary', ''), ['size' => 22, 'after' => 120]);
			$body .= self::_docx_p('Риск: '.(string) Arr::get(self::severities(), (string) Arr::get($audit, 'risk_level', 'low'), '—')
				.' · Оценка потерь: '.self::money((float) Arr::get($audit, 'impact_rub', 0)), ['size' => 22, 'after' => 120]);

			$risk_rows = [['Уровень', 'Находка', 'Доказательство', 'Рекомендация', 'Эффект, ₽']];

			foreach ((array) Arr::get($audit, 'findings', []) as $finding)
			{
				$risk_rows[] = [
					(string) Arr::get(self::severities(), (string) Arr::get($finding, 'severity', 'medium'), ''),
					(string) Arr::get($finding, 'title', ''),
					(string) Arr::get($finding, 'evidence', ''),
					(string) Arr::get($finding, 'recommendation', ''),
					number_format((float) Arr::get($finding, 'impact_rub', 0), 0, '.', ' '),
				];
			}

			$body .= self::_docx_table($risk_rows, [950, 1900, 2450, 2800, 1100]);
		}

		$body .= self::_docx_p('Лист согласования', ['bold' => TRUE, 'size' => 28, 'after' => 120, 'page_break' => TRUE]);
		$body .= self::_docx_p('Владелец процесса: '.(string) Arr::get($document, 'owner', '—').' ____________________', ['size' => 22, 'after' => 240]);
		$body .= self::_docx_p((string) Arr::get($document, 'approve_role', 'Согласующий').': ____________________ / '.$date, ['size' => 22, 'after' => 240]);
		$body .= self::_docx_p('Документ сформирован ProcessHub и соответствует '.(string) Arr::get($document, 'gost', '').'.', ['size' => 18, 'after' => 0]);

		$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			.'<w:body>'.$body
			.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
			.'<w:pgMar w:top="1134" w:right="850" w:bottom="1134" w:left="1701" w:header="708" w:footer="708" w:gutter="0"/>'
			.'</w:sectPr></w:body></w:document>';

		$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			.'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/>'
			.'<w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:rPrDefault>'
			.'<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
			.'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
			.'</w:styles>';

		$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			.'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			.'<Default Extension="xml" ContentType="application/xml"/>'
			.'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			.'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
			.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
			.'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
			.'</Types>';

		$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			.'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			.'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
			.'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
			.'</Relationships>';

		$doc_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			.'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			.'</Relationships>';

		$core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
			.' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
			.' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			.'<dc:title>'.self::_x($title).'</dc:title><dc:creator>N2J ProcessHub</dc:creator>'
			.'<cp:lastModifiedBy>N2J ProcessHub</cp:lastModifiedBy>'
			.'<dcterms:created xsi:type="dcterms:W3CDTF">'.date('c').'</dcterms:created>'
			.'<dcterms:modified xsi:type="dcterms:W3CDTF">'.date('c').'</dcterms:modified>'
			.'</cp:coreProperties>';

		$app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
			.'<Application>N2J ProcessHub</Application></Properties>';

		$parts = [
			'[Content_Types].xml'          => $content_types,
			'_rels/.rels'                  => $rels,
			'word/document.xml'            => $document_xml,
			'word/styles.xml'              => $styles,
			'word/_rels/document.xml.rels' => $doc_rels,
			'docProps/core.xml'            => $core,
			'docProps/app.xml'             => $app,
		];

		// ZipArchive есть не в каждой сборке PHP (на закрытом хосте ext/zip
		// часто не собирают). DOCX — это обычный ZIP, поэтому у нас есть
		// собственный писатель на zlib: регламент не должен зависеть от ext.
		if (class_exists('ZipArchive'))
		{
			$tmp = tempnam(sys_get_temp_dir(), 'nj670d');
			$zip = new ZipArchive();

			if ($zip->open($tmp, ZipArchive::OVERWRITE) !== TRUE)
			{
				@unlink($tmp);
				return '';
			}

			foreach ($parts as $name => $data)
			{
				$zip->addFromString($name, $data);
			}

			$zip->close();
			$bytes = (string) file_get_contents($tmp);
			@unlink($tmp);
		}
		else
		{
			$bytes = self::_zip($parts);
		}

		return substr($bytes, 0, 2) === 'PK' ? $bytes : '';
	}

	/**
	 * Минимальный ZIP-писатель (deflate/store) для DOCX без ext/zip.
	 * Формат: локальный заголовок, данные, центральный каталог, EOCD.
	 */
	protected static function _zip(array $files)
	{
		$local = '';
		$central = '';
		$offset = 0;
		$time = getdate();
		$dos_time = ($time['hours'] << 11) | ($time['minutes'] << 5) | ($time['seconds'] >> 1);

		if ($time['year'] < 1980)
		{
			$time['year'] = 1980;
			$time['mon'] = 1;
			$time['mday'] = 1;
		}

		$dos_date = (($time['year'] - 1980) << 9) | ($time['mon'] << 5) | $time['mday'];

		foreach ($files as $name => $data)
		{
			$data = (string) $data;
			$crc = crc32($data);
			$deflated = function_exists('gzdeflate') ? gzdeflate($data, 9) : FALSE;
			$use_deflate = ($deflated !== FALSE AND strlen($deflated) < strlen($data));
			$payload = $use_deflate ? $deflated : $data;
			$method = $use_deflate ? 8 : 0;
			$size = strlen($data);
			$packed = strlen($payload);
			$name_len = strlen($name);

			$local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method,
					$dos_time, $dos_date, $crc, $packed, $size, $name_len, 0)
				.$name.$payload;

			$central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method,
					$dos_time, $dos_date, $crc, $packed, $size, $name_len, 0, 0, 0, 0, 0, $offset)
				.$name;

			$offset += 30 + $name_len + $packed;
		}

		$central_size = strlen($central);

		return $local.$central.pack('VvvvvVVv', 0x06054b50, 0, 0,
			count($files), count($files), $central_size, strlen($local), 0);
	}

	/**
	 * PDF-фолбэк: текст без вёрстки таблиц, зато с кириллицей и нумерацией
	 * страниц — движком nj634.
	 */
	public static function build_pdf(array $document)
	{
		if ( ! class_exists('Service_Nj634_Txt2pdf'))
		{
			return '';
		}

		$lines = [];
		$lines[] = 'УТВЕРЖДАЮ';
		$lines[] = (string) Arr::get($document, 'approve_role', 'Владелец процесса');
		$lines[] = '____________ / '.(string) Arr::get($document, 'owner', '________________');
		$lines[] = '«___» ____________ '.(string) Arr::get($document, 'year', date('Y')).' г.';
		$lines[] = '';
		$lines[] = (string) Arr::get($document, 'org_name', '');
		$lines[] = '';
		$lines[] = 'РЕГЛАМЕНТ БИЗНЕС-ПРОЦЕССА';
		$lines[] = '«'.(string) Arr::get($document, 'title', '').'»';
		$lines[] = '';
		$lines[] = 'Версия: '.(string) Arr::get($document, 'version_tag', '').' · '.(string) Arr::get($document, 'kind_label', '');
		$lines[] = 'Статус: '.(string) Arr::get($document, 'status_label', '');
		$lines[] = 'Владелец: '.(string) Arr::get($document, 'owner', '—');
		$lines[] = (string) Arr::get($document, 'city', '').', '.(string) Arr::get($document, 'year', '');
		$lines[] = '';
		$lines[] = '1. ОБЩИЕ СВЕДЕНИЯ';
		$lines[] = 'Подразделение: '.(string) Arr::get($document, 'department', '—');
		$lines[] = 'Дата: '.(string) Arr::get($document, 'date_text', '');
		$lines[] = 'Формат: '.(string) Arr::get($document, 'gost', '');
		$lines[] = '';
		$lines[] = '2. ОПЕРАЦИОННЫЕ ШАГИ';
		$lines[] = '';

		foreach ((array) Arr::get($document, 'steps', []) as $step)
		{
			$lines[] = $step['n'].'. '.$step['title'];
			$lines[] = '   Роль: '.$step['role'].' | Система: '.$step['system'];
			$lines[] = '   Вход: '.$step['input'].' | Выход: '.$step['output'];
			$lines[] = '   SLA: '.$step['sla'];
			$lines[] = '';
		}

		$comments = self::comments_rows($document);

		if ($comments)
		{
			$lines[] = '2.1. КОММЕНТАРИИ РЕВЬЮЕРА';

			foreach ($comments as $comment)
			{
				$lines[] = $comment['n'].'. '.$comment['title'].' ['.$comment['overlay'].']';
				$lines[] = '   '.$comment['comment'];
			}

			$lines[] = '';
		}

		$graph = (array) Arr::get($document, 'graph', []);
		$lane_titles = [];

		foreach ((array) Arr::get($graph, 'lanes', []) as $lane)
		{
			$lane_titles[(string) Arr::get($lane, 'id', '')] = (string) Arr::get($lane, 'title', '');
		}

		$route = [];

		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			if ( ! in_array((string) Arr::get($node, 'type', ''), ['user_task', 'service_task', 'manual_task', 'subprocess'], TRUE))
			{
				continue;
			}

			$lane = (string) Arr::get($node, 'lane', '');
			$title = ($lane !== '' AND isset($lane_titles[$lane])) ? $lane_titles[$lane] : 'Без дорожки';
			$route[$title][] = (string) Arr::get($node, 'title', '');
		}

		if ($route)
		{
			$lines[] = '3. СХЕМА ПРОЦЕССА: МАРШРУТ ПО ДОРОЖКАМ';

			foreach ($route as $lane_title => $titles)
			{
				$lines[] = $lane_title.': '.implode(' → ', $titles);
			}

			$lines[] = '';
		}

		$raci = (array) Arr::get($document, 'raci', []);
		$columns = (array) Arr::get($raci, 'columns', []);

		if ($columns AND (array) Arr::get($raci, 'rows', []))
		{
			$lines[] = '4. МАТРИЦА RACI';
			$lines[] = 'Шаг | '.implode(' | ', array_map(function ($column) {
				return (string) Arr::get($column, 'name', '');
			}, $columns));

			foreach ((array) Arr::get($raci, 'rows', []) as $row)
			{
				$cells = [(string) Arr::get($row, 'title', '')];

				foreach ($columns as $column)
				{
					$cells[] = (string) Arr::get((array) Arr::get($row, 'cells', []), (string) Arr::get($column, 'code', ''), '');
				}

				$lines[] = implode(' | ', $cells);
			}

			$lines[] = 'R — исполнитель, A — ответственный, C — консультирует, I — информируется.';
			$lines[] = '';
		}

		$audit = (array) Arr::get($document, 'audit', []);

		if ($audit)
		{
			$lines[] = '5. АУДИТ «ПЛАН ↔ ФАКТ»';
			$lines[] = (string) Arr::get($audit, 'summary', '');
			$lines[] = '';

			foreach ((array) Arr::get($audit, 'findings', []) as $finding)
			{
				$lines[] = '['.(string) Arr::get(self::severities(), (string) Arr::get($finding, 'severity', 'medium'), '')
					.'] '.(string) Arr::get($finding, 'title', '');
				$lines[] = 'Доказательство: '.(string) Arr::get($finding, 'evidence', '');
				$lines[] = 'Рекомендация: '.(string) Arr::get($finding, 'recommendation', '');
				$lines[] = 'Эффект: '.self::money((float) Arr::get($finding, 'impact_rub', 0));
				$lines[] = '';
			}
		}

		$lines[] = 'Документ сформирован ProcessHub '.date('d.m.Y H:i').'.';

		$built = Service_Nj634_Txt2pdf::build(implode("\n", $lines), (string) Arr::get($document, 'title', ''));

		return (is_array($built) AND ! empty($built['ok'])) ? (string) $built['pdf'] : '';
	}

	public static function delete_for_process($process_id)
	{
		$rows = self::_all('SELECT * FROM nj_670_reports WHERE process_id = '.(int) $process_id);

		foreach ($rows as $row)
		{
			self::delete((int) $row['id']);
		}
	}

	/**
	 * Публичные (серверные) сводки для страницы отчёта.
	 */
	public static function raci_matrix_html_rows(array $document)
	{
		return (array) Arr::path($document, 'raci.rows', []);
	}

	public static function downloads(array $reports)
	{
		$out = [];

		foreach ($reports as $report)
		{
			$out[] = [
				'id'        => (int) $report['id'],
				'status'    => (string) $report['status'],
				'size_docx' => (int) $report['docx_size'],
				'size_pdf'  => (int) $report['pdf_size'],
				'has_docx'  => (string) $report['docx_path'] !== '',
				'has_pdf'   => (string) $report['pdf_path'] !== '',
				'error'     => (string) $report['error'],
				'created_at'=> (string) $report['created_at'],
			];
		}

		return $out;
	}
}
