<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Блок готового комплекта: превью файлов, ссылки, HTML-код.
 *
 * Один и тот же блок рендерит PHP (форма без JS: POST на /favicon-gen) —
 * views/663/script.php строит такую же разметку после запроса к API.
 *
 * @var array  $files список файлов [['name', 'label', 'bytes', 'size', 'url'], …]
 * @var string $html  готовый HTML для <head>
 * @var string $code  код набора
 */
$files = isset($files) ? (array) $files : [];
$html  = isset($html) ? (string) $html : '';
$code  = isset($code) ? (string) $code : '';

$zip = NULL;
$ico = NULL;

foreach ($files as $file)
{
	if (substr((string) Arr::get($file, 'name', ''), -4) === '.zip')
	{
		$zip = $file;
	}

	if (substr((string) Arr::get($file, 'name', ''), -4) === '.ico')
	{
		$ico = $file;
	}
}
?>
<div class="nj663-files mt-5 border-t border-gray-200 pt-5 dark:border-gray-700">
	<div class="flex flex-wrap items-center gap-3">
		<h3 class="n2j-h3">Комплект готов</h3>
		<?php if ($zip): ?>
			<a href="<?php echo HTML::chars((string) Arr::get($zip, 'url', '')); ?>"
			   class="n2j-btn-process">
				<i class="fas fa-file-zipper"></i>Скачать zip (<?php echo number_format((int) Arr::get($zip, 'bytes', 0) / 1024, 0, '.', ' '); ?> КБ)
			</a>
		<?php endif; ?>
		<?php if ($ico): ?>
			<a href="<?php echo HTML::chars((string) Arr::get($ico, 'url', '')); ?>"
			   class="n2j-btn-secondary">
				<i class="fas fa-file-image text-amber-600"></i>favicon.ico
			</a>
		<?php endif; ?>
		<?php if ($code !== ''): ?>
			<a href="/favicon-gen/p/<?php echo HTML::chars($code); ?>"
			   class="n2j-btn-secondary">
				<i class="fas fa-link"></i>Ссылка на набор
			</a>
		<?php endif; ?>
	</div>

	<!-- превью PNG -->
	<div class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
		<?php foreach ($files as $file): ?>
			<?php if (substr((string) Arr::get($file, 'name', ''), -4) !== '.png') { continue; } ?>
			<a href="<?php echo HTML::chars((string) Arr::get($file, 'url', '')); ?>"
			   download="<?php echo HTML::chars((string) Arr::get($file, 'name', '')); ?>"
			   title="Скачать <?php echo HTML::chars((string) Arr::get($file, 'name', '')); ?>"
			   class="group rounded-lg border border-gray-200 p-2 text-center transition hover:border-amber-500 dark:border-gray-700">
				<img src="<?php echo HTML::chars((string) Arr::get($file, 'url', '')); ?>"
					 alt="<?php echo HTML::chars((string) Arr::get($file, 'label', '')); ?>"
					 loading="lazy" class="mx-auto h-12 w-12 object-contain">
				<span class="mt-1 block truncate text-xs font-medium text-gray-600 group-hover:text-amber-600 dark:text-gray-300">
					<?php echo HTML::chars((string) Arr::get($file, 'label', '')); ?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>

	<!-- остальные файлы -->
	<ul class="mt-4 space-y-1.5 text-sm">
		<?php foreach ($files as $file): ?>
			<?php if (substr((string) Arr::get($file, 'name', ''), -4) === '.png') { continue; } ?>
			<li class="flex items-center gap-2 text-gray-600 dark:text-gray-300">
				<i class="fas fa-file-lines text-gray-400"></i>
				<a href="<?php echo HTML::chars((string) Arr::get($file, 'url', '')); ?>"
				   download="<?php echo HTML::chars((string) Arr::get($file, 'name', '')); ?>"
				   class="font-mono text-xs text-amber-600 hover:underline dark:text-amber-400"><?php echo HTML::chars((string) Arr::get($file, 'name', '')); ?></a>
				<span class="text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars((string) Arr::get($file, 'label', '')); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>

	<!-- HTML-код -->
	<?php if ($html !== ''): ?>
		<div class="mt-5">
			<div class="flex items-center justify-between gap-3">
				<h4 class="text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">HTML для &lt;head&gt;</h4>
				<button type="button" class="n2j-btn-secondary n2j-btn-sm nj663-copy">
					<i class="far fa-copy"></i>Скопировать
				</button>
			</div>
			<pre class="nj663-html mt-2 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs leading-relaxed text-gray-100"><code><?php echo HTML::chars($html); ?></code></pre>
		</div>
	<?php endif; ?>
</div>
