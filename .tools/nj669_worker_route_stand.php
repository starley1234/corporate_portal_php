<?php
/** Диспетчеризация приёмной: настоящие Route, Internal Client, Controller и NJ120 API.
 * Не ограничивается matches(): before/action/after реально выполняются.
 * Подменены Request/Response, очередь и бизнес-приёмка; токен не обходится.
 */
$root=is_dir('/app/application') ? '/app' : dirname(__DIR__);
define('APPPATH',$root.'/application/');if(!defined('SYSPATH')){define('SYSPATH',$root.'/system/');}define('EXT','.php');
set_error_handler(function($level,$text,$file,$line){if(error_reporting() & $level){throw new ErrorException($text,0,$level,$file,$line);}});
class Kohana { public static $charset='UTF-8'; public static $profiling=FALSE; public static $log; }
class Log { const ERROR=4; public function add($level,$message,$params=[]){} }
Kohana::$log=new Log;
class Log_Plain { public static function event($level,$message,$params=[]){} }
// CFS, как на сайте: имя класса переводится в путь; модульный контроллер не require вручную.
spl_autoload_register(function($class){
	foreach([APPPATH,APPPATH.'modules/nj669_solid3d/',APPPATH.'modules/nj120_jobs/',SYSPATH] as $base){
		$file=$base.'classes/'.str_replace('_','/',$class).'.php';
		if(is_file($file)){require $file;return;}
	}
});
class Request
{
	public static $current; public static $initial; public static $client_ip='127.0.0.1';
	const POST='POST'; public $path; public $verb; public $token; public $params=[];
	public function __construct($path,$verb='POST',$token=''){
		$this->path=$path;$this->verb=$verb;$this->token=$token;
		foreach(Route::all() as $route){$params=$route->matches($this);if($params!==FALSE){$this->params=$params;break;}}
	}
	public function uri(){return $this->path;}
	public function method(){return $this->verb;}
	public function headers($key){return strtolower($key)==='x-worker-token' ? $this->token : '';}
	public function body(){return '{"revision_id":12,"task_id":12,"run_token":"task-token"}';}
	public function post(){return [];}
	public function directory(){return $this->params['directory']??'';}
	public function controller(){return $this->params['controller']??'';}
	public function action(){return $this->params['action']??'';}
}
class Response
{
	public $code=200; public $text=''; public $headers=[];
	public function status($value=NULL){if($value!==NULL){$this->code=$value;}return $this->code;}
	public function body($value=NULL){if($value!==NULL){$this->text=$value;}return $this->text;}
	public function headers($key,$value){$this->headers[$key]=$value;}
}
class Service_Nj120_JobQueue { public static function instance(){return new stdClass;} }
class Service_Nj669_Solid3d
{
	public static $calls=[]; public static $verified=[];
	public static function worker($body,$action){self::$calls[]=[$body,$action];return ['ok'=>TRUE,'action'=>$action];}
	public static function verify_worker($body,$action){self::$verified[]=[$body,$action];return ['ok'=>TRUE,'action'=>$action];}
}
class Service_Nj669_Export
{
	public static $calls=[];
	public static function worker($body,$action){self::$calls[]=[$body,$action];return ['ok'=>TRUE,'action'=>$action];}
}
class Service_Nj669_Assist extends Service_Nj669_Solid3d {}
// Старый NJ669 может уже занимать этот класс и имя маршрута. Его методы НЕ наши.
class Controller_Nj669_Worker extends Controller
{
	public function action_index(){throw new RuntimeException('Вызван чужой контроллер');}
}
require APPPATH.'modules/nj669_solid3d/init.php';
Route::set('nj669_worker','scenes/w/<action>')->defaults(['controller'=>'Nj669_Worker']);
require APPPATH.'routes.php';
$count=0;
function check($value,$note){global $count;$count++;if(!$value){throw new RuntimeException($note);}}
$client=new Request_Client_Internal;
foreach(['context','upload','result','fail','verify_context','verify_result','verify_fail','assist_context','assist_result','assist_fail','export_context','export_upload','export_result','export_fail'] as $action)
{
	foreach(['','wrong','worker-secret'] as $token)
	{
		putenv('WORKER_API_TOKEN=worker-secret');
		$request=new Request('solid3d/w/'.$action,'POST',$token);
		check($request->controller()==='Solid3dWorker' AND $request->directory()==='Nj669','непосредственно наш контроллер '.$action);
		$response=$client->execute_request($request,new Response);
		check($response->code===($token==='worker-secret' ? 200 : 403),'ожидаемый HTTP статус '.$action);
		check(($response->headers['Content-Type']??'')==='application/json; charset=UTF-8','JSON вместо HTML '.$action);
		$data=json_decode($response->text,TRUE);
		check(is_array($data) AND $data['ok']===($token==='worker-secret'),'защита токеном '.$action);
		if($token==='worker-secret'){check($data['action']===$action,'действие не подменено');}
	}
	$response=$client->execute_request(new Request('solid3d/w/'.$action,'GET','worker-secret'),new Response);
	check($response->code===405,'GET не выполняет приёмку');
}
check(count(Service_Nj669_Solid3d::$calls)===7,'неавторизованные запросы не дошли до бизнес-данных');
check(count(Service_Nj669_Export::$calls)===4,'export_* уходят в приёмку STL, а не в версии');
check(array_column(Service_Nj669_Export::$calls,1)===['export_context','export_upload','export_result','export_fail'],'export_* по порядку');
check(count(Service_Nj669_Solid3d::$verified)===3,'verify_* уходят в verify_worker, а не в общую приёмку');
check(array_column(Service_Nj669_Solid3d::$verified,1)===['verify_context','verify_result','verify_fail'],'verify_* по порядку');
putenv('WORKER_API_TOKEN=');
$response=$client->execute_request(new Request('solid3d/w/context','POST','worker-secret'),new Response);
check($response->code===403,'пустой серверный токен закрывает API');
echo $count." проверок полной диспетчеризации приёмной — ОК\n";
