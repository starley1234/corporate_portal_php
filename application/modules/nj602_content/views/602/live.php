<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj602/admin: живой статус-ряд сбора статьи — ОБЩИЙ механизм модулей
 * (partials/live_status + partials/live_status_js), эталон — «Собираем обзор»
 * на /trends/<code>. Владелец: «давай оформим визуально процесс Сбора статьи
 * сейчас так же, как в „Собираем обзор“ — нужно приводить UI к общему знаменателю».
 *
 * Фрагмент один и тот же на странице и в AJAX-обновлении (?rows=1), поэтому
 * процесс стартует/завершается без перезагрузки админки.
 *
 * @var array  $live          Service_Nj602_Content::live_of()
 * @var string $csrf          CSRF-токен (для формы «Остановить»)
 * @var bool   $with_template отдать <template> — «Собрать статью» монтирует ряд сразу
 */
$live = (array) ($live ?? []);
$csrf = (string) ($csrf ?? '');

$code   = (string) ($live['code'] ?? '');
$status = (string) ($live['status'] ?? 'none');
$job_id = (int) ($live['job_id'] ?? 0);

$row = '';

if ( ! empty($live['found']) AND $code !== '')
{
    // «Остановить» — обычная POST-форма с CSRF: работает и без JS, и когда
    // воркер уже мёртв (эталон — ряд потока заказчиков на /leads).
    $stop = '';

    if ($job_id > 0 AND in_array($status, ['queued', 'running'], TRUE))
    {
        $stop = '<form method="post" action="/nj602/admin" class="inline-flex">'
            .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
            .'<input type="hidden" name="act" value="stop_job">'
            .'<input type="hidden" name="code" value="'.HTML::chars($code).'">'
            .'<button type="submit" title="Прервать сбор: черновик останется, его можно запустить заново" '
            .'class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950/40">'
            .'<i class="fas fa-stop"></i>Остановить</button>'
            .'</form>';
    }

    $row = View::factory('partials/live_status', [
        'url'     => '/nj602/admin/status/'.urlencode($code),
        'title'   => 'Собираем статью',
        'status'  => $status,
        'stage'   => (string) ($live['stage'] ?? ''),
        'elapsed' => (int) ($live['elapsed_sec'] ?? 0),
        'silent'  => $live['silent_sec'] ?? NULL,
        'note'    => 'Текст, схема и граф появятся в карточке статьи — страница обновится сама.',
        'steps'   => (array) ($live['stages'] ?? []),
        'stop'    => $stop,
    ])->render();
}
?>
<div id="nj602-live" data-code="<?php echo HTML::chars($code); ?>">
    <?php if ($row !== ''): ?>
    <?php echo $row; ?>
    <?php else: ?>
    <p class="n2j-hint rounded-xl border border-gray-200/80 bg-gray-50/70 px-4 py-3">
        Сбор идёт — здесь появится живой ряд: спиннер, секундомер, этап воркера и «Остановить»
        (как «Собираем обзор» в трендах). Ниже — журнал последнего прогона.
    </p>
    <?php endif; ?>

    <?php if ( ! empty($with_template) AND $code !== ''): ?>
    <?php /* Ряд для мгновенного показа после «Запустить сбор» — без перезагрузки. */ ?>
    <template data-n2j-live-tpl="<?php echo HTML::chars($code); ?>">
        <?php echo View::factory('partials/live_status', [
            'url'     => '/nj602/admin/status/'.urlencode($code),
            'title'   => 'Собираем статью',
            'status'  => 'queued',
            'stage'   => '',
            'elapsed' => 0,
            'silent'  => NULL,
            'note'    => 'Текст, схема и граф появятся в карточке статьи — страница обновится сама.',
            'steps'   => [],
            'stop'    => '',
        ])->render(); ?>
    </template>
    <?php endif; ?>
</div>
