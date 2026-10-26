<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница /plant/<id> — карточка растения.
 *
 * Вид, состояние, план ухода и календарь полива. Календарь приходит готовым
 * из `Service_Nj652_Plant::schedule()`.
 *
 * @var array  $run      строка прогона
 * @var array  $data     Service_Nj652_Plant::shape()
 * @var array  $live     контракт живого статус-ряда
 * @var string $hint     подсказка дожима
 * @var array  $schedule календарь ухода
 * @var int    $weeks    горизонт календаря в неделях
 */

$status = (string) $run['status'];
$plant  = (array) $data['plant'];
$cond   = (array) $data['condition'];
$care   = (array) $data['care'];
list($state_text, $tone) = Service_Nj652_Plant::state_label($cond['state']);

$tones = [
    'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-200',
    'amber'   => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200',
    'rose'    => 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200',
    'gray'    => 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200',
];
$row = 'flex items-baseline justify-between gap-3 border-b border-gray-100 py-2 last:border-0 dark:border-gray-700';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <div class="flex flex-wrap items-center gap-2">
        <a href="/plant" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">← Другое растение</a>
        <span class="text-xs text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $run['created_at']))); ?></span>
    </div>

    <h1 class="n2j-h1 mt-2"><?php echo HTML::chars($plant['name_ru']); ?></h1>
    <?php if ($plant['name_lat'] !== '' OR $plant['family'] !== ''): ?>
    <p class="mt-1 text-sm italic text-gray-500 dark:text-gray-400">
        <?php echo HTML::chars($plant['name_lat']); ?><?php echo ($plant['name_lat'] !== '' AND $plant['family'] !== '') ? ' · ' : ''; ?><?php echo HTML::chars($plant['family']); ?>
    </p>
    <?php endif; ?>

    <?php if (in_array($status, ['queued', 'running'], TRUE)): ?>
        <?php echo View::factory('partials/live_status', [
            'url'     => '/plant/api/status?id='.(int) $run['id'],
            'title'   => 'Рассматриваю растение',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) $live['stages'],
            'note'    => $hint !== '' ? $hint : 'Страница обновится сама, когда растение будет разобрано.',
        ]); ?>
        <?php echo View::factory('partials/live_status_js'); ?>
    <?php elseif ($status === 'error'): ?>
        <div class="n2j-flash n2j-flash-err mt-4 block">
            <p class="font-semibold">Растение не разобралось</p>
            <p class="mt-1"><?php echo HTML::chars((string) $run['error'] !== '' ? $run['error'] : 'Причина не записана.'); ?></p>
            <p class="mt-2"><a href="/plant" class="font-semibold underline">Попробовать другой кадр</a></p>
        </div>
    <?php else: ?>

    <p class="n2j-hint mt-2">
        Уверенность: <?php echo (int) $plant['confidence']; ?>% — <?php echo HTML::chars(Service_Nj652_Plant::confidence_label($plant['confidence'])); ?>.
    </p>

    <div class="mt-4 rounded-xl border p-4 <?php echo $tones[$tone]; ?>">
        <p class="text-sm font-bold"><?php echo HTML::chars($state_text); ?></p>
        <?php if ($cond['problem'] !== ''): ?>
        <p class="mt-1 text-sm"><?php echo HTML::chars($cond['problem']); ?></p>
        <?php endif; ?>
        <p class="mt-1 text-xs font-semibold uppercase tracking-wide opacity-80"><?php echo HTML::chars(Service_Nj652_Plant::severity_label($cond['severity'])); ?></p>
        <?php if ($cond['causes']): ?>
        <ul class="mt-2 space-y-1 text-sm">
            <?php foreach ($cond['causes'] as $cause): ?>
            <li>· <?php echo HTML::chars($cause); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <?php if ($data['actions']): ?>
    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Что сделать сейчас</h2>
        <ol class="mt-2 space-y-2 text-sm text-gray-700 dark:text-gray-200">
            <?php foreach ($data['actions'] as $i => $action): ?>
            <li class="flex gap-3">
                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><?php echo $i + 1; ?></span>
                <span><?php echo HTML::chars($action); ?></span>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php endif; ?>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <section class="n2j-card">
            <h2 class="n2j-h3">Уход</h2>
            <dl class="mt-2 text-sm">
                <?php
                $facts = [
                    'Свет'        => $care['light'],
                    'Полив летом' => 'раз в '.(int) $care['water_summer'].' дн.',
                    'Полив зимой' => 'раз в '.(int) $care['water_winter'].' дн.',
                    'Подкормка'   => 'раз в '.(int) $care['feed'].' дн. (не зимой)',
                    'Температура' => $care['temp'],
                    'Влажность'   => $care['humidity'],
                    'Грунт'       => $care['soil'],
                    'Пересадка'   => $care['repot'],
                ];
                ?>
                <?php foreach ($facts as $key => $value): ?>
                <?php if (trim((string) $value) === '') { continue; } ?>
                <div class="<?php echo $row; ?>">
                    <dt class="shrink-0 text-xs uppercase tracking-wide text-gray-400"><?php echo HTML::chars($key); ?></dt>
                    <dd class="text-right text-gray-900 dark:text-gray-100"><?php echo HTML::chars($value); ?></dd>
                </div>
                <?php endforeach; ?>
            </dl>
            <?php if ($care['toxic_pets']): ?>
            <p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">
                <i class="fas fa-paw mr-1"></i>Считается ядовитым для кошек и собак — держите вне досягаемости.
            </p>
            <?php endif; ?>
        </section>

        <section class="n2j-card">
            <h2 class="n2j-h3">Календарь на <?php echo (int) $weeks; ?> нед.</h2>
            <?php if ($schedule): ?>
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                <?php foreach ($schedule as $item): ?>
                <li class="flex items-center gap-3 py-2">
                    <span class="font-mono text-xs tabular-nums text-gray-500 dark:text-gray-400"><?php echo HTML::chars(date('d.m', strtotime($item['date'])).', '.$item['dow']); ?></span>
                    <span class="<?php echo $item['what'] === 'feed' ? 'text-amber-600 dark:text-amber-400' : 'text-blue-600 dark:text-blue-400'; ?>">
                        <i class="fas <?php echo $item['what'] === 'feed' ? 'fa-flask' : 'fa-droplet'; ?> mr-1"></i><?php echo HTML::chars($item['label']); ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <p class="n2j-hint mt-2">
                Даты ориентировочные: перед поливом проверьте, просох ли верхний слой земли на 2–3 см.
            </p>
            <?php else: ?>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Интервалы ухода не определились — ориентируйтесь на состояние грунта.</p>
            <?php endif; ?>
        </section>
    </div>

    <?php if ((string) $data['note'] !== ''): ?>
    <p class="n2j-hint mt-3">Пометка модели: <?php echo HTML::chars($data['note']); ?></p>
    <?php endif; ?>

    <?php endif; ?>

</div>

<aside class="mt-5 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Прежде чем лечить</h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            Самая частая причина гибели комнатных растений — не болезнь, а перелив: земля не успевает
            просыхать, корни задыхаются, листья желтеют «как от нехватки воды». Если сомневаетесь между
            «полить» и «подождать» — подождите день и проверьте грунт пальцем.
        </p>
    </section>

    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Границы сервиса</h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            Определение по фотографии — подсказка, а не диагноз. Сервис не отвечает на вопрос
            о съедобности растений и грибов и не заменяет консультацию специалиста.
        </p>
    </section>
</aside>
</div>
</div>
