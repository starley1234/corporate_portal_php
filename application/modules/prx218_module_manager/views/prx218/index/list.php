<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Платформа'], ['Реестр модулей']],
        'title'  => 'Реестр модулей',
        'pill'   => ['prx218 · платформа', 'fa-cubes'],
        'lead'   => 'Программные компоненты портала, их публикация, порядок в навигации и техническое состояние.',
    ]); ?>

    <div id="prx218-stats" class="mt-6 mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="prx-card"><div class="text-xs text-gray-500 dark:text-gray-400">Всего</div><div id="prx218-total" class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">—</div></div>
        <div class="prx-card"><div class="text-xs text-gray-500 dark:text-gray-400">Опубликовано</div><div id="prx218-active" class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">—</div></div>
        <div class="prx-card"><div class="text-xs text-gray-500 dark:text-gray-400">Стабильных</div><div id="prx218-stable" class="mt-1 text-2xl font-bold text-blue-600 dark:text-blue-400">—</div></div>
        <div class="prx-card"><div class="text-xs text-gray-500 dark:text-gray-400">Последнее сканирование</div><div id="prx218-scan" class="mt-1 truncate text-sm font-semibold text-gray-700 dark:text-gray-200">—</div></div>
    </div>

    <!-- Фильтры и Действия -->
    <div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-4">
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                    <i class="fas fa-search"></i>
                </span>
                <input type="text" id="moduleSearch" placeholder="Поиск модуля..."
                       class="prx-input w-full leading-5 pl-10">
            </div>

            <div class="flex items-center gap-2">
                <label for="visibilityFilter" class="prx-label">Видимость:</label>
                <select id="visibilityFilter" onchange="location = this.value;" class="prx-input prx-select w-full pr-10">
                    <option value="<?php echo URL::site(Request::current()->uri()) . URL::query(['visibility' => 'all']); ?>" <?php echo (!isset($filters['visibility']) || $filters['visibility'] == 'all') ? 'selected' : ''; ?>>Все</option>
                    <option value="<?php echo URL::site(Request::current()->uri()) . URL::query(['visibility' => 'published']); ?>" <?php echo (isset($filters['visibility']) && $filters['visibility'] == 'published') ? 'selected' : ''; ?>>Опубликованные</option>
                    <option value="<?php echo URL::site(Request::current()->uri()) . URL::query(['visibility' => 'hidden']); ?>" <?php echo (isset($filters['visibility']) && $filters['visibility'] == 'hidden') ? 'selected' : ''; ?>>Скрытые</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <label for="sortSelect" class="prx-label">Сортировка:</label>
                <select id="sortSelect" onchange="location = this.value;" class="prx-input prx-select w-full pr-10">
                    <option value="<?php echo URL::site(Request::current()->uri()) . URL::query(['sort' => 'sort_order', 'dir' => 'asc']); ?>" <?php echo ($filters['sort'] == 'sort_order') ? 'selected' : ''; ?>>По порядку (Drag-n-Drop)</option>
                    <option value="<?php echo URL::site(Request::current()->uri()) . URL::query(['sort' => 'display_name', 'dir' => 'asc']); ?>" <?php echo ($filters['sort'] == 'display_name' && $filters['dir'] == 'ASC') ? 'selected' : ''; ?>>По названию (А-Я)</option>
                    <option value="<?php echo URL::site(Request::current()->uri()) . URL::query(['sort' => 'display_name', 'dir' => 'desc']); ?>" <?php echo ($filters['sort'] == 'display_name' && $filters['dir'] == 'DESC') ? 'selected' : ''; ?>>По названию (Я-А)</option>
                    <option value="<?php echo URL::site(Request::current()->uri()) . URL::query(['sort' => 'updated_at', 'dir' => 'desc']); ?>" <?php echo ($filters['sort'] == 'updated_at') ? 'selected' : ''; ?>>Сначала новые</option>
                    <option value="<?php echo URL::site(Request::current()->uri()) . URL::query(['sort' => 'status', 'dir' => 'asc']); ?>" <?php echo ($filters['sort'] == 'status') ? 'selected' : ''; ?>>По статусу</option>
                </select>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500 dark:text-gray-400">Всего модулей: <?php echo $pagination->total_items; ?></span>
        </div>
    </div>

    <!-- Таблица реестра -->
    <section class="prx-panel">
        <div class="overflow-x-auto">
            <table class="prx-table min-w-full table-fixed">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th scope="col" class="w-10 px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <i class="fas fa-arrows-alt-v"></i>
                        </th>
                        <th scope="col" class="w-10 px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <i class="fas fa-home"></i>
                        </th>
                        <th scope="col" class="w-1/4 px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Модуль</th>
                        <th scope="col" class="w-32 px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Статус</th>
                        <th scope="col" class="w-24 px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Видимость</th>
                        <th scope="col" class="w-1/4 px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Примечание разработчика</th>
                        <th scope="col" class="w-40 px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Обновлен</th>
                        <th scope="col" class="w-48 relative px-6 py-3">
                            <span class="sr-only">Действия</span>
                        </th>
                    </tr>
                </thead>
                <tbody id="sortable-modules" class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if ($modules->count()): ?>
                        <?php foreach ($modules as $module): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors" data-module-id="<?php echo $module->id; ?>">
                                <td class="px-4 py-4 text-center <?php echo ($filters['sort'] == 'sort_order') ? 'cursor-move handle' : 'opacity-20 cursor-not-allowed'; ?> text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                                    <i class="fas fa-grip-vertical"></i>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <div class="main-page-link-container">
                                        <?php if (!empty($module->main_page_uri)): ?>
                                            <a href="<?php echo URL::site($module->main_page_uri); ?>" target="_blank" class="text-blue-600 transition-colors hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300" title="Перейти на главную страницу модуля">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                        <?php else: ?>
                                            <i class="fas fa-link text-gray-300 dark:text-gray-600" title="URI не указан"></i>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <a href="/prx218/index/details/<?php echo $module->id; ?>" class="flex-shrink-0 h-10 w-10 flex items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-800/50 transition-colors" title="Подробнее о модуле">
                                            <?php if (!empty($module->icon)): ?>
                                                <i class="fas <?php echo HTML::chars($module->icon); ?> text-lg"></i>
                                            <?php else: ?>
                                                <i class="fas fa-box-open text-lg"></i>
                                            <?php endif; ?>
                                        </a>
                                        <div class="ml-4 overflow-hidden">
                                            <div class="text-sm font-bold text-gray-900 dark:text-white editable-field truncate" data-field="display_name" data-module-id="<?php echo $module->id; ?>" title="<?php echo HTML::chars($module->display_name); ?>">
                                                <?php echo HTML::chars($module->display_name); ?>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                                /<?php echo HTML::chars($module->system_name); ?>
                                            </div>
                                            <?php if (!empty($module->description)): ?>
                                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 editable-field line-clamp-2" data-field="description" data-module-id="<?php echo $module->id; ?>">
                                                    <?php
                                                        $description_words = explode(' ', HTML::chars($module->description));
                                                        echo implode(' ', array_slice($description_words, 0, 12));
                                                        if (count($description_words) > 12) {
                                                            echo '...';
                                                        }
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                        $status_classes = [
                                            'alpha'    => 'prx-badge-run',
                                            'beta'     => 'prx-badge-info',
                                            'stable'   => 'prx-badge-ok',
                                            'archived' => 'prx-badge-wait',
                                        ];
                                        $current_status = $module->status ?? 'alpha';
                                    ?>
                                    <button type="button" class="edit-status-btn prx-badge <?php echo $status_classes[$current_status] ?? $status_classes['alpha']; ?>"
                                            data-module-id="<?php echo $module->id; ?>"
                                            data-current-status="<?php echo HTML::chars($current_status); ?>"
                                            title="Изменить статус">
                                        <span class="prx-dot"></span>
                                        <span class="status-text"><?php echo ucfirst(HTML::chars($current_status)); ?></span>
                                    </button>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <button class="toggle-published relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none <?php echo $module->is_published ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-700'; ?>"
                                            data-module-id="<?php echo $module->id; ?>"
                                            data-is-published="<?php echo $module->is_published ? '1' : '0'; ?>">
                                        <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow dark:bg-white ring-0 transition duration-200 ease-in-out <?php echo $module->is_published ? 'translate-x-5' : 'translate-x-0'; ?>"></span>
                                    </button>
                                </td>
                                <td class="px-6 py-4 text-sm text-blue-500 dark:text-blue-400 editable-field" data-field="developer_notes" data-module-id="<?php echo $module->id; ?>">
                                    <div class="line-clamp-3">
                                        <?php if (!empty($module->developer_notes)): ?>
                                            <i class="fas fa-sticky-note mr-1"></i> <?php echo HTML::chars($module->developer_notes); ?>
                                        <?php else: ?>
                                            <span class="text-gray-400 dark:text-gray-500">Нет примечаний</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo date('d.m.Y H:i', strtotime($module->updated_at)); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" data-prx-confirm="LLM-анализ модуля займёт некоторое время." data-prx-title="Запустить анализ?" data-prx-ok="Запустить" data-prx-tone="process" class="prx-btn-icon run-ai-btn" title="Запустить LLM анализ" data-module-id="<?php echo $module->id; ?>">
                                            <i class="fas fa-robot"></i>
                                        </button>
                                        <button class="prx-btn-icon edit-notes-btn" title="Рия" data-module-id="<?php echo $module->id; ?>" data-notes="<?php echo HTML::chars($module->developer_notes); ?>">
                                            <i class="fas fa-sticky-note"></i>
                                        </button>
                                        <button class="prx-btn-icon edit-attributes-btn" title="Редактировать атрибуты"
                                            data-module-id="<?php echo $module->id; ?>"
                                            data-display-name="<?php echo HTML::chars($module->display_name); ?>"
                                            data-description="<?php echo HTML::chars($module->description); ?>"
                                            data-version="<?php echo HTML::chars($module->version); ?>"
                                            data-icon="<?php echo HTML::chars($module->icon); ?>"
                                            data-main-page-uri="<?php echo HTML::chars($module->main_page_uri); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="/prx218/index/details/<?php echo $module->id; ?>" class="prx-btn-icon" title="Документация">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="px-6 py-4">
                                <div class="prx-empty">
                                    <span class="prx-empty-icon"><i class="fas fa-cubes"></i></span>
                                    <p class="prx-empty-text">Модули не найдены. Запустите сканирование системы.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($pagination->total_pages > 1): ?>
        <div class="mt-6">
            <?php echo $pagination->render(); ?>
        </div>
    <?php endif; ?>
