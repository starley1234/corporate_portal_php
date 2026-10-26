<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Карточка товара (/label/product/<id>) — что накопилось о нём с этикеток.
 *
 * Владелец (03.10.2026): «сопутствующую информацию о производителе и товаре с
 * этикетки тоже в БД сохраняй (для проекта в целом)». Здесь эта информация
 * видна: масса, ГОСТ/ТУ, срок годности, условия хранения, штрихкод, страна — и
 * ИСТОРИЯ РАЗБОРОВ: у товара состав меняется от партии к партии, и по датам с
 * оценками это видно без повторного ввода состава.
 *
 * Разборы других людей показываем только цифрами (оценка, вердикт, сколько
 * позиций): сам текст состава видел лишь тот, кто его прислал.
 */
$p = $product;
$v = $view['product'];

$fields = [
    ['Штрихкод', $p['barcode'], 'font-mono'],
    ['Масса / объём', $p['weight'], ''],
    ['ГОСТ / ТУ', $p['gost'], ''],
    ['Срок годности', $p['shelf_life'], ''],
    ['Условия хранения', $p['storage'], ''],
    ['Страна', $p['country'] !== '' ? $p['country'] : (string) ($producer['country'] ?? ''), ''],
];

// Пищевая ценность: таблица с этикетки (или правка админа) + нейтральные
// пометки «много сахаров / мало соли» с названными порогами.
$nutrition       = Service_Nj646_Store::nutrition_of($p);
$nutrition_flags = Service_Nj646_About::nutrition_flags($nutrition);
$nutrition_basis = Service_Nj646_About::nutrition_bases()[$nutrition['basis'] ?? '100g'] ?? 'на 100 г';

// Таблица «на порцию» несравнима ни с чем: пересчитываем её на 100 г, когда
// производитель назвал размер порции числом. Пересчёт показываем ОТДЕЛЬНО и
// с пометкой — в базе по-прежнему лежит то, что напечатано на упаковке.
$nutrition_per100 = ($nutrition AND ($nutrition['basis'] ?? '') === 'portion')
    ? Service_Nj646_About::nutrition_per100($nutrition) : [];
$nutrition_per100_flags = $nutrition_per100
    ? Service_Nj646_About::nutrition_flags($nutrition_per100) : [];

