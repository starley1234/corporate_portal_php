<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Отзыв 2 Ответ» (nj540_reply) — форма создания.
 * Работает и без JS: счётчик и выбор тона не обязательны.
 *
 * Гость вместо формы видит плашку входа (владелец, 08.10.2026): ответ пишет
 * LLM, а страницы готовых ответов по коду остаются доступными для ссылок.
 *
 * @var bool $logged вошёл ли человек
 */
$logged = ! empty($logged);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="gap-4 space-y-4 md:grid md:grid-cols-2 xl:grid-cols-4 md:items-start md:space-y-0">
<div class="min-w-0 md:col-span-2">

    <div class="n2j-card">
        <div class="flex items-center gap-3">
            <h1 class="n2j-h1">Отзыв 2 Ответ</h1>
            <span class="n2j-badge n2j-badge-info uppercase">ИИ</span>
        </div>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Вставьте отзыв клиента — получите готовый публичный ответ. Анализ тональности, выбранный тон, без шаблонного канцелярита.</p>

        <?php if ( ! empty($error)): ?>
            <div class="n2j-flash n2j-flash-err mt-4 block">
                <?php echo HTML::chars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ( ! $logged): ?>
        <?php echo Gate::wall('review', [
            'icon'   => 'fas fa-comment-dots',
            'title'  => 'Ответ на отзыв — по бесплатному аккаунту',
            'text'   => 'Ответ пишет LLM: это время модели и очередь задач. Аккаунт отделяет людей от ботов, а ваши ответы потом открываются с любого устройства — и ссылкой на готовый ответ можно поделиться.',
            'points' => [
                'Разбор отзыва: тональность, темы, нужна ли эскалация',
                'Готовый публичный ответ за 10–30 секунд — без канцелярита',
                'Пять тонов: дружелюбный, официальный, краткий, с извинением, премиальный',
                'История ответов в аккаунте: вернуться и перегенерировать в один клик',
            ],
            'stats'  => ['Beta, бесплатно', '6 запросов в час с одного адреса'],
            'cta'    => 'Войти и получить ответ',
            'note'   => 'Ссылка на результат остаётся рабочей и для тех, у кого нет аккаунта, — делитесь готовым ответом с коллегами.',
        ]); ?>
        <?php else: ?>

        <form method="post" action="/review" class="mt-5 space-y-5">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">

            <div>
                <label for="nj540-review" class="n2j-label">
                    Отзыв клиента <span class="font-normal text-gray-400">— от <?php echo (int) $min_len; ?> до <?php echo (int) $max_len; ?> символов</span>
                </label>
                <textarea id="nj540-review" name="review" rows="6" required minlength="<?php echo (int) $min_len; ?>" maxlength="<?php echo (int) $max_len; ?>"
                          placeholder="Заказ шел три недели, упаковка помята, но сама вещь нравится. Поддержка отвечала медленно…"
                          class="n2j-textarea min-h-[44px] mt-2 w-full"><?php echo HTML::chars($review_val); ?></textarea>
                <p id="nj540-counter" class="n2j-hint text-right"></p>
            </div>

            <div>
                <label for="nj540-shop" class="n2j-label">
                    Магазин или бренд <span class="font-normal text-gray-400">— необязательно</span>
                </label>
                <input id="nj540-shop" name="shop" type="text" maxlength="120"
                       value="<?php echo HTML::chars($shop_val); ?>"
                       placeholder="Например, «Мой Магазин»"
                       class="n2j-input mt-2 w-full">
            </div>

            <div>
                <span class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Тон ответа</span>
                <div class="mt-2 flex flex-wrap gap-2">
                    <?php foreach ($tones as $key => $t): ?>
                        <label>
                            <input type="radio" name="tone" value="<?php echo HTML::chars($key); ?>" class="min-h-[44px] peer sr-only"<?php if ($key === $tone_val): ?> checked<?php endif; ?>>
                            <span class="inline-block cursor-pointer rounded-full border border-gray-300 px-4 py-2 text-sm text-gray-600 transition peer-checked:border-blue-500 peer-checked:bg-blue-500 peer-checked:text-white hover:border-blue-400 dark:border-gray-600 dark:text-gray-300 dark:peer-checked:bg-blue-600"><?php echo HTML::chars($t['label']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button id="nj540-submit" type="submit"
                    class="n2j-btn-process">
                <i class="fas fa-wand-magic-sparkles mr-1"></i>Получить ответ
            </button>
        </form>
        <?php endif; ?>
    </div>


</div>

<div class="hidden min-w-0 space-y-4 md:block md:col-span-1">
    <!-- Как это работает -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Как это работает</h2>
        <ol class="mt-3 list-inside list-decimal space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <li>Вставляете отзыв — задача уходит в очередь фоновых воркеров, страница сама покажет готовый ответ.</li>
            <li>ИИ разбирает отзыв: тональность, темы, нужна ли эскалация (угрозы, возвраты, юристы).</li>
            <li>Готовый ответ можно скопировать или перегенерировать другим тоном. Ссылкой на результат можно поделиться.</li>
        </ol>
        <p class="n2j-hint mt-3">Обычно ответ готов за 10–30 секунд. Лимит — 6 запросов в час с одного адреса.</p>
    </div>
</div>
</div>
</div>


<?php if ($logged): ?>
<?php echo View::factory('540/script', ['code' => NULL, 'min_len' => $min_len, 'max_len' => $max_len]); ?>
<?php endif; ?>
