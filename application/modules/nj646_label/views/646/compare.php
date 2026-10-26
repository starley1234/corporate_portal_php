<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Сравнение двух товаров — /label/compare/<a>/<b>.
 *
 * Зачем. Владелец: «товар — главная сущность», и в базе уже копятся составы
 * товаров с оценками. Следующий естественный вопрос человека в магазине —
 * «а какой из этих двух чище?», и раньше ответить было нечем: разборы жили
 * каждый сам по себе. Здесь два состава меряются одной линейкой на один
 * момент: риски берутся по актуальной базе (вещество могли изучить после
 * разбора), общие позиции отделены от различий, а «чище» — это не вкусовое
 * суждение, а разница оценок и числа высокого риска.
 *
 * Чего страница НЕ делает: не смешивает разные продукты в один балл и не
 * прячет неизвестное. Составы с разной длиной честно сравниваются по среднему
 * риску и числу опасных позиций, а не по количеству строк.
 *
 * Полос-метров здесь нет (владелец: «полосы убрать везде»): числа, точки риска
 * и бейджи вердикта.
 *
 * @var array $a        карточка товара A
 * @var array $b        карточка товара B
 * @var array $compare  результат Service_Nj646_Label::compare()
 * @var array $view     ['a' => goods_view(A), 'b' => goods_view(B)]
 */

$fmt = function ($n) {
    return number_format((float) $n, 0, ',', ' ');
};

$verdict_class = [
    'useful'  => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    'neutral' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    'caution' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    'risky'   => 'bg-rose-50 text-rose-600 dark:bg-rose-900/20 dark:text-rose-300',
    'avoid'   => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-200',
];

$risk_dot  = ['bg-emerald-500', 'bg-gray-400', 'bg-amber-500', 'bg-rose-400', 'bg-rose-600'];

// Пищевая ценность рядом. Сравнивать можно только на ОДНОЙ основе, поэтому
// «на порцию» сначала пересчитывается на 100 г (когда порция названа числом),
// а если основы всё равно разные — блок честно говорит, что сравнения не
// будет, вместо того чтобы ставить рядом «180 ккал» и «480 ккал».
$nut_a = Service_Nj646_About::nutrition_per100(Service_Nj646_Store::nutrition_of($a));
$nut_b = Service_Nj646_About::nutrition_per100(Service_Nj646_Store::nutrition_of($b));
$nut_same_basis = ($nut_a AND $nut_b AND $nut_a['basis'] === $nut_b['basis']);
$risk_word = ['безопасное', 'низкий риск', 'умеренный риск', 'высокий риск', 'опасное'];

$side = function ($product, $kind_slug) {
    $kind = Service_Nj646_Label::product_kind_info((string) $kind_slug);

    return [
        'name'    => (string) $product['name'] !== '' ? (string) $product['name'] : 'Товар без названия',
        'url'     => '/label/product/'.(int) $product['id'],
        'kind'    => $kind,
        'auto'    => Service_Nj646_Store::flag($product['name_auto'] ?? FALSE),
        'producer' => trim((string) ($product['producer_name'] ?? '')),
    ];
};

$a_card = $side($a, $a['product_kind'] ?? '');
$b_card = $side($b, $b['product_kind'] ?? '');

$cmp = $compare;
$winner = $cmp['leader'] === 'a' ? $a_card : ($cmp['leader'] === 'b' ? $b_card : NULL);
$loser  = $cmp['leader'] === 'a' ? $b_card : ($cmp['leader'] === 'b' ? $a_card : NULL);

// Разница «в пунктах» — только по модулю: направление уже сказано словами.
$diff = abs((int) $cmp['diff']);

// Склонение по-русски: 1 пункт, 2 пункта, 19 пунктов, 21 пункт.
//
// Отдельным помощником, а не «на месте»: `$n === 1 ? 'пункт' : ($n >= 2 AND
// $n <= 4 ? ...)` — ловушка PHP, `AND` связывает слабее `?:`, и вместо слова
// печатается «1». Поймано стендом .tools/nj646_compare_stand.php.
$plural = function ($n, $one, $few, $many) {
    $n = abs((int) $n);

    if ($n % 10 === 1 AND $n % 100 !== 11) return $one;
    if ($n % 10 >= 2 AND $n % 10 <= 4 AND ($n % 100 < 10 OR $n % 100 >= 20)) return $few;

    return $many;
};

