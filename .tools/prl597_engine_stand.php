<?php
/**
 * Стенд ядра уведомлений prl597 (шаблоны, лимиты, планирование, разбор ботов).
 *
 * Исторически центр уведомлений умел только «событие → колокольчик → письмо».
 * С появлением реестра событий, матрицы каналов, шаблонов, лимитов, тихих
 * часов и привязки мессенджеров логика стала не тривиальной, и ошибку в ней
 * видно только на проде: письмо уйдёт дважды, Telegram пришлёт «Array»,
 * ночью разбудят, а лимит не сработает. Здесь проверяем то, что можно
 * проверить без базы: чистые функции и разборщики.
 *
 * Запуск: PRL_ROOT=/path/to/prl.ru node phpwasm_stand.mjs .tools/prl597_engine_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', dirname(__DIR__).'/system/');
}

// ---------------------------------------------------------------- заглушки

class Kohana_Config_Group
{
	protected $data;
	public function __construct(array $data) { $this->data = $data; }
	public function as_array() { return $this->data; }
	public function get($key, $default = NULL) { return array_key_exists($key, $this->data) ? $this->data[$key] : $default; }
}

class Kohana
{
	public static $log;
	public static $config;
	public static $caching = FALSE;
}

class Log
{
	const DEBUG = 1;
	const INFO  = 2;
	const WARNING = 3;
	const ERROR = 4;
}

class Log_Plain
{
	public function add($level, $message, array $values = NULL) { return $this; }
	public function write($file) { return TRUE; }
}

Kohana::$log = new Log_Plain;

Kohana::$config = new class {
	public function load($name)
	{
		// config/prl597_notifications.php — настоящий файл модуля
		$path = dirname(__DIR__).'/application/modules/'.$name.'/config/'.$name.'.php';

		if ( ! is_file($path))
		{
			$path = dirname(__DIR__).'/application/config/'.$name.'.php';
		}

		$data = is_file($path) ? (array) require $path : [];

		return new Kohana_Config_Group($data);
	}
};

class Arr
{
	public static function get($array, $key, $default = NULL)
	{
		if ( ! is_array($array)) return $default;

		return array_key_exists($key, $array) ? $array[$key] : $default;
	}

	public static function path($array, $path, $default = NULL, $delimiter = NULL)
	{
		if ( ! is_array($array)) return $default;

		if (is_array($path))
		{
			$parts = $path;
		}
		else
		{
			$parts = explode($delimiter === NULL ? '.' : $delimiter, trim((string) $path));
		}

		foreach ($parts as $part)
		{
			if ( ! is_array($array) OR ! array_key_exists($part, $array))
			{
				return $default;
			}

			$array = $array[$part];
		}

		return $array;
	}
}

class HTML
{
	public static function chars($value, $double_encode = TRUE)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $double_encode);
	}
}

class ACL
{
	public static $abilities = ['prl601.admin' => TRUE];

	public static function check(array $roles, $permission)
	{
		return ! empty(self::$abilities[$permission]);
	}
}

class Database
{
	const SELECT = 0;
	const INSERT = 1;
	const UPDATE = 2;
	const DELETE = 3;

	public static $db = NULL;
	public static function instance()
	{
		if (self::$db !== NULL) { return self::$db; }
		throw new RuntimeException('База в стенде не нужна: чистые функции');
	}
}

// Настоящий код модуля.
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Base.php';
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Render.php';
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Limits.php';
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Channel.php';
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Channel/Email.php';
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Channel/Telegram.php';
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Channel/Max.php';
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Channel/Inapp.php';
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Registry.php';
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Bind.php';
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Engine.php';
// ИИ-помощник по шаблонам (пункт «составление шаблона с помощью ИИ»):
require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Ai.php';

// ---------------------------------------------------------------- проверки
$ok  = 0;
$bad = 0;

function check($label, $got, $want)
{
	global $ok, $bad;
	$g = is_bool($got) ? ($got ? 'true' : 'false') : (is_array($got) ? json_encode($got, JSON_UNESCAPED_UNICODE) : (string) $got);
	$w = is_bool($want) ? ($want ? 'true' : 'false') : (is_array($want) ? json_encode($want, JSON_UNESCAPED_UNICODE) : (string) $want);

	if ($g === $w)
	{
		$ok++;
		echo "ok   $label\n";
	}
	else
	{
		$bad++;
		echo "ФЕЙЛ $label\n     получили: $g\n     ожидалось: $w\n";
	}
}

function check_true($label, $got)   { check($label, (bool) $got, TRUE); }
function check_false($label, $got)  { check($label, (bool) $got, FALSE); }

/** Вызвать protected/private static-метод (чистые помощники без базы). */
function call_hidden($class, $method, array $args = [])
{
	$ref = new ReflectionMethod($class, $method);
	$ref->setAccessible(TRUE);

	return $ref->invokeArgs(NULL, $args);
}

