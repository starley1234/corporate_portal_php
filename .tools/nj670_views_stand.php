<?php
/**
 * ProcessHub: смоук-рендер вьюх без Kohana и БД.
 *
 * Зачем: правки вьюх ловятся линтером только по синтаксису. Опечатка в
 * имени переменной или вызов несуществующего метода сервиса всплывает уже
 * на живом сервере. Здесь каждая вьюха собирается с фикстурами и реальными
 * сервисами модуля (без SQL): ошибка PHP печатается сразу.
 *
 * Запуск:
 *   cd /tmp/phpwasm && node phpwasm_stand.mjs .tools/nj670_views_stand.php
 *
 * HTML-галерея для просмотра глазами (PHP-WASM монтирует каталог хоста):
 *   rm -rf /tmp/nj670_preview
 *   N2J_PREVIEW_DIR=/preview N2J_MOUNT=/tmp/nj670_preview:/preview \
 *     node phpwasm_stand.mjs .tools/nj670_views_stand.php
 * Открывать через статический сервер из public_html: ссылки /assets/...
 */

if ( ! defined('SYSPATH')) { define('SYSPATH', '/app/system/'); }
if ( ! defined('DOCROOT')) { define('DOCROOT', '/app/'); }

class Arr
{
	public static function get($a, $k, $d = NULL)
	{
		return (is_array($a) AND array_key_exists($k, $a)) ? $a[$k] : $d;
	}

	public static function path($a, $p, $d = NULL)
	{
		$cur = $a;

		foreach (explode('.', $p) as $k)
		{
			if ( ! is_array($cur) OR ! array_key_exists($k, $cur)) { return $d; }
			$cur = $cur[$k];
		}

		return $cur;
	}
}

