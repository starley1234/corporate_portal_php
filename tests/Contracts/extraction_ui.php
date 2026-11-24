<?php
if (!defined('SYSPATH')) define('SYSPATH',dirname(__DIR__,2).'/system/');
class HTML {static function chars($value) {return htmlspecialchars((string) $value,ENT_QUOTES,'UTF-8');}}
class View {
 static function factory($name,$data=[]) {
  if ($name==='partials/page_header') return '<header>PDF extraction</header>';
  ob_start();include dirname(__DIR__,2).'/application/modules/prx395_document_extract/views/'.$name.'.php';return ob_get_clean();
 }
}
function render395($job=NULL) {
 $id=$job?42:0; $csrf='test-csrf'; $error=NULL; $can_monitor=TRUE;
 $progress=['elapsed_sec'=>12,'stage'=>'<stage>'];
 ob_start();include dirname(__DIR__,2).'/application/modules/prx395_document_extract/views/prx395/index.php';return ob_get_clean();
}
function check395($ok) {if (!$ok) throw new RuntimeException('Extraction UI contract failed');}
$empty=render395();
check395(substr_count($empty,'data-prx395-submit')===2);
check395(substr_count($empty,'value="test-csrf"')===2);
check395(strpos($empty,'name="sample" value="1"')!==FALSE);
check395(strpos($empty,'prx395-api-submit')!==FALSE);
$pending=render395(['status'=>'queued','attempt'=>0]);
check395(strpos($pending,'data-prx395-pending')!==FALSE);
check395(strpos($pending,'data-prx-stopwatch="12"')!==FALSE);
check395(strpos($pending,'&lt;stage&gt;')!==FALSE);
$done=render395(['status'=>'succeeded','attempt'=>1,'result'=>['text'=>'<script>alert(1)</script>','pages_total'=>1,'provider'=>'tesseract','format'=>'plain']]);
check395(strpos($done,'data-prx395-pending')===FALSE);
check395(strpos($done,'data-prx-stopwatch')===FALSE);
check395(strpos($done,'&lt;script&gt;alert(1)&lt;/script&gt;')!==FALSE);
check395(strpos($done,'<script>alert(1)</script>')===FALSE);
check395(strpos($done,'prx395-api-submit')!==FALSE);
echo "Extraction UI: 12 rendering checks OK\n";
