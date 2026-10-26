<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичная страница графа из каталога (nj667).
 *
 * Открыта всем: страница — витрина работы. Граф показывается тем же холстом,
 * но в режиме «только чтение»: крутить можно, править нельзя, а рядом всегда
 * лежит сам JSON — источник правды, ради которого всё и затевалось.
 */

$project    = isset($project) ? (array) $project : [];
$graph      = isset($graph) ? (array) $graph : ['meta' => [], 'nodes' => [], 'edges' => []];
$logged     = isset($logged) ? (bool) $logged : FALSE;
$can_start  = isset($can_start) ? (bool) $can_start : FALSE;
$can_moderate = isset($can_moderate) ? (bool) $can_moderate : FALSE;
$node_types = isset($node_types) ? (array) $node_types : [];
$edge_types = isset($edge_types) ? (array) $edge_types : [];
$csrf       = isset($csrf) ? (string) $csrf : '';

$code   = (string) Arr::get($project, 'code');
$owner  = isset($owner) ? (bool) $owner : FALSE;
$pub_build   = isset($pub_build) ? $pub_build : NULL;
$can_moderate = isset($can_moderate) ? (bool) $can_moderate : FALSE;
$pub_id      = $pub_build ? (int) Arr::get($pub_build, 'id', 0) : 0;
$build_href = '/graph3d/b/'.rawurlencode($code).($pub_id > 0 ? '?build='.$pub_id : '');
$nodes  = (array) Arr::get($graph, 'nodes', []);
$edges  = (array) Arr::get($graph, 'edges', []);
$tags   = array_filter(array_map('trim', explode(',', (string) Arr::get($project, 'tags', ''))));
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
		<h1 class="n2j-h1 break-words">
			<?php echo ((int) Arr::get($project, 'featured') === 1) ? '<i class="fas fa-star mr-1.5 text-amber-400"></i>' : '<i class="fas fa-cube mr-1.5 text-blue-500"></i>'; ?>
			<?php echo HTML::chars((string) Arr::get($project, 'title')); ?>
		</h1>
	</div>

	<?php echo View::factory('nj667/_nav', ['code' => $code, 'nav' => 'c', 'owner' => $owner])->render(); ?>

	<p class="mt-1.5 text-sm text-gray-600 dark:text-gray-300">
		<?php echo HTML::chars((string) Arr::get($project, 'summary') ?: Service_Nj667_Graph3d::graph_text($graph)); ?>
	</p>

	<?php if ($tags): ?>
	<div class="mt-3 flex flex-wrap gap-1.5">
		<?php foreach ($tags as $tag): ?>
		<a href="/graph3d/catalog?q=<?php echo rawurlencode((string) $tag); ?>"
			class="n2j-chip">#<?php echo HTML::chars((string) $tag); ?></a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="mt-5 gap-5 lg:grid lg:grid-cols-3 lg:items-start">

		<div class="min-w-0 lg:col-span-2">
			<div id="nj667-view-canvas" class="relative h-[420px] w-full overflow-hidden rounded-xl border border-gray-200 bg-white sm:h-[520px] dark:border-gray-800 dark:bg-gray-900"></div>
			<p class="n2j-hint mt-2">
				Крутите мышкой, колесо — приближение. Это просмотр: править можно в своей копии.
			</p>
			<div class="mt-3 flex flex-wrap gap-2">
				<button type="button" id="nj667-export-glb-btn" class="n2j-btn-secondary"><i class="fas fa-cube mr-1"></i>Скачать GLB</button>
				<button type="button" id="nj667-export-json-btn" class="n2j-btn-secondary"><i class="fas fa-code mr-1"></i>Скачать JSON</button>
			</div>

			<div class="n2j-card mt-5">
				<h2 class="n2j-h3"><i class="fas fa-code mr-1.5 text-blue-500"></i>Топология (источник правды)</h2>
				<p class="n2j-hint">Граф хранится узлами и связями, а не картинкой: картинку можно перерисовать, а геометрию из неё — нет.</p>
				<pre class="mt-3 max-h-80 overflow-auto rounded-lg bg-gray-950 p-3 text-xs leading-relaxed text-gray-200"><?php echo HTML::chars(json_encode([
					'meta'  => Arr::get($graph, 'meta', []),
					'nodes' => $nodes,
					'edges' => $edges,
				], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
			</div>
		</div>

		<div class="mt-5 min-w-0 space-y-5 lg:mt-0">
			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-cubes mr-1.5 text-blue-500"></i>Сборка</h2>
				<div class="mt-3 flex flex-wrap items-center gap-4">
					<?php if ($pub_id > 0): ?>
					<a href="<?php echo HTML::chars($build_href); ?>" class="shrink-0">
						<img src="<?php echo HTML::chars($build_href.'&file=card'); ?>" width="160" height="160" loading="lazy"
							alt="Изометрия сборки «<?php echo HTML::chars((string) Arr::get($project, 'title')); ?>»"
							class="h-40 w-40 rounded-md bg-blue-50/60 object-contain dark:bg-gray-800" onerror="this.remove()">
					</a>
					<?php endif; ?>
					<div class="min-w-0 flex-1">
						<p class="text-xs text-gray-600 dark:text-gray-300">
							<?php echo $pub_id > 0 ? 'Опубликованная сборка: модель, детали на печать и спецификация.' : 'Опубликованной сборки пока нет. На странице сборки можно собрать модель из этого графа.'; ?>
						</p>
						<a href="<?php echo HTML::chars($build_href); ?>"
							class="n2j-btn-process n2j-btn-sm mt-3">
							<i class="fas fa-arrow-right"></i>Перейти к сборке
						</a>
					</div>
				</div>
			</div>

			<div class="n2j-card">
				<ul class="space-y-1 text-xs text-gray-600 dark:text-gray-300">
					<li>узлов: <b class="font-semibold text-gray-900 dark:text-white"><?php echo (int) count($nodes); ?></b></li>
					<li>связей: <b class="font-semibold text-gray-900 dark:text-white"><?php echo (int) count($edges); ?></b></li>
					<li>просмотров: <b class="font-semibold text-gray-900 dark:text-white"><?php echo (int) Arr::get($project, 'views'); ?></b></li>
					<li>копий: <b class="font-semibold text-gray-900 dark:text-white"><?php echo (int) Arr::get($project, 'remixes'); ?></b></li>
					<?php if ($can_moderate AND (string) Arr::get($project, 'model', '') !== ''): ?>
					<li>модель: <b class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($project, 'model')); ?></b></li>
					<?php endif; ?>
				</ul>
			</div>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-shapes mr-1.5 text-blue-500"></i>Узлы</h2>
				<ul class="mt-3 max-h-72 space-y-1.5 overflow-y-auto text-xs">
					<?php foreach ($nodes as $node): ?>
					<li class="flex items-center gap-2">
						<span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: <?php echo HTML::chars((string) Arr::get(Arr::get($node_types, (string) Arr::get($node, 'type', ''), []), 'color', '#3b82f6')); ?>"></span>
						<span class="truncate text-gray-700 dark:text-gray-300"><?php
							$type_title = (string) Arr::get(Arr::get($node_types, (string) Arr::get($node, 'type', ''), []), 'title', Arr::get($node, 'type'));
							echo HTML::chars($type_title.' · '.(string) Arr::get($node, 'id'));
						?></span>
						<span class="ml-auto shrink-0 font-mono text-xs text-gray-400">
							<?php echo number_format((float) Arr::get($node, 'x', 0), 1); ?>,<?php echo number_format((float) Arr::get($node, 'y', 0), 1); ?>,<?php echo number_format((float) Arr::get($node, 'z', 0), 1); ?>
						</span>
					</li>
					<?php endforeach; ?>
					<?php if ( ! $nodes): ?><li class="text-gray-500 dark:text-gray-400">Граф пуст.</li><?php endif; ?>
				</ul>
			</div>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-clone mr-1.5 text-blue-500"></i>Использовать как шаблон</h2>
				<p class="n2j-hint">Копия попадёт в ваши проекты: правьте как хотите, оригинал не изменится.</p>

				<form method="post" action="/graph3d/catalog" class="mt-3">
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<input type="hidden" name="remix" value="<?php echo HTML::chars($code); ?>">
					<input type="hidden" name="r" value="<?php echo (int) Arr::get($project, 'revision_no', 0); ?>">
					<button type="submit" <?php echo ($logged AND $can_start) ? '' : 'disabled'; ?>
						class="n2j-btn-process w-full">
						Скопировать себе
					</button>
					<?php if ($logged AND ! $can_start): ?>
					<p class="mt-2 text-xs text-amber-700 dark:text-amber-400">Копировать могут аккаунты с правом nj667.start (роль login, vip или admin).</p>
					<?php elseif ( ! $logged): ?>
					<p class="n2j-hint mt-2">Нужен бесплатный аккаунт.</p>
					<?php endif; ?>
				</form>
			</div>

			<?php if ($can_moderate): ?>
			<a href="/graph3d/moderate?code=<?php echo HTML::chars($code); ?>"
				class="block rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
				<i class="fas fa-gavel mr-1.5"></i>Открыть в модерации
			</a>
			<?php endif; ?>
		</div>
	</div>
</div>

<script type="importmap">
{
	"imports": {
		"three": "/assets/vendor/three/mod/three.module.js",
		"three/addons/": "/assets/vendor/three/mod/addons/"
	}
}
</script>

<script type="application/json" id="nj667-config">
<?php echo json_encode([
	'code'       => $code,
	'csrf'       => $csrf,
	'title'      => (string) Arr::get($project, 'title', ''),
	'graph'      => $graph,
	'readonly'   => TRUE,
	'canvas'     => Service_Nj667_Graph3d::canvas(),
	'defaults'   => ['mode' => 'translate', 'grid' => 1, 'snap' => 0, 'labels' => 0],
	'limits'     => Service_Nj667_Graph3d::limits(),
	'node_types' => $node_types,
	'edge_types' => $edge_types,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>

<script type="module">
	window.NJ667 = JSON.parse(document.getElementById('nj667-config').textContent);
</script>
<script type="module" src="/assets/js/nj667-graph3d.js?v=20261015"></script>
