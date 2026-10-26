<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj607_industry: /industry/entity/<slug> — страница сущности онтологии.
 *
 * Владелец: «сделай из отчёта сущности, которые надо будет связать с другими
 * модулями. Наша цель — связанная онтология». Здесь видно всё, что мы знаем о
 * сущности: в каких отчётах она встречается и с какими объектами других
 * сервисов связана (связи только реальные — найденные в нашей базе).
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800';

$modules = [
    'profile'  => ['Профиль компании', 'fas fa-id-card'],
    'org'      => ['Организация',      'fas fa-landmark'],
    'orgdocs'  => ['Досье организации', 'fas fa-file-alt'],
    'dossier'  => ['AI-досье',         'fas fa-user-secret'],
    'industry' => ['Карта отрасли',    'fas fa-map'],
    'search'   => ['Найдено в базе',   'fas fa-magnifying-glass'],
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/industry" class="transition hover:text-blue-600 dark:hover:text-blue-400">Карта отрасли</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Сущность</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="<?php echo $kind['icon']; ?> mr-2 text-blue-500"></i><?php echo HTML::chars((string) $e['title']); ?>
        </h1>
        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="<?php echo $kind['icon']; ?> text-xs"></i><?php echo HTML::chars($kind['one']); ?>
        </span>
    </div>

    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 sm:text-sm dark:text-gray-400">
        <?php if ((string) $e['domain'] !== ''): ?>
        <span class="inline-flex items-center gap-1.5"><i class="fas fa-globe text-xs"></i><?php if ((string) $e['url'] !== ''): ?><a href="<?php echo HTML::chars((string) $e['url']); ?>" target="_blank" rel="noopener nofollow" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $e['domain']); ?></a><?php else: ?><?php echo HTML::chars((string) $e['domain']); ?><?php endif; ?></span>
        <span>•</span>
        <?php endif; ?>
        <?php if ((string) $e['region'] !== ''): ?>
        <span class="inline-flex items-center gap-1.5"><i class="fas fa-location-dot text-xs"></i><?php echo HTML::chars((string) $e['region']); ?></span>
        <span>•</span>
        <?php endif; ?>
        <?php if ( ! empty($_GET['flash'])): ?>
        <div class="n2j-flash n2j-flash-info mb-2 text-xs block"><?php echo HTML::chars((string) $_GET['flash']); ?></div>
        <?php endif; ?>
        <?php /* «Встречается в отчётах» считаем по УПОМИНАНИЯМ (nj_607_mentions):
         * сущность общая для онтологии, и один и тот же отчёт может упомянуть её,
         * не будучи «первым». Если миграции упоминаний ещё нет — как раньше, по
         * записям сущности. */ ?>
        <?php $reports_n = ! empty($reports) ? count($reports) : count($twins); ?>
        <span class="inline-flex items-center gap-1.5"><i class="fas fa-diagram-project text-xs"></i>встречается в отчётах: <strong class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $reports_n; ?></strong></span>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <div class="space-y-5 md:col-span-2">
            <!-- Что знаем -->
            <div class="<?php echo $card; ?>">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-circle-info text-base text-blue-600 dark:text-blue-400"></i>
                    <h2 class="n2j-h3">Что известно</h2>
                </div>
                <?php if (trim((string) $e['details']) !== ''): ?>
                <p class="mt-2 text-sm leading-relaxed text-gray-700 dark:text-gray-300"><?php echo HTML::chars((string) $e['details']); ?></p>
                <?php else: ?>
                <p class="n2j-hint mt-2">В отчёте у этой сущности нет описания — есть только упоминание. Связи ниже собраны по названию и домену.</p>
                <?php endif; ?>

                <?php if ( ! empty($actions)): ?>
                <p class="mt-3 border-t border-gray-100 pt-3 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:border-gray-700/60">Продолжить в других сервисах</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <?php foreach ($actions as $a): ?>
                    <a href="<?php echo HTML::chars($a[0]); ?>" class="n2j-btn-secondary">
                        <i class="<?php echo $a[1]; ?> text-xs"></i><?php echo HTML::chars($a[2]); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Связи с другими модулями -->
            <div class="<?php echo $card; ?>">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-link text-base text-emerald-600 dark:text-emerald-400"></i>
                    <h2 class="n2j-h3">Связанные объекты в сервисах</h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($links); ?></span>
                    <?php if ( ! empty($is_admin)): ?>
                    <form method="post" class="ml-auto">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars((string) $csrf); ?>">
                        <input type="hidden" name="act" value="links_refresh">
                        <button type="submit" title="Найти совпадения в профилях, организациях, досье и отчётах заново" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-rotate mr-1"></i>Обновить связи</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php if (empty($links)): ?>
                <p class="n2j-hint mt-2">Пока связи не нашлись: в базе нет объектов с таким названием или доменом. Связь появится сама, когда в сервисах появится подходящая компания, организация или досье.</p>
                <?php else: ?>
                <ul class="mt-2 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($links as $l): $mi = $modules[(string) $l['module']] ?? ['Другое', 'fas fa-link']; ?>
                    <li class="flex flex-wrap items-center gap-2 py-2 text-sm">
                        <i class="<?php echo $mi[1]; ?> w-4 shrink-0 text-gray-400"></i>
                        <span class="n2j-badge n2j-badge-wait shrink-0"><?php echo HTML::chars($mi[0]); ?></span>
                        <a href="<?php echo HTML::chars((string) $l['target_url']); ?>" class="min-w-0 flex-1 truncate font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($l['label'] !== '' ? (string) $l['label'] : (string) $l['target_url']); ?></a>
                        <span class="shrink-0 text-xs text-gray-400" title="Насколько уверенно совпало"><?php echo (int) $l['score']; ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Сайдбар: где встречается -->
        <div class="space-y-5">
            <div class="<?php echo $card; ?>">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-map text-base text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">В отчётах</h3>
                </div>
                <?php if ( ! empty($reports)): ?>
                <ul class="mt-2 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($reports as $rp): ?>
                    <li class="py-2 text-xs">
                        <a href="/industry/<?php echo HTML::chars((string) $rp['code']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $rp['industry']); ?></a>
                        <span class="mt-0.5 block text-gray-400">
                            <?php if ((string) $rp['created_day'] !== ''): ?><?php echo HTML::chars((string) $rp['created_day']); ?><?php endif; ?>
                            <?php if ((string) $rp['region'] !== ''): ?> · <?php echo HTML::chars((string) $rp['region']); ?><?php endif; ?>
                            <?php if (in_array((string) $rp['status'], ['running', 'new'], TRUE)): ?> · <span class="text-amber-600 dark:text-amber-400"><?php echo HTML::chars(Service_Nj607_Industry::status_label((string) $rp['status'])); ?></span><?php endif; ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($reports_n > count($reports)): ?>
                <p class="n2j-hint mt-2">Показаны последние <?php echo count($reports); ?> отчётов.</p>
                <?php endif; ?>
                <?php else: ?>
                <ul class="mt-2 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($twins as $t): ?>
                    <li class="py-2 text-xs">
                        <?php if ((string) $t['report_code'] !== ''): ?>
                        <a href="/industry/<?php echo HTML::chars((string) $t['report_code']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $t['industry']); ?></a>
                        <?php else: ?>
                        <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $t['industry']); ?></span>
                        <?php endif; ?>
                        <span class="mt-0.5 block text-gray-400">
                            <?php if ((string) $t['report_date'] !== ''): ?><?php echo HTML::chars((string) $t['report_date']); ?><?php endif; ?>
                            <?php if ((string) $t['report_region'] !== ''): ?> · <?php echo HTML::chars((string) $t['report_region']); ?><?php endif; ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-diagram-project text-amber-500"></i>
                    <h3 class="text-sm font-bold">Зачем это</h3>
                </div>
                <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Сущности — общий словарь сервисов: одна компания, регион или продукт связывают карту отрасли, радар заказов, лидов и профили компаний. Чем больше связей, тем полезнее каждый отчёт.</p>
            </div>
        </div>
    </div>

    <p class="mt-5"><a href="/industry" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400"><i class="fas fa-arrow-left text-[11px]"></i>Карта отрасли</a></p>
</div>
