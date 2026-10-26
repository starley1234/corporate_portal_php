<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Админка «Разбора состава» — ИМПОРТ СЛОВАРЯ (/nj646/admin/import).
 *
 * Владелец (03.10.2026): «импорт словаря CSV — долг». Экран из двух частей:
 * сверху — как загрузить (файл или вставленный текст, формат с шапкой и
 * образцом), ниже — ПЛАН: что добавится, что обновится (с «было → станет»),
 * что без изменений и что не прошло проверку.
 *
 * Главное правило страницы: пока не нажато «Применить», в базу не уходит
 * ничего. Поэтому «применить» — отдельная форма, а рядом «отменить».
 * Строки с ошибкой не применяются вовсе: половина файла — худший исход.
 *
 * Полос-метров здесь нет (владелец: «полосы убрать везде»): числа, бейджи и
 * подписи. Формы — POST + CSRF, GET-параметров у админки нет.
 *
 * @var array  $plan     план из сессии (пусто — его ещё не строили)
 * @var string $csv      прежний вставленный текст (если проверяли текстом)
 * @var string $template CSV-образец с шапкой
 * @var int    $total    сколько карточек в базе сейчас
 * @var string $csrf     токен форм
 * @var string $flash    сообщение действия
 */

$danger = 'border-rose-300/80 bg-rose-50/90 text-rose-900 dark:border-rose-800/80 dark:bg-rose-950/40 dark:text-rose-200';
$info   = 'border-blue-300/80 bg-blue-50/90 text-blue-900 dark:border-blue-800/80 dark:bg-blue-950/40 dark:text-blue-200';

$flash_class = ($flash_kind ?? '') === 'warn'
    ? 'border-amber-300/80 bg-amber-50/90 text-amber-900 dark:border-amber-800/80 dark:bg-amber-950/40 dark:text-amber-200'
    : $info;

$risk_dot = ['bg-emerald-500', 'bg-gray-400', 'bg-amber-500', 'bg-rose-400', 'bg-rose-600'];
$kind_word = ['food' => 'еда', 'cosmetic' => 'косметика', 'both' => 'еда и косметика'];

$counts = (array) ($plan['counts'] ?? []);
$csv    = (string) ($csv ?? '');
$has_plan = ! empty($plan);

// В предпросмотре показываем по 50 строк на корзину: дальше это уже не «прочитать
// глазами», а листать — для этого есть справочник и модерация.
$shown = 50;

