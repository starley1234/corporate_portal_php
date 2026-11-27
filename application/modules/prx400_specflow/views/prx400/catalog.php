<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx400_specflow/catalog — справочник НСИ (MVP): список + наполнение.
 *
 * Правильное место справочника — отдельный модуль НСИ/MDM (общий для
 * сервисов); до его появления позиции ведутся здесь и матчатся при разборе.
 *
 * Импорт CSV/XLSX — Service_Prx400_CatalogImport; отчёт последнего импорта
 * ($import_report) приходит одноразово из сессии после PRG.
 *
 * Внешний НСИ (PRX400_CATALOG_URL, провайдер только для чтения): страница
 * ищет в нём по запросу, формы добавления/импорта/удаления скрыты — справочник
 * ведётся в своей системе, здесь только подбор кодов.
 *
 * Переменные: $catalog (страница), $total, $query, $page_no, $pages, $per,
 * $per_options, $import_report, $max_import, $max_import_rows, $can_edit, $flash, $csrf,
 * $catalog_provider, $catalog_writable, $catalog_count (NULL — неизвестно).
 */
$editable = $can_edit && $catalog_writable;
$field_ru = ['code' => 'код', 'name' => 'наименование', 'unit' => 'ед. изм.', 'okei_code' => 'ОКЕИ', 'keywords' => 'ключевые слова'];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Оцифровка спецификаций', '/prx400_specflow'], ['Справочник НСИ']],
        'title' => 'Справочник НСИ',
        'pill' => ['prx400 · номенклатура', 'fa-book'],
        'lead' => 'Коды номенклатуры для матчинга позиций: чем полнее справочник, тем больше строк зелёных без вашего участия. Совпадение по наименованию и ключевым словам — нечёткое.',
        'actions' => [['label' => 'Запомненные сопоставления', 'href' => '/prx400_specflow/aliases', 'icon' => 'fa-link', 'role' => 'secondary']],
    ]); ?>
<?php if ( ! $catalog_writable): ?>
    <div class="prx-card mt-5 border-l-4 border-blue-500" role="note">
        <h2 class="prx-h3"><i class="fas fa-plug text-blue-600"></i> Подключён внешний справочник НСИ: <span class="font-mono"><?php echo HTML::chars($catalog_provider); ?></span></h2>
        <p class="prx-hint mt-2">Матчинг и подбор кодов идут по внешней системе<?php echo $catalog_count !== NULL ? ' ('.number_format((int) $catalog_count, 0, ',', ' ').' позиций)' : ''; ?>. Справочник ведётся там: добавление, импорт и удаление здесь отключены. Новую позицию заведите во внешнем НСИ — следующий разбор её увидит; решения операторов «наименование → код» по-прежнему запоминаются здесь.</p>
    </div>
<?php endif; ?>

    <?php echo View::factory('partials/flash', ['flash' => $flash]); ?>

<?php if (is_array($import_report)): $r = $import_report; ?>
    <section class="prx-card mt-5" aria-live="polite">
        <h2 class="prx-h3">
<?php if ($r['applied']): ?>
            <i class="fas fa-circle-check text-emerald-600"></i> Импорт применён
<?php elseif ($r['error_count'] > 0): ?>
            <i class="fas fa-triangle-exclamation text-red-600"></i> Файл не импортирован — исправьте строки с ошибками
<?php else: ?>
            <i class="fas fa-magnifying-glass text-blue-600"></i> Проверка пройдена — справочник не изменён
<?php endif; ?>
            <span class="ml-1 text-sm font-normal text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) ($r['file'] ?? '')); ?></span>
        </h2>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
            <div><dt class="text-gray-500 dark:text-gray-400">Строк в файле</dt><dd class="text-lg font-semibold"><?php echo (int) $r['total']; ?></dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400"><?php echo $r['applied'] ? 'Создано' : 'Будет создано'; ?></dt><dd class="text-lg font-semibold text-emerald-700 dark:text-emerald-400"><?php echo (int) $r['created']; ?></dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400"><?php echo $r['applied'] ? 'Обновлено' : 'Будет обновлено'; ?></dt><dd class="text-lg font-semibold text-blue-700 dark:text-blue-400"><?php echo (int) $r['updated']; ?></dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Без изменений</dt><dd class="text-lg font-semibold"><?php echo (int) $r['unchanged']; ?></dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Ошибок</dt><dd class="text-lg font-semibold <?php echo $r['error_count'] ? 'text-red-600' : ''; ?>"><?php echo (int) $r['error_count']; ?></dd></div>
        </dl>
        <p class="prx-hint mt-2">Столбцы: <?php echo HTML::chars(implode(', ', array_map(static fn ($f) => $field_ru[$f] ?? $f, $r['columns']))); ?><?php if ($r['ignored']): ?>; пропущены: <?php echo HTML::chars(implode(', ', array_slice($r['ignored'], 0, 12))); ?><?php endif; ?>.</p>
