<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница готового набора: /favicon-gen/p/<code>.
 *
 * Код набора — capability-ссылка: её можно отправить коллеге или вернуться к
 * ней позже (30 дней). Файлы лежат в storage/nj663/<код>, отдача — по белому
 * списку имён.
 *
 * @var array  $pack ['code', 'kind', 'html', 'base_path', 'bytes', 'created_at', 'files']
 * @var string $url  адрес этой страницы
 */
$pack  = isset($pack) ? (array) $pack : [];
$files = (array) Arr::get($pack, 'files', []);
$code  = (string) Arr::get($pack, 'code', '');

$preview = '';
$zip     = NULL;
$ico     = NULL;
$others  = [];
$pngs    = [];

foreach ($files as $file)
{
	$name = (string) Arr::get($file, 'name', '');

	if ($name === 'android-chrome-512x512.png' OR $name === 'favicon-512x512.png')
	{
		if ($preview === '' OR $name === 'favicon-512x512.png')
		{
			$preview = (string) Arr::get($file, 'url', '');
		}
	}

	if (substr($name, -4) === '.zip')
	{
		$zip = $file;
	}
	elseif (substr($name, -4) === '.ico')
	{
		$ico = $file;
	}
	elseif (substr($name, -4) === '.png')
	{
		$pngs[] = $file;
	}
	else
	{
		$others[] = $file;
	}
}

$label = 'text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="mt-2 flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex flex-wrap items-center gap-3">
				<i class="fas fa-icons text-amber-600"></i>Комплект иконок
				<span class="rounded-full bg-amber-50 px-3 py-1 font-mono text-sm font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950/60 dark:text-amber-300"><?php echo HTML::chars($code); ?></span>
			</h1>
			<p class="mt-1.5 max-w-3xl text-xs sm:text-sm text-gray-600 dark:text-gray-300">
				Собрано <?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) Arr::get($pack, 'created_at', 'now')))); ?>
				— всего <?php echo (int) count($files); ?> файлов,
				<?php echo number_format((int) Arr::get($pack, 'bytes', 0) / 1024, 0, '.', ' '); ?> КБ.
				Набор живёт 30 дней, ссылку можно сохранить или отправить.
			</p>
		</div>
		<a href="/favicon-gen" class="n2j-btn-process">
			<i class="fas fa-wand-magic-sparkles"></i>Собрать свой
		</a>
	</div>

	<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
		<div class="space-y-6 md:col-span-2">

			<!-- превью и скачивание -->
			<div class="n2j-card">
				<div class="flex flex-wrap items-center gap-5">
					<?php if ($preview !== ''): ?>
						<img src="<?php echo HTML::chars($preview); ?>" alt="Иконка 512×512"
							 class="n2j-checker h-28 w-28 rounded-2xl border border-gray-200 dark:border-gray-700">
					<?php endif; ?>
					<div class="flex flex-wrap items-center gap-3">
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
					</div>
				</div>

				<!-- PNG -->
				<h2 class="mt-6 <?php echo $label; ?>">PNG всех размеров</h2>
				<div class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
					<?php foreach ($pngs as $file): ?>
						<a href="<?php echo HTML::chars((string) Arr::get($file, 'url', '')); ?>"
						   download="<?php echo HTML::chars((string) Arr::get($file, 'name', '')); ?>"
						   title="<?php echo HTML::chars((string) Arr::get($file, 'name', '')); ?>"
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

				<!-- остальное -->
				<?php if ($others): ?>
					<ul class="mt-5 space-y-1.5 text-sm">
						<?php foreach ($others as $file): ?>
							<li class="flex items-center gap-2 text-gray-600 dark:text-gray-300">
								<i class="fas fa-file-lines text-gray-400"></i>
								<a href="<?php echo HTML::chars((string) Arr::get($file, 'url', '')); ?>"
								   download="<?php echo HTML::chars((string) Arr::get($file, 'name', '')); ?>"
								   class="font-mono text-xs text-amber-600 hover:underline dark:text-amber-400"><?php echo HTML::chars((string) Arr::get($file, 'name', '')); ?></a>
								<span class="text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars((string) Arr::get($file, 'label', '')); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<!-- HTML-код -->
			<?php if ((string) Arr::get($pack, 'html', '') !== ''): ?>
				<div class="n2j-card">
					<div class="flex items-center justify-between gap-3">
						<h2 class="text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">HTML для &lt;head&gt;</h2>
						<button type="button" class="n2j-btn-secondary n2j-btn-sm nj663-copy">
							<i class="far fa-copy"></i>Скопировать
						</button>
					</div>
					<pre class="nj663-html mt-2 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs leading-relaxed text-gray-100"><code><?php echo HTML::chars((string) Arr::get($pack, 'html', '')); ?></code></pre>
					<p class="n2j-hint mt-2">
						Файлы кладём по пути <code class="font-mono"><?php echo HTML::chars((string) Arr::get($pack, 'base_path', '/')); ?></code>
						— его можно было задать в форме перед сборкой.
					</p>
				</div>
			<?php endif; ?>
		</div>

		<!-- справка -->
		<div class="space-y-6">
			<div class="n2j-card">
				<h2 class="text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">Как подключить</h2>
				<ol class="mt-3 list-decimal space-y-1.5 pl-5 text-sm text-gray-600 dark:text-gray-300">
					<li>Скачайте zip и распакуйте файлы в указанный путь сайта.</li>
					<li>Вставьте HTML-код в <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">&lt;head&gt;</code>.</li>
					<li>Обновите страницу с жёсткой перезагрузкой (Ctrl+F5) — браузер любит кэшировать favicon.ico.</li>
				</ol>
			</div>
			<div class="n2j-card">
				<h2 class="text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">Для разработчика</h2>
				<p class="n2j-hint mt-2">Состав набора в JSON:</p>
				<a href="/favicon-gen/api/pack/<?php echo HTML::chars($code); ?>"
				   class="mt-1 block font-mono text-xs text-amber-600 hover:underline dark:text-amber-400">/favicon-gen/api/pack/<?php echo HTML::chars($code); ?></a>
			</div>
		</div>
	</div>
</div>

<script>
document.addEventListener('click', function (event) {
	var btn = event.target.closest ? event.target.closest('.nj663-copy') : null;

	if ( ! btn) { return; }

	var pre = btn.parentNode.parentNode.querySelector('.nj663-html');

	if ( ! pre || ! navigator.clipboard) { return; }

	navigator.clipboard.writeText(pre.textContent).then(function () {
		btn.innerHTML = '<i class="fas fa-check"></i> Скопировано';
		setTimeout(function () { btn.innerHTML = '<i class="far fa-copy"></i> Скопировать'; }, 2000);
	});
});
</script>
