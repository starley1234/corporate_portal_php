<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * «Состав: текст и фото этикетки» (nj646_label) — ЛЁГКАЯ страница ВХОДА.
 *
 * Владелец (03.10.2026): «/label — лёгкая страница входа, человек может написать
 * текст + этикетка, либо текст, либо этикетка, нажать на кнопку и получить
 * наглядный ответ полезен продукт или нет»; и следом: «сделай рабочую область во
 * всю ширину (как у других модулей), соблюдай единообразие! Текстовое поле слева,
 * вставка этикетки справа, зачем вниз простыню расстелил?».
 *
 * Поэтому страница собрана как у остальных модулей платформы
 * (`w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8`, сетка 1/3 + 2/3, блок-компаньон
 * `hidden md:block`): СЛЕВА — фото этикетки, СПРАВА — состав текстом и ОДНА
 * кнопка. Пояснения, которые раньше лежали простынёй под формой, уехали в
 * блок-компаньон под фото и читаются только там, где есть место.
 *
 * Порядок колонок (03.10.2026, владелец): «фото этикетки размести слева от
 * „Состав с упаковки“» — снимок делают первым, а текст правят уже после него,
 * поэтому ввод идёт слева направо в том же порядке, что и действия человека.
 * На узком экране колонки встают в столбик, и фото остаётся первым.
 *
 * Кнопок-примеров и выбора «еда/косметика» нет: тип продукта и вид состава
 * модуль определяет сам (Label::kind_guess + Label::product_kind).
 *
 * Фото — тот же ввод, а не отдельный шаг: приложил, нажал «Разобрать» — и
 * страница сама доводит дело до ответа (/label/analysis/<id>). Разметку живого
 * ряда распознавания даёт общий `partials/live_status`.
 */

