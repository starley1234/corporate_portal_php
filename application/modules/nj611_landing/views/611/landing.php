<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /s/<slug> — лендинг группы: обещание, виджет быстрого старта (реальный
 * запуск сервиса), шаги, карточки модулей группы, цифры, FAQ.
 * SEO: хлебные крошки + JSON-LD (BreadcrumbList, FAQPage).
 * Минимум абстракций: виджеты интерактивны, тексты — про результат.
 */

$qs = ! empty($g['quickstart']) ? $g['quickstart'] : NULL;
$has_fields = ($qs !== NULL AND ! empty($qs['fields']));

// JSON-LD: FAQPage + BreadcrumbList
$jsonld = [];

if ( ! empty($g['faq']))
{
    $faq = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];

    foreach ($g['faq'] as $qa)
    {
        $faq['mainEntity'][] = [
            '@type'          => 'Question',
            'name'           => $qa[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
        ];
    }

    $jsonld[] = $faq;
}

$jsonld[] = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'N2J.ru', 'item' => '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Подборки сервисов', 'item' => '/s'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $g['title']],
    ],
];
?>
<?php foreach ($jsonld as $ld): ?>
<script type="application/ld+json"><?php echo json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<?php endforeach; ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <!-- Хлебные крошки -->
    <nav aria-label="Хлебные крошки" class="mb-4 text-xs text-gray-400 dark:text-gray-500">
        <a href="/" class="hover:text-blue-600">Главная</a>
        <span class="mx-1.5">/</span>
        <a href="/s" class="hover:text-blue-600">Подборки сервисов</a>
        <span class="mx-1.5">/</span>
        <span class="font-semibold text-gray-600 dark:text-gray-300"><?php echo HTML::chars($g['title']); ?></span>
    </nav>

    <?php if ( ! empty($other_groups)): ?>
    <!-- Другие подборки: в теле страницы переносятся, не растягивают шапку -->
    <nav aria-label="Другие подборки" class="mb-6 flex flex-wrap gap-1.5">
        <?php foreach ($other_groups as $og): ?>
            <a href="<?php echo HTML::chars($og['href']); ?>"
               class="n2j-chip<?php echo ! empty($og['active']) ? ' is-on' : ''; ?>">
                <i class="<?php echo HTML::chars($og['icon']); ?> text-xs"></i><?php echo HTML::chars($og['title']); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <!-- Обещание + быстрый старт -->
    <div class="grid gap-6 lg:grid-cols-5 lg:items-start">
        <div class="lg:col-span-3">
            <p class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3.5 py-1 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-700/10 dark:bg-blue-950/60 dark:text-blue-300">
                <i class="<?php echo HTML::chars($g['icon']); ?>"></i><?php echo HTML::chars($g['title']); ?>
            </p>
            <h1 class="n2j-h1 mt-4"><?php echo HTML::chars($g['h1']); ?></h1>
            <p class="mt-4 text-base leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($g['promise']); ?></p>

            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400">
                <span><i class="fas fa-circle-check mr-1.5 text-emerald-500"></i>Бесплатно в бете</span>
                <span><i class="fas fa-circle-check mr-1.5 text-emerald-500"></i>Работает в браузере — без установки</span>
                <span><i class="fas fa-circle-check mr-1.5 text-emerald-500"></i>Данные не продаём и не передаём</span>
            </div>

            <?php if ( ! empty($g['steps'])): ?>
            <ol class="mt-7 space-y-4">
                <?php foreach ($g['steps'] as $i => $step): ?>
                <li class="flex gap-3.5">
                    <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white shadow-sm"><?php echo $i + 1; ?></span>
                    <div>
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($step[0]); ?></p>
                        <p class="mt-0.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($step[1]); ?></p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>

            <?php if ( ! empty($g['stats'])): ?>
            <div class="mt-7 grid gap-3 sm:grid-cols-3">
                <?php foreach ($g['stats'] as $s): ?>
                <div class="n2j-card">
                    <p class="text-sm font-bold text-blue-600 dark:text-blue-400"><?php echo HTML::chars($s[0]); ?></p>
                    <p class="n2j-hint mt-0.5"><?php echo HTML::chars($s[1]); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($qs): ?>
        <!-- Быстрый старт: форма целевого сервиса (поля подхватываются на месте) или переход -->
        <div id="quickstart" class="n2j-card border-blue-200 ring-4 ring-blue-50 dark:border-blue-900/60 dark:ring-blue-950/40 lg:col-span-2">
            <p class="text-base font-bold text-gray-900 dark:text-white"><i class="fas fa-bolt mr-1.5 text-amber-500"></i><?php echo HTML::chars($qs['label'] ?? 'Попробуйте сейчас'); ?></p>
            <form method="get" action="<?php echo HTML::chars($qs['action'] ?? '/'); ?>" class="mt-3 space-y-3">
                <?php foreach ((array) ($qs['fields'] ?? []) as $f): ?>
                <div>
                    <label class="n2j-label"><?php echo HTML::chars($f['label'] ?? ''); ?><?php echo ! empty($f['required']) ? ' <span class="text-rose-500">*</span>' : ''; ?></label>
                    <input type="text" name="<?php echo HTML::chars($f['name']); ?>" maxlength="300" placeholder="<?php echo HTML::chars($f['placeholder'] ?? ''); ?>"
                           <?php echo ! empty($f['required']) ? 'required' : ''; ?>
                           class="n2j-input mt-1 w-full">
                </div>
                <?php endforeach; ?>
                <button type="submit" class="n2j-btn-process w-full">
                    <?php echo HTML::chars($qs['button'] ?? 'Начать'); ?> <i class="fas fa-arrow-right ml-1 text-xs"></i>
                </button>
            </form>
            <p class="n2j-hint mt-2.5"><?php echo HTML::chars($qs['note'] ?? ''); ?>.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Модули группы -->
    <h2 class="n2j-h3 mt-12">Что внутри подборки</h2>
    <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($g['modules'] as $m): ?>
        <a href="<?php echo HTML::chars($m['href']); ?>"
           class="n2j-card group flex flex-col transition-all hover:-translate-y-1 hover:border-blue-400 hover:shadow-sm dark:hover:border-blue-600">
            <div class="flex items-center gap-2.5">
                <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/80 dark:text-blue-400"><i class="<?php echo HTML::chars($m['icon']); ?>"></i></span>
                <span class="font-bold text-gray-900 group-hover:text-blue-600 dark:text-white"><?php echo HTML::chars($m['title']); ?></span>
            </div>
            <p class="mt-2 flex-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400"><?php echo HTML::chars($m['short']); ?></p>
            <span class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-blue-600 dark:text-blue-400">Открыть <i class="fas fa-arrow-right text-[10px] transition-transform group-hover:translate-x-0.5"></i></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- FAQ -->
    <?php if ( ! empty($g['faq'])): ?>
    <h2 class="n2j-h3 mt-12">Частые вопросы</h2>
    <div class="mt-3 space-y-2.5">
        <?php foreach ($g['faq'] as $qa): ?>
        <details class="n2j-card group">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-bold text-gray-900 dark:text-white">
                <?php echo HTML::chars($qa[0]); ?>
                <i class="fas fa-chevron-down shrink-0 text-xs text-gray-400 transition-transform group-open:rotate-180"></i>
            </summary>
            <p class="mt-2.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($qa[1]); ?></p>
        </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Финальный CTA -->
    <div class="mt-12 flex flex-col items-start gap-4 rounded-2xl border border-blue-100 bg-blue-50 p-6 sm:flex-row sm:items-center dark:border-blue-900/40 dark:bg-blue-950/30">
        <div class="flex-1">
            <p class="text-base font-bold text-gray-900 dark:text-white">Готовы попробовать?</p>
            <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300">Быстрый старт наверху — <?php if ($has_fields): ?>заполните форму и нажмите «<?php echo HTML::chars($qs['button'] ?? 'Начать'); ?>»<?php else: ?>кнопка «<?php echo HTML::chars($qs['button'] ?? 'Начать'); ?>» уже ждёт<?php endif; ?>. Аккаунт сохранит историю, наблюдения и уведомления.</p>
        </div>
        <a href="#quickstart" class="n2j-btn-process shrink-0">К быстрому старту</a>
    </div>

</div>
