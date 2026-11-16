<?php

/**
 * Bootstrap тестового окружения.
 *
 * Повторяет подготовку из public_html/index.php (env -> константы путей),
 * но НЕ диспетчеризует запрос и НЕ запускает Minion: инициализацию ядра
 * выполняет application/bootstrap.php.
 */

$root = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR;

require $root . 'application/env.php';
prl_env_load($root);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

define('EXT', '.php');
define('DOCROOT', $root . 'public_html' . DIRECTORY_SEPARATOR);
define('APPPATH', $root . 'application' . DIRECTORY_SEPARATOR);
define('MODPATH', $root . 'modules' . DIRECTORY_SEPARATOR);
define('SYSPATH', $root . 'system' . DIRECTORY_SEPARATOR);

if ( ! defined('KOHANA_START_TIME')) {
    define('KOHANA_START_TIME', microtime(TRUE));
    define('KOHANA_START_MEMORY', memory_get_usage());
}

require APPPATH . 'bootstrap' . EXT;
