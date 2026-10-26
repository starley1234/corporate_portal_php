<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Навигация между страницами графа (nj667): холст → сборка → граф → чертёж.
 * Одна и та же строка под заголовком (h1) на всех четырёх страницах.
 *
 * Переменные: $code — код графа; $nav — текущая страница (p|b|c|d);
 * $owner — владелец (холст виден только ему); $nav_build — id сборки или 0.
 */

$code  = isset($code) ? (string) $code : '';
$nav   = isset($nav) ? (string) $nav : '';
$owner = isset($owner) ? (bool) $owner : FALSE;
$build = isset($nav_build) ? (int) $nav_build : 0;
$c     = rawurlencode($code);

$items = [];

if ($owner)
{
	$items[] = ['p', '/graph3d/p/'.$c, 'Холст', 'fa-diagram-project'];
}

$items[] = ['b', '/graph3d/b/'.$c.($build > 0 ? '?build='.$build : ''), 'Сборка', 'fa-cubes'];
$items[] = ['c', '/graph3d/c/'.$c, 'Граф', 'fa-share-nodes'];
$items[] = ['d', '/graph3d/d/'.$c, 'Чертёж', 'fa-ruler-combined'];
?>
<nav aria-label="Разделы графа" class="no-print mt-2 flex flex-wrap items-center gap-1">
<?php foreach ($items as $it): $on = ($it[0] === $nav); ?>
	<a href="<?php echo HTML::chars($it[1]); ?>" title="<?php echo HTML::chars($it[2]); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>
		class="n2j-chip<?php echo $on ? ' is-on' : ''; ?>">
		<i class="fas <?php echo $it[3]; ?> text-xs"></i><span><?php echo HTML::chars($it[2]); ?></span>
	</a>
<?php endforeach; ?>
</nav>
