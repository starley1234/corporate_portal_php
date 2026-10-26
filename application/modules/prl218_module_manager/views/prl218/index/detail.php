<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
        <div class="flex items-center space-x-4">
            <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900/30 rounded-2xl flex items-center justify-center text-blue-600 dark:text-blue-400 text-2xl shadow-sm">
                <?php if (!empty($module->icon)): ?>
                    <i class="fas <?php echo HTML::chars($module->icon); ?> text-lg"></i>
                <?php else: ?>
                    <i class="fas fa-box-open text-lg"></i>
                <?php endif; ?>
            </div>
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="prl-h1">
                        <?php echo HTML::chars($module->display_name); ?>
                    </h1>
                    <span class="prl-badge prl-badge-wait">
                        v<?php echo HTML::chars($module->version); ?>
                    </span>
                </div>
                <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
                    <i class="far fa-folder mr-1"></i> application/modules/<?php echo HTML::chars($module->system_name); ?>
                </p>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <?php
                $status_classes = [
                    'alpha'    => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                    'beta'     => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                    'stable'   => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                    'archived' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400',
                ];
                $current_status = $module->status ?? 'alpha';
            ?>
            <button class="edit-status-btn px-4 py-2 rounded-lg text-sm font-semibold <?php echo $status_classes[$current_status] ?? $status_classes['alpha']; ?>"
                    data-module-id="<?php echo $module->id; ?>"
                    data-current-status="<?php echo HTML::chars($current_status); ?>">
                <i class="fas fa-circle text-[8px] mr-2 opacity-70"></i>
                <span class="status-text"><?php echo ucfirst(HTML::chars($current_status)); ?></span>
            </button>

            <button class="prl-btn-secondary edit-attributes-btn"
                    data-module-id="<?php echo $module->id; ?>"
                    data-display-name="<?php echo HTML::chars($module->display_name); ?>"
                    data-description="<?php echo HTML::chars($module->description); ?>"
                    data-version="<?php echo HTML::chars($module->version); ?>"
                    data-icon="<?php echo HTML::chars($module->icon); ?>"
                    data-main-page-uri="<?php echo HTML::chars($module->main_page_uri); ?>">
                <i class="fas fa-edit"></i>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Left Column: Info & Dependencies -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Description Card -->
            <div class="prl-panel overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="font-bold text-gray-900 dark:text-white">Описание и назначение</h3>
                    <button class="toggle-published relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none <?php echo $module->is_published ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-700'; ?>"
                            data-module-id="<?php echo $module->id; ?>"
                            data-is-published="<?php echo $module->is_published ? '1' : '0'; ?>">
                        <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out <?php echo $module->is_published ? 'translate-x-5' : 'translate-x-0'; ?>"></span>
                    </button>
                </div>
                <div class="p-6">
                    <div class="prl-prose text-gray-600 dark:text-gray-300">
                        <?php echo nl2br(HTML::chars($module->description)); ?>
                    </div>

                    <?php if (!empty($module->developer_notes)): ?>
                        <div class="mt-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/50 rounded-xl relative group">
                            <h4 class="text-sm font-bold text-amber-800 dark:text-amber-400 mb-2">
                                <i class="fas fa-sticky-note mr-2"></i> Заметки разработчика
                            </h4>
                            <p class="text-sm text-amber-700 dark:text-amber-300/80 leading-relaxed">
                                <?php echo nl2br(HTML::chars($module->developer_notes)); ?>
                            </p>
                            <button class="prl-btn-icon edit-notes-btn absolute top-4 right-4 opacity-0 group-hover:opacity-100"
                                    data-module-id="<?php echo $module->id; ?>"
                                    data-notes="<?php echo HTML::chars($module->developer_notes); ?>">
                                <i class="fas fa-pen text-xs"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Dependencies Card -->
            <div class="prl-panel overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="font-bold text-gray-900 dark:text-white">Зависимости системы</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        <?php if (isset($dependencies) && !empty($dependencies)): ?>
                            <?php foreach ($dependencies as $dep): ?>
                                <div class="flex items-center justify-between p-3 rounded-xl bg-gray-50 dark:bg-gray-900/50 border border-gray-100 dark:border-gray-700">
                                    <div class="flex items-center">
                                        <i class="fas fa-link text-gray-400 mr-3 text-sm"></i>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($dep->name); ?></span>
                                    </div>
                                    <span class="text-xs px-2 py-1 rounded bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 text-gray-500">
                                        <?php echo HTML::chars($dep->version_required); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-sm text-gray-500 italic">Зависимости не указаны</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Functionality Card -->
            <div class="prl-panel overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="font-bold text-gray-900 dark:text-white">Функционал модуля</h3>
                </div>
                <div class="p-6">
                    <?php
                        $functionality = json_decode($module->functionality, true);
                        if (!empty($functionality)):
                    ?>
                        <ul class="list-disc list-inside space-y-2 text-gray-700 dark:text-gray-300">
                            <?php foreach ($functionality as $item): ?>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-emerald-500 mr-2 mt-1 text-sm"></i>
                                    <span><?php echo HTML::chars($item); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-sm text-gray-500 italic">Функционал не описан.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: History & Stats -->
        <div class="space-y-6">
            <!-- Meta Stats -->
            <div class="prl-card">
                <h3 class="font-bold text-gray-900 dark:text-white mb-4 text-sm uppercase tracking-wider">Информация</h3>
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-500">Последнее обновление</span>
                        <span class="text-sm font-medium"><?php echo date('d.m.Y H:i', strtotime($module->updated_at)); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-500">Тип</span>
                        <span class="text-sm font-medium">Internal Module</span>
                    </div>
                    <?php if (!empty($module->main_page_uri)): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Главная страница</span>
                            <a href="<?php echo URL::site($module->main_page_uri); ?>" target="_blank" class="text-sm font-medium text-blue-600 hover:underline">
                                Перейти <i class="fas fa-external-link-alt ml-1 text-xs"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                    <div class="pt-4 border-t border-gray-100 dark:border-gray-700">
                        <button type="button" data-prl-confirm="LLM-анализ модуля займёт некоторое время." data-prl-title="Запустить анализ?" data-prl-ok="Запустить" data-prl-tone="process" class="prl-btn-process run-ai-btn w-full" data-module-id="<?php echo $module->id; ?>">
                            <i class="fas fa-robot mr-2"></i> Запустить LLM анализ
                        </button>
                    </div>
                </div>
            </div>

            <!-- LLM Analysis Card -->
            <div class="prl-panel overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="font-bold text-gray-900 dark:text-white">LLM Анализ</h3>
                    <?php if ($module->analysis->loaded() && $module->analysis->analysis_status == 'completed'): ?>
                        <span class="text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                            <i class="fas fa-check-circle mr-1.5"></i> Завершен
                        </span>
                    <?php elseif ($module->analysis->loaded() && $module->analysis->analysis_status == 'pending'): ?>
                        <span class="text-xs text-amber-600 dark:text-amber-400 font-medium">
                            <i class="fas fa-hourglass-half mr-1.5"></i> В ожидании
                        </span>
                    <?php else: ?>
                        <span class="text-xs text-gray-500 dark:text-gray-400 font-medium">
                            <i class="fas fa-info-circle mr-1.5"></i> Нет данных
                        </span>
                    <?php endif; ?>
                </div>
                <div class="p-6">
                    <?php if ($module->analysis->loaded() && $module->analysis->analysis_status == 'completed'): ?>
                        <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                            <?php echo nl2br(HTML::chars($module->analysis->ai_summary)); ?>
                        </p>
                        <div class="flex justify-between items-center text-xs text-gray-500 dark:text-gray-400">
                            <span><?php echo date('d.m.Y H:i', strtotime($module->analysis->last_scan_at)); ?></span>
                            <button type="button" data-prl-confirm="LLM-анализ модуля займёт некоторое время." data-prl-title="Запустить анализ?" data-prl-ok="Запустить" data-prl-tone="process" class="prl-link run-ai-btn" data-module-id="<?php echo $module->id; ?>">Переанализировать</button>
                        </div>
                    <?php else: ?>
                        <p class="text-sm text-gray-500 italic mb-4">Данные LLM анализа отсутствуют.</p>
                        <button type="button" data-prl-confirm="LLM-анализ модуля займёт некоторое время." data-prl-title="Запустить анализ?" data-prl-ok="Запустить" data-prl-tone="process" class="prl-link run-ai-btn text-sm" data-module-id="<?php echo $module->id; ?>">Запустить анализ</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals (Reused from list.php) -->
