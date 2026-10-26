<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 gap-4">
        <div>
            <h1 class="n2j-h1 flex items-center">
                <i class="fas fa-server mr-3 text-blue-500"></i>
                Управление хранилищами (Backends)
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Конфигурация драйверов и физических мест хранения файлов
            </p>
        </div>
        
        <div class="flex items-center gap-3">
            <button class="n2j-btn-cta">
                <i class="fas fa-plus mr-2"></i>
                Добавить бэкенд
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6">
        <div class="n2j-card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="n2j-table min-w-full">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Название и ID</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Драйвер</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Статус</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Конфигурация</th>
                            <th scope="col" class="px-6 py-4 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($backends as $backend): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 flex items-center justify-center rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400">
                                            <i class="fas <?php 
                                                echo $backend->driver === 's3' ? 'fa-cloud' : ($backend->driver === 'ftp' ? 'fa-network-wired' : 'fa-hdd'); 
                                            ?> text-lg"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?php echo HTML::chars($backend->name); ?>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-500 font-mono">
                                                ID: <?php echo $backend->id; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="n2j-badge n2j-badge-wait uppercase">
                                        <?php echo HTML::chars($backend->driver); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($backend->is_active): ?>
                                        <span class="n2j-badge n2j-badge-ok">
                                            <span class="w-2 h-2 mr-1.5 bg-emerald-500 rounded-full"></span>
                                            Активен
                                        </span>
                                    <?php else: ?>
                                        <span class="n2j-badge n2j-badge-err">
                                            <span class="w-2 h-2 mr-1.5 bg-rose-500 rounded-full"></span>
                                            Отключен
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-xs text-gray-600 dark:text-gray-400 max-w-xs truncate font-mono">
                                        <?php 
                                            $config = $backend->get_config_array();
                                            if ($backend->driver === 'local') {
                                                echo 'Path: ' . HTML::chars($config['root_path'] ?? 'default');
                                            } elseif ($backend->driver === 's3') {
                                                echo 'Bucket: ' . HTML::chars($config['bucket'] ?? '-');
                                            } elseif ($backend->driver === 'ftp') {
                                                echo 'Host: ' . HTML::chars($config['host'] ?? '-');
                                            }
                                        ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end gap-2">
                                        <button class="n2j-btn-icon" title="Редактировать">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="n2j-btn-icon" title="Проверить соединение">
                                            <i class="fas fa-plug"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-8 bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 rounded-xl p-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-info-circle text-blue-400"></i>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800 dark:text-blue-300">Справка по драйверам</h3>
                <div class="mt-2 text-sm text-blue-700 dark:text-blue-400">
                    <ul class="list-disc pl-5 space-y-1">
                        <li><strong>Local:</strong> Файлы хранятся на сервере приложения. Требуются права на запись в целевую директорию.</li>
                        <li><strong>S3:</strong> Совместимые хранилища (AWS, Minio, Selectel). Требуются Key, Secret и Bucket.</li>
                        <li><strong>FTP:</strong> Удаленные серверы. Рекомендуется использовать только в изолированных сетях.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>