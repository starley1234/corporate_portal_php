<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Созвон по часовым поясам (nj648_timezone) — страница /timezones.
 *
 * @var array $grid    Service_Nj648_Timezone::grid()
 * @var array $now     текущее время участников
 * @var array $grouped города по регионам для выпадающего списка
 * @var int   $max     потолок участников
 */

$people = $grid['people'];
$base   = $grid['base'];
$slots  = min($max, max(count($people) + 1, 5));   // пустые места «про запас»
$base_offset = $now ? $now[0]['offset'] : 0;

$label = 'n2j-label';
$input = 'n2j-input mt-1.5 w-full font-semibold';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <!-- ======================== Форма ======================== -->
    <form method="get" action="/timezones" class="n2j-card">
        <p class="<?php echo $label; ?>">Участники <span class="normal-case text-gray-400">(первый — опорный город, по его времени строится сетка)</span></p>

        <div class="mt-1.5 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <?php for ($i = 0; $i < $slots; $i++): $current = isset($people[$i]) ? $people[$i]['code'] : ''; ?>
                <select name="p[]" aria-label="Участник <?php echo $i + 1; ?>"
                        class="n2j-input n2j-select w-full font-semibold">
                    <option value="">— не выбран —</option>
                    <?php foreach ($grouped as $region => $cities): ?>
                        <optgroup label="<?php echo HTML::chars($region); ?>">
                            <?php foreach ($cities as $code => $city): ?>
                                <option value="<?php echo HTML::chars($code); ?>" <?php echo $current === $code ? 'selected' : ''; ?>><?php echo HTML::chars($city); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                    <?php if ($current !== '' AND ! isset($grouped[$people[$i]['region']][$current])): ?>
                        <option value="<?php echo HTML::chars($current); ?>" selected><?php echo HTML::chars($people[$i]['city']); ?></option>
                    <?php endif; ?>
                </select>
            <?php endfor; ?>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <div>
                <label for="tz-date" class="<?php echo $label; ?>">Дата встречи</label>
                <input id="tz-date" type="date" name="date" value="<?php echo HTML::chars($grid['date']); ?>" class="<?php echo $input; ?>">
            </div>
            <div>
                <label for="tz-from" class="<?php echo $label; ?>">Рабочий день с</label>
                <input id="tz-from" type="number" name="from" min="0" max="23" value="<?php echo (int) $grid['from']; ?>" class="<?php echo $input; ?>">
            </div>
            <div>
                <label for="tz-to" class="<?php echo $label; ?>">до</label>
                <input id="tz-to" type="number" name="to" min="1" max="24" value="<?php echo (int) $grid['to']; ?>" class="<?php echo $input; ?>">
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="submit" class="n2j-btn-process">
                <i class="fas fa-clock"></i>Подобрать время
            </button>
            <button type="button" id="tz-copy" class="n2j-btn-secondary">
                <i class="fas fa-link"></i>Ссылка для коллег
            </button>
            <span class="text-xs text-gray-400 dark:text-gray-500">До <?php echo (int) $max; ?> участников. Летнее время и смена поясов учитываются сами.</span>
        </div>
    </form>

    <!-- ======================== Сейчас ======================== -->
    <div class="n2j-card mt-4">
        <h2 class="n2j-h3">Сейчас у участников</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($now as $idx => $n): ?>
                <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2.5 dark:border-gray-700 dark:bg-gray-900/60">
                    <div class="flex items-baseline justify-between gap-2">
                        <p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-100">
                            <?php echo HTML::chars($n['city']); ?>
                            <?php if ($idx === 0): ?><span class="n2j-badge n2j-badge-info ml-1 uppercase">опорный</span><?php endif; ?>
                        </p>
                        <p class="shrink-0 text-lg font-bold text-gray-900 tabular-nums dark:text-white"><?php echo HTML::chars($n['time']); ?></p>
                    </div>
                    <p class="n2j-hint mt-0.5">
                        <?php echo HTML::chars(date('d.m', strtotime($n['date'])).', '.$n['weekday']); ?> ·
                        <?php echo HTML::chars($n['utc']); ?>
                        <?php if ($idx > 0): ?> · <?php echo HTML::chars(Service_Nj648_Timezone::diff_text($n['offset'], $base_offset)); ?><?php endif; ?>
                        <?php if ($n['dst']): ?> · летнее время<?php endif; ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- =================== Когда созвониться =================== -->
    <div class="n2j-card mt-4">
        <h2 class="n2j-h3">
            Когда созвониться <?php echo HTML::chars(date('d.m.Y', strtotime($grid['date']))); ?>
        </h2>
        <p class="n2j-hint">
            Рабочим считается время с <?php echo (int) $grid['from']; ?>:00 до <?php echo (int) $grid['to']; ?>:00 у каждого участника;
            часы ниже — по времени города <?php echo HTML::chars($base['city']); ?>.
        </p>

        <?php if ($grid['none']): ?>
            <div class="n2j-flash n2j-flash-warn mt-3 block">
                <i class="fas fa-triangle-exclamation mr-1.5"></i>
                Общего рабочего часа нет: пояса разъехались слишком сильно. Расширьте рамки рабочего дня
                или договоритесь, кто выйдет за них — в таблице ниже видно, кому это обойдётся дешевле.
            </div>
        <?php else: ?>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php foreach ($grid['best'] as $w): ?>
                    <span class="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-sm font-semibold text-emerald-800 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200">
                        <i class="fas fa-circle-check"></i><?php echo HTML::chars($w['from'].' — '.$w['to']); ?>
                        <span class="text-xs font-normal">(<?php echo (int) $w['hours']; ?> ч)</span>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (Service_Nj648_Timezone::is_weekend($grid['date'])): ?>
            <p class="mt-3 text-xs text-amber-700 dark:text-amber-300">
                <i class="fas fa-umbrella-beach mr-1"></i>Выбранная дата — выходной день.
            </p>
        <?php endif; ?>

        <!-- Сетка суток -->
        <div class="mt-4 overflow-x-auto">
            <table class="n2j-table min-w-[32rem]">
                <thead>
                    <tr class="border-b border-gray-200 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500">
                        <th class="px-2 py-2 text-left font-semibold"><?php echo HTML::chars($base['city']); ?></th>
                        <?php foreach ($people as $idx => $p): if ($idx === 0) continue; ?>
                            <th class="px-2 py-2 text-left font-semibold"><?php echo HTML::chars($p['city']); ?></th>
                        <?php endforeach; ?>
                        <th class="px-2 py-2 text-right font-semibold">всем удобно</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($grid['hours'] as $hour): ?>
                        <tr class="<?php echo $hour['all']
                            ? 'bg-emerald-50/70 dark:bg-emerald-900/20'
                            : ''; ?>">
                            <td class="px-2 py-1.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100"><?php echo HTML::chars($hour['label']); ?></td>
                            <?php foreach ($hour['cells'] as $idx => $cell): if ($idx === 0) continue; ?>
                                <td class="px-2 py-1.5 tabular-nums <?php echo $cell['work']
                                    ? 'font-semibold text-gray-800 dark:text-gray-100'
                                    : ($cell['night'] ? 'text-gray-300 dark:text-gray-600' : 'text-gray-500 dark:text-gray-400'); ?>">
                                    <?php echo HTML::chars($cell['time']); ?><?php if ($cell['shift']): ?><span class="ml-0.5 text-xs text-gray-400"><?php echo sprintf('%+d', $cell['shift']); ?></span><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="px-2 py-1.5 text-right">
                                <?php if ($hour['all']): ?>
                                    <i class="fas fa-check text-emerald-500" title="рабочее время у всех"></i>
                                <?php else: ?>
                                    <span class="text-xs text-gray-300 dark:text-gray-600">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="n2j-hint mt-2">
            Светлым показаны часы вне рабочего дня, «+1» или «−1» рядом со временем — это уже другие сутки.
        </p>
    </div>
