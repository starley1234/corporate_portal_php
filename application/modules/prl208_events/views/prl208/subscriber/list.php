<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php $subscriber_create_url = Route::url('prl208_events', array('action' => 'manage_subscription')); ?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php if (Session::instance()->get('success_message')): ?>
        <div class="prl-flash prl-flash-ok mb-5" role="status">
            <i class="fas fa-circle-check"></i>
            <span><?php echo HTML::chars(Session::instance()->get_once('success_message')); ?></span>
        </div>
    <?php endif; ?>

    <?php if (Session::instance()->get('error_message')): ?>
        <div class="prl-flash prl-flash-err mb-5" role="alert">
            <i class="fas fa-triangle-exclamation"></i>
            <span><?php echo HTML::chars(Session::instance()->get_once('error_message')); ?></span>
        </div>
    <?php endif; ?>

    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Шина событий', '/prl208_events'], ['Подписчики']],
        'title'  => 'Подписчики событий',
        'pill'   => ['prl208 · подписки', 'fa-users-gear'],
        'lead'   => 'Обработчики и уведомления, подписанные на события модулей портала.',
        'actions' => [
            ['label' => 'Добавить подписчика', 'href' => $subscriber_create_url, 'icon' => 'fa-circle-plus', 'role' => 'cta'],
        ],
    ]); ?>

    <section class="prl-panel mt-6">
        <div class="prl-panel-head block">
            <form action="<?php echo Route::url('prl208_events', array('action' => 'list_subscribers')); ?>" method="get" class="flex flex-col sm:flex-row items-center space-y-3 sm:space-y-0 sm:space-x-4">
                <div class="relative flex-grow w-full sm:w-auto">
                    <input type="text" name="search" value="<?php echo HTML::chars($search); ?>" placeholder="<?php echo __('Search by event name, module, or callback...'); ?>" class="prl-input w-full leading-5 pl-10">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                </div>
                <button type="submit" class="prl-btn-process w-full sm:w-auto">
                    <?php echo __('Search'); ?>
                </button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo Route::url('prl208_events', array('action' => 'list_subscribers')); ?>" class="prl-btn-secondary w-full sm:w-auto">
                        <?php echo __('Reset'); ?>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (count($subscribers) > 0): ?>
            <div class="overflow-x-auto">
                <table class="prl-table min-w-full">
                    <thead>
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                <?php echo __('Event Name'); ?>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                <?php echo __('Module ID'); ?>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                <?php echo __('Callback Method'); ?>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                <?php echo __('Active'); ?>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                <?php echo __('Notification'); ?>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                <?php echo __('Description'); ?>
                            </th>
                            <th scope="col" class="relative px-6 py-3">
                                <span class="sr-only"><?php echo __('Actions'); ?></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($subscribers as $subscriber): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                    <?php echo HTML::chars($subscriber->event_name); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                                    <?php echo HTML::chars($subscriber->module_id); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                                    <?php echo HTML::chars($subscriber->callback_method); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if ($subscriber->is_active): ?>
                                        <span class="prl-badge prl-badge-ok"><span class="prl-dot"></span>
                                            <?php echo __('Yes'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="prl-badge prl-badge-wait"><span class="prl-dot"></span>
                                            <?php echo __('No'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if ($subscriber->is_notification): ?>
                                        <span class="prl-badge prl-badge-info"><span class="prl-dot"></span>
                                            <?php echo __('Yes'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="prl-badge prl-badge-wait"><span class="prl-dot"></span>
                                            <?php echo __('No'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300">
                                    <?php 
                                        $desc = strip_tags((string) $subscriber->description);
                                        $words = explode(' ', $desc);
                                        echo HTML::chars(implode(' ', array_slice($words, 0, 12))) . (count($words) > 12 ? '...' : '');
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="<?php echo Route::url('prl208_events', array('action' => 'manage_subscription', 'id' => $subscriber->id)); ?>" class="prl-btn-icon" title="<?php echo __('Edit'); ?>" aria-label="<?php echo __('Edit'); ?>">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="<?php echo Route::url('prl208_events', array('action' => 'delete_subscription', 'id' => $subscriber->id)); ?>" class="prl-btn-icon-danger" title="<?php echo __('Delete'); ?>" aria-label="<?php echo __('Delete'); ?>" data-prl-arm="Точно удалить?">
                                        <i class="fas fa-trash-can"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pagination->total_pages > 1): ?>
                <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                    <?php echo $pagination; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="prl-empty">
                <span class="prl-empty-icon"><i class="fas fa-users-gear"></i></span>
                <p class="prl-empty-text"><?php echo __('No subscribers found.'); ?></p>
            </div>
        <?php endif; ?>
    </section>
</div>