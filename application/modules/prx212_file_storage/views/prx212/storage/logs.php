<?php defined('SYSPATH') or die('No direct script access.');
$storage_url = URL::site('prx212_file_storage');
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Хранилище', $storage_url], ['Журнал доступа']],
        'title'  => 'Журнал доступа к файлам',
        'pill'   => ['prx212 · хранилище', 'fa-clock-rotate-left'],
        'lead'   => 'Кто и когда открывал, загружал, скачивал или удалял файлы.',
        'actions' => [
            ['label' => 'Назад в хранилище', 'href' => $storage_url, 'icon' => 'fa-arrow-left', 'role' => 'secondary'],
        ],
    ]); ?>

    <!-- Статистика логов -->
    <div class="mt-6 mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="prx-card">
            <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">Всего записей</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($total, 0, '.', ' '); ?></div>
        </div>
        <div class="prx-card">
            <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">Страница</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $current_page; ?> / <?php echo $total_pages; ?></div>
        </div>
        <div class="prx-card">
            <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">Записей на страницу</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $per_page; ?></div>
        </div>
    </div>

    <!-- Таблица логов -->
    <section class="prx-panel">
        <div class="overflow-x-auto">
            <table class="prx-table min-w-full">
                <thead>
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Дата и время</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Пользователь</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Действие</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Файл / Объект</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">IP-адрес</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4">
                                <div class="prx-empty">
                                    <span class="prx-empty-icon"><i class="fas fa-clock-rotate-left"></i></span>
                                    <p class="prx-empty-text">Записи в журнале отсутствуют.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/20 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-mono">
                                    <?php echo date('d.m.Y H:i:s', strtotime($log->created_at)); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="h-7 w-7 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400 mr-2 text-xs font-bold">
                                            <?php echo HTML::chars(mb_strtoupper(mb_substr((string) ($log->user_name ?? 'S'), 0, 1))); ?>
                                        </div>
                                        <div class="text-sm text-gray-900 dark:text-white">
                                            <?php echo HTML::chars($log->user_name ?? 'Система'); ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="prx-count font-mono"><?php echo HTML::chars($log->action); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900 dark:text-white truncate max-w-xs" title="<?php echo HTML::chars($log->details); ?>">
                                        <?php echo HTML::chars($log->details); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono">
                                    <?php echo HTML::chars($log->ip_address); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Пагинация -->
        <?php if ($total_pages > 1): ?>
            <div class="prx-panel-foot flex flex-wrap items-center justify-between gap-3">
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Показано <span class="font-medium text-gray-900 dark:text-white"><?php echo count($logs); ?></span> из <span class="font-medium text-gray-900 dark:text-white"><?php echo $total; ?></span> записей
                </div>
                <div class="flex gap-2">
                    <?php if ($current_page > 1): ?>
                        <a href="?page=<?php echo $current_page - 1; ?>" class="prx-btn-secondary">
                            <i class="fas fa-chevron-left mr-2"></i>Предыдущая
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?page=<?php echo $current_page + 1; ?>" class="prx-btn-secondary">
                            Следующая <i class="fas fa-chevron-right ml-2"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>