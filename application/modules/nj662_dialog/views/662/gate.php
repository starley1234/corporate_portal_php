<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Плашка «запуск диалога — привилегированным» (решение владельца 09.10.2026).
 *
 * Её видит зарегистрированный аккаунт без роли vip: войти он уже вошёл, поэтому
 * звать его входом бессмысленно (Gate::wall зовёт), а вот объяснить, почему
 * кнопка «начать разговор» не работает и что доступно прямо сейчас, — нужно.
 *
 * Вид держим в одном ключе с partials/auth_wall: та же карточка, тот же «одна
 * мысль — список — кнопка», только тон янтарный (внимание, а не приглашение) и
 * кнопка ведёт в каталог, а не на вход.
 *
 * @var bool     $logged  вошёл ли посетитель
 * @var View|NULL $wall   плашка входа (для гостя)
 */
?>
<div class="rounded-xl border border-amber-300 bg-amber-50/70 p-4 shadow-xs sm:p-5 dark:border-amber-800 dark:bg-amber-950/30"
	data-nj662-gate>

	<span class="n2j-badge n2j-badge-run uppercase tracking-wide">
		<i class="fas fa-user-lock text-[11px]" aria-hidden="true"></i>Запуск — привилегированным
	</span>

	<div class="mt-3 flex items-start gap-3">
		<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/80 text-amber-600 shadow-xs dark:bg-gray-900/60 dark:text-amber-300">
			<i class="fas fa-comments" aria-hidden="true"></i>
		</span>
		<div class="min-w-0">
			<h2 class="n2j-h3">Запускать диалог могут привилегированные аккаунты</h2>
			<p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
				Каждый обмен — это два вызова модели и задача очереди, поэтому запуск разговора открыт аккаунтам с ролью <span class="font-semibold text-gray-800 dark:text-gray-100">vip</span> и администраторам. Право выдаётся отдельно и включается сразу.
			</p>

			<ul class="mt-3 space-y-1.5 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
				<li class="flex items-start gap-2">
					<i class="fas fa-check mt-0.5 text-[11px] text-amber-600 dark:text-amber-400" aria-hidden="true"></i>
					<span><span class="font-semibold text-gray-800 dark:text-gray-100">Читать можно уже сейчас:</span> опубликованные разговоры открыты всем — без роли и без входа.</span>
				</li>
				<li class="flex items-start gap-2">
					<i class="fas fa-check mt-0.5 text-[11px] text-amber-600 dark:text-amber-400" aria-hidden="true"></i>
					<span><span class="font-semibold text-gray-800 dark:text-gray-100">Свои диалоги остаются вашими:</span> то, что вы уже запустили, продолжает идти и редактируется.</span>
				</li>
				<li class="flex items-start gap-2">
					<i class="fas fa-check mt-0.5 text-[11px] text-amber-600 dark:text-amber-400" aria-hidden="true"></i>
					<span><span class="font-semibold text-gray-800 dark:text-gray-100">Роль vip</span> выдаёт администратор сайта — это один флаг в профиле.</span>
				</li>
			</ul>

			<p class="mt-4 flex flex-wrap gap-2">
				<a href="/dialog/list" class="n2j-btn-process">
					<i class="fas fa-book-open text-[13px]" aria-hidden="true"></i>Читать опубликованные
				</a>
			</p>
		</div>
	</div>

	<?php // Гость видит и плашку входа: без аккаунта нет и «своих диалогов». ?>
	<?php if ( ! $logged AND ! empty($wall)): ?>
	<div class="mt-4">
		<?php echo $wall; ?>
	</div>
	<?php endif; ?>
</div>
