<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
$comparison = is_array($comparison ?? NULL) ? $comparison : NULL;
$runs = is_array($runs ?? NULL) ? $runs : [];
$score_delta = $comparison['score_delta'] ?? NULL;
$delta_class = $score_delta === NULL ? 'text-gray-500' : ($score_delta > 0 ? 'text-emerald-700 dark:text-emerald-300' : ($score_delta < 0 ? 'text-rose-700 dark:text-rose-300' : 'text-gray-500'));
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Сервисы', '/services'], ['AI Watch']],
        'title'  => 'AI Watch',
        'lead'   => 'Смотрите, что изменилось между завершёнными аудитами одного эндпоинта. Источник оценок — отчёты /aiscan; повторного скоринга здесь нет.',
        'actions'=> [
            ['label' => 'Запустить аудит', 'href' => '/aiscan/simple', 'icon' => 'fa-shield-halved', 'role' => 'process'],
        ],
    ]); ?>

    <?php if ($comparison): ?>
    <section class="mt-6 n2j-card" aria-labelledby="watch-diff-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-blue-600 dark:text-blue-300">Последнее сравнение</p>
                <h2 id="watch-diff-title" class="n2j-h2 mt-1"><?php echo HTML::chars($comparison['model']); ?></h2>
                <p class="mt-1 break-all text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($comparison['endpoint']); ?></p>
            </div>
            <?php if ($comparison['regression']): ?>
            <span class="n2j-badge n2j-badge-err">Есть признаки регрессии</span>
            <?php else: ?>
            <span class="n2j-badge n2j-badge-ok">Новых признаков регрессии нет</span>
            <?php endif; ?>
        </div>
        <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400">Оценка безопасности</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">
                    <?php echo $comparison['current_score'] === NULL ? '—' : (int) $comparison['current_score']; ?>
                    <?php if ($comparison['score_delta'] !== NULL): ?>
                    <span class="text-sm <?php echo $delta_class; ?>"><?php echo ((int) $comparison['score_delta'] > 0 ? '+' : '').(int) $comparison['score_delta']; ?></span>
                    <?php endif; ?>
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Текущий прогон #<?php echo (int) $comparison['current_id']; ?> · предыдущий #<?php echo (int) $comparison['previous_id']; ?></p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400">Провалы проверок</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) $comparison['current_fail']; ?> <span class="text-sm"><?php echo ((int) $comparison['fail_delta'] > 0 ? '+' : '').(int) $comparison['fail_delta']; ?></span></p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Изменение относительно предыдущего аудита</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700 sm:col-span-2">
                <p class="text-xs text-gray-500 dark:text-gray-400">Новые строки OWASP со статусом «уязвимо»</p>
                <?php if ($comparison['new_regressions']): ?>
                <p class="mt-2 flex flex-wrap gap-2">
                    <?php foreach ($comparison['new_regressions'] as $code): ?>
                    <span class="n2j-badge n2j-badge-err"><?php echo HTML::chars($code); ?></span>
                    <?php endforeach; ?>
                </p>
                <?php else: ?>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Новых проблемных строк матрицы нет. Это сравнение не заменяет чтение отчётов.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3 text-sm">
            <a class="n2j-link" href="/aiscan/result/<?php echo (int) $comparison['current_id']; ?>">Текущий отчёт →</a>
            <a class="n2j-link" href="/aiscan/result/<?php echo (int) $comparison['previous_id']; ?>">Предыдущий отчёт →</a>
        </div>
        <p class="mt-4 border-t border-gray-100 pt-3 text-xs leading-relaxed text-gray-500 dark:border-gray-700 dark:text-gray-400">Сравнение — подсказка для оператора. Разные наборы проверок или настройки могут менять оценки; для решения о выпуске сравните паспорта аудитов.</p>
    </section>
    <?php else: ?>
    <section class="mt-6 n2j-empty">
        <span class="n2j-empty-icon"><i class="fas fa-chart-line" aria-hidden="true"></i></span>
        <h2 class="n2j-h3">Пока нет пары сопоставимых аудитов</h2>
        <p class="n2j-empty-text">Запустите два завершённых аудита одной модели на том же эндпоинте и с одинаковыми наборами проверок. Цели с разными адресами/моделями, пользовательские промпты и системные инструкции не сравниваются.</p>
        <a href="/aiscan/simple" class="n2j-btn-process mt-4">Запустить аудит <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i></a>
    </section>
    <?php endif; ?>

    <section class="mt-8" aria-labelledby="watch-history-title">
        <header class="mb-4 flex items-end justify-between gap-3 border-b border-gray-200 pb-3 dark:border-gray-700">
            <div>
                <h2 id="watch-history-title" class="n2j-h2">История аудитов</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Последние <?php echo count($runs); ?> прогонов из /aiscan.</p>
            </div>
            <a href="/aiscan" class="n2j-link text-sm">Все настройки сканера →</a>
        </header>
        <?php if ($runs): ?>
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="n2j-table min-w-full">
                <thead><tr><th scope="col">Прогон</th><th scope="col">Цель / модель</th><th scope="col">Оценка</th><th scope="col">Риск</th><th scope="col">Состояние</th></tr></thead>
                <tbody>
                <?php foreach ($runs as $run): ?>
                <tr>
                    <td class="whitespace-nowrap">
                        <?php if ($run['status'] === 'done'): ?><a class="n2j-link font-semibold" href="/aiscan/result/<?php echo (int) $run['id']; ?>">#<?php echo (int) $run['id']; ?></a><?php else: ?>#<?php echo (int) $run['id']; ?><?php endif; ?>
                        <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($run['created_at']); ?></div>
                    </td>
                    <td><span class="font-medium"><?php echo HTML::chars($run['model'] ?: $run['title']); ?></span><?php if ($run['target'] !== ''): ?><div class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($run['target']); ?></div><?php endif; ?></td>
                    <td><?php echo $run['score'] === NULL ? '—' : (int) $run['score'].' · '.HTML::chars($run['grade']); ?></td>
                    <td><?php echo $run['risk'] !== '' ? HTML::chars($run['risk']) : '—'; ?></td>
                    <td><?php echo HTML::chars($run['status']); ?><?php if ($run['fail'] !== NULL): ?><div class="text-xs text-gray-500 dark:text-gray-400">провалов: <?php echo (int) $run['fail']; ?></div><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="n2j-hint">История пока пуста или таблица /aiscan ещё не создана миграцией.</p>
        <?php endif; ?>
    </section>
</div>
