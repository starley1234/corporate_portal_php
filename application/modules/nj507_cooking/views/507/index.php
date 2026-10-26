<?php defined('SYSPATH') or die('No direct script access.');

$faq = [
    ['Сколько грамм в стакане муки?', 'Зависит от размера стакана и того, как насыпали муку. Для бытовых таблиц стакан 200 мл часто даёт около 100–130 г, 250 мл — около 125–160 г. Здесь показан диапазон, а не псевдоточное значение.'],
    ['Почему одинаковый стакан даёт разный вес?', 'Стакан измеряет объём, а граммы — массу. Мука может быть рыхлой или утрамбованной, сахар и соль плотнее, а мёд тяжелее воды. Поэтому выбирайте продукт, а результат рассматривайте как ориентир.'],
    ['Какой объём у стакана?', 'Удобные бытовые ориентиры — 200 мл для стакана до ободка и 250 мл для полного стакана. Для иностранного рецепта проверьте, что автор понимает под cup: объём может отличаться.'],
    ['Как пересчитать рецепт на другое число порций?', 'Укажите, на сколько порций рассчитано исходное количество, и сколько нужно приготовить. Калькулятор умножит ингредиенты на отношение нового числа порций к исходному.'],
    ['Можно ли точно заменить форму для выпечки?', 'Коэффициент рассчитан по площади дна. Высота формы, толщина слоя, время выпечки и теплопередача могут отличаться — проверяйте готовность и не считайте время выпечки неизменным.'],
];
$groups = [];
foreach ($products as $slug => $item) $groups[$item['group']][$slug] = $item;
$initial = Service_Nj507_Cooking::calculate($product, $measure, $amount, $direction, $from_servings, $to_servings);
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<main class="min-w-0 flex-1">
    <header class="mb-5">
        <p class="text-xs font-bold uppercase tracking-widest text-rose-600 dark:text-rose-400">Кулинарные меры · 35 продуктов</p>
        <h1 class="n2j-h1 mt-1"><?php echo $product_page ? 'Сколько грамм '.$product_item['title'].' в стакане и ложке' : 'Сколько грамм в стакане?'; ?></h1>
        <p class="mt-2 max-w-3xl text-base text-gray-600 dark:text-gray-300">Переводите стаканы и ложки в граммы и обратно, меняйте число порций, пересчитывайте форму для выпечки. Для каждого продукта — честный диапазон: способ набора влияет на вес.</p>
    </header>

    <section class="n2j-card" aria-labelledby="cook-calc-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="cook-calc-title" class="n2j-h3">Перевести меру в граммы</h2>
                <p class="n2j-hint">Выберите продукт и меру; можно также перевести граммы обратно.</p>
            </div>
            <span class="n2j-badge n2j-badge-err">Расчёт в браузере</span>
        </div>
        <div id="cook-directions" class="mt-4 flex flex-wrap gap-2" role="radiogroup" aria-label="Направление перевода">
            <button type="button" class="n2j-chip cook-direction<?php echo $direction === 'to_mass' ? ' is-on' : ''; ?>" data-direction="to_mass" aria-checked="<?php echo $direction === 'to_mass' ? 'true' : 'false'; ?>">Мера → граммы</button>
            <button type="button" class="n2j-chip cook-direction<?php echo $direction === 'to_measure' ? ' is-on' : ''; ?>" data-direction="to_measure" aria-checked="<?php echo $direction === 'to_measure' ? 'true' : 'false'; ?>">Граммы → мера</button>
        </div>
        <form id="cook-form" method="get" action="<?php echo $product_page ? '/cooking/'.rawurlencode($product) : '/cooking'; ?>" class="mt-4">
            <input type="hidden" name="d" id="cook-direction" value="<?php echo HTML::chars($direction); ?>">
            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <label for="cook-product" class="n2j-label">Продукт</label>
                    <select id="cook-product" name="p" class="n2j-input n2j-select mt-1.5 w-full font-semibold" <?php echo $product_page ? 'disabled aria-disabled="true"' : ''; ?>>
                        <?php foreach ($groups as $group_name => $items): ?>
                        <optgroup label="<?php echo HTML::chars($group_name); ?>">
                            <?php foreach ($items as $slug => $item): ?>
                                <option value="<?php echo HTML::chars($slug); ?>" <?php echo $product === $slug ? 'selected' : ''; ?>><?php echo HTML::chars($item['title']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($product_page): ?><p class="n2j-hint mt-1.5">В этой карточке выбран фиксированный продукт; другие ингредиенты — в списке справа.</p><?php endif; ?>
                </div>
                <div>
                    <label id="cook-amount-label" for="cook-amount" class="n2j-label"><?php echo $direction === 'to_mass' ? 'Количество меры' : 'Масса, граммы'; ?></label>
                    <div class="mt-1.5 flex gap-2">
                        <input id="cook-amount" name="n" type="text" inputmode="decimal" autocomplete="off" value="<?php echo HTML::chars($amount); ?>" placeholder="1" class="n2j-input min-w-0 flex-1 text-xl font-bold">
                        <select id="cook-measure" name="u" class="n2j-input n2j-select max-w-[58%] font-semibold" aria-label="Мера для перевода">
                            <?php foreach ($measures as $code => $unit): ?><option value="<?php echo HTML::chars($code); ?>" <?php echo $measure === $code ? 'selected' : ''; ?>><?php echo HTML::chars($unit['title']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="mt-4 rounded-lg bg-gray-50 p-3 dark:bg-gray-900/60">
                <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">Пересчитать порции</p>
                <div class="mt-2 grid grid-cols-2 gap-3">
                    <label class="text-xs text-gray-500 dark:text-gray-400">Было порций
                        <input id="cook-from-servings" name="from" type="number" min="1" max="1000" step="1" value="<?php echo HTML::chars($from_servings); ?>" class="n2j-input mt-1 w-full font-semibold">
                    </label>
                    <label class="text-xs text-gray-500 dark:text-gray-400">Нужно порций
                        <input id="cook-to-servings" name="to" type="number" min="1" max="1000" step="1" value="<?php echo HTML::chars($to_servings); ?>" class="n2j-input mt-1 w-full font-semibold">
                    </label>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="submit" class="n2j-btn-process"><i class="fas fa-calculator"></i> Посчитать</button>
                <button id="cook-copy" type="button" disabled class="n2j-btn-secondary"><i class="fas fa-copy"></i> Копировать результат</button>
                <button id="cook-share" type="button" class="n2j-btn-secondary"><i class="fas fa-link"></i> Скопировать ссылку</button>
            </div>
        </form>
    </section>

    <section class="mt-4 rounded-xl border-2 border-rose-500 bg-rose-50/60 p-4 shadow-xs dark:border-rose-500/60 dark:bg-rose-900/20 sm:p-6" aria-live="polite">
        <p class="text-xs font-bold uppercase tracking-wide text-rose-700 dark:text-rose-300">Ориентир</p>
        <p id="cook-main" class="mt-1 break-words text-3xl font-extrabold text-rose-800 dark:text-rose-200 sm:text-4xl"><?php echo $initial !== NULL ? HTML::chars($initial['formatted']) : '—'; ?></p>
        <p id="cook-result-caption" class="mt-1 text-sm font-semibold text-gray-700 dark:text-gray-200"><?php echo $initial !== NULL ? HTML::chars($product_item['title'].' · '.$measures[$measure]['title']) : 'Введите количество, чтобы увидеть результат'; ?></p>
        <p id="cook-formula" class="mt-3 text-xs text-gray-600 dark:text-gray-300"><?php echo $initial !== NULL ? HTML::chars($initial['formula']) : ''; ?></p>
        <p id="cook-note" class="mt-3 rounded-lg bg-white/70 p-3 text-xs leading-relaxed text-gray-600 dark:bg-gray-900/50 dark:text-gray-300"><?php echo HTML::chars($product_item['note']); ?></p>
        <p id="cook-error" class="mt-2 hidden text-sm font-semibold text-rose-700 dark:text-rose-300"></p>
    </section>

    <section class="n2j-card mt-4">
        <h2 class="n2j-h3">Этим же продуктом в других мерах</h2>
        <p class="n2j-hint">Таблица пересчитывается по выбранному продукту.</p>
        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3" id="cook-measure-grid">
            <?php foreach ($measures as $code => $unit): $one = Service_Nj507_Cooking::calculate($product, $code, 1); ?>
            <button type="button" data-measure="<?php echo HTML::chars($code); ?>" class="cook-measure-card rounded-lg border border-gray-200 p-3 text-left transition hover:border-rose-400 hover:bg-rose-50/60 dark:border-gray-700 dark:hover:border-rose-500 dark:hover:bg-rose-900/20">
                <span class="block text-xs font-semibold text-gray-500 dark:text-gray-400"><?php echo HTML::chars($unit['title']); ?></span>
                <span class="mt-1 block text-sm font-bold text-gray-900 dark:text-white" data-output><?php echo $one !== NULL ? HTML::chars($one['formatted']) : '—'; ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="n2j-card mt-4" aria-labelledby="cook-form-title">
        <div class="flex items-start gap-3">
            <span class="rounded-xl bg-blue-50 p-2 text-blue-600 dark:bg-blue-950 dark:text-blue-300"><i class="fas fa-cookie-bite"></i></span>
            <div><h2 id="cook-form-title" class="n2j-h3">Пересчёт формы для выпечки</h2><p class="n2j-hint">Сравните площадь дна — получите коэффициент, на который изменить ингредиенты.</p></div>
        </div>
        <form id="cook-form-convert" class="mt-4 grid gap-3 md:grid-cols-2">
            <fieldset class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <legend class="px-1 text-xs font-bold text-gray-700 dark:text-gray-200">Форма в рецепте</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label class="text-xs text-gray-500 dark:text-gray-400">Тип
                        <select id="form-from-shape" class="n2j-input n2j-select mt-1 w-full"><option value="round">Круглая</option><option value="square">Квадратная</option><option value="rectangle">Прямоугольная</option></select>
                    </label>
                    <label class="text-xs text-gray-500 dark:text-gray-400"><span id="form-from-a-label">Диаметр, см</span>
                        <input id="form-from-a" type="number" min="1" max="200" step="0.1" value="24" class="n2j-input mt-1 w-full font-semibold">
                    </label>
                    <label id="form-from-b-wrap" class="hidden text-xs text-gray-500 dark:text-gray-400">Ширина, см
                        <input id="form-from-b" type="number" min="1" max="200" step="0.1" value="20" class="n2j-input mt-1 w-full font-semibold">
                    </label>
                </div>
            </fieldset>
            <fieldset class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <legend class="px-1 text-xs font-bold text-gray-700 dark:text-gray-200">Ваша форма</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label class="text-xs text-gray-500 dark:text-gray-400">Тип
                        <select id="form-to-shape" class="n2j-input n2j-select mt-1 w-full"><option value="round">Круглая</option><option value="square" selected>Квадратная</option><option value="rectangle">Прямоугольная</option></select>
                    </label>
                    <label class="text-xs text-gray-500 dark:text-gray-400"><span id="form-to-a-label">Сторона, см</span>
                        <input id="form-to-a" type="number" min="1" max="200" step="0.1" value="20" class="n2j-input mt-1 w-full font-semibold">
                    </label>
                    <label id="form-to-b-wrap" class="hidden text-xs text-gray-500 dark:text-gray-400">Ширина, см
                        <input id="form-to-b" type="number" min="1" max="200" step="0.1" value="20" class="n2j-input mt-1 w-full font-semibold">
                    </label>
                </div>
            </fieldset>
            <div class="md:col-span-2 rounded-lg bg-blue-50 p-4 dark:bg-blue-900/20" aria-live="polite">
                <p id="form-main" class="text-lg font-extrabold text-blue-800 dark:text-blue-200">Коэффициент 0,8842 · уменьшить ингредиенты примерно на 11,6 %</p>
                <p id="form-detail" class="mt-1 text-xs text-gray-600 dark:text-gray-300">Круглая Ø24 см → квадратная 20 × 20 см. Сравнивается площадь дна.</p>
                <p id="form-error" class="mt-2 hidden text-xs font-semibold text-rose-700 dark:text-rose-300"></p>
            </div>
        </form>
    </section>
</main>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-xs dark:border-amber-900/60 dark:bg-amber-950/30">
        <h2 class="text-sm font-bold text-amber-900 dark:text-amber-200"><i class="fas fa-scale-balanced mr-1.5"></i>Почему диапазон?</h2>
        <p class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80"><?php echo HTML::chars($data_note); ?></p>
        <p class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80">Для выпечки точнее всего кухонные весы: объёмный способ удобен, но не заменяет взвешивание.</p>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Справочники и источники</h2>
        <ul class="mt-2 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <?php foreach ($sources as $source): ?>
            <li><a href="<?php echo HTML::chars($source['url']); ?>" target="_blank" rel="noopener noreferrer" class="font-semibold text-rose-700 underline decoration-rose-300 underline-offset-2 hover:text-rose-900 dark:text-rose-300 dark:hover:text-rose-200"><?php echo HTML::chars($source['title']); ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Другие полезные инструменты</h2>
        <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
            <a href="/units?c=volume" class="n2j-chip">Конвертер единиц</a>
            <a href="/size" class="n2j-chip">Размер по меркам</a>
            <a href="/calendar" class="n2j-chip">Календарь</a>
        </div>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Ингредиенты</h2>
        <div class="mt-2 max-h-[28rem] space-y-3 overflow-y-auto pr-1">
            <?php foreach ($groups as $group_name => $items): ?>
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?php echo HTML::chars($group_name); ?></h3>
                <ul class="mt-1 space-y-1">
                    <?php foreach ($items as $slug => $item): ?>
                    <li><a href="/cooking/<?php echo rawurlencode($slug); ?>" class="text-xs text-gray-700 hover:text-rose-700 dark:text-gray-300 dark:hover:text-rose-300"><?php echo HTML::chars($item['title']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
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
<?php echo View::factory('507/script', [
    'products' => $products, 'measures' => $measures, 'product' => $product,
    'measure' => $measure, 'direction' => $direction,
    'from_servings' => $from_servings, 'to_servings' => $to_servings,
    'product_page' => $product_page,
]); ?>
</script>