$rows = function (array $list, $with_other = FALSE) use ($risk_dot, $risk_word, $a_card, $b_card) {
    foreach ($list as $row):
        $risk = max(0, min(4, (int) ($row['risk'] ?? 1)));
        ?>
        <li class="flex items-center gap-2 py-1.5 text-sm">
            <span class="h-2.5 w-2.5 shrink-0 rounded-full <?php echo $risk_dot[$risk]; ?>"
                  title="<?php echo HTML::chars($risk_word[$risk]); ?>"></span>
            <?php if ((string) ($row['slug'] ?? '') !== ''): ?>
                <a href="/label/ingredient/<?php echo HTML::chars((string) $row['slug']); ?>"
                   class="min-w-0 truncate text-gray-800 hover:text-blue-700 hover:underline dark:text-gray-100 dark:hover:text-blue-300"><?php echo HTML::chars((string) ($row['name_ru'] ?? '')); ?></a>
            <?php else: ?>
                <span class="min-w-0 truncate text-gray-800 dark:text-gray-100"><?php echo HTML::chars((string) ($row['name_ru'] ?? '')); ?></span>
            <?php endif; ?>
            <?php if ((string) ($row['category'] ?? '') !== ''): ?>
                <span class="hidden shrink-0 text-xs text-gray-400 sm:inline dark:text-gray-500"><?php echo HTML::chars((string) $row['category']); ?></span>
            <?php endif; ?>
            <?php if ($with_other AND isset($row['other_risk'])): ?>
                <span class="ml-auto shrink-0 text-xs text-gray-400 dark:text-gray-500"
                      title="риск: <?php echo $risk; ?> у <?php echo HTML::chars($a_card['name']); ?>, <?php echo (int) $row['other_risk']; ?> у <?php echo HTML::chars($b_card['name']); ?>">
                    <?php echo $risk; ?> / <?php echo (int) $row['other_risk']; ?>
                </span>
            <?php endif; ?>
        </li>
        <?php
    endforeach;
};

$ingredients_block = function ($title, $note, array $list, $with_other = FALSE) use ($rows) {
    ?>
    <div class="n2j-card">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="n2j-h3"><?php echo HTML::chars($title); ?></h2>
            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400"><?php echo count($list); ?></span>
        </div>
        <?php if ($note !== ''): ?><p class="n2j-hint"><?php echo $note; ?></p><?php endif; ?>
        <?php if ($list): ?>
            <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700"><?php $rows($list, $with_other); ?></ul>
        <?php else: ?>
            <p class="mt-2 text-sm text-gray-400 dark:text-gray-500">Пусто.</p>
        <?php endif; ?>
    </div>
    <?php
};

