<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <nav class="flex mb-2" aria-label="Breadcrumb">
                <ol class="inline-flex items-center space-x-1 md:space-x-3">
                    <li class="inline-flex items-center">
                        <a href="<?php echo Route::url('default', ['controller' => 'erp212_storage', 'action' => 'index']); ?>" class="text-sm text-gray-500 hover:text-blue-600 dark:text-gray-400 dark:hover:text-white">
                            <i class="fas fa-folder-open mr-2"></i>Хранилище
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 text-xs mx-2"></i>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Детали файла</span>
                        </div>
                    </li>
                </ol>
            </nav>
            <h1 class="n2j-h1 truncate max-w-2xl">
                <?php echo HTML::chars($file->filename); ?>
            </h1>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="<?php echo Route::url('default', ['controller' => 'erp212_download', 'action' => 'file', 'id' => $file->id]); ?>" 
               class="n2j-btn-process">
                <i class="fas fa-download mr-2"></i> Скачать
            </a>
            <?php if ($file->can_write(Auth::instance()->get_user()->id)): ?>
                <a href="/erp212_storage/edit/<?php echo HTML::chars($file->id); ?>" class="n2j-btn-secondary">
                    <i class="fas fa-edit mr-2"></i> Редактировать
                </a>
                <button type="button" onclick="deleteFile(<?php echo $file->id; ?>)" data-n2j-confirm="Файл удалится из хранилища." data-n2j-title="Удалить файл?" class="n2j-btn-danger-soft">
                    <i class="fas fa-trash-alt mr-2"></i> Удалить
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Основная информация -->
        <div class="lg:col-span-2 space-y-6">
            <div class="n2j-card p-0 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-900/20">
                    <h2 class="n2j-h3 flex items-center">
                        <i class="fas fa-info-circle mr-2 text-blue-500"></i> Свойства файла
                    </h2>
                </div>
                <div class="p-6">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-6">
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">MIME-тип</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white flex items-center">
                                <span class="px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-xs font-mono mr-2"><?php echo $file->mime_type; ?></span>
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
                                        <div class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center text-xs font-bold text-blue-600 mr-2">
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
                                    <span class="n2j-badge n2j-badge-ok">
                                        <i class="fas fa-globe mr-1"></i> Публичный
                                    </span>
                                <?php else: ?>
                                    <span class="n2j-badge n2j-badge-run">
                                        <i class="fas fa-lock mr-1"></i> Приватный
                                    </span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Секция предпросмотра (если применимо) -->
            <?php if (strpos($file->mime_type, 'image/') === 0): ?>
                <div class="n2j-card p-0 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="n2j-h3">Предпросмотр</h2>
                    </div>
                    <div class="p-6 flex justify-center bg-gray-100 dark:bg-gray-900/50">
                        <img src="<?php echo Route::url('default', ['controller' => 'erp212_download', 'action' => 'file', 'id' => $file->id]); ?>" 
                             alt="<?php echo HTML::chars($file->filename); ?>" 
                             class="max-w-full h-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Боковая панель: Ссылки и Статистика -->
        <div class="space-y-6">
            <div class="n2j-card p-0 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="n2j-h3 flex items-center">
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
                    
                    <button class="n2j-btn-secondary w-full">
                        <i class="fas fa-plus-circle mr-2"></i> Создать ссылку
                    </button>
                </div>
            </div>

            <div class="n2j-card p-0 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="n2j-h3 flex items-center">
                        <i class="fas fa-user-shield mr-2 text-blue-500"></i> Доступ
                    </h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600 dark:text-gray-400">Права доступа (ACL)</span>
                            <span class="n2j-badge n2j-badge-info">
                                <?php echo count($file->permissions->find_all()); ?> записей
                            </span>
                        </div>
                        <button onclick="manageAccess(<?php echo $file->id; ?>)" class="n2j-btn-secondary w-full">
                            Управление доступом
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Подтверждение — окном (data-n2j-confirm на кнопке, app.js): сюда клик доходит
// только после «Удалить».
function deleteFile(id) {
    fetch('/erp212_api/delete/' + id, {
        method: 'DELETE',
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '<?php echo Route::url('default', ['controller' => 'erp212_storage', 'action' => 'index']); ?>';
        } else {
            N2J.toast(data.error || 'Не удалось удалить файл', 0, 'err');
        }
    })
    .catch(() => N2J.toast('Не удалось удалить файл: сеть не ответила', 0, 'err'));
}

function manageAccess(id) {
    // В данной реализации перенаправляем на страницу управления правами или открываем модальное окно
    // Для примера реализуем переход на контроллер управления
    window.location.href = '/erp212_storage/permissions/' + id;
}
</script>