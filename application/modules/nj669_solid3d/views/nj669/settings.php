<?php defined('SYSPATH') or die('No direct script access.');
$card = 'rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-4';
$in = 'n2j-input mt-1 w-full py-1.5 text-sm min-h-0';
$lab = 'n2j-label text-xs';
$muted = 'text-xs text-gray-500 dark:text-gray-400';
$fields = [
	'model' => ['Модель генерации кода', 'text'],
	'vision_model' => ['Модель проверки ракурсов (ИИ)', 'text'],
	'light_model' => ['Лёгкая модель идей и карточек', 'text'],
	'projects' => ['Проектов по умолчанию', 'number'],
	'daily_runs' => ['Запусков в день по умолчанию', 'number'],
	'repairs' => ['Автоисправлений сборки (0–2)', 'number'],
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<?php echo View::factory('nj669/head', ['error' => $error]); ?>
<div class="flex flex-wrap items-end justify-between gap-2">
	<h1 class="n2j-h1">3D-детали · настройки</h1>
	<a href="/solid3d/limits" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Лимиты →</a>
</div>
<?php if ($saved): ?><p class="n2j-flash n2j-flash-ok mt-3 text-xs block" role="status">Настройки сохранены. Уже поставленные задачи используют свой снимок настроек.</p><?php endif; ?>

<form method="post" action="/solid3d/settings" class="<?php echo $card; ?> mt-3 max-w-3xl" autocomplete="off" data-n2j-no-remember>
	<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
	<div class="grid gap-3 sm:grid-cols-2">
	<?php foreach ($fields as $key => $f): ?>
		<div class="<?php echo in_array($key, ['model', 'vision_model', 'light_model'], TRUE) ? 'sm:col-span-2' : ''; ?>">
			<label for="solid-<?php echo $key; ?>" class="<?php echo $lab; ?>"><?php echo $f[0]; ?></label>
			<input id="solid-<?php echo $key; ?>" name="<?php echo $key; ?>" type="<?php echo $f[1]; ?>" autocomplete="off" data-n2j-no-remember class="<?php echo $in; ?>"
				<?php if ($f[1] === 'number'): ?>required min="0" max="<?php echo $key === 'repairs' ? '2' : '1000'; ?>"<?php else: ?>maxlength="200"<?php endif; ?>
				value="<?php echo HTML::chars((string) $settings[$key]); ?>">
		</div>
	<?php endforeach; ?>
	</div>
	<p class="mt-3 <?php echo $muted; ?>">Модель в виде <span class="font-mono">провайдер::модель</span>. Пустая модель генерации — дефолт воркера; пустая модель проверки — общая VLM_MODEL; пустая лёгкая модель — дефолт воркера.</p>
	<button class="n2j-btn-cta mt-4">Сохранить настройки</button>
</form>
<p class="mt-3 max-w-3xl <?php echo $muted; ?>">Сборка настраивается только в окружении воркера. Секреты и адреса провайдеров здесь не показываются.</p>
</div>
