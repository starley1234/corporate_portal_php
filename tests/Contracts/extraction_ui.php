<?php
if (!defined('SYSPATH')) define('SYSPATH',dirname(__DIR__,2).'/system/');
require dirname(__DIR__,2).'/application/modules/prx395_document_extract/classes/Service/Prx395/Limits.php';
class Kohana { public static $config; }
Kohana::$config = new class {
 function load($name) { return $this; }
 function as_array() { return require dirname(__DIR__,2).'/application/modules/prx395_document_extract/config/prx395_document_extract.php'; }
};
putenv('PRX395_MAX_PDF_BYTES=12582912');
putenv('PRX395_MAX_PAGES=33');
putenv('PRX395_MAX_TEXT_BYTES=2097152');
putenv('PRX395_RESULT_TTL_HOURS=48');
class HTML {static function chars($value) {return htmlspecialchars((string) $value,ENT_QUOTES,'UTF-8');}}
class View {
 static function factory($name,$data=[]) {
  if ($name==='partials/page_header') return '<header>PDF extraction</header>';
  extract($data);
  ob_start();include dirname(__DIR__,2).'/application/modules/prx395_document_extract/views/'.$name.'.php';return ob_get_clean();
 }
}
function render395($job=NULL, $error=NULL) {
 $id=$job?42:0; $csrf='test-csrf'; $can_monitor=TRUE;
 $limits=Service_Prx395_Limits::all(); $rate_limit=60;
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
foreach (['12 МиБ', '33 страницы', 'текст до 2 МиБ', '48 часов', 'data-max-bytes="12582912"', '60 запросов в минуту'] as $visible)
 check395(str_contains($empty, $visible));
check395(str_contains(render395(NULL, 'file_too_large'), 'Файл больше 12 МиБ.'));
echo "Extraction UI: rendering and configured labels OK\n";
