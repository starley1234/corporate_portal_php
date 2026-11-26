<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100 — «Использование модулей»: лента событий, задач и внешних вызовов.
 * Переменные: $rows, $note, $modules, $window, $module, $result, $limit.
 */
$result_badges = ['ok' => ['prx-badge-ok', 'успех'], 'err' => ['prx-badge-err', 'ошибка'], 'run' => ['prx-badge-wait', 'в работе']];
$when = static function ($value): string {
    return $value ? date('d.m.Y H:i:s', strtotime((string) $value)) : '—';
};
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

<?php echo View::factory('partials/page_header', [
    'crumbs' => [['Администрирование', '/admin'], ['Использование модулей']],
    'title'  => 'Использование модулей',
    'pill'   => ['prx100 · платформа', 'fa-chart-simple'],
    'lead'   => 'Кто что делал и чем закончилось: доменные события (prx208), фоновые задачи (prx120), внешние API-вызовы (prx150). Источники не копятся — лента ведётся их журналами.',
    'actions' => [
        ['label' => 'Журнал событий', 'href' => '/prx208_events', 'icon' => 'fa-bolt', 'role' => 'secondary'],
        ['label' => 'Администрирование', 'href' => '/admin', 'icon' => 'fa-gear', 'role' => 'secondary'],
    ],
]); ?>

    <form method="get" action="/prx100/usage" class="mt-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="prx-label" for="u-module">Модуль</label>
            <select id="u-module" name="module" class="prx-input mt-1 w-56">
                <option value="">Все модули</option>
<?php foreach ($modules as $slug => $title): ?>
                <option value="<?php echo HTML::chars($slug); ?>"<?php echo $slug === $module ? ' selected' : ''; ?>><?php echo HTML::chars($title); ?></option>
<?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="prx-label" for="u-window">Период</label>
            <select id="u-window" name="window" class="prx-input mt-1 w-40">
<?php foreach (['1h' => 'Последний час', '24h' => '24 часа', '7d' => '7 дней', '30d' => '30 дней'] as $w => $label): ?>
                <option value="<?php echo $w; ?>"<?php echo $w === $window ? ' selected' : ''; ?>><?php echo $label; ?></option>
<?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="prx-label" for="u-result">Результат</label>
            <select id="u-result" name="result" class="prx-input mt-1 w-40">
                <option value=""<?php echo $result === '' ? ' selected' : ''; ?>>Все</option>
                <option value="ok"<?php echo $result === 'ok' ? ' selected' : ''; ?>>Успех</option>
                <option value="err"<?php echo $result === 'err' ? ' selected' : ''; ?>>Ошибка</option>
            </select>
        </div>
        <div>
            <button type="submit" class="prx-btn-secondary"><i class="fas fa-filter mr-1.5" aria-hidden="true"></i>Показать</button>
        </div>
        <p class="prx-hint ml-auto self-end pb-2">Показано последних: <?php echo count($rows); ?> (лимит <?php echo (int) $limit; ?>)</p>
    </form>

<?php if ($note): ?>
    <p class="prx-flash prx-flash-warn mt-3 block" role="status"><?php echo HTML::chars((string) $note); ?></p>
<?php endif; ?>

    <section class="prx-card mt-3 overflow-x-auto p-0" aria-labelledby="prx100-usage-table">
        <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
            <h2 id="prx100-usage-table" class="prx-h3">Лента</h2>
        </div>
<?php if (empty($rows)): ?>
        <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">В выбранном окне записей нет: измените период, модуль или результат.</p>
<?php else: ?>
        <table class="prx-table min-w-[860px]">
            <thead><tr><th>Когда</th><th>Источник</th><th>Модуль</th><th>Кто</th><th>Что</th><th>Результат</th><th></th></tr></thead>
            <tbody>
<?php foreach ($rows as $row): ?>
            <tr>
                <td class="whitespace-nowrap tabular-nums text-xs text-gray-500 dark:text-gray-400"><?php echo $when($row['ts']); ?></td>
                <td class="text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $row['source']); ?></td>
                <td class="whitespace-nowrap font-mono text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $row['module']); ?></td>
                <td class="text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $row['actor']); ?></td>
                <td class="min-w-0">
                    <span class="block break-words text-sm text-gray-800 dark:text-gray-100"><?php echo HTML::chars((string) $row['what']); ?></span>
<?php if ((string) $row['detail'] !== ''): ?>
                    <span class="block break-words text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $row['detail']); ?></span>
<?php endif; ?>
                </td>
                <td><span class="prx-badge <?php echo $result_badges[$row['result']][0]; ?>"><span class="prx-dot"></span><?php echo $result_badges[$row['result']][1]; ?></span></td>
                <td class="text-right"><a class="prx-btn-secondary prx-btn-sm" href="<?php echo HTML::chars((string) $row['href']); ?>" title="Подробности в источнике"><i class="fas fa-arrow-up-right-from-square text-xs"></i><span class="sr-only">Открыть</span></a></td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php endif; ?>
    </section>
</div>
