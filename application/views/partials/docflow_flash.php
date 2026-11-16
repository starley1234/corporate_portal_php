<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php if ( ! empty($flash['text'])): ?>
    <?php $flash_type = in_array($flash['type'] ?? '', ['ok', 'info', 'warn', 'err'], TRUE) ? $flash['type'] : 'info'; ?>
    <div class="prl-flash prl-flash-<?php echo $flash_type; ?> mt-4" role="status">
        <i class="fas <?php echo $flash_type === 'ok' ? 'fa-circle-check' : ($flash_type === 'err' ? 'fa-circle-exclamation' : 'fa-circle-info'); ?>"></i>
        <span><?php echo HTML::chars($flash['text']); ?></span>
    </div>
<?php endif; ?>
