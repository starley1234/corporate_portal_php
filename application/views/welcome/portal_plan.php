<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * /portal-plan — дорожная карта портала.
 *
 * Показывает три источника в одном жизненном цикле:
 *   * реализованные сервисы — состояние из манифеста (approved/wip/unpublished);
 *   * сервисы вне отложенного плана — одобренные и «в работе»;
 *   * планируемые — записи portal.php, для которых модуля ещё нет.
 * Ссылку получают только доступные текущей роли одобренные сервисы.
 */
$badges = [
    'approved'    => ['prx-badge-ok', 'Одобрен'],
    'wip'         => ['prx-badge-run', 'В работе'],
    'unpublished' => ['prx-badge-wait', 'Не опубликован'],
    'archived'    => ['', 'Архив'],
    'planned'     => ['prx-badge-wait', 'Планируется'],
];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['План и архив портала']],
        'title'  => $title,
        'lead'   => 'Дорожная карта: что уже одобрено к работе, что в работе и что пока запланировано.',
    ]); ?>

    <section class="mt-6" aria-labelledby="pipeline-plan">
        <h2 id="pipeline-plan" class="prx-h3">План производственных пайплайнов</h2>
        <p class="mt-3">Запланированные сценарии: от разработки изделия до работы с рекламациями.</p>
        <?php echo View::factory('welcome/parts/enterprise_plan', ['phases' => $phases]); ?>
    </section>

    <section class="mt-8" aria-labelledby="portal-plan">
        <h2 id="portal-plan" class="prx-h3">План портала</h2>
        <p class="mt-3">Сервисы домена: одобренные, находящиеся в работе и запланированные. Реализованные сервисы показывают состояние из собственного манифеста.</p>
        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($plan as $domain): ?>
            <section class="prx-card">
                <h3 class="prx-h3"><?php echo HTML::chars($domain['title']); ?></h3>
                <ul class="mt-3 space-y-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <?php foreach ($domain['services'] as $service): ?>
                    <?php $badge = $badges[$service['state']] ?? $badges['planned']; ?>
                    <li>
                        <?php if ( ! empty($service['href'])): ?>
                        <a class="prx-link font-semibold" href="<?php echo HTML::chars($service['href']); ?>"><?php echo HTML::chars($service['title']); ?></a>
                        <?php else: ?>
                        <?php echo HTML::chars($service['title']); ?>
                        <?php endif; ?>
                        <span class="prx-badge <?php echo $badge[0]; ?> ml-2"><?php echo HTML::chars($service['label'] ?: $badge[1]); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($other): ?>
    <section class="mt-8" aria-labelledby="other-services">
        <h2 id="other-services" class="prx-h3">Сервисы вне отложенного плана</h2>
        <p class="mt-3">Реализовано вне доменного плана. «В работе» видно только здесь и в предпросмотре администратора, на /services такие сервисы не попадают.</p>
        <ul class="mt-4 grid gap-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300 sm:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($other as $service): ?>
            <?php $badge = $badges[$service['state']] ?? $badges['planned']; ?>
            <li class="prx-card flex items-center gap-2 py-3">
                <?php if ( ! empty($service['href'])): ?>
                <a class="prx-link min-w-0 truncate font-semibold" href="<?php echo HTML::chars($service['href']); ?>"><?php echo HTML::chars($service['title']); ?></a>
                <?php else: ?>
                <span class="min-w-0 truncate font-semibold"><?php echo HTML::chars($service['title']); ?></span>
                <?php endif; ?>
                <span class="prx-badge <?php echo $badge[0]; ?> ml-auto shrink-0"><?php echo HTML::chars($service['label'] ?: $badge[1]); ?></span>
                <span class="sr-only"><?php echo HTML::chars($service['group']); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
</div>
