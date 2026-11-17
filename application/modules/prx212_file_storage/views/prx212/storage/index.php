<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <!-- Хлебные крошки и поиск -->
    <div class="flex flex-col md:flex-row md:items-center justify-between mb-6 gap-4">
        <?php if (isset($storage_path) && !empty($storage_path)): ?>
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="inline-flex items-center space-x-1 md:space-x-3">
                    <li class="inline-flex items-center">
                        <a href="<?php echo URL::site('prx212_storage'); ?>" class="text-blue-600 hover:text-blue-700 dark:text-blue-400 transition-colors">
                            <i class="fas fa-hdd mr-2"></i>Хранилище
                        </a>
                    </li>
                    <?php foreach (array_slice($storage_path, 1) as $key => $crumb): ?>
                        <li class="inline-flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2 text-xs"></i>
                            <?php if ($key == count($storage_path) - 2): ?>
                                <span class="ml-1 text-gray-500 dark:text-gray-400 font-medium"><?php echo HTML::chars($crumb['name']); ?></span>
                            <?php else: ?>
                                <a href="<?php echo HTML::chars($crumb['url']); ?>" class="ml-1 text-blue-600 hover:text-blue-700 dark:text-blue-400 transition-colors">
                                    <?php echo HTML::chars($crumb['name']); ?>
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>

        <!-- Форма поиска -->
        <form action="<?php echo URL::site('prx212_storage/search'); ?>" method="GET" class="relative w-full md:w-64">
            <input type="text" name="q" value="<?php echo isset($search) ? HTML::chars($search) : ''; ?>"
                   placeholder="Поиск файлов..."
                   class="prx-input w-full pl-10">
            <div class="absolute left-3 top-2.5 text-gray-400">
                <i class="fas fa-search"></i>
            </div>
        </form>
    </div>

    <!-- Панель управления -->
    <div class="prx-card flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <div class="flex-1">
            <h1 class="prx-h1 flex items-center">
                <?php echo HTML::chars(isset($current_folder_name) ? $current_folder_name : 'Мои файлы'); ?>
                <?php if (isset($search) && $search): ?>
                    <span class="prx-count ml-2">Результаты поиска</span>
                <?php endif; ?>
            </h1>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-sm text-gray-500 dark:text-gray-400">
                <span class="flex items-center"><i class="far fa-file-alt mr-1.5"></i> <?php echo (int)$total_files; ?> элементов</span>
                <?php if (isset($stats['total_size']) && $stats['total_size'] !== NULL): ?>
                    <span class="flex items-center"><i class="far fa-chart-bar mr-1.5"></i>
                        <?php
                            /**
                             * Исправление ошибки Deprecated: strpos(): Passing null to parameter #1
                             * Метод Text::bytes(int $bytes, string $force_unit = NULL, ...) в Kohana 3.3
                             * вызывает strpos($force_unit, 'i'), что в PHP 8.1+ кидает ошибку, если $force_unit === NULL.
                             * Решение: передавать пустую строку вместо NULL во второй параметр.
                             */
                            echo Text::bytes((float)$stats['total_size'], '');
                        ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <?php if (in_array('212_storage_admin', $current_user_roles) || in_array('212_storage_user', $current_user_roles)): ?>
                <a href="<?php echo URL::site('prx212_storage/upload'); ?>" class="prx-btn-cta">
                    <i class="fas fa-cloud-upload-alt mr-2"></i>Загрузить
                </a>
            <?php endif; ?>

            <?php if (in_array('212_storage_admin', $current_user_roles)): ?>
                <a href="<?php echo URL::site('prx212_storage/backends'); ?>" class="prx-btn-icon text-sm" title="Настройки хранилищ">
                    <i class="fas fa-server"></i>
                </a>
                <a href="<?php echo URL::site('prx212_storage/logs'); ?>" class="prx-btn-icon text-sm" title="Журнал доступа">
                    <i class="fas fa-history"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Список файлов -->
    <?php if (empty($files)): ?>
        <section class="prx-panel">
            <div class="prx-empty">
                <div class="prx-empty-icon"><i class="fas fa-folder-open"></i></div>
                <h3 class="prx-h3 mt-4">Файлы не найдены</h3>
                <p class="prx-empty-text">
                <?php if (isset($search)): ?>
                    По запросу "<?php echo HTML::chars($search); ?>" ничего не найдено. Попробуйте изменить параметры поиска.
                <?php else: ?>
                    В этой папке пока нет файлов. Загрузите свой первый файл, чтобы начать работу.
                <?php endif; ?>
                </p>
            </div>
        </section>
    <?php else: ?>
        <div class="prx-card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="prx-table min-w-full">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">ID</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Имя</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden sm:table-cell">Размер</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden md:table-cell">Загрузил / Дата</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($files as $file): ?>
                            <tr class="group hover:bg-blue-50/50 dark:hover:bg-blue-900/10 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo $file->id; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <a href="<?php echo URL::site('prx212_storage/file/'.$file->id); ?>" class="flex-shrink-0 h-10 w-10 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center mr-4 group-hover:scale-110 transition-transform">
                                            <?php
                                                $ext = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
                                                $icon = 'fa-file';
                                                $color = 'text-gray-400';

                                                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg'])) { $icon = 'fa-file-image'; $color = 'text-blue-500'; }
                                                elseif (in_array($ext, ['pdf'])) { $icon = 'fa-file-pdf'; $color = 'text-rose-500'; }
                                                elseif (in_array($ext, ['doc', 'docx'])) { $icon = 'fa-file-word'; $color = 'text-blue-500'; }
                                                elseif (in_array($ext, ['xls', 'xlsx'])) { $icon = 'fa-file-excel'; $color = 'text-emerald-500'; }
                                                elseif (in_array($ext, ['zip', 'rar', '7z'])) { $icon = 'fa-file-archive'; $color = 'text-amber-600'; }
                                            ?>
                                            <i class="fas <?php echo $icon; ?> <?php echo $color; ?> text-xl"></i>
                                        </a>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-medium text-gray-900 dark:text-white truncate max-w-xs md:max-w-md" title="<?php echo HTML::chars($file->filename); ?>">
                                                <?php echo HTML::chars($file->filename); ?>
                                            </div>
                                            <div class="flex items-center mt-0.5">
                                                <span class="prx-count font-mono uppercase">
                                                    <?php echo HTML::chars($file->extension ?: 'bin'); ?>
                                                </span>
                                                <?php if ($file->is_public): ?>
                                                    <span class="ml-2 text-xs text-emerald-600 dark:text-emerald-400 flex items-center">
                                                        <i class="fas fa-globe-americas mr-1"></i> Общий
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap hidden sm:table-cell">
                                    <div class="text-sm text-gray-600 dark:text-gray-400">
                                        <?php
                                            // Исправление аналогичной ошибки здесь
                                            echo Text::bytes((float)($file->file_size ?? 0), '');
                                        ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap hidden md:table-cell">
                                    <div class="text-sm text-gray-900 dark:text-white mb-0.5">
                                        <?php
                                            if ($file->user_id) {
                                                $uploader = ORM::factory('User', $file->user_id);
                                                echo $uploader->loaded() ? HTML::chars($uploader->username) : 'Система';
                                            } else {
                                                echo 'Аноним';
                                            }
                                        ?>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        <?php echo date('d.m.Y H:i', strtotime($file->created_at)); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button"
                                                onclick="generateTempLink(<?php echo $file->id; ?>)"
                                                class="prx-btn-icon"
                                                title="Создать временную ссылку">
                                            <i class="fas fa-link"></i>
                                        </button>
                                        <a href="<?php echo URL::site('prx212_storage/file/'.$file->id); ?>"
                                           class="prx-btn-icon"
                                           title="Просмотр">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="<?php echo URL::site('prx212_download/file/'.$file->id); ?>"
                                           class="prx-btn-icon"
                                           title="Скачать">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <a href="<?php echo URL::site('/prx212_storage/edit/'.$file->id); ?>"
                                           class="prx-btn-icon"
                                           title="Редактировать">
                                            <i class="fas fa-edit"></i>
                                        </a>

                                        <?php if (in_array('212_storage_admin', $current_user_roles) || (isset($current_user) && $file->user_id == $current_user->id)): ?>
                                            <button type="button"
                                                    data-prx-arm="Удалить?"
                                                    onclick="fetch('<?php echo URL::site('prx212_api/delete/'.$file->id); ?>', {method: 'DELETE'})
                                                        .then(r => r.json())
                                                        .then(d => {
                                                            if (d.success) {
                                                                PRX.toast('Файл удалён', 0, 'ok');
                                                                setTimeout(() => location.reload(), 1000);
                                                            } else {
                                                                PRX.toast(d.error || 'Не удалось удалить файл', 0, 'err');
                                                            }
                                                        })
                                                        .catch(() => PRX.toast('Не удалось удалить файл: сеть не ответила', 0, 'err'));"
                                                    class="prx-btn-icon-danger"
                                                    title="Удалить">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Пагинация -->
            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/30 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Показано <span class="font-medium text-gray-900 dark:text-white"><?php echo count($files); ?></span> из <span class="font-medium text-gray-900 dark:text-white"><?php echo $total_files; ?></span>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($current_page > 1): ?>
                            <a href="?page=<?php echo $current_page - 1; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?>" class="prx-btn-secondary prx-btn-sm">
                                <i class="fas fa-chevron-left mr-1"></i> Назад
                            </a>
                        <?php endif; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="?page=<?php echo $current_page + 1; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?>" class="prx-btn-secondary prx-btn-sm">
                                Вперед <i class="fas fa-chevron-right ml-1"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
