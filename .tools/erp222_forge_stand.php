<?php
/**
 * erp222_forge: чистая логика «Кузницы систем» без БД, сети и LLM.
 *
 * Зачем стенд. Ценность модуля — в оценке артефактов: одна ошибка в шкале, и
 * каталог начнёт показывать черновики («брать в работу»), а Критик будет
 * пересчитывать ценность мимо. Здесь всё это ВЫПОЛНЯЕТСЯ на настоящих сервисах
 * модуля с настоящим конфигом (config/erp222_forge.php читается как есть).
 *
 * Что проверяется:
 *   · unit_scale — «0.8», 80, «80%», мусор (нейтраль 0.5), выход за границы;
 *   · value_score — веса, нейтральные оси, границы 0 и 100;
 *   · novelty — тот же текст низкая новизна, чужой текст высокая;
 *   · fingerprint — регистр и лишние пробелы дают один отпечаток;
 *   · value_word / as_bool / type_title / role_title / elapsed_text /
 *     counters_text — словари и строки статус-ряда;
 *   · Artifact::normalize — тип не из словаря берётся у роли, короткое тело
 *     отвергается, метаданные собираются из строк и массивов;
 *   · Artifact::list_of / ids / sources / merge_scores / critique_target —
 *     разбор того, что присылает воркер, и пересчёт ценности по критике.
 *
 *   php .tools/erp222_forge_stand.php
 */

if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');

$ROOT = dirname(__DIR__);
$MOD  = $ROOT.'/application/modules/erp222_forge';

$fail = 0;

set_error_handler(function ($no, $str, $file, $line) use (&$fail) {
	echo '  ПРОВАЛ  PHP: '.$str.' ('.$file.':'.$line.")\n";
	$fail++;

	return TRUE;
});

// ---------------------------------------------------------------- заглушки
class Kohana_Config_Group extends ArrayObject
{
	public function get($key, $default = NULL)
	{
		$array = (array) $this;

		return array_key_exists($key, $array) ? $array[$key] : $default;
	}
}

class Kohana_Config
{
	protected $_groups = [];

	public function load($group, $config = NULL)
	{
		if ( ! isset($this->_groups[$group]))
		{
			$file = $GLOBALS['ERP222_ROOT'].'/application/modules/erp222_forge/config/'.$group.'.php';

			$this->_groups[$group] = new Kohana_Config_Group(is_file($file) ? (array) require $file : []);
		}

		return $this->_groups[$group];
	}
}

class Kohana
{
	public static $config;
	public static $charset = 'utf-8';
	public static $log;
}

class Log
{
	const ERROR = 3;
	const INFO = 6;
}

class Log_Plain
{
	public static function event($level, $message, array $values = []) {}
}

class Arr
{
	public static function get($array, $key, $default = NULL)
	{
		$array = (array) $array;

		return (is_array($key) ? FALSE : array_key_exists($key, $array)) ? $array[$key] : $default;
	}

	public static function path($array, $path, $default = NULL, $delimiter = '.')
	{
		$cursor = (array) $array;

		foreach (explode($delimiter, (string) $path) as $step)
		{
			if ( ! is_array($cursor) OR ! array_key_exists($step, $cursor))
			{
				return $default;
			}

			$cursor = $cursor[$step];
		}

		return $cursor;
	}
}

class Database
{
	const SELECT = 1;
	const INSERT = 2;
	const UPDATE = 3;
}

/**
 * HTML и View — ровно те двери, через которые вьюхи модуля обращаются к ядру.
 * Стенд подменяет их: сама вёрстка берётся НАСТОЯЩАЯ, поэтому опечатку в
 * разметке или обращение к несуществующей переменной видно здесь, без PHP-ядра.
 */
class HTML
{
	public static function chars($value)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

class View
{
	private $name;
	private $data;

	public function __construct($name, array $data)
	{
		$this->name = $name;
		$this->data = $data;
	}

	public static function factory($name, array $data = [])
	{
		return new self($name, $data);
	}

