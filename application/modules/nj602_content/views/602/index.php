<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /content — лента опубликованных статей журнала N2J.
 */
$dt = function ($v) {
    return $v ? HTML::chars(Date::formatted_time((string) $v, 'd.m.Y')) : '';
};
$tags = function ($r) {
    $t = json_decode((string) ($r['tags'] ?? '[]'), TRUE) ?: [];

    return array_slice(array_map('strval', $t), 0, 4);
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Журнал</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-newspaper mr-2 text-blue-500"></i>Журнал N2J
        </h1>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">
        Разбираемся, что нового в технологиях для малого бизнеса: живым языком, со схемами
        и ссылками на источники. Каждый материал проходит редакторскую проверку перед публикацией.
    </p>

    <?php if (empty($items)): ?>
    <div class="n2j-card n2j-empty mt-6">
        <div class="n2j-empty-icon"><i class="fas fa-feather-pointed" aria-hidden="true"></i></div>
        <h2 class="n2j-h3 mt-4">Пока ни одной опубликованной статьи</h2>
        <p class="n2j-empty-text">Новые статьи выходят несколько раз в неделю.</p>
    </div>
    <?php else: ?>
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($items as $a): ?>
        <a href="/content/<?php echo HTML::chars($a['slug']); ?>" class="n2j-card flex flex-col justify-between transition-shadow hover:shadow-sm">
            <div>
                <div class="flex items-center justify-between gap-2">
                    <span class="n2j-badge n2j-badge-ok">
                        <span class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>опубликовано
                    </span>
                    <span class="text-xs text-gray-400"><?php echo $dt($a['published_at']); ?></span>
                </div>
                <h2 class="n2j-h3 mt-3"><?php echo HTML::chars($a['title']); ?></h2>
                <p class="n2j-hint line-clamp-3"><?php echo HTML::chars($a['summary']); ?></p>
                <div class="mt-4 flex flex-wrap gap-1.5">
                    <?php foreach ($tags($a) as $tg): ?>
                    <span class="n2j-badge n2j-badge-info"><?php echo HTML::chars($tg); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mt-5 flex items-center justify-between border-t border-gray-100 pt-3 text-xs dark:border-gray-700/60">
                <span class="font-mono text-gray-400"><?php echo HTML::chars($a['code']); ?></span>
                <span class="inline-flex min-h-[36px] items-center rounded-lg bg-blue-50 px-3 text-xs font-semibold text-blue-700 transition hover:bg-blue-100 dark:bg-blue-950/50 dark:text-blue-300">Читать<i class="fas fa-arrow-right ml-1.5 text-[10px]"></i></span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php echo View::factory('602/script'); ?>
