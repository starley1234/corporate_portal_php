<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Кнопка перегенерации ответа (POST + CSRF), используется в 540/result.php.
 */
?>
<form method="post" action="/nj540/api/regen/<?php echo HTML::chars($row['code']); ?>" class="inline">
    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
    <button type="submit"
            class="n2j-btn-secondary">
        <i class="fas fa-rotate"></i><?php echo HTML::chars($label); ?>
    </button>
</form>
