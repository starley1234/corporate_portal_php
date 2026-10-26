<?php defined('SYSPATH') or die('No direct script access.');
/**
 * nj607/admin — «Карта отрасли» · управление: все отчёты (кто/когда/статус),
 * здоровье воркера (канал nj607.industry); действия — AJAX (two-click).
 */
$dt = function ($v) { return $v ? HTML::chars(Date::formatted_time((string) $v, 'd.m.y H:i')) : '—'; };

$running = FALSE; $last_ok = ''; $last_err = '';
foreach ($jobs as $j)
{
    if (in_array($j['status'], ['pending', 'processing'], TRUE) AND ! $running) { $running = TRUE; }
    if ($j['status'] === 'done' AND $last_ok === '')    { $last_ok = $dt($j['finished_at']); }
    if ($j['status'] === 'failed' AND $last_err === '') { $last_err = $dt($j['finished_at']); }
}
$st = [
    'running' => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    'done'    => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    'error'   => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
    'new'     => 'bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300',
    'closed'  => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/industry" class="transition hover:text-blue-600 dark:hover:text-blue-400">Карта отрасли</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Управление</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-map mr-2 text-blue-500"></i>Карта отрасли · управление
        </h1>
        <a href="#" onclick="return false;" class="n2j-btn-secondary n2j-btn-sm">
            <i class="fas fa-lock text-[11px]"></i>Управление
        </a>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">Все отчёты всех пользователей: кто и когда запустил, чем закончилось. Зависший или упавший сбор перезапускается одной кнопкой.</p>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <!-- Карточка воркера -->
    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div class="n2j-card sm:col-span-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Воркер nj607.industry</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg <?php echo $running ? 'bg-amber-50 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400'; ?>"><i class="fas fa-robot text-sm"></i></span>
            </div>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs">
                <?php if ($running): ?><i class="fas fa-spinner n2j-spin text-[11px] text-amber-600 dark:text-amber-400"></i><span class="font-medium text-gray-700 dark:text-gray-300">отчёт собирается</span>
                <?php else: ?><span class="font-medium text-gray-700 dark:text-gray-300">сейчас тихо</span><?php endif; ?>
            </div>
            <p class="n2j-hint">последний удачный: <?php echo $last_ok ?: '—'; ?><?php echo $last_err !== '' ? ' · ошибка: '.$last_err : ''; ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Всего отчётов</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo count($reports); ?></p>
        </div>
    </div>

    <!-- Таблица отчётов -->
    <div class="n2j-card p-0 mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Отчёты</h2>
                <span class="n2j-badge n2j-badge-wait"><?php echo count($reports); ?></span>
                <?php /* Онтология: разовая догрузка упоминаний у старых отчётов
                 * (после миграции 003). Пачками по 5 отчётов за клик. */ ?>
                <form method="post" class="ml-auto">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars((string) ($csrf ?? Security::token())); ?>">
                    <input type="hidden" name="act" value="ontology_backfill">
                    <button type="submit" title="Разобрать старые отчёты заново — заполнить онтологию (по 5 отчётов за клик)" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-diagram-project mr-1"></i>Разобрать онтологию (5 отчётов)</button>
                </form>
            </div>
        </div>
        <div class="overflow-x-auto n2j-scrollbar">
            <table class="n2j-table whitespace-nowrap">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                        <th class="py-2.5 pr-3 pl-4 sm:pl-5">Отрасль / регион</th>
                        <th class="py-2.5 px-3">Кто</th>
                        <th class="py-2.5 px-3">Тип</th>
                        <th class="py-2.5 px-3">Статус</th>
                        <th class="py-2.5 px-3">Создан</th>
                        <th class="py-2.5 px-3">Готов</th>
                        <th class="py-2.5 pr-4 pl-3 text-right sm:pr-5">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                    <?php foreach ($reports as $r): ?>
                    <?php echo View::factory('nj607/admin_row', ['r' => $r, 'st' => $st, 'dt' => $dt]); ?>
                    <?php endforeach; ?>
                    <?php if (empty($reports)): ?><tr><td colspan="7" class="py-4 pl-4 text-xs text-gray-400 sm:pl-5">Отчётов пока нет.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
/* nj607/admin: AJAX-действия (перезапуск/закрыть/удалить) — без перезагрузки */
(function () {
    var CSRF = <?php echo json_encode(Security::token()); ?>;

    /* Обновление строки после перезапуска/закрытия ЧЕРЕЗ AJAX: страница не
       перезагружается, статус подменяется прямо в таблице. */
    function refreshRow(code, row) {
        fetch('/nj607/admin?rows=1&code=' + encodeURIComponent(code), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.row) return;
            if (!row) row = document.querySelector('[data-nj607admin-row="' + code + '"]');
            if (!row) return;
            var holder = document.createElement('tbody');
            holder.innerHTML = res.row;
            var fresh = holder.querySelector('[data-nj607admin-row="' + code + '"]');
            if (fresh) row.replaceWith(fresh);
            bindActions(fresh || row);
        })
        .catch(function () { /* сеть моргнула — строка обновится при следующем действии */ });
    }

    function toast(text, ok) {
        // Общий тост app.js; результат действия держим дольше обычного «ok».
        N2J.toast(text, ok ? 3500 : 0, ok ? 'ok' : 'err');
    }

    function bindActions(root) {
        root.querySelectorAll('[data-nj607admin-act]').forEach(function (btn) {
            if (btn.dataset.nj607Bound) return;
            btn.dataset.nj607Bound = '1';
            btn.addEventListener('click', function () {
                var act = btn.getAttribute('data-nj607admin-act');
                /* опасное действие (data-n2j-arm): первый клик взводит кнопку
                   в app.js и сюда не доходит, второй — выполняет */
                btn.disabled = true;
                var fd = new FormData();
                fd.set('csrf', CSRF); fd.set('act', act); fd.set('code', btn.getAttribute('data-nj607admin-code')); fd.set('ajax', '1');
                fetch('/nj607/admin', { method: 'POST', body: fd, cache: 'no-store',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        toast(res.flash || (res.ok ? 'Готово' : 'Не получилось'), !!res.ok);
                        if (res.ok) {
                            var row = document.querySelector('[data-nj607admin-row="' + btn.getAttribute('data-nj607admin-code') + '"]');
                            if (act === 'delete') { if (row) row.remove(); }
                            else { refreshRow(btn.getAttribute('data-nj607admin-code'), row); }
                        }
                        btn.disabled = false;
                    })
                    .catch(function () { toast('Сеть не ответила', false); btn.disabled = false; });
            });
        });
    }

    bindActions(document);
})();
</script>
