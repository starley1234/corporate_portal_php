<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Админка: СЛИЯНИЕ ДУБЛЕЙ производителей и товаров (/nj646/admin/merge).
 *
 * Владелец (P2-8): «слияние дублей производителей/товаров в админке: выбрать две
 * карточки → перенести разборы/товары на основную, вторую удалить».
 *
 * Страница нарочно не автоматическая. Один завод на разных этикетках пишется
 * по-разному, и «Ромашка» с «Ромашкой-Трейд» могут оказаться двумя разными
 * юрлицами — поэтому показываем ПАРУ-ПОДОЗРЕНИЕ, причину («ИНН совпадает»,
 * «название — часть второго»), что именно переедет и о чём стоит подумать
 * (разные ИНН, разные штрихкоды). Решение — за админом: две кнопки, которую из
 * карточек сделать главной, объединение в два клика (window.confirm на платформе
 * запрещён — образец удаления в `goods.php`).
 *
 * Полос-метров нет («полосы убрать везде»): числа, бейджи, значки риска.
 *
 * @var array  $groups  пары: ['producers' => [['pair','plan'], …], 'products' => […]]
 * @var array  $counts  всего карточек: producers/products
 * @var string $csrf
 * @var string $flash
 * @var string $flash_kind
 */

$producer_fields = ['inn' => 'ИНН', 'ogrn' => 'ОГРН', 'country' => 'страна', 'address' => 'адрес',
                    'phone' => 'телефон', 'site' => 'сайт'];
$product_fields  = ['brand' => 'бренд', 'barcode' => 'штрихкод', 'weight' => 'масса',
                    'gost' => 'ГОСТ/ТУ', 'shelf_life' => 'срок', 'country' => 'страна'];

/** Строка «подпись: значение» для карточки участника. */
$facts = function (array $row, array $fields, $kind) use ($producer_fields, $product_fields) {
    $map  = $kind === 'product' ? $product_fields : $producer_fields;
    $out  = [];

    foreach ($fields as $field)
    {
        $value = trim((string) ($row[$field] ?? ''));

        if ($value !== '')
        {
            $out[] = ($map[$field] ?? $field).': '.$value;
        }
    }

    if ($kind === 'product' AND trim((string) ($row['producer_name'] ?? '')) !== '')
    {
        $out[] = 'производитель: '.$row['producer_name'];
    }

    return implode(' · ', array_map(function ($v) use ($kind) { return $v; }, $out));
};

