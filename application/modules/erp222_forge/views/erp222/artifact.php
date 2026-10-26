<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /forge/artifact/<id> — страница артефакта.
 *
 * Это главная «единица пользы» сервиса, поэтому страница читается как рабочий
 * документ, а не как лог агента:
 *   · шапка: тип, ценность с расшифровкой, кто выпустил, когда, публикация;
 *   · тело документа — HTML (markdown переводит Parsedown в безопасном
 *     режиме), с разбивкой на страницы по заголовкам и листалкой; рядом
 *     «Скопировать» и «Скачать .md» — спецификацию и промпт забирают в
 *     работу, а не читают ради интереса;
 *   · карточка «чем доказывается» из словаря типов: видно, чего не хватает;
 *   · входы, выходы, допущения, открытые вопросы — из метаданных;
 *   · разбор Критика (если был) и связи: на что опирается, кто использовал;
 *   · переключатель публикации у владельца — «взведённая» кнопка в два клика.
 *
 * Дизайн — по эталону /pages/required_design_sample: сетка 2/3 + 1/3 (справа
 * конкретная помощь: оценки, связи, действия), карточки rounded-xl, контролы
 * rounded-lg min-h-[44px], полос нет.
 *
 * @var array  $artifact артефакт (decorate: metadata/scores разобраны)
 * @var array  $pages    документ, разбитый на страницы: [['title','markdown','html'], …]
 * @var array  $links    связи: depends_on / used_by
 * @var array|NULL $world мир артефакта
 * @var array  $events   события мира (что было до и после)
 * @var string $csrf     токен форм
 * @var bool   $is_owner владелец? ему — публикация
 * @var array  $flash    ['tone' => …, 'text' => …]
 */
