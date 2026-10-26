<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Главная страница 3D-конструктора (/3d): создание, мои модели, каталог.
 * Вёрстка по И22: обёртка-сетка, форма 3/4, справа 1/4 («Как это работает»).
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';

$tile = function (string $title, string $desc) use ($card) {
    return '<div class="'.$card.'">'
        .'<p class="text-sm font-bold text-gray-900 dark:text-white">'.$title.'</p>'
        .'<p class="mt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">'.$desc.'</p>'
        .'</div>';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="gap-4 space-y-4 md:grid md:grid-cols-4 md:items-start md:space-y-0">

    <!-- ===================== Основная колонка (3/4) ===================== -->
    <div class="space-y-4 md:col-span-3">

        <?php if ( ! empty($error)): ?>
        <div class="n2j-flash n2j-flash-err block">
            <?php echo HTML::chars($error); ?>
        </div>
        <?php endif; ?>

        <?php if ($logged): ?>
        <!-- Создание модели -->
        <form method="post" action="/3d" enctype="multipart/form-data"
              class="n2j-card border-blue-200 dark:border-blue-900/60 sm:p-6">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <h1 class="n2j-h1">3D-модель по тексту и фото</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Опишите конструкцию словами и/или приложите эскиз/фото. Воркер напишет код OpenSCAD, соберёт STL и покажет результат в 3D.</p>

            <textarea name="prompt" rows="3" required maxlength="4000"
                      placeholder="Например: подставка для телефона с наклоном 30°, паз для кабеля снизу, размер 90×60 мм"
                      class="n2j-textarea min-h-[44px] mt-4 w-full"></textarea>

            <div class="mt-4 grid gap-x-5 gap-y-1 rounded-xl border border-gray-200 bg-gray-50/60 p-3 sm:grid-cols-2 dark:border-gray-700 dark:bg-gray-900/40">
                <label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                    <i class="fas fa-image w-4 text-center text-gray-400"></i>Эскиз/фото:
                    <input type="file" name="image" accept="image/png,image/jpeg,image/webp"
                           class="w-full max-w-[15rem] text-xs text-gray-500 file:mr-2 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-blue-600 dark:file:bg-gray-700 dark:file:text-blue-300">
                </label>

                <label class="flex min-h-[44px] cursor-pointer items-center gap-2.5 rounded-lg text-sm text-gray-600 dark:text-gray-300" title="ИИ сначала задаст уточняющие вопросы с вариантами ответов">
                    <input type="checkbox" name="clarify" value="1" class="h-4 w-4 rounded accent-blue-600">
                    Уточнить параметры перед созданием
                </label>

                <label class="flex min-h-[44px] cursor-pointer items-center gap-2.5 rounded-lg text-sm text-gray-600 dark:text-gray-300" title="Поиск референсов в интернете (DuckDuckGo) для лучшего понимания задачи">
                    <input type="checkbox" name="use_search" value="1" class="h-4 w-4 rounded accent-blue-600">
                    Поиск референсов
                </label>

                <label class="flex min-h-[44px] items-center justify-between gap-2.5 text-sm text-gray-600 sm:justify-start dark:text-gray-300" title="Сколько циклов «код → рендер → проверка vision-моделью» выполнить">
                    Итераций:
                    <select name="iterations" class="n2j-input n2j-select ml-0 w-auto">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>"<?php echo $i === 3 ? ' selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
            </div>

            <button type="submit" class="n2j-btn-cta mt-4 w-full sm:w-auto">
                <i class="fas fa-cube mr-1.5"></i>Создать 3D-модель
            </button>
        </form>
        <?php endif; ?>

        <?php if ( ! empty($my)): ?>
        <!-- Мои модели -->
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Мои модели</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
            <?php foreach ($my as $m): ?>
            <a href="/3d/<?php echo HTML::chars($m['code']); ?>" class="<?php echo $card; ?> block overflow-hidden p-0 transition hover:border-blue-300 hover:shadow-sm dark:hover:border-blue-800">
                <div class="flex h-28 items-center justify-center overflow-hidden border-b border-gray-100 bg-gray-50 dark:border-gray-700/60 dark:bg-gray-900">
                    <?php if ( ! empty($m['png_file_id'])): ?>
                        <img src="/3d/file/<?php echo (int) $m['png_file_id']; ?>" alt="" class="max-h-28 object-contain p-2">
                    <?php else: ?>
                        <span class="rounded-lg bg-blue-50 p-2.5 text-sm font-bold text-blue-600 dark:bg-blue-950/80 dark:text-blue-400"><i class="fas fa-cube"></i></span>
                    <?php endif; ?>
                </div>
                <div class="p-3">
                    <p class="truncate text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($m['title'] ?: $m['code']); ?></p>
                    <p class="mt-1 text-xs font-semibold
                        <?php echo $m['status'] === 'published' ? 'text-emerald-600' : ($m['status'] === 'pending' ? 'text-amber-600' : ($m['status'] === 'rejected' ? 'text-rose-500' : 'text-gray-400')); ?>">
                        <?php echo $m['status'] === 'private' ? 'черновик' : ($m['status'] === 'pending' ? 'на модерации' : ($m['status'] === 'published' ? 'опубликовано' : 'отклонено')); ?>
                    </p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Каталог (опубликованные) -->
        <form method="get" action="/3d" class="flex flex-wrap items-center gap-2">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Каталог сообщества</h2>
            <input type="text" name="q" value="<?php echo HTML::chars($q); ?>" placeholder="Поиск моделей: текст или по смыслу…"
                   class="n2j-input min-h-[44px] ml-auto w-full max-w-xs py-1.5 text-sm">
            <button type="submit" class="n2j-btn-secondary n2j-btn-sm">Найти</button>
        </form>

        <?php if (empty($public)): ?>
            <p class="text-sm text-gray-500 dark:text-gray-400">Пока нет опубликованных моделей<?php echo $q !== '' ? ' по запросу «'.HTML::chars($q).'»' : ''; ?>.</p>
        <?php else: ?>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
            <?php foreach ($public as $m): ?>
            <a href="/3d/<?php echo HTML::chars($m['code']); ?>" class="<?php echo $card; ?> block overflow-hidden p-0 transition hover:border-blue-300 hover:shadow-sm dark:hover:border-blue-800">
                <div class="flex h-28 items-center justify-center overflow-hidden border-b border-gray-100 bg-gray-50 dark:border-gray-700/60 dark:bg-gray-900">
                    <?php if ( ! empty($m['png_file_id'])): ?>
                        <img src="/3d/file/<?php echo (int) $m['png_file_id']; ?>" alt="" class="max-h-28 object-contain p-2">
                    <?php else: ?>
                        <span class="rounded-lg bg-blue-50 p-2.5 text-sm font-bold text-blue-600 dark:bg-blue-950/80 dark:text-blue-400"><i class="fas fa-cube"></i></span>
                    <?php endif; ?>
                </div>
                <div class="p-3">
                    <p class="truncate text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($m['title'] ?: $m['code']); ?></p>
                    <p class="mt-1 text-xs text-amber-500">
                        <?php if ((float) $m['rating'] > 0): ?><i class="fas fa-star"></i> <?php echo HTML::chars($m['rating']); ?> <span class="text-gray-400">(<?php echo (int) $m['votes']; ?>)</span><?php else: ?><span class="text-gray-400">без оценок</span><?php endif; ?>
                    </p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===================== Правая колонка (1/4) ===================== -->
    <div class="space-y-4 md:col-span-1">
        <?php if ( ! $logged): ?>
        <div class="n2j-flash n2j-flash-info block">
            <i class="fas fa-circle-info mr-1"></i>Создание — для зарегистрированных:
            <a href="/auth/login?return_url=3d" class="font-semibold underline">войти</a>.
            Смотреть опубликованное можно и без входа.
        </div>
        <?php endif; ?>

        <div class="<?php echo $card; ?>">
            <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-question mr-1.5 text-blue-500"></i>Как это работает</p>
            <ol class="mt-2 list-decimal space-y-1.5 pl-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                <li>Опишите конструкцию текстом и/или приложите эскиз — можно включить уточнение параметров: ИИ задаст вопросы кнопками.</li>
                <li>Специальная модель пишет код OpenSCAD и проверяет результат по рендерам (до 5 итераций), описание делает вторая — экономичная модель.</li>
                <li>Воркер собирает STL, страницу можно крутить в 3D и скачать для печати.</li>
                <li>Правки — новым сообщением с текстом или картинкой; каждая версия сохраняется в ревизиях.</li>
                <li>Размеры модели можно менять ползунками — пересборка STL без переписывания кода.</li>
            </ol>
        </div>
    </div>
</div>
</div>

<?php echo View::factory('570/script');
