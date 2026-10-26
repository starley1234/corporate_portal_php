<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Рабочая область поиска: форма, карточка запроса и находки.
 *
 * Оформление выбрано владельцем 09.10.2026 — из трёх сделанных вариантов
 * оставлен первый («Рабочая колонка»: форма в широкой карточке, справа узкая
 * колонка с состоянием банка); переключатель и лишние вьюхи удалены.
 *
 * После отправки форма уступает место карточке запроса (она занимает те же
 * 2/3), а находки появляются плотной сеткой ниже; страница не перезагружается.
 *
 * @var array $query текущий поиск или NULL
 * @var array $hits  находки
 * @var array $stats состояние банка
 * @var int   $top   сколько совпадений показывать
 * @var int   $max   предел «сколько показать»
 * @var int   $mb    предел файла, МБ
 * @var string $csrf токен формы
 */
?>
<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

    <!-- ============ Форма поиска (2/3) ============ -->
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs sm:p-6 md:col-span-2 dark:border-gray-700/80 dark:bg-gray-800 <?php echo $query ? 'hidden' : ''; ?>"
         data-nj654-form>
        <form id="nj654-form" method="post" action="/similar/find" enctype="multipart/form-data" class="space-y-4 sm:space-y-5">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

            <div>
                <label for="nj654-text" class="n2j-label">
                    Что ищем <span class="font-normal text-gray-400">— словами</span>
                </label>
                <input id="nj654-text" name="text" type="text" autocomplete="off" enterkeyhint="search"
                       placeholder="например: рыжий кот на подоконнике"
                       class="n2j-input mt-1.5 w-full">
                <p class="n2j-hint">Ищем по смыслу, а не по имени файла.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                <div>
                    <label for="nj654-image" class="n2j-label">
                        …или картинка <span class="font-normal text-gray-400">— до <?php echo (int) $mb; ?> МБ</span>
                    </label>
                    <input id="nj654-image" name="image" type="file" accept="image/*"
                           class="mt-1.5 block min-h-[44px] w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-600 outline-none transition file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700 focus:border-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
                </div>
                <div>
                    <label for="nj654-top" class="n2j-label">Сколько показать</label>
                    <select id="nj654-top" name="top"
                            class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <?php foreach ([1, 3, 5, 10] as $n): ?>
                            <?php if ($n > (int) $max) { continue; } ?>
                            <option value="<?php echo $n; ?>" <?php echo $n === (int) $top ? 'selected' : ''; ?>>
                                <?php echo $n === 1 ? 'самое похожее' : $n.' варианта'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <p class="n2j-hint">Текст и картинку можно отправить вместе.</p>

            <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:items-center">
                <button type="submit" id="nj654-go"
                        class="n2j-btn-process w-full sm:w-auto">
                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>Найти
                </button>

                <a href="/similar/collection"
                   class="n2j-btn-secondary w-full sm:w-auto">
                    Пополнить банк
                </a>
            </div>
        </form>
    </div>

    <!-- ============ Что ищем и как идёт работа (2/3) ============ -->
    <?php echo View::factory('654/panel', ['query' => $query, 'top' => $top,  'class' => 'md:col-span-2']); ?>

    <!-- ============ Колонка-компаньон (1/3) ============ -->
    <div class="space-y-5">

        <div class="n2j-card">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">В банке</h3>
            <dl class="mt-3 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                <div class="flex items-baseline justify-between gap-2">
                    <dt>Элементов</dt>
                    <dd class="font-mono font-bold text-gray-900 dark:text-white"><?php echo (int) ($stats['total'] ?? 0); ?></dd>
                </div>
                <div class="flex items-baseline justify-between gap-2">
                    <dt>С картинкой</dt>
                    <dd class="font-mono font-bold text-gray-900 dark:text-white"><?php echo (int) ($stats['images'] ?? 0); ?></dd>
                </div>
                <?php if ((int) ($stats['waiting'] ?? 0) > 0): ?>
                <div class="flex items-center gap-1.5 pt-1 text-xs">
                    <i class="fas fa-spinner n2j-spin text-[11px] text-amber-600 dark:text-amber-400" aria-hidden="true"></i>
                    <span class="font-medium text-gray-700 dark:text-gray-300">Считается</span>
                    <span class="font-mono text-xs text-gray-400"><?php echo (int) $stats['waiting']; ?> шт.</span>
                </div>
                <?php endif; ?>
            </dl>
            <a href="/similar/collection"
               class="mt-3 inline-flex min-h-[36px] items-center gap-1.5 text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">
                <i class="fas fa-images text-[11px]" aria-hidden="true"></i>Открыть банк картинок
            </a>
        </div>

        <?php // Вторичные общие советы: на телефоне они только отодвигают результаты. ?>
        <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs sm:p-5 md:block dark:border-gray-700/60 dark:bg-gray-800/40">
            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                <i class="fas fa-lightbulb text-amber-500" aria-hidden="true"></i>
                <h3 class="text-sm font-bold">Быстрые советы</h3>
            </div>
            <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span>
                    <span>Описывайте то, что видно на картинке, а не название файла.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span>
                    <span>Картинка задаёт вид, текст рядом уточняет смысл.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span>
                    <span>Ответ приходит за несколько секунд — страница не перезагружается.</span></li>
            </ul>
        </div>
    </div>
</div>

<?php // Находки — во всю ширину: плитки не должны тесниться в двух третях. ?>
<?php echo View::factory('654/results', [
    'query' => $query,
    'hits'  => $hits,
    'head'  => 'Найдено в банке',
    'grid_class' => 'grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6',
]); ?>
