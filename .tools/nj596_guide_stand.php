<?php
/** Регрессии путеводителя: реальные манифесты, контроллер и вьюхи без БД.
 * Запуск — README модуля: PHP-in-WASM с README/AGENTS в N2J_STAND_FILES.
 */
$root = is_dir('/app/application') ? '/app' : dirname(__DIR__);
if ( ! defined('SYSPATH')) { define('SYSPATH', $root.'/system/'); }
define('APPPATH', $root.'/application/');
require SYSPATH.'classes/Kohana/Arr.php';
require SYSPATH.'classes/Arr.php';
set_error_handler(function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_NOTICE);
class Kohana
{
	public static $config;
	public static $caching = FALSE;
	public static function modules()
	{
		$out = [];
		foreach (glob(APPPATH.'modules/*', GLOB_ONLYDIR) as $path) { $out[basename($path)] = $path.'/'; }
		return $out;
	}
}
class Config
{
	public function load($name) { return require APPPATH.'modules/nj596_guide/config/'.$name.'.php'; }
}
Kohana::$config = new Config;
class HTML { public static function chars($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } }
class Auth
{
	public static $admin = FALSE;
	public static function instance() { return new self; }
	public function logged_in($role = NULL) { return self::$admin; }
}
class HTTP_Exception extends RuntimeException
{
	public static function factory($code, $message) { return new self($message, $code); }
}
class Request
{
	public $slug;
	public $path = '';
	public function uri() { return $this->path; }
	public function param($name) { return $this->slug; }
}
class Controller_Erp100_Base
{
	public $request;
	public $template;
	public function __construct() { $this->request = new Request; $this->template = new stdClass; }
	public function before() {}
	public function set_title($s) {}
	public function set_description($s) {}
	public function set_module_nav($items) {}
}
class View
{
	public $file;
	public $data;
	public static function factory($file, $data = []) { $v = new self; $v->file = $file; $v->data = $data; return $v; }
	public function render()
	{
		extract($this->data);
		ob_start();
		try { require APPPATH.'modules/nj596_guide/views/'.$this->file.'.php'; return ob_get_contents(); }
		finally { ob_end_clean(); }
	}
	public function __toString() { return $this->render(); }
}
require SYSPATH.'classes/Kohana/Route.php';
require SYSPATH.'classes/Route.php';
require APPPATH.'modules/nj596_guide/init.php';
require APPPATH.'classes/Manifest.php';
require APPPATH.'modules/nj110_catalog/classes/Service/Nj110/Catalog.php';
require APPPATH.'modules/nj596_guide/classes/Controller/Nj596/Guide.php';
$checks = 0;
function check($label, $condition)
{
	global $checks;
	$checks++;
	if ( ! $condition) { throw new RuntimeException('ФЕЙЛ '.$label); }
	echo "ок   $label\n";
}
$c = new Controller_Nj596_Guide;
try { $c->action_dev(); check('гостю dev недоступен', FALSE); }
catch (HTTP_Exception $e) { check('гостю dev недоступен', $e->getCode() === 403); }
Auth::$admin = TRUE;
$c->action_dev();
$sections = $c->template->content->data['sections'];
check('dev собрал все подключённые модули', count($sections) === count(Kohana::modules()));
$html = $c->template->content->render();
check('dev полностью рендерится без предупреждений', strpos($html, 'Документация разработчика') !== FALSE);
check('dev содержит платформенный модуль без group', strpos($html, 'id="mod-nj120_jobs"') !== FALSE);
check('dev содержит Graph3D', strpos($html, 'id="mod-nj667_graph3d"') !== FALSE);
$by_name = array_column($sections, NULL, 'name');
check('dev читает README и AGENTS из файлов модуля',
	$by_name['nj596_guide']['readme'] !== '' AND $by_name['nj596_guide']['agents'] !== '');
check('dev выводит собранный markdown', strpos($html, $by_name['nj596_guide']['readme']) !== FALSE);
// Минимальный реестровый манифест — никаких витринных полей.
$html = View::factory('596/dev', ['sections' => [[
	'name' => 'worker_only', 'manifest' => ['worker' => []], 'mcp' => [], 'readme' => '', 'agents' => '',
], [
	'name' => 'no_manifest', 'manifest' => NULL, 'mcp' => [], 'readme' => '', 'agents' => '',
], [
	'name' => '<unsafe>', 'manifest' => ['group' => '<group>', 'short' => '<script>bad</script>'],
	'mcp' => ['<tool>'], 'readme' => '', 'agents' => '',
]]])->render();
check('минимальный манифест и отсутствие манифеста не роняют dev', strpos($html, 'worker_only') !== FALSE);
check('метаданные экранируются', strpos($html, '&lt;group&gt;') !== FALSE AND strpos($html, '<script>bad</script>') === FALSE);
$c->action_index();
$html = $c->template->content->render();
check('обзор рендерится из текущего каталога', strpos($html, '/guide/graph3d') !== FALSE);
$pres = Kohana::$config->load('nj596_presentations');
$modules = Service_Nj110_Catalog::modules();
$slugs = array_column($modules, 'slug');
foreach ($pres as $slug => $presentation)
{
	check('инструкция привязана к действующему сервису: '.$slug, in_array($slug, $slugs, TRUE));
	check('есть описание и шаги: '.$slug, ! empty($presentation['about']) AND ! empty($presentation['steps']));
}
foreach ($modules as $module)
{
	$c->request->slug = $module['slug'];
	$c->request->path = 'guide/'.$module['slug'];
	$match = Route::get('nj596_service')->matches($c->request);
	check('реальный маршрут открывает инструкцию: '.$module['slug'], $match !== FALSE AND $match['slug'] === $module['slug']);
	$c->action_service();
	$html = $c->template->content->render();
	check('страница сервиса без предупреждений: '.$module['slug'], strpos($html, HTML::chars($module['title'])) !== FALSE);
}
foreach (['nj593_trends' => 'администратор', 'graph3d' => 'STL', 'multivoice' => 'vip', 'nj590_design' => 'вариант'] as $slug => $needle)
{
	$c->request->slug = $slug;
	$c->action_service();
	check('актуальная инструкция: '.$slug, mb_stripos($c->template->content->render(), $needle) !== FALSE);
}
$c->request->slug = 'does-not-exist';
try { $c->action_service(); check('несуществующий сервис → 404', FALSE); }
catch (HTTP_Exception $e) { check('несуществующий сервис → 404', $e->getCode() === 404); }
$init = file_get_contents(APPPATH.'modules/nj596_guide/init.php');
check('маршрут принимает слуги с дефисом', strpos($init, "'slug' => '[a-z0-9_-]+'") !== FALSE);
check('dev зарезервирован перед slug', strpos($init, "Route::set('nj596_dev'") < strpos($init, "Route::set('nj596_service'"));
$c->request->path = 'guide/dev';
check('dev-маршрут ведёт в документацию разработчика', Route::get('nj596_dev')->matches($c->request)['action'] === 'dev');
echo "ВСЁ ОК: $checks проверок\n";
