<?php
/** Парсер/рендер smoke для новых страниц NJ-673..675 без фреймворка. */
if ( ! defined('SYSPATH')) { define('SYSPATH', '/app/'); }
class Controller_Erp100_Base {}
class View
{
    public static function factory($name, $data = []) { return new View_Stub(); }
}
class View_Stub { public function __toString() { return ''; } }
class HTML { public static function chars($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); } }

require '/app/application/modules/nj673_aiwatch/classes/Controller/Nj673/AiWatch.php';
require '/app/application/modules/nj674_mcpguard/classes/Controller/Nj674/McpGuard.php';
require '/app/application/modules/nj675_ragguard/classes/Controller/Nj675/RagGuard.php';
$manifests = [
    include '/app/application/modules/nj673_aiwatch/config/nj_module.php',
    include '/app/application/modules/nj674_mcpguard/config/nj_module.php',
    include '/app/application/modules/nj675_ragguard/config/nj_module.php',
];
if (array_column($manifests, 'id') !== [673, 674, 675]) { exit(1); }

$comparison = NULL; $runs = []; $csrf = '';
ob_start(); include '/app/application/modules/nj673_aiwatch/views/673/index.php'; $out = ob_get_clean();
if (strpos($out, 'История аудитов') === FALSE) { exit(1); }
$comparison = ['model'=>'model-a','endpoint'=>'https://example.test/v1','current_score'=>72,'score_delta'=>-4,'current_id'=>2,'previous_id'=>1,'current_fail'=>1,'fail_delta'=>1,'new_regressions'=>['LLM01'],'regression'=>TRUE];
ob_start(); include '/app/application/modules/nj673_aiwatch/views/673/index.php'; $out = ob_get_clean();
if (strpos($out, 'Есть признаки регрессии') === FALSE) { exit(1); }

$json = ''; $csrf = '';
$result = ['error'=>'','tool_count'=>1,'risk'=>'high','counts'=>['high'=>1,'medium'=>0,'low'=>0],'tools'=>[['name'=>'tool','findings'=>[['severity'=>'high','message'=>'test']]]],'scope'=>'no network'];
ob_start(); include '/app/application/modules/nj674_mcpguard/views/674/index.php'; $out = ob_get_clean();
if (strpos($out, 'Результат статической проверки') === FALSE) { exit(1); }

$json = ''; $csrf = '';
$result = ['error'=>'','document_count'=>1,'expected_tenant'=>'tenant-a','risk'=>'critical','counts'=>['critical'=>1,'high'=>0,'medium'=>0,'low'=>0],'documents'=>[['id'=>'doc','trusted'=>FALSE,'findings'=>[['severity'=>'critical','message'=>'test']]]],'scope'=>'no data'];
ob_start(); include '/app/application/modules/nj675_ragguard/views/675/index.php'; $out = ob_get_clean();
if (strpos($out, 'Результат проверки контекста') === FALSE) { exit(1); }

echo "NJ-673/674/675 controllers parsed; 3 views rendered\n";
