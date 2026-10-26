<?php
/**
 * Регрессия исчезнувшей кнопки деталей: настоящий action_build и настоящая вьюха,
 * БД и очередь заменены заглушкой сервиса. Права самого сервиса дополнительно
 * покрывает tests/Unit/Nj667QuotaAndAccessTest.php.
 * N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj667_build_parts_stand.php
 */
define('STAND_ROOT', is_dir('/app/application') ? '/app' : dirname(__DIR__));
if ( ! defined('SYSPATH')) { define('SYSPATH', STAND_ROOT.'/system/'); }
require STAND_ROOT.'/system/classes/Kohana/Arr.php';
require STAND_ROOT.'/system/classes/Arr.php';
set_error_handler(function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

class Kohana { public static $charset = 'UTF-8'; }
class Request
{
	const POST = 'POST';
	public $data = [];
	public $verb = 'GET';
	public $ajax = TRUE;
	public function param($key, $default = NULL) { return 'y743ppqvv67m'; }
	public function query($key = NULL) { return $key === NULL ? ['build' => 252, 'job' => 2014] : (['build' => 252, 'job' => 2014][$key] ?? NULL); }
	public function post($key = NULL) { return $key === NULL ? $this->data : ($this->data[$key] ?? NULL); }
	public function method() { return $this->verb; }
	public function is_ajax() { return $this->ajax; }
}
class Response
{
	public $text = '';
	public function headers($name, $value) { return $this; }
	public function body($value) { $this->text = $value; return $this; }
}
class Controller_Erp100_Base
{
	public $request;
	public $response;
	public $template;
	public $auto_render = TRUE;
	public function set_title($title) {}
}
class Gate { public static function logged_in() { return TRUE; } }
class Security
{
	public static function token() { return 'csrf-test'; }
	public static function check($token) { return $token === self::token(); }
}
class HTTP_Exception extends Exception
{
	public static function factory($code, $message) { return new self($message, $code); }
}
class HTTP
{
	public static $location;
	public static $status;
	public static function redirect($location, $status) { self::$location = $location; self::$status = $status; }
}
class HTML { public static function chars($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } }
class UTF8 { public static function substr($s, $start, $len) { return mb_substr($s, $start, $len); } }
class View
{
	public $data;
	public $file;
	public static function factory($file, $data = [])
	{
		$v = new self; $v->file = $file; $v->data = $data; return $v;
	}
	public function render()
	{
		extract($this->data);
		ob_start();
		try {
			require STAND_ROOT.'/application/modules/nj667_graph3d/views/'.$this->file.'.php';
			return ob_get_contents();
		} finally { ob_end_clean(); }
	}
}
class Service_Nj667_Graph3d
{
	public static $owner = FALSE;
	public static $allowed = TRUE;
	public static $calls = [];
	public static $render_live = FALSE;
	public static $parts_live = FALSE;
	public static $parts_done = FALSE;
	public static function __callStatic($name, $args)
	{
		self::$calls[$name][] = $args;
		switch ($name)
		{
			case 'project': return ['id' => 7, 'user_id' => 99, 'code' => 'y743ppqvv67m', 'title' => 'Тест'];
			case 'owns': return self::$owner;
			case 'can_read': case 'can_read_build': case 'build_parts_ready': return TRUE;
			case 'can_prepare_build_parts': return self::$allowed;
			case 'build_get': case 'build_reconcile': case 'build_current':
				return ['id' => 252, 'project_id' => 7, 'user_id' => 123, 'job_id' => 2014,
					'status' => 'done', 'params_json' => '{"type":"flat","quality":"saved"}'];
			case 'build_prefs': return ['type' => 'flat', 'quality' => 'project-default'];
			case 'build_opt': return array_replace($args[1], $args[0]);
			case 'build_geometry': return ['ok' => TRUE,
				'counts' => ['nodes' => 2, 'edges' => 1, 'clusters' => 2, 'types' => 1],
				'span' => ['x' => 10, 'y' => 10, 'z' => 10],
				'dims' => ['slot_w' => 10, 'slot_t' => 2],
				'types' => [['i' => 0, 'count' => 2, 'slots' => 1, 'arms' => [], 'min_angle' => 999, 'nodes' => ['a', 'b']]]];
			case 'build_parts_render': return ['ok' => TRUE, 'job_id' => 3000, 'parts' => 1];
			case 'build_stale': return FALSE;
			case 'build_schema_state': return ['glb' => TRUE, 'parts' => TRUE];
			case 'build_file_path': return '';
			case 'build_parts_run_limit': return 30;
			case 'build_parts_job': return self::$parts_live ? 3000 : 0;
			case 'job_state': return ['live' => $args[0] === 3000 ? self::$parts_live : self::$render_live];
			case 'job_live': return $args[0]['live'];
			case 'build_parts': return self::$parts_done ? [['part_index' => 0, 'status' => 'done']]
				: (self::$parts_live ? [['part_index' => 0, 'status' => 'queued']] : []);
			case 'set_build_prefs': case 'graph_of': case 'public_graph': case 'simple_flow_state':
			case 'build_history': case 'build_types': case 'build_qualities': case 'build_lib_missing':
			case 'build_engine_hint': case 'build_parts_plan': case 'build_parts_previews':
			case 'drawing_units': case 'build_type_params': return [];
			default: throw new Exception('Неожиданный вызов сервиса: '.$name);
		}
	}
}
require STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php';
class Stand_Controller extends Controller_Nj667_Graph3d
{
	public function __construct($post = NULL, $ajax = TRUE)
	{
		$this->_user_id = 123;
		$this->request = new Request;
		$this->request->ajax = $ajax;
		if ($post !== NULL) { $this->request->verb = 'POST'; $this->request->data = $post; }
		$this->response = new Response;
		$this->template = new stdClass;
	}
}
$checks = 0;
function check($label, $actual, $expected)
{
	global $checks;
	$checks++;
	if ($actual !== $expected) { throw new Exception($label.': '.json_encode($actual, JSON_UNESCAPED_UNICODE)); }
	echo "ок   $label\n";
}

foreach ([FALSE, TRUE] as $owner)
{
	Service_Nj667_Graph3d::$owner = $owner;
	foreach ([FALSE, TRUE] as $allowed)
	{
		Service_Nj667_Graph3d::$allowed = $allowed;
		$c = new Stand_Controller;
		$c->action_build();
		$want = ($owner OR $allowed);
		check('GET: право владельца/автора '.(int) $owner.'/'.(int) $allowed,
			$c->template->content->data['can_prepare_parts'], $want);
		$html = $c->template->content->render();
		check('GET: обе кнопки в HTML', substr_count($html, 'data-job-btn="parts"'), $want ? 2 : 0);
	}
}
Service_Nj667_Graph3d::$owner = FALSE;
Service_Nj667_Graph3d::$allowed = TRUE;
foreach ([FALSE, TRUE] as $render_live)
{
	Service_Nj667_Graph3d::$render_live = $render_live;
	foreach ([FALSE, TRUE] as $parts_live)
	{
		Service_Nj667_Graph3d::$parts_live = $parts_live;
		$c = new Stand_Controller;
		$c->action_build();
		$html = $c->template->content->render();
		check('задача не убирает кнопки', substr_count($html, 'data-job-btn="parts"'), 2);
		check('блокировка только по деталям', substr_count($html, 'data-job-btn="parts" disabled'), $parts_live ? 2 : 0);
	}
}
Service_Nj667_Graph3d::$render_live = FALSE;
Service_Nj667_Graph3d::$parts_live = FALSE;
$post = ['build' => 252, 'parts' => 1, 'csrf' => 'csrf-test'];
foreach ([TRUE, FALSE] as $ajax)
{
	Service_Nj667_Graph3d::$calls = [];
	$c = new Stand_Controller($post, $ajax);
	$c->action_build();
	$call = Service_Nj667_Graph3d::$calls['build_parts_render'][0];
	check('POST: пачка на выбранную сборку, а не новую', $call[0], 252);
	check('POST: посадки из снимка сборки', $call[3]['quality'], 'saved');
	check('POST: запрос от текущего пользователя', $call[3]['request_user_id'], 123);
	check('POST: настройки чужого проекта не меняются', isset(Service_Nj667_Graph3d::$calls['set_build_prefs']), FALSE);
	if ($ajax)
	{
		check('AJAX: JSON не заменяется шаблоном', $c->auto_render, FALSE);
		$reply = json_decode($c->response->text, TRUE);
		check('AJAX: поставлено в очередь', $reply['ok'], TRUE);
		check('AJAX: задача узлов', $reply['job_id'], 3000);
		check('AJAX: выбранная сборка', $reply['build_id'], 252);
	}
	else
	{
		check('без JS: возврат на выбранную сборку', HTTP::$location, '/graph3d/b/y743ppqvv67m?build=252&job=3000&parts=1');
		check('без JS: POST → 303', HTTP::$status, 303);
	}
}
foreach (['access', 'csrf'] as $reason)
{
	Service_Nj667_Graph3d::$allowed = $reason !== 'access';
	Service_Nj667_Graph3d::$calls = [];
	$bad_post = $post;
	if ($reason === 'csrf') { $bad_post['csrf'] = 'invalid'; }
	$c = new Stand_Controller($bad_post);
	$code = 0;
	try { $c->action_build(); } catch (HTTP_Exception $e) { $code = $e->getCode(); }
	check('POST: запрет '.$reason, $code, 403);
	check('POST: отказ не ставит задачу', isset(Service_Nj667_Graph3d::$calls['build_parts_render']), FALSE);
}
Service_Nj667_Graph3d::$allowed = TRUE;
$c = new Stand_Controller;
$c->action_build();
check('GET истории: параметры выбранной сборки', $c->template->content->data['opt']['quality'], 'saved');
// Поле quality без type тоже должно считаться явными настройками.
$c = new Stand_Controller(['quality' => 'custom', 'csrf' => 'csrf-test']);
Service_Nj667_Graph3d::$owner = TRUE;
// Останавливаемся после сохранения настроек (build_create намеренно не замокан).
try { $c->action_build(); } catch (Exception $e) {
	if ($e->getMessage() !== 'Неожиданный вызов сервиса: build_create') { throw $e; }
}
$pref_calls = Service_Nj667_Graph3d::$calls['set_build_prefs'];
check('POST: явное качество не теряется из-за OR', end($pref_calls)[1]['quality'], 'custom');
Service_Nj667_Graph3d::$parts_done = TRUE;
$c = new Stand_Controller;
$c->action_build();
$html = $c->template->content->render();
check('готовая деталь — STL, как в опросе JS', strpos($html, 'STL готов') !== FALSE, TRUE);
check('скачивание узла #0 из выбранной сборки',
	strpos($html, '?part=0&amp;file=stl&amp;build=252') !== FALSE, TRUE);
echo "ВСЁ ОК: $checks проверок\n";
