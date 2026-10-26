<?php defined('SYSPATH') or die('No direct script access.');
/**
 * nj602/admin: пагинация списка статей — общий фрагмент страницы и
 * AJAX-обновления (GET /nj602/admin?rows=1 → {html, pager, total}).
 *
 * @var array $f фильтры: q, status, page, per, total, pages
 */
$nj602_qs = function (array $over) use ($f) {
    $qs = array_filter(array_merge([
        'q'      => (string) $f['q'],
        'status' => (string) $f['status'],
        'per'    => (int) $f['per'],
        'page'   => (int) $f['page'],
    ], $over), function ($v) { return (string) $v !== '' AND (string) $v !== '0'; });

    return '/nj602/admin'.($qs ? '?'.http_build_query($qs) : '');
};

$from = $f['total'] > 0 ? (($f['page'] - 1) * $f['per'] + 1) : 0;
$to   = min((int) $f['total'], $f['page'] * $f['per']);
?>
<div class="flex flex-wrap items-center justify-between gap-2.5 border-t border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-700/60" id="nj602-pager" data-page="<?php echo (int) $f['page']; ?>">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        <?php if ((int) $f['total'] === 0): ?>
        Ничего не найдено<?php echo $f['q'] !== '' ? ' по запросу «'.HTML::chars($f['q']).'»' : ''; ?>.
        <?php else: ?>
        Показано <span class="font-semibold text-gray-700 dark:text-gray-200" data-nj602-page-from><?php echo (int) $from; ?></span>–<span class="font-semibold text-gray-700 dark:text-gray-200" data-nj602-page-to><?php echo (int) $to; ?></span>
        из <span class="font-semibold text-gray-700 dark:text-gray-200" data-nj602-total><?php echo (int) $f['total']; ?></span>
        <span class="text-gray-400">·</span> стр. <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $f['page']; ?></span> из <span data-nj602-pages><?php echo (int) $f['pages']; ?></span>
        <?php endif; ?>
    </p>

    <?php if ((int) $f['pages'] > 1): ?>
    <div class="flex items-center gap-1.5">
        <a href="<?php echo HTML::chars($nj602_qs(['page' => max(1, $f['page'] - 1)])); ?>" data-nj602-page-link
           class="n2j-btn-secondary n2j-btn-sm <?php echo $f['page'] <= 1 ? 'pointer-events-none opacity-40' : ''; ?>">
            <i class="fas fa-chevron-left text-[10px]"></i>Назад
        </a>
        <a href="<?php echo HTML::chars($nj602_qs(['page' => min((int) $f['pages'], $f['page'] + 1)])); ?>" data-nj602-page-link
           class="n2j-btn-secondary n2j-btn-sm <?php echo $f['page'] >= $f['pages'] ? 'pointer-events-none opacity-40' : ''; ?>">
            Вперёд<i class="fas fa-chevron-right text-[10px]"></i>
        </a>
    </div>
    <?php endif; ?>
</div>
