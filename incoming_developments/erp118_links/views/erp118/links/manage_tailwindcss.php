<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8"> 

    <div class="n2j-card p-0 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex items-center justify-between">
            <h2 class="n2j-h3">Ваши ссылки</h2>
            <button type="button" id="add-link-btn"
                    class="n2j-btn-cta">
                <i class="fas fa-plus text-xs"></i> Новая ссылка
            </button>
        </div>
        
        <ul class="divide-y divide-gray-200 dark:divide-gray-700" id="sortable-links">
            <?php if (count($links) > 0): ?>
                <?php foreach ($links as $link): ?>
                    <li class="flex items-center p-4 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors link-item-draggable group"
                        data-id="<?php echo $link->id; ?>"
                        data-group="<?php echo HTML::chars($link->group_name); ?>"
                        data-is-menu-item="<?php echo $link->is_menu_item; ?>">
                        
                        <div class="handle cursor-grab active:cursor-grabbing p-2 mr-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                            <i class="fas fa-grip-vertical"></i>
                        </div>

                        <div class="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 mr-4">
                            <i class="fa-lg <?php echo HTML::chars($link->icon_class); ?>" style="color: <?php echo HTML::chars($link->color); ?>;"></i>
                        </div>

                        <div class="flex-grow min-w-0 mr-4">
                            <div class="flex items-center flex-wrap gap-2">
                                <a href="<?php echo HTML::chars($link->url); ?>" target="_blank" class="text-sm font-medium hover:underline truncate" style="color: <?php echo HTML::chars($link->color); ?>;">
                                    <span class="link-title"><?php echo HTML::chars($link->title); ?></span>
                                </a>
                                <?php if (!empty($link->group_name)): ?>
                                    <span class="n2j-badge n2j-badge-info link-group-display">
                                        <?php echo HTML::chars($link->group_name); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($link->is_menu_item): ?>
                                    <span class="n2j-badge n2j-badge-info link-menu-badge">
                                        <i class="fas fa-bars mr-1 text-[10px]"></i> Меню
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($link->description)): ?>
                                <p class="n2j-hint truncate link-description-display">
                                    <?php echo HTML::chars($link->description); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center space-x-2 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button class="n2j-btn-icon edit-link-btn"
                                    data-id="<?php echo $link->id; ?>"
                                    data-title="<?php echo HTML::chars($link->title); ?>"
                                    data-description="<?php echo HTML::chars($link->description); ?>"
                                    data-color="<?php echo HTML::chars($link->color); ?>"
                                    data-icon-class="<?php echo HTML::chars($link->icon_class); ?>"
                                    data-group-name="<?php echo HTML::chars($link->group_name); ?>"
                                    data-is-menu-item="<?php echo $link->is_menu_item; ?>">
                                <i class="fas fa-pencil-alt"></i>
                            </button>
                            <button type="button" data-n2j-arm="Удалить?" class="n2j-btn-icon-danger delete-link-btn" title="Удалить ссылку" aria-label="Удалить ссылку" data-id="<?php echo $link->id; ?>">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="p-8 text-center text-gray-500 dark:text-gray-400 empty-list-message">
                    <i class="fas fa-link-slash block text-3xl mb-3 opacity-20"></i>
                    У вас пока нет сохраненных ссылок.
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<!-- Tailwind Modal -->
<div id="editLinkModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-xs" data-erp118-overlay aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        
        <div class="n2j-modal-box p-0 relative inline-block align-bottom text-left overflow-hidden sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                <h3 class="n2j-h3">Редактировать ссылку</h3>
                <button type="button" class="n2j-btn-icon close-modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="editLinkForm" class="p-6 space-y-4">
                <input type="hidden" id="edit-link-id">

                <div id="edit-link-url-wrap" class="hidden">
                    <label class="n2j-label mb-1">URL <span class="text-xs text-gray-400">(можно относительный — /vat)</span></label>
                    <input type="text" id="edit-link-url" placeholder="https://… или /vat" class="n2j-input w-full">
                </div>

                <div>
                    <label class="n2j-label mb-1">Заголовок</label>
                    <input type="text" id="edit-link-title" required class="n2j-input w-full">
                </div>

                <div>
                    <label class="n2j-label mb-1">Описание</label>
                    <textarea id="edit-link-description" rows="2" class="n2j-textarea w-full"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="n2j-label mb-1">Цвет</label>
                        <input type="color" id="edit-link-color" class="h-10 w-full p-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700">
                    </div>
                    <div>
                        <label class="n2j-label mb-1">Иконка</label>
                        <div class="flex items-center gap-2">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700">
                                <i id="edit-link-icon-preview" class="fas fa-link text-gray-500 dark:text-gray-300"></i>
                            </span>
                            <input type="text" id="edit-link-icon-class" class="n2j-input w-full" placeholder="fas fa-link">
                            <button type="button" id="icon-picker-toggle" class="n2j-btn-secondary shrink-0" title="Выбрать иконку">
                                <i class="fas fa-icons"></i>
                            </button>
                        </div>
                        <div id="icon-picker" class="mt-2 hidden grid-cols-8 gap-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/60 p-2">
                            <?php foreach ($icon_suggestions as $icon_class): ?>
                                <button type="button" class="n2j-btn-icon icon-pick"
                                        data-icon="<?php echo HTML::chars($icon_class); ?>" title="<?php echo HTML::chars($icon_class); ?>">
                                    <i class="<?php echo HTML::chars($icon_class); ?>"></i>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="n2j-label mb-1">Группа</label>
                    <input type="text" id="edit-link-group" list="group-suggestions" class="n2j-input w-full">
                    <datalist id="group-suggestions">
                        <?php foreach ($groups as $group_name): ?>
                            <option value="<?php echo HTML::chars($group_name); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="flex items-center">
                    <input type="checkbox" id="edit-link-is-menu" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600">
                    <label for="edit-link-is-menu" class="n2j-label ml-2">Отображать в боковом меню</label>
                </div>
            </form>

            <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-200 dark:border-gray-700 flex justify-end space-x-3">
                <button type="button" class="n2j-btn-secondary close-modal">Отмена</button>
                <button type="button" id="save-edited-link-btn" class="n2j-btn-cta">Сохранить</button>
            </div>
        </div>
    </div>