$photo_mb = (int) ($limits['photo_mb'] ?? 8);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <h1 class="n2j-h1">Состав: текст и фото этикетки</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
        Вставьте состав с упаковки и/или приложите фото — сервис покажет, что в продукте полезного и что требует
        внимания. Это справочный разбор, а не медицинская рекомендация.
    </p>

    <div class="mt-5 gap-5 space-y-5 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ===================== слева: фото этикетки ===================== -->
        <aside class="space-y-5">

            <section class="n2j-card">
                <h2 class="n2j-h3">Фото этикетки</h2>

                <label id="nj646-drop" for="nj646-file"
                       class="mt-2 flex cursor-pointer flex-col items-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-7 text-center transition hover:border-blue-400 hover:bg-blue-50/40 dark:border-gray-600 dark:bg-gray-900 dark:hover:border-blue-500 dark:hover:bg-blue-950/20">
                    <input type="file" id="nj646-file" accept="image/*" class="hidden">
                    <i class="fas fa-camera text-2xl text-gray-400"></i>
                    <span class="block text-sm font-semibold text-gray-700 dark:text-gray-200">Приложить фото этикетки</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        JPEG/PNG/WebP до <?php echo $photo_mb; ?> МБ · или перетащите файл сюда
                    </span>
                </label>

                <div id="nj646-photo-preview" class="mt-3 hidden items-center gap-3">
                    <img id="nj646-photo-img" alt="Превью этикетки" class="h-16 w-16 rounded-lg border border-gray-200 object-cover dark:border-gray-700">
                    <span class="min-w-0 flex-1 text-xs text-gray-600 dark:text-gray-300">Фото готово — уйдёт вместе с разбором.</span>
                    <button type="button" id="nj646-photo-clear"
                            class="n2j-btn-danger n2j-btn-sm shrink-0">
                        убрать
                    </button>
                </div>
				
				<div class="flex flex-wrap items-center justify-between gap-2">
                <label for="nj646-text" class="n2j-label">
                    Состав с упаковки
                </label>
                <span class="hidden text-xs text-gray-400 sm:block dark:text-gray-500">Ctrl + Enter — разобрать</span>
            </div>

            <textarea id="nj646-text" rows="2" autocomplete="off"
                      placeholder="Например: сахар, пшеничная мука, пальмовое масло, какао-порошок, сухое молоко, соль, разрыхлитель (E500), ароматизатор…"
                      class="n2j-textarea mt-2 w-full leading-relaxed"><?php echo HTML::chars((string) ($replay_text ?? '')); ?></textarea>

            <?php if ( ! empty($replay_id)): ?>
                <p class="mt-1 text-xs text-blue-600 dark:text-blue-400">
                    Текст перенесён со <a class="underline" href="/label/analysis/<?php echo (int) $replay_id; ?>">страницы разбора №<?php echo (int) $replay_id; ?></a> —
                    нажмите «Разобрать».
                </p>
            <?php endif; ?>

            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-3">
                <button type="button" id="nj646-analyze"
                        class="n2j-btn-process">
                    <i class="fas fa-wand-magic-sparkles mr-1.5"></i>Разобрать
                </button>

                <label class="inline-flex cursor-pointer items-center gap-2 text-xs text-gray-600 dark:text-gray-300"
                       title="<?php echo $full
                           ? 'Изучить все незнакомые вещества и дождаться полного разбора (занимает до '.round($full_wait_sec / 60).' мин)'
                           : 'Незнакомые вещества уходят на изучение, и страница разбора уточняет результат'; ?>">
                    <input type="checkbox" id="nj646-detailed" class="h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo $detailed ? 'checked' : ''; ?>>
                    <span>развёрнутый разбор<?php echo $full ? ' (полный)' : ''; ?></span>
                </label>
            </div>

            <p id="nj646-text-out" class="n2j-hint mt-2"></p>

            <!-- Живой ход работы: распознавание фото и разбор видны на месте. -->
            <div id="nj646-photo-live"></div>
				
				
            </section>

            <?php // Блок-компаньон: правило платформы — на мобильных он скрыт
                  // (`hidden md:block`), потому что рядом с формой ему нет места.
                  // Всё, что раньше лежало простынёй под формой, живёт здесь. ?>
				  
				  
			<!-- ===================== справа: состав текстом ===================== -->
			<section class="n2j-card hidden md:col-span-2">
			<h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    Чтобы модель читала, а не угадывала
                </h2>
                <ul class="mt-2.5 space-y-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2">
                        <i class="fas fa-camera mt-0.5 shrink-0 text-gray-400"></i>
                        <span>Снимайте блок «Состав» крупно. Мелкий шрифт модель угадывает: если снимок мелкий, в разборе
                            появится честная пометка об этом.</span>
                    </li>
                    <li class="flex gap-2">
                        <i class="fas fa-layer-group mt-0.5 shrink-0 text-gray-400"></i>
                        <span>Текст и фото вместе — лучший вариант: состав разберём по вашему тексту, а с этикетки возьмём
                            товар, изготовителя, срок годности и штрихкод.</span>
                    </li>
                    <li class="flex gap-2">
                        <i class="fas fa-book-open mt-0.5 shrink-0 text-gray-400"></i>
                        <span>Разбор опирается на <a href="/label/knowledge" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">базу знаний</a>:
                            незнакомые вещества уходят на изучение, и разбор уточняется.</span>
                    </li>
                </ul>

                <?php if ( ! $llm_ready): ?>
                    <p class="n2j-flash n2j-flash-warn mt-3 text-xs block">
                        <i class="fas fa-triangle-exclamation mr-1"></i>Vision-модель сейчас не подключена: фото подождёт
                        в очереди, а текст разбирается как обычно.
                    </p>
                <?php endif; ?>
				
			</section>	  
				  
            

            <p class="n2j-hint md:block">
                Разборы привязаны к анонимной метке браузера: вход не нужен, но и «в другом браузере» история другая.
                Профиль (аллергии, диета, кожа) меняет предупреждения в разборах — <a href="/label/profile" class="underline">мой профиль</a>.
            </p>
        </aside>

    </div>
</div>

<?php /* Шаблон живого ряда распознавания: страница клонирует его, когда берётся
 * работа, и подставляет адрес своего прогона. Разметка — общая
 * (partials/live_status), поэтому ряд выглядит как на /trends и /docs. */ ?>
<template data-n646-live-tpl="photo">
    <?php echo View::factory('partials/live_status', [
        'url'    => '/label/api/status',
        'title'  => 'Распознаю этикетку',
        'status' => 'queued',
        'note'   => 'Задачу ведёт воркер: здесь видно время работы. Дальше разбор пойдёт сам.',
    ]); ?>
</template>

<?php echo View::factory('partials/live_status_js'); ?>
<?php echo View::factory('646/js/core'); ?>
<?php echo View::factory('646/js/label', ['limits' => $limits]); ?>
