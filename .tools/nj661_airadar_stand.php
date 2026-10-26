<?php
/**
 * Стенд NJ-661 «ИИ-радар» на PHP-in-WASM: чистые функции конвейера
 * (без БД, сети и LLM).
 *
 * Проверяет:
 *   - norm_url/url_hash: трекинг-параметры, регистр хоста, фрагменты, хвостовой
 *     слэш — дедуп «работу не делаем заново» не должен пропускать дубли;
 *   - fact_hash/idea_hash: нормализация (ё/е, пунктуация, регистр) даёт
 *     одинаковый хеш; другие числа — другой хеш;
 *   - rss_parse: RSS 2.0 и Atom, битый XML → [];
 *   - openrouter_scan: бесплатные модели = «окно возможностей» + высокий скор,
 *     старые платные обычные отфильтрованы;
 *   - hf_scan: открытая свежая модель проходит, закрытая игрушка отсеяна;
 *   - cosine, dedupe_items, mail_due (троттлинг и порог письма),
 *     report_sections (порядок секций, срез 8, шум пропущен);
 *   - mail_html: экранирование заголовков, секции, идеи, счётчик базы.
 *
 *   N2J_ROOT=/home/user/n2j.ru node phpwasm_stand.mjs .tools/nj661_airadar_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

// ---------------------------------------------------------------- заглушки
class Kohana_Config_Group extends ArrayObject
{
	public function as_array()
	{
		return (array) $this;
	}

	public function get($key, $default = NULL)
	{
		$array = (array) $this;

		return array_key_exists($key, $array) ? $array[$key] : $default;
	}
}

class Config
{
	public function load($name)
	{
		foreach (['/app/application/modules/nj661_airadar/config/'.$name.'.php',
			'/app/application/config/'.$name.'.php'] as $file)
		{
			if (is_file($file))
			{
				$data = require $file;

				return new Kohana_Config_Group(is_array($data) ? $data : []);
			}
		}

		return new Kohana_Config_Group([]);
	}
}

class Kohana
{
	public static $config;
	public static $log;
	public static $charset = 'UTF-8';
}

Kohana::$config = new Config;
Kohana::$log = new class { public function add($l, $m, $v = []) { } };

class Log
{
	const ERROR = 4;
	const WARNING = 3;
}

class Log_Plain { public static function event($level, $message, $values = []) {} }

class HTML
{
	public static function chars($value, $double_encode = TRUE)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $double_encode);
	}
}

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

require '/app/application/modules/nj661_airadar/classes/Service/Nj661/Airadar.php';

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

$f = 'Service_Nj661_Airadar';

echo "1. norm_url / url_hash: дедуп материалов\n";
check('utm и фрагмент срезаются', $f::norm_url('https://HuggingFace.co/model/x?utm_source=tw&utm_campaign=n#a'), 'https://huggingface.co/model/x');
check('хвостовой слэш убран', $f::norm_url('https://openrouter.ai/qwen/qwen3-coder/'), 'https://openrouter.ai/qwen/qwen3-coder');
check('осмысленный query сохранён', $f::norm_url('https://example.com/page?id=42&sort=new'), 'https://example.com/page?id=42&sort=new');
check('мусорный URL → пусто', $f::norm_url('javascript:alert(1)'), '');
check('дубль с трекингом = тот же хеш', $f::url_hash('https://HF.co/x?utm_source=x'), $f::url_hash('https://hf.co/x'));
check('хост из url', $f::url_host('https://Blog.Google/x/y'), 'blog.google');

echo "\n2. fact_hash / idea_hash: база знаний без дублей\n";
check('регистр и ё не меняют хеш факта', $f::fact_hash('Модель Qwen3 имеет 235B параметров'), $f::fact_hash('модель qwen3 имеет 235b параметров'));
check('пунктуация не меняет хеш факта', $f::fact_hash('GPT-5: контекст 400K!'), $f::fact_hash('gpt 5 контекст 400k'));
check('другие числа — другой факт', $f::fact_hash('контекст 400K') === $f::fact_hash('контекст 128K'), FALSE);
check('хеш идеи по заголовку', $f::idea_hash('Сделать Модуль Поиск Похожих') === $f::idea_hash('сделать модуль поиск похожих'), TRUE);
check('теги: регистр, дубли, лимит 8', $f::tags_norm(['Qwen', 'qwen', 'a', 'llm', 'open-source', 'x1', 'x2', 'x3', 'x4', 'x5', 'x6', 'x7', 'x8', 'x9']), ['qwen', 'llm', 'open-source', 'x1', 'x2', 'x3', 'x4', 'x5']);
check('теги строкой', $f::tags_norm('qwen, llm;; ,'), ['qwen', 'llm']);

echo "\n3. rss_parse: ленты гигантов\n";
$rss = <<<XML
<rss version="2.0"><channel><title>Blog</title>
<item><title>Open weights победили</title><link>https://example.com/a?utm_source=rss</link><description>&lt;b&gt;Модель&lt;/b&gt; X открыла веса</description><pubDate>Mon, 05 Oct 2026 10:00:00 +0000</pubDate></item>
<item><title>Без ссылки</title><description>пропускается</description></item>
<item><title>Второй пост</title><link>https://example.com/b</link></item>
</channel></rss>
XML;
$rows = $f::rss_parse($rss, 5);
check('RSS: два валидных item', count($rows), 2);
check('RSS: заголовок и ссылка', $rows[0]['title'].'|'.$rows[0]['url'], 'Open weights победили|https://example.com/a?utm_source=rss');
check('RSS: description без тегов', $rows[0]['snippet'], 'Модель X открыла веса');
check('RSS: дата в pubDate распарсена', $rows[0]['published_at'] !== NULL, TRUE);

$atom = <<<XML
<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Atom title</title>
<link href="https://example.com/atom" rel="alternate"/><summary>сводка</summary><updated>2026-10-05T10:00:00Z</updated></entry></feed>
XML;
$rows = $f::rss_parse($atom, 5);
check('Atom: ссылка из href', $rows ? $rows[0]['url'] : '', 'https://example.com/atom');
check('Atom: заголовок', $rows[0]['title'], 'Atom title');
check('битый XML → пусто', $f::rss_parse('<<< не xml'), []);

echo "\n4. openrouter_scan: окна возможностей\n";
$now = time();
$models = [
	['id' => 'vendor/good-free', 'name' => 'Good Free', 'created' => $now - 2 * 86400, 'context_length' => 200000,
	 'pricing' => ['prompt' => '0', 'completion' => '0'], 'architecture' => ['input_modalities' => ['text', 'image']]],
	['id' => 'vendor/old-paid', 'name' => 'Old Paid', 'created' => $now - 200 * 86400, 'context_length' => 8000,
	 'pricing' => ['prompt' => '0.5', 'completion' => '1.5'], 'architecture' => ['input_modalities' => ['text']]],
	['id' => 'vendor/new-paid', 'name' => 'New Paid', 'created' => $now - 3 * 86400, 'context_length' => 32000,
	 'pricing' => ['prompt' => '0.1', 'completion' => '0.2'], 'architecture' => ['input_modalities' => ['text']]],
	['id' => '', 'name' => 'Broken'],
];
$scan = $f::openrouter_scan($models, $now, 10);
check('старая платная обычная отфильтрована', count($scan), 2);
$free = $scan[0];
check('бесплатная модель — первая (скор выше)', $free['meta']['id'], 'vendor/good-free');
check('бесплатная → категория window', $free['category'], 'window');
check('бесплатная помечена в snippet', strpos($free['snippet'], 'БЕСПЛАТНО') !== FALSE, TRUE);
$new_paid = $scan[1];
check('новая платная → release', $new_paid['category'], 'release');

echo "\n5. hf_scan: открытые модели\n";
$hf = [
	['id' => 'qwen/Qwen3-235B', 'createdAt' => date('c', $now - 2 * 86400), 'downloads' => 150000, 'likes' => 900,
	 'pipeline_tag' => 'text-generation', 'tags' => ['license:apache-2.0', 'text-generation']],
	['id' => 'some/closed-toy', 'createdAt' => date('c', $now - 100 * 86400), 'downloads' => 12, 'likes' => 2,
	 'pipeline_tag' => 'text-to-image', 'tags' => ['license:other']],
	['id' => 'big/popular-old', 'createdAt' => date('c', $now - 300 * 86400), 'downloads' => 5000000, 'likes' => 2000,
	 'pipeline_tag' => 'text-generation', 'tags' => ['license:mit']],
];
$scan = $f::openrouter_scan([], $now, 5);
check('пустой список — пустой результат', $scan, []);
$hf_scan = $f::hf_scan($hf, $now, 10);
check('закрытая игрушка отсеяна', count($hf_scan), 2);
check('первая — свежая открытая', $hf_scan[0]['meta']['id'], 'qwen/Qwen3-235B');
check('лицензия видна в snippet', strpos($hf_scan[0]['snippet'], 'apache-2.0') !== FALSE, TRUE);

echo "\n6. cosine + dedupe_items\n";
check('косинус сам с собой = 1', round($f::cosine([1, 2, 3], [1, 2, 3]), 4), 1.0);
check('косинус ортогональных = 0', round($f::cosine([1, 0], [0, 1]), 4), 0.0);
check('косинус пустых = 0', $f::cosine([], []), 0.0);
$items = $f::dedupe_items([
	['source' => 'ddg', 'url' => 'https://Example.com/page/?utm_source=x', 'title' => 'a'],
	['source' => 'rss', 'url' => 'https://example.com/page', 'title' => 'a-дубль'],
	['source' => 'ddg', 'url' => 'https://other.com/y', 'title' => 'b'],
	['source' => 'ddg', 'url' => 'not-a-url', 'title' => 'мусор'],
]);
check('дедуп: 2 материала из 4', count($items), 2);
check('дедуп: host проставлен', $items[0]['host'], 'example.com');

echo "\n7. mail_due: когда писать владельцу\n";
$cfg = ['to_email' => 'owner@n2j.ru', 'every_days' => 3, 'min_score' => 70];
check('нет адреса — не пишем', $f::mail_due(['max_score' => 99], NULL, ['every_days' => 3, 'min_score' => 70], $now)['send'], FALSE);
check('первое письмо с находкой — пишем', $f::mail_due(['max_score' => 85], NULL, $cfg, $now)['send'], TRUE);
check('не прошло 3 дней — троттлинг', $f::mail_due(['max_score' => 85], $now - 86400, $cfg, $now)['send'], FALSE);
check('прошло 3 дня, скор ниже порога — не пишем', $f::mail_due(['max_score' => 40], $now - 4 * 86400, $cfg, $now)['send'], FALSE);
check('прошло 3 дня и есть новая идея — пишем', $f::mail_due(['max_score' => 40, 'ideas_new' => 2], $now - 4 * 86400, $cfg, $now)['send'], TRUE);
check('причина человекочитаемая', strpos($f::mail_due(['max_score' => 40], $now - 4 * 86400, $cfg, $now)['reason'], 'порог') !== FALSE, TRUE);

echo "\n8. report_sections: сборка отчёта\n";
$items = [];

foreach ([['window', 90, 'A'], ['breakthrough', 80, 'B'], ['release', 60, 'C'], ['noise', 99, 'N']] as $i)
{
	$items[] = ['category' => $i[0], 'score' => $i[1], 'title' => $i[2], 'why' => 'w', 'url' => 'https://x', 'source' => 'ddg'];
}

for ($i = 0; $i < 10; $i++)
{
	$items[] = ['category' => 'release', 'score' => 50 + $i, 'title' => 'r'.$i, 'why' => '', 'url' => 'https://y'.$i, 'source' => 'rss'];
}

$sections = $f::report_sections($items, [['text' => 'факт']], [['title' => 'идея', 'benefit' => 'b', 'fit' => 'f', 'score' => 5]]);
check('шум не попадает в секции', count($sections['sections']), 3);
check('окна возможностей — первая секция', $sections['sections'][0]['key'], 'window');
check('прорыв — вторая секция', $sections['sections'][1]['key'], 'breakthrough');
check('release срезан до 8', count($sections['sections'][2]['items']), 8);
check('release отсортирован по скору (C=60 выше r9=59)', $sections['sections'][2]['items'][0]['title'].'>'.$sections['sections'][2]['items'][1]['title'], 'C>r9');
check('max_score по полезным (шум не в счёт)', $sections['max_score'], 90);
check('факты и идеи на месте', [count($sections['facts_new']), $sections['ideas'][0]['title']], [1, 'идея']);

echo "\n9. mail_html: письмо\n";
$report = [
	'title' => 'Сводка <острая> & "важная"',
	'summary' => "Строка 1\nСтрока 2",
	'created_at' => '2026-10-06 12:00:00',
	'highlights' => ['Главное <b>жирно</b>'],
	'sections' => json_encode([
		'sections' => [['title' => 'Окна возможностей', 'items' => [
			['title' => 'Free model', 'score' => 95, 'why' => 'бесплатно до конца года', 'url' => 'https://openrouter.ai/x'],
		]]],
		'ideas' => [['title' => 'Идея X', 'benefit' => 'польза', 'fit' => 'модуль-сервис Y']],
	], JSON_UNESCAPED_UNICODE),
	'stats' => json_encode(['total_facts' => 42, 'total_ideas' => 7], JSON_UNESCAPED_UNICODE),
];
$html = $f::mail_html($report);
check('письмо: заголовок экранирован', strpos($html, 'Сводка &lt;острая&gt; &amp; &quot;важная&quot;') !== FALSE, TRUE);
check('письмо: нет сырого script', strpos($html, '<script>') === FALSE, TRUE);
check('письмо: секция с материалом', strpos($html, 'Free model') !== FALSE AND strpos($html, '95/100') !== FALSE, TRUE);
check('письмо: идея с пользой', strpos($html, 'Польза: польза') !== FALSE AND strpos($html, 'Куда: модуль-сервис Y') !== FALSE, TRUE);
check('письмо: счётчик базы', strpos($html, '42 фактов') !== FALSE, TRUE);
check('письмо: ссылка на полный отчёт', strpos($html, 'n2j.ru/airadar') !== FALSE, TRUE);

// --------------------------------------------- 10. active_run/_catch_up
// Живой случай 06.10.2026: авто-остановка nj120 сняла задачу, /finish не
// доехал — прогон навсегда «running», кнопка «Конвейер работает…», новый
// прогон не запустить. Сверка с очередью закрывает такой прогон сама.
echo "\n10. active_run/_catch_up: сверка зависшего прогона с очередью\n";

class FakeResult661
{
	public function __construct($rows) { $this->rows = $rows; }
	public $rows;
	public function current() { return $this->rows ? $this->rows[0] : FALSE; }
	public function as_array() { return $this->rows; }
	public function get($k) { $c = $this->current(); return $c ? $c[$k] : NULL; }
}

class Database
{
	const SELECT = 1;
	const UPDATE = 2;
	const INSERT = 3;
	/** @var array очередь результатов: по массиву-строк на каждый query */
	public static $next = [];
	/** @var string[] все SQL */
	public static $sqls = [];

	public static function instance() { return new self; }
	public function quote($v) { return "'".str_replace("'", "''", (string) $v)."'"; }

	public function query($type, $sql, $object = NULL)
	{
		self::$sqls[] = $sql;
		return new FakeResult661(array_shift(self::$next) ?: []);
	}
}

