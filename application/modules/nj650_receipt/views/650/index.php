<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница /receipt — снимок чека.
 *
 * Макет — паттерн A платформы: слева главный блок (съёмка и последние
 * разборы), справа колонка пояснений. Блок съёмки общий для всех сервисов с
 * vision-моделью (`partials/photo_capture`), поэтому выглядит и ведёт себя
 * одинаково на /receipt, /meal и /plant.
 *
 * @var bool  $ready  подключена ли vision-модель
 * @var array $limits пределы модуля
 * @var array $mine   последние разборы посетителя
 * @var bool  $guest  гость (видит плашку входа вместо съёмки — «как опросы»)
 */

$mb = (int) ($limits['photo_mb'] ?? 8);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <h1 class="n2j-h1">Чек по фото</h1>
    <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
        Сфотографируйте чек — сервис прочитает позиции и цены, сверит их сумму с итогом на чеке,
        разложит траты по категориям и поделит счёт между теми, кто сидел за столом. Фото не остаётся
        на сервере: кадр удаляется сразу после разбора.
    </p>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <?php if ( ! empty($guest)): ?>
        <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-5 text-center dark:border-blue-800 dark:bg-blue-900/30">
            <i class="fas fa-camera text-xl text-blue-600 dark:text-blue-300" aria-hidden="true"></i>
            <p class="mt-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Разбор чека — после быстрого входа</p>
            <p class="n2j-hint mx-auto max-w-md">
                Аккаунт бесплатный и нужен, чтобы очередь распознавания и время модели не расходовал кто попало.
                Ваши разборы потом видны с любого устройства — история следует за аккаунтом.
            </p>
            <a href="/auth/login?return_url=receipt" class="n2j-btn-process mt-3">Войти или зарегистрироваться</a>
        </div>
        <?php else: ?>
        <?php echo View::factory('partials/photo_capture', [
            'id'     => 'nj650',
            'api'    => '/receipt/api',
            'title'  => 'Фото чека',
            'button' => 'Прочитать чек',
            'live'   => 'Читаю чек',
            'hint'   => 'Держите чек ровно, снимайте весь столбец с ценами',
            'max_mb' => $mb,
            'ready'  => $ready,
        ]); ?>
        <?php endif; ?>

        <section class="n2j-card">
            <h2 class="n2j-h3">Что получится</h2>
            <ul class="mt-2 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <li><i class="fas fa-list-ul mr-2 text-blue-500"></i>Позиции с количеством, ценой и суммой — таблицей.</li>
                <li><i class="fas fa-scale-balanced mr-2 text-blue-500"></i>Сверка: сходится ли сумма позиций с итогом чека.</li>
                <li><i class="fas fa-chart-pie mr-2 text-blue-500"></i>Траты по категориям: продукты, кафе, аптека, бытовое.</li>
                <li><i class="fas fa-users mr-2 text-blue-500"></i>Дележ счёта на компанию, с чаевыми и без потерянных копеек.</li>
            </ul>
            <p class="n2j-hint mt-3 rounded-lg bg-gray-50 px-3 py-2">
                Считает сервер, а не модель: она только читает строки с бумажки. Поэтому суммы сходятся,
                а расхождение с итогом видно сразу.
            </p>
        </section>
    </div>

    <?php if ($mine): ?>
    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Мои чеки</h2>
        <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
            <?php foreach ($mine as $row): ?>
            <?php
                $result   = (array) ($row['result'] ?? []);
                $merchant = trim((string) ($result['merchant'] ?? ''));
                $total    = (float) ($result['total'] ?? 0);
            ?>
            <li class="flex flex-wrap items-center gap-2 py-2">
                <a href="/receipt/<?php echo (int) $row['id']; ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">
                    <?php echo HTML::chars($merchant !== '' ? $merchant : 'Чек №'.(int) $row['id']); ?>
                </a>
                <span class="text-xs text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $row['created_at']))); ?></span>
                <?php if ((string) $row['status'] === 'done' AND $total > 0): ?>
                <span class="ml-auto font-mono text-xs font-semibold tabular-nums text-gray-700 dark:text-gray-200">
                    <?php echo HTML::chars(Service_Nj650_Receipt::money($total)); ?>
                </span>
                <?php elseif ((string) $row['status'] === 'error'): ?>
                <span class="ml-auto text-xs text-rose-600 dark:text-rose-400">не удалось прочитать</span>
                <?php else: ?>
                <span class="ml-auto text-xs text-gray-400">в работе</span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="n2j-hint mt-2">
            История следует за аккаунтом (и этим браузером), очищается через 90 дней.
        </p>
    </section>
    <?php endif; ?>

    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Частые вопросы</h2>

        <div class="mt-3 space-y-3 text-sm text-gray-600 dark:text-gray-300">
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Фото куда-то сохраняется?</p>
                <p class="mt-1">Кадр лежит в приватном каталоге ровно столько, сколько идёт распознавание,
                    и удаляется сразу после разбора. В базе остаются только строки чека, а они очищаются через 90 дней.</p>
            </div>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Почему сумма позиций может не сойтись с итогом?</p>
                <p class="mt-1">Либо на чеке была скидка или возврат, либо строка прочиталась неверно: смазанный кадр,
                    блик, загнутый край. Сервис показывает расхождение прямо под таблицей, а не прячет его.</p>
            </div>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Как поделить счёт, если не все ели одно и то же?</p>
                <p class="mt-1">Откройте разбор чека и укажите число участников: доли считаются в копейках,
                    поэтому их сумма ВСЕГДА равна итогу. Параметры дележа попадают в адрес страницы — ссылку
                    можно переслать тем, с кем делите: получателю достаточно бесплатного входа.</p>
            </div>
        </div>
    </section>

</div>

<aside class="mt-5 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Чтобы чек прочитался</h2>
        <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
            <li>· Снимайте сверху, без наклона: строки должны идти горизонтально.</li>
            <li>· Разложите чек на ровной поверхности, придержите края.</li>
            <li>· Следите, чтобы в кадр попал столбец сумм и строка «Итог».</li>
            <li>· Длинный чек лучше снять двумя кадрами и прочитать по очереди.</li>
            <li>· Выключенная вспышка и тень от телефона — частая причина бликов.</li>
        </ul>
    </section>

    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Как это устроено</h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            Кадр уходит в очередь задач, воркер показывает его vision-модели (VLM — модель, которая «видит»
            картинку) и просит вернуть строгий список строк. Дальше считает уже сервер: суммы, сверка,
            категории и дележ. Ход работы виден этапами и секундомером.
        </p>
    </section>

    
</aside>
</div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>
<?php echo View::factory('partials/photo_capture_js'); ?>
<?php echo View::factory('650/script'); ?>
