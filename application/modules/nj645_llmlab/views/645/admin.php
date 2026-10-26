<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Админка «Лаборатории LLM»: модерация корпуса, чистка и обслуживание знаний.
 * Все действия — POST + CSRF (правило платформы: GET ничего не меняет).
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
        <div class="n2j-flash n2j-flash-info mb-5 block">
            <i class="fas fa-circle-info mr-1"></i><?php echo HTML::chars($flash); ?>
        </div>
    <?php endif; ?>

    <h1 class="n2j-h1">Лаборатория LLM · модерация</h1>
    <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
        Одобренные тексты попадают в n-граммную модель и словарь мини-BPE; отклонённые не влияют ни на что.
        Промпты можно скрыть из библиотеки. Пересборка модели нужна после массовых правок.
    </p>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $cards = [
            ['Одобрено текстов', $stats['corpus_ok'], 'посев + модерация'],
            ['На модерации', $stats['corpus_pending'], 'ждут решения'],
            ['N-грамм', $stats['ngrams'], 'связок контекст → токен'],
            ['Точек на карте', $stats['vectors'], 'семантическая карта'],
        ];
        foreach ($cards as $card): ?>
            <div class="n2j-card">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo $card[0]; ?></div>
                <div class="mt-1 text-xl font-bold text-gray-900 dark:text-white"><?php echo number_format((float) $card[1], 0, ',', ' '); ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo $card[2]; ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <form method="post" action="/nj645/admin/rebuild">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <button type="submit" class="n2j-btn-secondary">
                <i class="fas fa-rotate mr-1"></i>Пересобрать n-граммы
            </button>
        </form>
        <form method="post" action="/nj645/admin/trim_vectors">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <button type="submit" class="n2j-btn-secondary">
                <i class="fas fa-scissors mr-1"></i>Обрезать карту до свежих точек
            </button>
        </form>
    </div>

    <div class="n2j-card mt-6">
        <h2 class="n2j-h3">Тексты на модерации</h2>
        <?php if ( ! $pending): ?>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Очередь пуста.</p>
        <?php else: ?>
            <div class="mt-3 space-y-3">
                <?php foreach ($pending as $item): ?>
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($item['title'] !== '' ? $item['title'] : 'Без названия'); ?></span>
                            <span class="text-xs text-gray-400"><?php echo HTML::chars($item['kind']); ?> · <?php echo HTML::chars($item['lang']); ?> · <?php echo (int) $item['chars']; ?> зн.</span>
                        </div>
                        <div class="mt-2 flex gap-2">
                            <form method="post" action="/nj645/admin/moderate/<?php echo (int) $item['id']; ?>">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="status" value="approved">
                                <button type="submit" class="n2j-btn-cta n2j-btn-sm">Одобрить</button>
                            </form>
                            <form method="post" action="/nj645/admin/moderate/<?php echo (int) $item['id']; ?>">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="status" value="rejected">
                                <button type="submit" class="n2j-btn-danger n2j-btn-sm">Отклонить</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="n2j-card">
            <h2 class="n2j-h3">Промпты библиотеки</h2>
            <div class="mt-3 max-h-96 space-y-2 overflow-auto pr-1">
                <?php foreach ($prompts as $prompt): ?>
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-100 px-3 py-2 text-sm dark:border-gray-700">
                        <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-200"><?php echo HTML::chars($prompt['title']); ?></span>
                        <span class="text-xs text-gray-400">👍 <?php echo (int) $prompt['votes_up']; ?> / 👎 <?php echo (int) $prompt['votes_down']; ?> · <?php echo HTML::chars($prompt['status']); ?></span>
                        <form method="post" action="/nj645/admin/prompt_status/<?php echo (int) $prompt['id']; ?>">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="status" value="<?php echo $prompt['status'] === 'published' ? 'hidden' : 'published'; ?>">
                            <button type="submit" class="n2j-btn-secondary n2j-btn-sm">
                                <?php echo $prompt['status'] === 'published' ? 'Скрыть' : 'Опубликовать'; ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="n2j-card">
            <h2 class="n2j-h3">Что путают и где удивляются</h2>
            <?php if ($confusions): ?>
                <ul class="mt-3 space-y-1.5 text-sm text-gray-700 dark:text-gray-200">
                    <?php foreach ($confusions as $c): ?>
                        <li>«<?php echo HTML::chars($c['term']); ?>» → выбирают «<?php echo HTML::chars($c['chosen']); ?>» (<?php echo (int) $c['count']; ?>×)</li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Статистики квиза пока нет.</p>
            <?php endif; ?>

            <?php if ($hardest): ?>
                <h3 class="mt-4 text-sm font-semibold text-gray-700 dark:text-gray-300">Люди против модели</h3>
                <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($hardest as $h): ?>
                        <li>«<?php echo HTML::chars(mb_substr($h['context'], 0, 50)); ?>…» — модель «<?php echo HTML::chars($h['model_token']); ?>», промахов <?php echo (int) $h['tries'] - (int) $h['hits']; ?> из <?php echo (int) $h['tries']; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($approved OR $rejected): ?>
        <div class="n2j-card mt-4">
            <h2 class="n2j-h3">Последние решения</h2>
            <div class="mt-3 grid gap-2 text-xs text-gray-500 md:grid-cols-2 dark:text-gray-400">
                <div>
                    <div class="font-semibold text-gray-700 dark:text-gray-200">Одобрены</div>
                    <?php foreach ($approved as $item): ?>
                        <div class="truncate"><?php echo HTML::chars($item['title'] !== '' ? $item['title'] : 'Без названия'); ?> · <?php echo (int) $item['chars']; ?> зн.</div>
                    <?php endforeach; ?>
                </div>
                <div>
                    <div class="font-semibold text-gray-700 dark:text-gray-200">Отклонены</div>
                    <?php foreach ($rejected as $item): ?>
                        <div class="truncate"><?php echo HTML::chars($item['title'] !== '' ? $item['title'] : 'Без названия'); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
