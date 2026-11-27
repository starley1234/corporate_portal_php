<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Панель фильтров списка: GET-форма в карточке, одна сетка на все страницы.
 *
 *   <?php echo View::factory('partials/filters', [
 *       'id'     => 'employees',                       // префикс id полей
 *       'action' => '/prx300/admin/employees',
 *       'fields' => [
 *           ['type' => 'search', 'name' => 'q', 'label' => 'Поиск', 'placeholder' => 'ФИО, email', 'value' => $filters['q']],
 *           ['type' => 'select', 'name' => 'status', 'label' => 'Статус', 'value' => $filters['status'],
 *            'options' => ['' => 'Любой', 'active' => 'Работает']],
 *           ['type' => 'checkbox', 'name' => 'unlinked', 'label' => 'Без аккаунта', 'checked' => (bool) $filters['unlinked']],
 *       ],
 *       'submit' => 'Применить',
 *       'reset'  => '/prx300/admin/employees',         // необязательно
 *   ]); ?>
 *
 * `type`: search|text|date|select|checkbox. Форма всегда GET: фильтры не меняют
 * состояние и должны быть ссылкой, которой можно поделиться.
 */
$fl_id = isset($id) ? (string) $id : 'fl';
$fl_action = isset($action) ? (string) $action : '';
$fl_fields = array_values((array) ($fields ?? []));
$fl_submit = isset($submit) ? (string) $submit : 'Применить';
$fl_reset = isset($reset) ? (string) $reset : '';
$fl_columns = [
    1 => 'md:grid-cols-1', 2 => 'md:grid-cols-2', 3 => 'md:grid-cols-3',
    4 => 'md:grid-cols-2 xl:grid-cols-4', 5 => 'md:grid-cols-2 xl:grid-cols-5',
];
$fl_grid = $fl_columns[count($fl_fields)] ?? 'md:grid-cols-2 xl:grid-cols-4';
?>
<form method="get" action="<?php echo HTML::chars($fl_action); ?>" class="prx-card mt-5">
    <div class="grid gap-4 <?php echo $fl_grid; ?>">
<?php foreach ($fl_fields as $fl_field): ?>
<?php
    $fl_type = $fl_field['type'] ?? 'text';
    $fl_name = (string) ($fl_field['name'] ?? '');
    $fl_label = (string) ($fl_field['label'] ?? '');
    $fl_value = $fl_field['value'] ?? '';
    $fl_field_id = $fl_id.'-'.$fl_name;
?>
<?php if ($fl_type === 'checkbox'): ?>
        <label class="flex min-h-[44px] items-center gap-2 self-end text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="<?php echo HTML::chars($fl_name); ?>" value="1"<?php echo ! empty($fl_field['checked']) ? ' checked' : ''; ?> class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
            <?php echo HTML::chars($fl_label); ?>
        </label>
<?php else: ?>
        <div>
            <label class="prx-label" for="<?php echo HTML::chars($fl_field_id); ?>"><?php echo HTML::chars($fl_label); ?></label>
    <?php if ($fl_type === 'select'): ?>
            <select id="<?php echo HTML::chars($fl_field_id); ?>" name="<?php echo HTML::chars($fl_name); ?>" class="prx-select mt-1 w-full">
        <?php foreach ((array) ($fl_field['options'] ?? []) as $fl_option_value => $fl_option_label): ?>
                <option value="<?php echo HTML::chars((string) $fl_option_value); ?>"<?php echo (string) $fl_value === (string) $fl_option_value ? ' selected' : ''; ?>><?php echo HTML::chars((string) $fl_option_label); ?></option>
        <?php endforeach; ?>
            </select>
    <?php else: ?>
            <input id="<?php echo HTML::chars($fl_field_id); ?>" name="<?php echo HTML::chars($fl_name); ?>" type="<?php echo in_array($fl_type, ['search', 'date'], TRUE) ? $fl_type : 'text'; ?>" value="<?php echo HTML::chars((string) $fl_value); ?>"<?php if ( ! empty($fl_field['placeholder'])): ?> placeholder="<?php echo HTML::chars((string) $fl_field['placeholder']); ?>"<?php endif; ?> class="prx-input mt-1 w-full">
    <?php endif; ?>
        </div>
<?php endif; ?>
<?php endforeach; ?>
        <div class="flex items-end gap-2">
            <button class="prx-btn-process" type="submit"><i class="fas fa-filter"></i><?php echo HTML::chars($fl_submit); ?></button>
<?php if ($fl_reset !== ''): ?>
            <a class="prx-btn-secondary" href="<?php echo HTML::chars($fl_reset); ?>">Сбросить</a>
<?php endif; ?>
        </div>
    </div>
</form>