echo "-- шаблоны и подстановка --\n";

check('render: подстановка',
	Service_Prl597_Render::render('Здравствуйте, {{user_name}}!', ['user_name' => 'Иван']),
	'Здравствуйте, Иван!');

check('render: html экранирует значение',
	Service_Prl597_Render::render('<p>{{x}}</p>', ['x' => '<script>alert(1)</script>'], 'html'),
	'<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');

check('render: md экранирует значение',
	Service_Prl597_Render::render('*{{x}}*', ['x' => '*важно*'], 'md'),
	'*\*важно\**');

check('render: мессенджер вырезает теги',
	Service_Prl597_Render::render('{{x}}', ['x' => '<b>жирно</b>'], 'text'),
	'жирно');

check('render: булево значение по-русски',
	Service_Prl597_Render::render('Письма: {{on}}', ['on' => FALSE]),
	'Письма: нет');

check('render: массив превращается в JSON',
	Service_Prl597_Render::render('{{list}}', ['list' => ['a' => 1]]),
	'{"a":1}');

check('render: strtr не переподставляет',
	Service_Prl597_Render::render('{{a}} {{b}}', ['a' => '{{b}}', 'b' => 'X']),
	'{{b}} X');

check('render: пустой шаблон', Service_Prl597_Render::render('', ['a' => 1]), '');

check('placeholders: список ключей',
	Service_Prl597_Render::placeholders('{{user_name}} и {{ amount }} и {{user_name}}'),
	['user_name', 'amount']);

check('placeholders: пусто', Service_Prl597_Render::placeholders('без подстановок'), []);

check('html_to_text: списки в дефисы',
	(bool) preg_match('~• один~', Service_Prl597_Render::html_to_text('<p>Привет</p><ul><li>один</li><li>два</li></ul>')),
	TRUE);

check('html_to_text: тегов не осталось',
	mb_strpos(Service_Prl597_Render::html_to_text('<p>Привет</p>'), '<p>'),
	FALSE);

check('text_to_html: экранирование и перевод строки',
	Service_Prl597_Render::text_to_html("a < b\nb"),
	"a &lt; b<br />\nb");

check('clip: короче лимита не трогаем', Service_Prl597_Render::clip('коротко', 100), 'коротко');
check('clip: длинное режем с многоточием', mb_strlen(Service_Prl597_Render::clip(str_repeat('я', 100), 10)), 10);

echo "\n-- глобальные подстановки --\n";

$globals = Service_Prl597_Render::with_globals([], 0);

check('with_globals: ссылка на центр', (bool) preg_match('~/prl597$~', $globals['inbox_url']), TRUE);
check('with_globals: есть дата', (bool) preg_match('~^\d{2}\.\d{2}\.\d{4}$~', $globals['date']), TRUE);
check('with_globals: свои значения не затёрты', Service_Prl597_Render::with_globals(['site_url' => 'https://x'], 0)['site_url'], 'https://x');

echo "\n-- лимиты --\n";

check('ведро пользователя', (bool) preg_match('~^u:12:email:h:\d{4}-\d{2}-\d{2}T\d{2}$~', Service_Prl597_Limits::bucket_user(12, 'email', 'h')), TRUE);
check('ведро платформы', (bool) preg_match('~^g:telegram:h:\d{4}~', Service_Prl597_Limits::bucket_global('telegram', 'h')), TRUE);
check('ведро события', (bool) preg_match('~^ue:7:mail\.x:email:d:\d{4}~', Service_Prl597_Limits::bucket_event(7, 'email', 'mail.x', 'd')), TRUE);
check('ведра разные у разных окон', Service_Prl597_Limits::bucket_user(12, 'email', 'h') !== Service_Prl597_Limits::bucket_user(12, 'email', 'd'), TRUE);

$left = Service_Prl597_Limits::window_left('h');
check('до конца часа — в пределах часа', ($left > 0 AND $left <= 3600), TRUE);

