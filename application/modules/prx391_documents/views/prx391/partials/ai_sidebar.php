<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php $ai_busy = !empty($progress['found']) && in_array($progress['status'], ['queued', 'running'], TRUE); ?>
<section class="prx-card">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-wand-magic-sparkles mr-2 text-blue-500" aria-hidden="true"></i>ИИ-разбор</h2>
        <?php if ($can_run_ai): ?>
        <form method="post" action="/analysis/run/<?php echo (int) $doc['id']; ?>">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <button class="prx-btn-process prx-btn-sm" type="submit" <?php if ($ai_busy): ?>disabled aria-disabled="true"<?php endif; ?>>
                <i class="fas <?php echo $analysis ? 'fa-rotate-right' : 'fa-play'; ?>" aria-hidden="true"></i>
                <?php echo $ai_busy ? 'Разбор выполняется' : ($analysis ? 'Разобрать заново' : 'Запустить разбор'); ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
<?php include __DIR__.'/process.php'; ?>
    <?php if (empty($progress['found'])): ?>
    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Тип, реквизиты и краткое содержание. Изменения — после вашего подтверждения.</p>
    <?php endif; ?>
    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
        <?php if (!empty($progress['found'])): ?>
        <span>Прогон #<?php echo (int) $progress['run_id']; ?></span>
        <a href="/prx120/monitor" class="prx-link">Монитор очереди</a>
        <?php endif; ?>
        <a href="/analysis" class="prx-link">Все предложения</a>
    </div>
</section>