<?php if ($r['errors']): ?>
        <div class="mt-3 overflow-x-auto">
            <table class="prx-table">
                <thead><tr><th>Строка</th><th>Поле</th><th>Проблема</th><th>Значение</th></tr></thead>
                <tbody>
<?php foreach ($r['errors'] as $e): ?>
                <tr>
                    <td class="font-mono text-xs"><?php echo (int) $e['line']; ?></td>
                    <td><?php echo HTML::chars($field_ru[$e['field']] ?? $e['field']); ?></td>
                    <td><?php echo HTML::chars(Prx400_Messages::text($e['code'])); ?><?php if ( ! empty($e['first_line'])): ?> (впервые — строка <?php echo (int) $e['first_line']; ?>)<?php endif; ?></td>
                    <td class="max-w-[280px] truncate font-mono text-xs text-gray-500"><?php echo HTML::chars((string) $e['value']); ?></td>
                </tr>
<?php endforeach; ?>
                </tbody>
            </table>
<?php if ($r['error_count'] > count($r['errors'])): ?>
            <p class="prx-hint mt-2">Показаны первые <?php echo count($r['errors']); ?> из <?php echo (int) $r['error_count']; ?> ошибок.</p>
<?php endif; ?>
        </div>
<?php endif; ?>
    </section>
<?php endif; ?>

    <div class="mt-5 gap-5 space-y-5 lg:grid lg:grid-cols-3 lg:items-start lg:space-y-0">
        <section class="prx-card lg:col-span-2 overflow-x-auto p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
                <h2 class="prx-h3"><?php echo $catalog_writable ? 'Позиции справочника' : 'Поиск во внешнем НСИ'; ?> <span class="prx-count ml-1"><?php echo (int) $total; ?></span></h2>
                <div class="flex flex-wrap items-center gap-2">
                    <form method="get" action="/prx400_specflow/catalog" class="flex gap-2" role="search">
                        <input class="prx-input w-56" type="search" name="q" value="<?php echo HTML::chars($query); ?>" placeholder="Код, наименование, слово" aria-label="Поиск по справочнику">
                        <button class="prx-btn-secondary prx-btn-sm" type="submit"><i class="fas fa-magnifying-glass"></i><span class="sr-only">Найти</span></button>
                    </form>
<?php if ($catalog_writable): ?>
                    <a class="prx-btn-secondary prx-btn-sm" href="/prx400_specflow/catalog_export" title="CSV в формате импорта"><i class="fas fa-download"></i>CSV</a>
<?php endif; ?>
                </div>
            </div>
<?php if (empty($catalog)): ?>
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400"><?php echo $query !== ''
                ? 'По запросу ничего не найдено.'
                : ( ! $catalog_writable ? 'Введите код или слова наименования — поиск идёт во внешнем справочнике.' : 'Справочник пуст — все позиции спецификаций будут помечены «новая». Загрузите выгрузку НСИ (CSV/XLSX) или добавьте запись формой справа.'); ?></p>
<?php else: ?>
            <table class="prx-table">
                <thead><tr><th>Код</th><th>Наименование</th><th>Ед.</th><th>ОКЕИ</th><th>Ключевые слова</th><th></th></tr></thead>
                <tbody>
<?php foreach ($catalog as $c): ?>
                <tr>
                    <td class="font-mono text-xs font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($c['code']); ?></td>
                    <td class="max-w-[360px]"><span class="block text-sm text-gray-900 dark:text-white"><?php echo HTML::chars($c['name']); ?></span></td>
                    <td><?php echo HTML::chars((string) ($c['unit'] ?? '')); ?></td>
                    <td class="font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) ($c['okei_code'] ?? '')); ?></td>
                    <td class="max-w-[200px]"><span class="block truncate text-xs text-gray-400" title="<?php echo HTML::chars((string) ($c['keywords'] ?? '')); ?>"><?php echo HTML::chars((string) ($c['keywords'] ?? '')); ?></span></td>
                    <td class="text-right">