check('лимит из конфига: писем в час', Service_Prl597_Limits::limit('per_user_hour', 'email'), 6);
check('лимит из конфига: внутренних в час', Service_Prl597_Limits::limit('per_user_hour', 'in_app'), 60);
check('лимит неизвестного канала', Service_Prl597_Limits::limit('per_user_hour', 'sms'), 0);

echo "\n-- дедупликация --\n";

$k1 = Service_Prl597_Limits::dedup_key('mail.new', ['entity_id' => 42], '');
$k2 = Service_Prl597_Limits::dedup_key('mail.new', ['entity_id' => 42], '');
$k3 = Service_Prl597_Limits::dedup_key('mail.new', ['entity_id' => 43], '');

check('dedup: одинаковое событие — один ключ', $k1 === $k2, TRUE);
check('dedup: другая сущность — другой ключ', $k1 === $k3, FALSE);
check('dedup: явный ключ важнее', Service_Prl597_Limits::dedup_key('mail.new', ['entity_id' => 42], 'my-key'),
	Service_Prl597_Limits::dedup_key('mail.new', ['entity_id' => 43], 'my-key'));
check('dedup: без сущности ключа нет', Service_Prl597_Limits::dedup_key('mail.new', [], ''), '');
check('dedup: окно из конфига', Service_Prl597_Limits::dedup_window(), 300);

echo "\n-- тихие часы --\n";

$hour_now = Service_Prl597_Limits::local_hour(['tz' => 'Europe/Moscow']);
$prefs_day = ['quiet_from' => $hour_now, 'quiet_to' => ($hour_now + 23) % 24, 'tz' => 'Europe/Moscow', 'digest_mode' => 'off'];

check('тихие часы: мессенджер молчит', Service_Prl597_Limits::quiet_now($prefs_day, 'telegram', 5), TRUE);
check('тихие часы: колокольчик не молчит', Service_Prl597_Limits::quiet_now($prefs_day, 'in_app', 5), FALSE);
check('тихие часы: критичное не ждёт', Service_Prl597_Limits::quiet_now($prefs_day, 'telegram', 1), FALSE);
check('тихие часы: одинаковые границы выключают паузу', Service_Prl597_Limits::quiet_now(['quiet_from' => $hour_now, 'quiet_to' => $hour_now, 'tz' => 'Europe/Moscow'], 'telegram', 5), FALSE);
check('тихие часы: -1 берёт умолчания', Service_Prl597_Limits::quiet_bounds(['quiet_from' => -1, 'quiet_to' => -1]), [22, 8]);
check('тихие часы: границы из настроек', Service_Prl597_Limits::quiet_bounds(['quiet_from' => 23, 'quiet_to' => 7]), [23, 7]);
check('тихие часы: границы из конфига', Service_Prl597_Limits::quiet_bounds([]), [22, 8]);
check('тихие часы: woke-up в будущем', Service_Prl597_Limits::next_wakeup($prefs_day, 'telegram') > time(), TRUE);
check('тихие часы: без настроек user — по платформе', Service_Prl597_Limits::next_wakeup(['quiet_from' => -1]) > time(), TRUE);

echo "\n-- склейка в дайджест --\n";

check('дайджест выключен', Service_Prl597_Limits::digest_deadline(['digest_mode' => 'off']), 0);
check('дайджест: критичное сразу', Service_Prl597_Limits::digest_deadline(['digest_mode' => 'daily'], ['digest' => TRUE], 1), 0);
check('дайджест: событие не для склейки', Service_Prl597_Limits::digest_deadline(['digest_mode' => 'daily'], ['digest' => FALSE], 5), 0);

$deadline = Service_Prl597_Limits::digest_deadline(['digest_mode' => 'daily', 'tz' => 'Europe/Moscow'], ['digest' => TRUE], 5);
check('дайджест: суточный — в пределах суток', ($deadline > time() AND $deadline <= time() + 86400), TRUE);

$hourly = Service_Prl597_Limits::digest_deadline(['digest_mode' => 'hourly', 'tz' => 'Europe/Moscow'], ['digest' => TRUE], 5);
check('дайджест: часовой — в пределах двух часов', ($hourly > time() AND $hourly <= time() + 7200), TRUE);

