<?php
/** Настоящие формы лимитов и общая память форм для jsdom-регрессии. */
$root = is_dir('/app/application') ? '/app' : dirname(__DIR__);
if ( ! defined('SYSPATH')) { define('SYSPATH', $root.'/system/'); }
require $root.'/system/classes/Kohana/Arr.php';
require $root.'/system/classes/Arr.php';
class HTML
{
	public static function chars($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
set_error_handler(function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});
$users = [];
foreach ([6 => [5, 5], 2 => [7, 11], 1 => [999, 30], 7 => [0, 0]] as $id => $values)
{
	$users[] = [
		'id' => $id, 'username' => 'Пользователь '.$id, 'email' => '',
		'effective_limits' => ['active_projects' => $values[0], 'llm_operations_per_day' => $values[1]],
		'active_count' => 0, 'daily_usage' => ['used' => 0],
	];
}
$total = count($users);
$sorts = ['new' => 'Сначала новые'];
$csrf = 'limits-test';
require $root.'/application/modules/nj667_graph3d/views/nj667/limits.php';
require $root.'/application/views/partials/prefs_js.php';
