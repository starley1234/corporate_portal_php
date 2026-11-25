<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100/admin — «Администрирование»: инструменты платформы, сервисы платформенной
 * группы и разделы управления модулей. Всё, что до 06.10.2026 было пунктами
 * левой панели в группе «Платформа».
 */
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['Администрирование']],
        'title'  => 'Администрирование',
        'pill'   => ['prx103 · платформа', 'fa-gear'],
        'lead'   => 'Инструменты платформы и разделы управления сервисами. До 06.10.2026 эти ссылки заполняли группу «Платформа» в левой панели — теперь панель только навигация, управление живёт здесь.',
    ]); ?>

    <?php if ( ! empty($flash)): ?>
    <div class="prx-flash prx-flash-info mt-4 block text-xs font-semibold"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <?php
    /* Сводка состояния: одна строка карточек вместо обхода монитора, журнала,
       бюджетов, ошибок и миграций. Тон карточки — цвет значения и точка. */
    $health = isset($health) ? (array) $health : [];
    $tone_cls = [
        'ok'    => ['text-emerald-600 dark:text-emerald-400', 'bg-emerald-500'],
        'warn'  => ['text-amber-600 dark:text-amber-400',     'bg-amber-500'],
        'bad'   => ['text-rose-600 dark:text-rose-400',       'bg-rose-500'],
        'muted' => ['text-gray-900 dark:text-white',          'bg-gray-300 dark:bg-gray-600'],
    ];
    ?>
    <?php if ($health): ?>
    <section class="mt-6" aria-labelledby="prx100-admin-health">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="prx100-admin-health" class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Состояние платформы</h2>
            <form method="post" action="/admin" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <button type="submit" name="act" value="queue_maintain" class="prx-btn-secondary prx-btn-sm"
                        title="Закрыть доставленные, но не закрытые задачи и разобрать зависшие (то же, что делает уборка очереди)">
                    <i class="fas fa-broom mr-1"></i>Прибрать очередь
                </button>
                <button type="submit" name="act" value="cache_clear" data-prx-arm="Очистить кэш приложения?" class="prx-btn-secondary prx-btn-sm"
                        title="Сбросить кэш Kohana (каталог сервисов, списки модулей)">
                    <i class="fas fa-eraser mr-1"></i>Очистить кэш
                </button>
            </form>
        </div>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <?php foreach ($health as $h): ?>
            <?php [$vc, $dot] = $tone_cls[$h['tone']] ?? $tone_cls['muted']; ?>
            <a href="<?php echo HTML::chars($h['href']); ?>" class="prx-card group transition hover:-translate-y-0.5 hover:border-emerald-400 hover:shadow-xs dark:hover:border-emerald-700">
                <div class="flex items-center justify-between gap-2">
                    <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <i class="<?php echo HTML::chars($h['icon']); ?> text-gray-400" aria-hidden="true"></i><?php echo HTML::chars($h['title']); ?>
                    </p>
                    <i class="inline-block h-2 w-2 rounded-full <?php echo $dot; ?>" aria-hidden="true"></i>
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums <?php echo $vc; ?>"><?php echo HTML::chars($h['value']); ?>
                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400"><?php echo HTML::chars($h['unit']); ?></span></p>
                <p class="prx-hint mt-1"><?php echo HTML::chars($h['note']); ?></p>
                <?php if ( ! empty($h['rows'])): ?>
                <dl class="mt-2 space-y-0.5 text-xs text-gray-600 dark:text-gray-300">
                    <?php foreach ($h['rows'] as $row): ?>
                    <div class="flex justify-between gap-2"><dt><?php echo HTML::chars($row[0]); ?></dt><dd class="font-semibold tabular-nums"><?php echo HTML::chars($row[1]); ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="mt-6" aria-labelledby="prx100-admin-tools">
        <h2 id="prx100-admin-tools" class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Инструменты платформы</h2>
        <?php if ( ! $tools): ?>
            <p class="prx-hint mt-2 rounded-xl border border-dashed border-gray-300 px-4 py-3 dark:border-gray-600">Нет подключённых инструментов.</p>
        <?php else: ?>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($tools as $tool): ?>
            <a href="<?php echo HTML::chars($tool['href']); ?>"
               class="prx-card group transition hover:-translate-y-0.5 hover:border-emerald-400 hover:shadow-xs dark:hover:border-emerald-700">
                <div class="flex items-center gap-2.5">
                    <span class="rounded-lg bg-gray-100 p-2 text-sm text-gray-600 group-hover:bg-emerald-50 group-hover:text-emerald-600 dark:bg-gray-700 dark:text-gray-300 dark:group-hover:bg-emerald-950 dark:group-hover:text-emerald-300"><i class="<?php echo HTML::chars($tool['icon']); ?>" aria-hidden="true"></i></span>
                    <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($tool['title']); ?></p>
                </div>
                <p class="prx-hint mt-2"><?php echo HTML::chars($tool['note']); ?></p>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($platform): ?>
    <section class="mt-8" aria-labelledby="prx100-admin-platform">
        <h2 id="prx100-admin-platform" class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Сервисы платформы</h2>
        <p class="prx-hint">Сервисы группы «Платформа» — из панели убраны, здесь и в каталоге /services доступны.</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($platform as $m): ?>
            <a href="<?php echo HTML::chars($m['href']); ?>"
               class="prx-card group transition hover:-translate-y-0.5 hover:border-emerald-400 hover:shadow-xs dark:hover:border-emerald-700">
                <div class="flex items-center gap-2.5">
                    <span class="rounded-lg bg-gray-100 p-2 text-sm text-gray-600 group-hover:bg-emerald-50 group-hover:text-emerald-600 dark:bg-gray-700 dark:text-gray-300 dark:group-hover:bg-emerald-950 dark:group-hover:text-emerald-300"><i class="<?php echo HTML::chars($m['icon']); ?>" aria-hidden="true"></i></span>
                    <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($m['title']); ?></p>
                </div>
                <p class="prx-hint mt-2"><?php echo HTML::chars((string) $m['short']); ?></p>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($admin_sections): ?>
    <section class="mt-8" aria-labelledby="prx100-admin-sections">
        <h2 id="prx100-admin-sections" class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Разделы управления сервисов</h2>
        <p class="prx-hint">Админ-разделы, которые модули объявили в манифестах (в шапке сайта они показываются чипами на своих страницах).</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <?php foreach ($admin_sections as $s): ?>
            <a href="<?php echo HTML::chars($s['href']); ?>"
               class="prx-btn-secondary prx-btn-sm"
               title="<?php echo HTML::chars(($s['note'] !== '' ? $s['note'].' · ' : '').$s['group']); ?>">
                <i class="<?php echo HTML::chars($s['icon']); ?> text-xs opacity-70" aria-hidden="true"></i>
                <?php echo HTML::chars($s['title']); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
