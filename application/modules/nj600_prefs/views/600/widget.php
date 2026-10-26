<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj600: виджет «Вам может пригодиться» — рекомендации текущему посетителю.
 * Рендерит Service_Nj600_Reco::widget(); переход — через /prefs/api/go
 * (учёт клика), крестик — POST /prefs/api/dismiss («не предлагать»).
 */
?>
<div class="n2j-card" data-nj600-reco>
    <div class="flex items-center justify-between gap-2">
        <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400"><i class="fas fa-wand-magic-sparkles mr-1 text-blue-500"></i><?php echo HTML::chars($title); ?></p>
        <span class="n2j-hint cursor-help" title="Подбирается по вашим интересам на платформе; крестик — больше не предлагать">почему?</span>
    </div>
    <ul class="mt-2 space-y-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
        <?php foreach ($items as $r): $go = ! empty($r['log_id']) ? '/prefs/api/go?id='.(int) $r['log_id'] : $r['href']; ?>
        <li class="flex items-start gap-2" data-nj600-reco-item>
            <i class="<?php echo HTML::chars($r['icon']); ?> mt-1 w-4 shrink-0 text-center text-xs text-blue-500"></i>
            <span class="min-w-0 flex-1">
                <a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="<?php echo HTML::chars($go); ?>" title="<?php echo HTML::chars($r['reason']); ?>"><?php echo HTML::chars($r['title']); ?></a>
                <?php if ((string) $r['short'] !== ''): ?><span class="text-gray-500 dark:text-gray-400"> — <?php echo HTML::chars(mb_substr($r['short'], 0, 90)); ?></span><?php endif; ?>
            </span>
            <button type="button" class="shrink-0 rounded p-0.5 text-gray-300 transition hover:text-rose-500 dark:text-gray-600" title="Не предлагать"
                    data-nj600-dismiss="<?php echo HTML::chars($r['href']); ?>" data-csrf="<?php echo HTML::chars($csrf); ?>"><i class="fas fa-xmark text-xs"></i></button>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<script>
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-nj600-dismiss]') : null;

        if (!btn) return;

        e.preventDefault();

        var item = btn.closest('[data-nj600-reco-item]');
        var box  = btn.closest('[data-nj600-reco]');
        var body = new FormData();

        body.append('href', btn.getAttribute('data-nj600-dismiss'));
        body.append('csrf', btn.getAttribute('data-csrf'));

        if (item) item.remove();

        if (box && !box.querySelector('[data-nj600-reco-item]')) box.remove();

        fetch('/prefs/api/dismiss', { method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body }).catch(function () {});
    });
})();
</script>
