<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php [$label, $icon, $confirm] = $action; ?>
<form method="post" action="/portal-plan/approve" data-portal-plan-action>
    <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
    <input type="hidden" name="module" value="<?php echo HTML::chars($module); ?>">
    <input type="hidden" name="state" value="<?php echo HTML::chars($next); ?>">
    <input type="hidden" name="return_state" value="<?php echo HTML::chars($return_state); ?>">
    <button type="submit" class="<?php echo $compact ? ($confirm ? 'prx-btn-icon-danger' : 'prx-btn-icon') : 'prx-btn-danger-soft'; ?>"
            title="<?php echo HTML::chars($label); ?>" aria-label="<?php echo HTML::chars($label.': '.$title); ?>"
            <?php if ($confirm): ?>data-prx-confirm="<?php echo HTML::chars($confirm); ?>" data-prx-title="<?php echo HTML::chars($label.'?'); ?>" data-prx-ok="<?php echo HTML::chars($label); ?>" data-prx-subject="<?php echo HTML::chars($title); ?>"<?php endif; ?>>
        <i class="fas <?php echo HTML::chars($icon); ?>" aria-hidden="true"></i>
        <?php if ( ! $compact): ?><?php echo HTML::chars($label); ?><?php endif; ?>
    </button>
</form>