</div>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Как пользоваться</p>
        <ul class="mt-3 space-y-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
            <li>Первый участник — опорный: сетка строится по его часам, остальные приводятся к ним.</li>
            <li>Зелёные строки — часы, которые попадают в рабочий день сразу у всех.</li>
            <li>Нет общего окна — сдвиньте рамки рабочего дня: иногда хватает получаса.</li>
            <li>Ссылка «для коллег» несёт состав участников и дату: её можно переслать в чат.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Учитывается ли перевод стрелок?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да. Время считается по базе часовых поясов IANA, которая знает и летнее время Европы,
                    и отмену перевода стрелок в России, и переезд регионов в соседний пояс. Поэтому важна
                    именно дата встречи: в конце марта разница с Берлином меняется на час.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Моего города нет в списке
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Возьмите ближайший город того же пояса — время совпадёт. В адресе страницы и в API
                    можно указать зону напрямую: <span class="font-mono text-xs">?p[]=Asia/Tokyo</span>.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Можно получить данные программно?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: <span class="font-mono text-xs">GET /nj648/api/now?p[]=moscow&amp;p[]=Asia/Tokyo</span> —
                    текущее время, <span class="font-mono text-xs">/nj648/api/slots</span> — сетка и общие окна,
                    <span class="font-mono text-xs">/nj648/api/cities</span> — справочник кодов.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('648/script'); ?>
