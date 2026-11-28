<?php
/** prx218: скан берёт название/описание из манифеста, но не перетирает ручные правки. */
define('SYSPATH', dirname(__DIR__, 2).'/system/');
require dirname(__DIR__, 2).'/application/modules/prx218_module_manager/classes/Service/Prx218/Scanner.php';

function check(bool $ok, string $what): void { if (!$ok) throw new RuntimeException('FAIL: '.$what); echo "ok   $what\n"; }

$m = ['title' => 'Фасад LLM', 'short' => 'провайдеры и vision', 'has_manifest' => TRUE, 'has_agents' => TRUE];
$readme = ['name' => 'LLM module', 'description' => 'Первый абзац README'];
$sync = static fn (array $prev) => Service_Prx218_Scanner::sync_values($prev + ['manifest_title' => NULL, 'manifest_short' => NULL], $m, $readme, 'llm');

check($sync(['display_name' => 'llm', 'description' => NULL]) === ['display_name' => 'Фасад LLM', 'description' => 'провайдеры и vision'], 'системное имя и пустое описание заменяются манифестом');
check($sync(['display_name' => 'LLM module', 'description' => 'Первый абзац README']) === ['display_name' => 'Фасад LLM', 'description' => 'провайдеры и vision'], 'до первой сверки значения из README считаются автоматическими');
check($sync(['display_name' => 'Ручное имя', 'description' => 'Описание от LLM']) === [], 'ручное имя и LLM-описание не трогаются');
check($sync(['display_name' => 'Старое из манифеста', 'description' => 'старое short', 'manifest_title' => 'Старое из манифеста', 'manifest_short' => 'старое short'])
    === ['display_name' => 'Фасад LLM', 'description' => 'провайдеры и vision'], 'смена манифеста догоняет неправленные поля');
check($sync(['display_name' => 'LLM module', 'description' => 'x', 'manifest_title' => 'Фасад LLM', 'manifest_short' => 'провайдеры и vision']) === [], 'после сверки README-имя уже ручное');
check(Service_Prx218_Scanner::sync_values(['display_name' => 'llm', 'description' => NULL], ['title' => NULL, 'short' => NULL] + $m, $readme, 'llm') === [], 'без манифеста ничего не меняется');

$dir = sys_get_temp_dir().'/prx218_meta_'.getmypid();
@mkdir($dir.'/config', 0777, TRUE);
file_put_contents($dir.'/config/prx_module.php', "<?php return ['title' => ' Тест ', 'short' => '<b>кратко</b>'];");
$meta = Service_Prx218_Scanner::manifest_meta($dir);
check($meta === ['title' => 'Тест', 'short' => 'кратко', 'has_manifest' => TRUE, 'has_agents' => FALSE], 'manifest_meta: trim, strip_tags, нет AGENTS.md');
touch($dir.'/AGENTS.md');
check(Service_Prx218_Scanner::manifest_meta($dir)['has_agents'] === TRUE, 'manifest_meta видит AGENTS.md');
array_map('unlink', [$dir.'/config/prx_module.php', $dir.'/AGENTS.md']); rmdir($dir.'/config'); rmdir($dir);
