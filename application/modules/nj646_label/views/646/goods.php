<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php

/**
 * Админка: товары и производители с этикеток (/nj646/admin/goods).
 *
 * Владелец (03.10.2026): «сопутствующую информацию о производителе и товаре с
 * этикетки тоже в БД сохраняй (для проекта в целом)»; и следом: «по умолчанию
 * режим отображение, но можно переключить на редактирование, и нет из товара
 * ссылки на разбор!».
 *
 * Поэтому карточка открывается НА ЧТЕНИЕ (как в справочнике веществ): подписи и
 * значения, ссылки на разбор и на публичную карточку. Правка — по кнопке
 * «править»: показывается та же форма, что и раньше, с кнопкой «отмена».
 * Из товара ведут ссылки «последний разбор» (/label/analysis/<id>) и «все
 * разборы» (/label/product/<id>) — раньше из админки разбор было не открыть.
 *
 * ПРАВИЛА АДМИНКИ: никаких GET-параметров (поиск — POST в сессию, см.
 * action_goods_filter), все действия — POST + CSRF, удаление в два клика
 * (window.confirm на платформе запрещён), кнопки не мельче min-h-[36px].
 */

$input_class = 'n2j-input mt-1 w-full';
$cap_class   = 'text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500';
$btn_edit    = 'n2j-btn-secondary n2j-btn-sm';

