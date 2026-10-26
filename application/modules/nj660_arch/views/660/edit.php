<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php /** @var array $dsl @var string $title @var string $code @var array $palette @var array $families @var string $csrf @var string $error */
$error = isset($error) ? $error : ''; ?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-diagram-project text-blue-600 dark:text-blue-400" aria-hidden="true"></i><?php echo $code !== '' ? 'Правка архитектуры' : 'Новая архитектура'; ?></h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Соберите стек блоков: входы и модальности → позиционирование → слои (внимание, FFN, MoE, SSM) → хуки в середину → выход. Метрики справа пересчитываются на каждое изменение.</p>
		</div>
		<?php if ($code !== ''): ?>
			<a href="/arch/view/<?php echo HTML::chars($code); ?>" class="n2j-btn-secondary">← К странице модели</a>
		<?php endif; ?>
	</div>

	<?php if ($error !== ''): ?>
		<p class="n2j-flash n2j-flash-err mt-5 block"><?php echo HTML::chars($error); ?></p>
	<?php endif; ?>

	<form method="post" action="/arch/save" id="nj660-form" class="mt-5">
		<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
		<input type="hidden" name="code" value="<?php echo HTML::chars($code); ?>">
		<input type="hidden" name="dsl" id="nj660-dsl" value="">
		<div class="n2j-card flex flex-wrap items-center gap-3">
			<div class="min-w-[14rem] flex-1">
				<label class="n2j-label" for="nj660-title">Название проекта</label>
				<input id="nj660-title" name="title" maxlength="120" class="n2j-input mt-1.5 w-full" value="<?php echo HTML::chars($title); ?>" placeholder="Мой MoE-помощник">
			</div>
			<button type="submit" class="n2j-btn-cta"><i class="fas fa-floppy-disk mr-2" aria-hidden="true"></i>Сохранить</button>
		</div>
	</form>

	<div class="mt-5 grid gap-5 xl:grid-cols-[16rem_minmax(0,1fr)_20rem]">
		<!-- Палитра -->
		<aside class="space-y-3">
			<div class="n2j-card">
				<h2 class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Блоки</h2>
				<div id="nj660-palette" class="mt-3 space-y-4"></div>
			</div>
		</aside>

		<!-- Стек -->
		<main class="min-w-0 space-y-4">
			<div class="n2j-card">
				<h2 class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Общая конфигурация</h2>
				<div class="mt-3 grid gap-3 sm:grid-cols-3">
					<div>
						<label class="n2j-label" for="nj660-family">Семейство</label>
						<select id="nj660-family" class="n2j-input n2j-select mt-1.5 w-full">
							<?php foreach ($families as $fam => $hint): ?>
								<option value="<?php echo HTML::chars($fam); ?>"><?php echo HTML::chars($fam); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="n2j-label" for="nj660-d">d_model — ширина</label>
						<input id="nj660-d" type="number" min="64" max="65536" step="64" class="n2j-input mt-1.5 w-full">
					</div>
					<div>
						<label class="n2j-label" for="nj660-s">Контекст S (токенов)</label>
						<input id="nj660-s" type="number" min="64" max="2000000" step="64" class="n2j-input mt-1.5 w-full">
					</div>
				</div>
			</div>

			<div class="n2j-card">
				<div class="flex items-center justify-between">
					<h2 class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Стек модели</h2>
					<span class="text-xs text-gray-400 dark:text-gray-500">порядок = поток данных, ↑↓ двигают блоки</span>
				</div>
				<div id="nj660-stack" class="mt-3 space-y-2"></div>
				<p id="nj660-empty" class="mt-3 hidden rounded-xl border border-dashed border-blue-200 bg-blue-50/60 p-4 text-center text-sm text-gray-500 dark:border-blue-900 dark:bg-blue-950/20 dark:text-gray-400">Стек пуст — добавьте блоки из палитры слева.</p>
			</div>
		</main>

		<!-- Метрики -->
		<aside class="space-y-4">
			<div class="n2j-card">
				<h2 class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Метрики</h2>
				<div id="nj660-metrics" class="mt-3 space-y-3 text-sm"><p class="n2j-hint">Считаем…</p></div>
			</div>
		</aside>
	</div>
</div>

<script>
window.NJ660 = <?php echo json_encode([
	'palette' => $palette,
	'dsl'     => $dsl,
	'csrf'    => $csrf,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
</script>
