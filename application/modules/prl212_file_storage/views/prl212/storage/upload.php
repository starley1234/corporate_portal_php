<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div id="status-messages">
        <?php if (isset($error) && $error): ?>
            <div class="prl-flash prl-flash-err mb-6">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-rose-500 mr-3"></i>
                    <p class="text-sm text-rose-800 dark:text-rose-200"><?php echo HTML::chars($error); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($success) && $success): ?>
            <div class="prl-flash prl-flash-ok mb-6">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-emerald-500 mr-3"></i>
                    <p class="text-sm text-emerald-800 dark:text-emerald-200"><?php echo HTML::chars($success); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="max-w-5xl mx-auto">
        <div class="prl-card p-0 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                <h2 class="prl-h3 flex items-center">
                    <i class="fas fa-cloud-upload-alt mr-3 text-blue-500"></i>
                    Загрузка файлов
                </h2>
            </div>

            <div class="p-6">
                <form method="post"
                      enctype="multipart/form-data"
                      action="<?php echo HTML::chars($upload_url ?? '/prl212_api/upload'); ?>"
                      id="upload-form"
                      class="space-y-6">

                    <?php if (isset($csrf_token)): ?>
                        <input type="hidden" name="csrf_token" value="<?php echo HTML::chars($csrf_token); ?>">
                    <?php endif; ?>

                    <div id="drop-zone-container">
                        <label class="prl-label mb-2">
                            Выберите файл для загрузки
                        </label>
                        <div id="drop-zone" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 dark:border-gray-600 border-dashed rounded-lg hover:border-blue-400 dark:hover:border-blue-500 transition-colors">
                            <div class="space-y-1 text-center">
                                <div class="flex text-gray-400 justify-center mb-3">
                                    <i class="fas fa-file-upload text-4xl"></i>
                                </div>
                                <div class="flex text-sm text-gray-600 dark:text-gray-400 justify-center">
                                    <label for="file-upload" class="relative cursor-pointer bg-white dark:bg-gray-800 rounded-md font-medium text-blue-600 dark:text-blue-400 hover:text-blue-500 focus-within:outline-none">
                                        <span>Загрузить файл</span>
                                        <input id="file-upload" name="file" type="file" class="sr-only"
                                               accept="<?php echo isset($allowed_extensions) ? '.' . implode(',.', $allowed_extensions) : '*/*'; ?>"
                                               <?php echo isset($max_file_size) ? 'data-max-size="' . $max_file_size . '"' : ''; ?>
                                               required>
                                    </label>
                                    <p class="pl-1">или перетащите файл сюда</p>
                                </div>
                                <p class="prl-hint">
                                    <?php if (isset($allowed_extensions) && is_array($allowed_extensions)): ?>
                                        Поддерживаемые форматы: <?php echo implode(', ', array_map(function($ext) { return '.' . $ext; }, $allowed_extensions)); ?>
                                    <?php else: ?>
                                        Любые файлы
                                    <?php endif; ?>
                                    <?php if (isset($max_file_size)): ?>
                                        (макс. <?php echo round($max_file_size / 1024 / 1024, 2); ?> МБ)
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <?php // Ход загрузки — процентами, без полосы: полосы убраны везде. ?>
                        <div id="progress-container" class="mt-4 hidden">
                            <p id="progress-text" role="status" aria-live="polite"
                               class="text-xs font-semibold text-gray-500 mt-1 text-right dark:text-gray-400">0%</p>
                        </div>

                        <div id="file-info" class="mt-3 hidden">
                            <div class="flex items-center justify-between p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                                <div class="flex items-center overflow-hidden">
                                    <i class="fas fa-file text-blue-500 mr-3 text-xl"></i>
                                    <div class="min-w-0">
                                        <p id="file-name" class="text-sm font-medium text-gray-900 dark:text-white truncate"></p>
                                        <p id="file-size" class="prl-hint"></p>
                                    </div>
                                </div>
                                <button type="button" id="remove-file" class="prl-btn-icon-danger ml-3">
                                    <i class="fas fa-times-circle text-xl"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if (isset($storage_options) && is_array($storage_options) && count($storage_options) > 1): ?>
                        <div>
                            <label class="prl-label mb-2">
                                Хранилище
                            </label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <?php foreach ($storage_options as $key => $option): ?>
                                    <label class="relative flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                        <input type="radio"
                                               name="backend_id"
                                               value="<?php echo HTML::chars($key); ?>"
                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300"
                                               <?php echo (isset($default_storage) && $default_storage == $key) ? 'checked' : ''; ?>>
                                        <div class="ml-3">
                                            <span class="block text-sm font-medium text-gray-900 dark:text-white">
                                                <?php echo HTML::chars($option['title'] ?? $key); ?>
                                            </span>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            <i class="fas fa-info-circle mr-1"></i>
                            Загрузка выполняется через API
                        </p>
                        <div class="flex space-x-3">
                            <a href="<?php echo HTML::chars($cancel_url ?? '/prl212_storage'); ?>"
                               class="prl-btn-secondary">
                                Отмена
                            </a>
                            <button type="submit"
                                    class="prl-btn-process"
                                    id="submit-btn">
                                <i class="fas fa-upload mr-2"></i>
                                <span>Загрузить</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('file-upload');
    const fileInfo = document.getElementById('file-info');
    const fileName = document.getElementById('file-name');
    const fileSize = document.getElementById('file-size');
    const removeBtn = document.getElementById('remove-file');
    const form = document.getElementById('upload-form');
    const submitBtn = document.getElementById('submit-btn');
    const dropZone = document.getElementById('drop-zone');
    const progressContainer = document.getElementById('progress-container');
    const progressText = document.getElementById('progress-text');
    const statusMessages = document.getElementById('status-messages');

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function showStatus(message, type = 'error') {
        const bgColor = type === 'success' ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-rose-50 dark:bg-rose-900/20';
        const borderColor = type === 'success' ? 'border-emerald-200 dark:border-emerald-800' : 'border-rose-200 dark:border-rose-800';
        const textColor = type === 'success' ? 'text-emerald-800 dark:text-emerald-200' : 'text-rose-800 dark:text-rose-200';
        const icon = type === 'success' ? 'fa-check-circle text-emerald-500' : 'fa-exclamation-triangle text-rose-500';

        statusMessages.innerHTML = `
            <div class="mb-6 p-4 ${bgColor} border ${borderColor} rounded-lg">
                <div class="flex items-center">
                    <i class="fas ${icon} mr-3"></i>
                    <p class="text-sm ${textColor}">${message}</p>
                </div>
            </div>
        `;
    }

    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                fileName.textContent = file.name;
                fileSize.textContent = formatFileSize(file.size);
                fileInfo.classList.remove('hidden');
                statusMessages.innerHTML = '';
            }
        });

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            }, false);
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.add('border-blue-500', 'bg-blue-50', 'dark:bg-blue-900/20');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.remove('border-blue-500', 'bg-blue-50', 'dark:bg-blue-900/20');
            }, false);
        });

        dropZone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            if (dt.files.length) {
                fileInput.files = dt.files;
                fileInput.dispatchEvent(new Event('change'));
            }
        }, false);
    }

    if (removeBtn) {
        removeBtn.addEventListener('click', function() {
            fileInput.value = '';
            fileInfo.classList.add('hidden');
            progressContainer.classList.add('hidden');
        });
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const file = fileInput.files[0];
            if (!file) return;

            const maxSize = fileInput.dataset.maxSize ? parseInt(fileInput.dataset.maxSize) : null;
            if (maxSize && file.size > maxSize) {
                showStatus('Файл превышает максимальный размер');
                return;
            }

            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Загрузка...';
            progressContainer.classList.remove('hidden');
            statusMessages.innerHTML = '';

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    progressText.textContent = percent + '%';
                }
            });

            xhr.addEventListener('load', () => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-upload mr-2"></i><span>Загрузить</span>';

                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        showStatus(response.message || 'Файл успешно загружен', 'success');
                        fileInput.value = '';
                        fileInfo.classList.add('hidden');
                        setTimeout(() => {
                            window.location.href = '<?php echo $cancel_url ?? "/prl212_storage"; ?>';
                        }, 1500);
                    } else {
                        showStatus(response.error || 'Ошибка при загрузке');
                    }
                } catch (err) {
                    showStatus('Ошибка обработки ответа сервера');
                }
            });

            xhr.addEventListener('error', () => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-upload mr-2"></i><span>Загрузить</span>';
                showStatus('Сетевая ошибка при загрузке');
            });

            xhr.open('POST', form.action);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(formData);
        });
    }
});
</script>