<div id="notesModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="prl-modal-box relative top-20 mx-auto">
        <div class="mt-3 text-center">
            <h3 class="prl-h3">Редактировать примечания</h3>
            <div class="mt-2 px-7 py-3">
                <textarea id="developerNotesInput" class="prl-textarea w-full" rows="5"></textarea>
            </div>
            <div class="items-center px-4 py-3 flex gap-2">
                <button id="saveNotesBtn" class="prl-btn-cta w-full">Сохранить</button>
                <button id="cancelNotesBtn" class="prl-btn-secondary w-full">Отмена</button>
            </div>
        </div>
    </div>
</div>

<div id="attributesModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="prl-modal-box relative top-20 mx-auto">
        <div class="mt-3">
            <h3 class="prl-h3 text-center mb-4">Редактировать атрибуты</h3>
            <div class="space-y-4">
                <div>
                    <label class="prl-label">Название</label>
                    <input type="text" id="displayNameInput" class="prl-input mt-1 w-full">
                </div>
                <div>
                    <label class="prl-label">Описание</label>
                    <textarea id="descriptionInput" class="prl-textarea mt-1 w-full" rows="3"></textarea>
                </div>
                <div>
                    <label class="prl-label">Версия</label>
                    <input type="text" id="versionInput" class="prl-input mt-1 w-full">
                </div>
                <div>
                    <label class="prl-label">Иконка (FA класс)</label>
                    <input type="text" id="iconInput" class="prl-input mt-1 w-full">
                </div>
                <div>
                    <label class="prl-label">Main Page URI</label>
                    <input type="text" id="mainPageUriInput" class="prl-input mt-1 w-full">
                </div>
            </div>
            <div class="mt-6 flex gap-2">
                <button id="saveAttributesBtn" class="prl-btn-cta w-full">Сохранить</button>
                <button id="cancelAttributesBtn" class="prl-btn-secondary w-full">Отмена</button>
            </div>
        </div>
    </div>
