<?php
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
require dirname(__DIR__, 2).'/application/classes/Prx/ModuleNavigation.php';
$manifests = [
 ['id'=>450, 'href'=>'/prx450_document_ocr'], ['id'=>150, 'href'=>'/prx150_api_hub'],
 ['id'=>103, 'href'=>'/admin', 'nav_paths'=>['/prx100/logs']],
 ['id'=>391, 'href'=>'/prx391_documents'], ['id'=>392, 'href'=>'/analysis'],
];
$items = array_map(fn($href)=>['href'=>$href], ['/prx450_document_ocr','/prx150_api_hub','/prx100/logs','https://outside.test','#help']);
$result = Prx_ModuleNavigation::local_items($items, 'Prx450_Page', $manifests);
if (array_column($result,'href') !== ['/prx450_document_ocr','#help']) throw new RuntimeException('Foreign chips leaked');
$result = Prx_ModuleNavigation::local_items([['href'=>'/analysis'],['href'=>'/prx391_documents']], 'Prx391_Documents', $manifests);
if (array_column($result,'href') !== ['/prx391_documents']) throw new RuntimeException('Cross docflow chip leaked');
echo "Module navigation: 2 checks OK\n";
