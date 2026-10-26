<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php /** @var string $type success|error|warning @var string $title @var string $text @var string $href @var string $link */
$styles = [
	'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
	'error'   => 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300',
	'warning' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
];
$icons = [
	'success' => 'fa-circle-check',
	'error'   => 'fa-circle-xmark',
	'warning' => 'fa-triangle-exclamation',
];
$type = isset($styles[$type]) ? $type : 'warning';
?>
<div class="flex min-h-[calc(100vh-64px)] items-center justify-center px-4 py-10">
	<div class="n2j-card w-full max-w-md p-8 text-center">
		<span class="inline-flex rounded-full border p-4 <?php echo $styles[$type]; ?>"><i class="fas <?php echo $icons[$type]; ?> text-2xl" aria-hidden="true"></i></span>
		<h1 class="n2j-h1 mt-4"><?php echo HTML::chars($title); ?></h1>
		<p class="mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400"><?php echo HTML::chars($text); ?></p>
		<?php if ($href !== ''): ?>
			<a href="<?php echo HTML::chars($href); ?>" class="n2j-btn-process mt-6"><?php echo HTML::chars($link !== '' ? $link : 'Продолжить'); ?></a>
		<?php endif; ?>
	</div>
</div>
