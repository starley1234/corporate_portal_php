<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Живой статус-ряд задачи — ОБЩИЙ механизм для всех модулей.
 *
 * Эталон: /trends/<code> («неразрывный статус-ряд + живой прогресс»). Раньше
 * такой ряд был только у трендов и профиля компании; остальные модули показывали
 * либо ничего, либо вечный 15-минутный таймер «идёт», а после работы — молчали
 * (жалоба владельца: «по прежнему нет готово»).
 *
 * Как работает:
 *   1. SSR отдаёт первый кадр: спиннер, название, секундомер, известный этап;
 *   2. JS раз в 3 с опрашивает thin-endpoint модуля (JSON: status/stage/stages/
 *      elapsed_sec/silent_sec/error) и обновляет ряд;
 *   3. на терминальном статусе (done/failed/cancelled) блок становится
 *      «Готово ✓» / «Ошибка», и страница ОДИН раз перезагружается — дальше
 *      пользователь видит уже готовый результат.
 *
 * Параметры:
 *   url     (string, обяз.) — thin-endpoint модуля, отдающий JSON статуса;
 *   title   (string)        — «Собираем находки» и т.п.;
 *   status  (string)        — queued|running (что известно на сервере);
 *   stage   (string)        — последний этап, если уже есть (SSR-кадр);
 *   elapsed (int)           — секунд работы по данным сервера;
 *   silent  (int|NULL)      — секунд без пульса (показать «нет пульса»);
 *   note    (string)        — подпись под строкой этапа;
 *   steps   (array)         — уже известные этапы [['message'=>…], …];
 *   compact (bool, =false)  — только спиннер, название и секундомер (без этапов);
 *   stop    (string)        — HTML-форма «Остановить» (POST act=stop_run).
 *
 * Контракт endpoint'а одинаков для всех модулей (см. Service_Prx120_JobQueue::
 * live_status()): {"ok":true,"status":"queued|running|done|failed|cancelled",
 * "stage":"…","stages":[{"message":"…"}],"elapsed_sec":12,"silent_sec":3,
 * "error":"…"}.
 */
$live_url     = trim((string) ($url ?? ''));
// Подробности — необязательная часть ряда. Модуль, которому нужен лаконичный
// ряд (плитки изучаемых веществ), просит `compact`: спиннер, название и
// секундомер, без списка этапов.
//
// Полосы хода в ряду НЕТ вовсе (владелец: «убери полосу, я её из эталона
// убрал»; 03.10.2026 — «полосы убрать везде»). Ход работы виден спиннером и
// секундомером: полоса была анимацией-обманкой — двигалась не по прогрессу.
// Опцию `bar` не возвращать.
$live_compact = ! empty($compact);
$live_title   = (string) ($title ?? 'Идёт работа');
$live_status  = (string) ($status ?? 'running');
$live_stage   = trim((string) ($stage ?? ''));
$live_elapsed = max(0, (int) ($elapsed ?? 0));
$live_silent  = isset($silent) ? $silent : NULL;
$live_note    = (string) ($note ?? 'Страница обновится сама, когда работа закончится.');
$live_steps   = (array) ($steps ?? []);
// Готовая HTML-форма остановки задачи (кнопка «Остановить») — необязательна.
// Форма обычная (POST + CSRF): работает и без JS, и при мёртвом воркере.
$live_stop    = (string) ($stop ?? '');

if ($live_url === '')
{
    return;
}

// Первый кадр уже терминальный (например, повторный рендер после завершения) —
// JS ничего не опрашивает: страница показывает результат как есть.
$live_terminal = in_array($live_status, ['done', 'failed', 'cancelled'], TRUE);
// этапы содержат домены прочитанных источников — делаем их кликабельными
$live_esc = function ($text) {
    return class_exists('Service_Prx120_Viewer')
        ? Service_Prx120_Viewer::step_html($text)
        : HTML::chars((string) $text);
};
?>
<?php /* вид — карточка процесса эталона v6: спиннер · название · секундомер, этап строкой ниже */ ?>
<div class="mt-3 rounded-xl border <?php echo $live_terminal
        ? 'border-gray-200 dark:border-gray-700'
        : 'border-blue-200/90 bg-blue-50/40 dark:border-blue-900/60 dark:bg-blue-950/20'; ?> p-4"
     role="status" aria-live="polite"
     data-prx-live
     <?php if ($live_terminal): ?>data-live-terminal="1" <?php endif; ?>
     data-live-url="<?php echo HTML::chars($live_url); ?>"
     data-live-status="<?php echo HTML::chars($live_status); ?>"
     data-live-elapsed="<?php echo (int) $live_elapsed; ?>">
    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
        <i class="fas <?php echo $live_terminal ? 'fa-circle-check text-emerald-500' : 'fa-circle-notch prx-live-spin text-blue-600 dark:text-blue-400'; ?> text-sm" aria-hidden="true"></i>
        <span class="text-sm font-bold text-gray-900 dark:text-white" data-live-title><?php echo HTML::chars($live_title); ?></span>
        <span class="text-blue-300 dark:text-blue-700" aria-hidden="true">·</span>
        <span class="prx-clock text-sm"
              data-live-elapsed><?php echo (int) floor($live_elapsed / 60); ?>:<?php echo str_pad((string) ($live_elapsed % 60), 2, '0', STR_PAD_LEFT); ?></span>
        <?php if ($live_silent !== NULL AND (int) $live_silent > 180): ?>
        <span class="prx-badge prx-badge-run" data-live-silent><span class="prx-dot"></span>нет пульса <?php echo (int) floor(((int) $live_silent) / 60); ?> мин</span>
        <?php else: ?>
        <span class="prx-badge prx-badge-run hidden" data-live-silent></span>
        <?php endif; ?>
        <?php /* живой счётчик: сколько находок уже записано в этом прогоне */ ?>
        <span class="prx-count hidden" data-live-found></span>
        <?php if ($live_stop !== ''): ?>
        <span class="ml-auto" data-live-stop><?php echo $live_stop; ?></span>
        <?php endif; ?>
    </div>

    <p class="mt-1.5 text-sm font-semibold text-gray-900 dark:text-gray-100" data-live-stage><?php echo HTML::chars($live_stage !== '' ? $live_stage : 'Задача поставлена — ждём свободного воркера…'); ?></p>

    <?php if ($live_compact): ?>
        <?php /* компактный ряд: подробности не нужны — их видно на странице разбора */ ?>
    <?php elseif ( ! empty($live_steps)): ?>
    <ol class="mt-2 space-y-0.5 text-xs text-gray-400 dark:text-gray-500" data-live-steps>
        <?php foreach (array_slice($live_steps, -4) as $s): ?>
        <li>· <?php echo $live_esc(is_array($s) ? (string) ($s['message'] ?? '') : (string) $s); ?></li>
        <?php endforeach; ?>
    </ol>
    <?php else: ?>
    <ol class="mt-2 hidden space-y-0.5 text-xs text-gray-400 dark:text-gray-500" data-live-steps></ol>
    <?php endif; ?>

    <?php if ( ! $live_compact): ?>
    <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500" data-live-note><?php echo HTML::chars($live_note); ?></p>
    <?php endif; ?>
</div>
<?php /* JS статус-ряда (поллер + PRXLive.mount) подключается отдельным
 * partials/live_status_js.php — один раз на страницу, в том числе когда ни один
 * ряд сейчас не отрисован (тогда «Собрать» монтирует его сам). */ ?>
