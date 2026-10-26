<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница /meal/<id> — разбор одной тарелки.
 *
 * Серверная: блюда, итоги и доля дневной нормы считаются в
 * `Service_Nj651_Meal`. Доли БЖУ показаны числами и цветными чипами —
 * полос прогресса на платформе нет.
 *
 * @var array $run     строка прогона
 * @var array $data    Service_Nj651_Meal::shape()
 * @var array $live    контракт живого статус-ряда
 * @var string $hint   подсказка дожима
 * @var array $profile профиль человека
 * @var array $norm    суточная норма
 * @var float $share   доля тарелки от нормы, %
 * @var array $verdict короткие выводы
 */

$status = (string) $run['status'];
$total  = (array) $data['total'];
$cell   = 'px-3 py-2 text-right tabular-nums';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <div class="flex flex-wrap items-center gap-2">
        <a href="/meal" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">← Новая тарелка</a>
        <span class="text-xs text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $run['created_at']))); ?></span>
    </div>

    <h1 class="n2j-h1 mt-2">Что на тарелке</h1>

    <?php if (in_array($status, ['queued', 'running'], TRUE)): ?>
        <?php echo View::factory('partials/live_status', [
            'url'     => '/meal/api/status?id='.(int) $run['id'],
            'title'   => 'Смотрю тарелку',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) $live['stages'],
            'note'    => $hint !== '' ? $hint : 'Страница обновится сама, когда блюда будут распознаны.',
        ]); ?>
        <?php echo View::factory('partials/live_status_js'); ?>
    <?php elseif ($status === 'error'): ?>
        <div class="n2j-flash n2j-flash-err mt-4 block">
            <p class="font-semibold">Тарелка не распозналась</p>
            <p class="mt-1"><?php echo HTML::chars((string) $run['error'] !== '' ? $run['error'] : 'Причина не записана.'); ?></p>
            <p class="mt-2"><a href="/meal" class="font-semibold underline">Попробовать другой кадр</a></p>
        </div>
    <?php elseif ( ! $data['dishes']): ?>
        <div class="n2j-flash n2j-flash-warn mt-4 block">
            На фото не нашлось еды. Снимите тарелку сверху, при дневном свете, без лишних предметов в кадре.
        </div>
    <?php else: ?>

    <div class="mt-4 grid gap-3 sm:grid-cols-4">
        <div class="n2j-card">
            <p class="text-xs uppercase tracking-wide text-gray-400">Калорийность</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $total['kcal']; ?> <span class="text-base font-semibold">ккал</span></p>
            <p class="n2j-hint"><?php echo count($data['dishes']).' '.Service_Nj651_Meal::dishes_word(count($data['dishes'])); ?> · <?php echo (int) $total['grams']; ?> г</p>
        </div>
        <div class="n2j-card">
            <p class="text-xs uppercase tracking-wide text-gray-400">Доля дневной нормы</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo HTML::chars(Service_Nj651_Meal::pct($share)); ?></p>
            <p class="n2j-hint">норма <?php echo (int) $norm['kcal']; ?> ккал</p>
        </div>
        <div class="n2j-card">
            <p class="text-xs uppercase tracking-wide text-gray-400">Белки · жиры · углеводы</p>
            <p class="mt-1 text-lg font-bold tabular-nums text-gray-900 dark:text-white">
                <?php echo HTML::chars(number_format($total['protein'], 1, ',', ' ')); ?> ·
                <?php echo HTML::chars(number_format($total['fat'], 1, ',', ' ')); ?> ·
                <?php echo HTML::chars(number_format($total['carbs'], 1, ',', ' ')); ?> г
            </p>
            <p class="n2j-hint">
                в калориях: <?php echo HTML::chars(Service_Nj651_Meal::pct($data['balance']['protein'])); ?> ·
                <?php echo HTML::chars(Service_Nj651_Meal::pct($data['balance']['fat'])); ?> ·
                <?php echo HTML::chars(Service_Nj651_Meal::pct($data['balance']['carbs'])); ?>
            </p>
        </div>
        <div class="n2j-card">
            <p class="text-xs uppercase tracking-wide text-gray-400">Осталось на день</p>
            <?php $left = max(0, (int) $norm['kcal'] - (int) $total['kcal']); ?>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo $left; ?> <span class="text-base font-semibold">ккал</span></p>
            <p class="n2j-hint">если эта тарелка — первая за сегодня</p>
        </div>
    </div>

    <?php if ($verdict): ?>
    <ul class="mt-3 space-y-1.5 text-sm text-gray-700 dark:text-gray-200">
        <?php foreach ($verdict as $line): ?>
        <li><i class="fas fa-circle-info mr-2 text-blue-500"></i><?php echo HTML::chars($line); ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <section class="n2j-card p-0 mt-5 overflow-hidden">
        <h2 class="n2j-h3 border-b border-gray-200 px-4 py-3">Блюда на фото</h2>
        <div class="overflow-x-auto">
            <table class="n2j-table min-w-[32rem]">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Блюдо</th>
                        <th class="px-3 py-2 text-right">Вес</th>
                        <th class="px-3 py-2 text-right">Ккал</th>
                        <th class="px-3 py-2 text-right">Б</th>
                        <th class="px-3 py-2 text-right">Ж</th>
                        <th class="px-3 py-2 text-right">У</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($data['dishes'] as $dish): ?>
                    <tr>
                        <td class="px-3 py-2">
                            <span class="text-gray-900 dark:text-gray-100"><?php echo HTML::chars($dish['name']); ?></span>
                            <?php if ($dish['confidence'] < 60): ?>
                            <span class="ml-1 text-xs text-amber-600 dark:text-amber-400">под вопросом</span>
                            <?php endif; ?>
                        </td>
                        <td class="<?php echo $cell; ?> text-gray-600 dark:text-gray-300"><?php echo (int) $dish['grams']; ?> г</td>
                        <td class="<?php echo $cell; ?> font-semibold text-gray-900 dark:text-white"><?php echo (int) $dish['kcal']; ?></td>
                        <td class="<?php echo $cell; ?> text-gray-600 dark:text-gray-300"><?php echo HTML::chars(number_format($dish['protein'], 1, ',', ' ')); ?></td>
                        <td class="<?php echo $cell; ?> text-gray-600 dark:text-gray-300"><?php echo HTML::chars(number_format($dish['fat'], 1, ',', ' ')); ?></td>
                        <td class="<?php echo $cell; ?> text-gray-600 dark:text-gray-300"><?php echo HTML::chars(number_format($dish['carbs'], 1, ',', ' ')); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ((string) $data['note'] !== ''): ?>
    <p class="n2j-hint mt-3">Пометка модели: <?php echo HTML::chars($data['note']); ?></p>
    <?php endif; ?>

    <?php endif; ?>

</div>

<aside class="mt-5 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Ваш профиль</h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            <?php echo $profile['sex'] === 'm' ? 'Мужчина' : 'Женщина'; ?>,
            <?php echo (int) $profile['age']; ?> лет, <?php echo (int) $profile['weight']; ?> кг,
            <?php echo (int) $profile['height']; ?> см.<br>
            <?php echo HTML::chars(Service_Nj651_Meal::ACTIVITY[$profile['activity']][0]); ?>;
            цель — <?php echo HTML::chars(mb_strtolower(Service_Nj651_Meal::GOALS[$profile['goal']][0])); ?>.
        </p>
        <p class="n2j-hint mt-2">
            Профиль передаётся в адресе страницы: измените его на <a href="/meal" class="text-blue-600 hover:underline dark:text-blue-400">/meal</a> и снова отправьте фото.
        </p>
    </section>

    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Почему это оценка</h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            Калорийность считается по белкам, жирам и углеводам (4 · 9 · 4 ккал на грамм), а вес порции
            модель оценивает по виду. Масло в сковороде, сахар в соусе и жирность сметаны по фото не видны —
            поэтому цифры дают порядок величины, а не точный учёт.
        </p>
    </section>
</aside>
</div>
</div>
