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

// В старом public_html/storage могут оставаться файлы до переноса. Закрываем
// весь каталог, но сохраняем виртуальный маршрут /storage/get/<id> prx212:
// его всегда обрабатывает контроллер, даже если совпал реальный путь на диске.
$storage_download = (bool) preg_match('#^/storage/get/[^/]+/?$#', $uri);
if (preg_match('#^/storage(/|$)#', $uri) && !$storage_download) {
    http_response_code(404);
    exit;
}

// Статика: только реальные файлы (не каталоги) вне «точек»
if (!$storage_download && $uri !== '/' && is_file($file) && strpos($uri, '/.') === false) {
    return false; // встроенный сервер отдаст файл сам
}

// Каталожные запросы без index.php — тоже во фронт-контроллер
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
