<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100/selfcheck — «Проверка сервера»: одна кнопка, отчёт по среде, БД,
 * миграциям, очереди, докам модулей и синтаксису всего php-кода.
 */

$counts = ['OK' => 0, 'WARN' => 0, 'FAIL' => 0];
if ($results !== NULL)
{
    foreach ($results as $row)
    {
        $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
    }
}

// цвет бейджа статуса
$badge = [
    'OK'   => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
    'WARN' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    'FAIL' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400',
];

?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dashboard" class="transition hover:text-emerald-600 dark:hover:text-emerald-400">Кабинет</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <a href="/admin" class="transition hover:text-emerald-600 dark:hover:text-emerald-400">Администрирование</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Проверка сервера</span>
    </nav>

    <h1 class="prx-h1 mt-2">
        <i class="fas fa-clipboard-check mr-2 text-emerald-600 dark:text-emerald-400"></i>Проверка сервера
    </h1>
    <p class="prx-hint max-w-3xl">
        Те же проверки, что и <code>bin/console dev:doctor</code>, плюс компиляция всех php-файлов (<code>php&nbsp;-l</code>).
        Ничего не исполняется и не меняется — только читается состояние. Полный прогон может занять до минуты.
    </p>

    <?php if ( ! empty($flash)): ?>
    <div class="prx-flash prx-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <form method="post" action="/prx100/selfcheck" class="mt-4">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
        <button type="submit" class="prx-btn-cta">
            <i class="fas fa-play mr-1.5"></i><span>Прогнать проверки</span>
        </button>
    </form>

    <?php if ($results !== NULL): ?>
    <div class="mt-5 max-w-4xl">
        <p class="text-xs font-semibold mb-2
            <?php echo $counts['FAIL'] > 0
                ? 'text-red-600 dark:text-red-400'
                : ($counts['WARN'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'); ?>">
            Итог: <?php echo $counts['OK']; ?> в порядке,
            <?php echo $counts['WARN']; ?> предупреждений,
            <?php echo $counts['FAIL']; ?> проблем
        </p>

        <div class="prx-card divide-y divide-gray-100 dark:divide-gray-800">
            <?php $group = NULL; ?>
            <?php foreach ($results as $row): ?>
                <?php if ($row['group'] !== $group): $group = $row['group']; ?>
                <p class="px-3.5 pt-3 pb-1 text-[11px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    <?php echo HTML::chars($group); ?>
                </p>
                <?php endif; ?>
                <div class="flex items-start gap-2.5 px-3.5 py-2">
                    <span class="shrink-0 inline-flex justify-center min-w-[44px] rounded px-1.5 py-0.5 text-[11px] font-bold <?php echo $badge[$row['status']] ?? ''; ?>">
                        <?php echo HTML::chars($row['status']); ?>
                    </span>
                    <span class="text-xs text-gray-700 dark:text-gray-300 break-words min-w-0">
                        <?php echo HTML::chars($row['message']); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
