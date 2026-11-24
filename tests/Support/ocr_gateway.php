<?php
// Local-only fake gateway used by OcrGatewayIntegrationTest.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)==='/large') {echo str_repeat('x',2048); return;}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)==='/redirect') {header('Location: /parse',TRUE,302); return;}
header('Content-Type: application/json');
$file=$_FILES['file'] ?? [];
echo json_encode(['file'=>$file['name'] ?? '', 'content'=>'# Recognized', 'cached'=>FALSE,
 'engine_used'=>$_GET['engine'] ?? NULL, 'raw_result'=>['method'=>$_SERVER['REQUEST_METHOD'],
 'mime'=>$file['type'] ?? '', 'body'=>isset($file['tmp_name']) ? file_get_contents($file['tmp_name']) : '']]);
