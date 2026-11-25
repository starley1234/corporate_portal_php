<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * /services/<slug> — хаб группы сервисов (SSR, SEO-посадочная страница).
 */
$nav_favorite_hrefs = array_fill_keys(array_column((array) ($nav_favorites['items'] ?? []), 'href'), TRUE);
$nav_favorites_ready = ($nav_favorites['status'] ?? '') === 'ready';
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Все сервисы', '/prx110_catalog'], [HTML::chars($title)]],
        'title'  => $title,
        'pill'   => ['prx110 · каталог сервисов', 'fa-bars-progress'],
        'lead'   => $group_cnt.' '.($group_cnt === 1 ? 'сервис' : ($group_cnt < 5 ? 'сервиса' : 'сервисов')).' раздела. Показаны сервисы, доступные вашей роли.',
    ]); ?>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($items as $m): ?>
        <div class="group/item relative">
        <?php // Кнопка видна без наведения; состояние приходит вместе со страницей. ?>
        <?php $nav_is_pinned = isset($nav_favorite_hrefs[$m['href']]);
              $nav_pin_label = ! $nav_favorites_ready ? 'Избранное недоступно' : ($nav_is_pinned ? 'Убрать из Избранного' : 'В Избранное'); ?>
        <button type="button"
                data-nav-pin-card="<?php echo HTML::chars($m['href']); ?>"
                data-nav-pin-title="<?php echo HTML::chars($m['title']); ?>"
                data-nav-pin-icon="<?php echo HTML::chars($m['icon']); ?>"
                class="nav-pin-card absolute right-3 top-3 z-10 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-white/90 text-sm <?php echo $nav_is_pinned ? 'text-amber-500' : 'text-gray-400'; ?> shadow-xs transition hover:text-amber-500 dark:bg-gray-900/90"
                title="<?php echo HTML::chars($nav_pin_label); ?>" aria-label="<?php echo HTML::chars($nav_pin_label.': '.$m['title']); ?>"
                aria-pressed="<?php echo $nav_is_pinned ? 'true' : 'false'; ?>"<?php echo $nav_favorites_ready ? '' : ' disabled'; ?>><i class="fas fa-star"></i></button>
        <a href="<?php echo HTML::chars($m['href']); ?>"
           class="prx-card group flex flex-col transition-all hover:-translate-y-0.5 hover:border-blue-400 hover:shadow-sm dark:hover:border-blue-600">
            <div class="flex items-center gap-3 pr-10">
                <span class="rounded-lg bg-blue-50 p-2.5 text-sm font-bold text-blue-600 dark:bg-blue-950/80 dark:text-blue-400"><i class="<?php echo HTML::chars($m['icon']); ?>"></i></span>
                <div class="min-w-0">
                    <h2 class="prx-h3 truncate"><?php echo HTML::chars($m['title']); ?></h2>
                    <?php if ( ! empty($m['badge'])): ?>
                    <span class="prx-count mt-0.5 uppercase"><?php echo HTML::chars((string) $m['badge']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ( ! empty($m['short'])): ?>
            <p class="mt-3 line-clamp-3 flex-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400"><?php echo HTML::chars($m['short']); ?></p>
            <?php endif; ?>
            <span class="mt-4 inline-flex items-center gap-1 text-sm font-bold text-blue-600 dark:text-blue-400">Открыть <i class="fas fa-arrow-right text-xs transition-transform group-hover:translate-x-0.5"></i></span>
        </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
