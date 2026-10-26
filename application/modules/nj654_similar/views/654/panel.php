<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Карточка запроса и хода работы — общая часть всех трёх вариантов оформления.
 *
 * Что здесь и почему именно так:
 *   · «что ищем» — текст запроса, миниатюра приложенной картинки (по клику
 *     открывается целиком), дата и обещанное число совпадений;
 *   · ход работы — НЕРАЗРЫВНЫЙ ряд «спиннер + название + секундомер»
 *     (правило 4 дизайн-эталона): их нельзя расталкивать по краям карточки,
 *     полос хода нет — ход виден спиннером, названием этапа и числом секунд;
 *   · «Новый поиск» — обычная ссылка на /similar: без JS она честно открывает
 *     чистую страницу поиска, с JS движок просто показывает форму обратно,
 *     ничего не перезагружая (см. 654/search_js);
 *   · состояние приходит из `/similar/api/status?q=<id>` теми же полями, что и
 *     у общего механизма статус-ряда платформы (status/stage/elapsed_sec/error).
 *
 * @var array  $query строка nj_654_queries или NULL (заготовка для JS)
 * @var int    $top   сколько совпадений заказано
 * @var string $class дополнительный класс карточки (место в сетке варианта)
 */

$query = (array) ($query ?? []);
$id    = (int) ($query['id'] ?? 0);
$text  = trim((string) ($query['text'] ?? ''));
$file  = trim((string) ($query['file'] ?? ''));
$state = (string) ($query['status'] ?? 'queued');
$error = trim((string) ($query['error'] ?? ''));
$hits  = count((array) ($query['hits'] ?? []));

$busy  = in_array($state, ['queued', 'running'], TRUE);
$ready = $state === 'ready';
$dead  = ( ! $busy AND ! $ready);

// Секундомер: замера воркера ещё нет — считаем от постановки задачи. Так
// человек видит, что процесс жив, даже если воркер молчит.
$elapsed = $id > 0
    ? ((int) ($query['elapsed_ms'] ?? 0) > 0
        ? (int) floor((int) $query['elapsed_ms'] / 1000)
        : max(0, time() - (strtotime((string) ($query['created_at'] ?? 'now')) ?: time())))
    : 0;

$image = $file !== '' ? Service_Nj654_Bank::file_url($file) : '';
$shown = $text !== '' ? $text : 'Поиск по картинке';

// Адрес страницы поиска: вариант оформления выбран один (09.10.2026), поэтому
// больше не тащим ?ui= в ссылки движка и в history.
$page_url = '/similar';

