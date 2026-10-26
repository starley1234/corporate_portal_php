<?php defined('SYSPATH') or die('No direct script access.');
/**
 * nj605/admin — «Радар заказов» · управление: все радары, свежие находки,
 * здоровье воркера (канал nj605.radar). Удаления — AJAX (two-click).
 */
$dt = function ($v) { return $v ? HTML::chars(Date::formatted_time((string) $v, 'd.m.y H:i')) : '—'; };

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
        <a href="/radar" class="transition hover:text-blue-600 dark:hover:text-blue-400">Радар заказов</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Управление</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-satellite-dish mr-2 text-blue-500"></i>Радар заказов · управление
        </h1>
        <a href="#" onclick="return false;" class="n2j-btn-secondary n2j-btn-sm">
            <i class="fas fa-lock text-[11px]"></i>Управление
        </a>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">Все радары всех пользователей и свежие находки (закупки/заказы). Воркер обходит радары по расписанию — здесь видно, жив ли он.</p>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <!-- KPI -->
    <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Радаров</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400"><i class="fas fa-tower-broadcast text-sm"></i></span>
            </div>
            <div class="mt-2.5 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($counts['watches'] ?? 0); ?></div>
            <p class="n2j-hint">у всех пользователей</p>
        </div>
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Находок</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400"><i class="fas fa-crosshairs text-sm"></i></span>
            </div>
            <div class="mt-2.5 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($counts['items'] ?? 0); ?></div>
            <p class="n2j-hint">новых, не разобрано: <?php echo (int) ($counts['new'] ?? 0); ?></p>
        </div>
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Горячих (score ≥ 85)</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400"><i class="fas fa-fire text-sm"></i></span>
            </div>
            <div class="mt-2.5 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) ($counts['hot'] ?? 0); ?></div>
            <p class="n2j-hint">о них воркер звонит в nj597</p>
        </div>
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Воркер nj605.radar</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg <?php echo $running ? 'bg-amber-50 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400'; ?>"><i class="fas fa-robot text-sm"></i></span>
            </div>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs">
                <?php if ($running): ?><i class="fas fa-circle-notch fa-spin text-[11px] text-amber-600 dark:text-amber-400"></i><span class="font-medium text-gray-700 dark:text-gray-300">обход выполняется</span>
                <?php else: ?><span class="font-medium text-gray-700 dark:text-gray-300">сейчас тихо</span><?php endif; ?>
            </div>
            <p class="n2j-hint">последний удачный: <?php echo $last_ok ?: '—'; ?><?php echo $last_err !== '' ? ' · ошибка: '.$last_err : ''; ?></p>
        </div>
    </div>

    <!-- Таблица радаров -->
    <div class="n2j-card p-0 mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Радары</h2>
                <span class="n2j-badge n2j-badge-wait"><?php echo count($watches); ?></span>
            </div>
        </div>
        <div class="overflow-x-auto n2j-scrollbar">
            <table class="n2j-table md:whitespace-nowrap">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                        <th class="py-2.5 pr-3 pl-4 sm:pl-5">Радар / запрос</th>
                        <th class="hidden whitespace-nowrap py-2.5 px-3 md:table-cell">Владелец</th>
                        <th class="py-2.5 px-3">Состояние</th>
                        <th class="py-2.5 px-3">Находок</th>
                        <th class="hidden whitespace-nowrap py-2.5 px-3 md:table-cell">Последний обход</th>
                        <th class="py-2.5 pr-4 pl-3 text-right sm:pr-5">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                    <?php foreach ($watches as $w): ?>
                    <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25" data-nj605admin-row="<?php echo HTML::chars($w['code']); ?>">
                        <td class="py-2.5 pr-3 pl-4 sm:pl-5 md:max-w-[360px]">
                            <span class="block break-words font-semibold text-gray-900 md:truncate dark:text-white"><?php echo HTML::chars($w['title']); ?></span>
                            <p class="n2j-hint mt-0.5 break-words font-mono md:truncate"><?php echo HTML::chars($w['query']); ?></p>
                            <p class="n2j-hint mt-0.5 break-words md:hidden">
                                <?php echo HTML::chars((string) ($w['owner_name'] ?? '')); ?> · обход: <?php echo $dt($w['last_run_at']); ?>
                            </p>
                        </td>
                        <td class="hidden whitespace-nowrap py-2.5 px-3 text-xs text-gray-500 md:table-cell dark:text-gray-400"><?php echo HTML::chars((string) ($w['owner_name'] ?? '')); ?></td>
                        <td class="whitespace-nowrap py-2.5 px-3"><?php if ($w['enabled'] === 'f' OR $w['enabled'] === FALSE): ?><span class="n2j-badge n2j-badge-run">пауза</span><?php else: ?><span class="n2j-badge n2j-badge-ok">включен</span><?php endif; ?></td>
                        <td class="whitespace-nowrap py-2.5 px-3 text-xs font-semibold text-gray-700 dark:text-gray-300"><?php echo (int) $w['total']; ?></td>
                        <td class="hidden whitespace-nowrap py-2.5 px-3 text-xs text-gray-500 md:table-cell dark:text-gray-400"><?php echo $dt($w['last_run_at']); ?></td>
                        <td class="whitespace-nowrap py-2.5 pr-4 pl-3 text-right sm:pr-5">
                            <form method="post" action="/radar/admin" class="inline">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="toggle"><input type="hidden" name="code" value="<?php echo HTML::chars($w['code']); ?>"><input type="hidden" name="enabled" value="<?php echo ($w['enabled'] === 'f' OR $w['enabled'] === FALSE) ? '1' : '0'; ?>">
                                <button type="submit" title="<?php echo ($w['enabled'] === 'f' OR $w['enabled'] === FALSE) ? 'Включить радар' : 'Пауза'; ?>" class="n2j-btn-icon"><i class="fas <?php echo ($w['enabled'] === 'f' OR $w['enabled'] === FALSE) ? 'fa-play' : 'fa-pause'; ?> text-[11px]"></i></button>
                            </form>
                            <form method="post" action="/radar/admin" class="inline" data-nj605admin-ajax>
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="code" value="<?php echo HTML::chars($w['code']); ?>">
                                <button data-n2j-arm="Удалить?" type="submit" title="Удалить радар" class="n2j-btn-icon-danger"><i class="fas fa-trash-can text-xs"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($watches)): ?><tr><td colspan="6" class="py-4 pl-4 text-xs text-gray-400 sm:pl-5">Радаров пока нет.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Свежие находки -->
    <div class="n2j-card p-0 mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Свежие находки</h2>
                <span class="n2j-badge n2j-badge-wait"><?php echo count($items); ?></span>
            </div>
        </div>
        <div class="overflow-x-auto n2j-scrollbar">
            <table class="n2j-table md:whitespace-nowrap">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                        <th class="py-2.5 pr-3 pl-4 sm:pl-5">Заказ / запрос</th>
                        <th class="hidden whitespace-nowrap py-2.5 px-3 md:table-cell">Радар</th>
                        <th class="hidden whitespace-nowrap py-2.5 px-3 md:table-cell">Score</th>
                        <th class="py-2.5 px-3">Статус</th>
                        <th class="hidden whitespace-nowrap py-2.5 px-3 md:table-cell">Когда</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                    <?php foreach ($items as $i): ?>
                    <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                        <td class="py-2.5 pr-3 pl-4 sm:pl-5 md:max-w-[360px]">
                            <span class="block break-words font-semibold text-gray-900 md:truncate dark:text-white"><?php echo HTML::chars($i['title']); ?></span>
                            <?php if ($i['url'] !== ''): ?><p class="n2j-hint mt-0.5 break-words font-mono md:truncate"><?php echo HTML::chars(mb_substr((string) $i['url'], 0, 60)); ?></p><?php endif; ?>
                            <p class="n2j-hint mt-0.5 break-words md:hidden">
                                <span class="font-bold <?php echo (int) $i['score'] >= 85 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500'; ?>">score <?php echo (int) $i['score']; ?></span>
                                · <?php echo HTML::chars((string) $i['watch_title']); ?> · <?php echo $dt($i['created_at']); ?>
                            </p>
                        </td>
                        <td class="hidden whitespace-nowrap py-2.5 px-3 text-xs text-gray-500 md:table-cell dark:text-gray-400"><?php echo HTML::chars((string) $i['watch_title']); ?></td>
                        <td class="hidden py-2.5 px-3 text-xs font-bold md:table-cell <?php echo (int) $i['score'] >= 85 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500'; ?>"><?php echo (int) $i['score']; ?></td>
                        <td class="whitespace-nowrap py-2.5 px-3"><span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars($i['status']); ?></span></td>
                        <td class="hidden whitespace-nowrap py-2.5 px-3 text-xs text-gray-500 md:table-cell dark:text-gray-400"><?php echo $dt($i['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?><tr><td colspan="5" class="py-4 pl-4 text-xs text-gray-400 sm:pl-5">Находок пока нет — воркер ещё не обходил радары.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
/* nj605/admin: AJAX-удаление радара (two-click) */
(function () {
    function toast(text, ok) {
        // Общий тост app.js; результат действия держим дольше обычного «ok».
        N2J.toast(text, ok ? 3500 : 0, ok ? 'ok' : 'err');
    }

    document.querySelectorAll('form[data-nj605admin-ajax]').forEach(function (form) {
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
                        var row = document.querySelector('[data-nj605admin-row="' + code + '"]');
                        if (row) row.remove();
                    }
                })
                .catch(function () { toast('Сеть не ответила — попробуйте ещё раз', false); });
        });
    });
})();
</script>