class Service_Nj120_JobQueue
{
	public static $job = NULL;
	public static function instance() { return new self; }
	public function get($id) { return self::$job; }
}

$run_row = ['id' => 12, 'job_id' => 77, 'status' => 'running', 'stage' => 'Анализ',
	'stage_note' => 'батч 2', 'stats' => '{"sources":{"huggingface":200}}',
	'error' => '', 'created_at' => date('Y-m-d H:i:s', time() - 600), 'finished_at' => NULL];

// задача ещё живёт — прогон не трогаем
Database::$next = [[$run_row]];
Service_Nj120_JobQueue::$job = ['id' => 77, 'status' => 'processing'];
$alive = $f::active_run();
check('живая задача: прогон остался активным', $alive !== NULL, TRUE);
check('живая задача: без закрывающего UPDATE', count(preg_grep('/finished_at = now()/', Database::$sqls)) > 0, FALSE);

// задача отменена, отчёта нет — прогон закрывается fail'ом с причиной
Database::$next = [[$run_row], []];   // активный прогон; отчёта нет
Database::$sqls = [];
Service_Nj120_JobQueue::$job = ['id' => 77, 'status' => 'cancelled',
	'error' => 'Воркер не отвечал больше 4 мин', 'result' => NULL];
$closed = $f::active_run();
check('отменённая задача: активных не осталось', $closed === NULL, TRUE);
$finish_sql = implode('', preg_grep('/finished_at = now()/', Database::$sqls));
check('отменённая задача: статус fail', strpos($finish_sql, "'fail'") !== FALSE, TRUE);
check('отменённая задача: причина со сверкой и текстом очереди',
	strpos($finish_sql, 'сверкой с очередью') !== FALSE
	AND strpos($finish_sql, 'Воркер не отвечал') !== FALSE, TRUE);

