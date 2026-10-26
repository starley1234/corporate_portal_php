<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление 3D-конструктора: модерация моделей + учёт ресурсов воркеров.
 * (Роль admin, действия только POST+CSRF — см. контроллер.)
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ( ! empty($_GET['flash'])): ?>
    <div class="n2j-flash n2j-flash-info mb-3 text-xs block">
        <?php echo HTML::chars((string) $_GET['flash']); ?>
    </div>
    <?php endif; ?>

    <div class="gap-4 space-y-4 md:grid md:grid-cols-2 md:items-start md:space-y-0">

        <div class="space-y-4">
            <!-- ============ Очередь модерации ============ -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-gavel mr-1.5 text-amber-500"></i>Модерация моделей (<?php echo count($pending); ?>)</p>
                <?php if (empty($pending)): ?>
                    <p class="n2j-hint mt-2">Очередь пуста.</p>
                <?php else: ?>
                <ul class="mt-2 space-y-1.5">
                    <?php foreach ($pending as $m): ?>
                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border border-amber-200/70 px-3 py-2 dark:border-amber-900/50">
                        <?php if ( ! empty($m['png_file_id'])): ?>
                            <img src="/3d/file/<?php echo (int) $m['png_file_id']; ?>" alt="" class="h-12 w-16 rounded-xl border border-gray-100 bg-gray-50 object-contain dark:border-gray-700 dark:bg-gray-900">
                        <?php endif; ?>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-bold text-gray-900 dark:text-white"><a href="/3d/<?php echo HTML::chars($m['code']); ?>" class="hover:text-blue-500"><?php echo HTML::chars($m['title'] ?: $m['code']); ?></a></p>
                            <p class="n2j-hint truncate"><?php echo HTML::chars($m['username'] ?: ('user#'.$m['user_id'])); ?> · <?php echo HTML::chars((string) $m['updated_at']); ?></p>
                        </div>
                        <form method="post" action="/nj570/admin/moderate/<?php echo HTML::chars($m['code']); ?>" class="flex gap-1.5">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <button type="submit" name="decision" value="approve" class="n2j-btn-cta n2j-btn-sm">OK</button>
                            <button type="submit" name="decision" value="reject" class="n2j-btn-danger n2j-btn-sm">Нет</button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="space-y-4">
            <!-- ============ Сводка ресурсов воркеров ============ -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-gauge-high mr-1.5 text-blue-500"></i>Ресурсы воркеров</p>
                <div class="mt-2 grid grid-cols-2 gap-2 text-center sm:grid-cols-4">
                    <div class="rounded-xl bg-gray-50 p-2 dark:bg-gray-900">
                        <p class="text-xs uppercase font-bold text-gray-400">задач</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo (int) ($usage['total']['calls'] ?? 0); ?></p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-2 dark:bg-gray-900">
                        <p class="text-xs uppercase font-bold text-gray-400">токены in</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo number_format((int) ($usage['total']['pt'] ?? 0), 0, ',', ' '); ?></p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-2 dark:bg-gray-900">
                        <p class="text-xs uppercase font-bold text-gray-400">токены out</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo number_format((int) ($usage['total']['ct'] ?? 0), 0, ',', ' '); ?></p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-2 dark:bg-gray-900">
                        <p class="text-xs uppercase font-bold text-gray-400">время LLM</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo round(((int) ($usage['total']['ms'] ?? 0)) / 1000); ?> с</p>
                    </div>
                </div>
                <?php if ( ! empty($usage['by_phase'])): ?>
                <table class="n2j-table mt-3">
                    <thead><tr class="border-b border-gray-100 text-left text-gray-400 dark:border-gray-700">
                        <th class="py-1 font-bold">фаза</th><th class="font-bold">вызовы</th><th class="font-bold">in</th><th class="font-bold">out</th><th class="font-bold">время</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($usage['by_phase'] as $u): ?>
                        <tr class="border-b border-gray-50 dark:border-gray-800">
                            <td class="py-1 font-mono font-bold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($u['phase']); ?></td>
                            <td class="text-gray-500"><?php echo (int) $u['calls']; ?></td>
                            <td class="text-gray-500"><?php echo number_format((int) $u['pt'], 0, ',', ' '); ?></td>
                            <td class="text-gray-500"><?php echo number_format((int) $u['ct'], 0, ',', ' '); ?></td>
                            <td class="text-gray-500"><?php echo round((int) $u['ms'] / 1000); ?> с</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- ============ Последние вызовы LLM ============ -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-clock-rotate-left mr-1.5 text-gray-400"></i>Последние вызовы</p>
                <?php if (empty($recent)): ?>
                    <p class="n2j-hint mt-2">Пока пусто.</p>
                <?php else: ?>
                <ul class="mt-2 space-y-1 text-xs">
                    <?php foreach ($recent as $u): ?>
                    <li class="flex flex-wrap items-baseline gap-x-2 border-b border-gray-50 pb-1 dark:border-gray-800">
                        <span class="font-mono font-bold text-gray-600 dark:text-gray-300"><?php echo HTML::chars($u['phase']); ?></span>
                        <span class="text-gray-400"><?php echo HTML::chars(mb_substr((string) $u['model'], 0, 40)); ?></span>
                        <span class="ml-auto text-gray-500"><?php echo (int) $u['prompt_tokens']; ?>+<?php echo (int) $u['completion_tokens']; ?> т · <?php echo round((int) $u['duration_ms'] / 1000, 1); ?> с</span>
                        <?php if ( ! empty($u['model_code'])): ?>
                            <a href="/3d/<?php echo HTML::chars($u['model_code']); ?>" class="font-mono text-blue-500 hover:underline"><?php echo HTML::chars($u['model_code']); ?></a>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('570/admin_script');
