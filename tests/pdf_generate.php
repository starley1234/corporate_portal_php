<?php
/**
 * Хелпер для tests/pdf_external_check.py (bin/test --pdf).
 *
 * Генерирует PDF через сервисы prx634 в переданный каталог ($argv[1]) и печатает
 * JSON-отчёт. НЕ часть обычного прогона — вызывается только независимой
 * pypdf-валидацией.
 *
 *   php tests/pdf_generate.php /tmp/out
 */

if (PHP_SAPI !== 'cli')
{
    exit(1);
}

$root = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR;

require $root.'application/env.php';
prx_env_load($root);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

define('EXT', '.php');
define('DOCROOT', $root.'public_html'.DIRECTORY_SEPARATOR);
define('APPPATH', $root.'application'.DIRECTORY_SEPARATOR);
define('MODPATH', $root.'modules'.DIRECTORY_SEPARATOR);
define('SYSPATH', $root.'system'.DIRECTORY_SEPARATOR);

if ( ! defined('KOHANA_START_TIME'))
{
    define('KOHANA_START_TIME', microtime(TRUE));
    define('KOHANA_START_MEMORY', memory_get_usage());
}

require APPPATH.'bootstrap'.EXT;

$out_dir = isset($argv[1]) ? rtrim((string) $argv[1], '/').'/' : sys_get_temp_dir().'/';

// многостраничный текст → PDF
$lines = [];

for ($i = 0; $i < 300; $i++)
{
    $lines[] = 'Строка '.$i;
}

$multi = Service_Prx634_Txt2pdf::build(implode("\n", array_merge(['Многостраничный'], $lines)), 'Много');

if ( ! $multi['ok'])
{
    fwrite(STDERR, 'txt2pdf: '.($multi['error'] ?? 'ошибка')."\n");
    exit(2);
}

file_put_contents($out_dir.'multi.pdf', $multi['pdf']);

// split 2-3
$split = Service_Prx634_SplitMerge::extract($multi['pdf'], 2, 3);

if ( ! $split['ok'])
{
    fwrite(STDERR, 'split: '.($split['error'] ?? 'ошибка')."\n");
    exit(3);
}

file_put_contents($out_dir.'split.pdf', $split['pdf']);

// merge 3,1,2
$merge = Service_Prx634_SplitMerge::merge_select($multi['pdf'], [3, 1, 2]);

if ( ! $merge['ok'])
{
    fwrite(STDERR, 'merge: '.($merge['error'] ?? 'ошибка')."\n");
    exit(4);
}

file_put_contents($out_dir.'merge.pdf', $merge['pdf']);

// сколько страниц на самом деле в multi (по нашему парсеру — сверяем в python)
$info = Service_Prx634_Parser::meta($multi['pdf']);

echo json_encode([
    'multi_pages' => $info['pages'] ?? 0,
    'split_pages' => $split['pages'] ?? 0,
    'merge_pages' => $merge['pages'] ?? 0,
], JSON_UNESCAPED_UNICODE)."\n";
