<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Блок «снимите или приложите фото» — ОБЩИЙ для сервисов с vision-моделью.
 *
 * Механизм проверен на /label (nj646) и повторяется в каждом VLM-сервисе:
 * выбор файла, съёмка с камеры телефона, перетаскивание, превью, уменьшение
 * кадра прямо в браузере, кнопка действия и место под живой статус-ряд.
 * Чтобы это не копировалось по модулям, разметка живёт здесь, а JS — в
 * `partials/photo_capture_js` (`window.N2JVision`).
 *
 * Параметры:
 *   id      (string, обяз.) — префикс идентификаторов на странице («nj650»);
 *   api     (string, обяз.) — база API модуля («/receipt/api»);
 *   title   (string)        — заголовок блока;
 *   button  (string)        — надпись на кнопке действия;
 *   live    (string)        — название живого ряда («Читаю чек»);
 *   hint    (string)        — подсказка под рамкой;
 *   max_mb  (int)           — предел размера кадра;
 *   ready   (bool)          — подключена ли vision-модель (иначе честная плашка);
 *   compact (bool)          — узкий вариант (превью в строку, без большой рамки).
 *
 * Полос прогресса здесь нет и быть не может (правило платформы): ход работы
 * показывает общий статус-ряд — спиннер, этап воркера и секундомер.
 */

$pc_id     = (string) ($id ?? 'vision');
$pc_api    = rtrim((string) ($api ?? ''), '/');
$pc_title  = (string) ($title ?? 'Фото');
$pc_button = (string) ($button ?? 'Разобрать фото');
$pc_live   = (string) ($live ?? 'Смотрю фото');
$pc_hint   = (string) ($hint ?? 'JPEG, PNG или WebP · можно перетащить файл сюда');
$pc_mb     = max(1, (int) ($max_mb ?? 8));
$pc_ready  = isset($ready) ? (bool) $ready : TRUE; // раньше «= ... OR ...» присваивало только первый операнд
?>
<section class="n2j-card"
         data-vision-root="<?php echo HTML::chars($pc_id); ?>"
         data-vision-api="<?php echo HTML::chars($pc_api); ?>"
         data-vision-live="<?php echo HTML::chars($pc_live); ?>"
         data-vision-mb="<?php echo $pc_mb; ?>">

    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300"><?php echo HTML::chars($pc_title); ?></h2>

    <?php if ( ! $pc_ready): ?>
    <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
        Распознавание по фото сейчас выключено: на сервере не подключена vision-модель
        (VLM — модель, которая «видит» картинку). Страница и примеры работают, разбор фото — нет.
    </p>
    <?php endif; ?>

    <label for="<?php echo $pc_id; ?>-file" data-vision-drop
           class="mt-2 flex cursor-pointer flex-col items-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-7 text-center transition hover:border-blue-400 hover:bg-blue-50/40 dark:border-gray-600 dark:bg-gray-900 dark:hover:border-blue-500 dark:hover:bg-blue-950/20">
        <input type="file" id="<?php echo $pc_id; ?>-file" accept="image/*" class="hidden" data-vision-file>
        <i class="fas fa-camera text-2xl text-gray-400"></i>
        <span class="block text-sm font-semibold text-gray-700 dark:text-gray-200">Снять или приложить фото</span>
        <span class="block text-xs text-gray-500 dark:text-gray-400">
            <?php echo HTML::chars($pc_hint); ?> · до <?php echo $pc_mb; ?> МБ
        </span>
    </label>

    <div class="mt-3 hidden items-center gap-3" data-vision-preview>
        <img alt="Превью снимка" data-vision-img
             class="h-16 w-16 rounded-lg border border-gray-200 object-cover dark:border-gray-700">
        <span class="min-w-0 flex-1 text-xs text-gray-600 dark:text-gray-300">Кадр готов — нажмите кнопку ниже.</span>
        <button type="button" data-vision-clear class="n2j-btn-secondary n2j-btn-sm shrink-0">
            убрать
        </button>
    </div>

    <button type="button" data-vision-send class="n2j-btn-process mt-3 w-full">
        <i class="fas fa-wand-magic-sparkles"></i><span><?php echo HTML::chars($pc_button); ?></span>
    </button>

    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400" data-vision-say></p>

    <div data-vision-live-host></div>
</section>

<?php /* Шаблон живого ряда: страница клонирует его, когда работа взята, и
 * подставляет адрес своего прогона. Разметка общая (partials/live_status) —
 * ряд выглядит одинаково на /label, /trends и во всех VLM-сервисах. */ ?>
<template data-vision-live-tpl="<?php echo HTML::chars($pc_id); ?>">
    <?php echo View::factory('partials/live_status', [
        'url'    => $pc_api.'/status',
        'title'  => $pc_live,
        'status' => 'queued',
        'note'   => 'Задачу ведёт воркер: здесь видно этап и время работы. Результат появится сам.',
    ]); ?>
</template>
