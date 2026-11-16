<?php
/*
	First Previous 1 2 3 ... 22 23 24 25 26 [27] 28 29 30 31 32 ... 48 49 50 Next Last
*/

// Number of page links in the begin and end of whole range
$count_out = ( ! empty($config['count_out'])) ? (int) $config['count_out'] : 3;
// Number of page links on each side of current page
$count_in = ( ! empty($config['count_in'])) ? (int) $config['count_in'] : 2;

// Beginning group of pages: $n1...$n2
$n1 = 1;
$n2 = min($count_out, $total_pages);

// Ending group of pages: $n7...$n8
$n7 = max(1, $total_pages - $count_out + 1);
$n8 = $total_pages;

// Middle group of pages: $n4...$n5
$n4 = max($n2 + 1, $current_page - $count_in);
$n5 = min($n7 - 1, $current_page + $count_in);
$use_middle = ($n5 >= $n4);

// Point $n3 between $n2 and $n4
$n3 = (int) (($n2 + $n4) / 2);
$use_n3 = ($use_middle && (($n4 - $n2) > 1));

// Point $n6 between $n5 and $n7
$n6 = (int) (($n5 + $n7) / 2);
$use_n6 = ($use_middle && (($n7 - $n5) > 1));

// Links to display as array(page => content)
$links = [];

// Generate links data in accordance with calculated numbers
for ($i = $n1; $i <= $n2; ++$i)
{
	$links[$i] = $i;
}
if ($use_n3)
{
	$links[$n3] = '&hellip;';
}
for ($i = $n4; $i <= $n5; ++$i)
{
	$links[$i] = $i;
}
if ($use_n6)
{
	$links[$n6] = '&hellip;';
}
for ($i = $n7; $i <= $n8; ++$i)
{
	$links[$i] = $i;
}

// кнопки — компонент .prl-page эталона v6 (текущая — aria-current)
$base_classes = "prl-page text-sm font-semibold";
$active_classes = "";
$inactive_classes = "";
$disabled_classes = "pointer-events-none cursor-not-allowed text-gray-300 dark:text-gray-600";
?>

<div class="mt-6 flex items-center justify-between border-t border-gray-100 px-4 py-3 sm:px-6 dark:border-gray-700/60">
    <div class="flex flex-1 justify-between sm:hidden">
        <?php if ($previous_page !== FALSE): ?>
            <a href="<?php echo HTML::chars($page->url($previous_page)) ?>" class="prl-btn-secondary prl-btn-sm">Назад</a>
        <?php else: ?>
            <span class="prl-btn-secondary prl-btn-sm pointer-events-none opacity-50">Назад</span>
        <?php endif ?>

        <?php if ($next_page !== FALSE): ?>
            <a href="<?php echo HTML::chars($page->url($next_page)) ?>" class="prl-btn-secondary prl-btn-sm ml-3">Вперед</a>
        <?php else: ?>
            <span class="prl-btn-secondary prl-btn-sm pointer-events-none ml-3 opacity-50">Вперед</span>
        <?php endif ?>
    </div>
    <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
        <div>
            <p class="text-sm text-gray-700 dark:text-gray-400">
                Страница <span class="font-medium"><?php echo $current_page ?></span> из <span class="font-medium"><?php echo $total_pages ?></span>
            </p>
        </div>
        <div>
            <nav class="inline-flex items-center gap-1" aria-label="Страницы">
                <?php if ($previous_page !== FALSE): ?>
                    <a href="<?php echo HTML::chars($page->url($previous_page)) ?>" class="<?php echo $base_classes . ' ' . $inactive_classes; ?>" title="Предыдущая">
                        <span class="sr-only">Назад</span>
                        <i class="fas fa-chevron-left h-5 w-5 flex items-center justify-center"></i>
                    </a>
                <?php else: ?>
                    <span class="<?php echo $base_classes . ' ' . $disabled_classes; ?>">
                        <i class="fas fa-chevron-left h-5 w-5 flex items-center justify-center"></i>
                    </span>
                <?php endif ?>

                <?php foreach ($links as $number => $content): ?>
                    <?php if ($number === $current_page): ?>
                        <span aria-current="page" class="<?php echo $base_classes . ' ' . $active_classes; ?>"><?php echo $content ?></span>
                    <?php elseif ($content === '&hellip;'): ?>
                        <span class="<?php echo $base_classes . ' ' . $inactive_classes; ?>"><?php echo $content ?></span>
                    <?php else: ?>
                        <a href="<?php echo HTML::chars($page->url($number)) ?>" class="<?php echo $base_classes . ' ' . $inactive_classes; ?>"><?php echo $content ?></a>
                    <?php endif ?>
                <?php endforeach ?>

                <?php if ($next_page !== FALSE): ?>
                    <a href="<?php echo HTML::chars($page->url($next_page)) ?>" class="<?php echo $base_classes . ' ' . $inactive_classes; ?>" title="Следующая">
                        <span class="sr-only">Вперед</span>
                        <i class="fas fa-chevron-right h-5 w-5 flex items-center justify-center"></i>
                    </a>
                <?php else: ?>
                    <span class="<?php echo $base_classes . ' ' . $disabled_classes; ?>">
                        <i class="fas fa-chevron-right h-5 w-5 flex items-center justify-center"></i>
                    </span>
                <?php endif ?>
            </nav>
        </div>
    </div>
</div>