// Шкала одна: 1 — критично, 5 — обычное, 9 — фоновое (владелец, 16.10.2026).
check('дайджест: шкала — обычное копится', Service_Prl597_Limits::digest_deadline(['digest_mode' => 'daily'], ['digest' => TRUE], 5) > 0, TRUE);
check('дайджест: шкала — фоновое копится', Service_Prl597_Limits::digest_deadline(['digest_mode' => 'daily'], ['digest' => TRUE], 9) > 0, TRUE);
check('дайджест: шкала — важнее обычного уходит сразу', Service_Prl597_Limits::digest_deadline(['digest_mode' => 'daily'], ['digest' => TRUE], 4), 0);
$digest_cfg = Kohana::$config->load('prl597_notifications')->get('digest', []);
check('дайджест: режим по умолчанию — раз в сутки', (string) Arr::get($digest_cfg, 'default', ''), 'daily');
check('дайджест: сводка — умолчание для новых пользователей', Service_Prl597_Limits::digest_deadline(['digest_mode' => Arr::get($digest_cfg, 'default', 'off'), 'tz' => 'Europe/Moscow'], ['digest' => TRUE], 5) > 0, TRUE);
$prio_cfg = (array) Kohana::$config->load('prl597_notifications')->as_array();
check('приоритет: склейка с обычного и вниз', (int) Arr::path($prio_cfg, 'priority.digest_from', 0), 5);
check('приоритет: тихие часы пропускают важное', (int) Arr::path($prio_cfg, 'priority.quiet_skip', 0), 1);
check('приоритет: одна шкала на все механизмы', (int) Arr::path($prio_cfg, 'priority.digest_from', 0) > (int) Arr::path($prio_cfg, 'priority.quiet_skip', 0), TRUE);

echo "\n-- реестр событий --\n";

check('роли: событие для админов — обычному нет', Service_Prl597_Registry::user_allowed(['roles' => ['admin'], 'ability' => ''], ['login']), FALSE);
check('роли: событие для админов — админу да', Service_Prl597_Registry::user_allowed(['roles' => ['admin'], 'ability' => ''], ['login', 'admin']), TRUE);
check('роли: чужое событие не положено', Service_Prl597_Registry::user_allowed(['roles' => ['manager']], ['login']), FALSE);
check('роли: событие для всех вошедших', Service_Prl597_Registry::user_allowed(['roles' => ['login']], ['login']), TRUE);
check('право: без права не положено', Service_Prl597_Registry::user_allowed(['roles' => ['login'], 'ability' => 'prl601.other'], ['login']), FALSE);
check('право: с правом положено', Service_Prl597_Registry::user_allowed(['roles' => ['login'], 'ability' => 'prl601.admin'], ['login']), TRUE);
check('категория: подпись', Service_Prl597_Registry::category_label('jobs'), 'Задачи и отчёты');
check('категория: незнакомая', Service_Prl597_Registry::category_label('unknown'), 'Разное');
check('алиас события: нормализация', call_hidden('Service_Prl597_Registry', '_alias', ['PRL580.Documents Ready!']), 'prl580.documentsready');

echo "\n-- каналы --\n";

check('канал: имя из класса', Service_Prl597_Channel_Email::id(), 'email');
check('канал: имя Telegram', Service_Prl597_Channel_Telegram::id(), 'telegram');
check('канал: имя MAX', Service_Prl597_Channel_Max::id(), 'max');
check('канал: подпись из конфига', Service_Prl597_Channel_Email::label(), 'Электронная почта');
check('канал: привязка нужна почте', Service_Prl597_Channel_Email::needs_binding(), TRUE);
check('канал: привязка не нужна колокольчику', Service_Prl597_Channel_Inapp::needs_binding(), FALSE);
check('канал: почта без DSN выключена', Service_Prl597_Channel_Email::enabled(), FALSE);
check('канал: Telegram без токена выключен', Service_Prl597_Channel_Telegram::enabled(), FALSE);
check('канал: MAX без токена выключен', Service_Prl597_Channel_Max::enabled(), FALSE);
check('канал: колокольчик всегда включён', Service_Prl597_Channel_Inapp::enabled(), TRUE);
check('канал: MAX драйвер по умолчанию — бот', Service_Prl597_Channel_Max::driver(), 'bot');

putenv('TELEGRAM_BOT_TOKEN=123:ABC');
check('канал: Telegram включается токеном', Service_Prl597_Channel_Telegram::enabled(), TRUE);
putenv('TELEGRAM_BOT_TOKEN');

