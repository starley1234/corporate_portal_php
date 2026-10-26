<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Панель модератора каталога графов (nj667).
 *
 * Слева — живой просмотр графа (тот же холст, но только чтение) и структура
 * с пометками AI-Guard. Справа — вердикт: одобрить, отметить как образец или
 * отклонить. Причина по желанию (владелец, 10.10.2026).
 *
 * Отдельно показано, что успела накопать пре-модерация модели: её вердикт —
 * подсказка, а не решение.
 */

$queue      = isset($queue) ? (array) $queue : [];
$current    = isset($current) ? (array) $current : NULL;
$graph      = isset($graph) ? (array) $graph : ['meta' => [], 'nodes' => [], 'edges' => []];
$guard      = isset($guard) ? (array) $guard : [];
$events     = isset($events) ? (array) $events : [];
$status     = isset($status) ? (string) $status : '';
$statuses   = isset($statuses) ? (array) $statuses : [];
$node_types = isset($node_types) ? (array) $node_types : [];
$edge_types = isset($edge_types) ? (array) $edge_types : [];
$csrf       = isset($csrf) ? (string) $csrf : '';

$settings = json_decode((string) Arr::get((array) $current, 'settings_json', ''), TRUE);
$ai       = is_array($settings) ? (array) Arr::get($settings, 'guard', []) : [];

$badges = [
	'draft'     => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
	'review'    => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
	'published' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
	'rejected'  => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
];

