<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Реестр модулей', '/prx218_module_manager'], ['Сканирование системы']],
        'title' => 'Сканирование системы',
        'lead' => 'Обновляет реестр по папкам модулей. Не включает и не публикует модули.',
        'actions' => [['label' => 'К реестру модулей', 'href' => '/prx218_module_manager', 'icon' => 'fa-cubes']],
    ]); ?>

    <div class="prx-card mt-6">
        <div class="mb-4">
            <p class="text-gray-700 dark:text-gray-300 mb-2">
                <?php echo __('Путь для сканирования модулей:'); ?> <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded text-sm font-mono"><?php echo HTML::chars($scan_path); ?></code>
            </p>
            <p class="text-gray-700 dark:text-gray-300">
                <?php echo __('Последняя активность реестра:'); ?>
                <?php if ($last_activity): ?>
                    <span class="font-medium"><?php echo date('d.m.Y H:i', strtotime($last_activity)); ?></span>
                <?php else: ?>
                    <span class="font-medium"><?php echo __('Никогда'); ?></span>
                <?php endif; ?>
            </p>
        </div>

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <button type="button" id="runScannerBtn" class="prx-btn-process">
                <i class="fas fa-sync-alt mr-3"></i>
                <?php echo __('Запустить сканирование'); ?>
            </button>
            <?php if ($can_analyze): ?>
            <button type="button" id="runBatchAnalyzeBtn" class="prx-btn-secondary">
                <i class="fas fa-brain mr-3"></i>
                <?php echo __('Запустить LLM анализ для всех'); ?>
            </button>
            <?php endif; ?>
        </div>

        <div id="scanResult" class="hidden p-4 rounded-md text-sm" role="alert">
            <p class="font-medium mb-1" id="scanResultMessage"></p>
            <ul class="list-disc list-inside" id="scanResultDetails"></ul>
        </div>
        
        <div id="analyzeResult" class="hidden p-4 rounded-md text-sm" role="alert">
            <p class="font-medium mb-1" id="analyzeResultMessage"></p>
            <ul class="list-disc list-inside" id="analyzeResultDetails"></ul>
        </div>
    </div>

    <?php
    $runs = isset($runs) ? (array) $runs : [];
    $run_status = [
        'ok'      => ['Готово', 'prx-badge-ok'],
        'partial' => ['С ошибками', 'prx-badge-run'],
        'error'   => ['Сбой', 'prx-badge-err'],
        'running' => ['Идёт', 'prx-badge-wait'],
    ];
    ?>
    <div class="prx-card mt-4 overflow-x-auto p-0" id="scanRuns">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
            <h2 class="prx-h3">История сканирований</h2>
            <p class="prx-hint mt-0.5">Только запуски сканера: правки карточек и LLM-анализ сюда не попадают.</p>
        </div>
        <table class="prx-table">
            <thead>
                <tr class="text-left text-gray-400">
                    <th class="px-4 py-2">Запуск</th><th>Кто</th><th>Итог</th>
                    <th class="text-right">Найдено</th><th class="text-right">Добавлено</th><th class="text-right">Обновлено</th>
                    <th class="text-right">Ошибок</th><th class="text-right" title="Записи реестра, чьей папки на диске нет">Нет папки</th>
                    <th class="text-right" title="Название/описание обновлены из манифеста (ручные правки не трогаются)">Из манифеста</th>
                    <th class="pr-4 text-right" title="Модули без памятки AGENTS.md">Без AGENTS</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 dark:text-gray-300">
            <?php if ( ! $runs): ?>
                <tr><td colspan="10" class="px-4 py-4 text-sm text-gray-500 dark:text-gray-400">Сканер ещё не запускали (история ведётся с миграции prx218/003).</td></tr>
            <?php endif; ?>
            <?php foreach ($runs as $r): [$rl, $rc] = $run_status[$r['status']] ?? [$r['status'], 'prx-badge-wait']; ?>
                <tr class="border-t border-gray-50 align-top dark:border-gray-700/50">
                    <td class="whitespace-nowrap px-4 py-2 tabular-nums" title="<?php echo HTML::chars((string) $r['started_at']); ?>">
                        <?php echo HTML::chars(date('d.m.Y H:i:s', strtotime((string) $r['started_at']))); ?>
                        <?php if ( ! empty($r['finished_at'])): ?><span class="text-xs text-gray-400">· <?php echo max(0, strtotime((string) $r['finished_at']) - strtotime((string) $r['started_at'])); ?> с</span><?php endif; ?>
                    </td>
                    <td><?php echo HTML::chars((string) ($r['actor'] ?: 'система')); ?></td>
                    <td>
                        <span class="prx-badge <?php echo $rc; ?>"><?php echo HTML::chars($rl); ?></span>
                        <?php if ( ! empty($r['error'])): ?><div class="mt-0.5 max-w-xs truncate text-xs text-rose-600 dark:text-rose-400" title="<?php echo HTML::chars((string) $r['error']); ?>"><?php echo HTML::chars((string) $r['error']); ?></div><?php endif; ?>
                    </td>
                    <td class="text-right tabular-nums"><?php echo (int) $r['found']; ?></td>
                    <td class="text-right tabular-nums"><?php echo (int) $r['created']; ?></td>
                    <td class="text-right tabular-nums"><?php echo (int) $r['updated']; ?></td>
                    <td class="text-right tabular-nums <?php echo (int) $r['skipped'] > 0 ? 'font-bold text-rose-600 dark:text-rose-400' : ''; ?>"><?php echo (int) $r['skipped']; ?></td>
                    <td class="text-right tabular-nums <?php echo (int) $r['missing'] > 0 ? 'font-bold text-amber-600 dark:text-amber-400' : ''; ?>"><?php echo (int) $r['missing']; ?></td>
                    <td class="text-right tabular-nums"><?php echo (int) ($r['synced'] ?? 0); ?></td>
                    <td class="pr-4 text-right tabular-nums <?php echo (int) ($r['no_agents'] ?? 0) > 0 ? 'font-bold text-amber-600 dark:text-amber-400' : ''; ?>"><?php echo (int) ($r['no_agents'] ?? 0); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(document).ready(function() {
    const scanResult = $('#scanResult');
    const scanResultMessage = $('#scanResultMessage');
    const scanResultDetails = $('#scanResultDetails');

    const analyzeResult = $('#analyzeResult');
    const analyzeResultMessage = $('#analyzeResultMessage');
    const analyzeResultDetails = $('#analyzeResultDetails');

    function showMessage(element, message, type, details = []) {
        element.removeClass('hidden')
               .removeClass('bg-emerald-100 bg-rose-100 bg-blue-100 dark:bg-emerald-900/30 dark:bg-rose-900/30 dark:bg-blue-900/30')
               .addClass(type === 'success' ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-300' : 'bg-rose-100 dark:bg-rose-900/30 text-rose-800 dark:text-rose-300');
        element.find('p').text(message);
        element.find('ul').empty();
        details.forEach(detail => {
            element.find('ul').append($('<li>').text(detail));
        });
    }

    $('#runScannerBtn').on('click', function() {
        $(this).prop('disabled', true).find('i').removeClass('fa-sync-alt').addClass('fa-spinner fa-spin');
        scanResult.addClass('hidden');

        $.ajax({
            url: '/prx218_module_manager/scanner/run',
            method: 'POST',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'ok') {
                    showMessage(scanResult, response.message, response.skipped > 0 ? 'error' : 'success', [
                        `Найдено папок: ${response.found}`,
                        `Добавлено: ${response.added}. Обновлено: ${response.updated}. Пропущено с ошибкой: ${response.skipped}.`,
                        `Папка не найдена (запись реестра сохранена): ${response.missing || 0}`,
                        `Название/описание обновлено из манифеста: ${response.synced || 0}. Без памятки AGENTS.md: ${response.no_agents || 0}`
                    ]);
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    showMessage(scanResult, response.message || '<?php echo __('Произошла ошибка при сканировании.'); ?>', 'error');
                }
            },
            error: function(xhr) {
                const errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : '<?php echo __('Неизвестная ошибка сервера.'); ?>';
                showMessage(scanResult, errorMsg, 'error');
            },
            complete: function() {
                $('#runScannerBtn').prop('disabled', false).find('i').removeClass('fa-spinner fa-spin').addClass('fa-sync-alt');
            }
        });
    });

    $('#runBatchAnalyzeBtn').on('click', function() {
        $(this).prop('disabled', true).find('i').removeClass('fa-brain').addClass('fa-spinner fa-spin');
        analyzeResult.addClass('hidden');

        $.ajax({
            url: '/prx218_module_manager/scanner/batch_analyze',
            method: 'POST',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'ok') {
                    showMessage(analyzeResult, `<?php echo __('LLM анализ завершен.'); ?>`, 'success', [
                        `<?php echo __('Обработано модулей:'); ?> ${response.processed}`
                    ]);
                } else {
                    showMessage(analyzeResult, response.message || '<?php echo __('Произошла ошибка при LLM анализе.'); ?>', 'error');
                }
            },
            error: function(xhr) {
                const errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : '<?php echo __('Неизвестная ошибка сервера.'); ?>';
                showMessage(analyzeResult, errorMsg, 'error');
            },
            complete: function() {
                $('#runBatchAnalyzeBtn').prop('disabled', false).find('i').removeClass('fa-spinner fa-spin').addClass('fa-brain');
            }
        });
    });
});
</script>