$channels = Service_Prl597_Registry::channels(FALSE);
check('каналы: четыре в реестре', count($channels), 4);
check('каналы: тихие', array_values(array_keys(array_filter($channels, function ($c) { return ! empty($c['quiet']); }))), ['email', 'telegram', 'max']);

echo "\n-- привязка: разбор обновлений бота --\n";

$tg_update = ['update_id' => 100, 'message' => ['chat' => ['id' => 555001], 'from' => ['username' => 'ivan'], 'text' => '/start K7m2x9']];
$max_update = ['update_type' => 'message_created', 'message' => ['recipient' => ['chat_id' => 777, 'user_id' => 42], 'body' => ['text' => '/start Qw3rty']]];

check('telegram: текст', call_hidden('Service_Prl597_Bind', '_update_text', [$tg_update]), '/start K7m2x9');
check('telegram: чат', call_hidden('Service_Prl597_Bind', '_update_chat', [$tg_update]), '555001');
check('telegram: автор', call_hidden('Service_Prl597_Bind', '_update_user', [$tg_update]), 'ivan');
check('max: текст', call_hidden('Service_Prl597_Bind', '_update_text', [$max_update]), '/start Qw3rty');
check('max: чат', call_hidden('Service_Prl597_Bind', '_update_chat', [$max_update]), '777');

check('код из /start', call_hidden('Service_Prl597_Bind', '_code_from_text', ['/start K7m2x9']), 'k7m2x9');
check('код из /start с упоминанием бота', call_hidden('Service_Prl597_Bind', '_code_from_text', ['/start@prl_bot K7m2x9']), 'k7m2x9');
check('код без команды', call_hidden('Service_Prl597_Bind', '_code_from_text', ['K7m2x9']), 'k7m2x9');
check('кода нет', call_hidden('Service_Prl597_Bind', '_code_from_text', ['привет, как дела?']), '');

check('ссылка на бота Telegram', Service_Prl597_Bind::bot_url('telegram', 'abc'), '');
putenv('TELEGRAM_BOT_NICK=prl_notify_bot');
check('ссылка на бота Telegram с ником', Service_Prl597_Bind::bot_url('telegram', 'ABC'), 'https://t.me/prl_notify_bot?start=ABC');
putenv('TELEGRAM_BOT_NICK');

echo "\n-- фасад: разбор события --\n";

// Notify тянет за собой Kohana::$log и ORM — подключаем последним, с заглушками.
class Auth
{
	public static $user = NULL;
	private static $instance = NULL;
	public static function instance() { return self::$instance !== NULL ? self::$instance : self::$instance = new self; }
	public function logged_in($role = NULL) { return self::$user !== NULL; }
	public function get_user() { return self::$user; }
}

class ORM
{
	public static $users = [];
	public static function factory($model, $id = NULL)
	{
		return new class($id) {
			public $id; public $email;
			public function __construct($id)
			{
				$this->id = $id;
				// Подпись получателя: почта аккаунта (в бою её отдаёт Model_User).
				$this->email = isset(ORM::$users[$id]) ? ORM::$users[$id] : '';
			}
			public function loaded() { return isset(ORM::$users[$this->id]); }
			public function where($column, $op, $value) { $this->email = $value; return $this; }
			public function find() { $this->id = array_search($this->email, ORM::$users, TRUE); return $this; }
			// Подпись получателя в очереди: имя из аккаунта + почта (см. Engine::user_label).
			public function __get($name)
			{
				if ($name === 'username') { return 'user'.$this->id; }
				if ($name === 'email') { return isset(ORM::$users[$this->id]) ? ORM::$users[$this->id] : ''; }
				if ($name === 'profile') { return new class { public function loaded() { return FALSE; } }; }
				return NULL;
			}
		};
	}
}

require dirname(__DIR__).'/application/modules/prl597_notifications/classes/Service/Prl597/Notify.php';

check('kind_event: готовая задача', Service_Prl597_Notify::kind_event('job_done'), 'prl120.job.done');
check('kind_event: ошибка задачи', Service_Prl597_Notify::kind_event('job_failed'), 'prl120.job.failed');
check('kind_event: unknown', Service_Prl597_Notify::kind_event('Что-то Новое!'), 'system.message');
check('kind_event: системное', Service_Prl597_Notify::kind_event('system'), 'system.message');

check('алиас события: из фасада', call_hidden('Service_Prl597_Notify', '_event_alias', ['  PRL580.DOCUMENTS.ready  ']), 'prl580.documents.ready');

