<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Личный кабинет (/dashboard): профиль + «Мои материалы» у всех,
 * системный дашборд — у администратора. Показатели кликабельны.
 */
$card   = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$num    = 'mt-1 text-2xl font-extrabold text-gray-900 dark:text-white';
$lbl    = 'text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500';

/* Кликабельная карточка-показатель */
$stat = function (string $href, string $label, string $value, string $sub = '', string $extra = '', string $icon = 'fas fa-chart-simple') use ($card, $num, $lbl) {
    $ico_tint = (str_contains($extra, 'text-red') ? 'bg-rose-50 text-rose-500 dark:bg-rose-950/40' : (str_contains($extra, 'text-emerald') ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40' : 'bg-blue-50 text-blue-600 dark:bg-blue-900/40'));

    return '<a href="'.$href.'" class="block transition hover:border-blue-300 hover:shadow-sm dark:hover:border-blue-700 '.$card.'">'
        .'<div class="flex items-center justify-between">'
        .'<span class="'.$lbl.'">'.$label.'</span>'
        .'<span class="inline-flex h-8 w-8 items-center justify-center rounded-lg '.$ico_tint.'"><i class="'.$icon.' text-sm"></i></span>'
        .'</div>'
        .'<p class="'.$num.$extra.'">'.$value.'</p>'
        .($sub !== '' ? '<p class="text-xs text-gray-400 dark:text-gray-500">'.$sub.'</p>' : '')
        .'</a>';
};

$status_ru = function ($st) {
    $map = ['done' => 'готово', 'error' => 'ошибка', 'failed' => 'ошибка',
            'running' => 'идёт', 'processing' => 'выполняется', 'new' => 'в очереди',
            'pending' => 'в очереди', 'cancelled' => 'остановлено'];

    return $map[(string) $st] ?? (string) $st;
};

$status_tint = function ($st) {
    $st = (string) $st;
    if ($st === 'done') return 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60';
    if (in_array($st, ['error', 'failed'], TRUE)) return 'bg-rose-50 text-rose-500 dark:bg-rose-950/60';
    if (in_array($st, ['running', 'processing', 'new', 'pending'], TRUE)) return 'bg-blue-50 text-blue-600 dark:bg-blue-950/60';
    if ($st === 'cancelled') return 'bg-gray-100 text-gray-500 dark:bg-gray-800';
    return 'bg-gray-100 text-gray-500 dark:bg-gray-800';
};

?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Личный кабинет</span>
    </nav>
    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1"><i class="fas fa-user mr-2 text-blue-500"></i>Личный кабинет</h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs"><i class="fas fa-gauge-high text-[11px]"></i>Сводка</span>
    </div>

    <!-- ===================== Кабинет: профиль ===================== -->
    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="<?php echo $card; ?> sm:col-span-2">
            <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50 text-lg font-black text-blue-600 dark:bg-blue-900/40 dark:text-blue-300" title="<?php echo HTML::chars($me->username); ?>"><?php echo HTML::chars(mb_strtoupper(mb_substr((string) $me->username, 0, 1))); ?></span>
                <div class="min-w-0">
                    <p class="text-base font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($me->username); ?></p>
                    <p class="n2j-hint"><?php echo HTML::chars($me->email); ?></p>
                </div>
                <?php if ($is_admin): ?><span class="n2j-badge n2j-badge-info uppercase">admin</span><?php endif; ?>
                <div class="ml-auto flex flex-wrap gap-2">
                    <a href="/tasks" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-list-check mr-1.5"></i>Мои задачи</a>
                    <a href="/guide" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-map-location-dot mr-1.5"></i>Путеводитель</a>
                </div>
            </div>
            <p class="n2j-hint mt-3 border-t border-gray-100 pt-2.5">Входов: <b><?php echo (int) $me->logins; ?></b> · последний: <?php $ll = (string) $me->last_login; echo ($ll !== '' AND $ll !== '0') ? HTML::chars(Date::formatted_time(ctype_digit($ll) ? '@'.(int) $ll : $ll, 'd.m.Y H:i')) : '—'; ?></p>
        </div>

        <?php if ( ! empty($reco)): /* nj600: персональные рекомендации вместо статичного списка */ ?>
        <?php echo $reco; ?>
        <?php else: ?>
        <div class="<?php echo $card; ?>">
            <p class="<?php echo $lbl; ?>">Быстрый старт</p>
            <ul class="mt-2 space-y-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                <li><a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/docs/wizard">Пакет документов организации</a> — по ОГРН за минуты</li>
                <li><a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/poll">Опрос для коллег</a> — ссылка и QR</li>
                <li><a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/orgs">Справочник организаций</a> — реквизиты ЕГРЮЛ</li>
                <li><a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/3d">3D-модель по описанию</a> — словами в чертёж</li>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===================== Кабинет: мои материалы ===================== -->
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <div class="min-w-0 md:col-span-2">
            <div class="<?php echo $card; ?>">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3 dark:border-gray-700/60">
                    <h2 class="n2j-h3">Мои материалы</h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($my); ?></span>
                </div>
                <?php if (empty($my)): ?>
                <p class="n2j-hint mt-3">Пока ничего не создано. Начните с «Быстрого старта» справа — результаты появятся здесь.</p>
                <?php else: ?>
                <ul class="mt-2 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($my as $it): ?>
                    <li class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                        <a href="<?php echo HTML::chars($it['base'].( ! empty($it['code']) && $it['base'] !== '/nj530' ? $it['code'] : '')); ?>" class="flex flex-wrap items-center gap-2 px-1 py-2.5">
                            <i class="<?php echo HTML::chars((string) $it['ico']); ?> w-5 text-center text-xs text-blue-500"></i>
                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) ($it['t'] ?: $it['code'])); ?></span>
                            <?php if ( ! empty($it['status'])): ?><span class="rounded-full px-2 py-0.5 text-xs font-bold uppercase <?php echo $status_tint($it['status']); ?>"><?php echo HTML::chars($status_ru($it['status'])); ?></span><?php endif; ?>
                            <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $it['created_at'], 'd.m.y H:i')); ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="hidden space-y-4 md:block">
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Кабинет — это зеркало</p>
                <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-gray-600 dark:text-gray-300">
                    <li>«Мои материалы» собирает артефакты всех сервисов: документы, профили, вакансии, 3D, тренды, ссылки.</li>
                    <li>Прогресс по активным задачам — в «Моих задачах».</li>
                    <li>Инструкции и схемы сервисов — в <a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/guide">Путеводителе</a>.</li>
                </ul>
            </div>
            <?php if ($is_admin): ?>
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-user-shield mr-1.5 text-gray-400"></i>Администрирование</p>
                <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <li><a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/nj595/admin">Пользователи и роли</a></li>
                    <li><a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/nj595/admin/activity">Активность системы</a> (включая гостей ubid)</li>
                    <li><a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/nj600/admin/reco">Рекомендации под надзором</a> — правила, CTR, журнал показов</li>
                    <li><a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/orgs/admin">Справочник организаций</a></li>
                    <li><a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/nj100/errors">Хвост ошибок</a> — модули и лог (WARNING+)</li>
                    <li><a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/guide/dev">Документация разработчика</a></li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- ===================== Система (только управление) ===================== -->
    <div class="mt-8">
        <h2 class="n2j-h3 border-b border-gray-100 pb-3"><i class="fas fa-server mr-1.5 text-blue-500"></i>Система <span class="n2j-badge n2j-badge-wait ml-2 font-mono" title="Версия задеплоенного кода">build <?php echo HTML::chars($version); ?></span> <span class="ml-1 text-xs font-normal text-gray-400" title="Размер базы данных"><i class="fas fa-database mr-1"></i><?php echo HTML::chars($db_size); ?></span></h2>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
            <?php echo $stat('/tasks', 'В очереди', (string) (int) $queue['pending'], '', '', 'fas fa-hourglass-half'); ?>

            <?php echo $stat('/tasks', 'Выполняется', (string) (int) $queue['processing'], '', '', 'fas fa-gears'); ?>

            <?php echo $stat('/tasks', 'Ошибки (всего)', (string) (int) $queue['failed'], '', (int) $queue['failed'] > 0 ? ' text-rose-500' : '', 'fas fa-triangle-exclamation'); ?>

            <?php echo $stat('/tasks', 'Воркеры', $workers['online'] ? 'онлайн' : 'нет связи',
                (int) $workers['workers'].' шт · в работе: '.(int) $workers['busy'],
                $workers['online'] ? ' text-emerald-500' : ' text-rose-500', 'fas fa-server'); ?>

            <?php echo $stat('/nj560', 'Знаний (nj560)', (string) (int) ($modules['knowledge']['docs'] ?? 0), '', '', 'fas fa-brain'); ?>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-3 xl:grid-cols-4">
            <?php echo $stat('/nj520/admin', 'Опросы', (string) (int) ($modules['polls']['polls'] ?? 0), 'голосов: '.(int) ($modules['polls']['votes'] ?? 0), '', 'fas fa-chart-simple'); ?>

            <?php echo $stat('/nj530/admin', 'Короткие ссылки', (string) (int) ($modules['jump']['links'] ?? 0), 'переходов: '.(int) ($modules['jump']['clicks'] ?? 0), '', 'fas fa-link'); ?>

            <?php echo $stat('/review', 'Ответы на отзывы', (string) (int) ($modules['replies']['done'] ?? 0),
                'новых: '.(int) ($modules['replies']['new'] ?? 0).' · ошибок: '.(int) ($modules['replies']['error'] ?? 0), '', 'fas fa-comment-dots'); ?>

            <?php echo $stat('/nj550/admin', 'Поиски вакансий', (string) (int) ($modules['jobs']['done'] ?? 0),
                'новых: '.(int) ($modules['jobs']['new'] ?? 0).' · ошибок: '.(int) ($modules['jobs']['error'] ?? 0), '', 'fas fa-briefcase'); ?>
        </div>

        <div class="mt-6 grid gap-3 md:grid-cols-2">
            <!-- Ошибки -->
            <div class="<?php echo $card; ?>">
                <p class="<?php echo $lbl; ?>">Последние упавшие задачи</p>
                <?php if (empty($failed)): ?>
                    <p class="mt-2 text-sm text-emerald-600 dark:text-emerald-400"><i class="fas fa-check mr-1"></i>Ошибок нет — всё хорошо.</p>
                <?php else: ?>
                    <ul class="mt-2 space-y-1.5">
                        <?php foreach ($failed as $f): ?>
                        <li class="rounded-lg border border-rose-100 bg-rose-50/60 p-2 text-xs dark:border-rose-900/60 dark:bg-rose-950/20">
                            <span class="font-mono font-bold text-rose-500">#<?php echo (int) $f['id']; ?></span>
                            <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($f['channel']); ?></span>
                            <span class="block truncate text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars((string) $f['error']); ?>"><?php echo HTML::chars(mb_substr((string) $f['error'], 0, 90)); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- События -->
            <div class="<?php echo $card; ?>">
                <p class="<?php echo $lbl; ?>">Журнал очереди (последние события)</p>
                <?php if (empty($events)): ?>
                    <p class="mt-2 text-sm text-gray-400">Событий пока нет.</p>
                <?php else: ?>
                    <ul class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                        <?php foreach ($events as $e): ?>
                        <li class="flex flex-wrap gap-x-2 border-b border-gray-100 py-1 dark:border-gray-800">
                            <span class="font-mono text-gray-400"><?php echo HTML::chars((string) $e['created_at']); ?></span>
                            <span class="font-semibold text-gray-700 dark:text-gray-300">#<?php echo (int) $e['job_id']; ?> <?php echo HTML::chars($e['event']); ?></span>
                            <span class="min-w-0 truncate"><?php echo HTML::chars(mb_substr((string) $e['message'], 0, 60)); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