// Карточка запроса — сама себе контейнер: одному варианту она ложится в сетку
// 2/3 (и тогда её место задаёт класс), другой ставит её во всю ширину.
$card = trim('rounded-xl border border-gray-200 bg-white p-4 shadow-xs sm:p-6 dark:border-gray-700/80 dark:bg-gray-800 '.(string) ($class ?? ''));
?>
<div class="<?php echo HTML::chars($card); ?> <?php echo $id > 0 ? '' : 'hidden'; ?>"
     data-nj654-panel
     data-nj654-id="<?php echo $id; ?>"
     data-nj654-status="<?php echo HTML::chars($state); ?>"
     data-nj654-elapsed="<?php echo (int) $elapsed; ?>"
     data-nj654-base="<?php echo HTML::chars($page_url); ?>"
     data-nj654-result="/similar/result/"
     aria-live="polite">

    <div class="flex flex-wrap items-start gap-3">

        <?php // Миниатюра запроса: показывается картинкой, если запрос был картинкой. ?>
        <button type="button"
                class="<?php echo $image === '' ? 'hidden' : ''; ?> shrink-0 cursor-zoom-in rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                data-nj654-q-photo
                data-nj654-zoom="<?php echo HTML::chars($image); ?>"
                data-nj654-zoom-title="Картинка запроса">
            <img src="<?php echo HTML::chars($image); ?>" alt="Картинка запроса" data-nj654-q-img
                 class="h-14 w-14 rounded-lg border border-gray-200 bg-gray-50 object-cover sm:h-16 sm:w-16 dark:border-gray-700 dark:bg-gray-900">
        </button>
        <span data-nj654-q-icon
              class="<?php echo $image === '' ? '' : 'hidden'; ?> flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 sm:h-16 sm:w-16 dark:bg-blue-950/40 dark:text-blue-300"
              aria-hidden="true"><i class="fas fa-magnifying-glass"></i></span>

        <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Ваш запрос</p>
            <p class="mt-0.5 break-words text-sm font-bold text-gray-900 sm:text-base dark:text-white"
               data-nj654-q><?php echo HTML::chars($shown); ?></p>
            <p class="n2j-hint">
                <?php if ($id > 0): ?>
                    <?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) ($query['created_at'] ?? 'now')))); ?>
                    · показать до <?php echo (int) ($query['top'] ?? $top); ?> совпадений
                <?php else: ?>
                    покажем до <?php echo (int) $top; ?> совпадений
                <?php endif; ?>
            </p>
        </div>

        <a href="<?php echo HTML::chars($page_url); ?>" data-nj654-new
           class="n2j-btn-secondary n2j-btn-sm shrink-0">
            <i class="fas fa-rotate-left text-[11px]" aria-hidden="true"></i>Новый поиск
        </a>
    </div>

    <?php // Неразрывный статус-ряд: спиннер, название и секундомер всегда рядом. ?>
    <div class="mt-3.5 rounded-xl border <?php echo $busy
            ? 'border-blue-200 bg-blue-50/40 dark:border-blue-900/60 dark:bg-blue-950/20'
            : 'border-gray-200 dark:border-gray-700'; ?> p-3.5"
         data-nj654-state>

        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
            <i class="fas <?php echo $busy ? 'fa-spinner n2j-spin text-blue-500'
                : ($ready ? 'fa-circle-check text-emerald-500' : 'fa-triangle-exclamation text-rose-500'); ?> text-sm"
               data-nj654-spin aria-hidden="true"></i>
            <span class="text-sm font-bold text-gray-900 dark:text-white"
                  data-nj654-title><?php echo $busy ? 'Ищу похожие изображения' : ($ready ? 'Готово' : 'Поиск не выполнен'); ?></span>
            <span class="n2j-badge n2j-badge-wait font-mono tabular-nums"
                  data-nj654-elapsed><?php echo (int) floor($elapsed / 60); ?>:<?php echo str_pad((string) ($elapsed % 60), 2, '0', STR_PAD_LEFT); ?></span>
            <span class="<?php echo ($ready AND $hits > 0) ? '' : 'hidden'; ?> rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"
                  data-nj654-found>найдено: <?php echo $hits; ?></span>
        </div>

        <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-300" data-nj654-stage><?php
            if ($busy)
            {
                echo $state === 'queued'
                    ? 'Запрос ждёт свободного обработчика…'
                    : 'Сравниваю смысл запроса с картинками банка…';
            }
            elseif ($ready)
            {
                echo $hits > 0
                    ? 'Совпадения ниже — они уже на странице.'
                    : 'Похожего в банке нет — попробуйте другое описание.';
            }
            else
            {
                echo 'Воркер вернул отказ — детали ниже.';
            }
        ?></p>

        <p class="n2j-hint mt-1.5" data-nj654-note><?php
            echo $busy
                ? 'Результаты появятся здесь сами, обычно через несколько секунд.'
                : ($ready ? 'Число рядом с подписью — близость: 1,000 — та же картинка.' : 'Запрос сохранён в журнале поисков.');
        ?></p>
    </div>

    <?php // Ошибка: текст от воркера, а не «что-то пошло не так». ?>
    <div class="<?php echo $dead ? '' : 'hidden'; ?> mt-3 rounded-xl border border-rose-200 bg-rose-50/90 p-3.5 text-xs text-rose-800 dark:border-rose-800/80 dark:bg-rose-950/40 dark:text-rose-300"
         data-nj654-error role="alert">
        <p><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i><span data-nj654-error-text><?php echo HTML::chars($error !== '' ? $error : 'поиск не выполнен'); ?></span></p>
        <button type="button" data-nj654-reset
                class="n2j-btn-secondary n2j-btn-sm mt-2">
            Вернуться к запросу
        </button>
    </div>
</div>
