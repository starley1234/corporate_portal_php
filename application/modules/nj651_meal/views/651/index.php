<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница /meal — снимок тарелки и суточная норма.
 *
 * Макет — паттерн A: слева съёмка и профиль, справа пояснения. Блок съёмки
 * общий для всех VLM-сервисов (`partials/photo_capture`).
 *
 * @var bool  $ready   подключена ли vision-модель
 * @var array $limits  пределы модуля
 * @var array $profile профиль из адреса (пол, вес, рост, возраст, активность, цель)
 * @var array $norm    суточная норма по профилю
 * @var array $mine    последние разборы посетителя
 * @var bool  $guest   гость (видит плашку входа вместо съёмки — «как опросы»)
 */

$label = 'n2j-label';
$input = 'n2j-input mt-1.5 w-full font-semibold';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <h1 class="n2j-h1">Калории по фото тарелки</h1>
    <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
        Сфотографируйте тарелку — сервис назовёт блюда, прикинет вес порций и посчитает калории,
        белки, жиры и углеводы. Это оценка порядка величины (±20–30 %): по фото не видно,
        сколько масла попало на сковороду.
    </p>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <?php if ( ! empty($guest)): ?>
        <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-5 text-center dark:border-blue-800 dark:bg-blue-900/30">
            <i class="fas fa-camera text-xl text-blue-600 dark:text-blue-300" aria-hidden="true"></i>
            <p class="mt-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Разбор тарелки — после быстрого входа</p>
            <p class="n2j-hint mx-auto max-w-md">
                Аккаунт бесплатный и нужен, чтобы очередь распознавания и время модели не расходовал кто попало.
                Суточную норму можно посчитать и без входа — форма справа никуда не денется.
            </p>
            <a href="/auth/login?return_url=meal" class="n2j-btn-process mt-3">Войти или зарегистрироваться</a>
        </div>
        <?php else: ?>
        <?php echo View::factory('partials/photo_capture', [
            'id'     => 'nj651',
            'api'    => '/meal/api',
            'title'  => 'Фото тарелки',
            'button' => 'Посчитать калории',
            'live'   => 'Смотрю тарелку',
            'hint'   => 'Снимайте сверху, чтобы были видны все блюда',
            'max_mb' => (int) ($limits['photo_mb'] ?? 8),
            'ready'  => $ready,
        ]); ?>
        <?php endif; ?>

        <section class="n2j-card">
            <h2 class="n2j-h3">Ваша суточная норма</h2>

            <form method="get" action="/meal" class="mt-3 grid grid-cols-2 gap-3">
                <div>
                    <label for="ml-sex" class="<?php echo $label; ?>">Пол</label>
                    <select id="ml-sex" name="sex" class="n2j-input n2j-select mt-1.5 w-full font-semibold">
                        <option value="f" <?php echo $profile['sex'] === 'f' ? 'selected' : ''; ?>>женский</option>
                        <option value="m" <?php echo $profile['sex'] === 'm' ? 'selected' : ''; ?>>мужской</option>
                    </select>
                </div>
                <div>
                    <label for="ml-age" class="<?php echo $label; ?>">Возраст</label>
                    <input id="ml-age" name="age" inputmode="numeric" value="<?php echo (int) $profile['age']; ?>" class="<?php echo $input; ?>">
                </div>
                <div>
                    <label for="ml-weight" class="<?php echo $label; ?>">Вес, кг</label>
                    <input id="ml-weight" name="weight" inputmode="numeric" value="<?php echo (int) $profile['weight']; ?>" class="<?php echo $input; ?>">
                </div>
                <div>
                    <label for="ml-height" class="<?php echo $label; ?>">Рост, см</label>
                    <input id="ml-height" name="height" inputmode="numeric" value="<?php echo (int) $profile['height']; ?>" class="<?php echo $input; ?>">
                </div>
                <div class="col-span-2">
                    <label for="ml-activity" class="<?php echo $label; ?>">Активность</label>
                    <select id="ml-activity" name="activity" class="n2j-input n2j-select mt-1.5 w-full font-semibold">
                        <?php foreach (Service_Nj651_Meal::ACTIVITY as $key => $row): ?>
                        <option value="<?php echo $key; ?>" <?php echo $profile['activity'] === $key ? 'selected' : ''; ?>><?php echo HTML::chars($row[0]); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-span-2">
                    <span class="<?php echo $label; ?>">Цель</span>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        <?php foreach (Service_Nj651_Meal::GOALS as $key => $row): ?>
                        <label class="inline-flex min-h-[36px] cursor-pointer items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600 dark:text-gray-200">
                            <input type="radio" name="goal" value="<?php echo $key; ?>" <?php echo $profile['goal'] === $key ? 'checked' : ''; ?>>
                            <?php echo HTML::chars($row[0]); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-span-2">
                    <button type="submit" class="n2j-btn-secondary w-full">
                        Пересчитать норму
                    </button>
                </div>
            </form>

            <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                    <dt class="text-xs text-gray-400">Норма в день</dt>
                    <dd class="font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $norm['kcal']; ?> ккал</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                    <dt class="text-xs text-gray-400">Основной обмен</dt>
                    <dd class="font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $norm['bmr']; ?> ккал</dd>
                </div>
                <div class="col-span-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                    Белки <?php echo (int) $norm['protein']; ?> г · жиры <?php echo (int) $norm['fat']; ?> г · углеводы <?php echo (int) $norm['carbs']; ?> г
                </div>
            </dl>
            <p class="n2j-hint mt-2">
                Формула Миффлина — Сан-Жеора. Ничего не сохраняется: профиль живёт в адресе страницы.
            </p>
        </section>
    </div>

    <?php if ($flash !== ''): ?>
    <p role="status" class="n2j-flash n2j-flash-info mt-4 block">
        <?php echo HTML::chars($flash); ?>
    </p>
    <?php endif; ?>

    <?php if ($mine): ?>
    <section class="n2j-card mt-5">
        <div class="flex items-end justify-between gap-3">
            <div>
                <h2 class="n2j-h3">Мои тарелки</h2>
                <p class="n2j-hint mt-0.5">Последние разборы с превью, весом и КБЖУ</p>
            </div>
            <span class="n2j-badge n2j-badge-wait"><?php echo count($mine); ?></span>
        </div>
        <ul class="mt-3 grid gap-3 sm:grid-cols-2">
            <?php foreach ($mine as $row): ?>
            <?php
                $id       = (int) $row['id'];
                $meal     = (array) ($row['meal'] ?? []);
                $total    = (array) ($meal['total'] ?? []);
                $dishes   = (array) ($meal['dishes'] ?? []);
                $names    = array_slice(array_column($dishes, 'name'), 0, 2);
                $status   = (string) ($row['status'] ?? '');
                $status_ui = [
                    'queued' => 'В очереди', 'running' => 'Распознаётся',
                    'done' => 'Готово', 'error' => 'Не получилось', 'cancelled' => 'Остановлено',
                ][$status] ?? 'Разбор';
                $profile_suffix = trim((string) $profile_query) !== '' ? '?'.HTML::chars((string) $profile_query) : '';
                $deletable = ! in_array($status, ['queued', 'running'], TRUE);
            ?>
            <li class="relative min-w-0 overflow-visible rounded-xl border border-gray-200 bg-gray-50 p-2.5 transition hover:border-blue-300 hover:bg-white dark:border-gray-700 dark:bg-gray-900 dark:hover:border-blue-700 dark:hover:bg-gray-800">
                <div class="flex min-w-0 items-start gap-3">
                    <a href="/meal/<?php echo $id.$profile_suffix; ?>" class="flex min-w-0 flex-1 gap-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" aria-label="Открыть разбор: <?php echo HTML::chars(trim((string) $row['title']) !== '' ? $row['title'] : 'Тарелка №'.$id); ?>">
                        <?php if ((string) ($row['preview_url'] ?? '') !== ''): ?>
                        <img src="<?php echo HTML::chars($row['preview_url']); ?>" alt="Фото тарелки" loading="lazy" class="h-20 w-20 shrink-0 rounded-lg bg-gray-200 object-cover dark:bg-gray-700">
                        <?php else: ?>
                        <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-500 dark:bg-gray-700 dark:text-amber-300" aria-hidden="true"><i class="fas fa-utensils text-xl"></i></span>
                        <?php endif; ?>
                        <span class="min-w-0 flex-1 py-0.5">
                            <span class="flex flex-wrap items-center gap-1.5">
                                <span class="truncate text-sm font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars(trim((string) $row['title']) !== '' ? $row['title'] : 'Тарелка №'.$id); ?></span>
                                <span class="n2j-badge n2j-badge-info"><?php echo HTML::chars($status_ui); ?></span>
                            </span>
                            <span class="mt-1 block text-xs text-gray-400"><?php echo HTML::chars(date('d.m.Y · H:i', strtotime((string) $row['created_at']))); ?></span>
                            <?php if ((int) ($total['kcal'] ?? 0) > 0): ?>
                            <span class="mt-1.5 flex flex-wrap gap-x-2 text-xs font-semibold tabular-nums text-gray-700 dark:text-gray-200">
                                <span><?php echo (int) $total['kcal']; ?> ккал</span><span class="font-normal text-gray-400">·</span><span><?php echo (int) ($total['grams'] ?? 0); ?> г</span><span class="font-normal text-gray-400">·</span><span><?php echo count($dishes); ?> <?php echo HTML::chars(Service_Nj651_Meal::dishes_word(count($dishes))); ?></span>
                            </span>
                            <span class="mt-1 flex flex-wrap gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                                <span>Б <?php echo number_format((float) ($total['protein'] ?? 0), 1, ',', ' '); ?> г</span>
                                <span>Ж <?php echo number_format((float) ($total['fat'] ?? 0), 1, ',', ' '); ?> г</span>
                                <span>У <?php echo number_format((float) ($total['carbs'] ?? 0), 1, ',', ' '); ?> г</span>
                            </span>
                            <span class="mt-1 block truncate text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(implode(' · ', $names)); ?></span>
                            <?php elseif ((string) ($row['error'] ?? '') !== ''): ?>
                            <span class="mt-1 block line-clamp-2 text-xs text-rose-600 dark:text-rose-300"><?php echo HTML::chars((string) $row['error']); ?></span>
                            <?php endif; ?>
                        </span>
                    </a>

                    <?php if ($deletable): ?>
                    <details class="group relative shrink-0">
                        <summary class="flex h-9 w-9 cursor-pointer list-none items-center justify-center rounded-lg text-gray-400 transition hover:bg-rose-50 hover:text-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-500 dark:hover:bg-rose-950/40" aria-label="Удалить эту тарелку" title="Удалить">
                            <i class="fas fa-trash-alt text-sm" aria-hidden="true"></i>
                        </summary>
                        <form method="post" action="/meal/<?php echo $id; ?>/delete" class="absolute right-0 top-10 z-10 w-48 rounded-xl border border-rose-200 bg-white p-3 shadow-lg dark:border-rose-800 dark:bg-gray-800">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="confirm" value="1">
                            <p class="text-xs font-medium text-gray-700 dark:text-gray-200">Удалить разбор и фото-превью?</p>
                            <button type="submit" class="n2j-btn-danger n2j-btn-sm mt-2 w-full">Да, удалить</button>
                        </form>
                    </details>
                    <?php else: ?>
                    <button type="button" disabled class="n2j-btn-icon shrink-0 cursor-not-allowed" aria-label="Нельзя удалить до окончания распознавания" title="Удалить можно после завершения"><i class="fas fa-trash-alt text-sm" aria-hidden="true"></i></button>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-3 space-y-3 text-sm text-gray-600 dark:text-gray-300">
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Насколько это точно?</p>
                <p class="mt-1">Это оценка. Модель видит блюдо и его размер, но не знает, сколько масла ушло
                    на жарку и сколько сахара в соусе. Ошибка в 20–30 % — норма, и для контроля рациона этого
                    обычно достаточно. Точные цифры даёт только взвешивание продуктов.</p>
            </div>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Откуда берётся дневная норма?</p>
                <p class="mt-1">Формула Миффлина — Сан-Жеора: основной обмен по полу, весу, росту и возрасту,
                    умноженный на коэффициент активности и на цель (минус 15 % при снижении веса, плюс 10 % при наборе).</p>
            </div>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Фото сохраняется?</p>
                <p class="mt-1">Оригинальный кадр удаляется после разбора. Для плитки истории может храниться
                    небольшая приватная миниатюра до 90 дней; в базе остаются только блюда и числа.</p>
            </div>
        </div>
    </section>

</div>

<aside class="mt-5 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Чтобы счёт был точнее</h2>
        <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
            <li>· Снимайте сверху под углом 45–90°: так видно и площадь, и высоту горки.</li>
            <li>· Положите рядом вилку или телефон — модели проще оценить размер порции.</li>
            <li>· Соусы и масло назовите в подписи к блюду, если они не видны.</li>
            <li>· Одна тарелка — один кадр: по фото всего стола порции не разделить.</li>
        </ul>
    </section>

    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Важно</h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            Это справочный расчёт, а не медицинская рекомендация. При диабете, болезнях почек и назначенной
            врачом диете ориентируйтесь на предписания врача, а не на оценку по фотографии.
        </p>
    </section>

</aside>
</div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>
<?php echo View::factory('partials/photo_capture_js'); ?>
<?php echo View::factory('651/script', ['profile' => $profile]); ?>
