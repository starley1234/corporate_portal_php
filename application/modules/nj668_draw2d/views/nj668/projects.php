<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Проекты» (nj668): мои иллюстрации.
 *
 * Удаление — маленькая иконка, вторым нажатием (confirm/alert в проекте нет).
 */

$logged    = isset($logged) ? (bool) $logged : FALSE;
$mine      = isset($mine) ? (array) $mine : [];
$statuses  = isset($statuses) ? (array) $statuses : [];
$csrf      = isset($csrf) ? (string) $csrf : '';
$rate_text = isset($rate_text) ? (string) $rate_text : '';
$limits    = isset($limits) ? (array) $limits : [];

$badges = [
	'draft'     => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
	'published' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
	'rejected'  => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-end justify-between gap-3">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-folder-open mr-2 text-blue-500"></i>Проекты</h1>
			<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Ваши иллюстрации: черновики и уже в каталоге. Новую сборку — на странице <a href="/draw2d/create" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Создать</a>.</p>
		</div>
		<a href="/draw2d/create" class="n2j-btn-cta">Новая иллюстрация</a>
	</div>

	<?php if (empty($logged)): ?>
	<div class="mt-6">
		<?php echo Gate::wall('draw2d', [
			'icon'   => 'fas fa-folder-open',
			'title'  => 'Проекты — по аккаунту',
			'text'   => 'Список ваших иллюстраций хранится на сервере. Войдите, чтобы видеть черновики и продолжать правку. Каталог открыт и без входа.',
			'points' => [
				'Черновики и опубликованные — в одном списке',
				'Удаление иконкой, вторым нажатием',
				'Лимит: '.$rate_text,
			],
			'stats'  => ['до '.(int) Arr::get($limits, 'max_projects', 200).' работ'],
			'cta'    => 'Войти к проектам',
		]); ?>
	</div>
	<?php elseif ( ! $mine): ?>
	<div class="mt-6 rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-900">
		<p class="text-sm text-gray-500 dark:text-gray-400">Иллюстраций пока нет.</p>
		<a href="/draw2d/create" class="mt-3 inline-flex min-h-[44px] items-center text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">Описать сцену →</a>
	</div>
	<?php else: ?>
	<ul class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
		<?php foreach ($mine as $row): ?>
		<li class="n2j-card flex gap-3 p-3">
			<a href="/draw2d/p/<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>" class="shrink-0">
				<?php echo Service_Nj668_Draw2d::preview_img($row, '', 'h-16 w-24 rounded-md border border-gray-200 object-contain dark:border-gray-700'); ?>
			</a>
			<div class="min-w-0 flex-1">
				<div class="flex items-start gap-2">
					<a href="/draw2d/p/<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>"
						class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 hover:underline dark:text-white">
						<?php echo HTML::chars((string) Arr::get($row, 'title')); ?>
					</a>
					<span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold <?php echo Arr::get($badges, (string) Arr::get($row, 'status'), Arr::get($badges, 'draft')); ?>">
						<?php echo HTML::chars(Arr::get($statuses, (string) Arr::get($row, 'status'), (string) Arr::get($row, 'status'))); ?>
					</span>
				</div>
				<p class="n2j-hint mt-0.5 truncate">
					фигур <?php echo (int) Arr::get($row, 'shapes_count'); ?>
					· <?php echo HTML::chars((string) Arr::get($row, 'updated_at')); ?>
				</p>
				<div class="mt-1.5 flex items-center justify-end">
					<button data-n2j-arm="Удалить?" type="button" data-nj668-del="<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>"
						title="Удалить" aria-label="Удалить"
						class="n2j-btn-icon-danger">
						<i class="fas fa-trash-alt text-xs" aria-hidden="true"></i>
					</button>
				</div>
			</div>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
</div>

<script>
(function () {
	var csrf = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;

	// Удаление — вторым нажатием (data-n2j-arm, app.js): первое взводит иконку
	// и сюда не доходит; клик мимо и Esc снимают взвод.
	Array.prototype.forEach.call(document.querySelectorAll('[data-nj668-del]'), function (btn) {
		btn.addEventListener('click', function (event) {
			event.preventDefault();
			btn.disabled = true;
			fetch('/draw2d/api/delete', {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify({csrf: csrf, code: btn.getAttribute('data-nj668-del')})
			}).then(function (res) { return res.json(); }).then(function (data) {
				if (data && data.ok) {
					var card = btn.closest('li');
					if (card) { card.remove(); }
					return;
				}
				btn.disabled = false;
				N2J.toast((data && data.error) || 'Удалить не вышло.', 0, 'err');
			}).catch(function () {
				btn.disabled = false;
				N2J.toast('Сеть не отвечает, попробуйте ещё раз.', 0, 'err');
			});
		});
	});
}());
</script>
