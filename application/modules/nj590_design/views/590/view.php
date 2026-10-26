<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /design/<code> — прогон: статус, варианты дизайна, выбор.
 */

$status_ru = [
    'new'     => 'в очереди', 'running' => 'работает',
    'done'    => 'готово',    'error'   => 'ошибка',
];
$pending = in_array($run['status'], ['new', 'running'], TRUE);
$chosen  = (int) $run['chosen'];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-n590-code="<?php echo HTML::chars($run['code']); ?>" data-n590-status="<?php echo HTML::chars($run['status']); ?>">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-3 text-xs block">
        <?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <?php /* Живой статус-ряд — ОБЩИЙ механизм (эталон /trends/<code>): спиннер,
     * секундомер от фактического времени, этап воркера, «нет пульса», остановка. */ ?>
    <?php if ($pending): ?>
    <?php echo View::factory('partials/live_status', [
        'url'     => '/design/api/status/'.urlencode((string) $run['code']),
        'title'   => 'Собираем варианты дизайна',
        'status'  => (string) $run['status'] === 'new' ? 'queued' : 'running',
        'stage'   => (string) $run['progress'],
        'elapsed' => 0,
        'silent'  => NULL,
        'steps'   => [],
        'note'    => 'Готовые варианты появятся здесь — страница обновится сама',
        'stop'    => '<form method="post" action="/design/'.HTML::chars($run['code']).'/cancel" class="inline-flex">'
            .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
            .'<button type="submit" title="Остановить прогон" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950/40"><i class="fas fa-stop"></i>Остановить</button>'
            .'</form>',
    ]); ?>
    <?php endif; ?>

    <div class="gap-4 space-y-4 md:grid md:grid-cols-2 md:items-start md:space-y-0">

        <!-- ============ Левая половина: варианты ============ -->
        <div class="space-y-4">

            <?php if ($run['status'] === 'error'): ?>
            <div class="rounded-xl border border-rose-300 bg-rose-50 p-4 dark:border-rose-800 dark:bg-rose-950/40">
                <p class="text-sm font-bold text-rose-700 dark:text-rose-300"><i class="fas fa-circle-exclamation mr-1.5"></i>Сборка не удалась</p>
                <p class="mt-1 break-words text-xs text-rose-600 dark:text-rose-300"><?php echo HTML::chars((string) $run['error']); ?></p>
                <a href="/design" class="n2j-btn-cta n2j-btn-sm mt-2">Создать прогон заново</a>
            </div>
            <?php endif; ?>

            <?php if ($pending): ?>
            <div class="n2j-card border-blue-200" data-n590-waitcard>
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-hourglass-half mr-1.5 text-blue-500"></i>Идёт сборка вариантов</p>
                <p class="n2j-hint break-words">Страница: <span class="font-mono"><?php echo HTML::chars($run['host']); ?></span>. Можно закрыть вкладку и вернуться позже — ссылка на прогон сохранится в «Моих прогонах».</p>
            </div>
            <?php endif; ?>

            <?php if ( ! empty($variants)): ?>
            <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-layer-group mr-1.5 text-blue-500"></i>Варианты дизайна <?php echo $chosen > 0 ? '<span class="ml-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-bold uppercase text-emerald-600">выбран №'.$chosen.'</span>' : ''; ?></p>

            <?php foreach ($variants as $v): ?>
            <?php $is_chosen = ($chosen === (int) $v['no']); ?>
            <div class="overflow-hidden rounded-xl border bg-white shadow-xs dark:bg-gray-800 <?php echo $is_chosen ? 'border-2 border-emerald-400' : 'border-gray-200 dark:border-gray-700'; ?>">
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                    <span class="n2j-badge n2j-badge-wait font-mono">№<?php echo (int) $v['no']; ?></span>
                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($v['title'] !== '' ? $v['title'] : 'Вариант №'.$v['no']); ?></span>
                    <?php if ($is_chosen): ?>
                    <span class="n2j-badge n2j-badge-ok uppercase"><i class="fas fa-check mr-0.5"></i>выбран</span>
                    <?php endif; ?>
                    <a href="/design/<?php echo HTML::chars($run['code']); ?>/file/<?php echo (int) $v['id']; ?>" target="_blank" rel="noopener"
                       class="n2j-btn-secondary n2j-btn-sm">во всю ширину<i class="fas fa-arrow-up-right-from-square ml-1"></i></a>
                </div>

                <div class="px-4 pt-2">
                    <?php if ($v['concept'] !== ''): ?>
                    <p class="text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars(mb_substr((string) $v['concept'], 0, 600)); ?></p>
                    <?php endif; ?>
                    <iframe src="/design/<?php echo HTML::chars($run['code']); ?>/file/<?php echo (int) $v['id']; ?>"
                            sandbox title="Вариант №<?php echo (int) $v['no']; ?>"
                            class="mt-2 h-[420px] w-full rounded-xl border border-gray-200 bg-white dark:border-gray-700"></iframe>
                </div>

                <?php if ($is_owner AND (string) $v['path'] !== ''): ?>
                <div class="px-4 pb-3 pt-2">
                    <form method="post" action="/design/<?php echo HTML::chars($run['code']); ?>/pick" class="flex items-center gap-2">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="no" value="<?php echo (int) $v['no']; ?>">
                        <button type="submit" class="<?php echo $is_chosen ? 'n2j-btn-cta' : 'n2j-btn-process'; ?> n2j-btn-sm">
                            <?php echo $is_chosen ? '<i class="fas fa-check mr-1"></i>Выбран — подтвердить' : '<i class="fas fa-hand-pointer mr-1"></i>Выбрать этот'; ?>
                        </button>
                        <span class="text-xs text-gray-400"><?php echo $is_chosen ? 'выбор сохранён' : 'выбор можно поменять в любой момент'; ?></span>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php elseif ( ! $pending AND $run['status'] !== 'error'): ?>
            <div class="n2j-card text-xs text-gray-400">Варианты не сохранились — создайте прогон заново.</div>
            <?php endif; ?>
        </div>

        <!-- ============ Правая половина: сведения о прогоне ============ -->
        <div class="space-y-4">
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Сведения о прогоне</p>
                <dl class="mt-2 space-y-1.5 text-xs">
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-gray-400">Код</dt><dd class="min-w-0 break-all font-mono text-gray-700 dark:text-gray-200"><?php echo HTML::chars($run['code']); ?></dd></div>
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-gray-400">Страница</dt><dd class="min-w-0 break-all text-gray-700 dark:text-gray-200"><a href="<?php echo HTML::chars($run['url']); ?>" target="_blank" rel="noopener nofollow" class="text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($run['url']); ?></a></dd></div>
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-gray-400">Статус</dt><dd class="text-gray-700 dark:text-gray-200"><?php echo $status_ru[$run['status']] ?? HTML::chars($run['status']); ?><?php echo ($run['progress'] !== '' AND $pending) ? ' — '.HTML::chars($run['progress']) : ''; ?></dd></div>
                    <?php if ($run['title'] !== ''): ?>
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-gray-400">Страница это</dt><dd class="min-w-0 text-gray-700 dark:text-gray-200"><?php echo HTML::chars($run['title']); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($run['wishes']): ?>
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-gray-400">Пожелания</dt><dd class="min-w-0 whitespace-pre-line text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $run['wishes']); ?></dd></div>
                    <?php endif; ?>
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-gray-400">Модель</dt><dd class="min-w-0 break-all font-mono text-gray-700 dark:text-gray-200"><?php $m_req = (string) ($run['model'] ?? ''); $m_use = (string) ($run['model_used'] ?? ''); echo HTML::chars($m_use !== '' ? (($m_req !== '' AND $m_use !== $m_req) ? $m_use.' (запрашивали '.$m_req.')' : $m_use) : ($m_req !== '' ? $m_req : 'из настроек воркера')); ?></dd></div>
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-gray-400">Создан</dt><dd class="text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $run['created_at']); ?></dd></div>
                </dl>
            </div>

            <?php if ($run['summary']): ?>
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-magnifying-glass-chart mr-1.5 text-blue-500"></i>Что ИИ увидел на странице</p>
                <p class="mt-1 whitespace-pre-line text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $run['summary']); ?></p>
            </div>
            <?php endif; ?>

            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-shuffle mr-1.5 text-gray-400"></i>Не то?</p>
                <p class="n2j-hint">Создайте новый прогон с уточнёнными пожеланиями — ИИ учтёт их при генерации вариантов.</p>
                <a href="/design" class="n2j-btn-secondary n2j-btn-sm mt-2"><i class="fas fa-plus mr-1"></i>Новый прогон</a>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>

<?php echo View::factory('590/script'); ?>
