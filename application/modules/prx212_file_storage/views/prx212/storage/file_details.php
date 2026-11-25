<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <?php echo View::factory('partials/page_header', [
            'crumbs'  => [['Хранилище', URL::site('prx212_file_storage')], ['Детали файла']],
            'title'   => $file->filename,
            'pill'    => ['prx212 · файл', 'fa-file-lines'],
            'actions' => [
                ['html' => '<a href="'.Route::url('default', ['controller' => 'prx212_download', 'action' => 'file', 'id' => $file->id]).'" class="prx-btn-process"><i class="fas fa-download mr-2"></i> Скачать</a>'],
                ['html' => '<a href="/prx212_file_storage/edit/'.HTML::chars($file->id).'" class="prx-btn-secondary"><i class="fas fa-edit mr-2"></i> Редактировать</a>'],
                ['html' => '<button type="button" onclick="deleteFile('.(int) $file->id.')" data-prx-confirm="Файл удалится из хранилища." data-prx-title="Удалить файл?" class="prx-btn-danger-soft"><i class="fas fa-trash-alt mr-2"></i> Удалить</button>'],
            ],
        ]); ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Основная информация -->
        <div class="lg:col-span-2 space-y-6">
            <div class="prx-card p-0 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-900/20">
                    <h2 class="prx-h3 flex items-center">
                        <i class="fas fa-info-circle mr-2 text-blue-500"></i> Свойства файла
                    </h2>
                </div>
                <div class="p-6">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-6">
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">MIME-тип</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white flex items-center">
                                <span class="prx-count mr-2 font-mono"><?php echo HTML::chars($file->mime_type); ?></span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Размер</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white"><?php echo Text::bytes($file->file_size); ?> (<?php echo number_format($file->file_size, 0, '.', ' '); ?> байт)</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Дата создания</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white"><?php echo date('d.m.Y H:i:s', strtotime($file->created_at)); ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Владелец</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                <?php if ($file->user->loaded()): ?>
                                    <div class="flex items-center">
                                        <div class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center text-xs font-bold text-blue-600 dark:text-blue-300 mr-2">
                                            <?php echo strtoupper(substr($file->user->username, 0, 1)); ?>
                                        </div>
                                        <?php echo HTML::chars($file->user->username); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-gray-400 italic">Системный</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Хэш (SHA256)</dt>
                            <dd class="mt-1 text-xs font-mono text-gray-600 dark:text-gray-400 break-all bg-gray-50 dark:bg-gray-900/50 p-2 rounded border border-gray-100 dark:border-gray-700">
                                <?php echo $file->hash ?: 'Не вычислен'; ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Бэкенд хранилища</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                <span class="inline-flex items-center">
                                    <i class="fas fa-server mr-2 text-gray-400"></i>
                                    <?php echo HTML::chars($file->backend->name); ?> (<?php echo $file->backend->driver; ?>)
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Статус доступа</dt>
                            <dd class="mt-1">
                                <?php if ($file->is_public): ?>
                                    <span class="prx-badge prx-badge-info"><span class="prx-dot"></span>Публичный</span>
                                <?php else: ?>
                                    <span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>Приватный</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Секция предпросмотра (если применимо) -->
            <?php if (strpos($file->mime_type, 'image/') === 0): ?>
                <div class="prx-card p-0 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="prx-h3">Предпросмотр</h2>
                    </div>
                    <div class="p-6 flex justify-center bg-gray-100 dark:bg-gray-900/50">
                        <img src="<?php echo Route::url('default', ['controller' => 'prx212_download', 'action' => 'file', 'id' => $file->id]); ?>"
                             alt="<?php echo HTML::chars($file->filename); ?>"
                             class="max-w-full h-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Боковая панель: Ссылки и Статистика -->
        <div class="space-y-6">
            <div class="prx-card p-0 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="prx-h3 flex items-center">
                        <i class="fas fa-link mr-2 text-emerald-500"></i> Временные ссылки
                    </h2>
                </div>
                <div class="p-6">
                    <?php $stats = $file->get_links_stats(); ?>
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-center">
                            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $stats['active_links']; ?></div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Активных</div>
                        </div>
                        <div class="p-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg text-center">
                            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400"><?php echo $stats['total_clicks']; ?></div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Переходов</div>
                        </div>
                    </div>

                    <button class="prx-btn-secondary w-full">
                        <i class="fas fa-plus-circle mr-2"></i> Создать ссылку
                    </button>
                </div>
            </div>

            <div class="prx-card p-0 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="prx-h3 flex items-center">
                        <i class="fas fa-user-shield mr-2 text-blue-500"></i> Доступ
                    </h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600 dark:text-gray-400">Права доступа (ACL)</span>
                            <span class="prx-count">
                                <?php echo count($file->permissions->find_all()); ?> записей
                            </span>
                        </div>
                        <button onclick="manageAccess(<?php echo $file->id; ?>)" class="prx-btn-secondary w-full">
                            Управление доступом
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Подтверждение — окном (data-prx-confirm на кнопке, app.js): сюда клик доходит
// только после «Удалить».
function deleteFile(id) {
    fetch('/prx212_api/delete/' + id, {
        method: 'DELETE',
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '<?php echo Route::url('default', ['controller' => 'prx212_file_storage', 'action' => 'index']); ?>';
        } else {
            PRX.toast(data.error || 'Не удалось удалить файл', 0, 'err');
        }
    })
    .catch(() => PRX.toast('Не удалось удалить файл: сеть не ответила', 0, 'err'));
}

function manageAccess(id) {
    // В данной реализации перенаправляем на страницу управления правами или открываем модальное окно
    // Для примера реализуем переход на контроллер управления
    window.location.href = '/prx212_file_storage/permissions/' + id;
}
</script>
