<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница /plant — снимок растения.
 *
 * Макет — паттерн A; блок съёмки общий (`partials/photo_capture`).
 *
 * @var bool  $ready  подключена ли vision-модель
 * @var array $limits пределы модуля
 * @var array $mine   последние разборы посетителя
 * @var bool  $guest  гость (видит плашку входа вместо съёмки — «как опросы»)
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <h1 class="n2j-h1">Растение по фото</h1>
    <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
        Сфотографируйте растение — сервис определит вид, скажет, что с ним не так (пятна, вредители,
        перелив или сухой воздух), и соберёт план ухода: свет, полив, подкормки, пересадка.
        Календарь полива считается на сервере и учитывает зимний режим.
    </p>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <?php if ( ! empty($guest)): ?>
        <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-5 text-center dark:border-blue-800 dark:bg-blue-900/30">
            <i class="fas fa-camera text-xl text-blue-600 dark:text-blue-300" aria-hidden="true"></i>
            <p class="mt-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Разбор растения — после быстрого входа</p>
            <p class="n2j-hint mx-auto max-w-md">
                Аккаунт бесплатный и нужен, чтобы очередь распознавания и время модели не расходовал кто попало.
                Что получится и как снимать — прямо на этой странице, без входа.
            </p>
            <a href="/auth/login?return_url=plant" class="n2j-btn-process mt-3">Войти или зарегистрироваться</a>
        </div>
        <?php else: ?>
        <?php echo View::factory('partials/photo_capture', [
            'id'     => 'nj652',
            'api'    => '/plant/api',
            'title'  => 'Фото растения',
            'button' => 'Определить растение',
            'live'   => 'Рассматриваю растение',
            'hint'   => 'Снимите лист крупно, а потом — растение целиком',
            'max_mb' => (int) ($limits['photo_mb'] ?? 8),
            'ready'  => $ready,
        ]); ?>
        <?php endif; ?>

        <section class="n2j-card">
            <h2 class="n2j-h3">Что получится</h2>
            <ul class="mt-2 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <li><i class="fas fa-leaf mr-2 text-emerald-500"></i>Вид растения по-русски и по-латыни, с честной оценкой уверенности.</li>
                <li><i class="fas fa-bug mr-2 text-emerald-500"></i>Что видно на фото: пятна, вредители, пересушка, перелив.</li>
                <li><i class="fas fa-list-check mr-2 text-emerald-500"></i>Что сделать в ближайшие дни — короткий список действий.</li>
                <li><i class="fas fa-calendar-days mr-2 text-emerald-500"></i>Календарь полива и подкормок на месяц вперёд.</li>
                <li><i class="fas fa-paw mr-2 text-emerald-500"></i>Пометка, если растение опасно для кошек и собак.</li>
            </ul>
        </section>
    </div>

    <?php if ($mine): ?>
    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Мои растения</h2>
        <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
            <?php foreach ($mine as $row): ?>
            <li class="flex flex-wrap items-center gap-2 py-2">
                <a href="/plant/<?php echo (int) $row['id']; ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">
                    <?php echo HTML::chars(trim((string) $row['title']) !== '' ? $row['title'] : 'Растение №'.(int) $row['id']); ?>
                </a>
                <span class="text-xs text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $row['created_at']))); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-3 space-y-3 text-sm text-gray-600 dark:text-gray-300">
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Можно ли доверять определению вида?</p>
                <p class="mt-1">Как подсказке. Сервис показывает, насколько модель уверена, и при низкой
                    уверенности честно просит переснять. Прежде чем лечить растение «по рецепту», сверьтесь
                    со справочником — у похожих видов уход различается.</p>
            </div>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">А съедобные грибы и ягоды определять можно?</p>
                <p class="mt-1">Нет. Сервис намеренно не отвечает на вопрос «можно ли это съесть»:
                    ошибка стоит слишком дорого, а по фотографии отличить двойников нельзя.</p>
            </div>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Откуда берётся календарь полива?</p>
                <p class="mt-1">Из интервалов ухода: сервер строит даты сам и зимой растягивает полив,
                    а подкормки в зимние месяцы не назначает вовсе — это самая частая причина загубленных
                    комнатных растений.</p>
            </div>
        </div>
    </section>

</div>

<aside class="mt-5 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Как снимать</h2>
        <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
            <li>· Дневной свет без вспышки: цвет листа — главный признак.</li>
            <li>· Проблемный лист — крупным планом, с обеих сторон.</li>
            <li>· Один кадр — одно растение, без пёстрого фона.</li>
            <li>· Полезно, чтобы в кадр попал горшок: по нему видно размер.</li>
        </ul>
    </section>

    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Важно</h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            Сервис не определяет съедобность и не заменяет агронома. Для ядовитых растений показывается
            пометка об опасности для животных — но проверяйте её по справочнику, прежде чем ставить
            горшок там, где до него дотянется кот.
        </p>
    </section>

</aside>
</div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>
<?php echo View::factory('partials/photo_capture_js'); ?>
<?php echo View::factory('652/script'); ?>
