<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Карточка производителя (/label/producer/<id>).
 *
 * Организации с этикеток — это те же предприятия, что в справочнике
 * организаций (nj594) и общей онтологии (nj607): здесь видно, что о компании
 * известно из разборов, какие у неё товары и куда она привязалась в проекте.
 * Одна запись на организацию — так решил ключ `name_key` (см. Store).
 */
$v = $view;

$facts = [
    ['Адрес', $producer['address']],
    ['ИНН', $producer['inn']],
    ['ОГРН', $producer['ogrn']],
    ['Телефон', $producer['phone']],
    ['Сайт', $producer['site']],
    ['Страна', $producer['country']],
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <p class="n2j-hint">
        <a href="/label" class="hover:underline">Разбор состава</a> ·
        <span>производитель</span>
    </p>
    <h1 class="n2j-h1 mt-1 break-words"><?php echo HTML::chars($producer['name']); ?></h1>
    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
        <span class="rounded-md bg-blue-50 px-2 py-0.5 font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
            <?php echo HTML::chars(Service_Nj646_About::roles()[(string) $producer['role']] ?? 'Изготовитель'); ?>
        </span>
        <span class="text-gray-400">на этикетках встретился: <?php echo (int) $producer['hits']; ?> раз</span>
        <span class="text-gray-400">·</span>
        <span class="text-gray-400">с <?php echo HTML::chars(substr((string) $producer['first_seen'], 0, 10)); ?></span>
    </div>

    <div class="mt-5 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <div class="space-y-5 md:col-span-2">
            <div class="n2j-card">
                <h2 class="n2j-h3">Реквизиты с этикетки</h2>
                <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <?php foreach ($facts as $fact): ?>
                        <?php if (trim((string) $fact[1]) === '') { continue; } ?>
                        <div class="min-w-0">
                            <dt class="text-xs uppercase tracking-wider text-gray-400"><?php echo HTML::chars($fact[0]); ?></dt>
                            <dd class="mt-0.5 break-words text-sm text-gray-800 dark:text-gray-100"><?php echo HTML::chars((string) $fact[1]); ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
                <p class="n2j-hint mt-3">
                    Реквизиты взяты с упаковки и проверены контрольными цифрами (ИНН, ОГРН) — ничего не додумано.
                    <?php if ($producer['address'] === ''): ?>Адрес на этикетке не печатали — и здесь его нет.<?php endif; ?>
                </p>
            </div>

            <div class="n2j-card">
                <h2 class="n2j-h3">Товары этого производителя</h2>

                <?php if ( ! $products): ?>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Пока ни одного товара с этой строкой производителя.</p>
                <?php else: ?>
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($products as $product): ?>
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                                <a href="/label/product/<?php echo (int) $product['id']; ?>"
                                   class="font-semibold text-gray-800 hover:text-blue-700 hover:underline dark:text-gray-100 dark:hover:text-blue-300">
                                    <?php echo HTML::chars($product['name'] !== '' ? $product['name'] : 'Без названия'); ?>
                                </a>
                                <?php if ($product['brand'] !== ''): ?>
                                    <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($product['brand']); ?></span>
                                <?php endif; ?>
                                <?php if ($product['weight'] !== ''): ?>
                                    <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($product['weight']); ?></span>
                                <?php endif; ?>
                                <?php if ($product['barcode'] !== ''): ?>
                                    <span class="ml-auto font-mono text-xs text-gray-400"><?php echo HTML::chars($product['barcode']); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php if ($analyses): ?>
            <div class="n2j-card">
                <h2 class="n2j-h3">Последние разборы</h2>
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($analyses as $row): ?>
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                            <span class="w-10 font-mono text-xs font-bold text-gray-800 dark:text-gray-100"><?php echo (int) $row['score']; ?></span>
                            <span class="text-xs text-gray-600 dark:text-gray-300">
                                <?php echo HTML::chars($row['product_name'] !== '' && $row['product_name'] !== NULL
                                    ? (string) $row['product_name'] : 'товар без названия'); ?>
                            </span>
                            <span class="text-xs text-gray-400"><?php echo HTML::chars(substr((string) $row['created_at'], 0, 16)); ?></span>
                            <?php if ($admin): ?>
                                <a href="/label/analysis/<?php echo (int) $row['id']; ?>"
                                   class="ml-auto text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">открыть разбор</a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <div class="space-y-5">
            <div class="n2j-card">
                <div class="flex items-center gap-2">
                    <i class="fas fa-diagram-project text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">В проекте</h3>
                </div>

                <div class="mt-3 space-y-2 text-xs">
                    <?php if ($v['ontology_url'] !== ''): ?>
                        <a href="<?php echo HTML::chars($v['ontology_url']); ?>"
                           class="n2j-btn-secondary n2j-btn-sm">
                            <i class="fas fa-sitemap text-[11px] text-gray-400"></i>Онтология предприятий
                        </a>
                    <?php else: ?>
                        <p class="text-gray-500 dark:text-gray-400">В общую онтологию предприятий запись ещё не попала.</p>
                    <?php endif; ?>

                    <?php if ($v['org_url'] !== ''): ?>
                        <a href="<?php echo HTML::chars($v['org_url']); ?>"
                           class="n2j-btn-secondary n2j-btn-sm">
                            <i class="fas fa-building-columns text-[11px] text-gray-400"></i>Справочник организаций
                        </a>
                    <?php else: ?>
                        <p class="text-gray-500 dark:text-gray-400">
                            В справочнике организаций (nj594) совпадений по ИНН/ОГРН нет:
                            <?php echo ($producer['inn'] === '' AND $producer['ogrn'] === '')
                                ? 'на этикетке этих реквизитов не было.'
                                : 'там такой организации ещё нет.'; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 text-xs text-gray-500 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40 dark:text-gray-400">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-shield-halved text-emerald-500"></i>
                    <h3 class="text-sm font-bold">Одна запись на организацию</h3>
                </div>
                <p class="mt-2">
                    Товары одной компании сходятся в эту карточку по нормализованному названию: «ООО «Ромашка»» и
                    «ООО "РОМАШКА"» — одна организация. Карточки не сливаются автоматически, если реквизиты разные.
                </p>
            </div>
        </div>
    </div>
</div>