async function generateTempLink(fileId) {
    const ttl = prompt("Введите время жизни ссылки в секундах (например, 3600 для 1 часа):", "3600");
    if (ttl === null) return;

    const formData = new FormData();
    formData.append('file_id', fileId);
    formData.append('ttl', ttl);

    try {
        const response = await fetch('<?php echo URL::site('prx212_link/create'); ?>', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            const url = result.data.url;

            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(url);
                PRX.toast("Временная ссылка создана и скопирована в буфер обмена", 0, "ok");
            } else {
                const tempInput = document.createElement("input");
                tempInput.value = url;
                tempInput.style.position = "fixed";
                tempInput.style.left = "-9999px";
                tempInput.style.top = "0";
                document.body.appendChild(tempInput);
                tempInput.focus();
                tempInput.select();
                try {
                    document.execCommand('copy');
                    PRX.toast("Временная ссылка создана и скопирована в буфер обмена", 0, "ok");
                } catch (err) {
                    prompt("Ссылка создана, скопируйте её вручную:", url);
                }
                document.body.removeChild(tempInput);
            }
        } else {
            PRX.toast("Ошибка: " + (result.error || "Не удалось создать ссылку"), 0, "err");
        }
    } catch (error) {
        console.error('Error:', error);
        PRX.toast("Произошла ошибка при выполнении запроса", 0, "err");
    }
}
</script>
