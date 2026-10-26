<?php defined('SYSPATH') or die('No direct script access.');

$github_spotlight = (isset($github_spotlight) && is_array($github_spotlight)) ? $github_spotlight : NULL;
$airadar_update = (isset($airadar_update) && is_array($airadar_update)) ? $airadar_update : NULL;
$seo_description = isset($seo_description) ? (string) $seo_description : '';

$github_date = $github_spotlight ? date('d.m.Y', (int) $github_spotlight['finished_at']) : '';
$github_count = $github_spotlight ? (int) $github_spotlight['item_count'] : 0;
$count_10 = $github_count % 10;
$count_100 = $github_count % 100;
$github_count_word = ($count_10 === 1 AND $count_100 !== 11)
    ? 'репозиторий'
    : (($count_10 >= 2 AND $count_10 <= 4 AND ($count_100 < 12 OR $count_100 > 14))
        ? 'репозитория'
        : 'репозиториев');
?>
<script type="application/ld+json"><?php echo json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'WebSite',
    'name'        => 'N2J.ru',
    'url'         => URL::site('', TRUE),
    'inLanguage'  => 'ru-RU',
    'description' => $seo_description,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

<?php /* главная — эталон v6: плоский фон, карточки .n2j-card; анимация .nj-fade-up — в input.css */ ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
        <section class="grid gap-5 xl:grid-cols-[minmax(0,1.3fr)_minmax(18rem,0.75fr)] xl:items-stretch" aria-label="ИИ-сервисы для бизнеса и свежая подборка GitHub">
            <div class="nj-fade-up n2j-card sm:p-6 lg:p-7">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600 dark:text-blue-300">N2J · искусственный интеллект для бизнеса</p>
                <h1 id="home-title" class="n2j-h1 mt-2">
                    ИИ-сервисы для бизнеса
                </h1>
                <p class="mt-3 max-w-3xl text-sm leading-relaxed text-gray-600 dark:text-gray-300 sm:text-base">
                    Проверяйте контрагентов, изучайте компании, готовьте документы и находите узкие места в процессах с помощью ИИ-инструментов N2J для продаж и команд.
                </p>
                <nav class="mt-4 flex flex-wrap gap-2" aria-label="ИИ-инструменты для бизнеса">
                    <a class="n2j-badge n2j-badge-wait transition hover:bg-gray-200 dark:hover:bg-gray-600" href="/scout">Разведка контрагента</a>
                    <a class="n2j-badge n2j-badge-wait transition hover:bg-gray-200 dark:hover:bg-gray-600" href="/docs">Документы организации</a>
                    <a class="n2j-badge n2j-badge-wait transition hover:bg-gray-200 dark:hover:bg-gray-600" href="/process">Аудит бизнес-процессов</a>
                    <a class="n2j-badge n2j-badge-wait transition hover:bg-gray-200 dark:hover:bg-gray-600" href="/coldmail">Персонализация B2B-писем</a>
                </nav>

                <section class="mt-5 rounded-xl border border-blue-200/80 bg-blue-50/30 p-4 dark:border-blue-900/60 dark:bg-blue-950/20 sm:p-5" aria-labelledby="company-check-title">
                    <header class="mb-2 flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                        <h2 id="company-check-title" class="text-base font-bold text-gray-900 dark:text-white sm:text-lg">
                            <i class="fas fa-magnifying-glass-chart mr-2 text-blue-500" aria-hidden="true"></i>Проверка компании и работодателя
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">По ИНН, ОГРН, названию или ссылке</p>
                    </header>
                    <?php echo View::factory('welcome/parts/check_form'); ?>
                </section>
            </div>

            <aside class="nj-fade-up n2j-card sm:p-6" style="animation-delay:.12s" aria-labelledby="github-spotlight-title">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-lg text-blue-600 dark:bg-blue-950/70 dark:text-blue-300" aria-hidden="true">
                        <i class="fab fa-github"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-600 dark:text-blue-300">Актуальная подборка</p>
                        <h2 id="github-spotlight-title" class="mt-0.5 text-base font-bold text-gray-900 dark:text-white">Тренды GitHub</h2>
                    </div>
                </div>

                <?php if ($github_spotlight): ?>
                <?php $spotlight_title = trim((string) $github_spotlight['title']) !== '' ? $github_spotlight['title'] : $github_spotlight['name']; ?>
                <p class="mt-5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Проект из свежего обзора</p>
                <h3 class="mt-1 text-lg font-bold leading-snug text-gray-900 dark:text-white">
                    <a href="/trends/<?php echo HTML::chars($github_spotlight['code']); ?>" class="rounded-sm transition hover:text-blue-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:text-blue-300">
                        <?php echo HTML::chars($spotlight_title); ?>
                    </a>
                </h3>
                <?php if ($github_spotlight['name'] !== $spotlight_title): ?>
                <p class="mt-1 break-words font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($github_spotlight['name']); ?></p>
                <?php endif; ?>
                <?php if (trim((string) $github_spotlight['summary']) !== ''): ?>
                <p class="mt-3 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($github_spotlight['summary']); ?></p>
                <?php endif; ?>
                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <span><i class="fas fa-star mr-1 text-amber-500" aria-hidden="true"></i><?php echo number_format((int) $github_spotlight['stars'], 0, ',', ' '); ?> звёзд</span>
                    <span><?php echo $github_count; ?> <?php echo $github_count_word; ?></span>
                    <span class="ml-auto">Обновлено <?php echo HTML::chars($github_date); ?></span>
                </div>
                <a href="/trends/<?php echo HTML::chars($github_spotlight['code']); ?>" class="n2j-btn-process mt-4">
                    Читать обзор <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
                </a>
                <?php else: ?>
                <p class="mt-4 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    Свежие открытые репозитории GitHub — с коротким разбором того, что это за проект и чем он может быть полезен.
                </p>
                <a href="/services" class="n2j-btn-secondary mt-4">
                    Каталог сервисов <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
                </a>
                <?php endif; ?>

                <?php if ($airadar_update): ?>
                <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300" aria-hidden="true">
                            <i class="fas fa-satellite-dish"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">ИИ-радар</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Последний отчёт: <?php echo date('d.m.Y', (int) $airadar_update['created_at']); ?></p>
                        </div>
                        <a href="/airadar" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-blue-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-blue-300" aria-label="Открыть личный ИИ-радар" title="Открыть ИИ-радар">
                            <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </aside>
        </section>

        <?php if (isset($cards_html) && trim((string) $cards_html) !== ''): ?>
        <section id="services" class="mt-8 scroll-mt-20" aria-labelledby="featured-title">
            <div class="flex flex-col gap-3 border-b border-gray-200/80 pb-4 dark:border-gray-700/80 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="featured-title" class="n2j-h2">
                        ИИ и онлайн-инструменты N2J
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Решения для бизнеса, документы, данные и инструменты для повседневных задач.</p>
                </div>
                <a href="/services" class="n2j-btn-secondary self-start">
                    Все сервисы <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
                </a>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-[repeat(auto-fit,minmax(17rem,1fr))]">
                <?php echo $cards_html; ?>
            </div>
        </section>
        <?php endif; ?>
</div>