</div>

<!-- Модальное окно для редактирования примечаний -->
<div id="notesModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="prx-modal-box relative top-20 mx-auto">
        <div class="mt-3 text-center">
            <h3 class="prx-h3">Редактировать примечания для <span id="notesModuleName" class="font-bold text-blue-600 dark:text-blue-400"></span></h3>
            <div class="mt-2 px-7 py-3">
                <textarea id="developerNotesInput" class="prx-textarea w-full" rows="5" placeholder="Введите примечания разработчика..."></textarea>
            </div>
            <div class="items-center px-4 py-3">
                <button id="saveNotesBtn" class="prx-btn-cta w-full">
                    Сохранить
                </button>
                <button id="cancelNotesBtn" class="prx-btn-secondary mt-2 w-full">
                    Отмена
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для редактирования атрибутов -->
<div id="attributesModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="prx-modal-box relative top-20 mx-auto">
        <div class="mt-3 text-center">
            <h3 class="prx-h3">Редактировать атрибуты модуля <span id="attributesModuleName" class="font-bold text-blue-600 dark:text-blue-400"></span></h3>
            <div class="mt-2 px-7 py-3 text-left">
                <div class="mb-4">
                    <label for="displayNameInput" class="prx-label">Название</label>
                    <input type="text" id="displayNameInput" class="prx-input mt-1 w-full">
                </div>
                <div class="mb-4">
                    <label for="descriptionInput" class="prx-label">Описание</label>
                    <textarea id="descriptionInput" class="prx-textarea mt-1 w-full" rows="3"></textarea>
                </div>
                <div class="mb-4">
                    <label for="versionInput" class="prx-label">Версия</label>
                    <input type="text" id="versionInput" class="prx-input mt-1 w-full">
                </div>
                <div class="mb-4">
                    <label for="iconInput" class="prx-label">Иконка (Font Awesome класс)</label>
                    <input type="text" id="iconInput" class="prx-input mt-1 w-full" placeholder="Например: fas fa-cog">
                </div>
                <div class="mb-4">
                    <label for="mainPageUriInput" class="prx-label">Main Page URI</label>
                    <input type="text" id="mainPageUriInput" class="prx-input mt-1 w-full" placeholder="Например: prx218">
                </div>
            </div>
            <div class="items-center px-4 py-3">
                <button id="saveAttributesBtn" class="prx-btn-cta w-full">
                    Сохранить
                </button>
                <button id="cancelAttributesBtn" class="prx-btn-secondary mt-2 w-full">
                    Отмена
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для изменения статуса -->
<div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="prx-modal-box relative top-20 mx-auto max-w-sm">
        <div class="mt-3 text-center">
            <h3 class="prx-h3">Изменить статус модуля <span id="statusModuleName" class="font-bold text-blue-600 dark:text-blue-400"></span></h3>
            <div class="mt-2 px-7 py-3 text-left">
                <label for="statusSelect" class="prx-label">Выберите статус</label>
                <select id="statusSelect" class="prx-input prx-select mt-1 w-full">
                    <option value="alpha">Alpha</option>
                    <option value="beta">Beta</option>
                    <option value="stable">Stable</option>
                    <option value="archived">Archived</option>
                </select>
            </div>
            <div class="items-center px-4 py-3">
                <button id="saveStatusBtn" class="prx-btn-cta w-full">
                    Сохранить
                </button>
                <button id="cancelStatusBtn" class="prx-btn-secondary mt-2 w-full">
                    Отмена
                </button>
            </div>
        </div>
    </div>
