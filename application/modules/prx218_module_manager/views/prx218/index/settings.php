<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="prx-card">
        <h1 class="prx-h1 mb-6"><?php echo __('Настройки реестра модулей'); ?></h1>

        <form id="settings-form" method="POST" action="<?php echo URL::site('prx218_module_manager/index/save_settings'); ?>">
            <div class="space-y-6">
                <!-- Настройки сканера -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4">
                    <h3 class="prx-h3 mb-4"><?php echo __('Настройки сканирования'); ?></h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="scanner_path" class="prx-label">
                                <?php echo __('Базовый путь для поиска модулей'); ?>
                            </label>
                            <input type="text" name="scanner_path" id="scanner_path"
                                   class="prx-input mt-1 w-full"
                                   value="<?php echo HTML::chars(Arr::get($config, 'scanner.path', '')); ?>"
                                   placeholder="<?php echo APPPATH . 'modules'; ?>" readonly>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                <?php echo __('Укажите директорию, в которой сканер будет искать модули. По умолчанию: ') . APPPATH . 'modules'; ?>
                            </p>
                        </div>

                        <div>
                            <label for="scanner_max_depth" class="prx-label">
                                <?php echo __('Глубина рекурсии при поиске'); ?>
                            </label>
                            <input type="number" name="scanner_max_depth" id="scanner_max_depth"
                                   class="prx-input mt-1 w-full"
                                   value="<?php echo HTML::chars(Arr::get($config, 'scanner.max_depth', 1)); ?>"
                                   min="0" readonly>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                <?php echo __('Определяет, насколько глубоко сканер будет искать модули. 0 - только корневая директория, 1 - папки в корне и т.д.'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label for="metadata_files" class="prx-label">
                            <?php echo __('Файлы метаданных (через запятую)'); ?>
                        </label>
                        <input type="text" name="metadata_files" id="metadata_files"
                               class="prx-input mt-1 w-full"
                               value="<?php echo HTML::chars(implode(', ', Arr::get($config, 'scanner.metadata_files', []))); ?>" readonly>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            <?php echo __('Список файлов, из которых сканер будет извлекать метаданные модуля (например, README.md, composer.json).'); ?>
                        </p>
                    </div>
                </div>

                <!-- Исключаемые директории -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4">
                    <h3 class="prx-h3 mb-4"><?php echo __('Исключаемые директории'); ?></h3>
                    <div>
                        <label for="excluded_directories" class="prx-label">
                            <?php echo __('Список исключаемых директорий (через запятую)'); ?>
                        </label>
                        <input type="text" name="excluded_directories" id="excluded_directories"
                               class="prx-input mt-1 w-full"
                               value="<?php echo HTML::chars(implode(', ', Arr::get($config, 'excluded_directories', []))); ?>" readonly>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            <?php echo __('Папки, которые будут игнорироваться сканером (например, .git, vendor).'); ?>
                        </p>
                    </div>
                </div>

                <!-- Настройки статусов (только отображение, редактирование сложнее) -->
                <div>
                    <h3 class="prx-h3 mb-4"><?php echo __('Настройки жизненного цикла (статусы)'); ?></h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        <?php echo __('Текущие статусы модулей. Для изменения этих настроек требуется редактирование файла конфигурации.'); ?>
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach (Arr::get($config, 'statuses', []) as $key => $status): ?>
                            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-md shadow-sm border border-gray-200 dark:border-gray-600">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-lg font-medium text-gray-900 dark:text-white"><?php echo HTML::chars(Arr::get($status, 'label', $key)); ?></span>
                                    <span class="inline-block w-4 h-4 rounded-full" style="background-color: <?php echo HTML::chars(Arr::get($status, 'color', '#ccc')); ?>;"></span>
                                </div>
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    <strong><?php echo __('Ключ:'); ?></strong> <?php echo HTML::chars($key); ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-700">
                <button type="submit" disabled class="prx-btn-cta">
                    <?php echo __('Сохранить настройки (только для чтения)'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Отключаем отправку формы, так как все поля только для чтения
        const settingsForm = document.getElementById('settings-form');
        settingsForm.addEventListener('submit', function(e) {
            e.preventDefault();
            PRX.toast('Настройки находятся в режиме только для чтения.');
        });
    });
</script>