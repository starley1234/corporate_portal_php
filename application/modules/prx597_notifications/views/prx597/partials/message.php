<?php defined('SYSPATH') or die('No direct script access.'); ?>

	<div class="prx-card p-0 mt-4">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prx-h3">Сообщение от системы</h2>
			<p class="prx-hint mt-0.5">Одному пользователю, только в колокольчик и ленту. Без почты и мессенджеров. Сообщение добавится даже при выключенных уведомлениях на сайте.</p>
		</div>
		<form autocomplete="off" method="post" action="/prx597/admin" class="prx597-busy grid gap-3 px-4 py-4 sm:grid-cols-2">
			<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
			<input type="hidden" name="act" value="notify_user">
			<input type="hidden" name="tab" value="message">
			<label class="sm:col-span-2 block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">ID или точный email пользователя</span>
				<input type="text" name="recipient" required maxlength="254" placeholder="Например: 42 или user@example.com" class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="sm:col-span-2 block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Заголовок</span>
				<input type="text" name="title" required maxlength="180" value="Сообщение от системы" class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="sm:col-span-2 block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Текст (до 2000 символов, без HTML)</span>
				<textarea autocomplete="off" name="body" required rows="5" maxlength="2000" class="prx-textarea mt-1 w-full"></textarea>
			</label>
			<div class="sm:col-span-2">
				<button type="submit" class="prx-btn-cta"><i class="fas fa-paper-plane mr-2"></i>Отправить пользователю</button>
			</div>
		</form>
	</div>
