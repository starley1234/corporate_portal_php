<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Путеводитель разработчика: автосборка — манифест, MCP, README, AGENTS
 * каждого модуля. Только admin.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/guide" class="transition hover:text-blue-600 dark:hover:text-blue-400">Путеводитель</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Разработчику</span>
    </nav>
    <h1 class="n2j-h1 mt-2"><i class="fas fa-code mr-2 text-blue-500"></i>Документация разработчика</h1>
    <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Автособирается из файлов модулей: манифесты nj110, MCP-инструменты, README.md, AGENTS.md. Модулей: <?php echo count($sections); ?>. Обновление — сразу после деплоя.</p>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-4 md:items-start md:space-y-0">

        <!-- ============ Оглавление (1/4, sticky на md запрещён — просто колонка) ============ -->
        <div class="min-w-0 space-y-4 md:col-span-1">
            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Модули</h3>
                <nav class="mt-2.5 space-y-1 text-xs">
                    <?php foreach ($sections as $s): ?>
                    <a href="#mod-<?php echo HTML::chars($s['name']); ?>" class="block truncate rounded-lg px-2 py-1.5 font-mono text-gray-600 transition hover:bg-gray-50 hover:text-blue-600 dark:text-gray-300 dark:hover:bg-gray-700/40 dark:hover:text-blue-400"><?php echo HTML::chars($s['name']); ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>

        <!-- ============ Секции модулей (3/4) ============ -->
        <div class="min-w-0 space-y-5 md:col-span-3">
            <?php foreach ($sections as $s): $m = $s['manifest']; ?>
            <section id="mod-<?php echo HTML::chars($s['name']); ?>" class="n2j-card p-0">
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h2 class="n2j-h3 font-mono"><?php echo HTML::chars($s['name']); ?></h2>
                    <?php if ($m): ?>
                    <span class="n2j-badge n2j-badge-ok uppercase"><?php echo HTML::chars((string) ($m['group'] ?? 'Платформа')); ?></span>
                    <?php if ( ! empty($m['href'])): ?><a href="<?php echo HTML::chars((string) $m['href']); ?>" class="font-mono text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $m['href']); ?></a><?php endif; ?>
                    <?php if ( ! empty($m['admin']['href'])): ?><a href="<?php echo HTML::chars((string) $m['admin']['href']); ?>" class="font-mono text-xs font-semibold text-gray-400 hover:underline"><?php echo HTML::chars((string) $m['admin']['href']); ?></a><?php endif; ?>
                    <?php else: ?>
                    <span class="n2j-badge n2j-badge-wait uppercase">платформенный</span>
                    <?php endif; ?>
                    <?php if ( ! empty($m['auth'])): ?><span class="n2j-badge n2j-badge-info uppercase" title="Требует входа">нужен вход</span><?php endif; ?>
                </div>

                <div class="px-4 py-4 sm:px-5">
                    <?php if ($m): ?>
                    <p class="text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) ($m['short'] ?? '')); ?></p>
                    <?php if ( ! empty($m['api'])): ?>
                    <p class="n2j-hint mt-2">API: <?php foreach ((array) $m['api'] as $i => $a) echo ($i ? ' · ' : '').'<span class="font-mono">'.HTML::chars((string) $a).'</span>'; ?></p>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if ( ! empty($s['mcp'])): ?>
                    <p class="n2j-hint mt-2">MCP: <?php foreach ($s['mcp'] as $i => $t) echo ($i ? ' · ' : '').'<span class="font-mono font-semibold text-blue-600 dark:text-blue-400">'.HTML::chars($t).'</span>'; ?></p>
                    <?php endif; ?>

                    <?php if ($s['readme'] !== ''): ?>
                    <details class="mt-3" open>
                        <summary class="cursor-pointer text-sm font-bold text-gray-900 dark:text-white">README.md</summary>
                        <div class="n2j-prose mt-2 text-sm text-gray-700 dark:text-gray-200"><?php echo $s['readme']; ?></div>
                    </details>
                    <?php endif; ?>

                    <?php if ($s['agents'] !== ''): ?>
                    <details class="mt-2">
                        <summary class="cursor-pointer text-sm font-bold text-gray-900 dark:text-white">AGENTS.md</summary>
                        <div class="n2j-prose mt-2 text-sm text-gray-700 dark:text-gray-200"><?php echo $s['agents']; ?></div>
                    </details>
                    <?php endif; ?>

                    <?php if ($s['readme'] === '' AND $s['agents'] === '' AND ! $m): ?>
                    <p class="n2j-hint">Документов нет — только код.</p>
                    <?php endif; ?>
                </div>
            </section>
            <?php endforeach; ?>
        </div>
    </div>
</div>

