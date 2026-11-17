<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <h1 class="prx-h1 mb-6"><?php echo __('Управление зависимостями модулей'); ?></h1>

    <div class="prx-card mb-8">
        <h3 class="prx-h3 mb-4"><?php echo __('Добавить новую зависимость'); ?></h3>
        <form id="addDependencyForm" method="POST" action="<?php echo URL::site('prx218/index/add_dependency'); ?>">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="module_id" class="prx-label mb-1">
                        <?php echo __('Основной модуль (зависимый)'); ?>
                    </label>
                    <select id="module_id" name="module_id" required
                            class="prx-input prx-select select2-search w-full">
                        <option value=""><?php echo __('Выберите модуль'); ?></option>
                        <?php foreach ($all_modules as $module): ?>
                            <option value="<?php echo $module->id; ?>" <?php echo (isset($_COOKIE['last_selected_module_id']) && $_COOKIE['last_selected_module_id'] == $module->id) ? 'selected' : ''; ?>>
                                <?php echo HTML::chars($module->display_name); ?> (<?php echo HTML::chars($module->system_name); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="required_module_id" class="prx-label mb-1">
                        <?php echo __('Требуемый модуль (зависимость)'); ?>
                    </label>
                    <select id="required_module_id" name="required_module_id" required
                            class="prx-input prx-select select2-search w-full">
                        <option value=""><?php echo __('Выберите модуль'); ?></option>
                        <?php foreach ($all_modules as $module): ?>
                            <option value="<?php echo $module->id; ?>"><?php echo HTML::chars($module->display_name); ?> (<?php echo HTML::chars($module->system_name); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="min_version" class="prx-label mb-1">
                        <?php echo __('Минимальная версия (опционально)'); ?>
                    </label>
                    <input type="text" id="min_version" name="min_version"
                           class="prx-input w-full"
                           placeholder="1.0.0">
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <button type="submit"
                        class="prx-btn-cta">
                    <i class="fas fa-plus mr-2"></i> <?php echo __('Добавить зависимость'); ?>
                </button>
            </div>
        </form>
    </div>

    <div class="prx-card">
        <h3 class="prx-h3 mb-4"><?php echo __('Существующие зависимости'); ?></h3>
        <?php if ($dependencies->count()): ?>
            <div class="overflow-x-auto -mx-6 sm:-mx-0">
                <table class="prx-table min-w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                <?php echo __('Основной модуль'); ?>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                <?php echo __('Зависит от'); ?>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                <?php echo __('Мин. версия'); ?>
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                <?php echo __('Действия'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($dependencies as $dependency): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-150">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                    <?php echo HTML::chars($dependency->module->display_name); ?> (<?php echo HTML::chars($dependency->module->system_name); ?>)
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo HTML::chars($dependency->required->display_name); ?> (<?php echo HTML::chars($dependency->required->system_name); ?>)
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo HTML::chars($dependency->min_version ?: __('Любая')); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <button data-prx-arm="Удалить?" type="button" data-dependency-id="<?php echo $dependency->id; ?>"
                                            class="delete-dependency-btn prx-btn-icon-danger" title="<?php echo __('Удалить'); ?>" aria-label="<?php echo __('Удалить'); ?>">
                                        <i class="fas fa-trash-can"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-gray-500 dark:text-gray-400 p-4 bg-gray-50 dark:bg-gray-700 rounded-md border border-gray-200 dark:border-gray-600">
                <?php echo __('Зависимости пока не добавлены. Используйте форму выше, чтобы добавить первую зависимость.'); ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<link href="/assets/vendor/select2/css/select2.min.css" rel="stylesheet" />
<script src="/assets/vendor/select2/js/select2.min.js"></script>

<?php /* design-audit: allow style — тёмная тема select2: разметку рисует библиотека */ ?>
<style>
    .select2-container--default .select2-selection--single {
        background-color: transparent !important;
        border-color: rgb(209 213 219) !important;
        height: 38px !important;
        display: flex !important;
        align-items: center !important;
    }
    .dark .select2-container--default .select2-selection--single {
        border-color: rgb(75 85 99) !important;
        color: white !important;
    }
    .dark .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: white !important;
    }
    .dark .select2-dropdown {
        background-color: rgb(31 41 55) !important;
        border-color: rgb(75 85 99) !important;
        color: white !important;
    }
    .dark .select2-search__field {
        background-color: rgb(55 65 81) !important;
        color: white !important;
        border-color: rgb(75 85 99) !important;
    }
    .dark .select2-results__option--highlighted[aria-selected] {
        background-color: rgb(37 99 235) !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
    }
    .select2-results__options {
        max-height: 400px !important;
    }
</style>

<script>
$(document).ready(function() {
    if ($.fn.select2) {
        $('.select2-search').select2({
            width: '100%',
            placeholder: "<?php echo __('Поиск модуля...'); ?>",
            allowClear: true,
            language: {
                noResults: function() {
                    return "<?php echo __('Ничего не найдено'); ?>";
                }
            }
        });
    }

    $('#module_id').on('change', function() {
        var val = $(this).val();
        document.cookie = "last_selected_module_id=" + val + "; path=/; max-age=" + (60*60*24*30);
    });

    $('#addDependencyForm').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        var url = form.attr('action');
        var formData = form.serialize();

        $.ajax({
            type: "POST",
            url: url,
            data: formData,
            dataType: "json",
            success: function(response) {
                if (response.status === 'ok') {
                    PRX.toast(response.message, 0, 'ok');
                    location.reload(); 
                } else {
                    PRX.toast(response.message || 'Ошибка при добавлении зависимости.', 0, 'err');
                }
            },
            error: function(xhr) {
                var errorMsg = 'Произошла ошибка при добавлении зависимости.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    if (typeof xhr.responseJSON.message === 'object') {
                        errorMsg = Object.values(xhr.responseJSON.message).join('<br>');
                    } else {
                        errorMsg = xhr.responseJSON.message;
                    }
                }
                PRX.toast(errorMsg, 0, 'err');
            }
        });
    });

    $('.delete-dependency-btn').on('click', function() {
        var dependencyId = $(this).data('dependency-id');
        // Второе нажатие (data-prx-arm, app.js): первое взводит кнопку и сюда не доходит.
        $.ajax({
            type: "POST",
            url: '<?php echo URL::site('prx218/index/delete_dependency'); ?>',
            data: { dependency_id: dependencyId },
            dataType: "json",
            success: function(response) {
                if (response.status === 'ok') {
                    PRX.toast(response.message, 0, 'ok');
                    location.reload();
                } else {
                    PRX.toast(response.message || 'Ошибка при удалении зависимости.', 0, 'err');
                }
            },
            error: function(xhr) {
                var errorMsg = 'Произошла ошибка при удалении зависимости.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                PRX.toast(errorMsg, 0, 'err');
            }
        });
    });
});
</script>