<?php if ($editable AND isset($c['id'])): ?>
                        <form method="post" action="/prx400_specflow/catalog_delete/<?php echo (int) $c['id']; ?>" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <button type="submit" class="prx-btn-danger prx-btn-sm" data-prx-arm="Удалить запись справочника? Двойное нажатие."><i class="fas fa-trash"></i><span class="sr-only">Удалить</span></button>
                        </form>
<?php endif; ?>
                    </td>
                </tr>
<?php endforeach; ?>
                </tbody>
            </table>
<?php if ($pages > 1): ?>
            <?php echo View::factory('partials/pagination', [
                'total' => $total, 'page' => $page_no, 'pages' => $pages, 'per' => $per, 'per_options' => $per_options,
                'url_base' => '/prx400_specflow/catalog', 'params' => $query !== '' ? ['q' => $query] : [],
            ]); ?>
<?php endif; ?>
<?php endif; ?>
        </section>

        <aside class="space-y-5">
<?php if ($editable): ?>
        <div class="prx-card">
            <h2 class="prx-h3">Загрузить выгрузку НСИ</h2>
            <form method="post" action="/prx400_specflow/catalog_import" enctype="multipart/form-data" class="mt-4 space-y-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <label class="block">
                    <span class="prx-label">CSV или XLSX, до <?php echo HTML::chars($max_import); ?> и <?php echo number_format((int) $max_import_rows, 0, ',', ' '); ?> строк</span>
                    <input type="file" name="file" required
                           accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                           class="prx-input mt-1 w-full file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white">
                </label>
                <p class="prx-hint">Первая строка — заголовок: <strong>Код</strong>, <strong>Наименование</strong> (обязательно), Ед. изм., ОКЕИ, Ключевые слова. Остальные столбцы выгрузки пропускаются. Существующие коды обновляются, отсутствующие в файле — не удаляются.</p>
                <div class="grid grid-cols-2 gap-2">
                    <button class="prx-btn-secondary justify-center" type="submit" name="apply" value="0"><i class="fas fa-magnifying-glass"></i>Проверить</button>
                    <button class="prx-btn-cta justify-center" type="submit" name="apply" value="1"><i class="fas fa-file-import"></i>Импортировать</button>
                </div>
                <p class="prx-hint">«Проверить» ничего не меняет. Ошибка хотя бы в одной строке — файл не применяется, в отчёте номера строк.</p>
            </form>
        </div>
<?php endif; ?>
<?php if ($catalog_writable): ?>
        <div class="prx-card">
            <h2 class="prx-h3">Добавить позицию</h2>
<?php if ( ! $can_edit): ?>
            <p class="prx-hint mt-3">Изменение справочника доступно с правом prx400.run.</p>
<?php else: ?>
            <form method="post" action="/prx400_specflow/catalog" class="mt-4 space-y-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <label class="block">
                    <span class="prx-label">Код номенклатуры *</span>
                    <input class="prx-input mt-1 w-full font-mono" name="code" maxlength="64" required placeholder="NSI-0001">
                </label>
                <label class="block">
                    <span class="prx-label">Наименование *</span>
                    <input class="prx-input mt-1 w-full" name="name" maxlength="500" required placeholder="Гайка шестигранная М12 ГОСТ 5915-70">
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="prx-label">Ед. изм.</span>
                        <input class="prx-input mt-1 w-full" name="unit" maxlength="16" value="шт.">
                    </label>
                    <label class="block">
                        <span class="prx-label">Код ОКЕИ</span>
                        <input class="prx-input mt-1 w-full font-mono" name="okei_code" maxlength="8" placeholder="796">
                    </label>
                </div>
                <label class="block">
                    <span class="prx-label">Ключевые слова</span>
                    <input class="prx-input mt-1 w-full" name="keywords" maxlength="500" placeholder="крепёж метиз гайка м12">
                    <span class="prx-hint mt-1">Повышают шансы матча: сокращения, синонимы, ГОСТ-марки.</span>
                </label>
                <button class="prx-btn-cta w-full justify-center" type="submit"><i class="fas fa-plus"></i>Добавить в справочник</button>
            </form>
<?php endif; ?>
            <p class="prx-hint mt-3">Матчинг нечёткий: «Гайка М12 ГОСТ 5915-70 цинк» найдёт «Гайка шестигранная М12…». ≥92% — автоматическая привязка, 65–92% — на подтверждение оператору.</p>
        </div>
<?php endif; ?>
        </aside>
    </div>
</div>
