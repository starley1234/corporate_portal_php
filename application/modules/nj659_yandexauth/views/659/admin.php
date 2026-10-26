<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php /** @var array $rows @var string $csrf @var string $notice */ ?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fab fa-yandex text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Вход через Яндекс · связки</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Диагностика OAuth: какие аккаунты Яндекса с какими пользователями связаны, когда входили в последний раз. Отвязка доступна здесь и у владельца связки.</p>
		</div>
		<span class="n2j-badge n2j-badge-wait"><?php echo count($rows); ?> связей</span>
	</div>

	<?php if ($notice): ?>
		<p class="n2j-flash n2j-flash-ok mt-5 block"><?php echo HTML::chars($notice); ?></p>
	<?php endif; ?>

	<?php if ( ! $rows): ?>
		<div class="mt-6 rounded-xl border border-dashed border-blue-200 bg-blue-50/60 p-6 text-center dark:border-blue-900 dark:bg-blue-950/20">
			<i class="fab fa-yandex text-2xl text-blue-500" aria-hidden="true"></i>
			<p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Связок пока нет</p>
			<p class="n2j-hint mx-auto max-w-md">Пользователи появятся здесь после первого входа через кнопку «Войти через Яндекс» на странице входа.</p>
		</div>
	<?php else: ?>
		<div class="n2j-card p-0 mt-6 overflow-x-auto">
			<table class="n2j-table">
				<thead>
					<tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
						<th class="px-4 py-3 font-semibold">Аккаунт Яндекса</th>
						<th class="px-4 py-3 font-semibold">Пользователь сайта</th>
						<th class="hidden px-4 py-3 font-semibold md:table-cell">Последний вход</th>
						<th class="px-4 py-3 text-right font-semibold">Действия</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-700">
					<?php foreach ($rows as $row): ?>
						<tr class="transition hover:bg-gray-50 dark:hover:bg-gray-900/60">
							<td class="px-4 py-2.5">
								<div class="flex items-center gap-2.5">
									<span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300" aria-hidden="true"><i class="fas fa-user"></i></span>
									<div class="min-w-0">
										<p class="truncate font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars(trim($row['first_name'].' '.$row['last_name']) !== '' ? trim($row['first_name'].' '.$row['last_name']) : $row['email']); ?></p>
										<p class="n2j-hint truncate font-mono">id <?php echo HTML::chars($row['provider_id']); ?></p>
									</div>
								</div>
							</td>
							<td class="px-4 py-2.5">
								<p class="truncate text-gray-800 dark:text-gray-200"><?php echo HTML::chars($row['user_email']); ?></p>
								<p class="n2j-hint truncate"><?php echo HTML::chars($row['username']); ?></p>
							</td>
							<td class="hidden px-4 py-2.5 text-gray-500 md:table-cell dark:text-gray-400">
								<?php echo $row['updated_at'] !== '' ? HTML::chars($row['updated_at']) : '—'; ?>
							</td>
							<td class="px-4 py-2.5 text-right">
								<form method="post" action="/auth/yandex/unlink" class="inline">
									<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
									<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
									<input type="hidden" name="back" value="/nj659/admin">
									<button type="submit" class="n2j-btn-secondary n2j-btn-sm">Отвязать</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<p class="n2j-hint mt-8">Настройки провайдера — в .env (YANDEX_OAUTH_*). Если кнопки на странице входа нет — провайдер не настроен.</p>
</div>
