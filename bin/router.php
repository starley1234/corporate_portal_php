<?php
/**
 * Роутер для встроенного dev-сервера PHP (bin/serve).
 *
 * Правила:
 *   * существующий файл в public_html (картинка, css, js) — отдаётся как есть;
 *   * всё остальное — во фронт-контроллер public_html/index.php (Kohana).
 */

$root = __DIR__ . '/../public_html';

$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = $root . $uri;

// prx390: входящие PDF лежат в public_html/storage/docflow из-за прав
// хостинга, но наружу не отдаются — только через контроллер с ACL.
// В nginx/Apache это делает .htaccess или location deny; здесь — роутер.
if (preg_match('#^/storage/docflow(/|$)#', $uri)) {
    http_response_code(404);
    exit;
}

// Статика: только реальные файлы (не каталоги) вне «точек»
if ($uri !== '/' && is_file($file) && strpos($uri, '/.') === false) {
    return false; // встроенный сервер отдаст файл сам
}

// Каталожные запросы без index.php — тоже во фронт-контроллер
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
