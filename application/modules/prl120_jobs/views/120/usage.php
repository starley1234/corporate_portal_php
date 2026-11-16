<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prl120/usage — «Ресурсы и потребление»: отчёты воркеров (токены, время, ошибки).
 */
$n = function ($v) { return number_format((float) $v, 0, ',', ' '); };
?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/tasks" class="transition hover:text-emerald-600 dark:hover:text-emerald-400">Задачи</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Ресурсы и потребление</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
        <h1 class="prl-h1">
            <i class="fas fa-gauge-high mr-2 text-emerald-600 dark:text-emerald-400"></i>Ресурсы и потребление
        </h1>
        <div class="flex flex-wrap items-center gap-2">
        <a href="/prl120/budgets" class="prl-btn-secondary prl-btn-sm"><i class="fas fa-coins mr-1.5 text-xs text-gray-400"></i>Бюджеты</a>
        <div class="prl-seg gap-1">
            <?php foreach ($periods as $k => $label): ?>
            <a href="/prl120/usage?p=<?php echo $k; ?>" class="prl-seg-item<?php echo $k === $p ? ' is-active' : ''; ?>"><?php echo HTML::chars($label); ?></a>
            <?php endforeach; ?>
        </div>
        </div>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="prl-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Задач с отчётом</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo $n($jobs); ?></p>
        </div>
        <div class="prl-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Токенов LLM</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo $n($tokens); ?></p>
        </div>
        <div class="prl-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Вызовов LLM</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo $n($llm_calls); ?></p>
        </div>
        <div class="prl-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Чтений + поисков</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo $n($fetches); ?></p>
        </div>
        <div class="prl-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Время работы</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo $n($elapsed_h); ?> ч</p>
        </div>
    </div>

    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div class="prl-card">
            <p class="text-sm font-bold text-gray-900 dark:text-white">По модулям (каналам)</p>
            <div class="mt-2 overflow-x-auto"><table class="prl-table">
                <thead><tr class="text-left text-gray-400"><th class="py-1">Модуль</th><th>Задач</th><th>Токены</th><th>Ср. время</th></tr></thead>
                <tbody class="text-gray-700 dark:text-gray-300">
                <?php foreach ($by_channel as $r): ?>
                <tr class="border-t border-gray-50 dark:border-gray-700/50">
                    <td class="py-1.5 font-semibold"><?php echo HTML::chars($r['channel']); ?></td>
                    <td><?php echo $n($r['jobs']); ?></td>
                    <td><?php echo $n($r['tokens']); ?></td>
                    <td><?php echo $n(($r['ms'] ?? 0) / 1000); ?> с</td>
                </tr>
                <?php endforeach; ?>
                <?php if ( ! $by_channel): ?><tr><td colspan="4" class="py-2 text-gray-400">Отчётов пока нет — появятся после работы воркеров (нужна миграция prl120/004).</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>

        <div class="prl-card">
            <p class="text-sm font-bold text-gray-900 dark:text-white">По моделям</p>
            <div class="mt-2 overflow-x-auto"><table class="prl-table">
                <thead><tr class="text-left text-gray-400"><th class="py-1">Модель</th><th>Выз.</th><th>Токены</th></tr></thead>
                <tbody class="text-gray-700 dark:text-gray-300">
                <?php foreach ($by_model as $r): ?>
                <tr class="border-t border-gray-50 dark:border-gray-700/50">
                    <td class="py-1.5 font-mono"><?php echo HTML::chars(($r['provider'] ?: '?').'::'.($r['model'] ?: '?')); ?></td>
                    <td><?php echo $n($r['calls']); ?></td>
                    <td><?php echo $n($r['ptokens'] + $r['ctokens']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ( ! $by_model): ?><tr><td colspan="3" class="py-2 text-gray-400">Пока пусто.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>

        <div class="prl-card">
            <p class="text-sm font-bold text-gray-900 dark:text-white">По пользователям</p>
            <div class="mt-2 overflow-x-auto"><table class="prl-table">
                <thead><tr class="text-left text-gray-400"><th class="py-1">Пользователь</th><th>Задач</th><th>Токены</th><th>Ошиб.</th></tr></thead>
                <tbody class="text-gray-700 dark:text-gray-300">
                <?php foreach ($by_user as $r): ?>
                <tr class="border-t border-gray-50 dark:border-gray-700/50">
                    <td class="py-1.5 font-semibold"><?php echo HTML::chars($r['name']); ?></td>
                    <td><?php echo $n($r['jobs']); ?></td>
                    <td><?php echo $n($r['tokens']); ?></td>
                    <td class="<?php echo (int) $r['errs'] > 0 ? 'font-bold text-rose-500' : 'text-gray-400'; ?>"><?php echo (int) $r['errs']; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ( ! $by_user): ?><tr><td colspan="4" class="py-2 text-gray-400">Пока пусто.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>

    <div class="prl-card mt-4">
        <p class="text-sm font-bold text-gray-900 dark:text-white">Последние ошибки воркеров <span class="text-xs font-normal text-gray-400">(для анализа; полный текст — в отчёте задачи)</span></p>
        <ul class="mt-2 space-y-1.5 text-xs text-gray-700 dark:text-gray-300">
            <?php foreach ($errors as $r): ?>
            <li class="flex flex-wrap items-center gap-2 border-b border-gray-50 pb-1.5 dark:border-gray-700/50">
                <span class="font-mono text-gray-400">#<?php echo (int) $r['job_id']; ?></span>
                <span class="prl-count"><?php echo HTML::chars($r['channel']); ?></span>
                <span class="font-semibold"><?php echo HTML::chars($r['name']); ?></span>
                <span class="min-w-0 flex-1 truncate"><?php echo HTML::chars(mb_substr(implode(' | ', json_decode((string) $r['errors'], TRUE) ?: []), 0, 220)); ?></span>
            </li>
            <?php endforeach; ?>
            <?php if ( ! $errors): ?><li class="text-gray-400">Ошибок с отчётами не было.</li><?php endif; ?>
        </ul>
    </div>
</div>