</div>

<div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="prl-modal-box relative top-20 mx-auto max-w-sm">
        <div class="mt-3 text-center">
            <h3 class="prl-h3 mb-4">Изменить статус</h3>
            <select id="statusSelect" class="prl-input prl-select mt-1 w-full">
                <option value="alpha">Alpha</option>
                <option value="beta">Beta</option>
                <option value="stable">Stable</option>
                <option value="archived">Archived</option>
            </select>
            <div class="mt-6 flex gap-2">
                <button id="saveStatusBtn" class="prl-btn-cta w-full">Сохранить</button>
                <button id="cancelStatusBtn" class="prl-btn-secondary w-full">Отмена</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Reuse logic from list.php for API calls
    const statusClasses = {
        'alpha': 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
        'beta': 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        'stable': 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
        'archived': 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400',
    };

    // Toggle Published
    $(document).on('click', '.toggle-published', function() {
        const btn = $(this);
        const id = btn.data('module-id');
        const val = btn.data('is-published') === 1 ? 0 : 1;
        $.post('/prl218/api/toggle_published', {id: id, is_published: val}, function(res) {
            if(res.status === 'ok') {
                btn.data('is-published', res.is_published);
                btn.toggleClass('bg-blue-600 bg-gray-200 dark:bg-gray-700');
                btn.find('span').toggleClass('translate-x-5 translate-x-0');
            }
        });
    });

    // Run AI
    $(document).on('click', '.run-ai-btn', function() {
        const id = $(this).data('module-id');
        $.post('/prl218/api/run_ai_analysis', {id: id}, function(res) {
            PRL.toast(res.status === 'ok' ? 'Анализ запущен' : res.message, 0, res.status === 'ok' ? 'ok' : 'err');
        });
    });

    // Status Modal
    let currentId = null;
    $(document).on('click', '.edit-status-btn', function() {
        currentId = $(this).data('module-id');
        $('#statusSelect').val($(this).data('current-status'));
        $('#statusModal').removeClass('hidden');
    });

    $('#saveStatusBtn').on('click', function() {
        const status = $('#statusSelect').val();
        $.post('/prl218/api/update_status', {id: currentId, status: status}, function(res) {
            if(res.status === 'ok') location.reload();
        });
    });

    // Attributes Modal
    $(document).on('click', '.edit-attributes-btn', function() {
        currentId = $(this).data('module-id');
        $('#displayNameInput').val($(this).data('display-name'));
        $('#descriptionInput').val($(this).data('description'));
        $('#versionInput').val($(this).data('version'));
        $('#iconInput').val($(this).data('icon'));
        $('#mainPageUriInput').val($(this).data('main-page-uri'));
        $('#attributesModal').removeClass('hidden');
    });

    $('#saveAttributesBtn').on('click', function() {
        const data = {
            id: currentId,
            display_name: $('#displayNameInput').val(),
            description: $('#descriptionInput').val(),
            version: $('#versionInput').val(),
            icon: $('#iconInput').val(),
            main_page_uri: $('#mainPageUriInput').val()
        };
        $.post('/prl218/api/update_attributes', data, function(res) {
            if(res.status === 'ok') location.reload();
        });
    });

    // Notes Modal
    $(document).on('click', '.edit-notes-btn', function() {
        currentId = $(this).data('module-id');
        $('#developerNotesInput').val($(this).data('notes'));
        $('#notesModal').removeClass('hidden');
    });

    $('#saveNotesBtn').on('click', function() {
        $.post('/prl218/api/update_developer_notes', {id: currentId, developer_notes: $('#developerNotesInput').val()}, function(res) {
            if(res.status === 'ok') location.reload();
        });
    });

    $('.fixed button[id^="cancel"]').on('click', function() {
        $(this).closest('.fixed').addClass('hidden');
    });
});
</script>