$weight_line = function (array $row, $kind) {
    $parts = ['разборов: '.(int) ($row['analyses'] ?? 0)];

    if ($kind === 'producer')
    {
        $parts[] = 'товаров: '.(int) ($row['products'] ?? 0);
    }

    $parts[] = 'встреч: '.(int) ($row['hits'] ?? 0);

    return implode(' · ', $parts);
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <p class="n2j-hint">
        <a href="/nj646/admin" class="hover:underline">Админка модуля</a> ·
        <a href="/nj646/admin/goods" class="hover:underline">Товары и производители</a> ·
        <a href="/nj646/admin/catalog" class="hover:underline">Справочник веществ</a>
    </p>

    <div class="mt-1 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="n2j-h1">Слияние дублей</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Один завод на разных этикетках пишется по-разному, и карточки расходятся: разборы лежат
                по двум страницам, счётчики разъехались. Здесь — пары, похожие на одну запись, с причиной
                и планом переноса. <span class="font-semibold">Решение за вами:</span> слияние не
                автоматическое (у похожих названий бывают разные ИНН), а вторую карточку после
                объединения уже не вернуть.
            </p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            <span class="rounded-lg bg-gray-100 px-2.5 py-1 font-bold text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                производителей: <?php echo (int) $counts['producers']; ?>
            </span>
            <span class="rounded-lg bg-gray-100 px-2.5 py-1 font-bold text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                товаров: <?php echo (int) $counts['products']; ?>
            </span>
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

    <?php if (empty($groups['producers']) AND empty($groups['products'])): ?>
        <div class="n2j-card mt-5 text-sm text-gray-600 dark:text-gray-300">
            <i class="fas fa-circle-check mr-1 text-emerald-600"></i>
            <span class="font-semibold text-gray-900 dark:text-white">Дублей не видно.</span>
            Пары ищутся по ИНН/ОГРН, штрихкоду и «ядру» названия (без форм юрлиц, веса и упаковки).
            <span class="mt-2 block text-xs text-gray-500 dark:text-gray-400">
                Карточки появляются из разборов этикеток — как только наберётся новая пара, она покажется здесь.
                Список всех карточек — <a href="/nj646/admin/goods" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">на странице товаров</a>.
            </span>
        </div>
    <?php endif; ?>

    <?php foreach (['producers' => 'Производители', 'products' => 'Товары'] as $bucket => $title): ?>
        <?php if (empty($groups[$bucket])) continue; ?>
        <?php $kind = $bucket === 'products' ? 'product' : 'producer'; ?>

        <section class="mt-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="n2j-h3"><?php echo $title; ?></h2>
                <span class="text-xs text-gray-500 dark:text-gray-400">пар-подозрений: <?php echo count($groups[$bucket]); ?></span>
            </div>

            <div class="mt-3 space-y-4">
                <?php foreach ($groups[$bucket] as $one): ?>
                    <?php
                    $pair = $one['pair'];
                    $plan = $one['plan'];
                    $a    = $pair['keep'];
                    $b    = $pair['drop'];
                    $a_id = (int) $a['id'];
                    $b_id = (int) $b['id'];
                    $name_of = function ($row) use ($kind) {
                        return trim((string) ($row['name'] ?? '')) !== ''
                            ? (string) $row['name']
                            : ($kind === 'product' ? 'товар #'.(int) $row['id'] : 'производитель #'.(int) $row['id']);
                    };
                    ?>

                    <div class="n2j-card p-0 overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                            <span class="n2j-badge n2j-badge-info">
                                <i class="fas fa-magnifying-glass mr-1.5 text-[10px]"></i><?php echo HTML::chars((string) $pair['why']); ?>
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($weight_line($a, $kind).' + '.$weight_line($b, $kind)); ?></span>
                        </div>

                        <div class="grid gap-3 p-4 md:grid-cols-2">
                            <?php foreach ([['row' => $a, 'label' => 'A'], ['row' => $b, 'label' => 'B']] as $side): ?>
                                <?php $row = $side['row']; $is_main = (int) $row['id'] === (int) $plan['keep']['id']; ?>
                                <div class="rounded-lg border <?php echo $is_main ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/60 dark:bg-emerald-950/20' : 'border-gray-200 dark:border-gray-700'; ?> p-3">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <p class="font-semibold text-gray-900 dark:text-white">
                                            <?php echo HTML::chars($name_of($row)); ?>
                                            <span class="ml-1 text-xs font-normal text-gray-400">#<?php echo (int) $row['id']; ?></span>
                                        </p>
                                        <?php if ($is_main): ?>
                                            <span class="n2j-badge n2j-badge-ok">предлагается главной</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php $line = $facts($row, array_keys($kind === 'product' ? $product_fields : $producer_fields), $kind); ?>
                                    <p class="n2j-hint break-words"><?php echo $line !== '' ? HTML::chars($line) : 'дополнительных сведений нет'; ?></p>
                                    <p class="n2j-hint"><?php echo HTML::chars($weight_line($row, $kind)); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="border-t border-gray-100 px-4 py-3 dark:border-gray-700">
                            <ul class="space-y-1 text-xs text-gray-600 dark:text-gray-300">
                                <?php foreach ((array) $plan['steps'] as $step): ?>
                                    <li><i class="fas fa-arrow-right mr-1.5 text-[10px] text-gray-400"></i><?php echo HTML::chars($step); ?></li>
                                <?php endforeach; ?>
                                <?php if ( ! empty($plan['fields'])): ?>
                                    <li>
                                        <i class="fas fa-fill-drip mr-1.5 text-[10px] text-gray-400"></i>Заполнится у главной:
                                        <?php
                                        $names = [];

                                        foreach ($plan['fields'] as $field)
                                        {
                                            $names[] = $field['label'].' → '.$field['after'];
                                        }

                                        echo HTML::chars(implode('; ', $names));
                                        ?>
                                    </li>
                                <?php endif; ?>
                            </ul>

                            <?php foreach ((array) $plan['warnings'] as $warning): ?>
                                <p class="mt-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                                    <i class="fas fa-triangle-exclamation mr-1"></i><?php echo HTML::chars($warning); ?>
                                </p>
                            <?php endforeach; ?>

                            <form method="post" action="/nj646/admin/goods_merge" class="mt-3 flex flex-wrap items-center gap-2">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="kind" value="<?php echo $kind === 'product' ? 'product' : 'producer'; ?>">
                                <input type="hidden" name="id_a" value="<?php echo $a_id; ?>">
                                <input type="hidden" name="id_b" value="<?php echo $b_id; ?>">

                                <button data-n2j-arm="Точно объединить?" type="submit" name="keep" value="<?php echo $a_id; ?>"
                                        class="n2j-btn-process n2j-btn-sm">
                                    <i class="fas fa-object-group mr-1.5 text-[11px]"></i><span data-n2j-arm-label>оставить «<?php echo HTML::chars($name_of($a)); ?>»</span>
                                </button>
                                <span class="text-xs text-gray-400">или</span>
                                <button data-n2j-arm="Точно объединить?" type="submit" name="keep" value="<?php echo $b_id; ?>"
                                        class="n2j-btn-secondary n2j-btn-sm">
                                    <i class="fas fa-object-group mr-1.5 text-[11px]"></i><span data-n2j-arm-label>оставить «<?php echo HTML::chars($name_of($b)); ?>»</span>
                                </button>
                                <span class="ml-auto text-xs text-gray-500 dark:text-gray-400">
                                    вторая карточка исчезнет, разборы останутся у главной
                                </span>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