$data = call_hidden('Service_Prl597_Notify', '_template_data', [[
	'user_id'  => 7,
	'title'    => 'Заголовок',
	'body'     => 'Текст',
	'href'     => '/docs/1',
	'code'     => 'abc123',
	'data'     => ['pages' => 12],
]]);

check('данные шаблона: служебное выкинуто', isset($data['user_id']) OR isset($data['title']), FALSE);
check('данные шаблона: скаляр подхвачен', $data['code'], 'abc123');
check('данные шаблона: data на месте', $data['pages'], 12);

Auth::$user = (object) ['id' => 5];

check('получатели: один', call_hidden('Service_Prl597_Notify', '_recipients', [['user_id' => 7], []]), [7]);
check('получатели: список', call_hidden('Service_Prl597_Notify', '_recipients', [['user_ids' => [7, 8, 7]], []]), [7, 8]);
check('получатели: инициатор исключён', call_hidden('Service_Prl597_Notify', '_recipients', [['user_ids' => [5, 7]], []]), [7]);
check('получатели: force_self оставляет', call_hidden('Service_Prl597_Notify', '_recipients', [['user_ids' => [5, 7], 'force_self' => TRUE], []]), [5, 7]);
check('получатели: никого', call_hidden('Service_Prl597_Notify', '_recipients', [[], []]), []);

check('ссылка: внутренняя пропущена', Service_Prl597_Notify::safe_href('/docs/abc123'), '/docs/abc123');
check('ссылка: мусор заменён', Service_Prl597_Notify::safe_href('javascript:alert(1)'), '/prl597');
check('ссылка: пусто заменено', Service_Prl597_Notify::safe_href(''), '/prl597');

echo "\n-- сводка: склейка строк --\n";

$group = ['event_alias' => 'test.group', 'title' => 'Иван оценил пост', 'group_key' => 'post:1:likes'];
$plain = ['event_alias' => 'test.plain', 'title' => 'Обычное событие'];

// Тема письма — «одна мысль» (владелец, 16.10.2026): без служебных приставок,
// без переводов строк, до 60 знаков — столько видно в списке писем.
echo "\n-- тема письма: одна мысль --\n";

check('тема: короткая остаётся как есть', Service_Prl597_Render::subject_line('Обзор готов: Отчёт за квартал'), 'Обзор готов: Отчёт за квартал');
check('тема: приставка «Уведомление» убрана', Service_Prl597_Render::subject_line('Уведомление: сборка готова'), 'сборка готова');
check('тема: имя площадки в теме не дублируется', Service_Prl597_Render::subject_line('PRL.ru: сборка готова'), 'сборка готова');
check('тема: «Событие:» тоже убрано', Service_Prl597_Render::subject_line('Событие — сборка готова'), 'сборка готова');
check('тема: переводы строк схлопнуты', Service_Prl597_Render::subject_line("Готово\n\nвторая строка"), 'Готово вторая строка');
check('тема: лишние пробелы убраны', Service_Prl597_Render::subject_line('  Много    пробелов  '), 'Много пробелов');
check('тема: длинная обрезана', mb_strlen(Service_Prl597_Render::subject_line(str_repeat('очень длинное название ', 6))), 60);
check('тема: обрезка помечена многоточием', mb_substr(Service_Prl597_Render::subject_line(str_repeat('слово ', 40)), -1), '…');
check('тема: без приставки не режем зря', mb_strlen(Service_Prl597_Render::subject_line('Короткая тема')), 13);
check('тема канала: правило применяется', call_hidden('Service_Prl597_Channel_Email', '_subject', [['subject' => 'Уведомление: оплата прошла', 'body' => 'Тело']]), 'оплата прошла');

check('подпись канала из сообщения', call_hidden('Service_Prl597_Channel_Email', '_subject', [['subject' => 'Тема', 'body' => 'Тело']]), 'Тема');
check('подпись из первой строки', call_hidden('Service_Prl597_Channel_Email', '_subject', [['subject' => '', 'body' => "Первая строка\nВторая"]]), 'Первая строка');

require dirname(__DIR__).'/.tools/prl597_queue_cases.php';
require dirname(__DIR__).'/.tools/prl597_settings_cases.php';
require dirname(__DIR__).'/.tools/prl597_admin_cases.php';

// --- итог -----------------------------------------------------------------
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