$ai_badge = [
	'ok'     => ['bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', 'чисто'],
	'review' => ['bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'посмотреть'],
	'reject' => ['bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300', 'подозрительно'],
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<h1 class="n2j-h1"><i class="fas fa-gavel mr-2 text-blue-500"></i>Модерация графов</h1>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Слева — живой граф и проверка структуры (AI-Guard): пустой ли он, нет ли узлов без связей, не вышел ли за лимиты. Справа — решение и причина. Модель успела проверить только текст; структуру проверяет сайт, а решение принимаете вы.</p>

	<div class="mt-4 flex flex-wrap gap-2">
		<a href="/graph3d/moderate" class="n2j-chip<?php echo $status === '' ? ' is-on' : ''; ?>">Очередь</a>
		<?php foreach ($statuses as $key => $title): ?>
		<a href="/graph3d/moderate?status=<?php echo rawurlencode((string) $key); ?>"
			class="n2j-chip<?php echo $status === (string) $key ? ' is-on' : ''; ?>">
			<?php echo HTML::chars((string) $title); ?>
		</a>
		<?php endforeach; ?>
	</div>

	<div class="mt-5 gap-5 lg:grid lg:grid-cols-3 lg:items-start">

		<!-- ==================== Очередь (1/3) ==================== -->
		<div class="min-w-0">
			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-list mr-1.5 text-blue-500"></i>Заявки</h2>

				<?php if ( ! $queue): ?>
				<p class="n2j-hint mt-3">Ничего нет.</p>
				<?php else: ?>
				<ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
					<?php foreach ($queue as $row): ?>
					<?php
						$rid = (int) Arr::get($row, 'revision_id', 0);
						$active = $current && (
							($rid > 0 AND $rid === (int) Arr::get($current, 'revision_id', 0))
							OR ($rid === 0 AND (int) Arr::get($row, 'id') === (int) Arr::get($current, 'id'))
						);
						$q = '/graph3d/moderate?code='.rawurlencode((string) Arr::get($row, 'code'));
						if ($rid > 0) { $q .= '&revision_id='.$rid; }
						if ($status !== '') { $q .= '&status='.rawurlencode($status); }
					?>
					<li>
						<a href="<?php echo HTML::chars($q); ?>"
							class="flex items-center gap-2 py-2 <?php echo $active ? 'font-semibold text-blue-600 dark:text-blue-400' : ''; ?>">
							<?php echo Service_Nj667_Graph3d::preview_img($row, '', 'h-8 w-12 shrink-0 rounded border border-gray-200 object-contain dark:border-gray-700'); ?>
							<span class="min-w-0 flex-1 truncate text-xs text-gray-800 dark:text-gray-200">
								<?php echo HTML::chars((string) Arr::get($row, 'title')); ?>
								<?php if ((int) Arr::get($row, 'revision_no', 0) > 0): ?>
								<span class="font-normal text-gray-400">№<?php echo (int) Arr::get($row, 'revision_no'); ?></span>
								<?php endif; ?>
							</span>
							<span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold <?php echo Arr::get($badges, (string) Arr::get($row, 'status'), Arr::get($badges, 'draft')); ?>">
								<?php echo HTML::chars(Arr::get($statuses, (string) Arr::get($row, 'status'), (string) Arr::get($row, 'status'))); ?>
							</span>
						</a>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</div>
		</div>

		<!-- ==================== Просмотр (1/3) ==================== -->
		<div class="mt-5 min-w-0 lg:mt-0">
			<?php if ($current === NULL): ?>
			<div class="n2j-card text-center text-sm text-gray-500 dark:text-gray-400">
				Выберите заявку слева.
			</div>
			<?php else: ?>
			<div id="nj667-canvas" class="relative h-[360px] w-full overflow-hidden rounded-xl border border-gray-200 bg-white sm:h-[420px] dark:border-gray-800 dark:bg-gray-900"></div>

			<div class="n2j-card mt-4">
				<h2 class="n2j-h3"><i class="fas fa-shield-halved mr-1.5 text-blue-500"></i>AI-Guard: структура</h2>

				<div class="mt-2 flex flex-wrap gap-2 text-xs">
					<span class="rounded-full px-2 py-0.5 font-semibold <?php echo Arr::get($guard, 'ok') ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300'; ?>">
						<?php echo Arr::get($guard, 'ok') ? 'пройдена' : 'есть замечания'; ?>
					</span>
					<span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">узлов: <?php echo (int) Arr::get(Arr::get($guard, 'stats', []), 'nodes', 0); ?></span>
					<span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">связей: <?php echo (int) Arr::get(Arr::get($guard, 'stats', []), 'edges', 0); ?></span>
					<span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">без связей: <?php echo (int) Arr::get(Arr::get($guard, 'stats', []), 'isolated', 0); ?></span>
				</div>

				<?php if ( ! Arr::get($guard, 'ok')): ?>
				<ul class="mt-3 space-y-1 text-xs text-rose-700 dark:text-rose-400">
					<?php foreach ((array) Arr::get($guard, 'errors', []) as $text): ?>
					<li><i class="fas fa-circle-exclamation mr-1"></i><?php echo HTML::chars((string) $text); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>

				<?php if ($ai): ?>
				<div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
					<h3 class="text-xs font-semibold text-gray-700 dark:text-gray-300">Пре-модерация текста (модель)</h3>
					<div class="mt-1.5 flex items-center gap-2 text-xs">
						<?php $row_ai = Arr::get($ai_badge, (string) Arr::get($ai, 'verdict', 'review'), Arr::get($ai_badge, 'review')); ?>
						<span class="rounded-full px-2 py-0.5 font-semibold <?php echo $row_ai[0]; ?>"><?php echo HTML::chars($row_ai[1]); ?></span>
						<span class="text-gray-500 dark:text-gray-400">уверенность <?php echo number_format((float) Arr::get($ai, 'score', 0) * 100, 0); ?>%</span>
					</div>
					<?php if ((string) Arr::get($ai, 'note', '') !== ''): ?>
					<p class="mt-1.5 text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($ai, 'note')); ?></p>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</div>

			<?php if ($events): ?>
			<div class="n2j-card mt-4">
				<h2 class="n2j-h3"><i class="fas fa-clock-rotate-left mr-1.5 text-blue-500"></i>Журнал</h2>
				<ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
					<?php foreach ($events as $event): ?>
					<li><span class="font-semibold"><?php echo HTML::chars((string) Arr::get($event, 'kind')); ?></span> — <?php echo HTML::chars((string) Arr::get($event, 'note')); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
			<?php endif; ?>
		</div>

		<!-- ==================== Вердикт (1/3) ==================== -->
		<div class="mt-5 min-w-0 lg:mt-0">
			<?php if ($current !== NULL): ?>
			<?php $code = (string) Arr::get($current, 'code'); ?>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-stamp mr-1.5 text-blue-500"></i>Решение</h2>

				<p id="nj667-caption-title" class="mt-2 text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($current, 'title')); ?></p>
				<p id="nj667-caption-summary" class="n2j-hint"><?php echo HTML::chars((string) Arr::get($current, 'summary')); ?></p>
				<p id="nj667-caption-tags" class="mt-1 text-xs text-gray-400<?php echo trim((string) Arr::get($current, 'tags', '')) === '' ? ' hidden' : ''; ?>"><?php echo HTML::chars((string) Arr::get($current, 'tags')); ?></p>

				<button type="button" id="nj667-caption-btn"
					data-code="<?php echo HTML::chars($code); ?>"
					data-csrf="<?php echo HTML::chars($csrf); ?>"
					data-revision="<?php echo (int) Arr::get($current, 'revision_id', 0); ?>"
					class="n2j-btn-secondary mt-3 w-full">
					<i class="fas fa-rotate mr-1.5"></i>Перегенерировать название и описание
				</button>
				<p id="nj667-caption-note" class="n2j-hint mt-1.5 hidden"></p>

				<form method="post" action="/graph3d/api/moderate" class="mt-4 space-y-3" id="nj667-verdict">
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<input type="hidden" name="code" value="<?php echo HTML::chars($code); ?>">
					<input type="hidden" name="revision_id" value="<?php echo (int) Arr::get($current, 'revision_id', 0); ?>">

					<div>
						<label for="nj667-note" class="n2j-label">Причина <span class="font-normal text-gray-400">(по желанию)</span></label>
						<textarea id="nj667-note" name="note" rows="3" maxlength="500"
							placeholder="Например: узлы без связей — не публикуем"
							class="n2j-textarea mt-1 w-full resize-y"></textarea>
					</div>

					<div class="grid gap-2">
						<button type="submit" name="verdict" value="approve"
							class="n2j-btn-cta">Одобрить</button>
						<button type="submit" name="verdict" value="feature"
							class="n2j-btn-secondary">Одобрить и в образцы</button>
						<?php if ((int) Arr::get($current, 'featured') === 1): ?>
						<button type="submit" name="verdict" value="unfeature"
							class="n2j-btn-secondary">Снять «образец»</button>
						<?php endif; ?>
						<button type="submit" name="verdict" value="reject"
							class="n2j-btn-danger">Отклонить</button>
					</div>
				</form>

				<p class="n2j-hint mt-3">Форма отправляет JSON на <code>/graph3d/api/moderate</code>; без JS решение не пройдёт — панель для администратора, JS там есть всегда.</p>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php if ($current !== NULL): ?>
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
	'code'       => (string) Arr::get($current, 'code'),
	'csrf'       => $csrf,
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

<script>
	// Вердикт отправляем сами: ответ сервера показываем на месте, а не теряем
	// в перезагрузке (модератор должен видеть причину отказа).
	(function () {
		var form = document.getElementById('nj667-verdict');
		if (!form) { return; }

		// Кнопка-вердикт запоминается по клику: submit сам по себе не знает,
		// какая из четырёх кнопок его вызвала.
		var CHOICE = 'approve';

		Array.prototype.forEach.call(form.querySelectorAll('button[name="verdict"]'), function (btn) {
			btn.addEventListener('click', function () { CHOICE = btn.value; });
		});

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var data = new FormData(form);
			var note = String(data.get('note') || '').trim();

			fetch('/graph3d/api/moderate', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
					'X-Requested-With': 'XMLHttpRequest' },
					body: JSON.stringify({
						csrf: data.get('csrf'), code: data.get('code'),
						revision_id: Number(data.get('revision_id') || 0),
						verdict: CHOICE, note: note
					})
			}).then(function (r) { return r.json(); }).then(function (data) {
				if (!data.ok) {
					form.insertAdjacentHTML('afterbegin',
						'<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">'
						+ String(data.error || 'Не вышло.').replace(/[<>&]/g, '') + '</p>');
					return;
				}

				window.location.href = '/graph3d/moderate';
			});
		});
	})();

	// Название и описание — отдельная дешёвая модель, без confirm()
	// (владелец, 11.10.2026).
	(function () {
		var btn = document.getElementById('nj667-caption-btn');
		if (!btn) { return; }

		var note = document.getElementById('nj667-caption-note');
		var titleEl = document.getElementById('nj667-caption-title');
		var summaryEl = document.getElementById('nj667-caption-summary');
		var tagsEl = document.getElementById('nj667-caption-tags');
		var timer = 0;
		var jobId = 0;
		var tries = 0;
		var idle = btn.innerHTML;

		function show(text, bad) {
			if (!note) { return; }
			note.textContent = text || '';
			note.classList.toggle('hidden', !text);
			note.classList.toggle('text-rose-600', !!bad);
			note.classList.toggle('dark:text-rose-400', !!bad);
		}

		function stop() {
			if (timer) { clearInterval(timer); timer = 0; }
			btn.disabled = false;
			btn.innerHTML = idle;
		}

		function poll() {
			tries += 1;
			if (tries > 45) {
				stop();
				show('Модель не ответила вовремя.', true);
				return;
			}

			fetch('/graph3d/api/caption?code=' + encodeURIComponent(btn.getAttribute('data-code') || '')
				+ '&job=' + jobId
				+ '&revision_id=' + Number(btn.getAttribute('data-revision') || 0), {
				headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
			}).then(function (r) { return r.json(); }).then(function (data) {
				if (!data || !data.ok) {
					stop();
					show(String((data && data.error) || 'Не вышло.'), true);
					return;
				}

				var job = data.job || {};
				var status = String(job.status || job.state || '').toLowerCase();

				if (data.failed) {
					stop();
					show(String(data.error || 'Модель не написала название.'), true);
					return;
				}

				if (/^(done|finished|complete|success)$/.test(status)) {
					if (titleEl) { titleEl.textContent = data.title || titleEl.textContent; }
					if (summaryEl) { summaryEl.textContent = data.summary || ''; }
					if (tagsEl) {
						tagsEl.textContent = data.tags || '';
						tagsEl.classList.toggle('hidden', !(data.tags || ''));
					}
					stop();
					show('Готово.');
					return;
				}

				show('Модель пишет название…');
			}).catch(function () {
				stop();
				show('Сеть не ответила.', true);
			});
		}

		btn.addEventListener('click', function () {
			if (btn.disabled) { return; }
			btn.disabled = true;
			tries = 0;
			show('Ставлю задачу…');

			fetch('/graph3d/api/caption', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
					'X-Requested-With': 'XMLHttpRequest' },
				body: JSON.stringify({
					csrf: btn.getAttribute('data-csrf') || '',
					code: btn.getAttribute('data-code') || '',
					revision_id: Number(btn.getAttribute('data-revision') || 0)
				})
			}).then(function (r) { return r.json(); }).then(function (data) {
				if (!data || !data.ok) {
					stop();
					show(String((data && data.error) || 'Не вышло.'), true);
					return;
				}

				jobId = Number(data.job_id || 0);
				if (!jobId) {
					stop();
					show('Очередь не приняла задачу.', true);
					return;
				}

				show('Модель пишет название…');
				poll();
				timer = setInterval(poll, 2000);
			}).catch(function () {
				stop();
				show('Сеть не ответила.', true);
			});
		});
	})();
</script>
<?php endif; ?>
