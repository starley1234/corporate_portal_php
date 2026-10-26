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
	$n2j_log_dir_error = $e->getMessage();
}

/**
 * Плоский ротируемый лог (application/logs/n2j-site.log) — его можно просто
 * прислать при разборе инцидента: одна строка на событие, без PHP-обёртки,
 * ротация по размеру. Настройки — N2J_LOG_* в `.env` (см. Log_Plain).
 */
try
{
	Kohana::$log->attach(new Log_Plain);

	if (isset($n2j_log_dir_error))
	{
		Kohana::$log->add(Log::WARNING, 'Каталог логов недоступен, PHP-лог ядра отключён: :e',
			[':e' => $n2j_log_dir_error]);
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
Kohana::modules([
	 'encrypt'    => MODPATH.'encrypt',    // Encryption supprt
	 'auth'       => MODPATH.'auth',       // Basic authentication
	 'cache'      => MODPATH.'cache',      // Caching with multiple backends
	 
	 'cookie_consent' => APPPATH.'modules/cookie_consent',
	 
	 'llm' => APPPATH.'modules/llm',
	 'erp100_base' => APPPATH.'modules/erp100_base',
	 'PostgreSQL' => APPPATH.'modules/PostgreSQL',
	 
	 'nj111_migrations' => APPPATH.'modules/nj111_migrations',
	 'nj100_platform' => APPPATH.'modules/nj100_platform',
	 'nj110_catalog' => APPPATH.'modules/nj110_catalog',
	 // erp208 — шина событий: её таблицы нужны миграциям nj120 (Notify_Bridge),
	 // поэтому подключаем ДО nj120_jobs (порядок модулей = порядок миграций).
	 'erp208_events' => APPPATH.'modules/erp208_events',
	 'nj120_jobs' => APPPATH.'modules/nj120_jobs',
	 'nj115_semantic' => APPPATH.'modules/nj115_semantic',
	 'nj130_agents' => APPPATH.'modules/nj130_agents',
	 'nj501_vat'    => APPPATH.'modules/nj501_vat',
	 'nj502_words'  => APPPATH.'modules/nj502_words',
	 'nj510_qr'     => APPPATH.'modules/nj510_qr',
	 'nj516_metrics' => APPPATH.'modules/nj516_metrics',
	 'nj520_poll'   => APPPATH.'modules/nj520_poll',
	 'nj530_jump'   => APPPATH.'modules/nj530_jump',
	 'nj540_reply'  => APPPATH.'modules/nj540_reply',
	 'nj550_jobs'   => APPPATH.'modules/nj550_jobs',
	 'nj160_mcp'    => APPPATH.'modules/nj160_mcp',
	 'nj560_knowledge' => APPPATH.'modules/nj560_knowledge',
	 'nj570_models' => APPPATH.'modules/nj570_models',
	 'nj580_orgdocs' => APPPATH.'modules/nj580_orgdocs',
	 'nj590_design' => APPPATH.'modules/nj590_design',
	 'nj591_company_profile' => APPPATH.'modules/nj591_company_profile',
	 'nj592_coldmail' => APPPATH.'modules/nj592_coldmail',
	 'nj593_trends' => APPPATH.'modules/nj593_trends',
	 'nj594_orgregistry' => APPPATH.'modules/nj594_orgregistry',
	 'nj595_useradmin' => APPPATH.'modules/nj595_useradmin',
	 'nj596_guide' => APPPATH.'modules/nj596_guide',
	 'nj597_notifications' => APPPATH.'modules/nj597_notifications',
	 'nj598_dossier' => APPPATH.'modules/nj598_dossier',
	 'nj599_account' => APPPATH.'modules/nj599_account',
	 'nj600_prefs' => APPPATH.'modules/nj600_prefs',
	 'nj601_billing' => APPPATH.'modules/nj601_billing',
	 'nj602_content' => APPPATH.'modules/nj602_content',
	 'nj603_kicker' => APPPATH.'modules/nj603_kicker',
	 'nj604_news' => APPPATH.'modules/nj604_news',
	 'nj605_radar' => APPPATH.'modules/nj605_radar',
	 'nj606_leads' => APPPATH.'modules/nj606_leads',
	 'nj607_industry' => APPPATH.'modules/nj607_industry',
	 'nj609_watchdog' => APPPATH.'modules/nj609_watchdog',
	 'nj610_inbox' => APPPATH.'modules/nj610_inbox',
	 'nj611_landing' => APPPATH.'modules/nj611_landing',
	 'nj612_touch' => APPPATH.'modules/nj612_touch',
	 'nj613_digest' => APPPATH.'modules/nj613_digest',
	 'nj614_json' => APPPATH.'modules/nj614_json',
	 'nj615_markdown' => APPPATH.'modules/nj615_markdown',
	 'nj616_convert' => APPPATH.'modules/nj616_convert',
	 'nj617_uuid' => APPPATH.'modules/nj617_uuid',
	 'nj618_hash' => APPPATH.'modules/nj618_hash',
	 'nj619_unixtime' => APPPATH.'modules/nj619_unixtime',
	 'nj620_jwt' => APPPATH.'modules/nj620_jwt',
	 'nj621_cron' => APPPATH.'modules/nj621_cron',
	 'nj622_regex' => APPPATH.'modules/nj622_regex',
	 'nj623_roman' => APPPATH.'modules/nj623_roman',
	 'nj624_base' => APPPATH.'modules/nj624_base',
	 'nj625_bytes' => APPPATH.'modules/nj625_bytes',
	 'nj626_unicode' => APPPATH.'modules/nj626_unicode',
	 'nj627_testdata' => APPPATH.'modules/nj627_testdata',
	 'nj628_checksum' => APPPATH.'modules/nj628_checksum',
	 'nj629_color' => APPPATH.'modules/nj629_color',
	 'nj630_ipcalc' => APPPATH.'modules/nj630_ipcalc',
	 'nj631_text' => APPPATH.'modules/nj631_text',
	 'nj632_csv' => APPPATH.'modules/nj632_csv',
	 'nj633_nettools' => APPPATH.'modules/nj633_nettools',
	 'nj634_pdf' => APPPATH.'modules/nj634_pdf',
	 'nj635_scout' => APPPATH.'modules/nj635_scout',
	 'nj636_pdfsign' => APPPATH.'modules/nj636_pdfsign',
	 'nj637_watermark' => APPPATH.'modules/nj637_watermark',
	 'nj638_pdfplan' => APPPATH.'modules/nj638_pdfplan',
	 'nj639_qc' => APPPATH.'modules/nj639_qc',
	 'nj640_cameras' => APPPATH.'modules/nj640_cameras',
	 'nj641_imagegen' => APPPATH.'modules/nj641_imagegen',
	 'nj642_vacancies' => APPPATH.'modules/nj642_vacancies',
	 'nj643_cad' => APPPATH.'modules/nj643_cad',
	 'nj644_military_pension' => APPPATH.'modules/nj644_military_pension',
	 'nj645_llmlab' => APPPATH.'modules/nj645_llmlab',
	 'nj646_label' => APPPATH.'modules/nj646_label',
	 'nj647_loan' => APPPATH.'modules/nj647_loan',
	 'nj648_timezone' => APPPATH.'modules/nj648_timezone',
	 'nj649_diff' => APPPATH.'modules/nj649_diff',
	 'nj650_receipt' => APPPATH.'modules/nj650_receipt',
	 'nj651_meal' => APPPATH.'modules/nj651_meal',
	 'nj652_plant' => APPPATH.'modules/nj652_plant',
	 'nj653_veclab' => APPPATH.'modules/nj653_veclab',
	 'nj654_similar' => APPPATH.'modules/nj654_similar',
	 'nj655_nav3d' => APPPATH.'modules/nj655_nav3d',
	 'nj656_meters' => APPPATH.'modules/nj656_meters',
	 'nj657_alice' => APPPATH.'modules/nj657_alice',
	 'nj658_geo' => APPPATH.'modules/nj658_geo',
	 'nj659_yandexauth' => APPPATH.'modules/nj659_yandexauth',
	 'nj660_arch' => APPPATH.'modules/nj660_arch',
	 'nj661_airadar' => APPPATH.'modules/nj661_airadar',
	 // erp222 — Кузница систем: миры синтеза и выпуск артефактов (спецификация,
	 // схема данных, API, промпт…). Читает находки ИИ-радара (nj661), поэтому
	 // подключается после него; своих внешних зависимостей у миграций нет.
	 'erp222_forge' => APPPATH.'modules/erp222_forge',
	 // nj662 — Диалог двух ИИ: порт наработок source_data/100_ai_symposium.
	 // Ход диалога ведёт серверная очередь (nj120 → воркер nj662.dialog), агенты
	 // умеют искать в интернете, материалы уходят в семантику (nj115).
	 'nj662_dialog' => APPPATH.'modules/nj662_dialog',
	 'nj663_favicon' => APPPATH.'modules/nj663_favicon',
	 // nj664 — Декодер JSON: экранированный, из URL или из HTML.
	 // Разбор целиком в браузере; сервер считает только вкладки PHP (print_r и код).
	 'nj664_jsondecode' => APPPATH.'modules/nj664_jsondecode',
	 // nj665 — Озвучивание текста: OmniVoice API v1 (POST /api/v1/audio/speech).
	 // Синтез синхронный (ответ сразу файлом), поэтому очереди и воркеров нет;
	 // адрес и ключ внешнего API — NJ665_API_URL / NJ665_API_KEY в .env.
	 'nj665_voice' => APPPATH.'modules/nj665_voice',
	 // nj666 — Многоголосая озвучка книги (/multivoice): текст → персонажи с
	 // голосами → блоки с логическим завершением → аудио. Разбор ведёт
	 // python-воркер через очередь nj120 (модель идёт по тексту окнами и
	 // держит список персонажей), синтез делает nj665: ключ внешнего API
	 // остаётся на сайте. Подключается после nj665 — иначе его сервис
	 // недоступен. 666 — следующий свободный номер модуля.
	 'nj666_multivoice' => APPPATH.'modules/nj666_multivoice',
	 // nj667 — Графовый 3D-конструктор (/graph3d): описание изделия →
	 // пространственный граф (узлы-детали, рёбра-связи) → 3D-холст на three.js
	 // с правкой руками, историей правок RFC 6902 и каталогом с модерацией.
	 // Сборку, саммари, вектор и пре-модерацию делает python-воркер через
	 // очередь nj120 (каналы nj667.*); холст (вендорный three.js в
	 // assets/vendor/three/mod) считает картинку из того же jsonb. 667 —
	 // следующий свободный номер модуля.
	 'nj667_graph3d' => APPPATH.'modules/nj667_graph3d',
	 // nj668 — Плоские иллюстрации (/draw2d): описание темы → векторная сцена
	 // (прямые, кривые Безье, цветные заливки) → SVG-холст с правкой руками
	 // и каталогом. Сборку делает python-воркер через очередь nj120
	 // (канал nj668.generate); картинку всегда можно пересчитать из jsonb.
	 // 668 — следующий свободный номер модуля.
	 'nj668_draw2d' => APPPATH.'modules/nj668_draw2d',
	 'nj669_solid3d' => APPPATH.'modules/nj669_solid3d',
	 // nj670 — ProcessHub (/process): текст регламента → онтологическая схема
	 // BPMN «План», журнал событий → «Факт», AI-аудит разрывов и регламент
	 // DOCX/PDF. Очередь nj120 (каналы nj670.*), холст — свой SVG-движок.
	 // Данные — только nj_670_*, воркеры ходят на /process/w/* по токену.
	 // 670 — следующий свободный номер модуля.
	 'nj670_processhub' => APPPATH.'modules/nj670_processhub',
	 // nj671 — Стенд эмбеддингов (/embedbench): один материал через два пути
	 // получить вектор (EMBEDDING_API_URL и EMBEDDING_MM_API_URL), метрики и
	 // готовый вывод «что брать для какой задачи». Аудитория admin, канал
	 // nj671.bench, данные — только nj_671_runs.
	 'nj671_embedbench' => APPPATH.'modules/nj671_embedbench',
	 // nj672 — Сканер безопасности ИИ-моделей (/aiscan): батарея атак по
	 // OWASP Top 10 for LLM и стресс-профиль (TTFT/ITL/TPS, колено KV-Cache)
	 // любого OpenAI-совместимого эндпоинта или встроенной песочницы.
	 // Аудитория admin (сканер шлёт атаки и нагрузку), канал nj672.scan,
	 // данные — только nj_672_runs, ключи целей в БД не пишутся.
	 'nj672_aiscan' => APPPATH.'modules/nj672_aiscan',
	 // nj673 — AI Watch (/aiwatch): сопоставление последовательных отчётов
	 // /aiscan без повторного скоринга и без копирования секретов/результатов.
	 'nj673_aiwatch' => APPPATH.'modules/nj673_aiwatch',
	 // nj674 — MCP Guard (/mcpguard): безсетевой статический разбор tools/list;
	 // исходные описания не сохраняются и не отправляются MCP-серверу.
	 'nj674_mcpguard' => APPPATH.'modules/nj674_mcpguard',
	 // nj675 — RAG Guard (/ragguard): безсетевой инспектор контекста с проверкой
	 // границы tenant и эвристиками инструкций/секретов; сырой текст не хранится.
	 'nj675_ragguard' => APPPATH.'modules/nj675_ragguard',
	 // nj676 — MCP Hub (/mcphub): публичный каталог; карточки не запускаются,
	 // репозитории не скачиваются, health-статусы пока только ручные.
	 'nj676_mcphub' => APPPATH.'modules/nj676_mcphub',
	 // nj677 — Разговор с ИИ голосом (/talk): одно нажатие включает микрофон,
	 // дальше конец фразы определяет тишина. Речь и синтез — через nj665_voice
	 // (единственный клиент OmniVoice), ответ модели — Llm::chat в HTTP, потому
	 // что человек ждёт ответа сейчас; сохраняется только текст, аудио — нет.
	 'nj677_talk' => APPPATH.'modules/nj677_talk',
	 'nj100_search' => APPPATH.'modules/nj100_search',
	 'nj509_password' => APPPATH.'modules/nj509_password',
	 'nj503_salary' => APPPATH.'modules/nj503_salary',
	 'nj504_vacation' => APPPATH.'modules/nj504_vacation',
	 'nj505_penalty' => APPPATH.'modules/nj505_penalty',
	 'nj506_units' => APPPATH.'modules/nj506_units',
	 'nj507_cooking' => APPPATH.'modules/nj507_cooking',
	 'nj508_calendar' => APPPATH.'modules/nj508_calendar',
	 'nj511_size' => APPPATH.'modules/nj511_size',
	 'nj513_datewords' => APPPATH.'modules/nj513_datewords',
	 'nj514_translit' => APPPATH.'modules/nj514_translit',
	 'nj517_inn' => APPPATH.'modules/nj517_inn',
	 'nj518_percent' => APPPATH.'modules/nj518_percent',
	 'erp212_file_storage' => APPPATH.'modules/erp212_file_storage',
	 'erp218_module_manager' => APPPATH.'modules/erp218_module_manager',
	 'erp118_links' => APPPATH.'modules/erp118_links',
	 
	// 'codebench'  => MODPATH.'codebench',  // Benchmarking tool
	
	 'minion'     => MODPATH.'minion',     // CLI Tasks
	 'database'   => MODPATH.'database',   // Database access
	// 'image'      => MODPATH.'image',      // Image manipulation
	 'orm'        => MODPATH.'orm',        // Object Relationship Mapping
	 'pagination' => MODPATH.'pagination', // Pagination
	// 'unittest'   => MODPATH.'unittest',   // Unit testing
	// 'userguide'  => MODPATH.'userguide',  // User guide and API documentation
	]);
	
	

	
	

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
 
