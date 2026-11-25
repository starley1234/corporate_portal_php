<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Реестр модулей', '/prx218_module_manager'], [$module->display_name, Route::url('prx218_module_manager_admin', ['controller' => 'index', 'action' => 'view', 'id' => $module->id])], ['Редактирование']],
        'title'   => 'Редактирование модуля: '.HTML::chars($module->display_name),
        'pill'    => ['prx218 · реестр', 'fa-pen-to-square'],
        'actions' => [['label' => 'Назад к просмотру', 'href' => Route::url('prx218_module_manager_admin', ['controller' => 'index', 'action' => 'view', 'id' => $module->id]), 'icon' => 'fa-arrow-left', 'role' => 'secondary']],
    ]); ?>

    <div class="prx-card sm:p-8">
        <form id="moduleEditForm" method="POST" action="<?php echo Route::url('prx218_module_manager_admin', ['controller' => 'index', 'action' => 'edit', 'id' => $module->id]); ?>">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Display Name -->
                <div>
                    <label for="display_name" class="prx-label mb-2">
                        Название модуля <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="display_name" id="display_name"
                           class="prx-input form-input w-full"
                           value="<?php echo HTML::chars($module->display_name); ?>"
                           required>
                </div>

                <!-- Main Page URI -->
                <div>
                    <label for="main_page_uri" class="prx-label mb-2">
                        URI главной страницы
                    </label>
                    <input type="text" name="main_page_uri" id="main_page_uri"
                           class="prx-input form-input w-full"
                           value="<?php echo HTML::chars($module->main_page_uri); ?>"
                           placeholder="например: prx/dashboard">
                    <p class="prx-hint mt-2">
                        Относительный путь к основной странице модуля.
                    </p>
                </div>

                <!-- System Name (Read-only) -->
                <div>
                    <label for="system_name" class="prx-label mb-2">
                        Системное имя (папка)
                    </label>
                    <input type="text" id="system_name"
                           class="prx-input form-input w-full cursor-not-allowed"
                           value="<?php echo HTML::chars($module->system_name); ?>"
                           readonly>
                    <p class="prx-hint mt-2">
                        Это имя папки модуля и не может быть изменено вручную.
                    </p>
                </div>

                <!-- Version (Read-only) -->
                <div>
                    <label for="version" class="prx-label mb-2">
                        Текущая версия
                    </label>
                    <input type="text" id="version"
                           class="prx-input form-input w-full cursor-not-allowed"
                           value="<?php echo HTML::chars($module->version); ?>"
                           readonly>
                    <p class="prx-hint mt-2">
                        Версия обновляется через историю изменений.
                    </p>
                </div>

                <!-- Status (Read-only) -->
                <div>
                    <label for="status" class="prx-label mb-2">
                        Статус
                    </label>
                    <input type="text" id="status"
                           class="prx-input form-input w-full cursor-not-allowed"
                           value="<?php echo ucfirst(HTML::chars($module->status)); ?>"
                           readonly>
                    <p class="prx-hint mt-2">
                        Статус изменяется отдельно через действия.
                    </p>
                </div>
            </div>

            <!-- Description -->
            <div class="mt-6">
                <label for="description" class="prx-label mb-2">
                    Описание функционала
                </label>
                <textarea name="description" id="description" rows="6"
                          class="prx-textarea form-textarea w-full"><?php echo HTML::chars($module->description); ?></textarea>
                <p class="prx-hint mt-2">
                    Подробное описание назначения и возможностей модуля.
                </p>
            </div>

            <!-- Developer Notes -->
            <div class="mt-6">
                <label for="developer_notes" class="prx-label mb-2">
                    Заметки разработчика
                </label>
                <textarea name="developer_notes" id="developer_notes" rows="4"
                          class="prx-textarea form-textarea w-full"><?php echo HTML::chars($module->developer_notes); ?></textarea>
                <p class="prx-hint mt-2">
                    Технические заметки, особенности внедрения, важные предупреждения для других разработчиков.
                </p>
            </div>

            <div class="mt-8 flex flex-col sm:flex-row justify-end gap-4">
                <a href="<?php echo Route::url('prx218_module_manager_admin', ['controller' => 'index', 'action' => 'view', 'id' => $module->id]); ?>" class="prx-btn-secondary w-full sm:w-auto">
                    Отмена
                </a>
                <button type="submit" class="prx-btn-cta w-full sm:w-auto">
                    <i class="fas fa-save mr-2"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('moduleEditForm');

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(form);
            const actionUrl = form.action;

            fetch(actionUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest' // Для Kohana, чтобы определить AJAX-запрос
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'ok') {
                    // Используем вашу систему уведомлений
                    PRX.toast(data.message, 0, 'ok');
                    window.location.href = '<?php echo Route::url('prx218_module_manager_admin', ['controller' => 'index', 'action' => 'view', 'id' => $module->id]); ?>';
                } else {
                    PRX.toast('Ошибка: ' + data.message, 0, 'err');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                PRX.toast('Произошла ошибка при сохранении.', 0, 'err');
            });
        });
    });
</script>