</div>

<script src="/assets/vendor/sortable/Sortable.min.js"></script>
<script>
$(document).ready(function() {
    // Инициализация Drag and Drop (только если выбрана сортировка по умолчанию)
    const el = document.getElementById('sortable-modules');
    const currentSort = '<?php echo $filters['sort']; ?>';

    if (el && currentSort === 'sort_order') {
        Sortable.create(el, {
            handle: '.handle',
            animation: 150,
            ghostClass: 'bg-blue-50',
            onEnd: function (evt) {
                const rows = el.querySelectorAll('tr');
                rows.forEach((row, index) => {
                    const moduleId = row.getAttribute('data-module-id');
                    if (moduleId) {
                        $.ajax({
                            url: '/prx218/api/update_sort_order',
                            method: 'POST',
                            data: {
                                id: moduleId,
                                sort_order: index + 1
                            }
                        });
                    }
                });
            }
        });
    }

    // Простой поиск по таблице
    $("#moduleSearch").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    // Переключение публикации модуля
    $(document).on('click', '.toggle-published', function() {
        const button = $(this);
        const moduleId = button.data('module-id');
        const isPublished = button.data('is-published') === 1 ? 0 : 1; // Инвертируем статус

        $.ajax({
            url: '/prx218/api/toggle_published',
            method: 'POST',
            data: {
                id: moduleId,
                is_published: isPublished
            },
            success: function(response) {
                if (response.status === 'ok') {
                    button.data('is-published', response.is_published);
                    if (response.is_published) {
                        button.removeClass('bg-gray-200 dark:bg-gray-700').addClass('bg-blue-600');
                        button.find('span').removeClass('translate-x-0').addClass('translate-x-5');
                    } else {
                        button.removeClass('bg-blue-600').addClass('bg-gray-200 dark:bg-gray-700');
                        button.find('span').removeClass('translate-x-5').addClass('translate-x-0');
                    }
                } else {
                    PRX.toast('Ошибка: ' + response.message, 0, 'err');
                }
            },
            error: function(xhr) {
                PRX.toast('Произошла ошибка при обновлении статуса публикации.', 0, 'err');
                console.error(xhr.responseText);
            }
        });
    });

    // Запуск LLM анализа
    $(document).on('click', '.run-ai-btn', function() {
        const button = $(this);
        const moduleId = button.data('module-id');


        button.find('i').addClass('fa-spin');
        button.prop('disabled', true);

        $.ajax({
            url: '/prx218/api/run_ai_analysis',
            method: 'POST',
            data: { id: moduleId },
            success: function(response) {
                button.find('i').removeClass('fa-spin');
                button.prop('disabled', false);
                if (response.status === 'ok') {
                    PRX.toast('Анализ запущен, номер: ' + response.analysis_id, 0, 'ok');
                } else {
                    PRX.toast('Ошибка: ' + response.message, 0, 'err');
                }
            },
            error: function(xhr) {
                button.find('i').removeClass('fa-spin');
                button.prop('disabled', false);
                PRX.toast('Произошла ошибка при запуске анализа.', 0, 'err');
            }
        });
    });

    // --- Функционал для примечаний разработчика ---
    let currentModuleIdForNotes = null;

    $(document).on('click', '.edit-notes-btn', function() {
        currentModuleIdForNotes = $(this).data('module-id');
        const currentNotes = $(this).data('notes');
        const moduleName = $(`tr[data-module-id="${currentModuleIdForNotes}"]`).find('.editable-field[data-field="display_name"]').text().trim();

        $('#notesModuleName').text(moduleName);
        $('#developerNotesInput').val(currentNotes);
        $('#notesModal').removeClass('hidden');
    });

    $('#cancelNotesBtn').on('click', function() {
        $('#notesModal').addClass('hidden');
        currentModuleIdForNotes = null;
    });

    $('#saveNotesBtn').on('click', function() {
        if (!currentModuleIdForNotes) return;

        const newNotes = $('#developerNotesInput').val();

        $.ajax({
            url: '/prx218/api/update_developer_notes',
            method: 'POST',
            data: {
                id: currentModuleIdForNotes,
                developer_notes: newNotes
            },
            success: function(response) {
                if (response.status === 'ok') {
                    const moduleRow = $(`tr[data-module-id="${currentModuleIdForNotes}"]`);
                    let notesElement = moduleRow.find('.editable-field[data-field="developer_notes"]');

                    if (notesElement.length) {
                        if (response.developer_notes) {
                            notesElement.html(`<div class="line-clamp-3"><i class="fas fa-sticky-note mr-1"></i> ${response.developer_notes}</div>`);
                            notesElement.removeClass('text-gray-400 dark:text-gray-500').addClass('text-blue-500 dark:text-blue-400');
                        } else {
                            notesElement.html(`<div class="line-clamp-3"><span class="text-gray-400 dark:text-gray-500">Нет примечаний</span></div>`);
                            notesElement.removeClass('text-blue-500 dark:text-blue-400').addClass('text-gray-400 dark:text-gray-500');
                        }
                    }

                    moduleRow.find('.edit-notes-btn').data('notes', response.developer_notes);
                    $('#notesModal').addClass('hidden');
                } else {
                    PRX.toast('Ошибка: ' + response.message, 0, 'err');
                }
            },
            error: function(xhr) {
                PRX.toast('Произошла ошибка при сохранении примечаний.', 0, 'err');
            }
        });
    });

    // --- Функционал для редактирования атрибутов модуля ---
    let currentModuleIdForAttributes = null;

    $(document).on('click', '.edit-attributes-btn', function() {
        currentModuleIdForAttributes = $(this).data('module-id');
        const moduleName = $(`tr[data-module-id="${currentModuleIdForAttributes}"]`).find('.editable-field[data-field="display_name"]').text().trim();

        $('#attributesModuleName').text(moduleName);
        $('#displayNameInput').val($(this).data('display-name'));
        $('#descriptionInput').val($(this).data('description'));
        $('#versionInput').val($(this).data('version'));
        $('#iconInput').val($(this).data('icon'));
        $('#mainPageUriInput').val($(this).data('main-page-uri'));
        $('#attributesModal').removeClass('hidden');
    });

    $('#cancelAttributesBtn').on('click', function() {
        $('#attributesModal').addClass('hidden');
        currentModuleIdForAttributes = null;
    });

    $('#saveAttributesBtn').on('click', function() {
        if (!currentModuleIdForAttributes) return;

        const newDisplayName = $('#displayNameInput').val();
        const newDescription = $('#descriptionInput').val();
        const newVersion = $('#versionInput').val();
        const newIcon = $('#iconInput').val();
        const newMainPageUri = $('#mainPageUriInput').val();

        $.ajax({
            url: '/prx218/api/update_attributes',
            method: 'POST',
            data: {
                id: currentModuleIdForAttributes,
                display_name: newDisplayName,
                description: newDescription,
                version: newVersion,
                icon: newIcon,
                main_page_uri: newMainPageUri
            },
            success: function(response) {
                if (response.status === 'ok') {
                    const moduleRow = $(`tr[data-module-id="${currentModuleIdForAttributes}"]`);
                    const btn = moduleRow.find('.edit-attributes-btn');

                    if (response.updated_fields.display_name !== undefined) {
                        moduleRow.find('.editable-field[data-field="display_name"]').text(response.updated_fields.display_name).attr('title', response.updated_fields.display_name);
                        btn.data('display-name', response.updated_fields.display_name);
                    }
                    if (response.updated_fields.description !== undefined) {
                        let descriptionElement = moduleRow.find('.editable-field[data-field="description"]');
                        const truncatedDescription = response.updated_fields.description.split(' ').slice(0, 12).join(' ') + (response.updated_fields.description.split(' ').length > 12 ? '...' : '');
                        if (descriptionElement.length) descriptionElement.text(truncatedDescription);
                        btn.data('description', response.updated_fields.description);
                    }
                    if (response.updated_fields.version !== undefined) {
                        moduleRow.find('.editable-field[data-field="version"]').html(`v${response.updated_fields.version}`);
                        btn.data('version', response.updated_fields.version);
                    }
                    if (response.updated_fields.icon !== undefined) {
                        moduleRow.find('.flex-shrink-0.h-10.w-10 i').attr('class', `fas ${response.updated_fields.icon} text-lg`);
                        btn.data('icon', response.updated_fields.icon);
                    }
                    if (response.updated_fields.main_page_uri !== undefined) {
                        const linkContainer = moduleRow.find('.main-page-link-container');
                        if (response.updated_fields.main_page_uri) {
                            linkContainer.html(`<a href="/${response.updated_fields.main_page_uri}" target="_blank" class="text-blue-600 transition-colors hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300" title="Перейти на главную страницу модуля"><i class="fas fa-external-link-alt"></i></a>`);
                        } else {
                            linkContainer.html(`<i class="fas fa-link text-gray-300 dark:text-gray-600" title="URI не указан"></i>`);
                        }
                        btn.data('main-page-uri', response.updated_fields.main_page_uri);
                    }

                    $('#attributesModal').addClass('hidden');
                } else {
                    PRX.toast('Ошибка: ' + response.message, 0, 'err');
                }
            },
            error: function(xhr) {
                PRX.toast('Произошла ошибка при сохранении атрибутов.', 0, 'err');
            }
        });
    });

    // --- Функционал для изменения статуса модуля ---
    let currentModuleIdForStatus = null;
    const statusClasses = {
        'alpha': 'prx-badge-run',
        'beta': 'prx-badge-info',
        'stable': 'prx-badge-ok',
        'archived': 'prx-badge-wait',
    };

    $(document).on('click', '.edit-status-btn', function() {
        currentModuleIdForStatus = $(this).data('module-id');
        const currentStatus = $(this).data('current-status');
        const moduleName = $(`tr[data-module-id="${currentModuleIdForStatus}"]`).find('.editable-field[data-field="display_name"]').text().trim();

        $('#statusModuleName').text(moduleName);
        $('#statusSelect').val(currentStatus);
        $('#statusModal').removeClass('hidden');
    });

    $('#cancelStatusBtn').on('click', function() {
        $('#statusModal').addClass('hidden');
        currentModuleIdForStatus = null;
    });

    $('#saveStatusBtn').on('click', function() {
        if (!currentModuleIdForStatus) return;

        const newStatus = $('#statusSelect').val();

        $.ajax({
            url: '/prx218/api/update_status',
            method: 'POST',
            data: {
                id: currentModuleIdForStatus,
                status: newStatus
            },
            success: function(response) {
                if (response.status === 'ok') {
                    const moduleRow = $(`tr[data-module-id="${currentModuleIdForStatus}"]`);
                    const statusButton = moduleRow.find('.edit-status-btn');
                    for (const key in statusClasses) { statusButton.removeClass(statusClasses[key]); }
                    statusButton.addClass(statusClasses[newStatus]);
                    statusButton.find('.status-text').text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
                    statusButton.data('current-status', newStatus);
                    $('#statusModal').addClass('hidden');
                } else {
                    PRX.toast('Ошибка: ' + response.message, 0, 'err');
                }
            },
            error: function(xhr) {
                PRX.toast('Произошла ошибка при сохранении статуса.', 0, 'err');
            }
        });
    });
});
    // Статистика реестра — отдельным API, чтобы список оставался быстрым.
    $.getJSON('/prx218/api/get_stats', function (response) {
        if (response.status !== 'ok' || !response.stats) return;
        var st = response.stats;
        $('#prx218-total').text(st.total ?? 0);
        $('#prx218-active').text(st.active ?? 0);
        $('#prx218-stable').text(st.stable ?? 0);
        $('#prx218-scan').text(st.last_scan || 'не запускалось');
    });

</script>�пускалось');
    });

</script>
