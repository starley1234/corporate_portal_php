<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Часть управления nj550: последние поиски вакансий и управление.
 */
$badge = [
    'done'   => ['bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/60 dark:text-emerald-400', 'готово'],
    'new'    => ['bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-950/60 dark:text-blue-400', 'в работе'],
    'error'  => ['bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-950/60 dark:text-rose-400', 'ошибка'],
];

?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="n2j-card">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="n2j-h1">Поиск вакансий — последние поиски</h1>
            <div class="n2j-seg ml-auto gap-1">
                <?php foreach ([NULL => 'все', 'new' => 'в работе', 'done' => 'готово', 'error' => 'ошибки'] as $k => $label): ?>
                    <a href="/nj550/admin<?php echo $k ? '?status='.$k : '' ?>"
                       class="n2j-seg-item<?php echo (($status ?? NULL) === $k) ? ' is-active' : ''; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="get" action="/nj550/admin" class="mt-4 flex flex-wrap items-center gap-2">
            <?php if ( ! empty($status)): ?><input type="hidden" name="status" value="<?php echo HTML::chars($status); ?>"><?php endif; ?>
            <input type="text" name="q" value="<?php echo HTML::chars($q ?? ''); ?>"
                   placeholder="Поиск: направление, город, код, посетитель…"
                   class="n2j-input w-full max-w-xs">
            <button type="submit" class="n2j-btn-process n2j-btn-sm"><i class="fas fa-magnifying-glass mr-1"></i>Найти</button>
            <?php if ( ! empty($q ?? '')): ?>
                <a href="/nj550/admin<?php echo ! empty($status) ? '?status='.HTML::chars($status) : ''; ?>" class="text-xs text-gray-400 hover:text-gray-600">сбросить</a>
            <?php endif; ?>
        </form>

        <?php if ( ! empty($_GET['flash'])): ?>
            <div class="n2j-flash n2j-flash-info mt-3 text-xs block">
                <?php echo HTML::chars((string) $_GET['flash']); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($rows)): ?>
            <p class="mt-5 text-sm text-gray-500 dark:text-gray-400">Поисков пока нет.</p>
        <?php else: ?>
            <div class="mt-4 overflow-x-auto">
                <table class="n2j-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
                            <th class="py-2.5 pr-3 font-semibold">#</th>
                            <th class="py-2.5 pr-3 font-semibold">Статус</th>
                            <th class="py-2.5 pr-3 font-semibold">Направление / город</th>
                            <th class="py-2.5 pr-3 font-semibold">Посетитель</th>
                            <th class="py-2.5 pr-3 font-semibold">Найдено</th>
                            <th class="py-2.5 pr-3 font-semibold">Создан</th>
                            <th class="py-2 font-semibold text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): $b = $badge[$r['status']] ?? $badge['new']; ?>
                        <tr class="border-b border-gray-100 align-middle dark:border-gray-800">
                            <td class="py-2.5 pr-3 text-xs text-gray-400"><?php echo (int) $r['id']; ?></td>
                            <td class="py-2.5 pr-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold uppercase ring-1 ring-inset <?php echo $b[0]; ?>"><?php echo $b[1]; ?></span>
                                <?php if ($r['error']): ?>
                                    <span class="block max-w-[220px] truncate text-xs text-rose-400" title="<?php echo HTML::chars($r['error']); ?>"><?php echo HTML::chars(mb_substr($r['error'], 0, 60)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-2.5 pr-3">
                                <a href="/jobs/<?php echo HTML::chars($r['code']); ?>" class="font-semibold text-gray-900 hover:text-emerald-600 dark:text-white"><?php echo HTML::chars($r['direction']); ?></a>
                                <span class="text-gray-400">· <?php echo HTML::chars($r['city']); ?></span>
                            </td>
                            <td class="py-2.5 pr-3 font-mono text-xs text-gray-400"><?php echo HTML::chars(mb_substr((string) $r['visitor_id'], 0, 14)); ?></td>
                            <td class="py-2.5 pr-3 text-gray-600 dark:text-gray-300"><?php echo (int) $r['results_cnt']; ?></td>
                            <td class="py-2.5 pr-3 text-xs text-gray-400"><?php echo HTML::chars((string) $r['created_at']); ?></td>
                            <td class="py-2 text-right">
                                <form method="post" action="/nj550/admin/requeue/<?php echo (int) $r['id']; ?>" class="inline">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <button type="submit" class="n2j-btn-secondary n2j-btn-sm" title="Перезапустить поиск">
                                        <i class="fas fa-rotate"></i> заново
                                    </button>
                                </form>
                                <form method="post" action="/nj550/admin/delete/<?php echo (int) $r['id']; ?>" class="inline">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <button data-n2j-arm="Удалить?" type="submit" class="n2j-btn-danger n2j-btn-sm ml-1" title="Удалить">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="n2j-hint mt-3">
                Страница <?php echo (int) $page; ?> ·
                <a href="/nj550/admin?page=<?php echo (int) ($page + 1); ?><?php echo $status ? '&status='.HTML::chars($status) : ''; ?>" class="text-blue-600 hover:underline dark:text-blue-400">следующие 50 →</a>
            </p>
        <?php endif; ?>
    </div>
</div>
