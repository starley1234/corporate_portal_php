<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Отзыв 2 Ответ» (nj540_reply) — статус и результат.
 *
 * $row — строка nj_540_replies; $analysis — разбор из LangChain-цепи; $meta — model/engine/history.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="gap-4 space-y-4 md:grid md:grid-cols-2 xl:grid-cols-4 md:items-start md:space-y-0">
<div class="min-w-0 md:col-span-2">

    <div class="n2j-card">
        <div class="flex items-center gap-3">
            <h1 class="n2j-h1">Ответ на отзыв</h1>
            <span class="n2j-badge n2j-badge-info uppercase">ИИ</span>
            <?php if ( ! empty($just_created)): ?>
                <span class="n2j-badge n2j-badge-ok ml-auto uppercase">принято</span>
            <?php endif; ?>
        </div>

        <?php if ( ! empty($regen_error)): ?>
            <div class="n2j-flash n2j-flash-warn mt-4 block">
                <?php echo HTML::chars($regen_error); ?>
            </div>
        <?php endif; ?>

        <!-- Исходный отзыв -->
        <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Отзыв клиента<?php if ( ! empty($row['shop_name'])): ?> — <?php echo HTML::chars($row['shop_name']); ?><?php endif; ?></p>
            <p class="mt-1 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300"><?php echo HTML::chars($row['review_text']); ?></p>
        </div>

        <?php if ($row['status'] === 'new'): ?>
            <?php
            /* Живой статус-ряд — общий механизм (partials/live_status): этапы
             * воркера, секундомер, «нет пульса», авто-стоп и перезагрузка на
             * финале. Раньше здесь был свой спиннер и свой поллинг: при
             * застрявшем воркере страница вечно писала «ИИ пишет ответ…». */
            echo View::factory('partials/live_status', [
                'url'   => '/nj540/api/status/'.urlencode((string) $row['code']),
                'title' => 'ИИ пишет ответ',
                'note'  => 'Обычно 10–30 секунд. Страница обновится сама.',
            ]);
            ?>
            <p class="n2j-hint mt-1.5">
                <a id="nj540-refresh" href="/review/<?php echo HTML::chars($row['code']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Обновить сейчас</a>
            </p>

        <?php elseif ($row['status'] === 'error'): ?>
            <!-- Ошибка -->
            <div class="n2j-flash n2j-flash-err mt-5 block">
                <p class="font-semibold">Не получилось подготовить ответ</p>
                <p class="mt-1 text-xs opacity-80"><?php echo HTML::chars($row['error'] ?: 'Ошибка воркера'); ?></p>
                <?php echo View::factory('540/regen', ['row' => $row, 'csrf' => $csrf, 'label' => 'Попробовать снова']); ?>
            </div>

        <?php else: ?>
            <!-- Разбор отзыва -->
            <?php if ( ! empty($analysis)): ?>
                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <?php
                    $sentiment = isset($analysis['sentiment']) ? (string) $analysis['sentiment'] : '';
                    $sent_view = [
                        'positive' => ['bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/60 dark:text-emerald-400', 'позитив'],
                        'negative' => ['bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-950/60 dark:text-rose-400', 'негатив'],
                        'mixed'    => ['bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-950/60 dark:text-amber-400', 'смешанный'],
                        'neutral'  => ['bg-gray-100 text-gray-600 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-300', 'нейтральный'],
                    ];
                    if (isset($sent_view[$sentiment])):
                        ?>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?php echo $sent_view[$sentiment][0]; ?>"><?php echo $sent_view[$sentiment][1]; ?></span>
                    <?php endif;
                    foreach ((array) ($analysis['topics'] ?? []) as $topic): ?>
                        <span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars((string) $topic); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if ( ! empty($analysis['escalation'])): ?>
                    <div class="n2j-flash n2j-flash-warn mt-3 block">
                        <i class="fas fa-triangle-exclamation mr-1"></i>Похоже, отзыв требует эскалации — стоит ответить не только публично, но и лично.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Ответ -->
            <div class="n2j-card mt-4 border-blue-200 dark:border-blue-800">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-500 dark:text-blue-400">Готовый ответ</p>
                    <button id="nj540-copy" type="button" data-text="<?php echo HTML::chars($row['reply_text']); ?>"
                            class="n2j-btn-secondary n2j-btn-sm">
                        Скопировать
                    </button>
                </div>
                <p id="nj540-reply" class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-gray-800 dark:text-gray-200"><?php echo HTML::chars($row['reply_text']); ?></p>
                <?php if ( ! empty($meta['model'])): ?>
                    <p class="n2j-hint mt-3">Модель: <?php echo HTML::chars((string) $meta['model']); ?><?php echo empty($meta['engine']) ? '' : ' · движок: '.HTML::chars((string) $meta['engine']); ?></p>
                <?php endif; ?>
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <?php echo View::factory('540/regen', ['row' => $row, 'csrf' => $csrf, 'label' => 'Переписать ответ']); ?>
                <a href="/review" class="text-sm font-semibold text-blue-600 hover:text-blue-500 dark:text-blue-400">Новый отзыв →</a>
                <?php if ( ! empty($meta['history'])): ?>
                    <span class="text-xs text-gray-400 dark:text-gray-500">прошлых вариантов: <?php echo count($meta['history']); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p class="n2j-hint mt-4">Ссылка на эту страницу: <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-gray-900">/review/<?php echo HTML::chars($row['code']); ?></code> — можно поделиться.</p>
    </div>
</div>
<div class="hidden min-w-0 space-y-4 md:block md:col-span-1">
<div class="rounded-xl border border-blue-200 bg-blue-50/70 p-4 shadow-xs dark:border-blue-800 dark:bg-blue-950/30">
    <p class="text-sm font-bold text-gray-900 dark:text-white">Ответ на отзыв за 30 секунд</p>
    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Review 2 Reply: вставьте отзыв клиента — ИИ определит тональность и подготовит публичный ответ нужным тоном.</p>
    <a href="/review" class="n2j-btn-process n2j-btn-sm mt-2">Ответить на отзыв</a>
</div>
</div>
</div>
</div>


<?php echo View::factory('partials/live_status_js'); ?>
<?php echo View::factory('540/script', ['min_len' => 0, 'max_len' => Service_Nj540_Reply::limits()['max']]); ?>
