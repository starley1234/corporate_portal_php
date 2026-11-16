<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php if ( ! empty($flash['text'])): ?>
<div class="prl-flash <?php echo ($flash['type'] ?? '') === 'err' ? 'prl-flash-err' : 'prl-flash-ok'; ?> mt-4 block" role="status">
    <?php echo HTML::chars((string) $flash['text']); ?>
</div>
<?php endif; ?>
