<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Быстрый опрос» (nj520_poll) — форма создания.
 * Одна textarea: каждый вариант на своей строке (работает без JS).
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="gap-4 space-y-4 md:grid md:grid-cols-2 xl:grid-cols-4 md:items-start md:space-y-0">
    <div class="n2j-card md:col-span-2">
        <h1 class="n2j-h1">Быстрый опрос</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Создайте опрос за 10 секунд — и поделитесь ссылкой. Голосование без регистрации, создание — по аккаунту.</p>

        <?php if ( ! empty($error)): ?>
            <div class="n2j-flash n2j-flash-err mt-4 block">
                <?php echo HTML::chars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ( ! empty($guest)): ?>
            <div class="mt-5 rounded-xl border border-blue-200 bg-blue-50 px-4 py-5 text-center dark:border-blue-800 dark:bg-blue-900/30">
                <i class="fas fa-user-plus text-xl text-blue-600 dark:text-blue-300" aria-hidden="true"></i>
                <p class="mt-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Создание опроса — после быстрого входа</p>
                <p class="n2j-hint mx-auto max-w-md">Аккаунт бесплатный и нужен, чтобы опросы не плодил кто попало. Голосовать по ссылке можно без регистрации — участникам ничего не нужно.</p>
                <a href="/auth/login?return_url=poll" class="n2j-btn-process mt-3">Войти или зарегистрироваться</a>
            </div>
        <?php else: ?>
        <form method="post" action="/poll" class="mt-5 space-y-5">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">

            <div>
                <label for="poll-title" class="n2j-label">Вопрос</label>
                <input id="poll-title" name="title" type="text" maxlength="300" required
                       value="<?php echo HTML::chars($title_val); ?>"
                       placeholder="Куда идём в пятницу?"
                       class="n2j-input mt-2 w-full font-semibold">
            </div>

            <div>
                <label for="poll-options" class="n2j-label">
                    Варианты <span class="font-normal text-gray-400">— по одному в строке, от 2 до 10</span>
                </label>
                <textarea id="poll-options" name="options" rows="5" required
                          placeholder="Бар&#10;Кино&#10;Дома"
                          class="n2j-textarea min-h-[44px] mt-2 w-full"><?php echo HTML::chars($options_val); ?></textarea>
            </div>

            <button type="submit"
                    class="n2j-btn-cta">
                <i class="fas fa-chart-simple"></i>Создать опрос
            </button>
        </form>
        <?php endif; ?>
    </div>


    <!-- Как это работает -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Как это работает</h2>
        <ol class="mt-3 list-inside list-decimal space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <li>Задайте вопрос и варианты — получите короткую ссылку <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-gray-900">/p/код</code>.</li>
            <li>Отправьте ссылку в чат. Каждый голосует один раз — повторный голос защита не пустит (один голос с одного браузера, общий IP офиса не мешает).</li>
            <li>Результаты открываются участнику сразу после его голоса — чтобы чужой выбор не влиял на его собственный. Дальше числа и полоски обновляются сами.</li>
        </ol>
    </div>
<!-- Последние активные опросы -->
    <div class="n2j-card">
        <h2 class="n2j-h3"><i class="fas fa-chart-simple mr-1.5 text-blue-500"></i>Последние опросы</h2>
        <?php $recent = isset($recent) ? (array) $recent : []; ?>
        <?php if (empty($recent)): ?>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Пока нет активных опросов. Создайте первый — он появится здесь.</p>
        <?php else: ?>
        <ul class="mt-3 space-y-2.5">
            <?php foreach ($recent as $r): ?>
            <li>
                <a href="/p/<?php echo HTML::chars($r['code']); ?>" class="group block rounded-lg px-2 py-1.5 transition hover:bg-gray-50 dark:hover:bg-gray-700/60">
                    <span class="block truncate text-sm font-medium text-gray-700 group-hover:text-blue-600 dark:text-gray-200 dark:group-hover:text-blue-400" title="<?php echo HTML::chars($r['title']); ?>"><?php echo HTML::chars($r['title']); ?></span>
                    <span class="text-xs text-gray-400 dark:text-gray-500"><?php echo (int) $r['options_cnt'].' '.Service_Nj520_Poll::options_word($r['options_cnt']); ?> · <?php echo (int) $r['votes'].' '.Service_Nj520_Poll::votes_word($r['votes']); ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="n2j-hint mt-3 border-t border-gray-100 pt-3">Только действующие — закрытые опросы не показываются.</p>
        <?php endif; ?>
    </div>
</div>
</div>
