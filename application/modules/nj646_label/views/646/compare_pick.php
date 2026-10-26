<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * «С кем сравнить» — /label/compare и /label/compare/<a>.
 *
 * Пара товаров выбирается ссылками, а не формой: адрес пары
 * (/label/compare/<a>/<b>) можно сохранить и послать, а страница работает без
 * JavaScript — как и весь модуль. Сверху — товары того же типа продукта: печенье
 * логичнее сравнивать с печеньем, и такие товары уже разобраны.
 *
 * @var array|NULL $product товар, для которого ищем пару (NULL — пара с нуля)
 * @var array      $pool    разобранные товары: те же по типу, иначе — свежие
 * @var string     $note    объяснение, почему сравнение не открылось
 */

$verdict_class = [
    'useful'  => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    'neutral' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    'caution' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    'risky'   => 'bg-rose-50 text-rose-600 dark:bg-rose-900/20 dark:text-rose-300',
    'avoid'   => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-200',
];

$name = function (array $row) {
    return (string) $row['name'] !== '' ? (string) $row['name'] : 'Товар без названия';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-3xl">

        <p class="n2j-hint">
            <a href="/label" class="hover:underline">Состав</a> ·
            <span>сравнение</span>
        </p>

        <h1 class="n2j-h1 mt-1">
            <?php if ($product): ?>
                С кем сравнить «<?php echo HTML::chars($name($product)); ?>»
            <?php else: ?>
                Сравнить два состава
            <?php endif; ?>
        </h1>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            <?php if ($product): ?>
                Выберите второй товар — откроется сравнение: общие вещества, различия, средний риск
                и число опасных позиций у каждого.
            <?php else: ?>
                Сначала выберите первый товар, затем второй — сравнение откроется по своему адресу.
                Сравнивать можно любые разобранные товары, но осмысленнее — одного типа.
            <?php endif; ?>
        </p>

        <?php if ($note !== ''): ?>
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                <?php echo HTML::chars($note); ?>
            </p>
        <?php endif; ?>

        <?php if ( ! $pool): ?>
            <div class="mt-5 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-6 text-center dark:border-gray-600 dark:bg-gray-800">
                <i class="fas fa-scale-balanced text-2xl text-gray-300"></i>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    Разобранных составов пока нет — сравнивать нечего.
                    <a href="/label" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Разберите первый состав</a>,
                    и товар появится здесь.
                </p>
            </div>
        <?php else: ?>
            <div class="mt-5 flex items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-400">
                    <?php echo $product ? 'Того же типа' : 'Разобранные товары'; ?>
                </h2>
                <span class="text-xs text-gray-400 dark:text-gray-500">разобрано: <?php echo count($pool); ?></span>
            </div>

            <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($pool as $row): ?>
                    <?php
                    $kind = Service_Nj646_Label::product_kind_info((string) ($row['product_kind'] ?? ''));
                    $href = $product
                        ? '/label/compare/'.(int) $product['id'].'/'.(int) $row['id']
                        : '/label/compare/'.(int) $row['id'];
                    ?>
                    <li>
                        <a href="<?php echo HTML::chars($href); ?>"
                           class="flex min-h-[56px] items-center gap-3 rounded-lg px-2 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-800">
                            <i class="<?php echo HTML::chars($kind['icon']); ?> w-5 shrink-0 text-center text-gray-400"></i>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">
                                    <?php echo HTML::chars($name($row)); ?>
                                    <?php if (Service_Nj646_Store::flag($row['name_auto'] ?? FALSE)): ?>
                                        <span class="ml-1 text-xs font-normal text-gray-400">имя рабочее</span>
                                    <?php endif; ?>
                                </span>
                                <span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">
                                    <?php echo HTML::chars($kind['title']); ?>
                                    <?php if ((string) ($row['producer_name'] ?? '') !== ''): ?> · <?php echo HTML::chars((string) $row['producer_name']); ?><?php endif; ?>
                                    · разборов: <?php echo (int) ($row['analyses_count'] ?? 0); ?>
                                </span>
                            </span>
                            <?php if ((int) ($row['last_score'] ?? 0) > 0): ?>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold <?php echo $verdict_class[(string) ($row['last_verdict'] ?? '')] ?? 'bg-gray-100 text-gray-600'; ?>">
                                    <?php echo (int) $row['last_score']; ?>
                                </span>
                            <?php endif; ?>
                            <i class="fas fa-chevron-right shrink-0 text-xs text-gray-300 dark:text-gray-600"></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="n2j-hint mt-4">
                В списке только товары с разобранным составом: сравнение — это два состава, а не две карточки.
            </p>
        <?php endif; ?>
    </div>
</div>
