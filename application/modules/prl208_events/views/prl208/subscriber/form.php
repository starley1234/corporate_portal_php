<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="prl-card">
        <h1 class="prl-h1 mb-6">
            <?php echo HTML::chars($subscriber->loaded() ? __('Edit Subscription') : __('Create New Subscription')); ?>
        </h1>

        <?php if (isset($errors) && !empty($errors)): ?>
            <div class="bg-rose-100 dark:bg-rose-900 border border-rose-400 text-rose-700 dark:text-rose-300 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold"><?php echo __('Validation Error!'); ?></strong>
                <span class="block sm:inline"><?php echo __('Please correct the following issues:'); ?></span>
                <ul class="mt-2 list-disc list-inside">
                    <?php foreach ($errors as $field => $message): ?>
                        <li><?php echo HTML::chars($message); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form action="<?php echo HTML::chars(Route::url('prl208_events', array('action' => 'manage_subscription', 'id' => $subscriber->id))); ?>" method="post" class="space-y-6">
            <div>
                <label for="event_name" class="prl-label mb-1">
                    <?php echo __('Event Name'); ?> <span class="text-rose-500">*</span>
                </label>
                <input type="text" id="event_name" name="event_name" autocomplete="off" required
                       value="<?php echo HTML::chars($subscriber->event_name); ?>"
                       class="prl-input mt-1 w-full"<?php echo isset($errors['event_name']) ? ' aria-invalid="true"' : ''; ?>
                       placeholder="<?php echo __('e.g., order.created'); ?>">
                <?php if (isset($errors['event_name'])): ?>
                    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400"><?php echo HTML::chars($errors['event_name']); ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="module_id" class="prl-label mb-1">
                    <?php echo __('Module ID'); ?>
                </label>
                <select id="module_id" name="module_id"
                        class="prl-input prl-select mt-1 w-full"<?php echo isset($errors['module_id']) ? ' aria-invalid="true"' : ''; ?>>
                    <option value=""><?php echo __('Select a module (optional)'); ?></option>
                    <?php foreach ($available_modules as $module_id_option): ?>
                        <option value="<?php echo HTML::chars($module_id_option); ?>"
                            <?php echo ($subscriber->module_id === $module_id_option) ? 'selected' : ''; ?>>
                            <?php echo HTML::chars($module_id_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['module_id'])): ?>
                    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400"><?php echo HTML::chars($errors['module_id']); ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="callback_method" class="prl-label mb-1">
                    <?php echo __('Callback Method'); ?>
                </label>
                <input type="text" id="callback_method" name="callback_method" autocomplete="off"
                       value="<?php echo HTML::chars($subscriber->callback_method); ?>"
                       class="prl-input mt-1 w-full"<?php echo isset($errors['callback_method']) ? ' aria-invalid="true"' : ''; ?>
                       placeholder="<?php echo __('e.g., Model_ModuleName::methodName (optional)'); ?>">
                <?php if (isset($errors['callback_method'])): ?>
                    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400"><?php echo HTML::chars($errors['callback_method']); ?></p>
                <?php endif; ?>
                <p class="prl-hint">
                    <?php echo __('Specify the class and method that should be called when the event occurs.'); ?>
                </p>
            </div>

            <div>
                <label for="description" class="prl-label mb-1">
                    <?php echo __('Description'); ?>
                </label>
                <textarea id="description" name="description" rows="3"
                          class="prl-textarea mt-1 w-full"<?php echo isset($errors['description']) ? ' aria-invalid="true"' : ''; ?>
                          placeholder="<?php echo __('Enter a description for this subscription'); ?>"><?php echo HTML::chars($subscriber->description); ?></textarea>
                <?php if (isset($errors['description'])): ?>
                    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400"><?php echo HTML::chars($errors['description']); ?></p>
                <?php endif; ?>
            </div>

            <div class="flex flex-col space-y-4">
                <div class="flex items-center">
                    <input id="is_active" name="is_active" type="checkbox" value="1"
                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                           <?php echo ($subscriber->is_active || !$subscriber->loaded()) ? 'checked' : ''; ?>>
                    <label for="is_active" class="ml-2 block text-sm text-gray-900 dark:text-gray-300">
                        <?php echo __('Is Active'); ?>
                    </label>
                </div>

                <div class="flex items-center">
                    <input id="is_notification" name="is_notification" type="checkbox" value="1"
                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                           <?php echo ($subscriber->is_notification) ? 'checked' : ''; ?>>
                    <label for="is_notification" class="ml-2 block text-sm text-gray-900 dark:text-gray-300">
                        <?php echo __('Is Notification'); ?>
                    </label>
                </div>
            </div>

            <div class="flex justify-end space-x-3">
                <a href="<?php echo HTML::chars(Route::url('prl208_events', array('action' => 'list_subscribers'))); ?>"
                   class="prl-btn-secondary">
                    <?php echo __('Cancel'); ?>
                </a>
                <button type="submit"
                        class="prl-btn-process">
                    <?php echo HTML::chars($subscriber->loaded() ? __('Update Subscription') : __('Create Subscription')); ?>
                </button>
            </div>
        </form>

        <div class="mt-10 pt-6 border-t border-gray-200 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Справка по настройке</h3>
            <p class="prl-hint">
                Эта форма связывает системные события с Центром уведомлений (prl597).
                <strong><?php echo __('Module ID'); ?></strong> — идентификатор системы-слушателя.
                <strong><?php echo __('Callback Method'); ?></strong> — PHP-метод для обработки логики.
                <strong><?php echo __('Is Notification'); ?></strong> — разрешает автоматическую передачу события в центр уведомлений prl597 (колокольчик, почта, Telegram, MAX).
            </p>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Optional: Add client-side validation
    });
</script>
