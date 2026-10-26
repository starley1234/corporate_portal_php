<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /coldmail/<code> — варианты первого абзаца + источники персонализации.
 */

$status_ru = ['new' => 'в очереди', 'running' => 'работает', 'done' => 'готово', 'error' => 'ошибка'];
$pending = in_array($l['status'], ['new', 'running'], TRUE);
$variants = isset($result['variants']) && is_array($result['variants']) ? $result['variants'] : [];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-n592-code="<?php echo HTML::chars($l['code']); ?>" data-n592-status="<?php echo HTML::chars($l['status']); ?>">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-3 block">
        <?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <?php if ($l['status'] === 'error'): ?>
    <div class="mb-4 rounded-xl border border-rose-300 bg-rose-50 p-4 dark:border-rose-800 dark:bg-rose-950/40">
        <p class="text-sm font-bold text-rose-700 dark:text-rose-300"><i class="fas fa-circle-exclamation mr-1.5"></i>Генерация не удалась</p>
        <p class="mt-1 break-words text-sm text-rose-600 dark:text-rose-300"><?php echo HTML::chars((string) $l['error']); ?></p>
        <a href="/coldmail" class="n2j-btn-process mt-2">Попробовать заново</a>
    </div>
    <?php endif; ?>

    <?php /* Живой статус-ряд — ОБЩИЙ механизм (эталон /trends/<code>): спиннер,
     * секундомер от фактического времени, этап воркера, «нет пульса», остановка. */ ?>
    <?php if ($pending): ?>
    <?php echo View::factory('partials/live_status', [
        'url'     => '/coldmail/api/status/'.urlencode((string) $l['code']),
        'title'   => 'Собираем письмо',
        'status'  => (string) $l['status'] === 'new' ? 'queued' : 'running',
        'stage'   => (string) $l['progress'],
        'elapsed' => 0,
        'silent'  => NULL,
        'steps'   => [],
        'note'    => 'Варианты письма появятся здесь — страница обновится сама',
        'stop'    => '<form method="post" action="/coldmail/'.HTML::chars($l['code']).'/stop" class="inline-flex">'
            .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
            .'<button type="submit" title="Остановить генерацию" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950/40"><i class="fas fa-stop"></i>Остановить</button>'
            .'</form>',
    ]); ?>
    <?php endif; ?>

    <div class="gap-5 space-y-4 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Левая часть (2/3): крупные карточки писем ============ -->
        <div class="space-y-4 md:col-span-2">

            <?php if ( ! empty($variants)): ?>
            <?php foreach ($variants as $i => $v): ?>
            <div class="n2j-card transition hover:border-blue-200 dark:hover:border-blue-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-xs font-bold uppercase text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-envelope-open-text"></i>Вариант <?php echo $i + 1; ?></p>
                    <button type="button" data-n592-copy="<?php echo HTML::chars((string) ($v['paragraph'] ?? '')); ?>"
                            class="n2j-btn-secondary">
                        <i class="fas fa-copy mr-1"></i>Копировать абзац
                    </button>
                </div>
                <?php if ( ! empty($v['subject'])): ?>
                <p class="mt-4 text-base font-bold text-gray-900 dark:text-white"><i class="fas fa-tag mr-1.5 text-gray-400"></i>Тема: <?php echo HTML::chars((string) $v['subject']); ?></p>
                <?php endif; ?>
                <p class="mt-2 whitespace-pre-line text-base leading-relaxed text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) ($v['paragraph'] ?? '')); ?></p>
                <?php if ( ! empty($v['why'])): ?>
                <p class="mt-3 rounded-xl bg-blue-50/60 px-3 py-2 text-sm text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-lightbulb mr-1"></i><?php echo HTML::chars((string) $v['why']); ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php elseif ( ! $pending AND $l['status'] !== 'error'): ?>
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-400 dark:border-gray-600 dark:bg-gray-800">Варианты не сохранились — создайте прогон заново.</div>
            <?php elseif ($pending): ?>
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-400 dark:border-gray-600 dark:bg-gray-800">Варианты появятся здесь после генерации.</div>
            <?php endif; ?>
        </div>

        <!-- ============ Правая часть (1/3): сведения + источники ============ -->
        <div class="space-y-4">
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Сборка</p>
                <?php // Онтология: та же компания в других сервисах (профиль, досье, отчёты) ?>
                <div class="mt-2"><?php echo View::factory('partials/ontology_link', [
                    'title' => (string) $l['company_query'],
                    'kind'  => 'company',
                ]); ?></div>
                <dl class="mt-2 space-y-2 text-sm">
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Код</dt><dd class="font-mono text-gray-700 dark:text-gray-200"><?php echo HTML::chars($l['code']); ?></dd></div>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Компания</dt><dd class="min-w-0 break-all text-gray-700 dark:text-gray-200"><?php echo HTML::chars($l['company_query']); ?><?php echo $l['profile_code'] ? ' <span class="text-gray-400">· профиль '.$l['profile_code'].'</span>' : ''; ?></dd></div>
                    <?php if ($l['domain'] !== ''): ?>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Сайт</dt><dd class="min-w-0 break-all text-gray-700 dark:text-gray-200"><a href="https://<?php echo HTML::chars($l['domain']); ?>" target="_blank" rel="noopener nofollow" class="text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($l['domain']); ?></a></dd></div>
                    <?php endif; ?>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Предложение</dt><dd class="min-w-0 whitespace-pre-line text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $l['product']); ?></dd></div>
                    <?php if ($l['audience'] !== ''): ?>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Кому</dt><dd class="text-gray-700 dark:text-gray-200"><?php echo HTML::chars($l['audience']); ?></dd></div>
                    <?php endif; ?>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Статус</dt><dd class="text-gray-700 dark:text-gray-200"><?php echo $status_ru[$l['status']] ?? HTML::chars($l['status']); ?><?php echo ($l['progress'] !== '' AND $pending) ? ' — '.HTML::chars($l['progress']) : ''; ?></dd></div>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Создан</dt><dd class="text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $l['created_at']); ?></dd></div>
                </dl>
            </div>

            <?php if ( ! empty($sources)): ?>
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-sitemap mr-1.5 text-gray-400"></i>Источники персонализации</p>
                <ul class="mt-2 space-y-1.5 text-sm">
                    <?php foreach ($sources as $s): ?>
                    <li class="truncate">
                        <span class="n2j-badge n2j-badge-wait uppercase"><?php echo HTML::chars((string) ($s['type'] ?? '')); ?></span>
                        <?php if ( ! empty($s['url'])): $s_url = preg_match('~^https?://~i', (string) $s['url']) ? $s['url'] : 'https://'.$s['url']; ?><a href="<?php echo HTML::chars($s_url); ?>" target="_blank" rel="noopener nofollow" class="text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) ($s['title'] ?? $s['url'])); ?></a>
                        <?php else: ?><span class="text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) ($s['title'] ?? '')); ?></span><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ((string) $l['status'] === 'done' AND ! empty($variants)): ?>
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-shuffle mr-1.5 text-gray-400"></i>Не попали в тон?</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Создайте новый прогон с другим тоном или уточните предложение.</p>
                <a href="/coldmail" class="n2j-btn-secondary mt-2"><i class="fas fa-plus mr-1"></i>Новый прогон</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>

<?php echo View::factory('592/script'); ?>
