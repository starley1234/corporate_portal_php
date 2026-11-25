<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100/cleanup — «Чистка данных»: оценка объёма, удаление порциями (окно подтверждения).
 */
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['Администрирование', '/admin'], ['Чистка данных']],
        'title'  => 'Чистка данных',
        'pill'   => ['prx103 · обслуживание', 'fa-broom'],
        'lead'   => 'Обслуживание под присмотром человека: за клик удаляется порция до 5 000 строк — счётчик покажет, что осталось. Суммаризации (компактные профили и т.п.) не трогаются — только сырьё и устаревшие данные.',
    ]); ?>

    <?php if ( ! empty($flash)): ?>
    <div class="prx-flash prx-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="mt-5 grid gap-3 xl:grid-cols-2">
        <?php foreach ($items as $key => $conf): ?>
        <div class="prx-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($conf['label']); ?></p>
                    <p class="prx-hint mt-0.5"><?php echo HTML::chars($conf['note']); ?></p>
                </div>
                <span class="prx-count shrink-0">
                    <?php echo $state[$key] === NULL ? '—' : number_format((float) $state[$key], 0, ',', ' '); ?>
                </span>
            </div>
            <form method="post" action="/prx100/cleanup" class="mt-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="run">
                <input type="hidden" name="item" value="<?php echo HTML::chars($key); ?>">
                <button data-prx-confirm="Удалятся записи старше <?php echo (int) $conf['days']; ?> дн." data-prx-title="Удалить порцию?" type="submit"
                        class="prx-btn-danger-soft">
                    <i class="fas fa-trash-can mr-1.5"></i><span>Удалить порцию</span>
                </button>
                <span class="ml-2 text-xs text-gray-400">старше <?php echo (int) $conf['days']; ?> дн.</span>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>

