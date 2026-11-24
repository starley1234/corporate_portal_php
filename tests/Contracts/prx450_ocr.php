<?php
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__,2).'/system/');
$root=dirname(__DIR__,2);
require $root.'/application/modules/prx150_api_hub/classes/Prx150/Error.php';
require $root.'/application/modules/prx450_document_ocr/classes/Prx450/Error.php';
require $root.'/application/modules/prx450_document_ocr/classes/Service/Prx450/Ocr.php';
$config=require $root.'/application/modules/prx450_document_ocr/config/prx450_document_ocr.php';
$n=0;
function verify450($ok) {global $n; $n++; if (!$ok) throw new RuntimeException('OCR contract mismatch #'.$n);}
function call450($method, ...$args) {return (new ReflectionMethod(Service_Prx450_Ocr::class,$method))->invoke(NULL,...$args);}
function error450($code,$status,callable $fn) {
 try {$fn();} catch (Prx450_Error $e) {verify450($e->getMessage()===$code && $e->getCode()===$status); return;}
 throw new RuntimeException('OCR error expected');
}
Service_Prx450_Ocr::assert_request_size(2097152,'2M');
Service_Prx450_Ocr::assert_request_size(PHP_INT_MAX,'0');
error450('file_too_large',413,fn()=>Service_Prx450_Ocr::assert_request_size(2097153,'2M'));
error450('file_too_large',413,fn()=>Service_Prx450_Ocr::assert_request_size(1025,'1K'));
foreach (['application/pdf'=>'%PDF-1.7', 'image/png'=>"\x89PNG\r\n\x1a\n", 'image/jpeg'=>"\xff\xd8\xff", 'image/tiff'=>"II\x2a\x00", 'image/webp'=>'RIFF0000WEBP', 'image/bmp'=>'BM00'] as $mime=>$bytes) {
 verify450(call450('_signature',$mime,$bytes));
 verify450(!call450('_signature',$mime,'not a document'));
}
$values=call450('_values',$config,['ocr'=>'false','force'=>'1','unknown'=>'secret','max_image_side'=>'512']);
verify450(!$values['ocr'] && $values['force'] && !isset($values['unknown']));
verify450(strpos(call450('_query',$values),'ocr=false')!==FALSE);
foreach ([['engine'=>[]],['engine'=>'bad'],['force'=>'maybe'],['max_image_side'=>8193],['max_image_side'=>'1.2'],['extra_config'=>'not json']] as $input)
 error450('invalid_option',422,fn()=>call450('_values',$config,$input));
$body=['file'=>'document.pdf','content'=>'Hello','cached'=>FALSE,'raw_result'=>['text'=>'abc']];
verify450(call450('_result',200,json_encode($body),$config,'md')['raw_result']===NULL);
verify450(call450('_result',200,json_encode($body),$config,'full')['raw_result']===['text'=>'abc']);
$config['limits']['max_raw_result_bytes']=3;
error450('result_too_large',502,fn()=>call450('_result',200,json_encode($body),$config,'full'));
$config['limits']['max_content_bytes']=3;
error450('result_too_large',502,fn()=>call450('_result',200,json_encode($body),$config,'md'));
foreach ([413=>['file_too_large',413],415=>['unsupported_file',415],401=>['ocr_auth',503],403=>['ocr_auth',503],422=>['ocr_rejected',422],500=>['ocr_upstream_error',502],302=>['ocr_invalid_response',502]] as $status=>$expected)
 error450($expected[0],$expected[1],fn()=>call450('_result',$status,'<html>private upstream error</html>',$config,'md'));
error450('ocr_invalid_response',502,fn()=>call450('_result',200,'not json',$config,'md'));
error450('file_required',400,fn()=>call450('_validate_upload',NULL,$config));
error450('file_too_large',413,fn()=>call450('_validate_upload',['error'=>UPLOAD_ERR_INI_SIZE],$config));
// Method errors precede key/rate checks and retain JSON + Allow + no-store.
class Request {const POST='POST'; function method() {return 'GET';}}
class OcrResponseProbe {
 public $values=[];
 function headers($key,$value) {$this->values[$key]=$value;}
}
class Controller_Prx150_Machine {
 public $request,$response,$answer;
 protected function respond(callable $fn): void {throw new LogicException('GET must not authenticate or invoke OCR');}
 protected function _json_response($data,$status) {$this->answer=[$data,$status];}
}
require $root.'/application/modules/prx450_document_ocr/classes/Controller/Prx450/Api.php';
$api=new Controller_Prx450_Api;
$api->request=new Request; $api->response=new OcrResponseProbe; $api->action_parse();
verify450($api->answer[1]===405 && $api->answer[0]['error']['code']==='method_not_allowed');
verify450($api->response->values===['Cache-Control'=>'no-store','Allow'=>'POST']);
echo "OCR contract: $n checks OK\n";