class HTML
{
	public static function chars($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

class UTF8
{
	public static function substr($s, $from, $len = NULL)
	{
		return mb_substr((string) $s, (int) $from, $len === NULL ? NULL : (int) $len, 'UTF-8');
	}

	public static function strlen($s) { return mb_strlen((string) $s, 'UTF-8'); }
}

class Kohana_Config_Group
{
	protected $values;

	public function __construct(array $values) { $this->values = $values; }
	public function as_array() { return $this->values; }
}

class Kohana_Config_Stub
{
	// Конфиг модуля — обычный PHP-массив; читаем его как на сайте, чтобы
	// вьюха editors получила реальные подписи типов узлов.
	public function load($name)
	{
		$file = DOCROOT.'application/modules/nj670_processhub/config/'.$name.'.php';

		return new Kohana_Config_Group(is_file($file) ? (array) include $file : []);
	}
}

class Kohana
{
	public static $log;
	public static $config;
}

class Log
{
	const WARNING = 4;
	public function add($level, $msg, $args = []) {}
}

Kohana::$log = new Log;
Kohana::$config = new Kohana_Config_Stub;

class Security
{
	public static function token() { return 'csrf'; }
	public static function check($t) { return TRUE; }
}

class View
{
	protected $file;
	protected $data = [];

	public static function factory($name)
	{
		$v = new self;
		$v->file = DOCROOT.'application/modules/nj670_processhub/views/'.$name.'.php';

		return $v;
	}

	public function set($k, $v = NULL)
	{
		if (is_array($k)) { $this->data = array_merge($this->data, $k); }
		else { $this->data[$k] = $v; }

		return $this;
	}

	public function render()
	{
		extract($this->data, EXTR_SKIP);
		ob_start();
		include $this->file;

		return ob_get_clean();
	}

	public function __toString() { return $this->render(); }
}

require_once DOCROOT.'application/modules/nj670_processhub/classes/Service/Nj670/Repo.php';
require_once DOCROOT.'application/modules/nj670_processhub/classes/Service/Nj670/Graph.php';
require_once DOCROOT.'application/modules/nj670_processhub/classes/Service/Nj670/Process.php';

// Отчётный сервис тянет ZIP-писатель и шаблоны DOCX — для смоука вьюх
// достаточно подписей оверлеев.
class Service_Nj670_Report
{
	public static function overlay_labels()
	{
		return ['risk' => 'риск', 'control' => 'контроль', 'question' => 'вопрос', 'idea' => 'идея'];
	}
}

$fails = 0;
// Необязательная HTML-галерея для глазами: N2J_PREVIEW_DIR=/preview + N2J_MOUNT.
// CSS и FontAwesome берутся из public_html, поэтому открывать через сервер,
// а не file://.
$preview_dir = (string) getenv('N2J_PREVIEW_DIR');
$preview_pages = [];

function preview_write($dir, $name, $html, $title)
{
	if ( ! is_dir($dir) AND ! mkdir($dir, 0777, TRUE)) { return FALSE; }

	$nav = '<div class="mx-auto mb-4 flex max-w-6xl flex-wrap items-center gap-2 px-4 pt-4 text-sm">'
		.'<a class="font-semibold text-blue-600 hover:underline" href="index.html">ProcessHub · предпросмотр</a>'
		.'<span class="text-gray-300">/</span><span class="text-gray-500">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</span>'
		.'<span class="ml-auto rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">фикстуры стенда</span></div>';

	$page = '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
		.'<meta name="viewport" content="width=device-width, initial-scale=1">'
		.'<title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').' · ProcessHub</title>'
		.'<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">'
		.'<link rel="stylesheet" href="/assets/css/app.css"></head>'
		.'<body class="bg-gray-50">'.$nav.'<div class="mx-auto max-w-6xl px-4 pb-10">'.$html.'</div></body></html>';

	return (bool) file_put_contents($dir.'/view-'.$name.'.html', $page);
}

function render_view($name, array $vars, $label)
{
	global $fails, $preview_dir, $preview_pages;

	try
	{
		$html = View::factory('670/'.$name)->set($vars)->render();
		$size = strlen($html);
		$ok = $size > 500;

		echo ($ok ? 'ok   ' : 'ФЕЙЛ ').$label.' ('.$size.' байт)'."\n";
		if ( ! $ok) { $fails++; }

		if ($ok AND $preview_dir !== '')
		{
			$preview_pages[$name] = $label;
			preview_write($preview_dir, $name, $html, $label);
		}

		return $html;
	}
	catch (Throwable $e)
	{
		echo 'ФЕЙЛ '.$label.': '.$e->getMessage()."\n";
		$fails++;

		return '';
	}
}

$process = [
	'id' => 7, 'code' => 'zakupka1', 'title' => 'Закупка у поставщика', 'description' => 'От заявки до оплаты.',
	'workspace_id' => 1, 'is_demo' => 0, 'plan_status' => 'active', 'owner_role_name' => 'Юрист',
	'department' => 'Финансы', 'owner_role_code' => 'lawyer', 'parent_id' => 0,
	'plan_minutes' => 1440, 'fact_minutes' => 4320, 'audit_impact' => 18400.0,
	'versions_count' => 3, 'reports_count' => 2, 'plan_metrics' => [], 'fact_metrics' => [],
	'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-19 10:00:00',
];

$job = ['found' => TRUE, 'job_id' => 5, 'status' => 'running', 'stage' => 'размечаю шаги',
	'stages' => [['message' => 'читаю документ', 'at' => '2026-10-19 10:00:00'],
		['message' => 'размечаю шаги', 'at' => '2026-10-19 10:00:20']],
	'elapsed_sec' => 95, 'error' => ''];

$metrics = ['nodes_count' => 4, 'edges_count' => 4, 'elapsed_minutes' => 4320, 'work_minutes' => 600,
	'wait_minutes' => 3720, 'cost_rub' => 18400.0, 'loop_count' => 1, 'shadow_count' => 2,
	'bottleneck' => ['title' => 'Согласование', 'share' => 0.6]];

$versions = [
	['id' => 3, 'version_tag' => 'v3', 'kind' => 'plan', 'status' => 'active', 'version_no' => 3,
		'created_at' => '2026-10-18 12:00:00', 'job_id' => 5, 'source_kind' => 'text', 'model' => 'llm',
		'error' => '', 'note' => '', 'metrics' => $metrics, 'graph' => ['nodes' => [], 'edges' => []]],
];

$audit = ['id' => 1, 'status' => 'running', 'job_id' => 5, 'risk_level' => 'high',
	'mode' => 'llm', 'model' => 'gpt-4o-mini',
	'total_impact_rub' => 18400.0, 'summary' => 'Обход юриста', 'findings' => [],
	'created_at' => '2026-10-19 10:00:00'];

$report = ['id' => 9, 'status' => 'running', 'job_id' => 5, 'error' => '', 'model' => 'worker',
	'created_at' => '2026-10-19 10:00:00', 'docx_path' => '', 'pdf_path' => '',
	'docx_size' => 0, 'pdf_size' => 0];

$base = [
	'process' => $process, 'csrf' => 'csrf', 'notice' => '', 'error' => '', 'job' => $job,
	'statuses' => ['active' => 'действует', 'draft' => 'черновик'],
	'node_types' => ['user_task' => 'Задача'],
];

$html = render_view('index', array_merge($base, [
	'processes' => [$process], 'totals' => ['processes' => 1, 'audits' => 1, 'reports' => 2, 'impact' => 18400],
	'ontology' => ['role' => [['name' => 'Юрист', 'code' => 'lawyer']]],
	'workspace' => ['company_name' => 'ООО Тест', 'title' => 'Финансы'],
	'query' => ['q' => '', 'department' => '', 'owner' => 0, 'state' => ''],
	'facets' => ['departments' => ['Финансы'], 'owners' => [1 => 'Иван']],
	'all_count' => 1, 'worker' => ['online' => TRUE, 'queued' => 2, 'hint' => ''],
	'limits' => ['max_nodes' => 80, 'synth_per_day' => 10, 'audit_per_day' => 10],
]), 'index: реестр с быстрыми фильтрами');

// Быстрые фильтры действительно ведут на отфильтрованный список.
foreach (['no_fact', 'no_owner', 'audited'] as $state)
{
	echo (strpos($html, 'state='.$state) !== FALSE ? 'ok   ' : 'ФЕЙЛ ')
		.'index: чип «'.$state.'» ведёт на фильтр'."\n";

	if (strpos($html, 'state='.$state) === FALSE) { $fails++; }
}

echo (strpos($html, 'факт ×3,0') !== FALSE ? 'ok   ' : 'ФЕЙЛ ').'index: разрыв факт/план числом'."\n";
if (strpos($html, 'факт ×3,0') === FALSE) { $fails++; }

$html = render_view('create', array_merge($base, [
	'post' => ['title' => '', 'text' => '', 'department' => '', 'owner_role_code' => '', 'parent_code' => ''],
	'ontology' => ['role' => [['name' => 'Юрист', 'code' => 'lawyer']],
		'system' => [['name' => '1С', 'code' => '1c']], 'artifact' => [['name' => 'Заявка', 'code' => 'req']]],
	'limits' => ['max_text' => 20000], 'parents' => [], 'allow_rules' => TRUE,
	'worker' => ['online' => TRUE, 'queued' => 0, 'hint' => ''],
]), 'create: форма с примером и счётчиком');

echo (strpos($html, 'id="nj670-example"') !== FALSE ? 'ok   ' : 'ФЕЙЛ ')
	.'create: есть кнопка «Вставить пример»'."\n";
if (strpos($html, 'id="nj670-example"') === FALSE) { $fails++; }

echo (strpos($html, 'id="nj670-count"') !== FALSE ? 'ok   ' : 'ФЕЙЛ ')
	.'create: счётчик знаков на месте'."\n";
if (strpos($html, 'id="nj670-count"') === FALSE) { $fails++; }

$editor_graph = [
	'lanes' => [
		['id' => 'l1', 'title' => 'Финансы', 'role_code' => 'lawyer', 'y' => 0, 'height' => 210],
		['id' => 'l2', 'title' => 'Служба безопасности', 'role_code' => 'sec', 'y' => 210, 'height' => 170],
	],
	'nodes' => [
		['id' => 'n1', 'type' => 'start', 'title' => 'Заявка', 'x' => 40, 'y' => 40, 'w' => 86, 'h' => 60, 'properties' => []],
		['id' => 'n2', 'type' => 'user_task', 'title' => 'Согласовать договор', 'x' => 180, 'y' => 28, 'w' => 196, 'h' => 86,
			'role_code' => 'lawyer', 'system_code' => '1c', 'duration_minutes' => 120, 'wait_time_minutes' => 90,
			'properties' => ['role_label' => 'Юрист', 'system_label' => '1С', 'overlay' => 'risk', 'comment' => 'Обходят в 35 % кейсов']],
		['id' => 'n3', 'type' => 'gateway_xor', 'title' => 'Проверка СБ', 'x' => 430, 'y' => 25, 'w' => 92, 'h' => 92, 'properties' => []],
		['id' => 'n4', 'type' => 'service_task', 'title' => 'Оплата в 1С', 'x' => 580, 'y' => 28, 'w' => 196, 'h' => 86,
			'role_code' => 'accountant', 'system_code' => '1c', 'duration_minutes' => 30, 'properties' => ['role_label' => 'Бухгалтер', 'system_label' => '1С']],
		['id' => 'n5', 'type' => 'end', 'title' => 'Оплачено', 'x' => 830, 'y' => 40, 'w' => 86, 'h' => 60, 'properties' => []],
	],
	'edges' => [
		['id' => 'e1', 'from' => 'n1', 'to' => 'n2', 'type' => 'sequence', 'condition' => '', 'weight' => 40],
		['id' => 'e2', 'from' => 'n2', 'to' => 'n3', 'type' => 'sequence', 'condition' => '', 'weight' => 40],
		['id' => 'e3', 'from' => 'n3', 'to' => 'n4', 'type' => 'sequence', 'condition' => 'СБ одобрила', 'weight' => 40],
		['id' => 'e4', 'from' => 'n4', 'to' => 'n5', 'type' => 'sequence', 'condition' => '', 'weight' => 40],
	],
	'meta' => ['mode' => 'plan', 'width' => 980, 'height' => 420],
];

render_view('editor', array_merge($base, [
	'version' => $versions[0], 'graph' => $editor_graph, 'metrics' => $metrics,
	'ontology' => ['list' => ['role' => [['name' => 'Юрист', 'code' => 'lawyer']],
		'system' => [['name' => '1С', 'code' => '1c']], 'artifact' => [['name' => 'Заявка', 'code' => 'req']]]],
	'can_start' => TRUE, 'siblings' => [], 'versions' => $versions,
	'parent_process' => NULL, 'child_processes' => [], 'warn' => '',
	'limits' => ['max_nodes' => 80], 'kind' => 'plan',
]), 'editor: холст, палитра и инспектор');

render_view('fact', array_merge($base, ['version' => $versions[0], 'graph' => $editor_graph, 'metrics' => $metrics,
	'svg' => Service_Nj670_Graph::svg($editor_graph, ['mode' => 'fact']), 'imports' => [], 'plan' => $versions[0],
	'limits' => ['max_import_bytes' => 8388608, 'max_import_rows' => 100000]]), 'fact: журнал и полоса воркера');

render_view('audit', array_merge($base, ['audit' => $audit, 'findings' => [], 'severities' => [],
	'plan_metrics' => $metrics, 'fact_metrics' => $metrics, 'audits' => [$audit],
	'plan_svg' => '', 'fact_svg' => '']), 'audit: находки и полоса воркера');

render_view('versions', array_merge($base, ['versions' => $versions, 'left' => NULL, 'right' => NULL,
	'diff' => NULL]), 'versions: история редакций');

render_view('passport', array_merge($base, ['workspace' => [], 'plan' => $versions[0], 'fact' => NULL,
	'plan_graph' => [], 'fact_graph' => [], 'plan_metrics' => $metrics, 'fact_metrics' => NULL,
	'coverage_plan' => Service_Nj670_Process::coverage([]),
	'coverage_fact' => Service_Nj670_Process::coverage([]),
	'architecture' => Service_Nj670_Process::architecture([], [], []),
	'raci' => [], 'versions' => $versions, 'audits' => [$audit], 'latest_audit' => $audit,
	'findings' => [], 'reports' => [$report], 'calls' => [], 'children' => [], 'parent' => NULL,
	'comments' => []]), 'passport: паспорт процесса');

render_view('report', array_merge($base, ['version' => $versions[0], 'kind' => 'plan',
	'audit' => $audit, 'document' => NULL, 'reports' => [$report], 'versions' => $versions,
	'plan' => $versions[0], 'fact' => NULL]), 'report: регламент');

if ($preview_dir !== '' AND $preview_pages)
{
	$links = '';

	foreach ($preview_pages as $name => $label)
	{
		$links .= '<li><a class="font-semibold text-blue-600 hover:underline" href="view-'.$name.'.html">'
			.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a></li>';
	}

	$page = '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
		.'<meta name="viewport" content="width=device-width, initial-scale=1">'
		.'<title>ProcessHub · предпросмотр вьюх</title>'
		.'<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">'
		.'<link rel="stylesheet" href="/assets/css/app.css"></head><body class="bg-gray-50">'
		.'<div class="mx-auto max-w-3xl px-4 py-10"><h1 class="n2j-ds-h2 font-bold text-gray-900">ProcessHub: предпросмотр вьюх</h1>'
		.'<p class="mt-2 n2j-ds-body text-gray-600">Отрендерено смоук-стендом на фикстурах. Живые данные и права не участвуют.</p>'
		.'<ul class="mt-4 space-y-2 n2j-ds-body">'.$links.'</ul></div></body></html>';

	file_put_contents($preview_dir.'/index.html', $page);
	echo 'HTML-галерея: '.$preview_dir."/index.html\n";
}

echo $fails ? "\nПРОВАЛ: замечаний $fails\n" : "\nвсе проверки пройдены\n";
exit($fails ? 1 : 0);
