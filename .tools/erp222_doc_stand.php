<?php
/**
 * Стенд документа артефакта (erp222): markdown → HTML + разбивка на страницы.
 *
 * 09.10.2026 владелец: «блок Документ показываем в виде html (переводим в него
 * маркдаун) с поддержкой страниц, но предусматриваем кнопку скачать в
 * markdown». Значит, страница обязана:
 *   - переводить markdown в HTML и НЕ пускать в него чужой HTML/JS
 *     (тело пишет LLM — Parsedown включён в безопасном режиме);
 *   - резать документ на страницы по заголовкам `#`/`##`, а длинный раздел —
 *     по абзацам, не разрывая абзац посередине;
 *   - короткий документ держать одной страницей (листалка тогда не нужна);
 *   - не падать на пустом теле.
 *
 *   N2J_ROOT=/path/to/n2j.ru node phpwasm_stand.mjs .tools/erp222_doc_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

define('APPPATH', '/app/application/');

// ---------------------------------------------------------------- заглушки

class Arr
{
	public static function get($array, $key, $default = NULL)
	{
		return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
	}
}

class HTML
{
	public static function chars($value, $double_encode = TRUE)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $double_encode);
	}
}

// Настоящий сервис артефактов.
require '/app/application/modules/erp222_forge/classes/Service/Erp222/Artifact.php';

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

$S = 'Service_Erp222_Artifact';

// --- пустой документ ---------------------------------------------------------
check('пустое тело: страниц нет', count($S::document_pages('')), 0);
check('пустое тело: document_pages пуст', $S::document_pages('   '), []);

// --- короткий документ — одна страница --------------------------------------
$short = "# Спецификация\n\nКороткий документ из одного раздела.";
$pages = $S::document_pages($short);
check('короткий документ: одна страница', count($pages), 1);
check('короткий документ: заголовок', $pages[0]['title'], 'Спецификация');
check('короткий документ: markdown сохранён', strpos($pages[0]['markdown'], '# Спецификация') === 0 ? 'true' : 'false', 'true');
check('короткий документ: html с заголовком', strpos($pages[0]['html'], '<h1>') === 0 ? 'true' : 'false', 'true');

// --- разбивка по заголовкам --------------------------------------------------
$doc = "# Введение\n\nТекст введения.\n\n## Схема данных\n\nТаблицы и поля.\n\n## Контракт API\n\nМетоды и ответы.";
$pages = $S::document_pages($doc);
check('три раздела: три страницы', count($pages), 3);
check('страница 1: заголовок', $pages[0]['title'], 'Введение');
check('страница 2: заголовок', $pages[1]['title'], 'Схема данных');
check('страница 3: заголовок', $pages[2]['title'], 'Контракт API');
check('страница 1: не тянет чужой текст', strpos($pages[0]['markdown'], 'Таблицы') === FALSE ? 'true' : 'false', 'true');

// --- длинный раздел режется по абзацам --------------------------------------
$long = "## Длинный раздел\n\n".str_repeat("Абзац с содержанием про схему данных и её поля. ", 3)."\n\n"
	.str_repeat("Второй абзац, тоже не короткий, про индексы и связи. ", 3);
$pages = $S::document_pages($long, 200);
check('длинный раздел: больше одной страницы', count($pages) > 1 ? 'true' : 'false', 'true');
check('длинный раздел: продолжение помечено', strpos($pages[1]['title'], 'продолжение') !== FALSE ? 'true' : 'false', 'true');
$first_len = mb_strlen($pages[0]['markdown']);
check('первая страница не больше лимита + абзаца', $first_len < 200 + 200 ? 'true' : 'false', 'true');

// --- абзац длиннее лимита не рвём -------------------------------------------
$one_block = "## Раз\n\n".str_repeat('очень длинный абзац без переносов — резать его посередине нельзя; ', 8);
$pages = $S::document_pages($one_block, 120);
check('целый абзац: одна страница', count($pages), 1);
check('целый абзац: текст не обрезан', mb_strlen($pages[0]['markdown']) > 400 ? 'true' : 'false', 'true');

// --- безопасность: чужой HTML и JS не проходят -------------------------------
$dirty = "## Опасный раздел\n\n<script>alert(1)</script>\n\n[ссылка](javascript:alert(2))\n\n<b>жирный</b>";
$pages = $S::document_pages($dirty);
$html  = $pages[0]['html'];

check('безопасный режим: script вырезан', strpos($html, '<script') === FALSE ? 'true' : 'false', 'true');
check('безопасный режим: javascript: вырезан', strpos($html, 'javascript:') === FALSE ? 'true' : 'false', 'true');
check('безопасный режим: <b> не пропущен', strpos($html, '<b>жирный</b>') === FALSE ? 'true' : 'false', 'true');
check('безопасный режим: текст ссылки остался', strpos($html, 'ссылка') !== FALSE ? 'true' : 'false', 'true');

// --- markdown разметка работает ----------------------------------------------
$md = "## Список\n\n- один\n- два\n\n`код` и **жирный**";
$pages = $S::document_pages($md);
$html  = $pages[0]['html'];

check('markdown: список', strpos($html, '<li>один</li>') !== FALSE ? 'true' : 'false', 'true');
check('markdown: код', strpos($html, '<code>код</code>') !== FALSE ? 'true' : 'false', 'true');
check('markdown: жирный', strpos($html, '<strong>жирный</strong>') !== FALSE ? 'true' : 'false', 'true');

// --- переносы строк Windows --------------------------------------------------
$pages = $S::document_pages("# Раз\r\n\r\nТекст с виндовыми переводами строк.");
check('CRLF: одна страница', count($pages), 1);
check('CRLF: заголовок распознан', $pages[0]['title'], 'Раз');

// --- без заголовков: документ не теряется ------------------------------------
$pages = $S::document_pages("Просто абзац.\n\nЕщё один абзац.");
check('без заголовков: страница есть', count($pages), 1);
check('без заголовков: заголовок пуст', $pages[0]['title'], '');
check('без заголовков: текст на месте', strpos($pages[0]['markdown'], 'Просто абзац') === 0 ? 'true' : 'false', 'true');

// --- итог -------------------------------------------------------------------
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
