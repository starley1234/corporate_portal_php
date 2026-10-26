
<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <!-- Файловая карточка -->
    <div class="prl-card p-0 mb-6 overflow-hidden">
        <div class="p-6">
            <div class="flex flex-col lg:flex-row lg:items-start gap-6">
                <!-- Иконка файла -->
                <div class="flex-shrink-0">
                    <div class="w-16 h-16 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400">
                        <i class="fas fa-file-lines text-2xl"></i>
                    </div>
                </div>
                
                <!-- Информация о файле -->
                <div class="flex-grow min-w-0">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-3">
                        <h2 class="prl-h2 truncate" title="<?php echo HTML::chars($file['name'] ?? ''); ?>">
                            <?php echo HTML::chars($file['name'] ?? 'Неизвестный файл'); ?>
                        </h2>
                        <div class="flex flex-wrap gap-2">
                            <?php if (isset($file['mime'])): ?>
                                <span class="prl-badge prl-badge-wait">
                                    <?php echo HTML::chars($file['mime']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (isset($file['size_formatted'])): ?>
                                <span class="prl-badge prl-badge-wait">
                                    <?php echo HTML::chars($file['size_formatted']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                        <?php if (isset($file['path'])): ?>
                            <div class="flex items-start">
                                <span class="text-gray-500 dark:text-gray-400 w-24 flex-shrink-0">Путь:</span>
                                <span class="text-gray-900 dark:text-gray-100 font-mono text-xs break-all" title="<?php echo HTML::chars($file['path']); ?>">
                                    <?php echo HTML::chars($file['path']); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($file['created_at'])): ?>
                            <div class="flex items-start">
                                <span class="text-gray-500 dark:text-gray-400 w-24 flex-shrink-0">Создан:</span>
                                <span class="text-gray-900 dark:text-gray-100">
                                    <?php echo HTML::chars($file['created_at']); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($file['updated_at'])): ?>
                            <div class="flex items-start">
                                <span class="text-gray-500 dark:text-gray-400 w-24 flex-shrink-0">Изменен:</span>
                                <span class="text-gray-900 dark:text-gray-100">
                                    <?php echo HTML::chars($file['updated_at']); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($file['storage_type'])): ?>
                            <div class="flex items-start">
                                <span class="text-gray-500 dark:text-gray-400 w-24 flex-shrink-0">Хранилище:</span>
                                <span class="text-gray-900 dark:text-gray-100">
                                    <?php echo HTML::chars(ucfirst($file['storage_type'])); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Две колонки: Права и Ссылки -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Управление правами -->
        <div class="prl-card p-0 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                <h3 class="prl-h3">Права доступа</h3>
                <?php if (isset($can_edit_permissions) && $can_edit_permissions): ?>
                    <button type="button" 
                            onclick="openPermissionModal()"
                            class="prl-btn-cta prl-btn-sm">
                        <i class="fas fa-user-plus mr-2"></i> Добавить право
                    </button>
                <?php endif; ?>
            </div>
            
            <div class="p-6">
                <?php if (!empty($permissions)): ?>
                    <div class="overflow-x-auto">
                        <table class="prl-table min-w-full">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Пользователь/Группа</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Право</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Дата</th>
                                    <?php if (isset($can_edit_permissions) && $can_edit_permissions): ?>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Действия</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($permissions as $perm): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">
                                                    <?php
                                                    $subject = (string) ($perm['user_name'] ?? $perm['group_name'] ?? '—');
                                                    echo HTML::chars(strtoupper(function_exists('mb_substr') ? mb_substr($subject, 0, 1) : substr($subject, 0, 1)));
                                                    ?>
                                                </div>
                                                <div class="ml-3">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo HTML::chars($subject); ?></div>
                                                    <?php if (isset($perm['subject_type'])): ?>
                                                        <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($perm['subject_type']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                            <?php echo HTML::chars($perm['permission'] ?? $perm['role'] ?? '—'); ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?php echo HTML::chars($perm['created_at'] ?? '—'); ?>
                                        </td>
                                        <?php if (isset($can_edit_permissions) && $can_edit_permissions): ?>
                                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                                                <?php if (!empty($perm['id'])): ?>
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="action" value="delete_permission">
                                                        <input type="hidden" name="permission_id" value="<?php echo (int) $perm['id']; ?>">
                                                        <button type="submit" data-prl-arm="Точно удалить?" class="prl-btn-danger prl-btn-sm">Удалить</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Права доступа не настроены.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ссылки на файл -->
        <div class="prl-card p-0 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="prl-h3">Ссылки</h3>
            </div>
            <div class="p-6 space-y-3">
                <?php if (!empty($download_url)): ?>
                    <a href="<?php echo HTML::chars($download_url); ?>" class="inline-flex items-center text-blue-600 hover:text-blue-800 dark:text-blue-400">
                        <i class="fas fa-download mr-2"></i> Скачать файл
                    </a>
                <?php elseif (!empty($file['download_url'])): ?>
                    <a href="<?php echo HTML::chars($file['download_url']); ?>" class="inline-flex items-center text-blue-600 hover:text-blue-800 dark:text-blue-400">
                        <i class="fas fa-download mr-2"></i> Скачать файл
                    </a>
                <?php else: ?>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Ссылка на скачивание недоступна.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