$scores = (array) $artifact['scores'];
$meta   = (array) $artifact['metadata'];
$pages  = (array) ($pages ?? []);
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php if ( ! empty($flash['text'])): ?>
	<div class="n2j-flash <?php echo $flash['tone'] === 'error' ? 'n2j-flash-err' : 'n2j-flash-ok'; ?> mb-5">
		<i class="fas <?php echo $flash['tone'] === 'error' ? 'fa-circle-exclamation text-rose-600 dark:text-rose-400' : 'fa-circle-check text-emerald-600 dark:text-emerald-400'; ?> shrink-0 text-base"></i>
		<span class="flex-1"><?php echo HTML::chars((string) $flash['text']); ?></span>
	</div>
	<?php endif; ?>

	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/forge/artifacts" class="transition hover:text-blue-600 dark:hover:text-blue-400">Каталог артефактов</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300"><?php echo HTML::chars($artifact['type_title']); ?> #<?php echo (int) $artifact['id']; ?></span>
	</nav>

	<div class="mt-4 grid gap-5 lg:grid-cols-3">

		<!-- ============ Документ ============ -->
		<article class="lg:col-span-2">
			<div class="n2j-card">
				<div class="flex flex-wrap items-center gap-2 text-xs">
					<span class="inline-flex items-center gap-1.5 rounded-md bg-blue-50 px-2.5 py-1 font-semibold text-blue-800 dark:bg-blue-950/60 dark:text-blue-300">
						<i class="<?php echo HTML::chars((string) Arr::path(Service_Erp222_Forge::types(), $artifact['type'].'.icon', 'fas fa-file-lines')); ?> text-[11px]" aria-hidden="true"></i><?php echo HTML::chars($artifact['type_title']); ?>
					</span>
					<?php if ($artifact['published']): ?>
					<span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>опубликован</span>
					<?php else: ?>
					<span class="rounded-md bg-amber-100 px-2.5 py-1 font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">снят с публикации</span>
					<?php endif; ?>
					<span class="rounded-md bg-gray-100 px-2.5 py-1 font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300"><?php echo HTML::chars($artifact['role_title']); ?></span>
					<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $artifact['created_at']))); ?></span>
				</div>

				<h1 class="n2j-h1 mt-3"><?php echo HTML::chars((string) $artifact['title']); ?></h1>

				<?php $lead = trim((string) $artifact['summary']); ?>
				<?php if ($lead !== ''): ?>
				<p class="mt-3 rounded-lg border border-blue-200/70 bg-blue-50/50 p-3.5 text-sm leading-relaxed text-gray-700 dark:border-blue-900/60 dark:bg-blue-950/20 dark:text-gray-300"><?php echo HTML::chars($lead); ?></p>
				<?php endif; ?>

				<?php $proof = (string) Arr::path(Service_Erp222_Forge::types(), $artifact['type'].'.proof', ''); ?>
				<?php if ($proof !== ''): ?>
				<p class="n2j-hint mt-3"><i class="fas fa-circle-info mr-1 text-[11px] text-blue-500" aria-hidden="true"></i>Чем доказывается, что документ «<?php echo HTML::chars(mb_strtolower($artifact['type_title'])); ?>» состоялся: <?php echo HTML::chars($proof); ?>.</p>
				<?php endif; ?>

				<div class="mt-4 flex flex-wrap items-center gap-2">
					<button type="button" class="n2j-btn-secondary"
							data-erp222-copy="<?php echo (int) $artifact['id']; ?>" data-erp222-copy-label="Скопировать документ">
						<i class="fas fa-copy text-[13px]" aria-hidden="true"></i><span data-erp222-copy-text>Скопировать документ</span>
					</button>
					<a href="/forge/artifact/<?php echo (int) $artifact['id']; ?>/md"
					   class="n2j-btn-secondary">
						<i class="fas fa-file-arrow-down text-[13px]" aria-hidden="true"></i>Скачать .md
					</a>
					<?php if ($is_owner AND $world): ?>
					<a href="/forge/world/<?php echo HTML::chars((string) $world['code']); ?>" class="n2j-btn-secondary">
						<i class="fas fa-globe text-[13px]" aria-hidden="true"></i>Мир «<?php echo HTML::chars((string) $world['title']); ?>»
					</a>
					<?php endif; ?>
				</div>
			</div>

			<!-- Тело документа: HTML со страницами -->
			<section class="n2j-card mt-5" data-erp222-doc>
				<div class="flex flex-wrap items-center justify-between gap-3">
					<h2 class="n2j-h3 flex items-center gap-2">
						<i class="fas fa-file-lines text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Документ
					</h2>
					<?php if (count($pages) > 1): ?>
					<div class="flex items-center gap-1.5" role="group" aria-label="Страницы документа">
						<button type="button" data-erp222-page-prev class="n2j-btn-secondary n2j-btn-sm" aria-label="Предыдущая страница">
							<i class="fas fa-chevron-left text-[11px]" aria-hidden="true"></i>
						</button>
						<span class="text-xs font-semibold text-gray-500 dark:text-gray-400">
							<span data-erp222-page-current>1</span> / <?php echo count($pages); ?>
						</span>
						<button type="button" data-erp222-page-next class="n2j-btn-secondary n2j-btn-sm" aria-label="Следующая страница">
							<i class="fas fa-chevron-right text-[11px]" aria-hidden="true"></i>
						</button>
					</div>
					<?php endif; ?>
				</div>

				<?php if ( ! $pages): ?>
					<p class="mt-3 text-sm text-gray-400 dark:text-gray-500">Документ пуст.</p>
				<?php else: ?>
					<?php if (count($pages) > 1): ?>
					<div class="mt-3 flex flex-wrap gap-1.5" data-erp222-page-tabs>
						<?php foreach ($pages as $index => $page): ?>
							<button type="button" data-erp222-page-tab="<?php echo (int) $index; ?>"
									class="n2j-chip block max-w-[16rem] truncate<?php echo $index === 0 ? ' is-on' : ''; ?>"
									title="<?php echo HTML::chars((string) $page['title']); ?>">
								<?php echo (int) ($index + 1); ?>. <?php echo HTML::chars($page['title'] !== '' ? (string) $page['title'] : 'Без заголовка'); ?>
							</button>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>

					<?php foreach ($pages as $index => $page): ?>
						<article class="erp222-doc-page mt-4 <?php echo $index === 0 ? '' : 'hidden'; ?>" data-erp222-page="<?php echo (int) $index; ?>">
							<div class="n2j-prose break-words text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo $page['html']; ?></div>
						</article>
					<?php endforeach; ?>
				<?php endif; ?>

				<?php /* Исходник для кнопки «Скопировать»: копируется именно markdown, а не то, что наэкране. */ ?>
				<div class="hidden" data-erp222-copy-source="<?php echo (int) $artifact['id']; ?>"><?php echo HTML::chars((string) $artifact['body']); ?></div>
			</section>

			<script>
			(function () {
				'use strict';

				var doc = document.querySelector('[data-erp222-doc]');

				if ( ! doc) { return; }

				var pages  = doc.querySelectorAll('[data-erp222-page]');
				var tabs   = doc.querySelectorAll('[data-erp222-page-tab]');
				var out    = doc.querySelector('[data-erp222-page-current]');
				var prev   = doc.querySelector('[data-erp222-page-prev]');
				var next   = doc.querySelector('[data-erp222-page-next]');
				var active = 0;

				if (pages.length < 2) { return; }

				function show(index) {
					active = Math.max(0, Math.min(pages.length - 1, index));

					pages.forEach(function (page) {
						page.classList.toggle('hidden', page.getAttribute('data-erp222-page') !== String(active));
					});

					tabs.forEach(function (tab) {
						var on = tab.getAttribute('data-erp222-page-tab') === String(active);

						tab.className = 'n2j-chip block max-w-[16rem] truncate' + (on ? ' is-on' : '');
					});

					if (out) { out.textContent = String(active + 1); }
					if (prev) { prev.disabled = active === 0; }
					if (next) { next.disabled = active === pages.length - 1; }
				}

				tabs.forEach(function (tab) {
					tab.addEventListener('click', function () { show(parseInt(tab.getAttribute('data-erp222-page-tab'), 10) || 0); });
				});

				if (prev) { prev.addEventListener('click', function () { show(active - 1); }); }
				if (next) { next.addEventListener('click', function () { show(active + 1); }); }

				show(0);
			})();
			</script>

			<?php if (trim((string) $artifact['critique']) !== ''): ?>
			<!-- Разбор Критика -->
			<section class="mt-5 rounded-xl border border-amber-200/80 bg-amber-50/60 p-4 shadow-xs sm:p-6 dark:border-amber-900/60 dark:bg-amber-950/20">
				<h2 class="flex items-center gap-2 text-sm font-bold text-amber-950 dark:text-amber-200">
					<i class="fas fa-scale-balanced text-amber-600 dark:text-amber-400" aria-hidden="true"></i>Разбор Критика
				</h2>
				<p class="mt-1 text-xs text-amber-900/80 dark:text-amber-300/80">Ценность артефакта пересчитана по этой критике — она и показана в каталоге.</p>
				<div class="mt-3 whitespace-pre-wrap break-words text-sm leading-relaxed text-amber-950/90 dark:text-amber-100/90"><?php echo HTML::chars(trim((string) $artifact['critique'])); ?></div>
			</section>
			<?php endif; ?>

			<?php if ( ! empty($meta['inputs']) OR ! empty($meta['outputs'])): ?>
			<!-- Входы и выходы -->
			<section class="mt-5 grid gap-3 sm:grid-cols-2">
				<div class="n2j-card">
					<h3 class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500"><i class="fas fa-arrow-right-to-bracket text-[11px]" aria-hidden="true"></i>Вход</h3>
					<?php if (empty($meta['inputs'])): ?>
					<p class="n2j-hint mt-2">не описан</p>
					<?php else: ?>
					<ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-gray-700 dark:text-gray-200">
						<?php foreach ((array) $meta['inputs'] as $row): ?><li><?php echo HTML::chars((string) $row); ?></li><?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</div>
				<div class="n2j-card">
					<h3 class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500"><i class="fas fa-arrow-right-from-bracket text-[11px]" aria-hidden="true"></i>Выход</h3>
					<?php if (empty($meta['outputs'])): ?>
					<p class="n2j-hint mt-2">не описан</p>
					<?php else: ?>
					<ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-gray-700 dark:text-gray-200">
						<?php foreach ((array) $meta['outputs'] as $row): ?><li><?php echo HTML::chars((string) $row); ?></li><?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</div>
			</section>
			<?php endif; ?>

			<?php if ( ! empty($meta['assumptions']) OR ! empty($meta['open_questions'])): ?>
			<section class="mt-3 grid gap-3 sm:grid-cols-2">
				<?php if ( ! empty($meta['assumptions'])): ?>
				<div class="n2j-card">
					<h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Допущения</h3>
					<ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-gray-700 dark:text-gray-200">
						<?php foreach ((array) $meta['assumptions'] as $row): ?><li><?php echo HTML::chars((string) $row); ?></li><?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
				<?php if ( ! empty($meta['open_questions'])): ?>
				<div class="rounded-xl border border-dashed border-gray-300 bg-white p-4 shadow-xs dark:border-gray-600 dark:bg-gray-800/60">
					<h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Открытые вопросы</h3>
					<ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-gray-700 dark:text-gray-200">
						<?php foreach ((array) $meta['open_questions'] as $row): ?><li><?php echo HTML::chars((string) $row); ?></li><?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
			</section>
			<?php endif; ?>
		</article>

		<!-- ============ Сайдбар: оценки, связи, действия ============ -->
		<aside class="space-y-4 lg:self-start">

			<div class="n2j-card">
				<div class="flex items-center justify-between gap-3">
					<h3 class="text-sm font-bold text-gray-900 dark:text-white">Ценность</h3>
					<span class="rounded-md bg-emerald-50 px-2.5 py-1 text-sm font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><?php echo (int) $artifact['value_score']; ?>/100</span>
				</div>
				<p class="n2j-hint"><?php echo HTML::chars($artifact['score_word']); ?> — свёртка четырёх осей по весам.</p>
				<dl class="mt-3 space-y-2 text-xs">
					<?php
					$axes = [
						'usefulness'   => 'Полезность',
						'concreteness' => 'Конкретность',
						'novelty'      => 'Новизна',
						'feasibility'  => 'Реализуемость',
					];
					?>
					<?php foreach ($axes as $key => $label): ?>
					<div class="flex items-center justify-between gap-3">
						<dt class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($label); ?></dt>
						<dd class="font-mono font-bold tabular-nums text-gray-800 dark:text-gray-100"><?php echo (int) round(100 * (float) Arr::get($scores, $key, 0.5)); ?></dd>
					</div>
					<?php endforeach; ?>
				</dl>
				<?php if (trim((string) $artifact['critique']) === ''): ?>
				<p class="n2j-hint mt-3 rounded-lg border border-dashed border-gray-300 px-3 py-2">Критик ещё не разбирал этот артефакт: ценность пока авторская.</p>
				<?php endif; ?>
			</div>

			<div class="n2j-card">
				<h3 class="text-sm font-bold text-gray-900 dark:text-white">Связи</h3>
				<?php if (empty($links['depends_on']) AND empty($links['used_by'])): ?>
				<p class="n2j-hint mt-2">Связей нет — артефакт стоит сам по себе.</p>
				<?php else: ?>
				<?php if ( ! empty($links['depends_on'])): ?>
				<p class="mt-3 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Опирается на</p>
				<ul class="mt-1.5 space-y-1.5 text-xs">
					<?php foreach ($links['depends_on'] as $l): ?>
					<li><a href="/forge/artifact/<?php echo (int) $l['id']; ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $l['title']); ?></a><span class="ml-1 text-gray-400 dark:text-gray-500"><?php echo HTML::chars(Service_Erp222_Forge::type_title((string) $l['type'])); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<?php if ( ! empty($links['used_by'])): ?>
				<p class="mt-3 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Использовали</p>
				<ul class="mt-1.5 space-y-1.5 text-xs">
					<?php foreach ($links['used_by'] as $l): ?>
					<li><a href="/forge/artifact/<?php echo (int) $l['id']; ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $l['title']); ?></a><span class="ml-1 text-gray-400 dark:text-gray-500"><?php echo HTML::chars(Service_Erp222_Forge::type_title((string) $l['type'])); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<?php endif; ?>
			</div>

			<?php if ( ! empty($artifact['sources'])): ?>
			<div class="n2j-card">
				<h3 class="text-sm font-bold text-gray-900 dark:text-white">Опора</h3>
				<p class="n2j-hint">На чём стоял артефакт: находки ИИ-радара, ссылки, чужие работы.</p>
				<ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
					<?php foreach ((array) $artifact['sources'] as $source): ?>
					<li class="break-words"><?php echo HTML::chars((string) $source); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<?php if ($is_owner): ?>
			<div class="n2j-card">
				<h3 class="text-sm font-bold text-gray-900 dark:text-white">Публикация</h3>
				<p class="n2j-hint"><?php echo $artifact['published']
						? 'Артефакт в каталоге и в карте сайта.'
						: 'Артефакт скрыт из каталога — страница работает только у вас.'; ?></p>
				<form method="post" action="/forge/artifact/<?php echo (int) $artifact['id']; ?>/publish" class="mt-3">
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<input type="hidden" name="published" value="<?php echo $artifact['published'] ? '0' : '1'; ?>">
					<button data-n2j-arm="<?php echo $artifact['published'] ? 'Точно снять?' : 'Точно вернуть?'; ?>" title="<?php echo $artifact['published'] ? 'Снять артефакт с публикации — он пропадёт из каталога?' : 'Вернуть артефакт в каталог?'; ?>" type="submit" class="n2j-btn-secondary">
						<i class="fas <?php echo $artifact['published'] ? 'fa-eye-slash' : 'fa-eye'; ?> text-[13px]" aria-hidden="true"></i><?php echo $artifact['published'] ? 'Снять с публикации' : 'Вернуть в каталог'; ?>
					</button>
				</form>
			</div>
			<?php endif; ?>

			<?php if ($world): ?>
			<div class="rounded-xl border border-blue-200/80 bg-blue-50/20 p-4 shadow-xs sm:p-5 dark:border-blue-900/40 dark:bg-blue-950/10">
				<h3 class="text-sm font-bold text-gray-900 dark:text-white">Мир</h3>
				<p class="mt-1 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars(mb_substr(trim((string) $world['brief']), 0, 220)); ?><?php echo mb_strlen((string) $world['brief']) > 220 ? '…' : ''; ?></p>
				<?php if ($is_owner): ?>
				<a href="/forge/world/<?php echo HTML::chars((string) $world['code']); ?>" class="mt-3 inline-flex min-h-[36px] items-center gap-1.5 text-xs font-semibold text-blue-600 transition hover:underline dark:text-blue-400">Страница мира<i class="fas fa-arrow-right text-[11px]" aria-hidden="true"></i></a>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</aside>
	</div>
</div>