/** Ячейка карточки просмотра: подпись + значение (значение может быть HTML). */
$cell = function ($caption, $value, $html = FALSE) use ($cap_class) {
    $value = (string) $value;

    if ( ! $html AND trim($value) === '')
    {
        return '';
    }

    return '<div><dt class="'.$cap_class.'">'.HTML::chars($caption).'</dt>'
        .'<dd class="mt-0.5 break-words text-sm text-gray-800 dark:text-gray-100">'
        .($html ? $value : HTML::chars($value)).'</dd></div>';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <p class="n2j-hint">
        <a href="/nj646/admin" class="hover:underline">Админка модуля</a> ·
        <a href="/nj646/admin/catalog" class="hover:underline">Справочник веществ</a>
    </p>
    <div class="mt-1 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="n2j-h1">Товары и производители</h1>
            <p class="n2j-hint">
                Разобрано из строк этикеток автоматически (без модели) и лежит в общих таблицах проекта:
                производители связаны с онтологией предприятий и справочником организаций по ИНН/ОГРН.
            </p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            <span class="rounded-lg bg-gray-100 px-2.5 py-1 font-bold text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                производителей: <?php echo (int) $counts['producers']; ?>
            </span>
            <span class="rounded-lg bg-gray-100 px-2.5 py-1 font-bold text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                товаров: <?php echo (int) $counts['products']; ?>
            </span>
            <?php if ( ! $modules['ontology']): ?>
                <span class="rounded-lg bg-amber-50 px-2.5 py-1 font-semibold text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">nj607 не подключён</span>
            <?php endif; ?>
            <?php if ( ! $modules['orgs']): ?>
                <span class="rounded-lg bg-amber-50 px-2.5 py-1 font-semibold text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">nj594 не подключён</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="mt-4 flex items-start gap-2.5 rounded-xl border p-3.5 text-sm <?php echo $flash_kind === 'warn'
            ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200'
            : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/80 dark:bg-emerald-950/40 dark:text-emerald-300'; ?>">
            <i class="fas <?php echo $flash_kind === 'warn' ? 'fa-triangle-exclamation text-amber-500' : 'fa-check-circle text-emerald-600'; ?> mt-0.5 shrink-0"></i>
            <span><?php echo HTML::chars($flash); ?></span>
        </div>
    <?php endif; ?>

    <!-- Поиск и сборка товаров: POST в сессию (у админки нет GET-параметров). -->
    <div class="mt-4 flex flex-wrap items-end gap-2">
        <form method="post" action="/nj646/admin/goods_filter" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <input type="text" name="q" value="<?php echo HTML::chars($q); ?>" autocomplete="off"
                   placeholder="Название, ИНН, ОГРН, штрихкод…"
                   class="n2j-input w-full sm:w-80">
            <button type="submit" class="n2j-btn-process">
                <i class="fas fa-search mr-1.5 text-xs"></i>найти
            </button>
            <?php if ($q !== ''): ?>
                <span class="text-xs text-gray-500 dark:text-gray-400">фильтр: «<?php echo HTML::chars($q); ?>»</span>
                <button type="submit" name="q" value="" class="n2j-btn-secondary">
                    сбросить
                </button>
            <?php endif; ?>
        </form>

        <?php // Старые разборы (до появления товаров) — в перечень товаров одной
              // кнопкой: списки модуля показывают ТОВАР, и запись без товара
              // читалась как «63/100 · нейтрально» (жалоба владельца). ?>
        <form method="post" action="/nj646/admin/goods_backfill" class="ml-auto flex items-center gap-2">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <button type="submit" class="n2j-btn-secondary">
                <i class="fas fa-rotate mr-1.5 text-xs"></i>собрать товары из старых разборов
            </button>
        </form>

        <?php // Пищевая ценность появилась миграцией 005 — у старых карточек
              // таблица пустая, хотя текст этикетки в разборе есть. ?>
        <form method="post" action="/nj646/admin/goods_nutrition_backfill" class="flex items-center gap-2">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <button type="submit" class="n2j-btn-secondary">
                <i class="fas fa-table mr-1.5 text-xs"></i>дочитать пищевую ценность
            </button>
        </form>
    </div>

    <div class="mt-5 gap-6 space-y-6 lg:grid lg:grid-cols-2 lg:items-start lg:space-y-0">

        <!-- ---------------------------- производители ---------------------------- -->
        <section class="n2j-card p-0">
            <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                <h2 class="n2j-h3">Производители и импортёры</h2>
                <span class="n2j-badge n2j-badge-wait">
                    <?php echo count($producers); ?>
                </span>
            </div>

            <?php if ( ! $producers): ?>
                <p class="px-4 py-5 text-sm text-gray-500 sm:px-5 dark:text-gray-400">
                    Пока пусто: строки изготовителя появятся здесь, как только человек пришлёт фото этикетки или текст
                    с производителем.
                </p>
            <?php endif; ?>

            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($producers as $row): ?>
                    <details class="group px-4 py-3 sm:px-5" data-nj646-card>
                        <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 text-sm marker:content-none">
                            <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($row['name']); ?></span>
                            <span class="n2j-badge n2j-badge-wait">
                                <?php echo HTML::chars(Service_Nj646_About::roles()[(string) $row['role']] ?? (string) $row['role']); ?>
                            </span>
                            <?php if ($row['inn'] !== '' OR $row['ogrn'] !== ''): ?>
                                <span class="font-mono text-xs text-gray-400">
                                    <?php echo HTML::chars($row['inn'] !== '' ? 'ИНН '.$row['inn'] : 'ОГРН '.$row['ogrn']); ?>
                                </span>
                            <?php endif; ?>
                            <span class="ml-auto flex items-center gap-2 text-xs text-gray-400">
                                <?php if ($row['ontology_url'] !== ''): ?><i class="fas fa-sitemap text-blue-500" title="в онтологии предприятий"></i><?php endif; ?>
                                <?php if ((int) $row['org_id'] > 0): ?><i class="fas fa-building-columns text-emerald-500" title="в справочнике организаций"></i><?php endif; ?>
                                <span>встреч: <?php echo (int) $row['hits']; ?></span>
                            </span>
                        </summary>

                        <div class="mt-3 rounded-lg border border-gray-100 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-900/40">

                            <!-- ПРОСМОТР: то, что известно о производителе, и куда он связан. -->
                            <div data-nj646-view class="space-y-3">
                                <dl class="grid gap-x-4 gap-y-2.5 text-sm sm:grid-cols-2">
                                    <?php
                                    echo $cell('Роль на этикетке', Service_Nj646_About::roles()[(string) $row['role']] ?? (string) $row['role']);
                                    echo $cell('Страна', (string) $row['country']);
                                    echo $cell('ИНН', (string) $row['inn']);
                                    echo $cell('ОГРН', (string) $row['ogrn']);
                                    echo $cell('Телефон', (string) $row['phone']);
                                    echo $cell('Сайт', (string) $row['site']);
                                    ?>
                                    <div class="sm:col-span-2">
                                        <dt class="<?php echo $cap_class; ?>">Адрес</dt>
                                        <dd class="mt-0.5 break-words text-sm text-gray-800 dark:text-gray-100">
                                            <?php echo $row['address'] !== '' ? HTML::chars((string) $row['address']) : '—'; ?>
                                        </dd>
                                    </div>
                                </dl>

                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <a href="/label/producer/<?php echo (int) $row['id']; ?>"
                                       class="n2j-btn-secondary n2j-btn-sm">
                                        <i class="fas fa-up-right-from-square mr-1.5 text-[11px]"></i>карточка производителя
                                    </a>
                                    <?php if (trim((string) $row['ontology_url']) !== ''): ?>
                                        <a href="<?php echo HTML::chars((string) $row['ontology_url']); ?>" target="_blank" rel="noopener"
                                           class="n2j-btn-secondary n2j-btn-sm">
                                            <i class="fas fa-sitemap mr-1.5 text-[11px]"></i>в онтологии проекта
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <div data-nj646-actions class="flex flex-wrap items-center gap-2 border-t border-gray-200 pt-3 dark:border-gray-700">
                                    <button type="button" data-nj646-edit-open class="<?php echo $btn_edit; ?>" title="Править реквизиты">
                                        <i class="fas fa-pencil mr-1"></i>править
                                    </button>

                                    <form method="post" action="/nj646/admin/goods_link">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                        <button type="submit" class="<?php echo $btn_edit; ?>">
                                            <i class="fas fa-diagram-project mr-1.5 text-[11px]"></i>связать с проектом
                                        </button>
                                    </form>

                                    <form method="post" action="/nj646/admin/goods_delete" class="ml-auto">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <input type="hidden" name="kind" value="producer">
                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                        <button data-n2j-arm="Точно удалить?" type="submit"
                                                class="n2j-btn-danger-soft n2j-btn-sm">
                                            <i class="fas fa-trash-can mr-1.5 text-[11px]"></i><span data-n2j-arm-label>Удалить карточку</span>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- ПРАВКА: та же форма, что была, но открывается по кнопке. -->
                            <form method="post" action="/nj646/admin/goods_save" data-nj646-edit class="hidden space-y-3">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="kind" value="producer">
                                <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="<?php echo $cap_class; ?>">Название</span>
                                        <input type="text" name="name" value="<?php echo HTML::chars($row['name']); ?>" class="<?php echo $input_class; ?>">
                                    </label>
                                    <label class="block">
                                        <span class="<?php echo $cap_class; ?>">Роль на этикетке</span>
                                        <select name="role" class="n2j-input n2j-select mt-1 w-full">
                                            <?php foreach (Service_Nj646_About::roles() as $role => $label): ?>
                                                <option value="<?php echo HTML::chars($role); ?>" <?php echo $row['role'] === $role ? 'selected' : ''; ?>>
                                                    <?php echo HTML::chars($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="<?php echo $cap_class; ?>">Адрес</span>
                                        <input type="text" name="address" value="<?php echo HTML::chars($row['address']); ?>" class="<?php echo $input_class; ?>">
                                    </label>
                                    <label class="block">
                                        <span class="<?php echo $cap_class; ?>">ИНН</span>
                                        <input type="text" name="inn" value="<?php echo HTML::chars($row['inn']); ?>" class="<?php echo $input_class; ?> font-mono">
                                    </label>
                                    <label class="block">
                                        <span class="<?php echo $cap_class; ?>">ОГРН</span>
                                        <input type="text" name="ogrn" value="<?php echo HTML::chars($row['ogrn']); ?>" class="<?php echo $input_class; ?> font-mono">
                                    </label>
                                    <label class="block">
                                        <span class="<?php echo $cap_class; ?>">Телефон</span>
                                        <input type="text" name="phone" value="<?php echo HTML::chars($row['phone']); ?>" class="<?php echo $input_class; ?>">
                                    </label>
                                    <label class="block">
                                        <span class="<?php echo $cap_class; ?>">Сайт</span>
                                        <input type="text" name="site" value="<?php echo HTML::chars($row['site']); ?>" class="<?php echo $input_class; ?>">
                                    </label>
                                    <label class="block">
                                        <span class="<?php echo $cap_class; ?>">Страна</span>
                                        <input type="text" name="country" value="<?php echo HTML::chars($row['country']); ?>" class="<?php echo $input_class; ?>">
                                    </label>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="submit" class="n2j-btn-cta">
                                        сохранить
                                    </button>
                                    <button type="button" data-nj646-edit-cancel
                                            class="n2j-btn-secondary">
                                        отмена
                                    </button>
                                </div>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ------------------------------- товары ------------------------------- -->
        <section class="n2j-card p-0">
            <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                <h2 class="n2j-h3">Товары</h2>
                <span class="n2j-badge n2j-badge-wait">
                    <?php echo count($products); ?>
                </span>
            </div>

            <?php if ( ! $products): ?>
                <p class="px-4 py-5 text-sm text-gray-500 sm:px-5 dark:text-gray-400">
                    Пока пусто: товар появится здесь, когда в разборе найдут наименование, бренд или штрихкод.
                </p>
            <?php endif; ?>

            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($products as $row): ?>
                    <?php
                    $auto      = Service_Nj646_Store::flag($row['name_auto'] ?? FALSE);
                    $kind      = Service_Nj646_Label::product_kind_info((string) ($row['product_kind'] ?? ''));
                    $count     = (int) ($row['analyses_count'] ?? $row['hits']);
                    $last_id   = (int) ($row['last_analysis_id'] ?? 0);
                    $score     = (int) ($row['last_score'] ?? 0);
                    $verdict   = (string) ($row['last_verdict'] ?? '');
                    // Пищевая ценность приезжает из колонки jsonb строкой —
                    // раскодирует и проверяет Store (одно место на все вьюхи).
                    $nutrition       = Service_Nj646_Store::nutrition_of($row);
                    $nutrition_flags = Service_Nj646_About::nutrition_flags($nutrition);
                    $nutrition_basis = Service_Nj646_About::nutrition_bases()[$nutrition['basis'] ?? '100g'] ?? 'на 100 г';
                    $nutrition_manual = (string) ($row['nutrition_source'] ?? '') === 'manual';
                    ?>
                    <details class="group px-4 py-3 sm:px-5" data-nj646-card>
                        <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 text-sm marker:content-none">
                            <span class="shrink-0 text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars($kind['title']); ?>">
                                <i class="<?php echo HTML::chars($kind['icon']); ?>"></i>
                            </span>
                            <span class="font-semibold text-gray-900 dark:text-white">
                                <?php echo HTML::chars($row['name'] !== '' ? $row['name'] : 'Без названия'); ?>
                                <?php if ($auto): ?>
                                    <span class="rounded-full border border-dashed border-gray-300 px-1.5 py-0.5 text-xs font-normal text-gray-500 dark:border-gray-600 dark:text-gray-400">имя рабочее</span>
                                <?php endif; ?>
                            </span>
                            <span class="n2j-badge n2j-badge-wait">
                                <?php echo HTML::chars($kind['title']); ?>
                            </span>
                            <?php if ($row['brand'] !== ''): ?>
                                <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($row['brand']); ?></span>
                            <?php endif; ?>
                            <span class="ml-auto flex items-center gap-2 text-xs text-gray-400">
                                <?php if ($verdict !== '' AND $score > 0): ?>
                                    <span class="rounded-md bg-gray-100 px-1.5 py-0.5 font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        <?php echo HTML::chars(Service_Nj646_Label::verdict_label($verdict)); ?> <?php echo $score; ?>
                                    </span>
                                <?php endif; ?>
                                <span>разборов: <?php echo $count; ?></span>
                                <?php // Ссылка на разбор прямо из строки: раскрывать карточку ради неё не нужно.
                                      // data-nj646-stop не даёт клику раскрыть <details>. ?>
                                <?php if ($last_id > 0): ?>
                                    <a href="/label/analysis/<?php echo $last_id; ?>" data-nj646-stop
                                       class="font-semibold text-blue-600 hover:underline dark:text-blue-400">
                                        разбор <?php echo $last_id; ?> →
                                    </a>
                                <?php endif; ?>
                            </span>
                        </summary>

                        <div class="mt-3 rounded-lg border border-gray-100 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-900/40">

                            <!-- ПРОСМОТР: что известно о товаре + ссылки на разборы. -->
                            <div data-nj646-view class="space-y-3">
                                <dl class="grid gap-x-4 gap-y-2.5 text-sm sm:grid-cols-2">
                                    <?php
                                    echo $cell('Тип продукта', '<i class="'.HTML::chars($kind['icon']).' mr-1 text-gray-400"></i>'.HTML::chars($kind['title']), TRUE);
                                    echo $cell('Бренд', (string) $row['brand']);
                                    echo $cell('Штрихкод', (string) $row['barcode']);
                                    echo $cell('Масса / объём', (string) $row['weight']);
                                    echo $cell('ГОСТ / ТУ', (string) $row['gost']);
                                    echo $cell('Срок годности', (string) $row['shelf_life']);
                                    echo $cell('Условия хранения', (string) $row['storage']);
                                    echo $cell('Страна', (string) $row['country']);
                                    ?>
                                    <div>
                                        <dt class="<?php echo $cap_class; ?>">Производитель</dt>
                                        <dd class="mt-0.5 text-sm text-gray-800 dark:text-gray-100">
                                            <?php if ((int) $row['producer_id'] > 0 AND $row['producer_name'] !== NULL): ?>
                                                <a href="/label/producer/<?php echo (int) $row['producer_id']; ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">
                                                    <?php echo HTML::chars((string) $row['producer_name']); ?>
                                                </a>
                                            <?php else: ?>
                                                не определён
                                            <?php endif; ?>
                                        </dd>
                                    </div>
                                    <?php if ($auto): ?>
                                        <div>
                                            <dt class="<?php echo $cap_class; ?>">Название</dt>
                                            <dd class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">
                                                имени на этикетке не нашлось — модуль дал рабочее по типу продукта;
                                                настоящее подставится, когда этикетка или модель его дадут
                                            </dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>

                                <?php // ПИЩЕВАЯ ЦЕННОСТЬ: таблица с этикетки (или правка руками).
                                      // Показываем только заполненные поля — пустые строки «— г»
                                      // в карточке читались бы как «ноль», а это неправда. ?>
                                <?php if ($nutrition): ?>
                                    <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="<?php echo $cap_class; ?>">Пищевая ценность</span>
                                            <span class="n2j-badge n2j-badge-wait">
                                                <?php echo HTML::chars($nutrition_basis); ?>
                                            </span>
                                            <span class="text-xs text-gray-400">
                                                <?php echo $nutrition_manual ? 'заполнено вручную' : 'разобрано с этикетки'; ?>
                                            </span>
                                        </div>
                                        <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
                                            <?php foreach (Service_Nj646_About::nutrition_fields() as $nf => $nspec): ?>
                                                <?php if ( ! isset($nutrition['values'][$nf])) { continue; } ?>
                                                <div class="flex items-baseline justify-between gap-2">
                                                    <dt class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($nspec['title']); ?></dt>
                                                    <dd class="font-semibold text-gray-800 dark:text-gray-100">
                                                        <?php echo HTML::chars(Service_Nj646_About::nutrition_number($nutrition['values'][$nf])); ?>
                                                        <span class="text-xs font-normal text-gray-400"><?php echo HTML::chars($nspec['unit']); ?></span>
                                                    </dd>
                                                </div>
                                            <?php endforeach; ?>
                                        </dl>
                                        <?php if ($nutrition_flags): ?>
                                            <ul class="mt-2 flex flex-wrap gap-1.5">
                                                <?php foreach ($nutrition_flags as $flag): ?>
                                                    <li class="n2j-badge <?php echo $flag['kind'] === 'warn' ? 'n2j-badge-run' : 'n2j-badge-ok'; ?>">
                                                        <?php echo HTML::chars($flag['text']); ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="n2j-hint">
                                        Пищевой ценности на этикетке не нашлось — её можно вписать руками по карандашу.
                                    </p>
                                <?php endif; ?>

                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <?php if ($last_id > 0): ?>
                                        <a href="/label/analysis/<?php echo $last_id; ?>"
                                           class="n2j-btn-secondary n2j-btn-sm">
                                            <i class="fas fa-clipboard-check mr-1.5 text-[11px]"></i>последний разбор
                                        </a>
                                    <?php endif; ?>
                                    <a href="/label/product/<?php echo (int) $row['id']; ?>"
                                       class="n2j-btn-secondary n2j-btn-sm">
                                        <i class="fas fa-up-right-from-square mr-1.5 text-[11px]"></i>карточка товара: все разборы
                                    </a>
                                </div>

                                <div data-nj646-actions class="flex flex-wrap items-center gap-2 border-t border-gray-200 pt-3 dark:border-gray-700">
                                    <button type="button" data-nj646-edit-open class="<?php echo $btn_edit; ?>" title="Править карточку товара">
                                        <i class="fas fa-pencil mr-1"></i>править
                                    </button>

                                    <form method="post" action="/nj646/admin/goods_delete" class="ml-auto">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <input type="hidden" name="kind" value="product">
                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                        <button data-n2j-arm="Точно удалить?" type="submit"
                                                class="n2j-btn-danger-soft n2j-btn-sm">
                                            <i class="fas fa-trash-can mr-1.5 text-[11px]"></i><span data-n2j-arm-label>Удалить карточку</span>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- ПРАВКА -->
                            <form method="post" action="/nj646/admin/goods_save" data-nj646-edit class="hidden space-y-3">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="kind" value="product">
                                <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <?php
                                    $fields = [
                                        ['name', 'Название'], ['brand', 'Бренд'], ['barcode', 'Штрихкод'],
                                        ['weight', 'Масса / объём'], ['gost', 'ГОСТ / ТУ'],
                                        ['shelf_life', 'Срок годности'], ['storage', 'Условия хранения'],
                                        ['country', 'Страна'],
                                    ];
                                    ?>

                                    <?php foreach ($fields as $field): ?>
                                        <label class="block <?php echo in_array($field[0], ['storage', 'name'], TRUE) ? 'sm:col-span-2' : ''; ?>">
                                            <span class="<?php echo $cap_class; ?>"><?php echo HTML::chars($field[1]); ?></span>
                                            <input type="text" name="<?php echo HTML::chars($field[0]); ?>"
                                                   value="<?php echo HTML::chars((string) $row[$field[0]]); ?>"
                                                   class="<?php echo $input_class; ?> <?php echo $field[0] === 'barcode' ? 'font-mono' : ''; ?>">
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <?php // ПИЩЕВАЯ ЦЕННОСТЬ: те же поля, что разбирает этикетка.
                                      // Пустое поле — это «нет данных», а не ноль: оно просто не
                                      // сохраняется (Service_Nj646_About::nutrition_sanitize).
                                      // Сохранение руками помечает таблицу как `manual`, и
                                      // следующая этикетка её не перетрёт. ?>
                                <fieldset class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                    <legend class="<?php echo $cap_class; ?> px-1">Пищевая ценность</legend>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="block">
                                            <span class="<?php echo $cap_class; ?>">Считается</span>
                                            <select name="nutrition_basis" class="n2j-input n2j-select mt-1 w-full">
                                                <?php foreach (Service_Nj646_About::nutrition_bases() as $bkey => $btitle): ?>
                                                    <option value="<?php echo HTML::chars($bkey); ?>" <?php echo ($nutrition['basis'] ?? '100g') === $bkey ? 'selected' : ''; ?>>
                                                        <?php echo HTML::chars($btitle); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="<?php echo $cap_class; ?>">Размер порции</span>
                                            <input type="text" name="nutrition_portion" value="<?php echo HTML::chars((string) ($nutrition['portion'] ?? '')); ?>"
                                                   placeholder="например: 30 г" class="<?php echo $input_class; ?>">
                                        </label>
                                    </div>

                                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                        <?php foreach (Service_Nj646_About::nutrition_fields() as $nf => $nspec): ?>
                                            <label class="block">
                                                <span class="<?php echo $cap_class; ?>">
                                                    <?php echo HTML::chars($nspec['title']); ?>, <?php echo HTML::chars($nspec['unit']); ?>
                                                </span>
                                                <input type="text" inputmode="decimal"
                                                       name="nutrition[<?php echo HTML::chars($nf); ?>]"
                                                       value="<?php echo isset($nutrition['values'][$nf])
                                                           ? HTML::chars(Service_Nj646_About::nutrition_number($nutrition['values'][$nf])) : ''; ?>"
                                                       class="<?php echo $input_class; ?>">
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                    <p class="n2j-hint mt-2">
                                        Пустое поле — «нет данных» (ноль ставить не нужно). Сохранённое руками
                                        этикетка больше не перепишет: очистите все поля, чтобы вернуть автоматический разбор.
                                    </p>
                                </fieldset>

                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="submit" class="n2j-btn-cta">
                                        сохранить
                                    </button>
                                    <button type="button" data-nj646-edit-cancel
                                            class="n2j-btn-secondary">
                                        отмена
                                    </button>
                                </div>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <div class="n2j-card mt-6 flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
        <span>Выгрузка базы веществ (CSV, «;»): для правок в таблице и переезда. Обратный ход — импорт: тот же формат, сначала план, потом запись.</span>
        <form method="post" action="/nj646/admin/export">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <button type="submit" class="n2j-btn-secondary n2j-btn-sm">
                <i class="fas fa-file-csv mr-1.5 text-[11px]"></i>Скачать CSV
            </button>
        </form>
        <a href="/nj646/admin/import"
           class="n2j-btn-secondary n2j-btn-sm">
            <i class="fas fa-file-import mr-1.5 text-[11px]"></i>Импорт словаря
        </a>
        <a href="/nj646/admin/merge"
           class="n2j-btn-secondary n2j-btn-sm">
            <i class="fas fa-object-group mr-1.5 text-[11px]"></i>Слияние дублей
        </a>
    </div>
</div>

<script>
/* Карточки товаров и производителей: просмотр — по умолчанию, правка — по карандашу. */
(function () {
    'use strict';

    // Правка: просмотр прячем, форму показываем. Карточка — общий контейнер
    // [data-nj646-card] (у каждой строки списка свой).
    document.querySelectorAll('[data-nj646-edit-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var card = btn.closest('[data-nj646-card]');

            if (!card) { return; }

            card.querySelector('[data-nj646-view]').classList.add('hidden');
            card.querySelector('[data-nj646-edit]').classList.remove('hidden');
        });
    });

    document.querySelectorAll('[data-nj646-edit-cancel]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var card = btn.closest('[data-nj646-card]');

            if (!card) { return; }

            card.querySelector('[data-nj646-edit]').classList.add('hidden');
            card.querySelector('[data-nj646-view]').classList.remove('hidden');
        });
    });

    // Ссылка на разбор стоит в строке-заголовке: клик по ней не должен
    // раскрывать карточку (иначе человек уйдёт на разбор через полстраницы).
    document.querySelectorAll('[data-nj646-stop]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    });

})();
</script>
