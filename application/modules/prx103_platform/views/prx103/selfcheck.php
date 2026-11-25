<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100/selfcheck — «Проверка сервера»: одна кнопка, отчёт по среде, БД,
 * миграциям, очереди, докам модулей — сразу; компиляция php-кода — порциями
 * из браузера с прогресс-баром (750+ файлов одним запросом = 502 от nginx,
 * прод 25.11.2026). CSRF-заголовок к fetch добавляет глобальный хук app.js.
 */

$counts = ['OK' => 0, 'WARN' => 0, 'FAIL' => 0];
if ($results !== NULL)
{
    foreach ($results as $row)
    {
        $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
    }
}

// цвет бейджа статуса
$badge = [
    'OK'   => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
    'WARN' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    'FAIL' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400',
];

?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['Администрирование', '/admin'], ['Проверка сервера']],
        'title'  => 'Проверка сервера',
        'pill'   => ['prx103 · selfcheck', 'fa-clipboard-check'],
        'lead'   => 'Проверки bin/console dev:doctor плюс компиляция всех php-файлов (php -l) по частям. Ничего не исполняется и не меняется — только читается состояние. Быстрая часть — секунды, компиляция идёт после неё порциями с прогрессом.',
    ]); ?>

    <?php if ( ! empty($flash)): ?>
    <div class="prx-flash prx-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <form method="post" action="/prx100/selfcheck" class="mt-4">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
        <button type="submit" class="prx-btn-cta">
            <i class="fas fa-play mr-1.5"></i><span>Прогнать проверки</span>
        </button>
    </form>

    <?php if ($results !== NULL): ?>

    <div class="mt-5 max-w-4xl">
        <p class="text-xs font-semibold mb-2
            <?php echo $counts['FAIL'] > 0
                ? 'text-red-600 dark:text-red-400'
                : ($counts['WARN'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'); ?>">
            Быстрые проверки — итог: <?php echo $counts['OK']; ?> в порядке,
            <?php echo $counts['WARN']; ?> предупреждений,
            <?php echo $counts['FAIL']; ?> проблем
        </p>

        <div class="prx-card divide-y divide-gray-100 dark:divide-gray-800">
            <?php $group = NULL; ?>
            <?php foreach ($results as $row): ?>
                <?php if ($row['group'] !== $group): $group = $row['group']; ?>
                <p class="px-3.5 pt-3 pb-1 text-[11px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    <?php echo HTML::chars($group); ?>
                </p>
                <?php endif; ?>
                <div class="flex items-start gap-2.5 px-3.5 py-2">
                    <span class="shrink-0 inline-flex justify-center min-w-[44px] rounded px-1.5 py-0.5 text-[11px] font-bold <?php echo $badge[$row['status']] ?? ''; ?>">
                        <?php echo HTML::chars($row['status']); ?>
                    </span>
                    <span class="text-xs text-gray-700 dark:text-gray-300 break-words min-w-0">
                        <?php echo HTML::chars($row['message']); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($lint_max > 0): ?>
    <div id="prx-selfcheck-lint" class="mt-4 max-w-4xl prx-card" data-total="<?php echo (int) $lint_max; ?>">
        <div class="flex items-center justify-between gap-3 px-3.5 pt-3">
            <p class="text-sm font-bold text-gray-900 dark:text-white">
                <i class="fas fa-code mr-1.5 text-gray-400"></i>Синтаксис PHP
                <span class="ml-1 text-xs font-semibold text-gray-400">— <?php echo (int) $lint_max; ?> файлов, идёт по частям…</span>
            </p>
            <span data-lint-counter class="shrink-0 prx-count">0 / <?php echo (int) $lint_max; ?></span>
        </div>
        <div class="px-3.5 pt-2">
            <div class="h-2 rounded bg-gray-200 dark:bg-gray-700 overflow-hidden">
                <div data-lint-bar class="h-full w-0 bg-emerald-500 transition-all"></div>
            </div>
        </div>
        <div data-lint-status class="px-3.5 py-2 text-xs text-gray-500 dark:text-gray-400">подождите…</div>
        <div data-lint-bad class="px-3.5 pb-3 hidden">
            <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500 pb-1">Ошибки</p>
            <div data-lint-list class="space-y-1.5"></div>
        </div>
    </div>
    <?php elseif ($lint_note !== ''): ?>
    <div class="mt-4 max-w-4xl prx-flash prx-flash-info text-xs font-semibold block"><?php echo HTML::chars($lint_note); ?></div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<?php if ($results !== NULL AND $lint_max > 0): ?>
<script>
(function () {
    'use strict';
    var root = document.getElementById('prx-selfcheck-lint');
    if (!root || typeof window.fetch !== 'function') return;

    var total = parseInt(root.dataset.total || '0', 10);
    if (!total) return;

    var bar = root.querySelector('[data-lint-bar]');
    var counter = root.querySelector('[data-lint-counter]');
    var status = root.querySelector('[data-lint-status]');
    var badBox = root.querySelector('[data-lint-bad]');
    var list = root.querySelector('[data-lint-list]');
    var CHUNK = 40;
    var offset = 0;
    var badCount = 0;
    var stopped = false;

    function note(text) {
        status.textContent = text;
        status.classList.remove('text-gray-500', 'dark:text-gray-400');
        status.classList.add('text-amber-600', 'dark:text-amber-400');
    }

    function finalLine(text, ok) {
        status.textContent = text;
        status.classList.remove('text-gray-500', 'dark:text-gray-400');
        status.classList.add(ok ? 'text-emerald-600' : 'text-red-600', ok ? 'dark:text-emerald-400' : 'dark:text-red-400');
    }

    function appendBad(rows) {
        if (!rows.length) return;
        badBox.classList.remove('hidden');
        rows.forEach(function (item) {
            var line = document.createElement('p');
            line.className = 'text-xs text-red-600 dark:text-red-400 break-words';
            line.textContent = item.file + ': ' + item.text;
            list.appendChild(line);
        });
    }

    function step() {
        if (stopped) return;
        var body = new FormData();
        body.append('offset', String(offset));
        body.append('count', String(CHUNK));

        fetch('/prx100/selfcheck/lint', { method: 'POST', body: body })
            .then(function (r) {
                return r.text().then(function (text) {
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) { /* не JSON */ }
                    if (!r.ok || !data || data.ok === undefined) {
                        stopped = true;
                        note('сервер ответил HTTP ' + r.status + ' вместо JSON — '
                            + 'подробности в /prx100/logs (HTTP-лог Kohana)');
                        return null;
                    }
                    return data;
                });
            })
            .then(function (data) {
                if (!data || stopped) return;
                if (data.ok !== true) {
                    stopped = true;
                    bar.classList.remove('bg-emerald-500');
                    bar.classList.add('bg-amber-500');
                    note(data.note || 'проверка синтаксиса не смогла начаться — обновите страницу');
                    return;
                }
                offset = Math.max(offset, data.done);
                badCount += data.bad.length;
                appendBad(data.bad);
                bar.style.width = Math.round((offset / data.total) * 100) + '%';
                if (counter) counter.textContent = offset + ' / ' + data.total;

                if (data.finished) {
                    if (bar) bar.classList.toggle('bg-emerald-500', badCount === 0);
                    if (badCount === 0) bar.classList.add('bg-emerald-500');
                    finalLine(badCount === 0
                        ? 'php -l: ' + data.total + ' файлов — ошибок нет'
                        : 'php -l: ' + data.total + ' файлов, найдено ошибок: ' + badCount, badCount === 0);
                    return;
                }
                step();
            })
            .catch(function () {
                stopped = true;
                note('связь прервана на позиции ' + offset + ' — обновите страницу, чтобы продолжить');
            });
    }

    step();
})();
</script>
<?php endif; ?>
