<?php
/** nj510_qr: живой рендер и контракт без БД. --json: настройки; --html: вьюха. */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');
$root = dirname(__DIR__);
$module = $root.'/application/modules/nj510_qr';

class HTML {
    public static function chars($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
class View {
    private $name;
    private $data;
    public function __construct($name, array $data) { $this->name = $name; $this->data = $data; }
    public static function factory($name, array $data = []) { return new self($name, $data); }
    public function __toString()
    {
        extract($this->data);
        ob_start();
        require dirname(__DIR__).'/application/modules/nj510_qr/views/'.$this->name.'.php';
        return ob_get_clean();
    }
}
class Route {
    public static $routes = [];
    private $name;
    public function __construct($name) { $this->name = $name; }
    public static function set($name, $uri) { self::$routes[$name] = ['uri' => $uri]; return new self($name); }
    public function defaults(array $data) { self::$routes[$this->name]['defaults'] = $data; return $this; }
}
class Nj510_StandRequest {
    private $query;
    public function __construct(array $query = []) { $this->query = $query; }
    public function query($key) { return $this->query[$key] ?? NULL; }
}
class Controller_Erp100_Base {
    public $template;
    public $request;
    public $title = '';
    public $description = '';
    public function __construct(array $query = []) { $this->template = new stdClass(); $this->request = new Nj510_StandRequest($query); }
    public function before() {}
    public function set_title($title) { $this->title = $title; }
    public function set_description($description) { $this->description = $description; }
}
require $module.'/classes/Service/Nj510/Qr.php';
require $module.'/classes/Controller/Nj510/Qr.php';
require $module.'/init.php';
$settings = Service_Nj510_Qr::settings();
$mode = $argv[1] ?? '';
if ($mode === '--json') {
    echo json_encode($settings, JSON_UNESCAPED_UNICODE);
    exit;
}

// Любой warning в вьюхе — провал, а не незаметная строка в HTML.
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$controller = new Controller_Nj510_Qr();
$controller->before();
$controller->action_index();
$html = (string) $controller->template->content;
if ($mode === '--html') {
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<title>'.HTML::chars($controller->title).'</title><link rel="stylesheet" href="/assets/css/app.css">'
        .'<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css"></head><body class="bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">'
        .'<header class="flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4 dark:border-gray-800 dark:bg-gray-900">'
        .'<a href="/qr" class="text-xl font-extrabold text-blue-600">N2J<span class="text-sm font-medium text-gray-400">.ru</span></a>'
        .'<span class="text-xs text-gray-500">Полезные инструменты · QR-коды</span></header><div id="page-wrapper">'
        .$html.'</div></body></html>';
    exit;
}
function nj510_check($condition, $name) {
    if ( ! $condition) throw new RuntimeException('ПРОВАЛ: '.$name);
    echo '✓ '.$name."\n";
}
nj510_check(Route::$routes['nj510_qr_page']['uri'] === 'qr', 'Короткий маршрут /qr');
$manifest = require $module.'/config/nj_module.php';
nj510_check($manifest['auth'] === FALSE && $manifest['pin'] === TRUE, 'Сервис публичный и закреплён в меню');
nj510_check($manifest['api'] === [], 'Нет API, принимающего содержимое кодов');
nj510_check($settings['limits']['batch'] === 200 && $settings['defaults']['margin'] >= 4, 'Лимит пакета и безопасное поле');
nj510_check(Service_Nj510_Qr::initial_state([], []) === ['mode' => 'single', 'type' => 'url'], 'Параметры-массивы не ломают страницу');
nj510_check(strpos($html, 'qr-batch-text') !== FALSE && strpos($html, 'qr-logo-file') !== FALSE, 'Форма пакета и логотипа отрендерена');
preg_match('/<script id="qr-settings" type="application\/json">(.*?)<\/script>/s', $html, $json);
nj510_check(json_decode($json[1] ?? '', TRUE) === $settings, 'Настройки PHP отданы в JS без потерь');
foreach (['single', 'batch'] as $selected_mode) {
    foreach (array_keys($settings['types']) as $type) {
        $page = new Controller_Nj510_Qr(['mode' => $selected_mode, 'type' => $type]);
        $page->before(); $page->action_index();
        nj510_check(strpos((string) $page->template->content, 'data-type="'.$type.'"') !== FALSE,
            'Рендер '.$selected_mode.' / '.$type.' без предупреждений');
    }
}
echo "nj510_qr: стенд пройден.\n";
