<?php defined('SYSPATH') or die('No direct script access.');

$faq = [
    ['Как узнать российский размер одежды по обхвату груди?', 'Измерьте грудь горизонтально и найдите ближайшее значение в таблице. Для типовой женской маркировки RU 46 соответствует обхвату груди около 92 см; это ориентир, а не гарантия посадки конкретной вещи.'],
    ['Как измерить стопу для выбора обуви?', 'Встаньте на лист, отметьте край пятки и самый длинный палец. Измерьте обе стопы и возьмите большую. Затем сравните длину с таблицей производителя: у разных марок одна и та же EU-цифра может соответствовать разной колодке.'],
    ['Буквенный размер M — одинаковый у всех брендов?', 'Нет. Буквы S/M/L особенно сильно различаются между производителями, странами и моделями. Сверяйте сантиметровые мерки с размерной сеткой конкретного магазина.'],
    ['Как подобрать размер кольца?', 'Измерьте внутренний диаметр кольца, которое уже хорошо сидит. Табличная окружность считается как диаметр × π; ширина кольца, температура и время суток также влияют на посадку.'],
    ['Сохраняются ли мои мерки?', 'Нет. Введённая величина нужна только для расчёта в браузере и параметров ссылки; сервис не создаёт профиль и не сохраняет измерения.'],
];
$active = $categories[$category];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<main class="min-w-0 flex-1">
    <header class="mb-5">
        <p class="text-xs font-bold uppercase tracking-widest text-blue-600 dark:text-blue-400">Одежда · обувь · аксессуары</p>
        <h1 class="n2j-h1 mt-1">Подбор размера по меркам</h1>
        <p class="mt-2 max-w-3xl text-base text-gray-600 dark:text-gray-300">Введите сантиметры или миллиметры — получите ближайший размер RU, EU, US и дополнительные ориентиры. Сначала измеряем, потом сверяемся с таблицей продавца.</p>
    </header>

    <section class="n2j-card" aria-labelledby="size-category-title">
        <h2 id="size-category-title" class="n2j-h3">Что подбираем?</h2>
        <div id="size-categories" role="tablist" aria-label="Категории размеров" class="mt-3 flex flex-wrap gap-2">
            <?php foreach ($categories as $code => $item): $on = $category === $code; ?>
            <button type="button" role="tab" data-category="<?php echo HTML::chars($code); ?>" aria-selected="<?php echo $on ? 'true' : 'false'; ?>"
                class="n2j-chip size-category<?php echo $on ? ' is-on' : ''; ?>">
                <?php echo HTML::chars($item['title']); ?>
            </button>
            <?php endforeach; ?>
        </div>
        <form id="size-form" method="get" action="/size" class="mt-5">
            <input type="hidden" id="size-type" name="type" value="<?php echo HTML::chars($category); ?>">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <div>
                    <label id="size-measure-label" for="size-measure" class="n2j-label"><?php echo HTML::chars($active['field_label'].' ('.$active['unit'].')'); ?></label>
                    <div class="relative mt-1.5">
                        <input id="size-measure" name="v" type="text" inputmode="decimal" autocomplete="off" value="<?php echo HTML::chars($measure); ?>" placeholder="Например, 92" class="n2j-input w-full text-2xl font-bold pr-16">
                        <span id="size-unit" class="absolute right-4 top-1/2 -translate-y-1/2 text-sm font-semibold text-gray-500 dark:text-gray-400"><?php echo HTML::chars($active['unit']); ?></span>
                    </div>
                </div>
                <button type="submit" class="n2j-btn-process"><i class="fas fa-ruler-combined"></i> Подобрать размер</button>
            </div>
            <p id="size-guide" class="mt-3 rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-600 dark:bg-gray-900/60 dark:text-gray-300"><?php echo HTML::chars($active['guide']); ?></p>
        </form>
    </section>

    <section class="mt-4 rounded-xl border-2 border-blue-500 bg-blue-50/60 p-4 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20 sm:p-6" aria-live="polite">
        <p class="text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">Ближайший ориентир</p>
        <p id="size-main" class="mt-1 text-4xl font-extrabold text-blue-800 dark:text-blue-200"><?php echo $result !== NULL ? HTML::chars(reset($result['sizes'])) : '—'; ?></p>
        <p id="size-main-label" class="mt-1 text-sm font-semibold text-gray-700 dark:text-gray-200"><?php echo $result !== NULL ? HTML::chars(key($result['sizes'])) : 'Введите мерку — таблица подберёт ближайшее значение'; ?></p>
        <dl id="size-results" class="mt-4 grid gap-2 sm:grid-cols-2">
            <?php if ($result !== NULL): foreach ($result['sizes'] as $label => $value): ?>
            <div class="flex items-center justify-between gap-2 rounded-lg bg-white/80 px-3 py-2 dark:bg-gray-900/50"><dt class="text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($label); ?></dt><dd class="text-sm font-extrabold text-gray-900 dark:text-white"><?php echo HTML::chars($value); ?></dd></div>
            <?php endforeach; endif; ?>
        </dl>
        <dl id="size-details" class="mt-2 grid gap-2 sm:grid-cols-2">
            <?php if ($result !== NULL): foreach ($result['details'] as $label => $value): ?>
            <div class="flex items-center justify-between gap-2 rounded-lg border border-blue-100 px-3 py-2 dark:border-blue-900/40"><dt class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($label); ?></dt><dd class="text-xs font-bold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($value); ?></dd></div>
            <?php endforeach; endif; ?>
        </dl>
        <p id="size-difference" class="mt-3 text-xs text-gray-600 dark:text-gray-300"><?php echo $result !== NULL ? 'Ближайшая строка таблицы: '.HTML::chars($result['display'].' '.$result['unit']) : ''; ?></p>
        <p id="size-error" class="mt-2 hidden text-sm font-semibold text-rose-700 dark:text-rose-300"></p>
        <div class="mt-4 flex flex-wrap gap-2">
            <button id="size-copy" type="button" class="n2j-btn-process" <?php echo $result === NULL ? 'disabled' : ''; ?>><i class="fas fa-copy"></i> Копировать результат</button>
            <button id="size-share" type="button" class="n2j-btn-secondary"><i class="fas fa-link"></i> Скопировать ссылку</button>
        </div>
    </section>

    <section class="n2j-card mt-4">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div><h2 class="n2j-h3">Справочная таблица</h2><p class="n2j-hint">Нажмите строку, чтобы подставить мерку в калькулятор.</p></div>
            <span id="size-table-label" class="text-xs font-semibold text-blue-700 dark:text-blue-300"><?php echo HTML::chars($active['title']); ?></span>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="n2j-table min-w-[540px]">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400"><tr><th id="size-table-measure" class="py-2 pr-3"><?php echo HTML::chars($active['field_label'].' · '.$active['unit']); ?></th><th class="py-2 pr-3">Размеры</th><th class="py-2">Дополнительные мерки</th></tr></thead>
                <tbody id="size-table" class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($active['rows'] as $row): ?>
                    <tr data-measure="<?php echo HTML::chars((string) $row['measure']); ?>" class="size-row cursor-pointer transition hover:bg-blue-50/70 dark:hover:bg-blue-900/20">
                        <th class="py-2 pr-3 font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($row['display'].' '.$active['unit']); ?></th>
                        <td class="py-2 pr-3 text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars(implode(' · ', array_map(function ($k, $v) { return $k.' '.$v; }, array_keys($row['sizes']), $row['sizes']))); ?></td>
                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(implode(' · ', array_map(function ($k, $v) { return $k.' '.$v; }, array_keys($row['details']), $row['details']))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-xs dark:border-amber-900/60 dark:bg-amber-950/30">
        <h2 class="text-sm font-bold text-amber-900 dark:text-amber-200"><i class="fas fa-circle-info mr-1.5"></i>Это ориентир, не гарантия</h2>
        <p id="size-notice" class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80"><?php echo HTML::chars($notice); ?></p>
        <p class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80">Удобнее всего измерять мягкой лентой, не затягивая её; обувь мерить стоя и по большей стопе.</p>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Источники и справочники</h2>
        <ul class="mt-2 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <?php foreach ($sources as $source): ?><li><a href="<?php echo HTML::chars($source['url']); ?>" target="_blank" rel="noopener noreferrer" class="font-semibold text-blue-700 underline decoration-blue-300 underline-offset-2 hover:text-blue-900 dark:text-blue-300"><?php echo HTML::chars($source['title']); ?></a></li><?php endforeach; ?>
        </ul>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Другие калькуляторы</h2>
        <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
            <a href="/units" class="n2j-chip">Конвертер единиц</a>
            <a href="/cooking" class="n2j-chip">Кулинарные меры</a>
            <a href="/calendar" class="n2j-chip">Календарь</a>
        </div>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <?php foreach ($faq as $i => $item): ?>
            <details class="group py-3" <?php echo $i === 0 ? 'open' : ''; ?>><summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200"><i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i><?php echo HTML::chars($item[0]); ?></summary><p class="mt-2 pl-6 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($item[1]); ?></p></details>
            <?php endforeach; ?>
        </div>
    </section>
</aside>
</div>
</div>
<script type="application/ld+json"><?php echo json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($item) { return ['@type' => 'Question', 'name' => $item[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]]]; }, $faq)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script>
<?php echo View::factory('511/script', ['categories' => $categories, 'category' => $category, 'measure' => $measure, 'result' => $result]); ?>
</script>
