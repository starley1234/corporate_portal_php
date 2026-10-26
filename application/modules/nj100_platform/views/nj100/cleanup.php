<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj100/cleanup — «Чистка данных»: оценка объёма, удаление порциями (окно подтверждения).
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dashboard" class="transition hover:text-emerald-600 dark:hover:text-emerald-400">Кабинет</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Чистка данных</span>
    </nav>

    <h1 class="n2j-h1 mt-2">
        <i class="fas fa-broom mr-2 text-emerald-600 dark:text-emerald-400"></i>Чистка данных
    </h1>
    <p class="n2j-hint max-w-3xl">
        Обслуживание под присмотром человека: за клик удаляется порция до 5 000 строк — счётчик покажет, что осталось.
        Суммаризации (компактные профили и т.п.) не трогаются — только сырьё и устаревшие данные.
    </p>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="mt-5 grid gap-3 xl:grid-cols-2">
        <?php foreach ($items as $key => $conf): ?>
        <div class="n2j-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($conf['label']); ?></p>
                    <p class="n2j-hint mt-0.5"><?php echo HTML::chars($conf['note']); ?></p>
                </div>
                <span class="shrink-0 rounded-lg bg-gray-100 px-2.5 py-1 text-sm font-black text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                    <?php echo $state[$key] === NULL ? '—' : number_format((float) $state[$key], 0, ',', ' '); ?>
                </span>
            </div>
            <form method="post" action="/nj100/cleanup" class="mt-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="run">
                <input type="hidden" name="item" value="<?php echo HTML::chars($key); ?>">
                <button data-n2j-confirm="Удалятся записи старше <?php echo (int) $conf['days']; ?> дн." data-n2j-title="Удалить порцию?" type="submit"
                        class="n2j-btn-danger-soft">
                    <i class="fas fa-trash-can mr-1.5"></i><span>Удалить порцию</span>
                </button>
                <span class="ml-2 text-xs text-gray-400">старше <?php echo (int) $conf['days']; ?> дн.</span>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>

