<?php
/** Worker POST bypasses session CSRF, but never bypasses worker authentication. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__,2).'/system/');
class Request { public function method() {return 'POST';} }
class Controller_Prx39x_Base {
 public $request, $response, $auto_render=TRUE, $answer;
 public function before() { if (!$this->machine_request()) throw new RuntimeException('CSRF blocked machine POST'); }
 protected function _json($data, $code=200) { $this->answer=[$code,$data]; }
}
class Prx_Gate {
 public static $valid=FALSE, $calls=0;
 public static function worker_request($request, $token) {self::$calls++; return self::$valid && $token==='test-only';}
}
require dirname(__DIR__,2).'/application/modules/prx390_intake/classes/Controller/Prx39x/Api.php';
class WorkerApiProbe extends Controller_Prx39x_Api {
 protected function _token_prefix() {return 'PRX392';}
 protected function _worker_token() {return $this->token;}
 public $token='';
 public function halted() {return $this->_halt;}
}
function verify($ok) {if (!$ok) throw new RuntimeException('worker API authentication regression');}
$api=new WorkerApiProbe; $api->request=new Request; $api->before();
verify($api->halted() && $api->answer[0]===403);
$api=new WorkerApiProbe; $api->request=new Request; $api->token='test-only'; $api->before();
verify($api->halted() && $api->answer[0]===403);
Prx_Gate::$valid=TRUE;
$api=new WorkerApiProbe; $api->request=new Request; $api->token='test-only'; $api->before();
verify(!$api->halted() && !$api->auto_render);
verify(Prx_Gate::$calls===2);
echo "Docflow worker POST authentication: 4 checks OK\n";
