<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="n2j-card mb-6">
        <div class="flex items-center justify-between mb-4 border-b border-gray-200 dark:border-gray-700 pb-4">
            <h1 class="n2j-h1">
                <?php echo HTML::chars($event->event_name); ?>
            </h1>
            <div class="flex space-x-2">
                <a href="<?php echo Route::url('erp208_events', array('action' => 'edit_event', 'id' => $event->id)); ?>"
                   class="n2j-btn-process">
                    <i class="fas fa-edit mr-2"></i> <?php echo __('Edit'); ?>
                </a>
                <a href="<?php echo Route::url('erp208_events', array('action' => 'delete_event', 'id' => $event->id)); ?>"
                   class="n2j-btn-danger"
                   data-n2j-confirm="<?php echo HTML::chars(__('Are you sure you want to delete this event?')); ?>" data-n2j-title="Удалить событие?">
                    <i class="fas fa-trash-alt mr-2"></i> <?php echo __('Delete'); ?>
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <p class="text-gray-500 dark:text-gray-400 text-sm mb-1"><?php echo __('Event ID'); ?>:</p>
                <p class="text-gray-900 dark:text-gray-100 text-lg font-semibold"><?php echo HTML::chars($event->id); ?></p>
            </div>
            <div>
                <p class="text-gray-500 dark:text-gray-400 text-sm mb-1"><?php echo __('Event Name'); ?>:</p>
                <p class="text-gray-900 dark:text-gray-100 text-lg font-semibold"><?php echo HTML::chars($event->event_name); ?></p>
            </div>
            <div>
                <p class="text-gray-500 dark:text-gray-400 text-sm mb-1"><?php echo __('Published At'); ?>:</p>
                <p class="text-gray-900 dark:text-gray-100 text-lg font-semibold"><?php echo HTML::chars(date('Y-m-d H:i:s', strtotime($event->created_at))); ?></p>
            </div>
        </div>

        <div class="mt-6">
            <h3 class="n2j-h3 mb-3"><?php echo __('Event Payload'); ?>:</h3>
            <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded-md overflow-x-auto">
                <pre class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap"><?php
                    // Попытка декодировать JSON для красивого вывода
                    $payload_decoded = json_decode($event->payload, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        echo HTML::chars(json_encode($payload_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    } else {
                        echo HTML::chars($event->payload);
                    }
                ?></pre>
            </div>
        </div>

        <div class="mt-8 text-center">
            <a href="<?php echo Route::url('erp208_events', array('action' => 'list_events')); ?>"
               class="n2j-btn-secondary">
                <i class="fas fa-arrow-left mr-3"></i> <?php echo __('Back to Event List'); ?>
            </a>
        </div>
    </div>
</div>

<?php
// Вывод сообщений об успехе/ошибке, если они есть в сессии
if (Session::instance()->get('success_message')) {
    echo '<script>document.addEventListener("DOMContentLoaded", function() { N2J.toast("' . addslashes(Session::instance()->get_once('success_message')) . '", 0, "ok"); });</script>';
}
if (Session::instance()->get('error_message')) {
    echo '<script>document.addEventListener("DOMContentLoaded", function() { N2J.toast("' . addslashes(Session::instance()->get_once('error_message')) . '", 0, "err"); });</script>';
}
?>