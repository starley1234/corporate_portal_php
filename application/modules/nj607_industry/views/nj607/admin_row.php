<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj607/admin_row — ОДНА строка таблицы отчётов (и для полной страницы, и для
 * AJAX-подмены после перезапуска/закрытия — страница не перезагружается).
 */
$stc = $st[$r['status']] ?? 'bg-gray-100 text-gray-500 dark:bg-gray-800';
$stl = Service_Nj607_Industry::status_label((string) $r['status']);
?>
<tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25" data-nj607admin-row="<?php echo HTML::chars($r['code']); ?>">
    <td class="max-w-[320px] py-2.5 pr-3 pl-4 sm:pl-5">
        <a href="/industry/<?php echo HTML::chars($r['code']); ?>" class="truncate font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"><?php echo HTML::chars((string) $r['industry']); ?></a>
        <p class="n2j-hint mt-0.5 truncate"><?php echo $r['region'] !== '' ? HTML::chars($r['region']).' · ' : ''; ?><span class="font-mono"><?php echo HTML::chars($r['code']); ?></span></p>
    </td>
    <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) ($r['owner_name'] ?? '')); ?></td>
    <td class="py-2.5 px-3"><span class="n2j-badge n2j-badge-wait"><?php echo $r['kind'] === 'self' ? 'авто' : 'заказ'; ?></span></td>
    <td class="py-2.5 px-3"><span class="rounded-md <?php echo $stc; ?> px-2 py-0.5 text-xs font-bold"><?php echo HTML::chars($stl); ?></span></td>
    <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400"><?php echo $dt($r['created_at']); ?></td>
    <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400"><?php echo $dt($r['finished_at']); ?></td>
    <td class="py-2.5 pr-4 pl-3 text-right sm:pr-5">
        <div class="inline-flex items-center justify-end gap-1">
            <?php if ($r['kind'] === 'self'): ?>
            <button type="button" data-nj607admin-act="restart" data-nj607admin-code="<?php echo HTML::chars($r['code']); ?>" title="Перезапустить сбор" class="n2j-btn-icon"><i class="fas fa-rotate-right text-xs"></i></button>
            <?php endif; ?>
            <?php if ($r['kind'] === 'order' AND $r['status'] === 'new'): ?>
            <button type="button" data-nj607admin-act="close" data-nj607admin-code="<?php echo HTML::chars($r['code']); ?>" title="Закрыть заявку" class="n2j-btn-icon"><i class="fas fa-check text-xs"></i></button>
            <?php endif; ?>
            <button data-n2j-arm="Удалить отчёт?" type="button" data-nj607admin-act="delete" data-nj607admin-code="<?php echo HTML::chars($r['code']); ?>" title="Удалить" class="n2j-btn-icon-danger"><i class="fas fa-trash-can text-xs"></i></button>
        </div>
    </td>
</tr>
