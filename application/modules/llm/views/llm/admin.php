<?php defined('SYSPATH') or die('No direct script access.'); $llm_flash = Session::instance()->get_once('llm_flash'); ?>
<link rel="stylesheet" type="text/css" href="/assets/vendor/datatables/css/jquery.dataTables.min.css">
<?php /* Обычный CSS: @apply в браузере не работает (с 28.09.2026 Tailwind собирается заранее). */ ?>
<?php /* design-audit: allow style — тёмная тема DataTables: разметку рисует библиотека */ ?>
<style>
    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
        background: #f9fafb; border: 1px solid #d1d5db; color: #111827; font-size: .875rem;
        border-radius: .5rem; padding: .5rem; outline: none;
    }
    .dataTables_wrapper .dataTables_filter input { margin-bottom: 1rem; }
    .dataTables_wrapper .dataTables_length select { padding: .375rem; }
    .dataTables_wrapper .dataTables_filter input:focus,
    .dataTables_wrapper .dataTables_length select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgb(59 130 246 / .15); }
    .dark .dataTables_wrapper .dataTables_filter input,
    .dark .dataTables_wrapper .dataTables_length select { background: #374151; border-color: #4b5563; color: #fff; }
    .dark .dataTables_wrapper .dataTables_filter input::placeholder { color: #9ca3af; }
    .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_paginate { font-size: .875rem; color: #6b7280; margin-top: 1rem; }
    .dark .dataTables_wrapper .dataTables_info, .dark .dataTables_wrapper .dataTables_paginate { color: #9ca3af; }
    table.dataTable { border-collapse: collapse !important; }
    table.dataTable thead th { border-bottom: 1px solid #e5e7eb !important; }
    .dark table.dataTable thead th { border-bottom: 1px solid #374151 !important; }
</style>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php if ($llm_flash): $llm_flash_ok = ! empty($llm_flash['ok']); ?>
    <div class="prl-flash <?php echo $llm_flash_ok ? 'prl-flash-ok' : 'prl-flash-err'; ?> mb-5" role="<?php echo $llm_flash_ok ? 'status' : 'alert'; ?>">
        <i class="fas <?php echo $llm_flash_ok ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
        <span><?php echo HTML::chars($llm_flash['message'] ?? ''); ?></span>
    </div>
    <?php endif; ?>

    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Инструменты ИИ'], ['Локальные LLM']],
        'title'  => 'Управление локальными LLM',
        'pill'   => ['LM Studio', 'fa-robot'],
        'lead'   => 'Состояние локального сервера инференса и модели, доступные сервисам портала.',
    ]); ?>

    <section class="prl-panel mt-6">
        <div class="prl-panel-head">
            <div>
                <h2 class="prl-h3"><i class="fas fa-server mr-2 text-blue-500"></i>Локальный провайдер (LM Studio)</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Управление моделями и мониторинг состояния API.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <?php if ($error): ?>
                    <span class="prl-badge prl-badge-err"><span class="prl-dot"></span>Нет соединения</span>
                <?php else: ?>
                    <span class="prl-badge prl-badge-ok"><span class="prl-dot"></span>Сервер доступен</span>
                <?php endif; ?>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Сервер: <code class="rounded-md bg-gray-100 px-2 py-0.5 text-xs dark:bg-gray-700"><?php echo HTML::chars($base_url); ?></code></span>
            </div>
        </div>

        <div class="p-6">
            <?php if ($error): ?>
                <div class="prl-flash prl-flash-err" role="alert">
                    <i class="fas fa-triangle-exclamation"></i>
                    <div>
                        <strong>Ошибка соединения</strong>
                        <p class="mt-1"><?php echo HTML::chars($error); ?></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table id="modelsTable" class="prl-table border-collapse">
                        <thead>
                            <tr class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                                <th class="px-4 py-3">Модель</th>
                                <th class="px-4 py-3">Архитектура / Параметры</th>
                                <th class="px-4 py-3">Контекст</th>
                                <th class="px-4 py-3">Размер / Квантование</th>
                                <th class="px-4 py-3 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php if (isset($status_data['models']) && !empty($status_data['models'])):
                                $models = $status_data['models'];
                            ?>
                                <?php foreach ($models as $model): ?>
                                    <tr id="model-row-<?php echo md5($model['key']); ?>" class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors <?php echo !empty($model['loaded_instances']) ? 'bg-emerald-50/30 dark:bg-emerald-900/10' : ''; ?>">
                                        <td class="px-4 py-4">
                                            <div class="flex flex-col">
                                                <div class="flex items-center">
                                                    <i class="fas <?php echo ($model['type'] === 'embedding') ? 'fa-font' : 'fa-robot'; ?> <?php echo !empty($model['loaded_instances']) ? 'text-emerald-500' : 'text-blue-500'; ?> mr-3"></i>
                                                    <span class="font-medium text-gray-900 dark:text-white"><?php echo HTML::chars($model['display_name'] ?? $model['key']); ?></span>
                                                    <?php if (!empty($model['loaded_instances'])): ?>
                                                        <span data-llm-loaded class="prl-badge prl-badge-ok ml-2"><span class="prl-dot"></span>Загружена</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex items-center mt-1 ml-7 group">
                                                    <span class="text-xs text-gray-400 truncate max-w-[200px]"><?php echo HTML::chars($model['key']); ?></span>
                                                    <button onclick="copyToClipboard('<?php echo HTML::chars($model['key']); ?>', this)" class="prl-btn-icon ml-2" title="Копировать ID">
                                                        <i class="far fa-copy text-[10px]"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                                <span class="uppercase"><?php echo HTML::chars($model['architecture'] ?? 'n/a'); ?></span>
                                                <?php if (!empty($model['params_string'])): ?>
                                                    <span class="prl-count ml-2"><?php echo HTML::chars($model['params_string']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex gap-1 mt-1">
                                                <?php if ($model['capabilities']['vision'] ?? false): ?>
                                                    <span title="Vision" class="text-blue-400 text-xs"><i class="fas fa-eye"></i></span>
                                                <?php endif; ?>
                                                <?php if ($model['capabilities']['trained_for_tool_use'] ?? false): ?>
                                                    <span title="Tools" class="text-emerald-400 text-xs"><i class="fas fa-tools"></i></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-gray-600 dark:text-gray-400">
                                            <?php echo number_format($model['max_context_length'] ?? 0, 0, '.', ' '); ?>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="text-sm text-gray-900 dark:text-white">
                                                <?php echo round(($model['size_bytes'] ?? 0) / 1024 / 1024 / 1024, 2); ?> GB
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo HTML::chars($model['quantization']['name'] ?? 'unknown'); ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-right">
                                            <?php if (!empty($model['loaded_instances'])): ?>
                                                <button
                                                   onclick="unloadModel('<?php echo HTML::chars($model['key']); ?>', '<?php echo md5($model['key']); ?>')"
                                                   class="unload-btn prl-btn-danger prl-btn-sm">
                                                    <i class="fas fa-eject mr-1.5"></i> <span>Выгрузить</span>
                                                </button>
                                            <?php else: ?>
                                                <button
                                                   onclick="loadModel('<?php echo HTML::chars($model['key']); ?>', '<?php echo md5($model['key']); ?>')"
                                                   class="load-btn prl-btn-secondary prl-btn-sm">
                                                    <i class="fas fa-download mr-1.5"></i> <span>Загрузить</span>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </section>

    <section class="prl-card mt-6">
        <h2 class="prl-h3"><i class="fas fa-circle-info mr-2 text-blue-500"></i>Справка по интеграции</h2>
        <div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-2">
            <div class="space-y-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                <p>Для работы с локальными моделями используйте <strong>LM Studio</strong>.</p>
                <p>Убедитесь, что сервер запущен и принимает запросы по адресу, указанному в конфигурации <code>llm.php</code>.</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                <code class="break-all text-xs text-gray-700 dark:text-gray-200">
                    // Config path:<br>
                    application/config/llm.php
                </code>
            </div>
        </div>
    </section>
</div>

<script src="/assets/vendor/jquery/jquery-3.7.1.min.js"></script>
<script src="/assets/vendor/datatables/js/jquery.dataTables.min.js"></script>

<script>
$(document).ready(function() {
    $('#modelsTable').DataTable({
        pageLength: 50,
        // order: [[0, 'asc']],
        language: {
            url: '/assets/vendor/datatables/i18n/ru.json'
        },
        ordering: false,
        columnDefs: [
            { sorting: false, orderable: false, targets: 4 }
        ],
        dom: '<"flex flex-col sm:flex-row justify-between items-center mb-4"f l>rtip'
    });
});

function copyToClipboard(text, btn) {
    PRL.copyText(text).then(() => {
        const icon = btn.querySelector('i');
        icon.classList.remove('far', 'fa-copy');
        icon.classList.add('fas', 'fa-check', 'text-emerald-500');

        PRL.toast('ID модели скопирован');

        setTimeout(() => {
            icon.classList.remove('fas', 'fa-check', 'text-emerald-500');
            icon.classList.add('far', 'fa-copy');
        }, 2000);
    }, () => PRL.toast('Не удалось скопировать'));
}

function unloadModel(modelKey, rowId) {
    // окно эталона v6 вместо window.confirm
    PRL.confirm({ title: 'Выгрузить модель?', message: 'Выгрузить модель {subject} из памяти?', subject: modelKey, ok: 'Выгрузить', tone: 'process' })
        .then(function (yes) { if (yes) { unloadModelNow(modelKey, rowId); } });
}

function unloadModelNow(modelKey, rowId) {
    const row = document.getElementById('model-row-' + rowId);
    const btn = row.querySelector('.unload-btn');
    const originalContent = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i> Выгрузка...';

    fetch('<?php echo Route::url("default", ["controller" => "llm_admin", "action" => "unload"]); ?>', {
        method: 'POST',
        body: new URLSearchParams({model: modelKey, csrf: <?php echo json_encode(Security::token()); ?>}),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const actionCell = btn.parentElement;
            actionCell.innerHTML = '<button onclick="loadModel(\'' + modelKey + '\', \'' + rowId + '\')" class="load-btn prl-btn-secondary prl-btn-sm"><i class="fas fa-download mr-1.5"></i> <span>Загрузить</span></button>';
            row.classList.remove('bg-emerald-50/30', 'dark:bg-emerald-900/10');
            const icon = row.querySelector('.fa-robot, .fa-font');
            if (icon) {
                icon.classList.remove('text-emerald-500');
                icon.classList.add('text-blue-500');
            }
            const badge = row.querySelector('[data-llm-loaded]');
            if (badge) badge.remove();

            PRL.toast('Модель выгружена', 0, 'ok');
        } else {
            PRL.toast('Ошибка: ' + (data.message || 'Неизвестная ошибка'), 0, 'err');
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        PRL.toast('Не удалось отправить запрос — проверьте сеть', 0, 'err');
        btn.disabled = false;
        btn.innerHTML = originalContent;
    });
}

function loadModel(modelKey, rowId) {
    // окно эталона v6 вместо window.confirm
    PRL.confirm({ title: 'Загрузить модель?', message: 'Загрузить модель {subject} в память?', subject: modelKey, ok: 'Загрузить', tone: 'process' })
        .then(function (yes) { if (yes) { loadModelNow(modelKey, rowId); } });
}

function loadModelNow(modelKey, rowId) {
    const row = document.getElementById('model-row-' + rowId);
    const btn = row.querySelector('.load-btn');
    const originalContent = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i> Загрузка...';

    fetch('<?php echo Route::url("default", ["controller" => "llm_admin", "action" => "load"]); ?>', {
        method: 'POST',
        body: new URLSearchParams({model: modelKey, csrf: <?php echo json_encode(Security::token()); ?>}),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const actionCell = btn.parentElement;
            actionCell.innerHTML = '<button onclick="unloadModel(\'' + modelKey + '\', \'' + rowId + '\')" class="unload-btn prl-btn-danger prl-btn-sm"><i class="fas fa-eject mr-1.5"></i> <span>Выгрузить</span></button>';
            row.classList.add('bg-emerald-50/30', 'dark:bg-emerald-900/10');
            const icon = row.querySelector('.fa-robot, .fa-font');
            if (icon) {
                icon.classList.remove('text-blue-500');
                icon.classList.add('text-emerald-500');
            }
            const nameSpan = row.querySelector('.font-medium');
            if (nameSpan) {
                nameSpan.insertAdjacentHTML('afterend', '<span data-llm-loaded class="prl-badge prl-badge-ok ml-2"><span class="prl-dot"></span>Загружена</span>');
            }

            PRL.toast('Модель загружена', 0, 'ok');
        } else {
            PRL.toast('Ошибка: ' + (data.message || 'Неизвестная ошибка'), 0, 'err');
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        PRL.toast('Не удалось отправить запрос — проверьте сеть', 0, 'err');
        btn.disabled = false;
        btn.innerHTML = originalContent;
    });
}
</script>
