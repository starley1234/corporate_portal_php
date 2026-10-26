<?php /* Пагинация Kohana (Pagination::factory, erp208/erp218) — кнопки .n2j-page эталона v6 */ ?>
<?php $pg_off = 'n2j-page pointer-events-none cursor-not-allowed text-sm text-gray-300 dark:text-gray-600'; ?>
<nav class="my-6 flex items-center justify-center gap-1" aria-label="Страницы">
    <?php if ($first_page !== FALSE): ?>
        <a href="<?php echo HTML::chars($page->url($first_page)) ?>" class="n2j-page text-sm" title="<?php echo __('First') ?>" aria-label="Первая страница">
            <i class="fas fa-angles-left text-xs"></i>
        </a>
    <?php endif ?>

    <?php if ($previous_page !== FALSE): ?>
        <a href="<?php echo HTML::chars($page->url($previous_page)) ?>" class="n2j-page text-sm" rel="prev" aria-label="Предыдущая страница">
            <i class="fas fa-chevron-left text-xs"></i>
        </a>
    <?php else: ?>
        <span class="<?php echo $pg_off ?>" aria-hidden="true"><i class="fas fa-chevron-left text-xs"></i></span>
    <?php endif ?>

    <div class="hidden items-center gap-1 sm:flex">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == $current_page): ?>
                <span class="n2j-page text-sm" aria-current="page"><?php echo $i ?></span>
            <?php elseif ($i <= 3 || $i > $total_pages - 3 || ($i >= $current_page - 1 && $i <= $current_page + 1)): ?>
                <a href="<?php echo HTML::chars($page->url($i)) ?>" class="n2j-page text-sm"><?php echo $i ?></a>
            <?php elseif ($i == 4 || $i == $total_pages - 3): ?>
                <span class="px-1 text-sm text-gray-400 dark:text-gray-500">…</span>
            <?php endif ?>
        <?php endfor ?>
    </div>

    <span class="px-2 text-sm font-medium text-gray-700 sm:hidden dark:text-gray-300">
        <?php echo $current_page ?> / <?php echo $total_pages ?>
    </span>

    <?php if ($next_page !== FALSE): ?>
        <a href="<?php echo HTML::chars($page->url($next_page)) ?>" class="n2j-page text-sm" rel="next" aria-label="Следующая страница">
            <i class="fas fa-chevron-right text-xs"></i>
        </a>
    <?php else: ?>
        <span class="<?php echo $pg_off ?>" aria-hidden="true"><i class="fas fa-chevron-right text-xs"></i></span>
    <?php endif ?>

    <?php if ($last_page !== FALSE): ?>
        <a href="<?php echo HTML::chars($page->url($last_page)) ?>" class="n2j-page text-sm" title="<?php echo __('Last') ?>" aria-label="Последняя страница">
            <i class="fas fa-angles-right text-xs"></i>
        </a>
    <?php endif ?>
</nav>
