<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Форма подписки на событие: имя, модуль, callback, флаги.
 *
 * Переменные: $subscriber (ORM Prx208_Subscriber), $errors, $csrf, $db_subscribers_enabled.
 */
$field_error = function (string $name) use ($errors): string {
    return isset($errors[$name]) ? '<p class="prx-hint mt-1 text-rose-600 dark:text-rose-400">'.HTML::chars((string) $errors[$name]).'</p>' : '';
};
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Администрирование', '/admin'], ['События', '/prx208_events'], ['Подписки', '/prx208_events/list_subscribers'], [$subscriber->loaded() ? 'Подписка #'.(int) $subscriber->id : 'Новая подписка']],
        'title'   => $subscriber->loaded() ? 'Подписка #'.(int) $subscriber->id : 'Новая подписка',
        'pill'    => ['prx208 · подписки', 'fa-user-tag'],
        'lead'    => 'Кто реагирует на событие: callback в коде или мост в уведомления. Загрузка подписок из БД включается оператором после аудита.',
    ]); ?>

    <?php if ( ! $db_subscribers_enabled): ?>
        <div class="prx-flash prx-flash-warn mt-5" role="status"><i class="fas fa-triangle-exclamation"></i><span>Загрузка подписок из базы выключена (PRX208_DB_SUBSCRIBERS_ENABLED). Запись сохранится, но обработчик не запустится, пока оператор не включит настройку.</span></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="prx-flash prx-flash-err mt-5" role="alert"><i class="fas fa-circle-exclamation"></i><span>Проверьте поля: <?php echo HTML::chars(implode('; ', array_map('strval', $errors))); ?></span></div>
    <?php endif; ?>

    <form method="post" action="/prx208_events/manage_subscription/<?php echo (int) $subscriber->id; ?>" class="prx-card mt-5 max-w-3xl space-y-5">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

        <div>
            <label for="sub-event" class="prx-label">Имя события <span class="text-rose-500">*</span></label>
            <input id="sub-event" name="event_name" type="text" required class="prx-input mt-1.5 w-full font-mono" placeholder="prx120.job_done"
                   value="<?php echo HTML::chars((string) $subscriber->event_name); ?>"<?php echo isset($errors['event_name']) ? ' aria-invalid="true"' : ''; ?>>
            <p class="prx-hint">Формат <code>prxNNN.сущность.действие</code> — как в <code>Event::publish()</code>; иначе событие никто не опубликует.</p>
            <?php echo $field_error('event_name'); ?>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <label for="sub-module" class="prx-label">Модуль-подписчик</label>
                <input id="sub-module" name="module_id" type="text" class="prx-input mt-1.5 w-full" placeholder="prx597_notifications"
                       value="<?php echo HTML::chars((string) $subscriber->module_id); ?>">
                <?php echo $field_error('module_id'); ?>
            </div>
            <div>
                <label for="sub-callback" class="prx-label">Callback в коде</label>
                <input id="sub-callback" name="callback_method" type="text" class="prx-input mt-1.5 w-full font-mono" placeholder="Service_Prx597_Notify::on_event"
                       value="<?php echo HTML::chars((string) $subscriber->callback_method); ?>">
                <p class="prx-hint">Статический метод <code>Class::method</code>. Пусто — только уведомление.</p>
                <?php echo $field_error('callback_method'); ?>
            </div>
        </div>

        <div>
            <label for="sub-desc" class="prx-label">Зачем</label>
            <input id="sub-desc" name="description" type="text" class="prx-input mt-1.5 w-full" placeholder="Уведомить владельца задачи о провале"
                   value="<?php echo HTML::chars((string) $subscriber->description); ?>">
            <?php echo $field_error('description'); ?>
        </div>

        <div class="flex flex-wrap gap-6">
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="is_active" value="1" class="h-4 w-4 accent-emerald-600"<?php /* новая подписка активна по умолчанию — как в API */ echo ($subscriber->is_active ?? TRUE) ? ' checked' : ''; ?>>
                Активна
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="is_notification" value="1" class="h-4 w-4 accent-emerald-600"<?php echo $subscriber->is_notification ? ' checked' : ''; ?>>
                Уведомление (мост в prx597)
            </label>
        </div>

        <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-700/60">
            <button type="submit" class="prx-btn-cta"><i class="fas fa-check"></i><span>Сохранить</span></button>
            <a href="/prx208_events/list_subscribers" class="prx-btn-secondary">Отмена</a>
        </div>
    </form>
</div>