$product_card = function ($card, $side_data) use ($verdict_class, $risk_word) {
    ?>
    <div class="n2j-card">
        <div class="flex items-start gap-2">
            <i class="<?php echo HTML::chars($card['kind']['icon']); ?> mt-1 text-gray-400"></i>
            <div class="min-w-0">
                <a href="<?php echo HTML::chars($card['url']); ?>" class="break-words font-semibold text-gray-900 hover:text-blue-700 hover:underline dark:text-white dark:hover:text-blue-300">
                    <?php echo HTML::chars($card['name']); ?>
                </a>
                <?php if ($card['auto']): ?>
                    <span class="ml-1 rounded-full border border-dashed border-gray-300 px-1.5 py-0.5 align-middle text-xs text-gray-500 dark:border-gray-600 dark:text-gray-400">имя рабочее</span>
                <?php endif; ?>
                <p class="n2j-hint mt-0.5">
                    <?php echo HTML::chars($card['kind']['title']); ?><?php if ($card['producer'] !== ''): ?> · <?php echo HTML::chars($card['producer']); ?><?php endif; ?>
                </p>
            </div>
            <span class="ml-auto shrink-0 rounded-full px-2 py-0.5 text-xs font-bold <?php echo $verdict_class[(string) $side_data['verdict']] ?? 'bg-gray-100 text-gray-600'; ?>">
                <?php echo (int) $side_data['score']; ?>
            </span>
        </div>
        <dl class="mt-3 grid grid-cols-3 gap-2 border-t border-gray-100 pt-3 text-center dark:border-gray-700">
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-400">средний риск</dt>
                <dd class="font-semibold text-gray-900 dark:text-white"><?php echo number_format((float) $side_data['risk_mean'], 1, ',', ' '); ?></dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-400">высокий риск</dt>
                <dd class="font-semibold <?php echo (int) $side_data['high'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white'; ?>"><?php echo (int) $side_data['high']; ?></dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-400">незнакомых базе</dt>
                <dd class="font-semibold text-gray-900 dark:text-white"><?php echo count($side_data['unknown']); ?></dd>
            </div>
        </dl>
        <p class="n2j-hint mt-2 text-center">
            <?php echo HTML::chars(Service_Nj646_Label::score_label((int) $side_data['score'])); ?>
        </p>
    </div>
    <?php
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <p class="n2j-hint">
        <a href="/label" class="hover:underline">Состав</a> ·
        <a href="<?php echo HTML::chars($a_card['url']); ?>" class="hover:underline"><?php echo HTML::chars($a_card['name']); ?></a> ·
        <span>сравнение</span>
    </p>

    <h1 class="n2j-h1 mt-1">Что чище: <?php echo HTML::chars($a_card['name']); ?> или <?php echo HTML::chars($b_card['name']); ?></h1>

    <div class="mt-5 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- 2/3: вывод и различия -->
        <div class="space-y-5 md:col-span-2">

            <div class="n2j-card">
                <div class="flex items-start gap-3">
                    <i class="fas fa-scale-balanced mt-0.5 text-xl <?php echo $winner ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400'; ?>"></i>
                    <div class="min-w-0">
                        <?php if ($winner): ?>
                            <p class="text-base font-semibold text-gray-900 dark:text-white">
                                Чище состав у «<?php echo HTML::chars($winner['name']); ?>»
                            </p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                <?php echo (int) ($cmp['leader'] === 'a' ? $cmp['a']['score'] : $cmp['b']['score']); ?>
                                против <?php echo (int) ($cmp['leader'] === 'a' ? $cmp['b']['score'] : $cmp['a']['score']); ?>:
                                разница <?php echo $diff; ?> <?php echo $plural($diff, 'пункт', 'пункта', 'пунктов'); ?>.
                            </p>
                        <?php else: ?>
                            <p class="text-base font-semibold text-gray-900 dark:text-white">Составы равны по оценке</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                Обе стороны набрали <?php echo (int) $cmp['a']['score']; ?> — выбирайте по различиям ниже.
                            </p>
                        <?php endif; ?>

                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            <?php if ($winner): ?>
                                Разница по делу: высокий риск —
                                <?php echo (int) ($cmp['leader'] === 'a' ? $cmp['a']['high'] : $cmp['b']['high']); ?>
                                против <?php echo (int) ($cmp['leader'] === 'a' ? $cmp['b']['high'] : $cmp['a']['high']); ?>,
                                средний риск —
                                <?php echo number_format((float) ($cmp['leader'] === 'a' ? $cmp['a']['risk_mean'] : $cmp['b']['risk_mean']), 1, ',', ' '); ?>
                                против <?php echo number_format((float) ($cmp['leader'] === 'a' ? $cmp['b']['risk_mean'] : $cmp['a']['risk_mean']), 1, ',', ' '); ?>.
                            <?php endif; ?>
                            Позиций состава: <?php echo count($cmp['a']['known']) + count($cmp['a']['unknown']); ?>
                            у первого и <?php echo count($cmp['b']['known']) + count($cmp['b']['unknown']); ?> у второго;
                            общих веществ — <?php echo count($cmp['common']); ?>.
                        </p>
                    </div>
                </div>

                <?php if ($loser AND $diff >= 12): ?>
                    <p class="mt-3 rounded-lg bg-blue-50/60 px-3 py-2 text-xs text-blue-800 dark:bg-blue-950/20 dark:text-blue-200">
                        Разница в <?php echo $diff; ?> <?php echo $plural($diff, 'пункт', 'пункта', 'пунктов'); ?> — это заметно. Но составы разные: у «<?php echo HTML::chars($loser['name']); ?>»
                        <?php $own = count($cmp['leader'] === 'a' ? $cmp['only_b'] : $cmp['only_a']); ?>
                        <?php echo $own; ?> <?php echo $plural($own, 'позиция', 'позиции', 'позиций'); ?>,
                        которых нет у лидера, — смотрите списки ниже: среди них может быть то, что вам нужно.
                    </p>
                <?php endif; ?>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <?php $product_card($a_card, $cmp['a']); ?>
                <?php $product_card($b_card, $cmp['b']); ?>
            </div>

            <?php
            $ingredients_block('У обоих', 'Эти вещества есть в обоих составах; цифры справа — риск у первого и второго (0–4).', $cmp['common'], TRUE);
            $ingredients_block('Только у «'.$a_card['name'].'»', '', $cmp['only_a']);
            $ingredients_block('Только у «'.$b_card['name'].'»', '', $cmp['only_b']);
            ?>

            <?php if ($nut_a OR $nut_b): ?>
                <div class="n2j-card">
                    <h2 class="n2j-h3">Пищевая ценность</h2>

                    <?php if ( ! $nut_same_basis): ?>
                        <?php
                        // Почему не рисуем таблицу — объясняем словами, а не
                        // «нет данных»: у одного товара цифр может не быть
                        // вовсе, у другого — быть, но на несравнимой основе
                        // («на порцию: 1 печенье» в 100 г не переводится).
                        $nut_missing = [];

                        if ( ! $nut_a)
                        {
                            $nut_missing[] = $a_card['name'];
                        }

                        if ( ! $nut_b)
                        {
                            $nut_missing[] = $b_card['name'];
                        }
                        ?>
                        <p class="n2j-hint">
                            <?php if ($nut_missing): ?>
                                Сравнить цифры нельзя: у товара
                                <?php echo HTML::chars(implode(' и у товара ', $nut_missing)); ?>
                                пищевой ценности в сравнимом виде нет — на упаковке её не было
                                или она дана на порцию, которую не перевести в 100 г («1 печенье»).
                            <?php else: ?>
                                Сравнить цифры нельзя: таблицы указаны на разной основе
                                (<?php echo HTML::chars(Service_Nj646_About::nutrition_bases()[$nut_a['basis']]
                                    .' и '.Service_Nj646_About::nutrition_bases()[$nut_b['basis']]); ?>).
                                Так рядом стоят разные величины, и «меньше» ничего не значит.
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <p class="n2j-hint">
                            Цифры <?php echo HTML::chars(Service_Nj646_About::nutrition_bases()[$nut_a['basis']]); ?>
                            — как напечатано на упаковке<?php echo ($nut_a['recalculated'] OR $nut_b['recalculated'])
                                ? ' (где была указана порция, пересчитано на 100 — пересчёт помечен)' : ''; ?>.
                            Меньше или больше — не значит «лучше»: это справка, а не оценка продукта.
                        </p>

                        <div class="mt-3 overflow-x-auto">
                            <table class="n2j-table">
                                <thead>
                                    <tr class="text-left text-xs uppercase tracking-wide text-gray-400">
                                        <th class="py-1.5 pr-3 font-semibold">Показатель</th>
                                        <th class="py-1.5 pr-3 font-semibold">
                                            <?php echo HTML::chars($a_card['name']); ?>
                                            <?php if ($nut_a['recalculated']): ?><span class="font-normal normal-case text-gray-400">· пересчёт с «<?php echo HTML::chars($nut_a['from_portion']); ?>»</span><?php endif; ?>
                                        </th>
                                        <th class="py-1.5 font-semibold">
                                            <?php echo HTML::chars($b_card['name']); ?>
                                            <?php if ($nut_b['recalculated']): ?><span class="font-normal normal-case text-gray-400">· пересчёт с «<?php echo HTML::chars($nut_b['from_portion']); ?>»</span><?php endif; ?>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                    <?php foreach (Service_Nj646_About::nutrition_fields() as $nf => $nspec): ?>
                                        <?php
                                        $va = $nut_a['values'][$nf] ?? NULL;
                                        $vb = $nut_b['values'][$nf] ?? NULL;

                                        if ($va === NULL AND $vb === NULL)
                                        {
                                            continue;
                                        }
                                        ?>
                                        <tr>
                                            <td class="py-1.5 pr-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($nspec['title']); ?></td>
                                            <td class="py-1.5 pr-3 font-semibold text-gray-900 dark:text-white">
                                                <?php echo $va === NULL ? '<span class="font-normal text-gray-400">—</span>'
                                                    : HTML::chars(Service_Nj646_About::nutrition_number($va).' '.$nspec['unit']); ?>
                                            </td>
                                            <td class="py-1.5 font-semibold text-gray-900 dark:text-white">
                                                <?php echo $vb === NULL ? '<span class="font-normal text-gray-400">—</span>'
                                                    : HTML::chars(Service_Nj646_About::nutrition_number($vb).' '.$nspec['unit']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($cmp['a']['unknown'] OR $cmp['b']['unknown']): ?>
                <div class="n2j-card">
                    <h2 class="n2j-h3">Чего база ещё не знает</h2>
                    <p class="n2j-hint">
                        Эти позиции прочитаны с этикетки, но их нет в базе проекта — по ним вердикта нет.
                        Они попадают в очередь изучения и появятся в сравнении при следующем разборе.
                    </p>
                    <?php foreach ([['a', $a_card], ['b', $b_card]] as $pair): ?>
                        <?php if ($cmp[$pair[0]]['unknown']): ?>
                            <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo HTML::chars($pair[1]['name']); ?></p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300"><?php echo HTML::chars(implode(' · ', array_slice($cmp[$pair[0]]['unknown'], 0, 12))); ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 1/3: как это считается -->
        <div class="space-y-5">
            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 text-xs text-gray-500 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40 dark:text-gray-400">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-circle-info text-blue-500"></i>
                    <h3 class="text-sm font-bold">Как мы считаем</h3>
                </div>
                <p class="mt-2">
                    Оценка — та же, что и в разборе: средний риск веществ по базе минус штраф за незнакомые позиции.
                    Здесь она пересчитана <span class="font-semibold">по сегодняшней базе</span> — вещество могли изучить
                    после того, как разобрали состав, и оба товара должны меряться одной линейкой.
                </p>
                <p class="mt-2">
                    «Чище» — это не про вкус и не про пользу продукта: только про состав. Продукты разных типов
                    сравнивать можно, но помните, что творог и печенье — разная еда.
                </p>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Что именно сравнили</h3>
                <?php foreach ([[$a_card, $cmp['a']['verdict'], $a], [$b_card, $cmp['b']['verdict'], $b]] as $item): ?>
                    <div class="mt-3 text-xs">
                        <p class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($item[0]['name']); ?></p>
                        <p class="mt-0.5 text-gray-500 dark:text-gray-400">
                            <?php echo HTML::chars(Service_Nj646_Label::verdict_label((string) $item[1])); ?> ·
                            разборов: <?php echo (int) ($item[2]['analyses_count'] ?? 0); ?> ·
                            последний: <?php echo HTML::chars(Service_Nj646_Label::ago_ru((string) ($item[2]['last_seen'] ?? ''))); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
                <p class="n2j-hint mt-3">
                    Сравниваются последние разборы этих товаров. Состав у одной марки меняется от партии к партии —
                    то, что вы держите в руках, может отличаться от разобранного.
                </p>
                <a href="/label/compare" class="n2j-btn-secondary n2j-btn-sm mt-3">
                    <i class="fas fa-scale-balanced mr-1.5"></i>выбрать другую пару
                </a>
            </div>
        </div>
    </div>
</div>
