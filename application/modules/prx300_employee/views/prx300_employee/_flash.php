<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php if ( ! empty($flash['text'])): ?>
<div class="prx-flash <?php echo ($flash['type'] ?? '') === 'err' ? 'prx-flash-err' : 'prx-flash-ok'; ?> mt-4 block" role="status">
    <?php echo HTML::chars((string) $flash['text']); ?>
</div>
<?php endif; ?>
