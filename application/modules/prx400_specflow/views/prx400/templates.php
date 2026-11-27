<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx400_specflow/templates — управление шаблонами разбора.
 *
 * Встроенные (config/templates/*.json) — можно вкл/выкл (оверлей в БД),
 * пользовательские JSON — загрузка/удаление. Изменения применяются с новой
 * задачи разбора (шаблоны уезжают в payload).
 *
 * Переменные: $registry, $can_edit, $flash, $csrf.
 */
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Оцифровка спецификаций', '/prx400_specflow'], ['Шаблоны']],
        'title' => 'Шаблоны разбора',
        'pill' => ['prx400 · config-driven', 'fa-layer-group'],
        'lead' => 'Шаблон — это якоря детекции, синонимы колонок и правила пропуска/итогов/математики. Новый формат документа = новый шаблон, код не меняется. Библиотека уезжает в задачу разбора целиком.',
    ]); ?>

    <?php echo View::factory('partials/flash', ['flash' => $flash]); ?>


    <div class="mt-5 gap-5 space-y-5 lg:grid lg:grid-cols-3 lg:items-start lg:space-y-0">
        <section class="prx-card lg:col-span-2 overflow-x-auto p-0">
            <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
                <h2 class="prx-h3">Библиотека <span class="prx-count ml-1"><?php echo count($registry); ?></span></h2>
            </div>
<?php if (empty($registry)): ?>
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Библиотека пуста — разбор невозможен (задача вернёт template_missing). Добавьте шаблон формой справа.</p>
<?php else: ?>
            <table class="prx-table">
                <thead><tr><th>Шаблон</th><th>Код</th><th>Источник</th><th>Якоря</th><th>Колонки</th><th>Состояние</th><th></th></tr></thead>
                <tbody>
<?php foreach ($registry as $t): ?>
                <tr class="<?php echo empty($t['enabled']) ? 'opacity-50' : ''; ?>">
                    <td class="max-w-[240px]"><span class="block truncate text-sm font-semibold text-gray-900 dark:text-white" title="<?php echo HTML::chars($t['title']); ?>"><?php echo HTML::chars($t['title']); ?></span></td>
                    <td class="font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($t['code']); ?></td>
                    <td><span class="prx-count"><?php echo $t['source'] === 'builtin' ? 'встроенный' : 'пользовательский'; ?></span></td>
                    <td class="text-sm"><?php echo (int) $t['anchors']; ?></td>
                    <td class="text-sm"><?php echo (int) $t['columns']; ?></td>
                    <td>
<?php if ($t['enabled']): ?>
                        <span class="prx-badge prx-badge-ok"><span class="prx-dot"></span>вкл</span>
<?php else: ?>
                        <span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>выкл</span>
<?php endif; ?>
                    </td>
                    <td class="text-right whitespace-nowrap">
<?php if ( ! empty($t['json'])): ?>
                        <details class="inline-block text-left">
                            <summary class="prx-btn-secondary prx-btn-sm cursor-pointer list-none">JSON</summary>
                            <pre class="mt-2 max-h-72 max-w-xl overflow-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900"><?php echo HTML::chars((string) json_encode($t['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                        </details>
<?php endif; ?>
<?php if ($can_edit): ?>
                        <form method="post" action="/prx400_specflow/template_enable" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="code" value="<?php echo HTML::chars($t['code']); ?>">
                            <input type="hidden" name="enable" value="<?php echo $t['enabled'] ? '0' : '1'; ?>">
                            <button type="submit" class="prx-btn-secondary prx-btn-sm"><?php echo $t['enabled'] ? 'Выключить' : 'Включить'; ?></button>
                        </form>
<?php if ($t['source'] === 'custom'): ?>
                        <form method="post" action="/prx400_specflow/template_delete" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="code" value="<?php echo HTML::chars($t['code']); ?>">
                            <button type="submit" class="prx-btn-danger prx-btn-sm" data-prx-arm="Удалить пользовательский шаблон? Двойное нажатие."><i class="fas fa-trash"></i><span class="sr-only">Удалить</span></button>
                        </form>
<?php endif; ?>
<?php endif; ?>
                    </td>
                </tr>
<?php endforeach; ?>
                </tbody>
            </table>
<?php endif; ?>
        </section>

        <aside class="prx-card">
            <h2 class="prx-h3">Загрузить шаблон</h2>
<?php if ( ! $can_edit): ?>
            <p class="prx-hint mt-3">Изменение библиотеки доступно с правом prx400.run.</p>
<?php else: ?>
            <form method="post" action="/prx400_specflow/templates" enctype="multipart/form-data" class="mt-4 space-y-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <label class="block">
                    <span class="prx-label">JSON-файл шаблона *</span>
                    <input type="file" name="tpl" accept="application/json,.json" required
                           class="prx-input mt-1 w-full file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white">
                </label>
                <button class="prx-btn-cta w-full justify-center" type="submit"><i class="fas fa-upload"></i>Добавить в библиотеку</button>
            </form>
<?php endif; ?>
            <p class="prx-hint mt-3">Файл до 64 КиБ. Обязательные поля: <code class="font-mono text-xs">template_id</code>, <code class="font-mono text-xs">template_name</code>, <code class="font-mono text-xs">detection_policy.anchor_keywords</code> (≥1), <code class="font-mono text-xs">table_rules.columns</code>. Коды встроенных шаблонов зарезервированы.</p>
            <p class="prx-hint mt-2">Отключённые шаблоны не уезжают в задачи — их якоря перестают влиять на детекцию. Встроенные отключаются оверлеем в БД, файлы модуля не меняются.</p>
        </aside>
    </div>
</div>
