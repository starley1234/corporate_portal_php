<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Всего модулей -->
    <div class="prl-card">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Всего модулей</p>
                <h3 class="text-2xl font-bold mt-1"><?php echo count($modules); ?></h3>
            </div>
            <div class="w-12 h-12 bg-blue-50 dark:bg-blue-900/30 rounded-lg flex items-center justify-center text-blue-600 dark:text-blue-400">
                <i class="fas fa-cubes text-xl"></i>
            </div>
        </div>
        <div class="mt-4 flex items-center text-xs">
            <span class="text-emerald-500 flex items-center font-medium">
                <i class="fas fa-check-circle mr-1"></i>
                <?php echo $stats['active_count'] ?? 0; ?> активных
            </span>
        </div>
    </div>

    <!-- Статус Stable -->
    <div class="prl-card">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Production (Stable)</p>
                <h3 class="text-2xl font-bold mt-1"><?php echo $stats['stable_count'] ?? 0; ?></h3>
            </div>
            <div class="w-12 h-12 bg-emerald-50 dark:bg-emerald-900/30 rounded-lg flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                <i class="fas fa-shield-halved text-xl"></i>
            </div>
        </div>
        <?php // Доля — числом, без полосы: полосы убраны везде. ?>
        <p class="mt-4 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
            <?php echo (int) ($stats['stable_percent'] ?? 0); ?>% от всех модулей
        </p>
    </div>

    <!-- В разработке -->
    <div class="prl-card">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">В разработке (Alpha/Beta)</p>
                <h3 class="text-2xl font-bold mt-1"><?php echo $stats['dev_count'] ?? 0; ?></h3>
            </div>
            <div class="w-12 h-12 bg-amber-50 dark:bg-amber-900/30 rounded-lg flex items-center justify-center text-amber-600 dark:text-amber-400">
                <i class="fas fa-flask text-xl"></i>
            </div>
        </div>
        <div class="mt-4 flex items-center text-xs text-gray-500">
            <i class="fas fa-clock mr-1"></i> Требуют тестирования
        </div>
    </div>

    <!-- Системное состояние -->
    <div class="prl-card">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Последнее сканирование</p>
                <h3 class="prl-h3 mt-1 truncate max-w-[140px]">
                    <?php echo isset($last_scan) ? Date::fuzzy_span(strtotime($last_scan)) : 'Не проводилось'; ?>
                </h3>
            </div>
            <div class="w-12 h-12 bg-blue-50 dark:bg-blue-900/30 rounded-lg flex items-center justify-center text-blue-600 dark:text-blue-400">
                <i class="fas fa-sync-alt text-xl"></i>
            </div>
        </div>
        <div class="mt-4">
            <a href="/prl218/scan" class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium">
                Запустить сканирование <i class="fas fa-arrow-right ml-1"></i>
            </a>
        </div>
    </div>
</div>

<!-- Список последних обновлений (мини-таблица) -->
<div class="prl-card p-0 overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center">
        <h4 class="font-bold text-gray-700 dark:text-gray-200">Недавние изменения</h4>
        <a href="/prl218/modules" class="text-xs text-gray-500 transition-colors hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400">Все модули</a>
    </div>
    <div class="overflow-x-auto">
        <table class="prl-table">
            <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-500 uppercase text-xs tracking-wider">
                <tr>
                    <th class="px-4 py-2 font-semibold">Модуль</th>
                    <th class="px-4 py-2 font-semibold text-center">Версия</th>
                    <th class="px-4 py-2 font-semibold text-right">Статус</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php if (!empty($recent_modules)): ?>
                    <?php foreach ($recent_modules as $mod): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900 dark:text-white"><?php echo HTML::chars($mod->display_name); ?></div>
                            <div class="text-xs text-gray-400"><?php echo HTML::chars($mod->system_name); ?></div>
                        </td>
                        <td class="px-4 py-3 text-center font-mono text-xs">
                            v<?php echo HTML::chars($mod->version); ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <?php 
                                $status_classes = [
                                    'stable' => 'prl-badge-ok',
                                    'beta'   => 'prl-badge-info',
                                    'alpha'  => 'prl-badge-run',
                                    'archived' => 'prl-badge-wait'
                                ];
                                $class = $status_classes[$mod->status] ?? $status_classes['archived'];
                            ?>
                            <span class="prl-badge uppercase <?php echo $class; ?>"><span class="prl-dot"></span><?php echo HTML::chars($mod->status); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-gray-400 italic">Данные отсутствуют</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>