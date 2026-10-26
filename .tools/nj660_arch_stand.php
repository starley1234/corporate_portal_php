<?php
/**
 * Стенд NJ-660 «Конструктор архитектуры ИИ» на PHP-in-WASM.
 *
 * Проверяет без БД и без LLM:
 *   - калькулятор параметров против ЭТАЛОНОВ: GPT-2 124M, Llama-3-8B ~8B,
 *     Mixtral 46.7B/12.9B активных, ViT-L ~304M (формулы должны попадать
 *     в публикуемые числа моделей — иначе конструктор врёт);
 *   - validate(): деление d на головы, top-k ≤ E, хук за пределами глубины,
 *     пустой стек, неизвестные блоки;
 *   - flatten(): repeat разворачивается, хуки отделяются;
 *   - warnings(): нет нормы → error; top-1 MoE; sliding окно; MHA на длинном
 *     контексте → подсказка GQA; гибрид внимание+SSM → похвала;
 *   - pytorch(): скелет содержит MoE/topk/register_forward_hook (хук =
 *     настоящий forward hook), RMSNorm, steering-текст; невалидный DSL → '#';
 *   - Expert: _persona() отражает хук/MoE/контекст; _parse_json() ест
 *     markdown-обёртку и оборачивает {"review": {...}}; _design_summary()
 *     содержит параметры и стек;
 *   - fmt-хелперы.
 *
 *   N2J_ROOT=/path/to/n2j.ru node phpwasm_stand.mjs .tools/nj660_arch_stand.php
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
		$paths = [
			'/app/application/modules/nj660_arch/config/'.$name.'.php',
			'/app/application/config/'.$name.'.php',
		];

		foreach ($paths as $file)
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
}

Kohana::$config = new Config;
Kohana::$log    = new class { public function add($l, $m, $v = []) { } };

class Log
{
	const ERROR = 4;
}

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

require '/app/application/modules/nj660_arch/classes/Service/Nj660/Arch.php';
require '/app/application/modules/nj660_arch/classes/Service/Nj660/Expert.php';

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

function check_range($label, $got, $min, $max)
{
	check($label.' = '.Service_Nj660_Arch::fmt_params($got).' (окно '.Service_Nj660_Arch::fmt_params($min).'…'.Service_Nj660_Arch::fmt_params($max).')',
		($got >= $min AND $got <= $max) ? 'в окне' : 'вне окна', 'в окне');
}

$f = 'Service_Nj660_Arch';

echo "1. Калькулятор против эталонных моделей\n";
$presets = $f::presets();

foreach (['gpt2' => [1.20e8, 1.26e8, 1.24e8], 'llama8b' => [7.2e9, 8.2e9, 8.03e9], 'vit' => [2.9e8, 3.1e8, 3.04e8]] as $key => $window)
{
	$eval = $f::evaluate($presets[$key]['dsl']);
	check($key.': валиден', isset($eval['totals']), TRUE);
	check_range($key.': параметров', $eval['totals']['params_total'], $window[0], $window[1]);
}

$mixtral = $f::evaluate($presets['mixtral']['dsl']);
check_range('mixtral: параметров всего', $mixtral['totals']['params_total'], 4.55e10, 4.80e10);
check_range('mixtral: активных (top-2 из 8)', $mixtral['totals']['params_active'], 1.24e10, 1.34e10);
check('mixtral: активных меньше тотала', $mixtral['totals']['params_active'] < $mixtral['totals']['params_total'], TRUE);

$tiny = $f::evaluate($presets['tiny']['dsl']);
check('tiny: LoRA-параметры посчитаны', $tiny['totals']['params_lora'] > 0, TRUE);
check('tiny: LoRA = 8·r·d', $tiny['totals']['params_lora'], 8 * 16 * 512);
check('tiny: обучаемых при LoRA < 1%', $tiny['totals']['lora_train_pct'] < 1, TRUE);

echo "\n2. validate(): кривые DSL\n";
$base = $presets['llama8b']['dsl'];

$bad_dsl = $base;
$bad_dsl['config']['d'] = 1000; // не делится на 32
check('d не делится на H → ошибка', count(array_filter($f::validate($bad_dsl), function ($e) { return strpos($e, 'не делится') !== FALSE; })), 1);

$bad_dsl = $base;
$bad_dsl['stack'][3]['KV'] = 64; // больше H
check('KV > H → ошибка', count(array_filter($f::validate($bad_dsl), function ($e) { return strpos($e, 'KV-голов') !== FALSE; })), 1);

$bad_dsl = $presets['mixtral']['dsl'];
$bad_dsl['stack'][5]['k'] = 9; // больше E
check('top-k > E → ошибка', count(array_filter($f::validate($bad_dsl), function ($e) { return strpos($e, 'top-k') !== FALSE; })), 1);

$bad_dsl = $base;
$bad_dsl['stack'][] = ['t' => 'hook', 'kind' => 'steer', 'at' => 999, 's' => 0.5, 'text' => 'x'];
check('хук за пределами глубины → ошибка', count(array_filter($f::validate($bad_dsl), function ($e) { return strpos($e, 'всего слоёв') !== FALSE; })), 1);

$bad_dsl = $base;
$bad_dsl['stack'] = [];
check('пустой стек → ошибка', count($f::validate($bad_dsl)) > 0, TRUE);

$bad_dsl = $base;
$bad_dsl['stack'][0]['t'] = 'warp';
check('неизвестный блок → ошибка', count(array_filter($f::validate($bad_dsl), function ($e) { return strpos($e, 'неизвестный тип') !== FALSE; })), 1);

check('эталонный пресет валиден', $f::validate($base), []);

echo "\n3. flatten(): повтор и хуки\n";
$flat = $f::flatten($base);
$layers = 0;

foreach ($flat['layers'] as $l)
{
	if (in_array($l['t'], ['attn', 'ffn', 'moe', 'ssm', 'norm'], TRUE))
	{
		$layers++;
	}
}

check('llama8b: слоёв после разворота (32 attn + 32 ffn + 64 norm)', $layers, 128);
check('llama8b: хуков нет', count($flat['hooks']), 0);

$moe_small = $f::evaluate($presets['moe-small']['dsl']);
check('moe-small: хук извлечён', count($moe_small['hooks']), 1);
check('moe-small: хук steer на слое 8', $moe_small['hooks'][0]['at'], 8);

echo "\n4. warnings(): умные подсказки\n";
$w_of = function (array $eval) {
	$out = [];

	foreach ($eval['warnings'] as $w)
	{
		$out[] = $w['kind'];
	}

	return $out;
};

$no_norm = ['family' => 'decoder', 'config' => ['d' => 512, 'S' => 1024],
	'stack' => [['t' => 'emb', 'V' => 32000, 'tied' => TRUE], ['t' => 'pos', 'v' => 'rope'], ['t' => 'attn', 'v' => 'gqa', 'H' => 8, 'KV' => 2, 'n' => 4], ['t' => 'ffn', 'v' => 'gelu', 'h' => 2048, 'n' => 4], ['t' => 'head', 'v' => 'lm']]];
$eval = $f::evaluate($no_norm);
check('нет нормы → error', in_array('error', $w_of($eval), TRUE), TRUE);

$top1 = $presets['mixtral']['dsl'];
$top1['stack'][5]['k'] = 1;
$eval = $f::evaluate($top1);
check('top-1 MoE → warn', in_array('warn', $w_of($eval), TRUE), TRUE);

$mha_long = $presets['llama8b']['dsl'];
$mha_long['stack'][3]['v'] = 'mha';
$mha_long['stack'][3]['KV'] = 32;
$eval = $f::evaluate($mha_long);
check('MHA на 8k контексте → подсказка GQA', in_array('info', $w_of($eval), TRUE), TRUE);

$eval = $f::evaluate($presets['hybrid']['dsl']);
check('гибрид внимание+SSM → ok', in_array('ok', $w_of($eval), TRUE), TRUE);

$sliding = $presets['hybrid']['dsl'];
$sliding['stack'][3]['W'] = 1024; // окно сильно меньше S
$eval = $f::evaluate($sliding);
check('узкое окно → warn', in_array('warn', $w_of($eval), TRUE), TRUE);

echo "\n5. compare(): ближайшие аналоги\n";
$eval = $f::evaluate($presets['llama8b']['dsl']);
check('llama8b: ближайший аналог — Llama-3-8B', $eval['compare'][0]['title'], 'Llama-3-8B');
check('llama8b: отношение к самому себе ≈ 1', round($eval['compare'][0]['ratio_total'], 2) <= 1.1 AND round($eval['compare'][0]['ratio_total'], 2) >= 0.85, TRUE);

echo "\n6. pytorch(): скелет\n";
$py = $f::pytorch($presets['mixtral']['dsl'], NULL, 'Мой Mixtral');
check('py: класс MoE есть', strpos($py, 'class MoE') !== FALSE, TRUE);
check('py: top-k роутер', strpos($py, 'torch.topk') !== FALSE, TRUE);
check('py: RMSNorm собственный', strpos($py, 'class RMSNorm') !== FALSE, TRUE);
check('py: GQA раздувание', strpos($py, 'repeat_interleave') !== FALSE, TRUE);
check('py: заголовок nj660', strpos($py, 'nj660_arch') !== FALSE, TRUE);

$steer_py = $f::pytorch($presets['moe-small']['dsl'], NULL, 'Со хуком');
check('py: steering через register_forward_hook', strpos($steer_py, 'register_forward_hook') !== FALSE, TRUE);
check('py: смысл хука прокомментирован', strpos($steer_py, 'аккуратность формулировок') !== FALSE, TRUE);

$bad_py = $f::pytorch(['family' => 'decoder', 'config' => ['d' => 33, 'S' => 100], 'stack' => []], NULL, 'x');
check('py: невалидный DSL → комментарий об ошибке', strpos($bad_py, '# DSL невалиден') === 0, TRUE);

echo "\n7. Expert: персона и парсинг (LLM не зовём)\n";
$design = ['id' => 1, 'code' => 'aaaaaaaaaa', 'title' => 'Тест-модель', 'dsl' => json_encode($presets['moe-small']['dsl'])];
$eval = $f::evaluate($presets['moe-small']['dsl']);
$persona = Service_Nj660_Expert::_persona($design, $eval);
check('persona: имя модели', strpos($persona, 'Тест-модель') !== FALSE, TRUE);
check('persona: MoE отражён', strpos($persona, 'MoE') !== FALSE, TRUE);
check('persona: хук с силой и смыслом', strpos($persona, 'аккуратность формулировок') !== FALSE AND strpos($persona, 'сила 0.6') !== FALSE, TRUE);
check('persona: глубина слоя → характер влияния (слой 8 из 48 — ранний)', strpos($persona, 'стиль и формат') !== FALSE, TRUE);

$summary = Service_Nj660_Expert::_design_summary($design, $eval);
check('summary: параметры в тексте', strpos($summary, 'Параметров всего') !== FALSE, TRUE);
check('summary: бюджет обучения', strpos($summary, 'GPU-часов') !== FALSE, TRUE);

check('parse_json: markdown-обёртка', Service_Nj660_Expert::_parse_json("```json\n{\"score\": 7}\n```"), ['score' => 7]);
check('parse_json: обёрнутый объект', Service_Nj660_Expert::_parse_json('{"review": {"score": 8}}'), ['score' => 8]);
check('parse_json: мусор → NULL', Service_Nj660_Expert::_parse_json('просто текст'), NULL);
check('parse_json: пусто → NULL', Service_Nj660_Expert::_parse_json(''), NULL);

echo "\n8. fmt-хелперы\n";
check('fmt_params: B', $f::fmt_params(4.67e10), '46.7B');
check('fmt_params: M', $f::fmt_params(124355328), '124.4M');
check('fmt_params: K', $f::fmt_params(45000), '45K');
check('fmt_bytes: ГБ', $f::fmt_bytes(4.5 * 1024 * 1024 * 1024), '4.5 ГБ');
check('fmt_ratio: больше', $f::fmt_ratio(2.5), '×2.5');
check('fmt_ratio: меньше', $f::fmt_ratio(0.2), '×1/5');

// ---------------------------------------------------------------- итог
echo "\n";
echo $bad === 0 ? "СТЕНД NJ-660: OK ($ok проверок)\n" : "СТЕНД NJ-660: ПРОВАЛ ($bad из ".($ok + $bad).")\n";
exit($bad === 0 ? 0 : 1);
