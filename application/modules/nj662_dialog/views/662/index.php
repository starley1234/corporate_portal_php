<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /dialog — рабочий стол «Диалога двух ИИ».
 *
 * «Быстрое безгеморройное начало» (слово владельца): тема → одна кнопка →
 * разговор идёт. Поэтому форма одна, а кнопок в ней две:
 *   «Подобрать агентов и начать» (quick=go)    — имена и характеры придумает
 *       модель, первый обмен встанет в очередь сразу;
 *   «Создать и настроить» (quick=edit)         — открыть диалог с настройками:
 *       модели, обмены, контекст, суммаризация, поиск в интернете, материалы.
 *
 * Гость видит плашку входа (partials/auth_wall) и не видит ни формы, ни списка:
 * сервис работает на аккаунт, потому что каждый обмен — это работа моделей.
 *
 * С 09.10.2026 запуск разговора — привилегированным (роль vip или админ):
 * зарегистрированный аккаунт без права видит вместо формы объяснение (662/gate)
 * и свои прежние диалоги, а читать опубликованные может без всякого права.
 *
 * @var bool   $logged    залогинен ли посетитель
 * @var bool   $can_start есть ли право запускать разговор (vip или админ)
 * @var View|NULL $gate   плашка «запуск — привилегированным»
 * @var View|NULL $wall   плашка входа для гостя
 * @var array  $dialogs   мои диалоги (карточками)
 * @var array  $counters  счётчики рабочего стола
 * @var array  $samples   примеры тем для быстрого старта
 * @var array  $defaults  умолчания хода (обмены, контекст, суммаризация, поиск)
 * @var array  $limits    пределы полей
 * @var array  $roles     участники по умолчанию
 * @var string $csrf      токен форм
 * @var array  $flash     ['tone' => notice|error, 'text' => …]
 */

// Модель по умолчанию — первая в списке конфига: подпись на кнопке должна
// совпадать с тем, что уедет в диалог.
$model_label = Service_Nj662_Models::default_spec() !== ''
	? Service_Nj662_Models::label(Service_Nj662_Models::default_spec())
	: 'модель воркера';

