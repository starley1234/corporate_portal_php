<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница миграций (nj111_migrations).
 *
 * Раньше вьюха зависела от класса Component (render/begin/end) из чужого
 * проекта — здесь его нет, и страница падала «Class "Component" not found».
 * Теперь — самодостаточный HTML на общей сборке Tailwind (app.css) и app.js:
 * подтверждение «Применить» — data-n2j-confirm, без app.js кнопка срабатывала бы сразу.
 * Контракт данных прежний: inventory, pending, orphans, message,
 * message_type, operation_log, configuration_warning, csrf_token, action_url.
 */
$applied_count = count($inventory) - count($pending);
$dark_init = 'try{if(localStorage.getItem("theme")==="dark"||(!localStorage.getItem("theme")&&matchMedia("(prefers-color-scheme: dark)").matches))document.documentElement.classList.add("dark");}catch(e){}';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Миграции базы данных · N2J</title>
    <script><?php echo $dark_init; ?></script>
    <link rel="stylesheet" href="/assets/css/app.css" />
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
    <script src="/assets/js/app.js"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800 dark:bg-gray-900 dark:text-gray-100">
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div>
        <p class="text-xs font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">NJ111 · схема базы данных</p>
        <h1 class="n2j-h1 mt-1">Миграции</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Проверка и обновление структуры базы без командной строки.</p>
    </div>

    <?php if ( ! empty($configuration_warning)): ?>
        <div role="alert" class="n2j-flash n2j-flash-err block"><strong>Ошибка настройки баз:</strong> <?php echo HTML::chars($configuration_warning); ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div role="status" class="rounded-xl border p-4 text-sm <?php echo $message_type === 'success'
            ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200'
            : ($message_type === 'error'
                ? 'border-rose-300 bg-rose-50 text-rose-900 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-200'
                : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200'); ?>">
            <?php echo HTML::chars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty($operation_log)): ?>
        <div class="n2j-card" role="status">
            <h2 class="mb-2 font-semibold">Результат запуска</h2>
            <div class="space-y-1 font-mono text-xs">
                <?php foreach ($operation_log as $entry): ?><p class="<?php echo $entry['level']==='error'?'text-rose-600':($entry['level']==='ok'?'text-emerald-600':'text-gray-600 dark:text-gray-300'); ?>"><?php echo HTML::chars($entry['message']); ?></p><?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="n2j-card">
            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Найдено</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo count($inventory); ?> <span class="text-sm font-normal text-gray-400">миграций</span></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Применено</p>
            <p class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400"><?php echo $applied_count; ?> <span class="text-sm font-normal text-gray-400">миграций</span></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Ожидает</p>
            <p class="mt-1 text-2xl font-bold <?php echo $pending ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white'; ?>"><?php echo count($pending); ?> <span class="text-sm font-normal text-gray-400">миграций</span></p>
        </div>
    </div>

    <!-- Обновление схемы -->
    <div class="n2j-card">
        <h2 class="font-semibold text-gray-900 dark:text-white">Обновление схемы</h2>
        <p class="n2j-hint">Каждая миграция выполняется в отдельной транзакции. При ошибке текущая миграция откатывается.</p>

        <?php if ($pending): ?>
            <div class="n2j-flash n2j-flash-warn mt-4 block">
                Будут применены: <strong><?php echo count($pending); ?></strong>. Не закрывайте страницу до появления результата.
            </div>
            <form method="post" action="<?php echo HTML::chars($action_url); ?>" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo HTML::chars($csrf_token); ?>">
                <input type="hidden" name="operation" value="run">
                <button data-n2j-confirm="Применить все ожидающие миграции базы данных?" data-n2j-tone="process" data-n2j-ok="Применить" type="submit" class="n2j-btn-process">
                    <i class="fas fa-database" aria-hidden="true"></i>Применить ожидающие миграции
                </button>
            </form>
        <?php else: ?>
            <p class="mt-4 text-sm font-medium text-emerald-700 dark:text-emerald-300"><i class="fas fa-circle-check mr-2" aria-hidden="true"></i>Схема актуальна, запускать ничего не нужно.</p>
        <?php endif; ?>
    </div>

    <?php if ($orphans): ?>
        <div role="alert" class="n2j-flash n2j-flash-warn block">
            <strong>Записи без файлов миграций:</strong> <?php echo HTML::chars(implode(', ', array_keys($orphans))); ?>. Они не откатываются автоматически.
        </div>
    <?php endif; ?>

    <!-- Таблица миграций -->
    <div class="n2j-card p-0 overflow-x-auto">
        <table class="n2j-table">
            <caption class="px-4 py-3 text-left font-semibold text-gray-900 dark:text-white">Все миграции проекта</caption>
            <thead class="border-y border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:bg-gray-900/50 dark:text-gray-400">
                <tr><th class="px-4 py-2 font-semibold">Состояние</th><th class="px-4 py-2 font-semibold">Миграция</th><th class="px-4 py-2 font-semibold">Модуль</th><th class="px-4 py-2 font-semibold">База</th><th class="px-4 py-2 font-semibold">Применена</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($inventory as $row): ?>
                    <tr>
                        <td class="px-4 py-2">
                            <span class="n2j-badge <?php echo $row['applied'] ? 'n2j-badge-ok' : 'n2j-badge-run'; ?>">
                                <span class="inline-block h-1.5 w-1.5 rounded-full <?php echo $row['applied'] ? 'bg-emerald-500' : 'bg-amber-500'; ?>"></span><?php echo $row['applied'] ? 'применена' : 'ожидает'; ?>
                            </span>
                        </td>
                        <td class="px-4 py-2"><span class="font-mono text-xs"><?php echo HTML::chars($row['class']); ?></span></td>
                        <td class="px-4 py-2"><?php echo HTML::chars($row['module']); ?></td>
                        <td class="px-4 py-2"><?php echo HTML::chars($row['db_group']); ?></td>
                        <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo $row['applied_at'] ? HTML::chars($row['applied_at']) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( ! $inventory): ?>
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">Миграции не найдены</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p><a href="/" class="text-sm font-semibold text-blue-600 hover:underline">← На главную N2J</a></p>
</div>
</body></html>