// задача done и отчёт сохранился — прогон закрывается done, статистика из result
Database::$next = [[$run_row], [['1']], []];   // прогон; отчёт есть; (update без результата)
Database::$sqls = [];
Service_Nj120_JobQueue::$job = ['id' => 77, 'status' => 'done', 'error' => '',
	'result' => json_encode(['ok' => TRUE, 'stats' => ['sources' => ['huggingface' => 200, 'openrouter' => 30]]])];
$closed = $f::active_run();
check('done с отчётом: активных не осталось', $closed === NULL, TRUE);
$finish_sql = implode('', preg_grep('/finished_at = now()/', Database::$sqls));
check('done с отчётом: статус done', strpos($finish_sql, "'done'") !== FALSE, TRUE);
check('done с отчётом: статистика взята из result задачи', strpos($finish_sql, 'openrouter') !== FALSE, TRUE);

// прогон-сирота без задачи, старше 3 часов — закрыт по возрасту
$orphan = $run_row;
$orphan['job_id'] = NULL;
$orphan['created_at'] = date('Y-m-d H:i:s', time() - 4 * 3600);
Database::$next = [[$orphan]];
Database::$sqls = [];
Service_Nj120_JobQueue::$job = NULL;
$closed = $f::active_run();
check('сирота старше 3 ч: закрыт', $closed === NULL, TRUE);
$finish_sql = implode('', preg_grep('/finished_at = now()/', Database::$sqls));
check('сирота: причина — без задачи очереди', strpos($finish_sql, 'без задачи очереди') !== FALSE, TRUE);

