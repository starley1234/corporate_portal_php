<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Путеводитель: презентация сервиса (шаги, советы, Mermaid-схема).
 */
$title = (string) ($p['title'] ?? ($mod['title'] ?? $slug));
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="n2j-card">
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/guide" class="transition hover:text-blue-600 dark:hover:text-blue-400">Путеводитель</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($title); ?></span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-4 dark:border-gray-700/60">
            <div class="flex min-w-0 items-start gap-3">
                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300"><i class="<?php echo HTML::chars((string) ($mod['icon'] ?? 'fas fa-circle-info')); ?> text-lg"></i></span>
                <div class="min-w-0">
                    <h1 class="n2j-h1"><?php echo HTML::chars($title); ?></h1>
                    <p class="n2j-hint mt-0.5"><?php echo HTML::chars((string) ($p['tagline'] ?? ($mod['short'] ?? ''))); ?></p>
                </div>
            </div>
            <?php if ( ! empty($mod['href'])): ?>
            <a href="<?php echo HTML::chars((string) $mod['href']); ?>" class="n2j-btn-process shrink-0">
                <i class="fas fa-arrow-up-right-from-square text-xs"></i>Открыть сервис
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Презентация (2/3) ============ -->
        <div class="min-w-0 space-y-4 md:col-span-2">

            <div class="n2j-card">
                <h2 class="n2j-h3 border-b border-gray-100 pb-3"><i class="fas fa-circle-info mr-1.5 text-blue-500"></i>Что это и зачем</h2>
                <p class="mt-3 text-sm text-gray-700 sm:text-base dark:text-gray-200"><?php echo HTML::chars((string) ($p['about'] ?? ($mod['short'] ?? ''))); ?></p>
            </div>

            <?php if ( ! empty($p['steps'])): ?>
            <div class="n2j-card">
                <h2 class="n2j-h3 border-b border-gray-100 pb-3"><i class="fas fa-list-ol mr-1.5 text-blue-500"></i>Как пользоваться</h2>
                <ol class="mt-3 space-y-3">
                    <?php $i = 0; foreach ((array) $p['steps'] as $step): $i++; ?>
                    <li class="flex items-start gap-3">
                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-blue-600 font-mono text-xs font-bold text-white"><?php echo $i; ?></span>
                        <span class="text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $step); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <?php endif; ?>

            <?php if ( ! empty($p['mermaid'])): ?>
            <div class="n2j-card">
                <h2 class="n2j-h3 border-b border-gray-100 pb-3"><i class="fas fa-diagram-project mr-1.5 text-blue-500"></i>Схема работы</h2>
                <div class="mermaid mt-4 overflow-x-auto text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $p['mermaid']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ============ Компаньон (1/3) ============ -->
        <div class="hidden space-y-5 md:block">
            <?php if ( ! empty($p['tips'])): ?>
            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">Советы</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ((array) $p['tips'] as $tip): ?>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><?php echo HTML::chars((string) $tip); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Другие сервисы</h3>
                <ul class="mt-2.5 space-y-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                    <?php $n = 0; foreach ($others as $o): if ($o['slug'] === $slug OR $n >= 6) continue; $n++; ?>
                    <li><a href="/guide/<?php echo HTML::chars($o['slug']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $o['title']); ?></a><span class="block truncate text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $o['short']); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('596/script'); ?>
