<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-card">
    <h3 class="n2j-h3 mb-4">
        <?php echo __('Publish New Event'); ?>
    </h3>

    <?php if (!empty($errors)): ?>
        <div class="bg-rose-100 dark:bg-rose-900 border border-rose-400 text-rose-700 dark:text-rose-300 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold"><?php echo __('Error!'); ?></strong>
            <span class="block sm:inline">
                <?php foreach ($errors as $field => $message): ?>
                    <?php echo HTML::chars($message); ?><br>
                <?php endforeach; ?>
            </span>
        </div>
    <?php endif; ?>

    <form action="<?php echo URL::site('erp208_events/widget/event_publisher_form'); ?>" method="post" class="space-y-4">
        <div>
            <label for="event_name" class="n2j-label">
                <?php echo __('Event Name'); ?> <span class="text-rose-500">*</span>
            </label>
            <input
                type="text"
                id="event_name"
                name="event_name"
                list="event_name_suggestions"
                value="<?php echo HTML::chars(Arr::get($post_data, 'event_name', '')); ?>"
                class="n2j-input mt-1 w-full"
                placeholder="например, erp502.task_done"
                required
            >
            <?php if (!empty($event_name_suggestions)): ?>
                <datalist id="event_name_suggestions">
                    <?php foreach ($event_name_suggestions as $suggestion): ?>
                        <option value="<?php echo HTML::chars($suggestion); ?>">
                    <?php endforeach; ?>
                </datalist>
            <?php endif; ?>
            <p class="n2j-hint">
                <?php echo __('A unique identifier for the event (e.g., moduleID.action).'); ?>
            </p>
        </div>

        <div>
            <label for="payload" class="n2j-label">
                <?php echo __('Payload (JSON)'); ?>
            </label>
            <textarea
                id="payload"
                name="payload"
                rows="5"
                class="n2j-textarea mt-1 w-full"
                placeholder='<?php echo HTML::chars('{"task_id": 123, "status": "completed"}'); ?>'
            ><?php echo HTML::chars(Arr::get($post_data, 'payload', '')); ?></textarea>
            <p class="n2j-hint">
                <?php echo __('Optional data associated with the event, in JSON format.'); ?>
            </p>
        </div>

        <div class="flex justify-end">
            <button
                type="submit"
                class="n2j-btn-process"
            >
                <i class="fas fa-paper-plane mr-2"></i>
                <?php echo __('Publish Event'); ?>
            </button>
        </div>
    </form>
</div>