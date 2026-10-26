<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /news — лента новостей сайта.
 */
$dt = function ($v) {
    $ts = strtotime((string) $v);

    return $ts ? HTML::chars(Date::formatted_time('@'.$ts, 'd.m.Y в H:i')) : '';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Новости сайта</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-bullhorn mr-2 text-blue-500"></i>Новости сайта
        </h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-arrows-rotate text-[11px]"></i>автообновление
        </span>
        <a href="/news/rss.xml" class="n2j-btn-secondary n2j-btn-sm" title="RSS-лента новостей">
            <i class="fas fa-rss text-xs"></i>RSS
        </a>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">
        Что нового на N2J.ru: обновления платформы и новые инструменты. Релизы добавляются автоматически
        из истории разработки, важные фичи — отдельными заметками.
    </p>

    <?php if (empty($items)): ?>
    <div class="n2j-card n2j-empty mt-6">
        <div class="n2j-empty-icon"><i class="fas fa-envelope-open-text" aria-hidden="true"></i></div>
        <h2 class="n2j-h3 mt-4">Новостей пока нет</h2>
        <p class="n2j-empty-text">Релизы появятся после следующего обновления платформы.</p>
    </div>
    <?php else: ?>
    <div class="mt-6 space-y-4">
        <?php foreach ($items as $n): ?>
        <article class="n2j-card">
            <div class="flex flex-wrap items-center gap-2.5">
                <?php if ($n['kind'] === 'release'): ?>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs"><i class="fas fa-arrows-rotate text-[10px]"></i>релиз</span>
                <?php elseif ($n['kind'] === 'feature'): ?>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white shadow-xs"><i class="fas fa-wand-magic-sparkles text-[10px]"></i>новая возможность</span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white shadow-xs"><i class="fas fa-feather-pointed text-[10px]"></i>заметка</span>
                <?php endif; ?>
                <h2 class="n2j-h3"><?php echo HTML::chars($n['title']); ?></h2>
                <?php if ((string) $n['tag'] !== '' AND $n['tag'] !== 'новая возможность'): ?><span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars($n['tag']); ?></span><?php endif; ?>
                <span class="ml-auto text-xs text-gray-400"><?php echo $dt($n['published_at'] ?: $n['created_at']); ?></span>
            </div>

            <div class="mt-2.5 space-y-1 text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                <?php foreach (preg_split('~\r?\n~', (string) $n['body_md']) as $line): $t = trim($line); if ($t === '') continue; ?>
                <?php if ($n['kind'] === 'release') { $t = Service_Nj604_News::prettify_line($t); } ?>
                <p class="<?php echo $t[0] === '-' ? 'flex items-start gap-2' : ''; ?>">
                    <?php if ($t[0] === '-'): ?>
                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-500"></span><span><?php echo HTML::chars(mb_substr($t, 1)); ?></span>
                    <?php else: ?>
                    <?php echo HTML::chars($t); ?>
                    <?php endif; ?>
                </p>
                <?php endforeach; ?>
            </div>

            <?php if ((string) $n['href'] !== ''): ?>
            <a href="<?php echo HTML::chars($n['href']); ?>" class="n2j-btn-secondary n2j-btn-sm mt-3">Посмотреть<i class="fas fa-arrow-right text-[10px]"></i></a>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
