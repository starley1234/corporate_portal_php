<?php defined('SYSPATH') or die('No direct script access.');

$selected = $catalog[$category];
$faq = [
    ['Как перевести одну единицу в другую?', 'Выберите категорию, единицу, из которой переводите, и единицу результата. Калькулятор сразу покажет число и подставит его в формулу.'],
    ['Сотка — это сколько квадратных метров?', 'Одна сотка — ровно 100 м². Один гектар равен 100 соткам, то есть 10 000 м².'],
    ['Что такое пуд и верста?', 'Это исторические русские меры: пуд — примерно 16,38 кг, верста — 1 066,8 м. В разных местах и эпохах бытовали варианты, поэтому справочные исторические значения помечены отдельно.'],
    ['Сколько литров в ведре?', 'У бытового ведра нет универсального объёма. Для удобства можно считать условные 10 л; отдельно показана историческая мера около 12,3 л.'],
    ['Сохраняются ли введённые значения?', 'Нет. Расчёт выполняется в браузере; параметры ссылки нужны только для того, чтобы поделиться примером.'],
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<main class="min-w-0 flex-1">
    <header class="mb-5">
        <p class="text-xs font-bold uppercase tracking-widest text-blue-600 dark:text-blue-400">Бесплатно · без регистрации</p>
        <h1 class="n2j-h1 mt-1">Конвертер единиц и русских мер</h1>
        <p class="mt-2 max-w-3xl text-base text-gray-600 dark:text-gray-300">Метры, килограммы, литры, температура и давление — плюс сотки, пуды, сажени и вёрсты. Выберите величину и получите точный пересчёт с формулой.</p>
    </header>

    <section class="n2j-card" aria-labelledby="un-title">
        <h2 id="un-title" class="n2j-h3">Что переводим?</h2>
        <div id="un-categories" role="tablist" aria-label="Категории величин" class="mt-3 flex flex-wrap gap-2">
            <?php foreach ($catalog as $code => $item): $on = $code === $category; ?>
            <button type="button" role="tab" data-category="<?php echo HTML::chars($code); ?>" aria-selected="<?php echo $on ? 'true' : 'false'; ?>"
                    class="n2j-chip un-category<?php echo $on ? ' is-on' : ''; ?>">
                <?php echo HTML::chars($item['title']); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <form id="un-form" method="get" action="/units" class="mt-5">
            <input id="un-category" type="hidden" name="c" value="<?php echo HTML::chars($category); ?>">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-end">
                <div>
                    <label for="un-value" class="n2j-label">Значение</label>
                    <input id="un-value" name="v" type="text" inputmode="decimal" autocomplete="off" value="<?php echo HTML::chars($value); ?>" placeholder="Например, 1"
                           class="n2j-input mt-1.5 w-full text-2xl font-bold">
                </div>
                <button id="un-swap" type="button" aria-label="Поменять единицы местами" title="Поменять единицы местами"
                        class="n2j-btn-icon mx-auto mb-1">
                    <i class="fas fa-right-left"></i>
                </button>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="un-from" class="n2j-label">Из</label>
                        <select id="un-from" name="from" class="n2j-input n2j-select mt-1.5 w-full font-semibold">
                            <?php foreach ($selected['units'] as $code => $unit): ?>
                                <option value="<?php echo HTML::chars($code); ?>" <?php echo $from === $code ? 'selected' : ''; ?>><?php echo HTML::chars($unit['title'].' ('.$unit['symbol'].')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="un-to" class="n2j-label">В</label>
                        <select id="un-to" name="to" class="n2j-input n2j-select mt-1.5 w-full font-semibold">
                            <?php foreach ($selected['units'] as $code => $unit): ?>
                                <option value="<?php echo HTML::chars($code); ?>" <?php echo $to === $code ? 'selected' : ''; ?>><?php echo HTML::chars($unit['title'].' ('.$unit['symbol'].')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div id="un-examples" class="mt-4 flex flex-wrap gap-2" aria-label="Популярные примеры">
                <?php foreach ($selected['examples'] as $example): ?>
                <button type="button" class="un-example n2j-chip"
                        data-value="<?php echo HTML::chars((string) $example['value']); ?>" data-from="<?php echo HTML::chars($example['from']); ?>" data-to="<?php echo HTML::chars($example['to']); ?>">
                    <?php echo HTML::chars($example['label']); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="submit" class="n2j-btn-process">
                    <i class="fas fa-calculator"></i> Перевести
                </button>
                <button id="un-copy" type="button" class="n2j-btn-secondary" disabled>
                    <i class="fas fa-copy"></i> Копировать
                </button>
                <button id="un-share" type="button" class="n2j-btn-secondary">
                    <i class="fas fa-link"></i> Скопировать ссылку
                </button>
            </div>
        </form>
    </section>

    <section id="un-result" class="mt-4 rounded-xl border-2 border-blue-500 bg-blue-50/70 p-4 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20 sm:p-6" aria-live="polite">
        <p class="text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">Результат</p>
        <p id="un-main" class="mt-1 break-words text-3xl font-extrabold text-blue-800 dark:text-blue-200 sm:text-4xl"><?php echo $result !== NULL ? HTML::chars($result['formatted'].' '.$catalog[$category]['units'][$to]['symbol']) : '—'; ?></p>
        <p id="un-formula" class="mt-2 text-sm text-gray-700 dark:text-gray-200"><?php echo $result !== NULL ? HTML::chars($result['formula']) : 'Введите число — пересчёт появится сразу'; ?></p>
        <p id="un-error" class="mt-2 hidden text-sm font-medium text-rose-700 dark:text-rose-300"></p>
    </section>

    <section class="n2j-card mt-4">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="n2j-h3">Сколько будет в других единицах?</h2>
                <p class="n2j-hint">Текущая величина пересчитывается во все меры выбранной категории.</p>
            </div>
            <span id="un-table-caption" class="text-xs font-semibold text-blue-700 dark:text-blue-300"></span>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="n2j-table min-w-[360px]">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400"><tr><th class="py-2 pr-3">Единица</th><th class="py-2 text-right">Значение</th></tr></thead>
                <tbody id="un-table" class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($selected['units'] as $code => $unit): ?>
                    <tr><th class="py-2 pr-3 font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($unit['title'].' ('.$unit['symbol'].')'); ?></th><td class="py-2 text-right font-mono font-semibold text-gray-900 dark:text-white">—</td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-xs dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-gray-200">
        <h2 class="text-sm font-bold text-amber-900 dark:text-amber-200"><i class="fas fa-landmark mr-1.5"></i>Русские меры — с оговорками</h2>
        <p class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80">Пуд — примерно 16,38 кг, верста — 1 066,8 м, сажень — 2,1336 м. Сотка — ровно 100 м². Для ведра показаны два значения: условные 10 л и исторические 12,3 л.</p>
        <p class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80"><?php echo HTML::chars($data_note); ?></p>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Ещё полезные конвертеры</h2>
        <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
            <a href="/cooking" class="n2j-chip">Граммы в стаканах и ложках</a>
            <a href="/size" class="n2j-chip">Подбор размера по меркам</a>
            <a href="/calendar" class="n2j-chip">Календарь и даты</a>
        </div>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <?php foreach ($faq as $i => $item): ?>
            <details class="group py-3" <?php echo $i === 0 ? 'open' : ''; ?>>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200"><i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i><?php echo HTML::chars($item[0]); ?></summary>
                <p class="mt-2 pl-6 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($item[1]); ?></p>
            </details>
            <?php endforeach; ?>
        </div>
    </section>
</aside>
</div>
</div>

<script type="application/ld+json"><?php echo json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($item) { return ['@type' => 'Question', 'name' => $item[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]]]; }, $faq)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script>
<?php echo View::factory('506/script', ['catalog' => $catalog, 'category' => $category, 'from' => $from, 'to' => $to]); ?>
</script>
