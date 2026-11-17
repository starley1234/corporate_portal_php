<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between mb-6">
        <h1 class="prx-h1"><?= __('All Events') ?></h1>
        <a href="<?= Route::url('prx208_events', array('action' => 'create_event')) ?>" class="prx-btn-cta">
            <i class="fas fa-plus-circle mr-2"></i>
            <?= __('Publish New Event') ?>
        </a>
    </div>

    <div class="prx-card mb-6">
        <form action="<?= Route::url('prx208_events', array('action' => 'list_events')) ?>" method="get" class="flex flex-col sm:flex-row items-center gap-4">
            <div class="relative flex-grow w-full sm:w-auto">
                <input type="text" name="search" id="search" class="prx-input w-full leading-5 pl-10" placeholder="<?= __('Search by event name...') ?>" value="<?= HTML::chars($search) ?>">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400"></i>
                </div>
            </div>
            <button type="submit" class="prx-btn-process w-full sm:w-auto">
                <?= __('Search') ?>
            </button>
            <?php if (!empty($search)): ?>
                <a href="<?= Route::url('prx208_events', array('action' => 'list_events')) ?>" class="prx-btn-secondary w-full sm:w-auto text-center">
                    <?= __('Reset') ?>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (count($events) > 0): ?>
        <div class="prx-card p-0 overflow-x-auto">
            <table class="prx-table min-w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                            <?= __('ID') ?>
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                            <?= __('Event Name') ?>
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                            <?= __('Payload Summary') ?>
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                            <?= __('Created At') ?>
                        </th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                            <?= __('Actions') ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                <?= HTML::chars($event->id) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                <?= HTML::chars($event->event_name) ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300 max-w-xs truncate">
                                <?php
                                    $payload_data = json_decode($event->payload, true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($payload_data)) {
                                        echo HTML::chars(substr(json_encode($payload_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0, 100)) . (strlen(json_encode($payload_data)) > 100 ? '...' : '');
                                    } else {
                                        echo HTML::chars(substr($event->payload, 0, 100)) . (strlen($event->payload) > 100 ? '...' : '');
                                    }
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                <?= HTML::chars(date('Y-m-d H:i:s', strtotime($event->created_at))) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="<?= Route::url('prx208_events', array('action' => 'view_event', 'id' => $event->id)) ?>" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 mr-3" title="<?= __('View Details') ?>">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="<?= Route::url('prx208_events', array('action' => 'edit_event', 'id' => $event->id)) ?>" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 mr-3" title="<?= __('Edit Event') ?>">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?= Route::url('prx208_events', array('action' => 'delete_event', 'id' => $event->id)) ?>" class="text-rose-600 hover:text-rose-900 dark:text-rose-400 dark:hover:text-rose-300" title="<?= __('Delete Event') ?>" data-prx-arm="Удалить?">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pagination->total_pages > 1): ?>
            <div class="mt-6">
                <?= $pagination->render() ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="prx-card text-center text-gray-700 dark:text-gray-300">
            <p class="text-lg mb-4"><?= __('No events found.') ?></p>
            <p><?= __('You can publish a new event using the button above.') ?></p>
        </div>
    <?php endif; ?>
</div>

<?php if (Session::instance()->get('success_message')): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            PRX.toast(<?php echo json_encode((string) Session::instance()->get_once('success_message'), JSON_UNESCAPED_UNICODE); ?>, 0, 'ok');
        });
    </script>
<?php endif; ?>

<?php if (Session::instance()->get('error_message')): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            PRX.toast(<?php echo json_encode((string) Session::instance()->get_once('error_message'), JSON_UNESCAPED_UNICODE); ?>, 0, 'err');
        });
    </script>
<?php endif; ?>