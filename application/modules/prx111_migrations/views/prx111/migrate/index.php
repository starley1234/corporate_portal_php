<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница миграций (prx111_migrations).
 *
 * Раньше вьюха зависела от класса Component (render/begin/end) из чужого
 * проекта — здесь его нет, и страница падала «Class "Component" not found».
 * Теперь — самодостаточный HTML на общей сборке Tailwind (app.css) и app.js:
 * подтверждение «Применить» — data-prx-confirm, без app.js кнопка срабатывала бы сразу.
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
    <title>Миграции базы данных · PRX</title>
    <script><?php echo $dark_init; ?></script>
    <link rel="stylesheet" href="/assets/css/app.css" />
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
    <script src="/assets/js/app.js"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800 dark:bg-gray-900 dark:text-gray-100">
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Администрирование', '/admin'], ['Миграции']],
        'title'  => 'Миграции',
        'pill'   => ['PRX111 · схема базы данных', 'fa-database'],
        'lead'   => 'Проверка и обновление структуры базы без командной строки.',
    ]); ?>

    <div class="mt-5 space-y-5">
    <?php if ( ! empty($configuration_warning)): ?>
        <div role="alert" class="prx-flash prx-flash-err block"><strong>Ошибка настройки баз:</strong> <?php echo HTML::chars($configuration_warning); ?></div>
    <?php endif; ?>

    <?php if ($message):
        $message_flash = $message_type === 'success' ? 'prx-flash-ok'
            : ($message_type === 'error' ? 'prx-flash-err' : 'prx-flash-warn');
        $message_icon = $message_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation';
    ?>
        <div role="<?php echo $message_type === 'error' ? 'alert' : 'status'; ?>" class="prx-flash <?php echo $message_flash; ?>">
            <i class="fas <?php echo $message_icon; ?>"></i>
            <span><?php echo HTML::chars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ( ! empty($operation_log)): ?>
        <div class="prx-card" role="status">
            <h2 class="prx-h3 mb-2">Результат запуска</h2>
            <div class="space-y-1 font-mono text-xs">
                <?php foreach ($operation_log as $entry): ?><p class="<?php echo $entry['level']==='error'?'text-rose-600':($entry['level']==='ok'?'text-emerald-600':'text-gray-600 dark:text-gray-300'); ?>"><?php echo HTML::chars($entry['message']); ?></p><?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="prx-card">
            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Найдено</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo count($inventory); ?> <span class="text-sm font-normal text-gray-400">миграций</span></p>
        </div>
        <div class="prx-card">
            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Применено</p>
            <p class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400"><?php echo $applied_count; ?> <span class="text-sm font-normal text-gray-400">миграций</span></p>
        </div>
        <div class="prx-card">
            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">Ожидает</p>
            <p class="mt-1 text-2xl font-bold <?php echo $pending ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white'; ?>"><?php echo count($pending); ?> <span class="text-sm font-normal text-gray-400">миграций</span></p>
        </div>
    </div>

    <!-- Обновление схемы -->
    <div class="prx-card">
        <h2 class="prx-h3">Обновление схемы</h2>
        <p class="prx-hint">Каждая миграция выполняется в отдельной транзакции. При ошибке текущая миграция откатывается.</p>

        <?php if ($pending): ?>
            <div class="prx-flash prx-flash-warn mt-4 block">
                Будут применены: <strong><?php echo count($pending); ?></strong>. Не закрывайте страницу до появления результата.
            </div>
            <form method="post" action="<?php echo HTML::chars($action_url); ?>" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo HTML::chars($csrf_token); ?>">
                <input type="hidden" name="operation" value="run">
                <button data-prx-confirm="Применить все ожидающие миграции базы данных?" data-prx-tone="process" data-prx-ok="Применить" type="submit" class="prx-btn-process">
                    <i class="fas fa-database" aria-hidden="true"></i>Применить ожидающие миграции
                </button>
            </form>
        <?php else: ?>
            <p class="mt-4 text-sm font-medium text-emerald-700 dark:text-emerald-300"><i class="fas fa-circle-check mr-2" aria-hidden="true"></i>Схема актуальна, запускать ничего не нужно.</p>
        <?php endif; ?>
    </div>

    <?php if ($orphans): ?>
        <div role="alert" class="prx-flash prx-flash-warn block">
            <strong>Записи без файлов миграций:</strong> применены на этой базе, но файла класса в коде больше нет (миграция переименована или модуль снят). Откатиться они не могут, а на свежем развёртывании и не выполнятся — «Забыть» удаляет только строку журнала, схему базы не трогает.
            <ul class="mt-2 space-y-1.5">
            <?php foreach ($orphans as $orphan_key => $orphan_at): ?>
                <li class="flex flex-wrap items-center gap-2">
                    <code class="font-mono text-xs"><?php echo HTML::chars((string) $orphan_key); ?></code>
                    <span class="text-xs text-gray-500 dark:text-gray-400">применена <?php echo HTML::chars(date('d.m.Y', strtotime((string) $orphan_at))); ?></span>
                    <form method="post" action="<?php echo HTML::chars($action_url); ?>" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo HTML::chars($csrf_token); ?>">
                        <input type="hidden" name="operation" value="forget">
                        <input type="hidden" name="orphan" value="<?php echo HTML::chars((string) $orphan_key); ?>">
                        <button type="submit" class="text-xs font-semibold text-rose-600 hover:underline dark:text-rose-400"
                                data-prx-confirm="Удалить из журнала запись о применённой миграции? Схема базы не меняется; запись исчезнет из этого списка.">Забыть</button>
                    </form>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Таблица миграций -->
    <div class="prx-card p-0 overflow-x-auto">
        <table class="prx-table">
            <caption class="px-4 py-3 text-left font-semibold text-gray-900 dark:text-white">Все миграции проекта</caption>
            <thead class="border-y border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:bg-gray-900/50 dark:text-gray-400">
                <tr><th class="px-4 py-2 font-semibold">Состояние</th><th class="px-4 py-2 font-semibold">Миграция</th><th class="px-4 py-2 font-semibold">Модуль</th><th class="px-4 py-2 font-semibold">База</th><th class="px-4 py-2 font-semibold">Применена</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($inventory as $row): ?>
                    <tr>
                        <td class="px-4 py-2">
                            <span class="prx-badge <?php echo $row['applied'] ? 'prx-badge-ok' : 'prx-badge-wait'; ?>"><span class="prx-dot"></span><?php echo $row['applied'] ? 'применена' : 'ожидает'; ?></span>
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

    <p><a href="/" class="text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">← На главную PRX</a></p>
    </div><!-- /space-y -->
</div>
</body></html>