$verdict_class = [
    'useful'  => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    'neutral' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    'caution' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    'risky'   => 'bg-rose-50 text-rose-600 dark:bg-rose-900/20 dark:text-rose-300',
    'avoid'   => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-200',
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <p class="n2j-hint">
        <a href="/label" class="hover:underline">Состав</a> ·
        <span>товар</span>
    </p>
    <?php
    // Тип продукта («зубная паста», «печенье») модуль определяет сам —
    // по нему и иконка, и подпись карточки.
    $kind = Service_Nj646_Label::product_kind_info((string) ($p['product_kind'] ?? ''));
    $name_auto = Service_Nj646_Store::flag($p['name_auto'] ?? FALSE);
    ?>
    <h1 class="n2j-h1 mt-1 flex flex-wrap items-center gap-2">
        <i class="<?php echo HTML::chars($kind['icon']); ?> text-gray-400"></i>
        <span><?php echo HTML::chars($p['name'] !== '' ? $p['name'] : 'Товар без названия'); ?></span>
        <?php if ($name_auto): ?>
            <span class="rounded-full border border-dashed border-gray-300 px-2 py-0.5 text-xs font-normal text-gray-500 dark:border-gray-600 dark:text-gray-400"
                  title="Названия на этикетке не нашлось — модуль дал рабочее имя по типу продукта. Настоящее имя подставится, когда этикетка его даст или модель прочитает.">
                имя рабочее
            </span>
        <?php endif; ?>
    </h1>
    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
        <span class="rounded-md bg-gray-100 px-2 py-0.5 font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
            <i class="<?php echo HTML::chars($kind['icon']); ?> mr-1"></i><?php echo HTML::chars($kind['title']); ?>
        </span>
        <?php if ((int) ($p['last_score'] ?? 0) > 0): ?>
            <span class="rounded-md px-2 py-0.5 font-semibold <?php echo $verdict_class[(string) $p['last_verdict']] ?? 'bg-gray-100 text-gray-600'; ?>">
                <?php echo HTML::chars(Service_Nj646_Label::verdict_label((string) $p['last_verdict'])); ?>
                <?php echo (int) $p['last_score']; ?>
            </span>
        <?php endif; ?>
        <?php if ($p['brand'] !== ''): ?>
            <span class="rounded-md bg-blue-50 px-2 py-0.5 font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                <?php echo HTML::chars($p['brand']); ?>
            </span>
        <?php endif; ?>
        <?php if ($p['barcode'] !== ''): ?>
            <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-0.5 font-mono text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                <i class="fas fa-barcode text-[11px]"></i><?php echo HTML::chars($p['barcode']); ?>
            </span>
        <?php endif; ?>
        <span class="text-gray-400">разборов состава: <?php echo (int) ($p['analyses_count'] ?? $p['hits']); ?></span>
        <span class="text-gray-400">·</span>
        <span class="text-gray-400">данные с этикеток, <?php echo HTML::chars(substr((string) $p['first_seen'], 0, 10)); ?></span>
    </div>

    <div class="mt-5 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- 2/3: что известно о товаре и его разборы -->
        <div class="space-y-5 md:col-span-2">

            <div class="n2j-card">
                <h2 class="n2j-h3">Что напечатано на этикетке</h2>
                <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <?php foreach ($fields as $field): ?>
                        <?php if (trim((string) $field[1]) === '') { continue; } ?>
                        <div class="min-w-0">
                            <dt class="text-xs uppercase tracking-wider text-gray-400"><?php echo HTML::chars($field[0]); ?></dt>
                            <dd class="mt-0.5 break-words text-sm text-gray-800 <?php echo $field[2]; ?> dark:text-gray-100">
                                <?php echo HTML::chars((string) $field[1]); ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($p['gost'] === '' AND $p['shelf_life'] === '' AND $p['storage'] === '' AND $p['weight'] === ''): ?>
                        <p class="n2j-hint">
                            На этой этикетке служебных строк (ГОСТ, масса, срок) не было — сохранили только то, что удалось прочитать.
                        </p>
                    <?php endif; ?>
                </dl>
            </div>

            <?php // ПИЩЕВАЯ ЦЕННОСТЬ. Для еды это половина ответа на вопрос «что я ем»:
                  // сахара и соль видно сразу, а не через состав. Таблицы нет —
                  // блока нет: пустые строки читались бы как нули. ?>
            <?php if ($nutrition): ?>
                <div class="n2j-card">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="n2j-h3">Пищевая ценность</h2>
                        <p class="n2j-hint">
                            <?php echo HTML::chars($nutrition_basis); ?><?php
                                echo (string) ($nutrition['portion'] ?? '') !== ''
                                    ? ' · порция '.HTML::chars((string) $nutrition['portion']) : ''; ?>
                        </p>
                    </div>

                    <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                        <?php foreach (Service_Nj646_About::nutrition_fields() as $nf => $nspec): ?>
                            <?php if ( ! isset($nutrition['values'][$nf])) { continue; } ?>
                            <div class="flex items-baseline justify-between gap-3 border-b border-gray-100 py-1 dark:border-gray-700/60">
                                <dt class="text-sm text-gray-600 dark:text-gray-300"><?php echo HTML::chars($nspec['title']); ?></dt>
                                <dd class="text-sm font-semibold text-gray-900 dark:text-white">
                                    <?php echo HTML::chars(Service_Nj646_About::nutrition_number($nutrition['values'][$nf])); ?>
                                    <span class="text-xs font-normal text-gray-400"><?php echo HTML::chars($nspec['unit']); ?></span>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>

                    <?php if ($nutrition_flags): ?>
                        <ul class="mt-3 space-y-1.5">
                            <?php foreach ($nutrition_flags as $flag): ?>
                                <li class="flex items-start gap-2 rounded-lg px-2.5 py-1.5 text-xs <?php echo $flag['kind'] === 'warn'
                                    ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
                                    : 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'; ?>">
                                    <i class="fas <?php echo $flag['kind'] === 'warn' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?> mt-0.5 shrink-0"></i>
                                    <span><?php echo HTML::chars($flag['text']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($nutrition_per100): ?>
                        <?php // Та же таблица на 100 г: только так её можно сравнить
                              // с другим товаром и с ориентирами маркировки. ?>
                        <div class="mt-3 rounded-lg border border-dashed border-gray-200 px-3 py-2 dark:border-gray-700">
                            <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                                В пересчёте <?php echo HTML::chars(Service_Nj646_About::nutrition_bases()[$nutrition_per100['basis']]); ?>
                                <span class="font-normal text-gray-400">(порция <?php echo HTML::chars($nutrition_per100['from_portion']); ?> — арифметика сервиса, не этикетка)</span>
                            </p>
                            <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">
                                <?php
                                $per100_parts = [];

                                foreach (Service_Nj646_About::nutrition_fields() as $nf => $nspec)
                                {
                                    if (isset($nutrition_per100['values'][$nf]))
                                    {
                                        $per100_parts[] = $nspec['title'].' '
                                            .Service_Nj646_About::nutrition_number($nutrition_per100['values'][$nf]).' '.$nspec['unit'];
                                    }
                                }

                                echo HTML::chars(implode(' · ', $per100_parts));
                                ?>
                            </p>
                            <?php if ($nutrition_per100_flags): ?>
                                <ul class="mt-2 space-y-1">
                                    <?php foreach ($nutrition_per100_flags as $flag): ?>
                                        <li class="text-xs <?php echo $flag['kind'] === 'warn'
                                            ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300'; ?>">
                                            <?php echo HTML::chars($flag['text']); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <p class="n2j-hint mt-3">
                        Цифры взяты с этикетки как есть. Пометки — справочное сравнение с общепринятыми
                        ориентирами маркировки, а не медицинская рекомендация.
                    </p>
                </div>
            <?php endif; ?>

            <div class="n2j-card">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="n2j-h3">История разборов</h2>
                    <?php if ($average !== NULL): ?>
                        <p class="n2j-hint">
                            средняя оценка состава: <span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo (int) $average; ?></span>
                            из 100<?php if (count($analyses) > 1): ?>, лучшая — <?php echo (int) $best; ?><?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ( ! $analyses): ?>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Разборов этого товара ещё нет.</p>
                <?php else: ?>
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($analyses as $row): ?>
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                                <span class="w-10 shrink-0 font-mono text-xs font-bold text-gray-800 dark:text-gray-100"><?php echo (int) $row['score']; ?></span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?php echo $verdict_class[(string) $row['verdict']] ?? $verdict_class['neutral']; ?>">
                                    <?php echo HTML::chars(Service_Nj646_Label::verdict_label((string) $row['verdict'])); ?>
                                </span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    <?php echo HTML::chars(substr((string) $row['created_at'], 0, 16)); ?>
                                    · <?php echo $row['source'] === 'photo' ? 'фото этикетки' : 'состав текстом'; ?>
                                </span>
                                <?php if ($admin): ?>
                                    <a href="/label/analysis/<?php echo (int) $row['id']; ?>"
                                       class="ml-auto text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">открыть разбор</a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="n2j-hint mt-3">
                        Тексты составов не показываем: разбор открыт только тому, кто его прислал.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 1/3: производитель и связи -->
        <div class="space-y-5">
            <?php if ( ! empty($similar)): ?>
                <div class="n2j-card">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-scale-balanced text-blue-600 dark:text-blue-400"></i>
                        <h3 class="text-sm font-bold text-gray-900 dark:text-white">Сравнить с другим товаром</h3>
                    </div>
                    <p class="n2j-hint">
                        Составы меряются одной линейкой: общие вещества, различия, средний риск и число опасных позиций.
                    </p>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($similar as $row): ?>
                            <?php $row_kind = Service_Nj646_Label::product_kind_info((string) $row['product_kind']); ?>
                            <li>
                                <a href="/label/compare/<?php echo (int) $p['id']; ?>/<?php echo (int) $row['id']; ?>"
                                   class="flex min-h-[44px] items-center gap-2 py-2 hover:text-blue-700 dark:hover:text-blue-300">
                                    <i class="<?php echo HTML::chars($row_kind['icon']); ?> w-4 shrink-0 text-center text-gray-400"></i>
                                    <span class="min-w-0 flex-1 truncate text-xs text-gray-800 dark:text-gray-100">
                                        <?php echo HTML::chars($row['name'] !== '' ? $row['name'] : 'Товар без названия'); ?>
                                        <?php if ((string) ($row['producer_name'] ?? '') !== ''): ?>
                                            <span class="text-gray-400">· <?php echo HTML::chars((string) $row['producer_name']); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ((int) ($row['last_score'] ?? 0) > 0): ?>
                                        <span class="shrink-0 text-xs font-bold text-gray-500 dark:text-gray-400"><?php echo (int) $row['last_score']; ?></span>
                                    <?php endif; ?>
                                    <i class="fas fa-chevron-right shrink-0 text-[10px] text-gray-300 dark:text-gray-600"></i>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="/label/compare/<?php echo (int) $p['id']; ?>"
                       class="mt-2 inline-flex min-h-[44px] items-center text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">
                        выбрать другого
                    </a>
                </div>
            <?php endif; ?>

            <div class="n2j-card">
                <div class="flex items-center gap-2">
                    <i class="fas fa-industry text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">Кто произвёл</h3>
                </div>

                <?php if ($producer): ?>
                    <a href="/label/producer/<?php echo (int) $producer['id']; ?>"
                       class="mt-2 block break-words text-sm font-semibold text-gray-900 hover:text-blue-700 hover:underline dark:text-white dark:hover:text-blue-300">
                        <?php echo HTML::chars($producer['name']); ?>
                    </a>
                    <p class="n2j-hint mt-0.5">
                        <?php echo HTML::chars(Service_Nj646_About::roles()[(string) $producer['role']] ?? 'Изготовитель'); ?>
                        <?php if ($producer['country'] !== ''): ?> · <?php echo HTML::chars($producer['country']); ?><?php endif; ?>
                    </p>
                    <?php if ($producer['address'] !== ''): ?>
                        <p class="mt-2 break-words text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($producer['address']); ?></p>
                    <?php endif; ?>
                    <?php if ($producer['inn'] !== '' OR $producer['ogrn'] !== ''): ?>
                        <p class="n2j-hint mt-2 font-mono">
                            <?php if ($producer['inn'] !== ''): ?>ИНН <?php echo HTML::chars($producer['inn']); ?><?php endif; ?>
                            <?php if ($producer['ogrn'] !== ''): ?> · ОГРН <?php echo HTML::chars($producer['ogrn']); ?><?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <?php if ($v['ontology_url'] !== ''): ?>
                            <a href="<?php echo HTML::chars($v['ontology_url']); ?>"
                               class="n2j-btn-secondary n2j-btn-sm">
                                в онтологии предприятий
                            </a>
                        <?php endif; ?>
                        <?php if ($v['org_url'] !== ''): ?>
                            <a href="<?php echo HTML::chars($v['org_url']); ?>"
                               class="n2j-btn-secondary n2j-btn-sm">
                                в справочнике организаций
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="n2j-hint mt-2">
                        Производителя на этикетке не было (или строку не удалось прочитать).
                        Появится — сохраним и свяжем с проектом.
                    </p>
                <?php endif; ?>
            </div>

            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 text-xs text-gray-500 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40 dark:text-gray-400">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-circle-info text-blue-500"></i>
                    <h3 class="text-sm font-bold">Откуда это</h3>
                </div>
                <p class="mt-2">
                    Данные разобраны из строк этикетки автоматически (без модели): реквизиты проверены контрольными
                    цифрами, штрихкод — знаком EAN. Чего на этикетке нет, того здесь не будет.
                </p>
                <p class="mt-2">
                    <a href="/label" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Разобрать состав</a>
                    — и этот товар попадёт в общую базу проекта.
                </p>
            </div>
        </div>
    </div>
</div>

<?php
/* -----------------------------------------------------------------------------
 Разметка товара для поисковых систем (Schema.org, JSON-LD).

 Карточка товара — главный публичный актив модуля: страниц много, и каждая
 отвечает на вопрос «что внутри этого продукта». Без разметки поиск видит
 просто текст; с ней у страницы есть шанс на расширенный сниппет, а таблица
 пищевой ценности — ровно тот тип данных, который поисковики умеют показывать.

 Пишем ТОЛЬКО то, что есть на этикетке: ни цен, ни рейтингов, ни наличия —
 выдумывать разметку ради сниппета нельзя (и это прямой путь к санкциям).
 Нет названия — нет и разметки: «Продукт без названия» в выдаче не нужен.
----------------------------------------------------------------------------- */
$ld = [];

// `$name_auto` посчитан выше через `Store::flag()`: PostgreSQL отдаёт `false`
// строкой «f», и `empty()` на ней не сработает — разметка появилась бы у
// товаров с рабочим именем «Печенье без названия».
if (trim((string) $p['name']) !== '' AND ! $name_auto)
{
    $ld = [
        '@context' => 'https://schema.org',
        '@type'    => 'Product',
        'name'     => (string) $p['name'],
        'url'      => URL::site('label/product/'.(int) $p['id'], TRUE),
    ];

    if (trim((string) $p['brand']) !== '')
    {
        $ld['brand'] = ['@type' => 'Brand', 'name' => (string) $p['brand']];
    }

    if (trim((string) $p['barcode']) !== '')
    {
        $ld['gtin'] = (string) $p['barcode'];
    }

    if ($producer AND trim((string) $producer['name']) !== '')
    {
        $ld['manufacturer'] = ['@type' => 'Organization', 'name' => (string) $producer['name']];
    }

    // Пищевая ценность: только на 100 г/мл (в том числе пересчитанная с
    // порции) — иначе «на порцию» прочитается как «на 100 г» и соврёт.
    $ld_nutrition = Service_Nj646_About::nutrition_per100($nutrition);
    $ld_map = [
        'kcal'    => ['calories', ' calories'],
        'protein' => ['proteinContent', ' g'],
        'fat'     => ['fatContent', ' g'],
        'fat_sat' => ['saturatedFatContent', ' g'],
        'carbs'   => ['carbohydrateContent', ' g'],
        'sugars'  => ['sugarContent', ' g'],
        'fiber'   => ['fiberContent', ' g'],
        'sodium'  => ['sodiumContent', ' g'],
    ];

    if ($ld_nutrition AND $ld_nutrition['basis'] !== 'portion')
    {
        $info = ['@type' => 'NutritionInformation',
                 'servingSize' => $ld_nutrition['basis'] === '100ml' ? '100 ml' : '100 g'];

        foreach ($ld_map as $field => $pair)
        {
            if (isset($ld_nutrition['values'][$field]))
            {
                $info[$pair[0]] = rtrim(rtrim(number_format($ld_nutrition['values'][$field], 2, '.', ''), '0'), '.').$pair[1];
            }
        }

        if (count($info) > 2)
        {
            $ld['nutrition'] = $info;
        }
    }
}
?>
<?php if ($ld): ?>
    <script type="application/ld+json">
<?php echo json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
    </script>
<?php endif; ?>
