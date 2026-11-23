<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Пустое состояние списка: иконка, заголовок, пояснение, действия.
 *
 *   <?php echo View::factory('partials/empty_state', [
 *       'icon'  => 'fas fa-inbox',
 *       'title' => 'Заявок пока нет',
 *       'text'  => 'Создайте первую заявку — она появится в этом списке.',
 *       'actions' => [['label' => 'Создать', 'href' => '/x/new', 'role' => 'cta']],
 *   ]); ?>
 *
 * Без `title` выводится только иконка и текст. role: cta|process|secondary|danger.
 */
$es_icon = isset($icon) ? (string) $icon : 'fas fa-inbox';
$es_title = isset($title) ? (string) $title : '';
$es_text = isset($text) ? (string) $text : '';
$es_actions = isset($actions) ? (array) $actions : [];
$es_roles = [
    'cta' => 'prx-btn-cta',
    'process' => 'prx-btn-process',
    'secondary' => 'prx-btn-secondary',
    'danger' => 'prx-btn-danger',
];
?>
<div class="prx-empty">
    <div class="prx-empty-icon"><i class="<?php echo HTML::chars($es_icon); ?>" aria-hidden="true"></i></div>
<?php if ($es_title !== ''): ?>
    <h2 class="prx-empty-title mt-4"><?php echo HTML::chars($es_title); ?></h2>
<?php endif; ?>
<?php if ($es_text !== ''): ?>
    <p class="prx-empty-text mt-2"><?php echo HTML::chars($es_text); ?></p>
<?php endif; ?>
<?php if ($es_actions): ?>
    <div class="mt-5 flex flex-wrap justify-center gap-2">
        <?php foreach ($es_actions as $es_action): ?>
            <?php $es_role = $es_roles[$es_action['role'] ?? ''] ?? 'prx-btn-secondary'; ?>
            <?php if ( ! empty($es_action['href'])): ?>
                <a href="<?php echo HTML::chars($es_action['href']); ?>" class="<?php echo $es_role; ?> prx-btn-sm"><?php echo HTML::chars($es_action['label']); ?></a>
            <?php else: ?>
                <button type="button" class="<?php echo $es_role; ?> prx-btn-sm"><?php echo HTML::chars($es_action['label']); ?></button>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
