<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Стандартное flash-сообщение страницы (успех или ошибка).
 *
 *   <?php echo View::factory('partials/flash', [
 *       'flash' => ['type' => 'ok', 'text' => 'Сохранено'],
 *   ]); ?>
 *
 * Один шаблон вместо отдельного `_flash.php` в каждом модуле. `type`: ok|err.
 * Можно передать список: `['type' => 'err', 'text' => [...], 'list' => TRUE]`.
 */
$fk_type = ($flash['type'] ?? '') === 'err' ? 'err' : 'ok';
$fk_text = $flash['text'] ?? '';
$fk_items = (array) ($flash['items'] ?? []);
$fk_class = 'prx-flash prx-flash-'.$fk_type;
?>
<?php if ($fk_text !== '' OR $fk_items): ?>
<div class="<?php echo $fk_class; ?> mt-4 block" role="status">
    <?php if ($fk_text !== ''): ?>
        <?php echo HTML::chars((string) $fk_text); ?>
    <?php endif; ?>
    <?php if ($fk_items): ?>
        <ul class="mt-2 list-disc pl-5">
            <?php foreach ($fk_items as $fk_item): ?>
                <li><?php echo HTML::chars((string) $fk_item); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php endif; ?>
