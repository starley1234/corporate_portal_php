<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div id="status-messages">
        <?php if (isset($error) && $error): ?>
            <div class="prx-flash prx-flash-err mb-5" role="alert">
                <i class="fas fa-triangle-exclamation"></i>
                <span><?php echo HTML::chars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($success) && $success): ?>
            <div class="prx-flash prx-flash-ok mb-5" role="status">
                <i class="fas fa-circle-check"></i>
                <span><?php echo HTML::chars($success); ?></span>
            </div>
        <?php endif; ?>
    </div>

    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Файлы', '/prx212_file_storage'], ['Загрузка файлов']],
        'title'  => 'Загрузка файлов',
        'pill'   => ['prx212 · хранилище', 'fa-cloud-arrow-up'],
        'lead'   => 'Выберите файл. Он сохранится в хранилище по умолчанию и будет доступен владельцу.',
        'actions' => [
            ['label' => 'Мои файлы', 'href' => $cancel_url ?? '/prx212_file_storage', 'icon' => 'fa-folder-open', 'role' => 'secondary'],
        ],
    ]); ?>

    <section class="prx-panel mt-6">
            <div class="prx-panel-head">
                <div>
                    <h2 class="prx-h3"><i class="fas fa-cloud-arrow-up mr-2 text-blue-500"></i>Новый файл</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Файл можно выбрать на устройстве или перетащить в выделенную область.</p>
                </div>
            </div>

            <div class="p-6">
                <form method="post"
                      enctype="multipart/form-data"
                      action="<?php echo HTML::chars($upload_url ?? '/prx212_api/upload'); ?>"
                      id="upload-form"
                      class="space-y-6">

                    <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">

                    <div id="drop-zone-container">
                        <label class="prx-label mb-2">
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
                                               accept="<?php echo HTML::chars(implode(',', $allowed_types ?? [])); ?>"
                                               <?php echo isset($max_file_size) ? 'data-max-size="' . $max_file_size . '"' : ''; ?>
                                               required>
                                    </label>
                                    <p class="pl-1">или перетащите файл сюда</p>
                                </div>
                                <p class="prx-hint">
                                    Поддерживаются разрешённые хранилищем типы файлов.
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
                                        <p id="file-size" class="prx-hint"></p>
                                    </div>
                                </div>
                                <button type="button" id="remove-file" class="prx-btn-icon-danger ml-3">
                                    <i class="fas fa-times-circle text-xl"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            <i class="fas fa-info-circle mr-1"></i>
                            Загрузка выполняется через API
                        </p>
                        <div class="flex space-x-3">
                            <a href="<?php echo HTML::chars($cancel_url ?? '/prx212_file_storage'); ?>"
                               class="prx-btn-secondary">
                                Отмена
                            </a>
                            <button type="submit"
                                    class="prx-btn-cta"
                                    id="submit-btn">
                                <i class="fas fa-upload mr-2"></i>
                                <span>Загрузить</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
    </section>
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
        const ok = type === 'success';
        const host = document.createElement('div');
        host.className = 'prx-flash mb-5 ' + (ok ? 'prx-flash-ok' : 'prx-flash-err');
        host.setAttribute('role', ok ? 'status' : 'alert');
        const icon = document.createElement('i');
        icon.className = 'fas ' + (ok ? 'fa-circle-check' : 'fa-triangle-exclamation');
        const text = document.createElement('span');
        text.textContent = message;
        host.append(icon, text);
        statusMessages.replaceChildren(host);
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
                            window.location.href = '/prx212_file_storage';
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
