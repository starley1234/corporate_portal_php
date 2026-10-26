<?php defined('SYSPATH') or die('No direct script access.');
/**
 * nj602/admin: строки таблицы модерации — общий фрагмент полного вида
 * и AJAX-обновления (GET /nj602/admin?rows=1 → {html, counts}).
 *
 * @var array  $items строки статей (Service_Nj602_Content::admin_list())
 * @var string $csrf  CSRF-токен формы
 */
$st_chip = function ($st) {
    $map = [
        'published'  => ['опубликовано', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'],
        'review'     => ['на модерации', 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'],
        'collecting' => ['собирается', 'bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300'],
        'rejected'   => ['отклонено', 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'],
        'error'      => ['ошибка', 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300'],
    ];

    [$t, $c] = $map[$st] ?? [$st, 'bg-gray-100 text-gray-500 dark:bg-gray-800'];

    return '<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold '.$c.'">'.HTML::chars((string) $t).'</span>';
};
?>
<?php foreach ($items as $a): ?>
<tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
    <td class="py-2.5 pl-4 align-top sm:pl-5">
        <?php /* чекбокс принадлежит форме массовых действий (form="nj602-bulk"),
                 хотя стоит в таблице — так HTML и остаётся валидным */ ?>
        <input type="checkbox" name="ids[]" value="<?php echo (int) $a['id']; ?>" form="nj602-bulk"
               class="nj602-row-check mt-0.5 h-4 w-4 cursor-pointer rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900">
    </td>
    <td class="max-w-[420px] py-2.5 pr-3">
        <div class="flex items-center gap-2">
            <span class="font-mono text-xs font-bold text-blue-600 dark:text-blue-400"><?php echo HTML::chars($a['code']); ?></span>
            <span class="truncate font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($a['title'] !== '' ? $a['title'] : '(заголовок появится) '.($a['topic'] === '__AUTO_TRENDS__' ? 'авто: свежий тренд' : $a['topic'])); ?></span>
        </div>
        <?php if ((string) $a['error'] !== ''): ?><p class="mt-0.5 truncate text-xs text-rose-400" title="<?php echo HTML::chars($a['error']); ?>"><?php echo HTML::chars(mb_substr($a['error'], 0, 120)); ?></p><?php endif; ?>
    </td>
    <td class="py-2.5 px-3">
        <?php echo $st_chip($a['status']); ?>
        <?php $q = json_decode((string) ($a['quality'] ?? ''), TRUE) ?: []; if ($q): ?>
        <?php $qs = (float) ($q['score'] ?? 0); $qc = $qs >= 7 ? 'text-emerald-600 dark:text-emerald-400' : ($qs >= 4.5 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400'); ?>
        <span class="mt-1 block text-xs text-gray-400 dark:text-gray-500"
              title="Предметность: метрика ссылок/цифр/штампов <?php echo HTML::chars((string) $qs); ?>/10; рецензент <?php echo (int) ($q['critic'] ?? -1); ?>/10; фактов <?php echo (int) ($q['facts'] ?? 0); ?>, ссылок <?php echo (int) ($q['citations'] ?? 0); ?>">
            <i class="fas fa-flask text-xs"></i> <b class="<?php echo $qc; ?>"><?php echo HTML::chars((string) $qs); ?></b>/10
            · факты <?php echo (int) ($q['facts'] ?? 0); ?> · ссылки <?php echo (int) ($q['citations'] ?? 0); ?>
        </span>
        <?php endif; ?>
        <?php if ((int) ($a['job_id'] ?? 0) > 0 AND ($a['status'] === 'collecting' OR $a['status'] === 'error')): ?>
        <span class="mt-1 block text-xs text-gray-400 dark:text-gray-500" title="Статус задачи в очереди nj_120_jobs">
            задача <span class="font-mono">#<?php echo (int) $a['job_id']; ?></span>
            <?php $js = (string) ($a['job_status'] ?? 'processing'); $jmap = [
                'pending'    => ['в очереди', 'text-gray-400'],
                'processing' => ['выполняется', 'text-amber-600 dark:text-amber-400'],
                'done'       => ['сделано', 'text-emerald-600 dark:text-emerald-400'],
                'failed'     => ['провалено', 'text-rose-600 dark:text-rose-400'],
                'cancelled'  => ['отменено', 'text-gray-400'],
            ]; [$jt, $jc] = $jmap[$js] ?? [$js, 'text-gray-400']; ?>
            · <span class="<?php echo $jc; ?>"><?php echo HTML::chars($jt); ?></span>
        </span>
        <?php endif; ?>
    </td>
    <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400">
        <?php echo HTML::chars(Date::formatted_time((string) $a['created_at'], 'd.m.y H:i')); ?>
        <?php if (in_array($a['status'], ['collecting', 'error'], TRUE)): ?>
        <button type="button" data-nj602-trace-open="<?php echo HTML::chars($a['code']); ?>"
                class="n2j-btn-secondary n2j-btn-sm mt-1 flex"
                title="Открыть живой журнал прогона этой статьи">
            <i class="fas fa-list-ul mr-1"></i>журнал
        </button>
        <?php endif; ?>
    </td>
    <td class="py-2.5 pr-4 pl-3 text-right sm:pr-5">
        <div class="inline-flex items-center justify-end gap-1.5">
            <?php if ($a['status'] !== 'collecting'): ?>
            <button type="button" data-nj602-regen data-id="<?php echo (int) $a['id']; ?>" data-code="<?php echo HTML::chars($a['code']); ?>"
                    title="Перегенерировать схему (ИИ, текст статьи не меняется)"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-blue-50 hover:text-blue-600 dark:hover:bg-blue-950/40 dark:hover:text-blue-400">
                <i class="fas fa-diagram-project text-sm"></i><span data-regen-text class="sr-only">Перегенерировать схему</span>
            </button>
            <a href="/nj602/admin/edit/<?php echo (int) $a['id']; ?>" title="Править текст и схему"
               class="n2j-btn-icon">
                <i class="fas fa-pen text-sm"></i>
            </a>
            <?php endif; ?>
            <?php if (in_array($a['status'], ['published', 'review'], TRUE)): ?>
            <a href="/content/<?php echo HTML::chars($a['slug']); ?>" class="n2j-btn-secondary n2j-btn-sm">Открыть</a>
            <?php endif; ?>
            <?php if ($a['status'] === 'review'): ?>
            <form method="post" action="/nj602/admin" class="inline">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="publish">
                <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                <button type="submit" class="n2j-btn-cta n2j-btn-sm">Опубликовать</button>
            </form>
            <form method="post" action="/nj602/admin" class="inline">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="reject">
                <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                <button data-n2j-arm="Отклонить?" type="submit" class="n2j-btn-secondary n2j-btn-sm"><span data-n2j-arm-label>Отклонить</span></button>
            </form>
            <?php endif; ?>
            <?php if (in_array($a['status'], ['error', 'collecting'], TRUE)): ?>
            <form method="post" action="/nj602/admin" class="inline">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="retry">
                <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                <button data-n2j-arm="Перезапустить сбор?" type="submit" title="Повторить сбор" class="n2j-btn-icon"><i class="fas fa-rotate-right text-xs"></i></button>
            </form>
            <?php endif; ?>
            <form method="post" action="/nj602/admin" class="inline">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                <button data-n2j-arm="Удалить?" type="submit" title="Удалить" class="n2j-btn-icon-danger"><i class="fas fa-trash-can text-xs"></i></button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($items)): ?>
<tr><td colspan="5" class="py-4 pl-4 text-sm text-gray-400 sm:pl-5">Статей пока нет — запустите сбор справа.</td></tr>
<?php endif; ?>
