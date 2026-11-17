<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 gap-4">
        <div>
            <h1 class="prx-h1 flex items-center">
                <i class="fas fa-globe-americas mr-3 text-blue-500"></i>
                Общие файлы
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Список файлов с публичным доступом для всех пользователей системы
            </p>
        </div>

        <div class="flex items-center gap-3">
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Всего файлов: <span class="font-semibold text-gray-900 dark:text-white"><?php echo $total; ?></span>
            </span>
        </div>
    </div>

    <?php if (count($files) > 0): ?>
        <div class="prx-card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="prx-table min-w-full">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Имя файла</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Тип</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Размер</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Дата загрузки</th>
                            <th scope="col" class="px-6 py-4 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($files as $file): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 flex items-center justify-center rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400">
                                            <i class="fas fa-file-alt text-lg"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?php echo HTML::chars($file->filename); ?>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-500 font-mono">
                                                ID: <?php echo $file->id; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="prx-count uppercase">
                                        <?php echo HTML::chars($file->extension ?: 'bin'); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                    <?php echo Text::bytes($file->file_size); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                    <?php echo date('d.m.Y H:i', strtotime($file->created_at)); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end gap-2">
                                        <a href="<?php echo Route::url('default', ['controller' => 'prx212_storage', 'action' => 'file', 'id' => $file->id]); ?>"
                                           class="prx-btn-icon"
                                           title="Просмотр">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="<?php echo Route::url('default', ['controller' => 'prx212_download', 'action' => 'file', 'id' => $file->id]); ?>"
                                           class="prx-btn-icon"
                                           title="Скачать">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total > 20): ?>
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            Страница <?php echo $page; ?>
                        </div>
                        <div class="flex gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>" class="prx-btn-secondary prx-btn-sm">Назад</a>
                            <?php endif; ?>
                            <?php if ($total > $page * 20): ?>
                                <a href="?page=<?php echo $page + 1; ?>" class="prx-btn-secondary prx-btn-sm">Вперед</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <section class="prx-panel">
            <div class="prx-empty">
                <div class="prx-empty-icon"><i class="fas fa-folder-open"></i></div>
                <h3 class="prx-h3 mt-4">Публичные файлы отсутствуют</h3>
                <p class="prx-empty-text">В данный момент нет файлов, доступных для общего просмотра.</p>
            </div>
        </section>
    <?php endif; ?>
</div>
