<?php
/**
 * Дымовой рендер вьюх nj600 (owner / reco / widget) в PHP-in-WASM: ловит
 * runtime-ошибки и warning'и (неопределённые переменные/индексы), которые
 * синтаксический линт не видит. Данные — стабы, похожие на реальные.
 *
 *   N2J_ROOT=/home/user/n2j.ru node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj600_views_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
	throw new ErrorException($str, 0, $no, $file, $line);
});

class View_Exception extends Exception {}

class Kohana
{
	public static $config;
	public static $log;
	public static $charset = 'UTF-8';

	public static function find_file($dir, $file, $ext = 'php')
	{
		if (isset($file[0]) AND $file[0] === '/')
		{
			return is_file($file) ? $file : FALSE;
		}

		$path = '/app/application/modules/nj600_prefs/'.trim($dir, '/').'/'.$file.'.'.$ext;

		return is_file($path) ? $path : FALSE;
	}
}
Kohana::$log = new class { public function add($l, $m, $v = []) { } };

class Log { const WARNING = 3; }

class HTML
{
	public static function chars($value, $double_encode = TRUE)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $double_encode);
	}
}

class Date
{
	public static function formatted_time($ts, $f = 'Y-m-d H:i:s')
	{
		$t = strtotime((string) $ts);

		if ($t === FALSE)
		{
			throw new Exception('bad date '.$ts);
		}

		return date($f, $t);
	}
}

class Database_Stub { }
class Database { public static function instance() { return new Database_Stub; } }
class Visitor { public static function uid() { return 'u:7'; } }
class Service_Nj120_JobQueue
{
	public static function job_title_of($payload)
	{
		$p = json_decode((string) $payload, TRUE);

		return is_array($p) ? (string) ($p['query'] ?? '') : '';
	}
}

require '/app/system/classes/Kohana/View.php';
class View extends Kohana_View {}
require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

require '/app/application/modules/nj600_prefs/classes/Service/Nj600/Reco.php';

$ok = 0; $bad = 0;

function render($file, array $data)
{
	global $ok, $bad;

	try
	{
		$view = new View($file);
		$view->set($data);
		$out = $view->render();
		echo "ok   ".basename($file)." отрендерился (".strlen($out)." байт)\n";
		$ok++;

		return $out;
	}
	catch (Throwable $e)
	{
		$bad++;
		echo "ФЕЙЛ ".basename($file).": ".$e->getMessage()." @ ".basename($e->getFile()).":".$e->getLine()."\n";

		return '';
	}
}

function contains($label, $hay, $needle)
{
	global $ok, $bad;

	if (strpos($hay, $needle) !== FALSE) { $ok++; echo "ok   $label\n"; }
	else { $bad++; echo "ФЕЙЛ $label (нет '$needle')\n"; }
}

$catalog = [
	'vat'  => ['section' => 'vat',  'href' => '/vat',  'title' => 'Калькулятор НДС', 'short' => 'НДС в обе стороны', 'icon' => 'fas fa-percent', 'group' => 'Финансы'],
	'loan' => ['section' => 'loan', 'href' => '/loan', 'title' => 'Кредитный калькулятор', 'short' => '', 'icon' => 'fas fa-coins', 'group' => 'Финансы'],
	'jobs' => ['section' => 'jobs', 'href' => '/jobs', 'title' => 'Поиск вакансий', 'short' => '', 'icon' => 'fas fa-briefcase', 'group' => 'Работа'],
];

$reco = Service_Nj600_Reco::rank(['vat' => 10, 'home' => 3], $catalog,
	[['id' => 1, 'kind' => 'boost', 'when_section' => '', 'when_owner' => 'u:7', 'href' => '/jobs', 'title' => '', 'note' => 'вам <точно> надо', 'weight' => 90]],
	[['section' => 'loan', 'owners' => 4]], [], 8);

$profile = [
	'owner' => 'u:7', 'sections' => ['vat' => 10, 'home' => 3, 'unknown_sec' => 1],
	'phrases' => [['ндс 20%', 3], ['кредит & <ставка>', 1]], 'ref_hosts' => ['yandex.ru' => 2],
	'utm_last' => ['utm_source' => 'tg'], 'pages_total' => 14, 'searches_total' => 4,
	'first_seen' => '2026-10-01 10:00:00+00', 'last_seen' => '2026-10-24 09:30:00+00',
	'llm_summary' => "Бухгалтер малого бизнеса.\nИщет расчёты.", 'llm_at' => '2026-10-20 08:00:00+00', 'llm_model' => 'local::test',
];

$common = [
	'key'      => 'u:7',
	'profile'  => $profile,
	'events'   => [
		['id' => 1, 'section' => 'vat', 'action' => 'page', 'phrase' => '', 'ref_host' => 'yandex.ru', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'uri' => 'vat', 'created_at' => '2026-10-24 09:30:00+00'],
		['id' => 2, 'section' => '', 'action' => 'search', 'phrase' => 'ндс <20%>', 'ref_host' => '', 'utm_source' => 'tg', 'utm_medium' => '', 'utm_campaign' => 'oct', 'uri' => '/search/api', 'created_at' => '2026-10-24 09:31:00+00'],
	],
	'days'     => [date('Y-m-d') => 3, date('Y-m-d', strtotime('-2 days')) => 1],
	'catalog'  => $catalog,
	'reco'     => $reco,
	'reco_log' => [
		['id' => 5, 'href' => '/loan', 'source' => 'covisit', 'rule_id' => NULL, 'reason' => 'похожие', 'shown_at' => '2026-10-23 10:00:00+00', 'clicked_at' => '2026-10-23 10:01:00+00', 'dismissed_at' => NULL],
		['id' => 6, 'href' => '/jobs', 'source' => 'group', 'rule_id' => NULL, 'reason' => '', 'shown_at' => '2026-10-23 10:00:00+00', 'clicked_at' => NULL, 'dismissed_at' => '2026-10-23 11:00:00+00'],
		['id' => 7, 'href' => '/qr', 'source' => 'rule', 'rule_id' => 1, 'reason' => '', 'shown_at' => '2026-10-23 10:00:00+00', 'clicked_at' => NULL, 'dismissed_at' => NULL],
	],
	'rules'    => [['id' => 1, 'kind' => 'boost', 'when_section' => '', 'when_owner' => 'u:7', 'href' => '/jobs', 'title' => '', 'note' => '', 'weight' => 90, 'enabled' => 't']],
	'jobs'     => [['id' => 44, 'channel' => 'nj550.jobs', 'status' => 'done', 'payload' => '{"query":"инженер"}', 'error' => NULL, 'module' => 'nj550', 'created_at' => '2026-10-22 10:00:00+00', 'started_at' => NULL, 'finished_at' => NULL]],
	'logins'   => [['id' => 1, 'user_id' => 7, 'ip' => '10.0.0.1', 'ua' => 'x', 'session_id' => '', 'via' => 'password', 'created_at' => '2026-10-24 09:00:00+00']],
	'flash'    => 'Закреплено <ok>',
	'csrf'     => 'tok',
];

echo "--- owner.php (пользователь)\n";
$out = render('/app/application/modules/nj600_prefs/views/600/owner.php', $common + ['user' => ['id' => 7, 'username' => 'ivan <x>', 'email' => 'i@x.ru', 'logins' => 5, 'last_login' => '1760000000']]);
contains('экранирован username', $out, 'ivan &lt;x&gt;');
contains('есть кнопка «Карточка пользователя»', $out, '/nj595/admin/user/7');
contains('рекомендация с причиной', $out, 'лично для вас');
contains('кнопка уведомления есть у u:', $out, 'name="act" value="notify"');
contains('ИИ-резюме выведено', $out, 'Бухгалтер малого бизнеса');
contains('задача воркера подписана', $out, 'инженер');
contains('флеш экранирован', $out, 'Закреплено &lt;ok&gt;');
contains('входы показаны', $out, '10.0.0.1');
contains('epoch last_login отформатирован', $out, date('d.m.Y', 1760000000));

echo "--- owner.php (гость, пустой профиль, без флеша)\n";
$out = render('/app/application/modules/nj600_prefs/views/600/owner.php', array_merge($common, [
	'key' => 'g:abcdef123456', 'user' => NULL, 'profile' => NULL, 'events' => [], 'days' => [], 'reco' => [],
	'reco_log' => [], 'rules' => [], 'jobs' => [], 'logins' => [], 'flash' => '',
]));
contains('гость помечен', $out, 'гость (кука ubid)');
contains('пустое состояние рекомендаций', $out, 'Пока нечего предложить');
contains('кнопки уведомления у гостя нет', $out, 'name="act" value="pin"');
if (strpos($out, 'value="notify"') === FALSE) { $ok++; echo "ok   notify у гостя скрыт\n"; } else { $bad++; echo "ФЕЙЛ notify у гостя виден\n"; }

echo "--- reco.php\n";
$stats = [
	'days' => 30,
	'kpi'  => ['shown' => 12, 'clicked' => 3, 'dismissed' => 1, 'owners' => 5, 'ctr' => 25.0],
	'by_source' => [['source' => 'covisit', 'shown' => 8, 'clicked' => 2, 'dismissed' => 1, 'ctr' => 25.0], ['source' => 'rule', 'shown' => 4, 'clicked' => 1, 'dismissed' => 0, 'ctr' => 25.0]],
	'by_href'   => [['href' => '/loan', 'shown' => 8, 'clicked' => 2, 'dismissed' => 1, 'ctr' => 25.0], ['href' => '/elsewhere', 'shown' => 1, 'clicked' => 0, 'dismissed' => 0, 'ctr' => 0.0]],
	'recent'    => $common['reco_log'] + [],
	'rules_total' => 2,
];
foreach ($stats['recent'] as $i => $r) { $stats['recent'][$i]['owner_key'] = 'u:7'; }
$rules = [
	['id' => 1, 'kind' => 'boost', 'when_section' => 'vat', 'when_owner' => '', 'href' => '/loan', 'title' => 'Кредит', 'note' => 'рядом с <НДС>', 'weight' => 50, 'enabled' => 't', 'created_by' => 1, 'created_at' => '2026-10-20 00:00:00+00'],
	['id' => 2, 'kind' => 'block', 'when_section' => '', 'when_owner' => 'u:7', 'href' => '/jobs', 'title' => '', 'note' => '', 'weight' => 0, 'enabled' => 'f', 'created_by' => 1, 'created_at' => '2026-10-20 00:00:00+00'],
	['id' => 3, 'kind' => 'boost', 'when_section' => '', 'when_owner' => '', 'href' => '/qr', 'title' => '', 'note' => '', 'weight' => 10, 'enabled' => TRUE, 'created_by' => NULL, 'created_at' => '2026-10-20 00:00:00+00'],
];
$out = render('/app/application/modules/nj600_prefs/views/600/reco.php', ['stats' => $stats, 'rules' => $rules, 'catalog' => $catalog, 'flash' => '', 'csrf' => 'tok']);
contains('CTR выведен', $out, '25,0 %');
contains('выключенное правило приглушено', $out, 'opacity-50');
contains('адресное правило ссылается на владельца', $out, '/nj600/admin/owner/u%3A7');
contains('note экранирован', $out, 'рядом с &lt;НДС&gt;');
contains('сервис из каталога назван по-человечески', $out, 'Кредитный калькулятор');
contains('datalist с адресами', $out, 'id="rule-hrefs"');

echo "--- reco.php (пусто)\n";
$out = render('/app/application/modules/nj600_prefs/views/600/reco.php', ['stats' => ['days' => 30, 'kpi' => ['shown' => 0, 'clicked' => 0, 'dismissed' => 0, 'owners' => 0, 'ctr' => 0.0], 'by_source' => [], 'by_href' => [], 'recent' => [], 'rules_total' => 0], 'rules' => [], 'catalog' => [], 'flash' => '', 'csrf' => 'tok']);
contains('пустое состояние правил', $out, 'Правил нет');

echo "--- widget.php\n";
$items = $reco;
$items[0]['log_id'] = 77;
$out = render('/app/application/modules/nj600_prefs/views/600/widget.php', ['items' => $items, 'title' => 'Вам может пригодиться', 'csrf' => 'tok']);
contains('переход через /prefs/api/go', $out, '/prefs/api/go?id=77');
contains('второй элемент без log_id ведёт прямо', $out, 'href="/loan"');
contains('note правила экранирована', $out, 'вам &lt;точно&gt; надо');
contains('кнопка отказа', $out, 'data-nj600-dismiss="/jobs"');

echo "\nИтого: ok=$ok, провалов=$bad\n";
exit($bad ? 1 : 0);
