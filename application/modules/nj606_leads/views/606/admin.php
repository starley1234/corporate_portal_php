<?php defined('SYSPATH') or die('No direct script access.');
/**
 * nj606/admin — «Поток заказчиков» · управление: все потоки, свежие лиды,
 * здоровье воркера (канал nj606.leads). Удаления — AJAX (two-click).
 */
$dt = function ($v) { return $v ? HTML::chars(Date::formatted_time((string) $v, 'd.m.y H:i')) : '—'; };

// здоровье воркера: что говорят последние задачи канала
$running = FALSE; $last_ok = ''; $last_err = '';
foreach ($jobs as $j)
{
    if (in_array($j['status'], ['pending', 'processing'], TRUE) AND ! $running) { $running = TRUE; }
    if ($j['status'] === 'done' AND $last_ok === '')    { $last_ok = $dt($j['finished_at']); }
    if ($j['status'] === 'failed' AND $last_err === '') { $last_err = $dt($j['finished_at']); }
}
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/leads" class="transition hover:text-blue-600 dark:hover:text-blue-400">Поток заказчиков</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Управление</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-users-rays mr-2 text-blue-500"></i>Поток заказчиков · управление
        </h1>
        <a href="#" onclick="return false;" class="n2j-btn-secondary n2j-btn-sm">
            <i class="fas fa-lock text-[11px]"></i>Управление
        </a>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">Все потоки всех пользователей и свежие найденные компании. Воркер делает сбор по расписанию («Пиналка»), здесь видно — жив ли он.</p>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <!-- KPI -->
    <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Потоков</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400"><i class="fas fa-filter text-sm"></i></span>
            </div>
            <div class="mt-2.5 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($counts['flows'] ?? 0); ?></div>
            <p class="n2j-hint">запускали сбор: <?php echo (int) ($counts['ran'] ?? 0); ?></p>
        </div>
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Компаний найдено</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400"><i class="fas fa-building text-sm"></i></span>
            </div>
            <div class="mt-2.5 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($counts['leads'] ?? 0); ?></div>
            <p class="n2j-hint">новых, не разобрано: <?php echo (int) ($counts['new'] ?? 0); ?></p>
        </div>
        <div class="n2j-card sm:col-span-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Воркер nj606.leads</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg <?php echo $running ? 'bg-amber-50 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400'; ?>"><i class="fas fa-robot text-sm"></i></span>
            </div>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs">
                <?php if ($running): ?><i class="fas fa-spinner n2j-spin text-[11px] text-amber-600 dark:text-amber-400"></i><span class="font-medium text-gray-700 dark:text-gray-300">сбор выполняется</span>
                <?php else: ?><span class="font-medium text-gray-700 dark:text-gray-300">сейчас тихо</span><?php endif; ?>
            </div>
            <p class="n2j-hint">последний удачный: <?php echo $last_ok ?: '—'; ?><?php echo $last_err !== '' ? ' · ошибка: '.$last_err : ''; ?></p>
        </div>
    </div>

    <!-- Таблица потоков -->
    <div class="n2j-card p-0 mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Потоки</h2>
                <span class="n2j-badge n2j-badge-wait"><?php echo count($flows); ?></span>
            </div>
        </div>
        <div class="overflow-x-auto n2j-scrollbar">
            <table class="n2j-table md:whitespace-nowrap">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                        <th class="py-2.5 pr-3 pl-4 sm:pl-5">Поток / код</th>
                        <th class="hidden whitespace-nowrap py-2.5 px-3 md:table-cell">Владелец</th>
                        <th class="py-2.5 px-3">Лидов</th>
                        <th class="hidden whitespace-nowrap py-2.5 px-3 md:table-cell">Последний сбор</th>
                        <th class="py-2.5 pr-4 pl-3 text-right sm:pr-5">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                    <?php foreach ($flows as $f): ?>
                    <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25" data-nj606admin-row="<?php echo HTML::chars($f['code']); ?>">
                        <td class="py-2.5 pr-3 pl-4 sm:pl-5 md:max-w-[360px]">
                            <span class="block break-words font-semibold text-gray-900 md:truncate dark:text-white"><?php echo HTML::chars($f['title']); ?></span>
                            <p class="mt-0.5 break-words text-sm text-gray-400 md:truncate"><?php echo HTML::chars($f['industry']); ?><?php echo $f['region'] !== '' ? ' · '.HTML::chars($f['region']) : ''; ?> · <span class="font-mono"><?php echo HTML::chars($f['code']); ?></span></p>
                            <p class="n2j-hint mt-0.5 break-words md:hidden">
                                <?php echo HTML::chars((string) ($f['owner_name'] ?? '')); ?> · сбор: <?php echo $dt($f['last_run_at']); ?>
                            </p>
                        </td>
                        <td class="hidden whitespace-nowrap py-2.5 px-3 text-sm text-gray-500 md:table-cell dark:text-gray-400"><?php echo HTML::chars((string) ($f['owner_name'] ?? '')); ?></td>
                        <td class="whitespace-nowrap py-2.5 px-3 text-sm font-semibold text-gray-700 dark:text-gray-300"><?php echo (int) $f['total']; ?></td>
                        <td class="hidden whitespace-nowrap py-2.5 px-3 text-sm text-gray-500 md:table-cell dark:text-gray-400"><?php echo $dt($f['last_run_at']); ?></td>
                        <td class="whitespace-nowrap py-2.5 pr-4 pl-3 text-right sm:pr-5">
                            <form method="post" action="/leads/admin" class="inline" data-nj606admin-ajax>
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="code" value="<?php echo HTML::chars($f['code']); ?>">
                                <button data-n2j-arm="Удалить?" type="submit" title="Удалить поток" class="n2j-btn-icon-danger"><i class="fas fa-trash-can text-sm"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($flows)): ?><tr><td colspan="5" class="py-4 pl-4 text-sm text-gray-400 sm:pl-5">Потоков пока нет.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Последние лиды -->
    <div class="n2j-card p-0 mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Свежие компании</h2>
                <span class="n2j-badge n2j-badge-wait"><?php echo count($leads); ?></span>
            </div>
        </div>
        <div class="overflow-x-auto n2j-scrollbar">
            <table class="n2j-table md:whitespace-nowrap">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                        <th class="py-2.5 pr-3 pl-4 sm:pl-5">Компания</th>
                        <th class="hidden whitespace-nowrap py-2.5 px-3 md:table-cell">Поток</th>
                        <th class="hidden whitespace-nowrap py-2.5 px-3 md:table-cell">Score</th>
                        <th class="py-2.5 px-3">Статус</th>
                        <th class="hidden whitespace-nowrap py-2.5 px-3 md:table-cell">Когда</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                    <?php foreach ($leads as $l): ?>
                    <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                        <td class="py-2.5 pr-3 pl-4 sm:pl-5 md:max-w-[360px]">
                            <span class="block break-words font-semibold text-gray-900 md:truncate dark:text-white"><?php echo HTML::chars($l['company']); ?></span>
                            <?php if ($l['site'] !== ''): ?><p class="n2j-hint mt-0.5 break-words font-mono md:truncate"><?php echo HTML::chars($l['site']); ?></p><?php endif; ?>
                            <p class="n2j-hint mt-0.5 break-words md:hidden">
                                <span class="font-bold <?php echo (int) $l['score'] >= 70 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500'; ?>">score <?php echo (int) $l['score']; ?></span>
                                · <?php echo HTML::chars((string) $l['flow_title']); ?> · <?php echo $dt($l['created_at']); ?>
                            </p>
                        </td>
                        <td class="hidden whitespace-nowrap py-2.5 px-3 text-xs text-gray-500 md:table-cell dark:text-gray-400"><?php echo HTML::chars((string) $l['flow_title']); ?></td>
                        <td class="hidden py-2.5 px-3 text-xs font-bold md:table-cell <?php echo (int) $l['score'] >= 70 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500'; ?>"><?php echo (int) $l['score']; ?></td>
                        <td class="whitespace-nowrap py-2.5 px-3"><span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars($l['status']); ?></span></td>
                        <td class="hidden whitespace-nowrap py-2.5 px-3 text-xs text-gray-500 md:table-cell dark:text-gray-400"><?php echo $dt($l['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($leads)): ?><tr><td colspan="5" class="py-4 pl-4 text-xs text-gray-400 sm:pl-5">Лидов пока нет — воркер ещё не приносил компании.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
/* nj606/admin: AJAX-удаление потока (two-click) — страница не перезагружается */
(function () {
    function toast(text, ok) {
        // Общий тост app.js; результат действия держим дольше обычного «ok».
        N2J.toast(text, ok ? 3500 : 0, ok ? 'ok' : 'err');
    }

    document.querySelectorAll('form[data-nj606admin-ajax]').forEach(function (form) {
        // Первый клик взводит кнопку (data-n2j-arm, app.js) и сюда не доходит;
        // второй — отправляет форму без перезагрузки.
        var btn = form.querySelector('button[type="submit"]');
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var fd = new FormData(form); fd.set('ajax', '1');
            fetch(form.getAttribute('action'), { method: 'POST', body: fd, cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    toast(res.flash || (res.ok ? 'Готово' : 'Не получилось'), !!res.ok);
                    if (res.ok) {
                        var code = form.querySelector('[name="code"]').value;
                        var row = document.querySelector('[data-nj606admin-row="' + code + '"]');
                        if (row) row.remove();
                    }
                })
                .catch(function () { toast('Сеть не ответила — попробуйте ещё раз', false); });
        });
    });
})();
</script>
