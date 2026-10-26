<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="prl-h1">Настройки хранилища</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Управление конфигурацией и активными бэкендами</p>
        </div>
        <div class="flex gap-2">
            <a href="<?php echo URL::site('prl212_index'); ?>" class="prl-btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i> К списку файлов
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Основные параметры -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                    <h3 class="font-semibold text-gray-900 dark:text-white">Глобальная конфигурация</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="prl-label mb-1">Максимальный размер файла</label>
                            <div class="px-3 py-2 bg-gray-100 dark:bg-gray-700 rounded-md text-gray-900 dark:text-white border border-gray-200 dark:border-gray-600">
                                <?php echo Text::bytes((float)$config['upload_max_size'] , ''); ?>
                               
                            </div>
                        </div>
                        <div>
                            <label class="prl-label mb-1">Разрешенные типы (MIME)</label>
                            <div class="flex flex-wrap gap-1">
                                <?php foreach ((array)($config['allowed_mime_types'] ?? []) as $mime): ?>
                                    <span class="prl-badge prl-badge-info">
                                        <?php echo HTML::chars($mime); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                    <h3 class="font-semibold text-gray-900 dark:text-white">Активные бэкенды (Драйверы)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="prl-table min-w-full">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Драйвер</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Статус</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($backends as $backend): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">#<?php echo $backend->id; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <i class="fas <?php echo ($backend->driver == 's3') ? 'fa-cloud' : 'fa-server'; ?> mr-2 text-gray-400"></i>
                                            <span class="text-sm font-medium text-gray-900 dark:text-white"><?php echo strtoupper($backend->driver); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="prl-badge prl-badge-ok">
                                            Активен
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <button class="prl-btn-icon" title="Редактировать">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Боковая панель: Инструменты -->
        <div class="space-y-6">
            <div class="prl-card">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Обслуживание</h3>
                <div class="space-y-3">
                    <button class="prl-btn-secondary w-full justify-between">
                        <span>Очистить временные ссылки</span>
                        <i class="fas fa-broom text-gray-400"></i>
                    </button>
                    <button class="prl-btn-secondary w-full justify-between">
                        <span>Проверить целостность</span>
                        <i class="fas fa-check-double text-gray-400"></i>
                    </button>
                </div>
            </div>

            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800 p-6">
                <h3 class="font-semibold text-blue-900 dark:text-blue-300 mb-2">Информация</h3>
                <p class="text-sm text-blue-800 dark:text-blue-400 leading-relaxed">
                    Изменения в конфигурационном файле <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">prl212_storage.php</code> применяются мгновенно. Для добавления новых бэкендов используйте SQL миграции или интерфейс управления БД.
                </p>
            </div>
        </div>
    </div>
</div>