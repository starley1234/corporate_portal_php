<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Need 2 Jump» (nj530_jump) — форма сокращения + результат/статистика.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="gap-4 space-y-4 md:grid md:grid-cols-4 md:items-start md:space-y-0">
<div class="space-y-4 md:col-span-3">
    <!-- ============ Форма ============ -->
    <div class="n2j-card">
        <h1 class="n2j-h1">Сократить ссылку</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Длинный URL → короткий <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-gray-900">/x/код</code> со счётчиком переходов. Создание — по аккаунту, переходы открыты всем.</p>

        <?php if ( ! empty($error)): ?>
            <div class="n2j-flash n2j-flash-err mt-4 block">
                <?php echo HTML::chars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ( ! empty($guest)): ?>
            <div class="mt-5 rounded-xl border border-blue-200 bg-blue-50 px-4 py-5 text-center dark:border-blue-800 dark:bg-blue-900/30">
                <i class="fas fa-user-plus text-xl text-blue-600 dark:text-blue-300" aria-hidden="true"></i>
                <p class="mt-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Сокращение ссылок — после быстрого входа</p>
                <p class="n2j-hint mx-auto max-w-md">Аккаунт нужен, чтобы короткий адрес домена не раздавали под чужие рассылки. Существующие ссылки и статистика работают без входа.</p>
                <a href="/auth/login?return_url=jump" class="n2j-btn-process mt-3">Войти или зарегистрироваться</a>
            </div>
        <?php else: ?>
        <form method="post" action="/jump" class="mt-5">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <label for="jump-url" class="n2j-label">Длинная ссылка</label>
            <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                <input id="jump-url" name="url" type="text" maxlength="2048" required
                       placeholder="https://example.com/очень/длинная/ссылка?utm_source=…"
                       class="n2j-input w-full">
                <button type="submit"
                        class="n2j-btn-process shrink-0">
                    <i class="fas fa-compress mr-1"></i>Сократить
                </button>
            </div>
            <p class="n2j-hint mt-1.5">Можно и внутренние пути — «/vat» станет ссылкой на этот сайт.</p>
        </form>
        <?php endif; ?>
    </div>

    <!-- ============ Результат ============ -->
    <?php if ( ! empty($created) && ! empty($created['code'])): ?>
        <div class="rounded-xl border-2 border-blue-500 bg-blue-50/60 p-5 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20">
            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">
                <?php echo ! empty($created['existing']) ? 'У этой ссылки уже был короткий код' : 'Готово! Ваша короткая ссылка'; ?>
            </p>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <code id="jump-short" class="rounded-xl bg-white px-3 py-2 font-mono text-lg font-bold text-blue-700 shadow-xs dark:bg-gray-900 dark:text-blue-300"><?php echo HTML::chars(URL::base(TRUE).'x/'.$created['code']); ?></code>
                <button type="button" id="jump-copy"
                        class="n2j-btn-process">
                    <i class="fas fa-copy"></i> Копировать
                </button>
                <a href="<?php echo HTML::chars(URL::base(TRUE).'x/'.$created['code']); ?>" target="_blank" rel="noopener"
                   class="n2j-btn-secondary">
                    <i class="fas fa-arrow-up-right-from-square"></i> Проверить
                </a>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-4 border-t border-blue-200/60 pt-4 dark:border-blue-800/60">
                <div class="shrink-0 rounded-xl bg-white p-2 shadow-xs">
                    <div id="nj530-qr" data-qr-url="<?php echo HTML::chars(URL::base(TRUE).'x/'.$created['code']); ?>"></div>
                </div>
                <div class="min-w-0 text-xs text-gray-500 dark:text-gray-400">
                    <p class="font-semibold text-gray-700 dark:text-gray-200"><i class="fas fa-qrcode mr-1 text-blue-500"></i>QR-код ссылки</p>
                    <p class="mt-1">Наведите камеру телефона — удобно для афиш, чеков и экранов.</p>
                </div>
            </div>

            <?php if (isset($created['row'])): ?>
                <table class="n2j-table mt-4">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr><td class="py-2 text-gray-500 dark:text-gray-400">Переходов</td><td class="py-2 text-right font-semibold"><?php echo (int) $created['row']['clicks']; ?></td></tr>
                        <tr><td class="py-2 text-gray-500 dark:text-gray-400">Куда ведёт</td><td class="max-w-[20rem] truncate py-2 text-right font-medium"><?php echo HTML::chars($created['url']); ?></td></tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<aside class="space-y-4 md:col-span-1">
    <!-- ============ Мои ссылки (для авторизованных) ============ -->
    <?php if ( ! empty($my)): ?>
        <div class="n2j-card">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-clock-rotate-left mr-1.5 text-gray-400"></i>Мои короткие ссылки</p>
            <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($my as $link): ?>
                    <li class="flex items-center gap-3 py-2.5">
                        <i class="fas fa-link w-5 shrink-0 text-center text-xs text-gray-400"></i>
                        <a href="<?php echo HTML::chars(URL::base(TRUE).'x/'.$link['code']); ?>" target="_blank" rel="noopener"
                           class="shrink-0 font-mono text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">/x/<?php echo HTML::chars($link['code']); ?></a>
                        <a href="/x/<?php echo HTML::chars($link['code']); ?>/qr" title="Страница ссылки с QR-кодом"
                           class="shrink-0 text-gray-400 transition hover:text-blue-600 dark:hover:text-blue-400"><i class="fas fa-qrcode text-sm"></i></a>
                        <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($link['url']); ?></span>
                        <span class="n2j-badge n2j-badge-wait shrink-0"
                              title="Переходов"><?php echo (int) $link['clicks']; ?> <i class="fas fa-arrow-pointer text-xs"></i></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- ============ FAQ ============ -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3" open>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Одна ссылка — один код?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да. Сократите один и тот же URL дважды — получите один и тот же короткий код.
                    Счётчик переходов при этом общий.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что считается в статистике?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Считаются все переходы; IP-адрес кликнувшего сохраняется для защиты от накрутки и доступен только администратору сайта.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Код навсегда?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да, ссылки не «протухают» и не требуют аккаунта. Для интеграций есть JSON API: POST /nj530/api/shorten.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php // Библиотека QR (vendor) + инлайн-скрипт в конце вьюхи
if ( ! empty($created) && ! empty($created['code'])): ?>
<script src="/assets/vendor/qrcode/qrcode.js"></script>
<?php endif;
echo View::factory('530/script');