// --------------------------------------------- 11. регрессии пустых отчётов
class Llm
{
	public static function get_embedding($text) { throw new Exception('LLM вызвана в HTTP-приёмке'); }
}
check('вектор из воркера валиден', count($f::fact_embedding(array_fill(0, 8, 0.5))), 8);
check('битый вектор отвергнут', $f::fact_embedding(array_fill(0, 8, 'oops')), NULL);

Database::$next = [[['url_hash' => $f::url_hash('https://old.example/a')]], [['id' => 77]],
	[['id' => 55, 'url' => 'https://pending.example/b', 'title' => 'Старый неразобранный', 'snippet' => '', 'meta' => '{}', 'source' => 'rss']],
	[], [], [], [['facts' => 0, 'ideas' => 0]]];
Database::$sqls = [];
$received = $f::api_items(8, [
	['url' => 'https://old.example/a', 'title' => 'Готовый'],
	['url' => 'https://new.example/a', 'title' => 'Новый'],
]);
check('готовый материал пропущен, pending возвращён', array_column($received['accepted'], 'id'), [77, 55]);
check('все ответы БД для приёмки использованы', count(Database::$next), 0);
check('дедуп считает только обработанные URL', $received['skipped'], 1);
check('pending не зачислен в готовые дубли', strpos(Database::$sqls[0], "status <> 'new'") !== FALSE, TRUE);
check('UPSERT возвращает старый id pending', strpos(Database::$sqls[1], "WHERE nj_661_items.status = 'new' RETURNING id") !== FALSE, TRUE);
check('статистика приёмки сохраняется сразу', strpos(implode('', Database::$sqls), '"candidates":2') !== FALSE, TRUE);

