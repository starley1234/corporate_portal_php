<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Блок «Похожие и другие сервисы N2J.ru» — для сайдбара сервисных страниц.
 *
 * Источник — манифесты nj110_catalog (никаких хардкод-списков): сервисы
 * той же группы идут первыми («Похожие»), остальные следом.
 * $except — href текущего сервиса.
 */

$except = isset($except) ? (string) $except : '';
$merged = ! empty($merged); // одна карточка «Другие сервисы» (похожие — первыми)
$me     = NULL;
$all    = [];

if (class_exists('Service_Nj110_Catalog'))
{
    foreach (Service_Nj110_Catalog::modules() as $m)
    {
        if ($m['href'] === $except)
        {
            $me = $m;
        }
        else
        {
            $all[] = $m;
        }
    }
}

$similar = $me
    ? array_values(array_filter($all, function ($m) use ($me) { return $m['group'] === $me['group']; }))
    : [];
$others  = $me
    ? array_values(array_filter($all, function ($m) use ($me) { return $m['group'] !== $me['group']; }))
    : $all;

?>

<?php if ($merged): ?>
<?php $both = array_merge($similar, $others); ?>
<?php if ( ! empty($both)): ?>
<div class="n2j-card hidden md:block">
    <p class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Другие сервисы N2J.ru</p>
    <ul class="mt-2 space-y-0.5">
        <?php foreach ($both as $s): ?>
        <li>
            <a href="<?php echo HTML::chars($s['href']); ?>"
               class="group flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-600 transition hover:bg-gray-50 hover:text-blue-600 dark:text-gray-300 dark:hover:bg-gray-700/60">
                <i class="fas <?php echo HTML::chars($s['icon']); ?> w-4 text-center text-gray-400 group-hover:text-blue-500"></i><?php echo HTML::chars($s['title']); ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php else: ?>
<?php if ( ! empty($similar)): ?>
<div class="n2j-card hidden md:block">
    <p class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Похожие сервисы</p>
    <ul class="mt-2 space-y-0.5">
        <?php foreach ($similar as $s): ?>
        <li>
            <a href="<?php echo HTML::chars($s['href']); ?>"
               class="group flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-600 transition hover:bg-gray-50 hover:text-blue-600 dark:text-gray-300 dark:hover:bg-gray-700/60">
                <i class="fas <?php echo HTML::chars($s['icon']); ?> w-4 text-center text-gray-400 group-hover:text-blue-500"></i><?php echo HTML::chars($s['title']); ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ( ! empty($others)): ?>
<div class="n2j-card hidden md:block">
    <p class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Другие сервисы N2J.ru</p>
    <ul class="mt-2 space-y-0.5">
        <?php foreach ($others as $s): ?>
        <li>
            <a href="<?php echo HTML::chars($s['href']); ?>"
               class="group flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-600 transition hover:bg-gray-50 hover:text-blue-600 dark:text-gray-300 dark:hover:bg-gray-700/60">
                <i class="fas <?php echo HTML::chars($s['icon']); ?> w-4 text-center text-gray-400 group-hover:text-blue-500"></i><?php echo HTML::chars($s['title']); ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php endif; ?>
