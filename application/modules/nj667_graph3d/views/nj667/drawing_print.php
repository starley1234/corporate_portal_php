<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Лист чертежа на печать / «Сохранить как PDF» (владелец, 11.10.2026).
 *
 * Без шаблона сайта: иначе в PDF уедут шапка и меню. Браузер сам пишет PDF.
 */

$plan  = isset($plan) ? (array) $plan : [];
$svg   = isset($svg) ? (string) $svg : '';
$opt   = isset($opt) ? (array) $opt : [];
$title = (string) Arr::get($plan, 'title', 'Чертёж');
$paper = (string) Arr::get($opt, 'paper', 'a4') === 'a3' ? 'A3' : 'A4';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="utf-8">
	<title><?php echo HTML::chars($title); ?> — чертёж</title>
	<?php /* design-audit: allow style — отдельная страница печати без шаблона и app.css; @page с выбранным форматом */ ?>
	<style>
		@page { size: <?php echo $paper; ?> landscape; margin: 8mm; }
		html, body { margin: 0; padding: 0; background: #fff; }
		svg { display: block; width: 100%; height: auto; }
		.hint { display: none; font: 12px/1.4 sans-serif; color: #6b7280; padding: 12px 16px; }
		@media screen {
			.hint { display: block; }
			body { padding: 12px; background: #e5e7eb; }
			svg { background: #fff; box-shadow: 0 1px 4px rgba(17, 24, 39, .12); }
		}
	</style>
</head>
<body>
	<p class="hint">В диалоге печати выберите «Сохранить как PDF» и размер <?php echo $paper; ?> альбом. Это окно можно закрыть.</p>
	<?php echo $svg; ?>
	<script>
		window.addEventListener('load', function () {
			window.print();
		});
	</script>
</body>
</html>
