<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="n2j-card">
        <h1 class="n2j-h1 mb-6">
            <?php echo HTML::chars($event->loaded() ? __('Edit Event: :event_name', array(':event_name' => $event->event_name)) : __('Создать новое событие')); ?>
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

        <form action="<?php echo HTML::chars($event->loaded() ? Route::url('erp208_events', array('action' => 'edit_event', 'id' => $event->id)) : Route::url('erp208_events', array('action' => 'create_event'))); ?>" method="post">
            <div class="mb-4">
                <label for="event_name" class="n2j-label mb-2">
                    <?php echo __('Event Name'); ?> <span class="text-rose-500">*</span>
                </label>
                <input type="text"
                       id="event_name"
                       name="event_name"
                       class="n2j-input w-full"<?php echo isset($errors['event_name']) ? ' aria-invalid="true"' : ''; ?>
                       value="<?php echo HTML::chars(Arr::get($_POST, 'event_name', $event->event_name)); ?>"
                       placeholder="<?php echo __('e.g., erp502.task_done'); ?>"
                       required>
                <?php if (isset($errors['event_name'])): ?>
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400"><?php echo HTML::chars($errors['event_name']); ?></p>
                <?php endif; ?>
            </div>

            <div class="mb-6">
                <label for="payload" class="n2j-label mb-2">
                    <?php echo __('Event Payload (JSON)'); ?>
                </label>
                <textarea id="payload"
                          name="payload"
                          rows="8"
                          class="n2j-textarea w-full"<?php echo isset($errors['payload']) ? ' aria-invalid="true"' : ''; ?>
                          placeholder='<?php echo HTML::chars(__('e.g., {"task_id": 123, "status": "completed"}')); ?>'><?php echo HTML::chars(Arr::get($_POST, 'payload', $event->payload)); ?></textarea>
                <?php if (isset($errors['payload'])): ?>
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400"><?php echo HTML::chars($errors['payload']); ?></p>
                <?php endif; ?>
                <p class="n2j-hint mt-2">
                    <?php echo __('Enter event data in JSON format. This data will be passed to event listeners.'); ?>
                </p>
            </div>

            <div class="flex justify-end space-x-3">
                <a href="<?php echo HTML::chars(Route::url('erp208_events', array('action' => 'list_events'))); ?>"
                   class="n2j-btn-secondary">
                    <?php echo __('Cancel'); ?>
                </a>
                <button type="submit"
                        class="n2j-btn-process">
                    <i class="fas fa-save mr-2"></i>
                    <?php echo HTML::chars($event->loaded() ? __('Save Changes') : __('Create & Publish Event')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const payloadTextarea = document.getElementById('payload');

        // Функция для форматирования JSON
        function formatJson() {
            try {
                const json = JSON.parse(payloadTextarea.value);
                payloadTextarea.value = JSON.stringify(json, null, 2);
            } catch (e) {
                // Если JSON невалиден, оставляем как есть
                console.warn('Invalid JSON in payload:', e);
            }
        }

        // Форматируем JSON при загрузке, если он есть
        if (payloadTextarea.value.trim() !== '') {
            formatJson();
        }

        // Добавляем обработчик для форматирования при потере фокуса
        payloadTextarea.addEventListener('blur', formatJson);
    });
</script>