Database::$next = [[['id' => 77, 'url' => 'https://new.example/a']], [], [], []];
Database::$sqls = [];
$analyzed = $f::api_analysis(8, [['id' => 77, 'relevant' => TRUE, 'category' => 'release', 'score' => 80,
	'why' => 'Полезно', 'facts' => [['text' => 'Открытая модель содержит 12B параметров', 'embedding' => array_fill(0, 8, 0.5)]], 'ideas' => []]]);
check('факт принят без HTTP-вызова LLM', $analyzed['facts_new'], 1);
check('вектор принят из воркера', strpos(implode('', Database::$sqls), '[0.5,0.5') !== FALSE, TRUE);
check('статус материала меняется после фактов', strpos(end(Database::$sqls), "SET status = 'analyzed'") !== FALSE, TRUE);

Database::$next = [[['total' => 5, 'analyzed' => 0]]];
Database::$sqls = [];
$empty_report = $f::api_report(8, ['title' => 'Пустышка']);
check('нулевой анализ не создаёт успешный отчёт', $empty_report['ok'], FALSE);
check('не было INSERT отчёта', count(preg_grep('/INSERT INTO nj_661_reports/', Database::$sqls)), 0);

// Отчёт получает исходные счётчики, а повторная доставка обновляет ту же строку.
Database::$next = [[['total' => 1, 'analyzed' => 1]], [['stats' => '{"candidates":120,"accepted":1}']],
	[['id' => 77, 'category' => 'release', 'score' => 80, 'title' => 'Модель', 'url' => 'https://new.example/a']],
	[], [], [], [], [['facts' => 0, 'ideas' => 0]], [['id' => 9, 'emailed' => TRUE]], []];
