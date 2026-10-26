<?php defined('SYSPATH') or die('No direct script access.');
/**
 * nj609/admin — «Сторож контрагентов» · управление: все контрагенты, свежие
 * события, здоровье воркера (канал nj609.watchdog). Удаления — AJAX.
 */
$dt = function ($v) { return $v ? HTML::chars(Date::formatted_time((string) $v, 'd.m.y H:i')) : '—'; };

$running = FALSE; $last_ok = ''; $last_err = '';
foreach ($jobs as $j)
{
    if (in_array($j['status'], ['pending', 'processing'], TRUE) AND ! $running) { $running = TRUE; }
    if ($j['status'] === 'done' AND $last_ok === '')    { $last_ok = $dt($j['finished_at']); }
    if ($j['status'] === 'failed' AND $last_err === '') { $last_err = $dt($j['finished_at']); }
}
$sev = function ($s) {
    $map = [
        'risk' => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
        'warn' => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
        'info' => 'bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300',
    ];

    return '<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold '.($map[$s] ?? 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400').'">'.HTML::chars((string) $s).'</span>';
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/watchdog" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сторож контрагентов</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Управление</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-shield-halved mr-2 text-blue-500"></i>Сторож контрагентов · управление
        </h1>
        <a href="#" onclick="return false;" class="n2j-btn-secondary n2j-btn-sm">
            <i class="fas fa-lock text-[11px]"></i>Управление
        </a>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">Все контрагенты под наблюдением и свежие события риска. Воркер проверяет каждого раз в неделю — здесь видно, жив ли он.</p>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <!-- KPI -->
    <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Под наблюдением</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400"><i class="fas fa-magnifying-glass-chart text-sm"></i></span>
            </div>
            <div class="mt-2.5 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($counts['subjects'] ?? 0); ?></div>
            <p class="n2j-hint">компаний у всех пользователей</p>
        </div>
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Событий</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400"><i class="fas fa-bell text-sm"></i></span>
            </div>
            <div class="mt-2.5 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($counts['events'] ?? 0); ?></div>
            <p class="n2j-hint">непрочитанных: <?php echo (int) ($counts['new'] ?? 0); ?></p>
        </div>
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Рисков</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-900/40 dark:text-rose-400"><i class="fas fa-triangle-exclamation text-sm"></i></span>
            </div>
            <div class="mt-2.5 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($counts['risk'] ?? 0); ?></div>
            <p class="n2j-hint">событий severity=risk</p>
        </div>
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Воркер nj609.watchdog</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg <?php echo $running ? 'bg-amber-50 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400'; ?>"><i class="fas fa-robot text-sm"></i></span>
            </div>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs">
                <?php if ($running): ?><i class="fas fa-spinner n2j-spin text-[11px] text-amber-600 dark:text-amber-400"></i><span class="font-medium text-gray-700 dark:text-gray-300">проверка выполняется</span>
                <?php else: ?><span class="font-medium text-gray-700 dark:text-gray-300">сейчас тихо</span><?php endif; ?>
            </div>
            <p class="n2j-hint">последний удачный: <?php echo $last_ok ?: '—'; ?><?php echo $last_err !== '' ? ' · ошибка: '.$last_err : ''; ?></p>
        </div>
    </div>

    <!-- Таблица контрагентов -->
    <div class="n2j-card p-0 mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Контрагенты</h2>
                <span class="n2j-badge n2j-badge-wait"><?php echo count($subjects); ?></span>
            </div>
        </div>
        <div class="overflow-x-auto n2j-scrollbar">
            <table class="n2j-table whitespace-nowrap">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                        <th class="py-2.5 pr-3 pl-4 sm:pl-5">Компания</th>
                        <th class="py-2.5 px-3">Владелец</th>
                        <th class="py-2.5 px-3">Событий</th>
                        <th class="py-2.5 px-3">Последняя проверка</th>
                        <th class="py-2.5 pr-4 pl-3 text-right sm:pr-5">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                    <?php foreach ($subjects as $s): ?>
                    <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25" data-nj609admin-row="<?php echo HTML::chars($s['code']); ?>">
                        <td class="max-w-[360px] py-2.5 pr-3 pl-4 sm:pl-5">
                            <span class="truncate font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($s['company']); ?></span>
                            <p class="n2j-hint mt-0.5 truncate"><?php echo $s['inn'] !== '' ? 'ИНН '.HTML::chars($s['inn']).' · ' : ''; ?><span class="font-mono"><?php echo HTML::chars($s['code']); ?></span></p>
                        </td>
                        <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) ($s['owner_name'] ?? '')); ?></td>
                        <td class="py-2.5 px-3 text-xs font-semibold text-gray-700 dark:text-gray-300"><?php echo (int) $s['total']; ?></td>
                        <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400"><?php echo $dt($s['last_run_at']); ?></td>
                        <td class="py-2.5 pr-4 pl-3 text-right sm:pr-5">
                            <form method="post" action="/watchdog/admin" class="inline" data-nj609admin-ajax>
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="code" value="<?php echo HTML::chars($s['code']); ?>">
                                <button data-n2j-arm="Снять?" type="submit" title="Снять с наблюдения" class="n2j-btn-icon-danger"><i class="fas fa-trash-can text-xs"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($subjects)): ?><tr><td colspan="5" class="py-4 pl-4 text-xs text-gray-400 sm:pl-5">Контрагентов пока нет.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Свежие события -->
    <div class="n2j-card p-0 mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Свежие события</h2>
                <span class="n2j-badge n2j-badge-wait"><?php echo count($events); ?></span>
            </div>
        </div>
        <div class="overflow-x-auto n2j-scrollbar">
            <table class="n2j-table whitespace-nowrap">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                        <th class="py-2.5 pr-3 pl-4 sm:pl-5">Событие</th>
                        <th class="py-2.5 px-3">Контрагент</th>
                        <th class="py-2.5 px-3">Тяжесть</th>
                        <th class="py-2.5 px-3">Статус</th>
                        <th class="py-2.5 px-3">Когда</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                    <?php foreach ($events as $e): ?>
                    <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                        <td class="max-w-[380px] py-2.5 pr-3 pl-4 sm:pl-5">
                            <span class="truncate font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($e['title']); ?></span>
                            <?php if ($e['url'] !== ''): ?><p class="n2j-hint mt-0.5 truncate font-mono"><?php echo HTML::chars(mb_substr((string) $e['url'], 0, 60)); ?></p><?php endif; ?>
                        </td>
                        <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $e['subject_company']); ?></td>
                        <td class="py-2.5 px-3"><?php echo $sev($e['severity']); ?></td>
                        <td class="py-2.5 px-3"><span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars($e['status']); ?></span></td>
                        <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400"><?php echo $dt($e['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($events)): ?><tr><td colspan="5" class="py-4 pl-4 text-xs text-gray-400 sm:pl-5">Событий пока нет — воркер ещё не приносил сигналы.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
/* nj609/admin: AJAX-удаление контрагента (two-click) */
(function () {
    function toast(text, ok) {
        // Общий тост app.js; результат действия держим дольше обычного «ok».
        N2J.toast(text, ok ? 3500 : 0, ok ? 'ok' : 'err');
    }

    document.querySelectorAll('form[data-nj609admin-ajax]').forEach(function (form) {
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
                        var row = document.querySelector('[data-nj609admin-row="' + code + '"]');
                        if (row) row.remove();
                    }
                })
                .catch(function () { toast('Сеть не ответила — попробуйте ещё раз', false); });
        });
    });
})();
</script>