</div>

<script src="/assets/vendor/sortable/Sortable.min.js"></script>
<script>
$(document).ready(function() {
    // Сообщения — общий тост (N2J.toast): текстом, без вставки HTML.
    function showAlert(message, type) {
        N2J.toast(message, 0, type === 'success' ? 'ok' : 'err');
    }

    function updateGroupHeaders() {
        $('.group-header').remove();
        let currentGroup = null;
        const $list = $('#sortable-links');
        let linksExist = false;

        $list.find('li.link-item-draggable').each(function() {
            linksExist = true;
            const linkGroup = $(this).data('group');
            const displayGroup = (!linkGroup || linkGroup === 'null') ? 'Без группы' : linkGroup;

            if (displayGroup !== currentGroup) {
                currentGroup = displayGroup;
                $(`<li class="group-header bg-gray-50 dark:bg-gray-900/50 px-6 py-2 text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 border-y border-gray-200 dark:border-gray-700" data-group-name="${linkGroup}">${currentGroup}</li>`).insertBefore($(this));
            }
        });

        if (!linksExist && $list.find('.empty-list-message').length === 0) {
            $list.html('<li class="p-8 text-center text-gray-500 dark:text-gray-400 empty-list-message">У вас пока нет сохраненных ссылок.</li>');
        } else if (linksExist) {
            $list.find('.empty-list-message').remove();
        }
    }

    const sortableEl = document.getElementById('sortable-links');
    if (sortableEl) {
        Sortable.create(sortableEl, {
            animation: 150,
            handle: '.handle',
            filter: '.group-header, .empty-list-message',
            onEnd: function() {
                const order = [];
                $('#sortable-links li.link-item-draggable').each(function(index) {
                    order.push({ id: $(this).data('id'), order: index });
                });

                $.ajax({
                    url: '<?php echo URL::site('erp118_links/reorder'); ?>',
                    method: 'POST',
                    data: { order: order },
                    dataType: 'json',
                    success: function(res) {
                        if (res.status === 'success') {
                            showAlert('Порядок обновлен', 'success');
                            updateGroupHeaders();
                        }
                    }
                });
            }
        });
    }

    const $modal = $('#editLinkModal');
    const closeModal = () => {
        $modal.addClass('hidden');
        $('body').removeClass('overflow-hidden');
    };

    // ---- Пикер иконок (иконки nj-модулей + популярные FA) ----
    const $iconInput = $('#edit-link-icon-class');
    const $iconPreview = $('#edit-link-icon-preview');
    const syncIconPreview = () => {
        const cls = ($iconInput.val() || 'fas fa-link').trim();
        $iconPreview.attr('class', cls + ' text-gray-500 dark:text-gray-300');
    };
    $iconInput.on('input', syncIconPreview);
    $('#icon-picker-toggle').on('click', function () {
        $('#icon-picker').toggleClass('hidden').toggleClass('grid');
    });
    $(document).on('click', '.icon-pick', function () {
        $iconInput.val($(this).data('icon'));
        syncIconPreview();
        $('#icon-picker').addClass('hidden').removeClass('grid');
    });

    // ---- Создание ссылки (кнопка «Новая ссылка») ----
    let createMode = false;
    const setModalMode = (mode) => {
        createMode = (mode === 'create');
        $('#editLinkModal h3').text(createMode ? 'Новая ссылка' : 'Редактировать ссылку');
        $('#save-edited-link-btn').text(createMode ? 'Добавить' : 'Сохранить');
    };
    $('#add-link-btn').on('click', function () {
        setModalMode('create');
        $('#edit-link-id').val('');
        $('#edit-link-title').val('');
        $('#edit-link-description').val('');
        $('#edit-link-color').val('#3b82f6');
        $iconInput.val('');
        syncIconPreview();
        $('#edit-link-group').val('');
        $('#edit-link-is-menu').prop('checked', false);
        $('#edit-link-url-wrap').removeClass('hidden');
        $modal.removeClass('hidden');
        $('body').addClass('overflow-hidden');
        $('#edit-link-url').trigger('focus');
    });

    $(document).on('click', '.edit-link-btn', function() {
        setModalMode('edit');
        $('#edit-link-url-wrap').addClass('hidden');
        const d = $(this).data();
        $('#edit-link-id').val(d.id);
        $('#edit-link-title').val(d.title);
        $('#edit-link-description').val(d.description);
        $('#edit-link-color').val(d.color);
        $('#edit-link-icon-class').val(d.iconClass);
        $('#edit-link-group').val((d.groupName === 'null' || !d.groupName) ? '' : d.groupName);
        $('#edit-link-is-menu').prop('checked', parseInt(d.isMenuItem) === 1);
        
        $modal.removeClass('hidden');
        $('body').addClass('overflow-hidden');
    });

    $('.close-modal, [data-erp118-overlay]').on('click', closeModal);

    $('#save-edited-link-btn').on('click', function() {
        const data = {
            id: $('#edit-link-id').val(),
            title: $('#edit-link-title').val(),
            url: $('#edit-link-url') ? $('#edit-link-url').val() : '',
            description: $('#edit-link-description').val(),
            color: $('#edit-link-color').val(),
            icon_class: $('#edit-link-icon-class').val(),
            group_name: $('#edit-link-group').val(),
            is_menu_item: $('#edit-link-is-menu').is(':checked') ? 1 : 0
        };

        if (createMode) {
            if (!data.url || !data.url.trim()) {
                showAlert('Укажите ссылку (URL)', 'danger');
                $('#edit-link-url') && $('#edit-link-url').focus();
                return;
            }
            $.ajax({
                url: '<?php echo URL::site('erp118_links/create'); ?>',
                method: 'POST',
                data: data,
                dataType: 'json',
                success: function(res) {
                    if (res.status === 'success') {
                        showAlert('Ссылка добавлена', 'success');
                        closeModal();
                        setTimeout(() => location.reload(), 400);
                    } else {
                        showAlert(res.message || 'Ошибка', 'danger');
                    }
                },
                error: function(xhr) {
                    const res = xhr.responseJSON;
                    showAlert(res && res.errors ? Object.values(res.errors).join(', ') : 'Не удалось сохранить', 'danger');
                }
            });
            return;
        }

        $.ajax({
            url: '<?php echo URL::site('erp118_links/update'); ?>',
            method: 'POST',
            data: data,
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {
                    showAlert('Обновлено', 'success');
                    const $li = $(`li[data-id="${data.id}"]`);
                    $li.find('.link-title').text(data.title);
                    $li.find('a').css('color', data.color);
                    $li.find('.fa-lg').removeClass().addClass(`fa-lg ${data.icon_class}`).css('color', data.color);
                    $li.data('group', data.group_name);
                    $li.data('is-menu-item', data.is_menu_item);
                    
                    // Update description
                    let $desc = $li.find('.link-description-display');
                    if (data.description) {
                        if ($desc.length) $desc.text(data.description);
                        else $li.find('.min-w-0').append(`<p class="text-xs text-gray-500 dark:text-gray-400 mt-1 truncate link-description-display">${data.description}</p>`);
                    } else $desc.remove();

                    // Update group badge
                    let $badge = $li.find('.link-group-display');
                    if (data.group_name) {
                        if ($badge.length) $badge.text(data.group_name);
                        else $li.find('.link-title').parent().append(`<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300 link-group-display">${data.group_name}</span>`);
                    } else $badge.remove();

                    // Update menu badge
                    let $menuBadge = $li.find('.link-menu-badge');
                    if (data.is_menu_item) {
                        if ($menuBadge.length === 0) {
                            $li.find('.link-title').parent().append(`<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300 link-menu-badge"><i class="fas fa-bars mr-1 text-[10px]"></i> Меню</span>`);
                        }
                    } else {
                        $menuBadge.remove();
                    }

                    $li.find('.edit-link-btn').data({
                        title: data.title,
                        description: data.description,
                        color: data.color,
                        iconClass: data.icon_class,
                        groupName: data.group_name,
                        isMenuItem: data.is_menu_item
                    });

                    closeModal();
                    updateGroupHeaders();
                }
            }
        });
    });

    // Удаление — вторым нажатием (data-n2j-arm, app.js): первое взводит кнопку
    // и до этого обработчика не доходит.
    $(document).on('click', '.delete-link-btn', function() {
        const id = $(this).data('id');
        const $li = $(this).closest('li');
        $.ajax({
            url: '<?php echo URL::site('erp118_links/delete'); ?>',
            method: 'POST',
            data: { id: id },
            success: function() {
                $li.remove();
                updateGroupHeaders();
                showAlert('Удалено', 'success');
            },
            error: function() { showAlert('Не удалось удалить ссылку', 'danger'); }
        });
    });

    updateGroupHeaders();
});
</script>