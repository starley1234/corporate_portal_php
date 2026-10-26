<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница короткой ссылки (nj530_jump): /x/<code>/qr — краткая карточка
 * ссылки с QR-кодом (для печати, презентаций, отправки в мессенджер).
 */
$created_fmt = empty($link['created_at']) ? '' : HTML::chars((string) $link['created_at']);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mx-auto max-w-3xl">

    <p class="n2j-hint">
        <a href="/jump" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сократить ссылку</a>
        <i class="fas fa-chevron-right mx-1 text-[10px]"></i>/x/<?php echo HTML::chars($link['code']); ?>
    </p>

    <div class="n2j-card mt-3">
        <h1 class="n2j-h1">
            <i class="fas fa-qrcode mr-2 text-blue-500"></i>Ссылка /x/<?php echo HTML::chars($link['code']); ?>
        </h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Покажите QR-код с экрана — или распечатайте эту страницу. Переход по короткой ссылке работает как обычно.</p>

        <div class="mt-5 grid gap-5 md:grid-cols-3 md:items-start">
            <!-- QR-код -->
            <div class="flex flex-col items-center rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/60">
                <div id="nj530-qr-page" data-qr-url="<?php echo HTML::chars($short_url); ?>"></div>
                <p class="mt-2 font-mono text-xs font-semibold text-gray-600 dark:text-gray-300">/x/<?php echo HTML::chars($link['code']); ?></p>
            </div>

            <!-- Детали ссылки -->
            <div class="space-y-3 md:col-span-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Короткая ссылка</p>
                    <div class="mt-1 flex items-center gap-2">
                        <a href="<?php echo HTML::chars($short_url); ?>" class="min-w-0 flex-1 truncate font-mono text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($short_url); ?></a>
                        <button type="button" id="nj530-qr-copy"
                                class="n2j-btn-secondary n2j-btn-sm shrink-0">
                            <i class="fas fa-copy"></i>Копировать
                        </button>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Куда ведёт</p>
                    <p class="mt-1 break-all text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars($link['url']); ?></p>
                </div>
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-gray-400 dark:text-gray-500">
                    <span><i class="fas fa-arrow-pointer mr-1"></i>Переходов: <b class="text-gray-600 dark:text-gray-300"><?php echo (int) $link['clicks']; ?></b></span>
                    <?php if ($created_fmt !== ''): ?><span><i class="fas fa-calendar mr-1"></i>Создана: <?php echo $created_fmt; ?></span><?php endif; ?>
                </div>
                <div class="flex flex-wrap gap-2 pt-1">
                    <a href="/jump?c=<?php echo HTML::chars($link['code']); ?>"
                       class="n2j-btn-secondary n2j-btn-sm">
                        <i class="fas fa-chart-line"></i>Статистика
                    </a>
                    <a href="/jump"
                       class="n2j-btn-secondary n2j-btn-sm">
                        <i class="fas fa-compress"></i>Сократить ещё
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<script src="/assets/vendor/qrcode/qrcode.js"></script>
<?php echo View::factory('530/qr_script'); ?>