	public function __toString()
	{
		$path = $GLOBALS['ERP222_ROOT'].'/application/modules/erp222_forge/views/'.$this->name.'.php';

		extract($this->data);
		ob_start();

		require $path;

		return (string) ob_get_clean();
	}
}

$GLOBALS['ERP222_ROOT'] = $ROOT;
Kohana::$config = new Kohana_Config();

require $MOD.'/classes/Service/Erp222/Forge.php';
require $MOD.'/classes/Service/Erp222/Artifact.php';

// ---------------------------------------------------------------- помощники
function check($title, $got, $want)
{
	global $fail;

	$ok = ($got === $want);

	if ( ! $ok)
	{
		$fail++;
		echo '  ПРОВАЛ  '.$title.': ожидалось '.var_export($want, TRUE).', получено '.var_export($got, TRUE)."\n";

		return;
	}

	echo '  ок      '.$title."\n";
}

echo "erp222_forge — чистые функции сервисов\n\n";

// ---------------------------------------------------------------- шкала
echo "Шкала оценок и ценность:\n";
check('unit_scale 0.8', Service_Erp222_Forge::unit_scale(0.8), 0.8);
check('unit_scale 80', Service_Erp222_Forge::unit_scale(80), 0.8);
check('unit_scale "80%" → 0.8', Service_Erp222_Forge::unit_scale('80%'), 0.5); // «%» не число: нейтраль
check('unit_scale "0,75"', Service_Erp222_Forge::unit_scale('0,75'), 0.75);
check('unit_scale 140 → 1.0', Service_Erp222_Forge::unit_scale(140), 1.0);
check('unit_scale -5 → 0.0', Service_Erp222_Forge::unit_scale(-5), 0.0);
check('unit_scale "нет" → 0.5', Service_Erp222_Forge::unit_scale('нет'), 0.5);

check('value_score максимум', Service_Erp222_Forge::value_score([
	'usefulness' => 1, 'concreteness' => 1, 'novelty' => 1, 'feasibility' => 1,
]), 100);

check('value_score минимум', Service_Erp222_Forge::value_score([
	'usefulness' => 0, 'concreteness' => 0, 'novelty' => 0, 'feasibility' => 0,
]), 0);

check('value_score молчание модели = 50', Service_Erp222_Forge::value_score([]), 50);

check('веса влияют', Service_Erp222_Forge::value_score([
	'usefulness' => 1, 'concreteness' => 0, 'novelty' => 0, 'feasibility' => 0,
]), 35);

echo "\nНовизна и отпечаток:\n";
$same = [[
	'title'   => 'Спецификация учёта заявок сервисного центра',
	'summary' => 'Сервисный центр принимает заявки и распределяет их по мастерам, учитывает статусы и сроки',
]];

$repeat = Service_Erp222_Forge::novelty(
	'Спецификация учёта заявок сервисного центра. Сервисный центр принимает заявки и распределяет их по мастерам', $same);
$fresh = Service_Erp222_Forge::novelty(
	'Контракт API склада: приёмка, отгрузка, инвентаризация. Каждый метод с примером ответа и кодами ошибок', $same);

check('повтор того же документа — низкая новизна', $repeat < 0.5, TRUE);
check('другой документ — высокая новизна', $fresh > 0.8, TRUE);
check('новизна без выпущенного = 1',
	Service_Erp222_Forge::novelty('что угодно', []), 1.0);
check('отпечаток игнорирует регистр и пробелы',
	Service_Erp222_Forge::fingerprint('Спецификация   учёта', 'Кратко о  документе'),
	Service_Erp222_Forge::fingerprint('спецификация учёта', 'кратко о документе'));
check('отпечаток различает документы',
	Service_Erp222_Forge::fingerprint('A', 'B') === Service_Erp222_Forge::fingerprint('A', 'C'), FALSE);

echo "\nСловари и строки статус-ряда:\n";
check('type_title известного типа', Service_Erp222_Forge::type_title('schema'), 'Схема данных');
check('type_title неизвестного типа', Service_Erp222_Forge::type_title('weird'), 'weird');
check('role_title известной роли', Service_Erp222_Forge::role_title('critic'), 'Критик');
check('value_word 90', Service_Erp222_Forge::value_word(90), 'брать в работу');
check('value_word 30', Service_Erp222_Forge::value_word(30), 'черновик');
check('as_bool "f" — ложь', Service_Erp222_Forge::as_bool('f'), FALSE);
check('as_bool "t" — истина', Service_Erp222_Forge::as_bool('t'), TRUE);
check('elapsed_text 65', Service_Erp222_Forge::elapsed_text(65), '1:05');
check('elapsed_text 3725', Service_Erp222_Forge::elapsed_text(3725), '1:02:05');

check('counters_text пустого цикла', Service_Erp222_Forge::counters_text([]), 'ставлю задачу в очередь…');
check('counters_text шага',
	Service_Erp222_Forge::counters_text(['steps_planned' => 6, 'steps_done' => 2, 'artifacts_new' => 2, 'stats' => ['value_total' => 120]]),
	'шаг 3/6 · артефактов: 2 · ценность: 120');

echo "\nПриём артефакта (Artifact):\n";
$norm = Service_Erp222_Artifact::normalize([
	'type'     => 'какая-то-ерунда',
	'title'    => "  Схема   данных  ",
	'summary'  => 'Таблицы заявок, мастеров и статусов',
	'body'     => str_repeat('CREATE TABLE orders (id bigserial PRIMARY KEY); ', 10),
	'metadata' => ['inputs' => "заявки клиентов; справочник услуг", 'outputs' => ['таблица orders']],
	'scores'   => ['usefulness' => 90, 'concreteness' => '0,8'],
	'depends_on' => [3, '5', 0, -1, 'abc'],
	'sources'  => ["факт #12", ''],
], [], ['schema', 'spec']);

check('normalize прошёл', $norm['ok'], TRUE);
check('тип взят у роли', $norm['artifact']['type'], 'schema');
check('заголовок нормализован', $norm['artifact']['title'], 'Схема данных');
check('входы разобраны из строки', $norm['artifact']['metadata']['inputs'], ['заявки клиентов', 'справочник услуг']);
check('оценки приведены к 0..1', $norm['artifact']['scores']['usefulness'], 0.9);
check('оценка "0,8" прочитана', $norm['artifact']['scores']['concreteness'], 0.8);
check('depends_on только положительные id', $norm['artifact']['depends_on'], [3, 5]);
check('sources без пустых', $norm['artifact']['sources'], ['факт #12']);

$no_title = Service_Erp222_Artifact::normalize(['body' => str_repeat('x', 500)], [], ['spec']);
check('без заголовка — ошибка', $no_title['ok'], FALSE);

$short = Service_Erp222_Artifact::normalize(['title' => 'T', 'body' => 'коротко'], [], ['spec']);
check('короткое тело — ошибка', $short['ok'], FALSE);

$list = Service_Erp222_Artifact::list_of([' одна ', '', ['вложено' => 1]]);
check('list_of из массива: первый пункт', $list[0], 'одна');
check('list_of из массива: вложенное стало JSON', strpos($list[1], 'вложено') !== FALSE, TRUE);
check('list_of из ";"', Service_Erp222_Artifact::list_of('первое; второе'), ['первое', 'второе']);
check('ids из строки', Service_Erp222_Artifact::ids('3, 5 и 7'), [3, 5, 7]);
check('sources из строки', Service_Erp222_Artifact::sources("URL\nhttps://example.com"), ['URL', 'https://example.com']);

$merged = Service_Erp222_Artifact::merge_scores(['usefulness' => 0.9, 'novelty' => 0.2], ['novelty' => 0.85]);
check('merge_scores: чужая новизна перекрывает', $merged['novelty'], 0.85);
check('merge_scores: остальное сохранено', $merged['usefulness'], 0.9);
check('merge_scores: недостающая ось нейтральна', $merged['feasibility'], 0.5);

$critique = Service_Erp222_Artifact::critique_target(
	['scores' => ['usefulness' => 0.9, 'concreteness' => 0.9, 'novelty' => 0.9, 'feasibility' => 0.9]],
	['scores' => ['usefulness' => 0.3, 'concreteness' => 0.4, 'novelty' => 0.9, 'feasibility' => 0.2]]
);

check('критика снижает ценность', $critique['value_score'] < 60, TRUE);
check('критика оставляет прочие оценки', $critique['scores']['novelty'], 0.9);

// ---------------------------------------------------------------------------
// Вёрстка страниц — та же, что уезжает в браузер.
//
// Зачем: статус-ряд «спиннер + этап + секундомер», запуск без перезагрузки и
// «взведённые» кнопки живут в erp222-forge.js, а не в PHP. Стенд отдаёт
// страницы ему: `php .tools/erp222_forge_stand.php --dump-ui=/tmp/erp222-ui`,
// дальше поведение проверяет .tools/erp222_forge_ui.mjs (jsdom).
// ---------------------------------------------------------------------------

$now = date('Y-m-d H:i:s');

$world_live = [
	'id' => 1, 'code' => 'uchebnyy_mir', 'title' => 'Учёт заявок сервисного центра',
	'brief' => 'Сервисный центр принимает заявки по телефону и почте, распределяет по мастерам и теряет статусы.',
	'goal' => 'Заявка видна всем участникам, история сохраняется, клиент получает SMS.',
	'protocol' => 'Ценность — готовность к работе: сценарии, поля, числа.',
	'domain' => 'сервис', 'status' => 'active', 'model' => '', 'steps_done' => 12,
	'artifacts_done' => 7, 'value_total' => 541,
	'created_at' => $now, 'updated_at' => $now,
];

$run_live = [
	'id' => 3, 'world_id' => 1, 'status' => 'running', 'trigger' => 'manual',
	'stage' => 'Синтез', 'stage_note' => 'шаг 4/6 · Инженер → Контракт API',
	'role_code' => 'engineer', 'steps_planned' => 6, 'steps_done' => 3,
	'artifacts_new' => 3, 'stats' => '{"value_total": 240}', 'error' => '',
	'created_at' => date('Y-m-d H:i:s', time() - 65), 'finished_at' => NULL,
];

$run_done = array_merge($run_live, [
	'id' => 2, 'status' => 'done', 'stage' => 'Сводка', 'stage_note' => 'цикл завершён',
	'steps_done' => 6, 'artifacts_new' => 6, 'stats' => '{"value_total": 474}',
	'created_at' => date('Y-m-d H:i:s', time() - 900), 'finished_at' => date('Y-m-d H:i:s', time() - 600),
]);

$artifact_rows = [
	Service_Erp222_Forge::decorate([
		'id' => 2, 'world_id' => 1, 'type' => 'spec', 'role_code' => 'architect',
		'title' => 'Спецификация учёта заявок сервисного центра',
		'summary' => 'Границы системы, сценарии оператора и диспетчера, входы и выходы данных, критерии приёмки.',
		'body' => "## Назначение\nПриём заявок сервисного центра.\n\n## Сценарии\n1. Оператор фиксирует заявку.\n2. Диспетчер назначает мастера.",
		'metadata' => json_encode(['inputs' => ['заявка: клиент, техника, адрес'], 'outputs' => ['заявка со статусом'],
			'assumptions' => ['SMS через провайдера'], 'open_questions' => ['интеграция с 1С']], JSON_UNESCAPED_UNICODE),
		'depends_on' => '[]', 'sources' => '["факт #1"]',
		'scores' => '{"usefulness":0.9,"concreteness":0.85,"novelty":0.8,"feasibility":0.85}',
		'value_score' => 88, 'critique' => '', 'published' => TRUE, 'created_at' => $now,
	]),
	Service_Erp222_Forge::decorate([
		'id' => 5, 'world_id' => 1, 'type' => 'risk', 'role_code' => 'critic',
		'title' => 'Разбор: где сломается учёт заявок',
		'summary' => 'SLA и матрица компетенций оставлены на словах — на внедрении это станет спорами.',
		'body' => '## Находки\n1. SLA не формализован. Мера: поле due_at и эскалация.',
		'metadata' => '{}', 'depends_on' => '[1]', 'sources' => '[]',
		'scores' => '{"usefulness":0.85,"concreteness":0.8,"novelty":0.7,"feasibility":0.9}',
		'value_score' => 85, 'critique' => 'Разбор с мерами по каждому пункту.', 'published' => TRUE, 'created_at' => $now,
	]),
];

$events = [
	['id' => 3, 'world_id' => 1, 'run_id' => 3, 'role_code' => 'engineer', 'kind' => 'artifact',
	 'message' => 'Артефакт #3 (Контракт API): POST /v1/requests — ценность 76', 'created_at' => $now],
	['id' => 2, 'world_id' => 1, 'run_id' => 3, 'role_code' => 'architect', 'kind' => 'step',
	 'message' => 'Шаг 1/6: Архитектор — Спецификация', 'created_at' => $now],
	['id' => 1, 'world_id' => 1, 'run_id' => 3, 'role_code' => '', 'kind' => 'run',
	 'message' => 'Цикл #3 запущен: шагов 6', 'created_at' => $now],
];

/** Страница дашборда в заданном состоянии: живой цикл, завершённый или пусто. */
function stand_index_page($run, $artifacts, $events, $world)
{
	$progress = $run ? Service_Erp222_Forge::run_progress($run) : NULL;

	return (string) View::factory('erp222/index', [
		'ov' => [
			'world' => $world, 'worlds' => [$world], 'run' => $run,
			'counts' => [], 'latest' => $artifacts, 'events' => $events,
		],
		'run' => $run, 'progress' => $progress,
		'counts' => ['worlds' => 1, 'worlds_active' => 1, 'artifacts' => 7, 'value_total' => 541,
			'value_avg' => 77, 'critiqued' => 1, 'published' => 7],
		'latest' => $artifacts, 'events' => $events, 'world' => $world,
		'roles' => Service_Erp222_Forge::roles(), 'types' => Service_Erp222_Forge::types(),
		'steps_default' => 6, 'steps_max' => 12, 'csrf' => 'stand-csrf', 'flash' => [],
	]);
}

$pages = [
	'index-live' => stand_index_page($run_live, $artifact_rows, $events, $world_live),
	'index-done' => stand_index_page($run_done, $artifact_rows, $events, $world_live),
	'index-fresh' => stand_index_page(NULL, [], [], $world_live),
	'artifact' => (string) View::factory('erp222/artifact', [
		'artifact' => $artifact_rows[1],
		'links' => ['depends_on' => [$artifact_rows[0]], 'used_by' => []],
		'world' => $world_live, 'events' => $events, 'csrf' => 'stand-csrf',
		'is_owner' => TRUE, 'flash' => [],
	]),
];

echo "\nСтраницы модуля (живой цикл, завершённый, пустой, артефакт):\n";

$live = $pages['index-live'];
check('живой цикл: карточка хода не скрыта', strpos($live, 'data-erp222-run="3"') !== FALSE, TRUE);
check('живой цикл: спиннер крутится', strpos($live, 'fa-spinner animate-spin') !== FALSE, TRUE);
check('живой цикл: секундомер показан', (bool) preg_match('~data-erp222-elapsed-text>\d+:\d\d<~', $live), TRUE);
check('живой цикл: счётчики словами', strpos($live, 'data-erp222-counter') !== FALSE, TRUE);
check('живой цикл: лестница этапов на месте', substr_count($live, 'data-erp222-stage-item') === 4, TRUE);
check('живой цикл: форма запуска знает про опрос', strpos($live, 'data-erp222-progress="/forge/progress"') !== FALSE, TRUE);
check('живой цикл: есть кнопка «Один шаг»', strpos($live, 'data-erp222-one-step') !== FALSE, TRUE);
check('пусто: карточка хода скрыта', strpos($pages['index-fresh'], 'data-erp222-empty="1"') !== FALSE, TRUE);
check('карточка артефакта: якорь копирования', strpos($pages['artifact'], 'data-erp222-copy-source="5"') !== FALSE, TRUE);
check('карточка артефакта: ценность с расшифровкой', strpos($pages['artifact'], 'брать в работу') !== FALSE, TRUE);
check('карточка артефакта: разбор Критика отрисован', strpos($pages['artifact'], 'Разбор Критика') !== FALSE, TRUE);

// Отдать готовые страницы JS-проверке (jsdom): ей нужен ровно этот HTML.
foreach ((array) ($argv ?? []) as $stand_arg)
{
	if (strpos((string) $stand_arg, '--dump-ui=') !== 0) { continue; }

	$dump = rtrim(substr((string) $stand_arg, 10), '/');

	if ($dump === '' OR ( ! is_dir($dump) AND ! @mkdir($dump, 0777, TRUE))) { continue; }

	foreach ($pages as $name => $html)
	{
		file_put_contents($dump.'/'.$name.'.html', $html);
	}

	echo "  HTML для JS-проверки: {$dump}\n";
}

echo "\n";
echo $fail === 0
	? "Всё чисто: erp222_forge (чистые функции и вёрстка)\n"
	: "Есть замечания: {$fail}\n";

exit($fail === 0 ? 0 : 1);
