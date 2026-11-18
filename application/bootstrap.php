<?php

// -- Environment setup --------------------------------------------------------

// Load the core Kohana class
require SYSPATH.'classes/Kohana/Core'.EXT;

if (is_file(APPPATH.'classes/Kohana'.EXT))
{
	// Application extends the core
	require APPPATH.'classes/Kohana'.EXT;
}
else
{
	// Load empty core extension
	require SYSPATH.'classes/Kohana'.EXT;
}

// Подключение автозагрузчика Composer.
// vendor/ живёт в корне проекта (bootstrap.php — в application/).
// DOCROOT (= public_html/) своего vendor не имеет — второй блок ниже удалён.
$composer_autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($composer_autoload))
{
	require $composer_autoload;
}

/**
 * Set the default time zone.
 *
 * @link http://kohanaframework.org/guide/using.configuration
 * @link http://www.php.net/manual/timezones
 */
date_default_timezone_set('Europe/Moscow');

/**
 * Set the default locale.
 *
 * @link http://kohanaframework.org/guide/using.configuration
 * @link http://www.php.net/manual/function.setlocale
 */
setlocale(LC_ALL, 'en_US.utf-8');

/**
 * Enable the Kohana auto-loader.
 *
 * @link http://kohanaframework.org/guide/using.autoloading
 * @link http://www.php.net/manual/function.spl-autoload-register
 */
spl_autoload_register(['Kohana', 'auto_load']);

/**
 * Optionally, you can enable a compatibility auto-loader for use with
 * older modules that have not been updated for PSR-0.
 *
 * It is recommended to not enable this unless absolutely necessary.
 */
//spl_autoload_register(array('Kohana', 'auto_load_lowercase'));

/**
 * Enable the Kohana auto-loader for unserialization.
 *
 * @link http://www.php.net/manual/function.spl-autoload-call
 * @link http://www.php.net/manual/var.configuration#unserialize-callback-func
 */
ini_set('unserialize_callback_func', 'spl_autoload_call');

/**
 * Set the mb_substitute_character to "none"
 *
 * @link http://www.php.net/manual/function.mb-substitute-character.php
 */
mb_substitute_character('none');

// -- Configuration and initialization -----------------------------------------

/**
 * Set the default language
 */
I18n::lang('ru-ru');

if (isset($_SERVER['SERVER_PROTOCOL']))
{
	// Replace the default protocol.
	HTTP::$protocol = $_SERVER['SERVER_PROTOCOL'];
}

/**
 * Set Kohana::$environment if a 'KOHANA_ENV' environment variable has been supplied.
 *
 * Note: If you supply an invalid environment name, a PHP warning will be thrown
 * saying "Couldn't find constant Kohana::<INVALID_ENV_NAME>"
 */
if (isset($_SERVER['KOHANA_ENV']))
{
	Kohana::$environment = constant('Kohana::'.strtoupper($_SERVER['KOHANA_ENV']));
}

/**
 * Initialize Kohana, setting the default options.
 *
 * The following options are available:
 *
 * - string   base_url    path, and optionally domain, of your application   NULL
 * - string   index_file  name of your index file, usually "index.php", if set to FALSE uses clean URLS     index.php
 * - string   charset     internal character set used for input and output   utf-8
 * - string   cache_dir   set the internal cache directory                   APPPATH/cache
 * - integer  cache_life  lifetime, in seconds, of items cached              60
 * - boolean  errors      enable or disable error handling                   TRUE
 * - boolean  profile     enable or disable internal profiling               TRUE
 * - boolean  caching     enable or disable internal caching                 FALSE
 * - boolean  expose      set the X-Powered-By header                        FALSE
 */
Kohana::init([
	'base_url'   => '/',
	'index_file' => FALSE, // Убираем index.php из генерации ссылок
	'profile' => FALSE,
	'caching' => FALSE,


]);


/**
 * Attach the file write to logging. Multiple writers are supported.
 *
 * Каталог логов создаётся `bin/setup.sh`, но на чужом хостинге его может не
 * быть — и тогда Kohana падала на bootstrap.php всем сайтом (500 на каждой
 * странице). Лог для сайта не критичен: нет каталога — нет файлового лога,
 * приложение работает.
 */
try
{
	Kohana::$log->attach(new Log_File(APPPATH.'logs'));
}
catch (Throwable $e)
{
	// Молча: писать пока некуда, покажем причину после подключения Log_Plain.
	$prx_log_dir_error = $e->getMessage();
}

/**
 * Плоский ротируемый лог (application/logs/prx-site.log) — его можно просто
 * прислать при разборе инцидента: одна строка на событие, без PHP-обёртки,
 * ротация по размеру. Настройки — PRX_LOG_* в `.env` (см. Log_Plain).
 */
try
{
	Kohana::$log->attach(new Log_Plain);

	if (isset($prx_log_dir_error))
	{
		Kohana::$log->add(Log::WARNING, 'Каталог логов недоступен, PHP-лог ядра отключён: :e',
			[':e' => $prx_log_dir_error]);
	}
}
catch (Throwable $e)
{
	// Лог не критичен для работы сайта.
}

/**
 * Attach a file reader to config. Multiple readers are supported.
 */
Kohana::$config->attach(new Config_File);

Cookie::$salt = Kohana::$config->load('cookie')->get('salt');


ini_set('session.save_path', APPPATH.'sessions');
// Session::$default = 'database';


/**
 * Enable modules. Modules are referenced by a relative or absolute path.
 */
// Старый документооборот (Входящие, Реестр, ИИ-анализ, Сроки): TRUE — стенд, FALSE — выключен.
define('PRX_DOCFLOW_ENABLED', FALSE);
Kohana::modules(require APPPATH.'config/modules.php');






/**
 * Cookie Salt
 * @see  http://kohanaframework.org/3.3/guide/kohana/cookies
 *
 * If you have not defined a cookie salt in your Cookie class then
 * uncomment the line below and define a preferrably long salt.
 */
// Cookie::$salt = NULL;
/**
 * Cookie HttpOnly directive
 * If set to true, disallows cookies to be accessed from JavaScript
 * @see https://en.wikipedia.org/wiki/Session_hijacking
 */
// Cookie::$httponly = TRUE;
/**
 * If website runs on secure protocol HTTPS, allows cookies only to be transmitted
 * via HTTPS.
 * Warning: HSTS must also be enabled in .htaccess, otherwise first request
 * to http://www.example.com will still reveal this cookie
 */
// Cookie::$secure = isset($_SERVER['HTTPS']) AND $_SERVER['HTTPS'] == 'on' ? TRUE : FALSE;

/**
 * Set the routes. Each route must have a minimum of a name, a URI and a set of
 * defaults for the URI.
 */

require_once APPPATH.'routes.php';

