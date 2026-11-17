<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Платформа'], ['Шина событий']],
        'title'  => 'Шина событий',
        'pill'   => ['prx208 · события', 'fa-tower-broadcast'],
        'lead'   => 'Состояние шины, активные подписки и последние системные события.',
        'actions' => [
            ['label' => 'Опубликовать событие', 'href' => '/prx208_events/create_event', 'icon' => 'fa-circle-plus', 'role' => 'cta'],
        ],
    ]); ?>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        
        <!-- Левая колонка: Статистика и быстрые действия -->
        <div class="lg:col-span-1 space-y-6">
            <div class="prx-card">
                <h2 class="prx-h3 mb-4 flex items-center">
                    <i class="fas fa-chart-line mr-2 text-blue-500"></i> Состояние шины
                </h2>
                <div class="space-y-4">
                    <div class="flex justify-between items-center p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <span class="text-sm text-gray-500 dark:text-gray-400">Всего событий</span>
                        <span class="text-xl font-bold text-gray-900 dark:text-white"><?php echo $total_events ?? 0; ?></span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <span class="text-sm text-gray-500 dark:text-gray-400">Активных подписок</span>
                        <span class="text-xl font-bold text-gray-900 dark:text-white"><?php echo $active_subscribers ?? 0; ?></span>
                    </div>
                </div>
            </div>

            <div class="prx-card">
                <h2 class="prx-h3 mb-4 flex items-center">
                    <i class="fas fa-plug mr-2 text-amber-500"></i> Быстрые ссылки
                </h2>
                <nav class="space-y-2">
                    <a href="/prx208_events/list_subscribers" class="flex items-center p-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-md transition-colors">
                        <i class="fas fa-users-cog w-6"></i> Управление подписчиками
                    </a>
                    <a href="/prx208_events/list_events" class="flex items-center p-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-md transition-colors">
                        <i class="fas fa-list-ul w-6"></i> Полный лог событий
                    </a>
                    <a href="/prx208_events/settings" class="flex items-center p-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-md transition-colors">
                        <i class="fas fa-cog w-6"></i> Настройки модуля
                    </a>
                </nav>
            </div>
        </div>

        <!-- Правая колонка: Последние события -->
        <div class="lg:col-span-2">
            <section class="prx-panel">
                <div class="prx-panel-head">
                    <div>
                        <h2 class="prx-h3"><i class="fas fa-list-ul mr-2 text-blue-500"></i>Последние события в системе</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Десять последних зафиксированных событий.</p>
                    </div>
                    <button type="button" onclick="location.reload()" class="prx-btn-icon" title="Обновить" aria-label="Обновить">
                        <i class="fas fa-arrows-rotate"></i>
                    </button>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="prx-table border-collapse">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-900/50 text-xs uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-6 py-3 font-medium">Событие</th>
                                <th class="px-6 py-3 font-medium">Инициатор</th>
                                <th class="px-6 py-3 font-medium">Дата и время</th>
                                <th class="px-6 py-3 font-medium text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php if (!empty($latest_events)): ?>
                                <?php foreach ($latest_events as $event): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                        <td class="px-6 py-4">
                                            <span class="font-mono text-sm text-blue-600 dark:text-blue-400"><?php echo HTML::chars($event->event_name); ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-sm">
                                            <?php echo $event->initiator_id ? 'Пользователь № '.(int) $event->initiator_id : '<span class="text-gray-400 dark:text-gray-500">Система</span>'; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            <?php echo date('d.m.Y H:i:s', strtotime($event->created_at)); ?>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <button onclick="showEventDetails(<?php echo $event->id; ?>)" class="prx-btn-icon">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-4">
                                        <div class="prx-empty">
                                            <span class="prx-empty-icon"><i class="fas fa-tower-broadcast"></i></span>
                                            <p class="prx-empty-text">Событий пока не зафиксировано.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="prx-panel-foot">
                    <a href="/prx208_events/list_events" class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                        Смотреть всю историю <i class="fas fa-arrow-right ml-1 text-xs"></i>
                    </a>
                </div>
            </section>
        </div>
    </div>
</div>

<!-- Модальное окно для деталей (пример реализации) -->
<div id="eventModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-xs" aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="prx-modal-box p-0 relative inline-block align-bottom text-left overflow-hidden sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <h3 class="prx-h3" id="modal-title">Детали события</h3>
                <div class="mt-4">
                    <pre id="eventPayload" class="text-xs bg-gray-100 dark:bg-gray-900 p-4 rounded-lg overflow-x-auto whitespace-nowrap text-gray-700 dark:text-gray-300"></pre>
                </div>
            </div>
            <div class="bg-gray-50 dark:bg-gray-700/50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" onclick="closeModal()" class="prx-btn-secondary mt-3 w-full sm:mt-0 sm:ml-3 sm:w-auto">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<script>
function showEventDetails(id) {
    // В реальном приложении здесь будет AJAX запрос
    document.getElementById('eventPayload').innerText = "// Загрузка данных для ID: " + id + "...\n{\n  \"status\": \"success\",\n  \"module\": \"prx208\",\n  \"action\": \"view_details\"\n}";
    document.getElementById('eventModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('eventModal').classList.add('hidden');
}
</script>