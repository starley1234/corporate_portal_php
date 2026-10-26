<?php defined('SYSPATH') or die('No direct script access.');
/** @var array $devices @var array $units @var array $form @var bool $owner_ok @var string $csrf */
$label = 'n2j-label';
$input = 'n2j-input mt-1.5 w-full';
$device_count = count($devices);
if ($device_count === 1)
{
    $devices_word = 'счётчик';
}
elseif ($device_count >= 2 AND $device_count <= 4)
{
    $devices_word = 'счётчика';
}
else
{
    $devices_word = 'счётчиков';
}
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-gauge-high text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Показания счётчиков: расход и прогноз</h1>
            <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Не просто записывайте цифры: сервис считает расход между реальными датами, стоимость по тарифу, месячный ориентир и замечает необычный скачок темпа.</p>
        </div>
        <span class="n2j-badge n2j-badge-info"><i class="fas fa-database" aria-hidden="true"></i>Личная история</span>
    </div>

    <?php if ($deleted): ?>
        <p class="n2j-flash n2j-flash-ok mt-5 block">Счётчик и все его показания удалены.</p>
    <?php endif; ?>

    <div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <main class="min-w-0">
            <section class="n2j-card" aria-labelledby="meters-list-title">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div><h2 id="meters-list-title" class="n2j-h3">Мои счётчики</h2><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Данные видны только в этом браузере или в вашей учётной записи.</p></div>
                    <span class="n2j-badge n2j-badge-wait"><?php echo $device_count; ?> <?php echo $devices_word; ?></span>
                </div>
                <?php if ( ! $devices): ?>
                    <div class="mt-5 rounded-xl border border-dashed border-blue-200 bg-blue-50/60 p-6 text-center dark:border-blue-900 dark:bg-blue-950/20"><i class="fas fa-droplet text-2xl text-blue-500" aria-hidden="true"></i><p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100">История начинается с первого счётчика</p><p class="n2j-hint mx-auto max-w-md">Например: «Холодная вода», «Электричество» или «Тепло». После двух показаний появится фактический расход, а не приблизительный калькулятор.</p></div>
                <?php else: ?>
                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <?php foreach ($devices as $device): ?>
                            <a href="/meters/<?php echo HTML::chars($device['code']); ?>" class="group rounded-xl border border-gray-200 p-4 transition hover:border-blue-400 hover:shadow-xs dark:border-gray-700 dark:hover:border-blue-700">
                                <div class="flex items-start gap-3"><span class="mt-0.5 rounded-lg bg-blue-50 p-2 text-blue-700 dark:bg-blue-950 dark:text-blue-300"><i class="fas fa-gauge" aria-hidden="true"></i></span><div class="min-w-0 flex-1"><p class="truncate font-semibold text-gray-900 group-hover:text-blue-700 dark:text-white dark:group-hover:text-blue-300"><?php echo HTML::chars($device['title']); ?></p><p class="n2j-hint mt-0.5">Тариф <?php echo Service_Nj656_Meters::rub($device['tariff']); ?> за <?php echo HTML::chars(Service_Nj656_Meters::unit_title($device['unit'])); ?></p></div><i class="fas fa-chevron-right pt-1 text-xs text-gray-300 group-hover:text-blue-500" aria-hidden="true"></i></div>
                                <?php if ($device['latest_on'] !== NULL): ?><p class="n2j-hint mt-4">Последнее: <b class="text-gray-800 dark:text-gray-100"><?php echo Service_Nj656_Meters::fmt($device['latest_value']); ?> <?php echo HTML::chars(Service_Nj656_Meters::unit_title($device['unit'])); ?></b> · <?php echo HTML::chars(date('d.m.Y', strtotime($device['latest_on']))); ?></p><?php else: ?><p class="mt-4 text-xs text-amber-700 dark:text-amber-300">Показаний ещё нет</p><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>

        <aside class="space-y-4">
            <section class="n2j-card border-blue-200 dark:border-blue-900/60" aria-labelledby="meters-create-title">
                <h2 id="meters-create-title" class="n2j-h3">Добавить счётчик</h2>
                <p class="n2j-hint">Не указывайте адрес, номер квартиры или имя: сервис их не запрашивает и для расчёта они не нужны.</p>
                <?php if ($error): ?><p class="n2j-flash n2j-flash-err mt-3 block"><?php echo HTML::chars($error); ?></p><?php endif; ?>
                <?php if ( ! $owner_ok): ?><p class="n2j-flash n2j-flash-warn mt-3 block">Разрешите технические cookie для личного журнала. Без них сервис не создаёт общую историю, чтобы не смешивать данные разных людей.</p><?php else: ?>
                <form method="post" action="/meters" class="mt-4 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div><label for="meters-title" class="<?php echo $label; ?>">Как назвать</label><input id="meters-title" name="title" maxlength="60" required placeholder="Например, холодная вода" value="<?php echo HTML::chars($form['title']); ?>" class="<?php echo $input; ?>"></div>
                    <div><label for="meters-unit" class="<?php echo $label; ?>">Единица</label><select id="meters-unit" name="unit" class="n2j-input n2j-select mt-1.5 w-full"><?php foreach ($units as $code => $unit): ?><option value="<?php echo HTML::chars($code); ?>" <?php echo $form['unit'] === $code ? 'selected' : ''; ?>><?php echo HTML::chars($unit['title'].' — '.$unit['hint']); ?></option><?php endforeach; ?></select></div>
                    <div><label for="meters-tariff" class="<?php echo $label; ?>">Тариф, ₽ за единицу</label><input id="meters-tariff" name="tariff" required inputmode="decimal" placeholder="Например, 58,72" value="<?php echo HTML::chars($form['tariff']); ?>" class="<?php echo $input; ?>"></div>
                    <div><label for="meters-budget" class="<?php echo $label; ?>">Месячный ориентир, ₽ <span class="normal-case font-normal">необязательно</span></label><input id="meters-budget" name="budget_rub" inputmode="decimal" placeholder="Например, 600" value="<?php echo HTML::chars($form['budget_rub']); ?>" class="<?php echo $input; ?>"></div>
                    <button type="submit" class="n2j-btn-cta w-full"><i class="fas fa-plus" aria-hidden="true"></i>Создать журнал</button>
                </form>
                <?php endif; ?>
            </section>
            <section class="n2j-card"><h2 class="n2j-h3">Почему это полезнее разового расчёта</h2><ol class="mt-3 space-y-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><li><b class="text-blue-700 dark:text-blue-300">1.</b> Расход считается по вашим двум датам и фактической разнице показаний.</li><li><b class="text-blue-700 dark:text-blue-300">2.</b> Тариф сохраняется в истории — смена цены не переписывает прошлые периоды.</li><li><b class="text-blue-700 dark:text-blue-300">3.</b> Прогноз и сравнение с прежним темпом помогают заметить утечку или ошибку ввода.</li></ol></section>
        </aside>
    </div>
</div>
