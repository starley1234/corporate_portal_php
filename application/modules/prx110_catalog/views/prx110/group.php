<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * /services/<slug> — хаб группы сервисов (SSR, SEO-посадочная страница).
 */
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="mb-8 max-w-3xl">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            <a href="/services" class="hover:text-blue-600 dark:hover:text-blue-400"><i class="fas fa-arrow-left mr-1"></i>Все сервисы</a>
            <span class="mx-1.5">/</span><span class="text-gray-600 dark:text-gray-300"><?php echo HTML::chars($title); ?></span>
        </p>
        <h1 class="prx-h1"><?php echo HTML::chars($title); ?></h1>
        <p class="mt-3 text-base text-gray-600 dark:text-gray-300">
            <?php echo $group_cnt; ?> <?php echo $group_cnt === 1 ? 'сервис' : ($group_cnt < 5 ? 'сервиса' : 'сервисов'); ?> раздела.
            Открываются сразу в браузере — без установки и регистрации.
        </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($items as $m): ?>
        <div class="group/item relative">
        <?php // Звёздочка «в Избранное» — проявляется при наведении на карточку. ?>
        <button type="button"
                data-nav-pin-card="<?php echo HTML::chars($m['href']); ?>"
                data-nav-pin-title="<?php echo HTML::chars($m['title']); ?>"
                data-nav-pin-icon="<?php echo HTML::chars($m['icon']); ?>"
                class="nav-pin-card absolute right-3 top-3 z-10 rounded-lg bg-white/90 p-1.5 text-sm text-gray-300 opacity-0 shadow-xs transition hover:text-amber-500 focus-visible:opacity-100 group-hover/item:opacity-100 dark:bg-gray-900/90"
                title="В Избранное" aria-label="В Избранное"><i class="fas fa-star"></i></button>
        <a href="<?php echo HTML::chars($m['href']); ?>"
           class="prx-card group flex flex-col transition-all hover:-translate-y-0.5 hover:border-blue-400 hover:shadow-sm dark:hover:border-blue-600">
            <div class="flex items-center gap-3">
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
