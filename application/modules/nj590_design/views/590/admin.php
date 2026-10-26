<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /nj590/admin — прогоны всех пользователей (только администратор).
 */

$status_ru = [
    'new'     => 'в очереди', 'running' => 'работает',
    'done'    => 'готово',    'error'   => 'ошибка',
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-3 text-xs block">
        <?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <div class="n2j-card">
        <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-palette mr-1.5 text-blue-500"></i>Прогоны дизайна (все пользователи)</p>

        <?php if (empty($runs)): ?>
        <p class="n2j-hint mt-2">Прогонов нет.</p>
        <?php else: ?>
        <div class="mt-2 overflow-x-auto">
            <table class="n2j-table">
                <thead>
                    <tr class="border-b border-gray-100 text-xs uppercase text-gray-400 dark:border-gray-700">
                        <th class="py-1.5 pr-3">Код</th>
                        <th class="py-1.5 pr-3">user_id</th>
                        <th class="py-1.5 pr-3">Хост / страница</th>
                        <th class="py-1.5 pr-3">Статус</th>
                        <th class="py-1.5 pr-3">Варианты</th>
                        <th class="py-1.5 pr-3">Выбран</th>
                        <th class="py-1.5 pr-3">Создан</th>
                        <th class="py-1.5 pr-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $r): ?>
                    <tr class="border-b border-gray-50 align-top dark:border-gray-700/50">
                        <td class="py-1.5 pr-3"><a href="/design/<?php echo HTML::chars($r['code']); ?>" class="font-mono font-bold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($r['code']); ?></a></td>
                        <td class="py-1.5 pr-3 text-gray-500"><?php echo (int) $r['user_id']; ?></td>
                        <td class="max-w-[16rem] truncate py-1.5 pr-3 text-gray-600 dark:text-gray-300" title="<?php echo HTML::chars($r['url']); ?>"><?php echo HTML::chars($r['host']); ?></td>
                        <td class="py-1.5 pr-3">
                            <span class="n2j-badge <?php echo $r['status'] === 'done' ? 'n2j-badge-ok' : ($r['status'] === 'error' ? 'n2j-badge-err' : 'n2j-badge-info'); ?> uppercase">
                                <?php echo $status_ru[$r['status']] ?? HTML::chars($r['status']); ?>
                            </span>
                            <?php if ($r['error']): ?><div class="mt-0.5 max-w-[14rem] truncate text-xs text-rose-400" title="<?php echo HTML::chars($r['error']); ?>"><?php echo HTML::chars(mb_substr($r['error'], 0, 80)); ?></div><?php endif; ?>
                        </td>
                        <td class="py-1.5 pr-3 text-gray-500"><?php echo (int) $r['variants_ready']; ?>/<?php echo (int) $r['variants_count']; ?></td>
                        <td class="py-1.5 pr-3 text-gray-500"><?php echo (int) $r['chosen'] > 0 ? '№'.(int) $r['chosen'] : '—'; ?></td>
                        <td class="py-1.5 pr-3 text-gray-400"><?php echo HTML::chars((string) $r['created_at']); ?></td>
                        <td class="py-1.5 pr-3">
                            <?php if ($r['deleted_at']): ?>
                            <form method="post" action="/nj590/admin/delete/<?php echo (int) $r['id']; ?>">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="mode" value="restore">
                                <button type="submit" class="n2j-btn-secondary n2j-btn-sm">вернуть</button>
                            </form>
                            <?php else: ?>
                            <form method="post" action="/nj590/admin/delete/<?php echo (int) $r['id']; ?>">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="mode" value="soft">
                                <button type="submit" class="n2j-btn-danger n2j-btn-sm">удалить</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