$numbers = function ($n) {
	return number_format((int) $n, 0, ',', ' ');
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-nj662-view data-nj662-run="0">

	<?php if ( ! empty($flash['text'])): ?>
	<div class="n2j-flash <?php echo $flash['tone'] === 'error' ? 'n2j-flash-err' : 'n2j-flash-ok'; ?> mb-5">
		<i class="fas <?php echo $flash['tone'] === 'error' ? 'fa-circle-exclamation text-rose-600 dark:text-rose-400' : 'fa-circle-check text-emerald-600 dark:text-emerald-400'; ?> shrink-0 text-base"></i>
		<span class="flex-1"><?php echo HTML::chars((string) $flash['text']); ?></span>
	</div>
	<?php endif; ?>

	<!-- ============ Шапка ============ -->
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<span class="font-semibold text-gray-700 dark:text-gray-300">Диалог двух ИИ</span>
	</nav>

	<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3">
				<i class="fas fa-comments text-blue-600 dark:text-blue-400"></i>Диалог двух ИИ
			</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
				Дайте тему — два агента обсудят её по делу: один держит структуру и критерии, второй ищет ход и упрощает проверку. Разговор ведёт сервер: каждый обмен идёт задачей очереди, поэтому закрытая вкладка разговор не останавливает.
			</p>
		</div>
		<a href="/dialog/list" class="n2j-btn-secondary">
			<i class="fas fa-book-open text-[13px]" aria-hidden="true"></i>Опубликованные
		</a>
	</div>

	<?php if ( ! $logged): ?>
	<div class="mt-5">
		<?php echo $wall; ?>
	</div>

	<?php else: ?>

	<?php if ( ! $can_start): ?>
	<div class="mt-5">
		<?php echo $gate; ?>
	</div>
	<?php else: ?>

	<!-- ============ Быстрый старт ============ -->
	<section class="mt-5 rounded-xl border border-blue-200/80 bg-blue-50/40 p-4 shadow-xs sm:p-6 dark:border-blue-900/60 dark:bg-blue-950/20" data-nj662-create-box>
		<h2 class="n2j-h3">О чём поговорят агенты</h2>
		<p class="n2j-hint mt-1.5">
			Напишите тему — агентов подберу сам, первый обмен встанет в очередь сразу. Модель: <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($model_label); ?></span> (меняется в настройках диалога).
		</p>

		<form method="post" action="/dialog/new" class="mt-4" data-nj662-create>
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

			<label for="nj662-topic" class="n2j-label">Тема разговора</label>
			<textarea id="nj662-topic" name="topic" rows="2" maxlength="<?php echo (int) Arr::get($limits, 'topic_max', 300); ?>"
				placeholder="Например: как проверить спрос на новую услугу за неделю и без бюджета"
				class="n2j-textarea mt-1.5 w-full"
				data-nj662-topic required></textarea>

			<?php if ( ! empty($samples)): ?>
			<p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-400 dark:text-gray-500">
				<span>Готовые темы:</span>
				<?php foreach ($samples as $sample): ?>
				<button type="button" data-nj662-sample="<?php echo HTML::chars((string) $sample); ?>"
					class="n2j-btn-secondary n2j-btn-sm max-w-full truncate">
					<?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) $sample, 52)); ?>
				</button>
				<?php endforeach; ?>
			</p>
			<?php endif; ?>

			<details class="mt-3">
				<summary class="cursor-pointer text-xs font-semibold text-gray-500 transition hover:text-blue-600 dark:text-gray-400">Что уже известно по теме (необязательно)</summary>
				<textarea name="brief" rows="3" maxlength="<?php echo (int) Arr::get($limits, 'brief_max', 2000); ?>"
					placeholder="Факты, цифры, условия — агенты получат это до первого вопроса и не будут выяснять заново"
					class="n2j-textarea mt-2 w-full"></textarea>
			</details>

			<div class="mt-4 flex flex-wrap items-center gap-3">
				<button type="submit" name="quick" value="go"
					class="n2j-btn-process">
					<i class="fas fa-wand-magic-sparkles text-[13px]" aria-hidden="true"></i>Подобрать агентов и начать
				</button>
				<button type="submit" name="quick" value="edit"
					class="n2j-btn-secondary">
					<i class="fas fa-sliders text-[13px]" aria-hidden="true"></i>Создать и настроить
				</button>
			</div>
			<p class="n2j-hint mt-2">Разговор идёт в фоне: можно уйти со страницы и вернуться — реплики сохранятся.</p>
		</form>
	</section>

	<?php endif; ?><!-- /can_start: формы создания -->

	<!-- ============ Счётчики ============ -->
	<div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Мои диалоги</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $numbers(Arr::get($counters, 'dialogs', 0)); ?></p>
			<p class="n2j-hint mt-0.5">идут сейчас: <?php echo $numbers(Arr::get($counters, 'running', 0)); ?></p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Завершено</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $numbers(Arr::get($counters, 'done', 0)); ?></p>
			<p class="n2j-hint mt-0.5">готовых разборов темы</p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Опубликовано</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $numbers(Arr::get($counters, 'public', 0)); ?></p>
			<p class="n2j-hint mt-0.5">
				<a href="/dialog/list" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">каталог разговоров</a>
			</p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Умолчания хода</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) Arr::get($defaults, 'pairs', 5); ?><span class="text-sm font-semibold text-gray-400"> обменов</span></p>
			<p class="n2j-hint mt-0.5"><?php echo HTML::chars(Service_Nj662_Dialog::ddg_label((int) Arr::get($defaults, 'ddg', 0))); ?></p>
		</div>
	</div>

	<!-- ============ Мои диалоги ============ -->
	<section class="n2j-card p-0 mt-5">
		<div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-700/60">
			<h2 class="n2j-h3 flex items-center gap-2">
				<i class="fas fa-list-check text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Мои диалоги
			</h2>
			<span class="n2j-badge n2j-badge-wait ml-auto"><?php echo $numbers(Arr::get($counters, 'dialogs', 0)); ?></span>
		</div>

		<?php if (empty($dialogs)): ?>
		<p class="px-4 py-6 text-sm text-gray-500 sm:px-5 dark:text-gray-400">
			<?php echo $can_start
				? 'Диалогов пока нет. Напишите тему выше — и агенты начнут разговор. Готовые темы подставляются одним кликом.'
				: 'Своих диалогов пока нет. Запустить разговор могут привилегированные аккаунты (роль vip) и администратор; опубликованные разговоры открыты всем.'; ?>
		</p>
		<?php else: ?>
		<div class="divide-y divide-gray-100 dark:divide-gray-700/60">
			<?php foreach ($dialogs as $d): ?>
			<?php
				$prog = Service_Nj662_Dialog::progress($d);
				$tone_class = [
					'live'  => 'bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200',
					'ok'    => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
					'warn'  => 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
					'error' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
					'plain' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
				];
			?>
			<article class="flex flex-wrap items-start gap-3 px-4 py-3.5 transition-colors hover:bg-gray-50/70 sm:px-5 dark:hover:bg-gray-700/20">
				<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400">
					<i class="fas <?php echo $prog['live'] ? 'fa-spinner animate-spin' : 'fa-comments'; ?>" aria-hidden="true"></i>
				</div>
				<div class="min-w-0 flex-1">
					<div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
						<span class="rounded-md px-2 py-0.5 font-semibold <?php echo $tone_class[$prog['tone']]; ?>"><?php echo HTML::chars($prog['status_word']); ?></span>
						<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars($prog['title']); ?></span>
						<span class="text-gray-300 dark:text-gray-600">·</span>
						<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars($prog['turn_word']); ?></span>
						<?php if ((int) $d['searches_used'] > 0): ?>
						<span class="text-gray-300 dark:text-gray-600">·</span>
						<span class="text-gray-400 dark:text-gray-500" title="поиски агентов в интернете"><i class="fas fa-magnifying-glass text-[10px]"></i> <?php echo (int) $d['searches_used']; ?></span>
						<?php endif; ?>
						<?php if (Service_Nj662_Dialog::as_bool($d['is_public'])): ?>
						<span class="rounded-md bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">опубликован</span>
						<?php endif; ?>
					</div>
					<h3 class="mt-1 text-sm font-bold leading-snug text-gray-900 dark:text-white">
						<a href="/dialog/<?php echo (int) $d['id']; ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) $d['topic'], 140)); ?></a>
					</h3>
					<?php if (trim((string) $d['last_reply']) !== ''): ?>
					<p class="n2j-hint mt-0.5">
						<span class="font-semibold text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $d['last_author']); ?>:</span>
						<?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) $d['last_reply'], 160)); ?>
					</p>
					<?php endif; ?>
				</div>
				<div class="shrink-0 text-right">
					<p class="n2j-hint"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $d['created_at']))); ?></p>
					<p class="n2j-hint mt-0.5">реплик: <?php echo (int) $d['replies']; ?></p>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</section>

	<?php endif; ?>
</div>
