<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="prx-h1 flex items-center">
                <i class="fas fa-edit mr-3 text-blue-500"></i>
                Редактирование файла
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Изменение метаданных и параметров доступа для файла #<?php echo $file->id; ?>
            </p>
        </div>
        <a href="<?php echo Route::url('default', ['controller' => 'prx212_file_storage', 'action' => 'file', 'id' => $file->id]); ?>"
           class="prx-btn-secondary">
            <i class="fas fa-arrow-left mr-2"></i> К информации
        </a>
    </div>

    <div class="prx-card p-0 overflow-hidden">
        <form action="" method="POST" class="p-6 space-y-6">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">

            <?php if (!empty($errors)): ?>
                <div class="prx-flash prx-flash-err mb-4 block">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo HTML::chars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Имя файла -->
            <div>
                <label for="filename" class="prx-label mb-2">
                    Имя файла
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-file-signature text-gray-400"></i>
                    </div>
                    <input type="text" name="filename" id="filename"
                           value="<?php echo HTML::chars($file->filename); ?>"
                           class="prx-input w-full pl-10"
                           required>
                </div>
                <p class="prx-hint mt-2">Отображаемое имя файла в системе.</p>
            </div>

            <!-- Префикс модуля -->
            <div>
                <label for="module_prefix" class="prx-label mb-2">
                    Префикс модуля
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-tag text-gray-400"></i>
                    </div>
                    <input type="text" name="module_prefix" id="module_prefix"
                           value="<?php echo HTML::chars($file->module_prefix); ?>"
                           class="prx-input w-full pl-10">
                </div>
                <p class="prx-hint mt-2">Используется для группировки файлов по модулям системы.</p>
            </div>

            <!-- Общий доступ -->
            <div class="flex items-start">
                <div class="flex items-center h-5">
                    <input id="is_public" name="is_public" type="checkbox" value="1"
                           <?php echo $file->is_public ? 'checked' : ''; ?>
                           class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
                </div>
                <div class="ml-3 text-sm">
                    <label for="is_public" class="font-medium text-gray-700 dark:text-gray-300">Общий файл</label>
                    <p class="text-gray-500 dark:text-gray-400">Если включено, файл будет виден всем пользователям в разделе «Общие файлы».</p>
                </div>
            </div>

            <div class="pt-6 border-t border-gray-200 dark:border-gray-700 flex items-center justify-end gap-4">
                <button type="reset" class="prx-btn-secondary">
                    Сбросить изменения
                </button>
                <button type="submit" class="prx-btn-cta">
                    <i class="fas fa-save mr-2"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>

    <!-- Информационная панель -->
    <div class="mt-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/30 rounded-xl p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-info-circle text-blue-400"></i>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800 dark:text-blue-300">Техническая информация</h3>
                <div class="mt-2 text-sm text-blue-700 dark:text-blue-400">
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Физический путь и MIME-тип файла изменить нельзя.</li>
                        <li>Для изменения прав доступа конкретных пользователей используйте вкладку «Права доступа» в карточке файла.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