Database::$sqls = [];
$report_saved = $f::api_report(8, ['title' => 'Сводка', 'summary' => 'Есть новое'], ['pages_read' => 12]);
check('повторная доставка сохраняет id отчёта', $report_saved['report_id'], 9);
$report_sql = implode('', preg_grep('/UPDATE nj_661_reports/', Database::$sqls));
check('кандидаты не теряются при сохранении отчёта', strpos($report_sql, '"candidates":120') !== FALSE, TRUE);
check('счётчики воркера тоже сохранены', strpos($report_sql, '"pages_read":12') !== FALSE, TRUE);
check('повторной вставки отчёта нет', count(preg_grep('/INSERT INTO nj_661_reports/', Database::$sqls)), 0);
check('все ответы БД для отчёта использованы', count(Database::$next), 0);

Database::$next = [[$run_row], [['1']], []];
Database::$sqls = [];
Service_Nj120_JobQueue::$job = ['id' => 77, 'status' => 'done', 'error' => '',
	'result' => json_encode(['ok' => FALSE, 'stats' => ['pending' => 2], 'error' => 'Неполный прогон'])];
$f::active_run();
check('наличие частичного отчёта не превращает fail в done',
	strpos(implode('', Database::$sqls), "status = 'fail'") !== FALSE, TRUE);

// Почта может вернуть FALSE без исключения — это не «письмо отправлено».
class MailConfig661 extends Config
{
	public function load($name)
	{
		$data = (array) parent::load($name);
		$data['mail']['to_email'] = 'test@example.com';
		return new Kohana_Config_Group($data);
	}
}
class Service_Nj597_Mail { public static function send_direct($to, $subject, $html) { return FALSE; } }
Kohana::$config = new MailConfig661;
Database::$next = [[['id' => 9, 'title' => 'Тест', 'summary' => '', 'sections' => '{}', 'stats' => '{}', 'created_at' => '2026-10-15']]];
Database::$sqls = [];
$mail_failed = $f::send_report_mail(9, TRUE);
check('FALSE почтового сервиса не помечается успехом', $mail_failed['mailed'], FALSE);
check('при отказе почты emailed не обновляется', count(preg_grep('/SET emailed = TRUE/', Database::$sqls)), 0);

// ---------------------------------------------------------------- итог
echo "\n";
echo $bad === 0 ? "СТЕНД NJ-661: OK ($ok проверок)\n" : "СТЕНД NJ-661: ПРОВАЛ ($bad из ".($ok + $bad).")\n";
exit($bad === 0 ? 0 : 1);
