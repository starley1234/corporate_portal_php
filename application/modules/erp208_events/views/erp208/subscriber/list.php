<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="flex justify-between items-center mb-6">
        <a href="<?php echo Route::url('erp208_events', array('action' => 'manage_subscription')); ?>" class="n2j-btn-process">
            <i class="fas fa-plus-circle mr-2"></i> <?php echo __('Add New Subscriber'); ?>
        </a>
    </div>

    <?php if (Session::instance()->get('success_message')): ?>
        <div class="bg-emerald-100 border border-emerald-400 text-emerald-700 px-4 py-3 rounded relative mb-4 dark:bg-emerald-900 dark:border-emerald-700 dark:text-emerald-300" role="alert">
            <strong class="font-bold"><?php echo __('Success!'); ?></strong>
            <span class="block sm:inline"><?php echo Session::instance()->get_once('success_message'); ?></span>
        </div>
    <?php endif; ?>

    <?php if (Session::instance()->get('error_message')): ?>
        <div class="bg-rose-100 border border-rose-400 text-rose-700 px-4 py-3 rounded relative mb-4 dark:bg-rose-900 dark:border-rose-700 dark:text-rose-300" role="alert">
            <strong class="font-bold"><?php echo __('Error!'); ?></strong>
            <span class="block sm:inline"><?php echo Session::instance()->get_once('error_message'); ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-gray-800 shadow overflow-hidden sm:rounded-lg mb-6">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <form action="<?php echo Route::url('erp208_events', array('action' => 'list_subscribers')); ?>" method="get" class="flex flex-col sm:flex-row items-center space-y-3 sm:space-y-0 sm:space-x-4">
                <div class="relative flex-grow w-full sm:w-auto">
                    <input type="text" name="search" value="<?php echo HTML::chars($search); ?>" placeholder="<?php echo __('Search by event name, module, or callback...'); ?>" class="n2j-input w-full leading-5 pl-10">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                </div>
                <button type="submit" class="n2j-btn-process w-full sm:w-auto">
                    <?php echo __('Search'); ?>
                </button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo Route::url('erp208_events', array('action' => 'list_subscribers')); ?>" class="n2j-btn-secondary w-full sm:w-auto">
                        <?php echo __('Reset'); ?>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (count($subscribers) > 0): ?>
            <div class="overflow-x-auto">
                <table class="n2j-table min-w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700">
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
                                        <span class="n2j-badge n2j-badge-ok">
                                            <?php echo __('Yes'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="n2j-badge n2j-badge-err">
                                            <?php echo __('No'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if ($subscriber->is_notification): ?>
                                        <span class="n2j-badge n2j-badge-info">
                                            <?php echo __('Yes'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="n2j-badge n2j-badge-wait">
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
                                    <a href="<?php echo Route::url('erp208_events', array('action' => 'manage_subscription', 'id' => $subscriber->id)); ?>" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 mr-3">
                                        <?php echo __('Edit'); ?>
                                    </a>
                                    <a href="<?php echo Route::url('erp208_events', array('action' => 'delete_subscription', 'id' => $subscriber->id)); ?>" class="text-rose-600 hover:text-rose-900 dark:text-rose-400 dark:hover:text-rose-300" data-n2j-arm="Точно удалить?">
                                        <?php echo __('Delete'); ?>
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
            <div class="p-4 text-center text-gray-500 dark:text-gray-400">
                <?php echo __('No subscribers found.'); ?>
            </div>
        <?php endif; ?>
    </div>
</div>