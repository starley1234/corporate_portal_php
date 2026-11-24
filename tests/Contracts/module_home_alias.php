<?php
if (!defined('SYSPATH')) define('SYSPATH',dirname(__DIR__,2).'/system/');
class Controller {public $request,$response;}
class HTTP_Exception extends RuntimeException {
 static function factory($code,$message='') {return new self($message,$code);}
 function allowed($methods) {return $this;}
}
class HTTP {static $target; static function redirect($target,$status) {self::$target=[$target,$status];}}
class Manifest {static $items=[]; static function all() {return self::$items;}}
class HomeRequest {
 public $id=218,$verb='GET',$query=[];
 function method() {return $this->verb;}
 function param($name) {return $this->id;}
 function query() {return $this->query;}
}
class HomeResponse {function headers($key,$value) {}}
require dirname(__DIR__,2).'/application/classes/Controller/ModuleHome.php';
$n=0;
function verifyHome($ok) {global $n; $n++; if (!$ok) throw new RuntimeException('Module alias regression');}
$controller=new Controller_ModuleHome; $controller->request=new HomeRequest; $controller->response=new HomeResponse;
Manifest::$items=[['id'=>218,'href'=>'/prx218_module_manager']];
$controller->action_index(); verifyHome(HTTP::$target===['/prx218_module_manager',302]);
$controller->request->query=['filter'=>'a&b'];
$controller->action_index(); verifyHome(HTTP::$target===['/prx218_module_manager?filter=a%26b',302]);
foreach (['','https://outside.test','//outside.test',"/\\outside.test", "/bad\r\nLocation: evil"] as $href) {
 Manifest::$items=[['id'=>218,'href'=>$href]];
 try {$controller->action_index(); throw new LogicException('Expected 404');}
 catch (HTTP_Exception $e) {verifyHome($e->getCode()===404);}
}
Manifest::$items=[];
try {$controller->action_index(); throw new LogicException('Expected missing 404');}
catch (HTTP_Exception $e) {verifyHome($e->getCode()===404);}
$controller->request->verb='POST';
try {$controller->action_index(); throw new LogicException('Expected 405');}
catch (HTTP_Exception $e) {verifyHome($e->getCode()===405);}
echo "Module home aliases: $n checks OK\n";