$fmt_rows = function (array $list, callable $line) use ($shown) {
    foreach (array_slice($list, 0, $shown) as $i => $row): ?>
        <tr class="border-t border-gray-100 text-sm dark:border-gray-700"><?php $line($row); ?></tr>
    <?php endforeach;

    if (count($list) > $shown): ?>
        <tr class="border-t border-gray-100 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500">
            <td colspan="4" class="px-2 py-2">…и ещё <?php echo count($list) - $shown; ?> — они тоже попадут в импорт.</td>
        </tr>
    <?php endif;
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
        <div class="mb-5 rounded-xl border px-4 py-3 text-sm <?php echo $flash_class; ?>">
            <i class="fas <?php echo ($flash_kind ?? '') === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-info'; ?> mr-1"></i><?php echo HTML::chars($flash); ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="n2j-h1">Импорт словаря веществ</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Свой список веществ (CSV) — в базу. Сначала покажем план: что добавится, что обновится,
                что не прошло проверку. <span class="font-semibold">Пока не нажато «Применить», в базу не записывается ничего.</span>
                Сейчас в базе <?php echo number_format((int) $total, 0, ',', ' '); ?> карточек.
            </p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            <a href="/nj646/admin/moderation"
               class="n2j-btn-secondary">
                <i class="fas fa-file-circle-check mr-1"></i>модерация
            </a>
            <a href="/nj646/admin/catalog"
               class="n2j-btn-secondary">
                <i class="fas fa-sitemap mr-1"></i>справочник
            </a>
        </div>
    </div>

    <?php if ($has_plan): ?>
        <!-- ===================== ПЛАН ИМПОРТА ===================== -->
        <div class="n2j-card mt-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="n2j-h3">План импорта</h2>
                <p class="n2j-hint">
                    <?php if ((string) ($plan['file'] ?? '') !== ''): ?>файл «<?php echo HTML::chars((string) $plan['file']); ?>» · <?php endif; ?>
                    новых карточек: <?php echo (string) (($plan['status'] ?? 'published') === 'draft' ? 'черновиком' : 'сразу опубликованы'); ?>
                </p>
            </div>

            <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                <span class="rounded-md bg-emerald-50 px-2 py-1 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                    добавится: <?php echo (int) ($counts['create'] ?? 0); ?>
                </span>
                <span class="rounded-md bg-blue-50 px-2 py-1 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                    обновится: <?php echo (int) ($counts['update'] ?? 0); ?>
                </span>
                <span class="rounded-md bg-gray-100 px-2 py-1 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                    без изменений: <?php echo (int) ($counts['same'] ?? 0); ?>
                </span>
                <span class="rounded-md <?php echo (int) ($counts['errors'] ?? 0) > 0 ? 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'; ?> px-2 py-1">
                    с ошибками: <?php echo (int) ($counts['errors'] ?? 0); ?>
                </span>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <form method="post" action="/nj646/admin/import_apply">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button type="submit"
                            class="n2j-btn-process">
                        <i class="fas fa-file-import mr-1.5"></i>применить план
                    </button>
                </form>
                <form method="post" action="/nj646/admin/import_cancel">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button type="submit"
                            class="n2j-btn-secondary">
                        отменить
                    </button>
                </form>
            </div>
        </div>

        <?php if ( ! empty($plan['errors'])): ?>
            <div class="mt-5 overflow-hidden rounded-xl border <?php echo $danger; ?>">
                <div class="px-4 py-3 text-sm font-semibold">
                    <i class="fas fa-circle-exclamation mr-1"></i>Не пройдут проверку: <?php echo count($plan['errors']); ?>
                    <span class="ml-1 font-normal">— эти строки в базу не пойдут, поправьте файл и проверьте снова.</span>
                </div>
                <div class="max-h-72 overflow-y-auto bg-white dark:bg-gray-800">
                    <table class="n2j-table">
                        <tbody>
                            <?php foreach (array_slice($plan['errors'], 0, 100) as $row): ?>
                                <tr class="border-t border-gray-100 text-sm dark:border-gray-700">
                                    <td class="w-16 px-4 py-2 font-mono text-xs text-gray-400"><?php echo (int) ($row['line'] ?? 0) ?: '—'; ?></td>
                                    <td class="px-2 py-2 text-gray-800 dark:text-gray-100"><?php echo HTML::chars((string) ($row['message'] ?? '')); ?></td>
                                    <td class="px-4 py-2 text-right text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) ($row['name'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (count($plan['errors']) > 100): ?>
                        <p class="n2j-hint px-4 py-2">…и ещё <?php echo count($plan['errors']) - 100; ?> ошибок.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ( ! empty($plan['create'])): ?>
            <div class="n2j-card p-0 mt-5 overflow-hidden">
                <div class="flex items-baseline justify-between gap-2 px-4 py-3">
                    <h2 class="n2j-h3">Добавится</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo count($plan['create']); ?></span>
                </div>
                <div class="max-h-96 overflow-y-auto">
                    <table class="n2j-table">
                        <thead class="sticky top-0 bg-gray-50 text-xs uppercase tracking-wide text-gray-400 dark:bg-gray-900">
                            <tr>
                                <th class="px-4 py-2">Название</th>
                                <th class="px-2 py-2">slug</th>
                                <th class="px-2 py-2">Вид</th>
                                <th class="px-4 py-2">Риск</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $fmt_rows($plan['create'], function ($row) use ($risk_dot, $kind_word) { ?>
                                <td class="px-4 py-2 text-gray-900 dark:text-white">
                                    <?php echo HTML::chars((string) ($row['name_ru'] !== '' ? $row['name_ru'] : $row['name_int'])); ?>
                                    <?php if ((string) $row['summary'] !== ''): ?>
                                        <span class="mt-0.5 block max-w-xl truncate text-xs text-gray-400"><?php echo HTML::chars((string) $row['summary']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-2 py-2 font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $row['slug']); ?></td>
                                <td class="px-2 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kind_word[(string) ($row['kind'] ?? 'both')] ?? '—'); ?></td>
                                <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300">
                                    <span class="mr-1.5 inline-block h-2.5 w-2.5 rounded-full align-middle <?php echo $risk_dot[max(0, min(4, (int) ($row['risk'] ?? 1)))]; ?>"></span><?php echo (int) ($row['risk'] ?? 1); ?>
                                </td>
                            <?php }); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ( ! empty($plan['update'])): ?>
            <div class="n2j-card p-0 mt-5 overflow-hidden">
                <div class="flex items-baseline justify-between gap-2 px-4 py-3">
                    <h2 class="n2j-h3">Обновится</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo count($plan['update']); ?></span>
                </div>
                <div class="max-h-96 overflow-y-auto">
                    <table class="n2j-table">
                        <thead class="sticky top-0 bg-gray-50 text-xs uppercase tracking-wide text-gray-400 dark:bg-gray-900">
                            <tr>
                                <th class="px-4 py-2">Карточка</th>
                                <th class="px-2 py-2">Что меняется</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $fmt_rows($plan['update'], function ($row) { ?>
                                <td class="px-4 py-2 align-top text-gray-900 dark:text-white">
                                    <?php echo HTML::chars((string) ($row['name'] ?? '')); ?>
                                    <span class="mt-0.5 block font-mono text-xs text-gray-400"><?php echo HTML::chars((string) ($row['slug'] ?? '')); ?></span>
                                </td>
                                <td class="px-2 py-2">
                                    <ul class="space-y-0.5 text-xs">
                                        <?php foreach ((array) ($row['changed'] ?? []) as $field => $pair): ?>
                                            <li>
                                                <span class="text-gray-400"><?php echo HTML::chars(Service_Nj646_Import::LABELS[$field] ?? $field); ?>:</span>
                                                <span class="text-gray-500 line-through dark:text-gray-400"><?php echo HTML::chars(Service_Nj646_Import::human($pair[0])); ?></span>
                                                <i class="fas fa-arrow-right mx-1 text-[10px] text-gray-300"></i>
                                                <span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars(Service_Nj646_Import::human($pair[1])); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                            <?php }); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ( ! empty($plan['same'])): ?>
            <details class="n2j-card p-0 mt-5 px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-200">
                    Без изменений: <?php echo count($plan['same']); ?> — файл совпал с базой
                </summary>
                <p class="n2j-hint mt-2">
                    <?php
                    $names = [];

                    foreach (array_slice($plan['same'], 0, 60) as $row)
                    {
                        $names[] = (string) $row['name'];
                    }

                    echo HTML::chars(implode(' · ', $names));
                    echo count($plan['same']) > 60 ? ' · …' : '';
                    ?>
                </p>
            </details>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ===================== ЗАГРУЗКА ===================== -->
    <form method="post" action="/nj646/admin/import_check" enctype="multipart/form-data"
          class="n2j-card mt-5">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

        <h2 class="n2j-h3">
            <?php echo $has_plan ? 'Проверить другой файл' : 'Загрузить словарь'; ?>
        </h2>
        <p class="n2j-hint">
            Формат — тот же, что у выгрузки: разделитель «;», первой строкой шапка, строки с «#» игнорируются.
            Пустая ячейка значит «здесь ничего не меняем», а непустая — заменяет поле целиком (списки тоже),
            поэтому файлом можно и дополнять карточки, не переписывая их.
        </p>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div>
                <label for="nj646-import-file" class="n2j-label">
                    Файл CSV
                </label>
                <input id="nj646-import-file" type="file" name="csv" accept=".csv,text/csv"
                       class="mt-2 block w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-gray-700 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:file:bg-gray-700 dark:file:text-gray-100">
                <label for="nj646-import-text" class="n2j-label mt-4">
                    …или вставить строками
                </label>
                <textarea id="nj646-import-text" name="csv_text" rows="8" spellcheck="false"
                          class="n2j-textarea mt-2 w-full font-mono"
                          placeholder="e211;Бензоат натрия;Sodium benzoate;both;3;risky;консерванты;E211;draft;Синтетический консервант"><?php echo HTML::chars($csv); ?></textarea>
            </div>

            <div>
                <label for="nj646-import-status" class="n2j-label">
                    Новые карточки
                </label>
                <select id="nj646-import-status" name="status"
                        class="n2j-input n2j-select mt-2 w-full">
                    <option value="published">публиковать сразу</option>
                    <option value="draft">добавлять черновиками</option>
                </select>
                <p class="n2j-hint mt-2">
                    Статус из файла (колонка «status») важнее этого выбора — он про строки, где статус не указан.
                </p>

                <details class="mt-4 rounded-lg border border-gray-200/80 bg-gray-50/70 px-3 py-2 dark:border-gray-700/60 dark:bg-gray-800/40">
                    <summary class="cursor-pointer text-xs font-semibold text-gray-600 dark:text-gray-300">
                        Шаблон и колонки
                    </summary>
                    <p class="n2j-hint mt-2">
                        Обязательно — название (русское или латиница). Остальное по желанию: slug выведется сам
                        (E-код, латиница или название латиницей), вид состава — «еда/косметика/оба»,
                        списки (синонимы, ограничения, источники) — через «|» или запятую.
                    </p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-white p-2 text-xs leading-relaxed text-gray-600 dark:bg-gray-900 dark:text-gray-300"><?php echo HTML::chars($template); ?></pre>
                </details>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="submit"
                    class="n2j-btn-process">
                <i class="fas fa-magnifying-glass-chart mr-1.5"></i>проверить и показать план
            </button>
            <p class="n2j-hint">Ничего не запишется: сначала план.</p>
        </div>
    </form>
</div>
