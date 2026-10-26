<?php
/**
 * Стенд NJ-658 «Справочник городов» на PHP-in-WASM.
 *
 * Проверяет без БД:
 *   - чистые функции сервиса (нормализация поиска, LIKE-экранирование,
 *     транслитерация slug, title_norm, подпись label, CSV);
 *   - конфиг модуля (белый список видов, лимиты подсказок);
 *   - сид-файл: 2020 строк, 9 батчей, ON CONFLICT,slug, уникальные slug.
 *
 *   N2J_ROOT=/path/to/n2j.ru node phpwasm_stand.mjs .tools/nj658_geo_stand.php
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
		$file = '/app/application/modules/nj658_geo/config/'.$name.'.php';

		if (is_file($file))
		{
			$data = require $file;

			return new Kohana_Config_Group(is_array($data) ? $data : []);
		}

		return new Kohana_Config_Group([]);
	}
}

class Kohana
{
	public static $config;
}

Kohana::$config = new Config;

// Настоящий Arr из ядра (без зависимостей).
require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

// Класс модуля.
require '/app/application/modules/nj658_geo/classes/Service/Nj658/Geo.php';

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

// ---- нормализация поиска
check('normalize: регистр и ё', Service_Nj658_Geo::normalize_query('  Ёлки Палки  '), 'елки палки');
check('normalize: пробелы схлопываются', Service_Nj658_Geo::normalize_query("Абан\t Абан"), 'абан абан');
check('normalize: латиница проходит', Service_Nj658_Geo::normalize_query('Zverinogolovskoe'), 'zverinogolovskoe');
check('normalize: обрезка до 64', mb_strlen(Service_Nj658_Geo::normalize_query(str_repeat('а', 100))), 64);

// ---- LIKE-экранирование
check('like: % _ и бэкслэш', Service_Nj658_Geo::_like('100% _x\\'), '100\% \_x\\\\');

// ---- slug
check('slug: транслитерация', Service_Nj658_Geo::slugify('Звериноголовское'), 'zverinogolovskoe');
check('slug: скобки и регистр', Service_Nj658_Geo::slugify('Республика Саха (Якутия)'), 'respublika-saha-yakutiya');
check('slug: пустая строка', Service_Nj658_Geo::slugify('!!!'), '');

// ---- title_norm
check('title_norm: ё и регистр', Service_Nj658_Geo::title_norm('Звериноголовское'), 'звериноголовское');
check('title_norm: обрезка до 100', mb_strlen(Service_Nj658_Geo::title_norm(str_repeat('ё', 150))), 100);

// ---- label
check('label: регион совпадает с названием', Service_Nj658_Geo::label(['title' => 'Москва', 'region' => 'Москва']), 'Москва');
check('label: регион начинается с названия', Service_Nj658_Geo::label(['title' => 'Красноярский', 'region' => 'Красноярский край']), 'Красноярский');
check('label: регион добавляется', Service_Nj658_Geo::label(['title' => 'Абан', 'region' => 'Красноярский край']), 'Абан · Красноярский край');
check('label: без региона', Service_Nj658_Geo::label(['title' => 'Абан', 'region' => '']), 'Абан');

// ---- CSV
$csv = Service_Nj658_Geo::csv([[
	'id' => 1, 'slug' => 'abakan', 'title' => 'Абакан', 'title_full' => 'Абакан',
	'name_latin' => 'abakan', 'region' => 'Республика Хакасия', 'district' => 'городской округ; Абакан',
	'kind' => 'город', 'population' => 165183, 'lat' => 53.721152, 'lon' => 91.442,
]]);
check('csv: заголовок', strpos($csv, 'id;slug;title;title_full;name_latin;region;district;kind;population;lat;lon') === 0, TRUE);
check('csv: точка с запятой в поле заменяется', strpos($csv, 'городской округ, Абакан') !== FALSE, TRUE);
check('csv: строка данных', strpos($csv, "1;abakan;Абакан;Абакан;abakan;Республика Хакасия") !== FALSE, TRUE);
check('csv: завершается переносом', substr($csv, -2) === "\r\n", TRUE);

// ---- конфиг
$kinds = Service_Nj658_Geo::kinds();
check('config: виды не пусты и город есть', ! empty($kinds) AND in_array('город', $kinds, TRUE), TRUE);
check('config: село есть', in_array('село', $kinds, TRUE), TRUE);

// ---- FIELDS (набор колонок, который читаем из справочника)
check('fields: содержит slug и population', strpos(Service_Nj658_Geo::FIELDS, 'slug') !== FALSE AND strpos(Service_Nj658_Geo::FIELDS, 'population') !== FALSE, TRUE);

// ---- сид-файл
$seed = file_get_contents('/app/application/modules/nj658_geo/migrations_pg/data/nj658_cities_seed.sql');
check('seed: файл читается', $seed !== FALSE AND strlen($seed) > 100000, TRUE);
check('seed: 2020 строк данных', count(preg_grep("/^\('/", explode("\n", $seed))), 2020);
check('seed: 9 батчей INSERT', substr_count($seed, 'INSERT INTO nj_658_cities'), 9);
check('seed: идемпотентен (ON CONFLICT)', count(preg_grep('/^ON CONFLICT \(slug\) DO NOTHING;$/', explode("\n", $seed))), 9);
check('seed: Якутия со скобками на месте', strpos($seed, 'Республика Саха (Якутия)') !== FALSE, TRUE);
check('seed: Звериноголовское через ё->е найдётся', strpos($seed, "('zverinogolovskoe', 'Звериноголовское', 'звериноголовское'") !== FALSE, TRUE);

$slugs = [];
foreach (preg_grep("/^\('/", explode("\n", $seed)) as $line)
{
	if (preg_match("/^\('([^']+)',/", $line, $m))
	{
		$slugs[] = $m[1];
	}
}
check('seed: все slug распознались', count($slugs), 2020);
check('seed: slug уникальны', count(array_unique($slugs)), 2020);

// ---------------------------------------------------------------- итог
echo "\n";
echo $bad === 0 ? "СТЕНД NJ-658: OK ($ok проверок)\n" : "СТЕНД NJ-658: ПРОВАЛ ($bad из ".($ok + $bad).")\n";
exit($bad === 0 ? 0 : 1);
