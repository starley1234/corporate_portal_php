<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/** @var array $design @var array $dsl @var array $eval @var bool $mine @var bool $logged
 *  @var array $reviews @var array $chats @var string $csrf @var string $engine @var int $llm_left
 *  @var bool $flash_saved @var bool $flash_reviewed @var string $error */
// опциональные флеш-переменные: дефолты, чтобы рендер не зависел от набора аргументов
$flash_saved    = isset($flash_saved) ? $flash_saved : FALSE;
$flash_reviewed = isset($flash_reviewed) ? $flash_reviewed : FALSE;
$error = isset($error) ? $error : '';
$f = 'Service_Nj660_Arch';
$t = $eval['totals'];
$fmt = [$f, 'fmt_params'];
$bytes = [$f, 'fmt_bytes'];
$num = [$f, 'fmt_num'];
$ratio = [$f, 'fmt_ratio'];
$warn_style = [
	'error' => 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300',
	'warn'  => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
	'info'  => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200',
	'ok'    => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
];
$warn_icon = ['error' => 'fa-circle-xmark', 'warn' => 'fa-triangle-exclamation', 'info' => 'fa-circle-info', 'ok' => 'fa-circle-check'];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-nj660-view data-code="<?php echo HTML::chars($design['code']); ?>">
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/arch" class="transition hover:text-blue-600 dark:hover:text-blue-400">Конструктор архитектуры</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300"><?php echo HTML::chars($design['title']); ?></span>
	</nav>

	<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex flex-wrap items-center gap-3">
				<?php echo HTML::chars($design['title']); ?>
				<span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars($t['family']); ?></span>
			</h1>
			<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">d_model <?php echo $num($t['d']); ?> · контекст <?php echo $num($t['seq']); ?> токенов · глубина <?php echo (int) $t['depth']; ?> слоёв · хуков <?php echo (int) $t['hooks']; ?></p>
		</div>
		<div class="flex flex-wrap items-center gap-2">
			<a href="/arch/export/pytorch/<?php echo HTML::chars($design['code']); ?>" class="n2j-btn-secondary"><i class="fab fa-python mr-2" aria-hidden="true"></i>PyTorch-скелет</a>
			<a href="/arch/export/json/<?php echo HTML::chars($design['code']); ?>" class="n2j-btn-secondary"><i class="fas fa-file-code mr-2" aria-hidden="true"></i>JSON</a>
			<?php if ($mine): ?>
				<a href="/arch/edit/<?php echo HTML::chars($design['code']); ?>" class="n2j-btn-process">Править</a>
			<?php endif; ?>
		</div>
	</div>

	<?php if ($flash_saved): ?>
		<p class="n2j-flash n2j-flash-ok mt-5 block">Проект сохранён.</p>
	<?php endif; ?>
	<?php if ($flash_reviewed): ?>
		<p class="n2j-flash n2j-flash-ok mt-5 block">Экспертиза готова — смотрите блок «Разбор от LLM» ниже.</p>
	<?php endif; ?>
	<?php if ($error !== ''): ?>
		<p class="n2j-flash n2j-flash-err mt-5 block"><?php echo HTML::chars($error); ?></p>
	<?php endif; ?>

	<!-- Метрики -->
	<div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
		<div class="n2j-card">
			<p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Параметров всего</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $fmt($t['params_total']); ?></p>
			<p class="n2j-hint mt-0.5"><?php echo $num($t['params_total']); ?> · активных <?php echo $fmt($t['params_active']); ?></p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Память (инференс)</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $bytes($t['vram_infer']); ?></p>
			<p class="n2j-hint mt-0.5">веса <?php echo $bytes($t['vram_weights']); ?> · KV-cache <?php echo $bytes($t['kv_cache_seq']); ?>/контекст</p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Обучение (AdamW)</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $bytes($t['vram_train']); ?></p>
			<p class="n2j-hint mt-0.5">16 байт/параметр (bf16 + оптимизатор)</p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">FLOPs на токен</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $num($t['flops_fwd_token']); ?></p>
			<p class="n2j-hint mt-0.5">forward · внимание: <?php echo $t['n_attn']; ?> слоёв, MoE: <?php echo $t['n_moe']; ?>, SSM: <?php echo $t['n_ssm']; ?></p>
		</div>
	</div>

	<div class="mt-3 grid gap-3 sm:grid-cols-2">
		<div class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-950/20">
			<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Бюджет обучения (Chinchilla: <?php echo $num($t['tokens_opt']); ?> токенов)</p>
			<p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">≈ <?php echo $num($t['gpu_hours']); ?> GPU-часов на <?php echo HTML::chars($t['gpu_name']); ?></p>
			<p class="n2j-hint mt-0.5">≈ $<?php echo $num($t['gpu_cost']); ?> аренды при MFU 40% — оценка порядка величины, не смета.</p>
		</div>
		<?php if ($t['params_lora'] > 0): ?>
			<div class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-950/20">
				<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Файнтюн LoRA (из хуков)</p>
				<p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">обучается <?php echo $fmt($t['params_lora']); ?> — <?php echo round($t['lora_train_pct'], 2); ?>% модели</p>
				<p class="n2j-hint mt-0.5">остальное заморожено: такой файнтюн помещается в память, где полный не влезает.</p>
			</div>
		<?php else: ?>
			<div class="n2j-card">
				<p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Ближайшие аналоги</p>
				<p class="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-100">
					<?php foreach (array_slice($eval['compare'], 0, 3) as $c): ?>
						<span class="mr-3 inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-blue-400" aria-hidden="true"></span><?php echo HTML::chars($c['title']); ?> (<?php echo $ratio($c['ratio_total']); ?>)</span>
					<?php endforeach; ?>
				</p>
			</div>
		<?php endif; ?>
	</div>

	<div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
		<main class="min-w-0 space-y-5">
			<!-- Предупреждения -->
			<?php if ($eval['warnings']): ?>
			<section class="n2j-card" aria-labelledby="arch-warnings">
				<h2 id="arch-warnings" class="n2j-h3">Разбор конструктора</h2>
				<div class="mt-3 space-y-2">
					<?php foreach ($eval['warnings'] as $w): ?>
						<?php $kind = isset($warn_style[$w['kind']]) ? $w['kind'] : 'info'; ?>
						<p class="flex items-start gap-2.5 rounded-lg border px-3 py-2 text-sm <?php echo $warn_style[$kind]; ?>"><i class="fas <?php echo $warn_icon[$kind]; ?> mt-0.5 shrink-0" aria-hidden="true"></i><span><?php echo HTML::chars($w['text']); ?></span></p>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>

			<!-- Стек с формулами -->
			<section class="n2j-card" aria-labelledby="arch-stack">
				<h2 id="arch-stack" class="n2j-h3">Стек и формулы параметров</h2>
				<div class="mt-3 overflow-x-auto">
					<table class="n2j-table">
						<thead>
							<tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
								<th class="py-2 pr-3 font-semibold">Блок</th>
								<th class="py-2 pr-3 font-semibold">Формула</th>
								<th class="py-2 text-right font-semibold">Параметры</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100 dark:divide-gray-700">
							<?php foreach ($eval['blocks'] as $b): ?>
								<tr>
									<td class="py-2 pr-3"><span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($b['label']); ?></span><span class="ml-2 text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($b['group']); ?></span></td>
									<td class="py-2 pr-3 font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($b['formula']); ?></td>
									<td class="py-2 text-right tabular-nums font-semibold text-gray-800 dark:text-gray-200"><?php echo $fmt($b['params']); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>

			<!-- Хуки -->
			<?php if ($eval['hooks']): ?>
			<section class="n2j-card" aria-labelledby="arch-hooks">
				<h2 id="arch-hooks" class="n2j-h3">Хуки в середину модели</h2>
				<div class="mt-3 space-y-2">
					<?php foreach ($eval['hooks'] as $h): ?>
						<div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
							<p class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-arrow-right-to-bracket mr-2 text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
								<?php if ($h['kind'] === 'steer'): ?>steering-вектор<?php elseif ($h['kind'] === 'lora'): ?>LoRA-адаптер<?php elseif ($h['kind'] === 'tap'): ?>отвод активаций<?php else: ?>ранний выход<?php endif; ?>
								— слой <?php echo (int) $h['at']; ?> из <?php echo (int) $t['depth']; ?></p>
							<?php if ($h['kind'] === 'steer'): ?>
								<p class="n2j-hint">сила <?php echo HTML::chars((string) $h['s']); ?> · смысл: «<?php echo HTML::chars($h['text']); ?>» · глубина слоя: <?php
									$third = max(1, (int) $t['depth']) / 3;
									if ($h['at'] <= $third) { echo 'стиль и формат'; } elseif ($h['at'] <= 2 * $third) { echo 'тональность и манера'; } else { echo 'содержание и факты'; } ?></p>
							<?php elseif ($h['kind'] === 'lora'): ?>
								<p class="n2j-hint">ранг <?php echo (int) $h['r']; ?> · целей q/k/v/o · +<?php echo $fmt(8 * (int) $h['r'] * (int) $t['d']); ?> обучаемых параметров</p>
							<?php else: ?>
								<p class="n2j-hint"><?php echo HTML::chars($h['note'] !== '' ? $h['note'] : 'инструмент отладки и управления поведением'); ?></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="n2j-hint mt-3">В сгенерированном PyTorch-скелете steering-хуки становятся настоящими <code class="font-mono">register_forward_hook</code> — тем самым механизмом «вмешательства в середину», которым пользуются исследователи.</p>
			</section>
			<?php endif; ?>

			<!-- Экспертиза -->
			<section class="n2j-card" aria-labelledby="arch-review">
				<div class="flex flex-wrap items-center justify-between gap-3">
					<h2 id="arch-review" class="n2j-h3">Экспертиза реальной LLM</h2>
					<form method="post" action="/arch/review" class="inline">
						<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<input type="hidden" name="code" value="<?php echo HTML::chars($design['code']); ?>">
						<button type="submit" class="n2j-btn-process" <?php if ( ! $logged) echo 'disabled title="Требуется вход"'; ?>>
							<i class="fas fa-microscope mr-2" aria-hidden="true"></i><?php echo $logged ? 'Запросить разбор' : 'Требуется вход'; ?>
						</button>
					</form>
				</div>
				<p class="n2j-hint">Движок <?php echo HTML::chars($engine !== '' ? $engine : 'LLM платформы'); ?> посмотрит на стек, числа и предупреждения — и вынесет вердикт с конкретикой.</p>

				<?php if ( ! $reviews): ?>
					<p class="mt-4 rounded-xl border border-dashed border-gray-300 p-4 text-center text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">Разборов ещё не было.</p>
				<?php else: ?>
					<div class="mt-4 space-y-4">
						<?php foreach ($reviews as $run): ?>
							<?php if ( ! empty($run['parsed'])): $r = $run['parsed']; ?>
								<div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
									<div class="flex items-center justify-between gap-3">
										<p class="text-sm font-bold text-gray-900 dark:text-white">Оценка: <?php echo HTML::chars((string) round((float) Arr::get($r, 'score', 0), 1)); ?>/10</p>
										<p class="n2j-hint"><?php echo HTML::chars((string) $run['model']); ?> · <?php echo (int) $run['ms']; ?> мс</p>
									</div>
									<?php if (Arr::get($r, 'verdict')): ?><p class="mt-2 text-sm leading-relaxed text-gray-700 dark:text-gray-300"><?php echo HTML::chars($r['verdict']); ?></p><?php endif; ?>
									<?php if (Arr::get($r, 'strengths')): ?>
										<p class="mt-3 text-xs font-bold uppercase tracking-wide text-emerald-600 dark:text-emerald-300">Сильные решения</p>
										<ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-gray-600 dark:text-gray-300"><?php foreach ($r['strengths'] as $s): ?><li><?php echo HTML::chars($s); ?></li><?php endforeach; ?></ul>
									<?php endif; ?>
									<?php if (Arr::get($r, 'risks')): ?>
										<p class="mt-3 text-xs font-bold uppercase tracking-wide text-amber-600 dark:text-amber-300">Риски и лечение</p>
										<ul class="mt-1 space-y-1 text-sm text-gray-600 dark:text-gray-300"><?php foreach ($r['risks'] as $rk): ?><li><span class="font-semibold"><?php echo HTML::chars(Arr::get($rk, 'what', '')); ?></span><?php if (Arr::get($rk, 'fix')): ?> → <?php echo HTML::chars($rk['fix']); ?><?php endif; ?></li><?php endforeach; ?></ul>
									<?php endif; ?>
									<?php if (Arr::get($r, 'advice')): ?>
										<p class="mt-3 text-xs font-bold uppercase tracking-wide text-blue-600 dark:text-blue-300">Советы</p>
										<ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-gray-600 dark:text-gray-300"><?php foreach ($r['advice'] as $s): ?><li><?php echo HTML::chars($s); ?></li><?php endforeach; ?></ul>
									<?php endif; ?>
									<?php if (Arr::get($r, 'reading')): ?>
										<p class="mt-3 text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Что почитать</p>
										<ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-gray-600 dark:text-gray-300"><?php foreach ($r['reading'] as $s): ?><li><?php echo HTML::chars($s); ?></li><?php endforeach; ?></ul>
									<?php endif; ?>
								</div>
							<?php else: ?>
								<div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
									<p class="whitespace-pre-line text-sm leading-relaxed text-gray-700 dark:text-gray-300"><?php echo HTML::chars($run['raw'] !== '' ? $run['raw'] : 'Движок не ответил: '.$run['error']); ?></p>
									<p class="n2j-hint mt-2"><?php echo HTML::chars((string) $run['model']); ?> · <?php echo (int) $run['ms']; ?> мс</p>
								</div>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</main>

		<!-- Симулятор -->
		<aside class="space-y-4">
			<section class="n2j-card" aria-labelledby="arch-sim">
				<h2 id="arch-sim" class="n2j-h3">Симулятор поведения</h2>
				<p class="n2j-hint">Проверьте, как модель могла бы отвечать, до всякого обучения: движок играет роль вашей конфигурации — хуки задают характер влияния, контекст — «память» диалога, MoE — узость эксперта. Это имитация поведения (исполнения весов нет), движок указан под ответом.</p>
				<div id="nj660-chat" class="mt-4 max-h-[28rem] space-y-3 overflow-y-auto rounded-xl border border-gray-100 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-900/40">
					<?php foreach ($chats as $run): ?>
						<?php if ($run['prompt'] !== ''): ?>
							<p class="ml-auto max-w-[85%] rounded-xl rounded-br-sm bg-blue-600 px-3 py-2 text-sm text-white shadow-xs"><?php echo HTML::chars($run['prompt']); ?></p>
						<?php endif; ?>
						<?php if ($run['output'] !== ''): ?>
							<p class="max-w-[85%] whitespace-pre-line rounded-xl rounded-bl-sm bg-white px-3 py-2 text-sm text-gray-800 shadow-xs dark:bg-gray-800 dark:text-gray-100"><?php echo HTML::chars($run['output']); ?></p>
						<?php else: ?>
							<p class="n2j-flash n2j-flash-err max-w-[85%] text-xs block">движок не ответил: <?php echo HTML::chars($run['error']); ?></p>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
				<?php if ($logged): ?>
					<form id="nj660-chat-form" class="mt-3 flex items-end gap-2">
						<textarea id="nj660-chat-input" rows="2" maxlength="2000" placeholder="Спросите что-нибудь у «<?php echo HTML::chars($design['title']); ?>»…" class="n2j-textarea min-h-[44px] w-full"></textarea>
						<button type="submit" class="n2j-btn-process shrink-0"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
					</form>
					<p id="nj660-chat-hint" class="n2j-hint mt-2">Осталось сообщений в этот час: <?php echo (int) $llm_left; ?></p>
				<?php else: ?>
					<a href="/auth/login?return_url=arch/view/<?php echo HTML::chars($design['code']); ?>" class="n2j-btn-process mt-3 w-full">Войти, чтобы оживить модель</a>
				<?php endif; ?>
			</section>

			<?php if ($mine): ?>
			<section class="n2j-card">
				<h2 class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Управление</h2>
				<form method="post" action="/arch/delete" class="mt-3" data-nj660-delete>
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<input type="hidden" name="code" value="<?php echo HTML::chars($design['code']); ?>">
					<button type="submit" class="n2j-btn-danger n2j-btn-sm"><i class="fas fa-trash mr-2" aria-hidden="true"></i>Удалить проект</button>
				</form>
			</section>
			<?php endif; ?>
		</aside>
	</div>
</div>
