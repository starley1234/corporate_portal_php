<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj605_radar: /radar/<code> — страница одного запроса («радара») с находками.
 *
 * Зачем отдельная страница (владелец, 26.09.2026): после создания радара
 * редирект вёл на `/radar?flash=Радар создан — сбор уже идёт`, и текст в адресе
 * переживал завершение сбора — «на новой странице „сбор уже идёт“, хотя он
 * завершён». Здесь адрес чистый и лаконичный (`/radar/rad1a2b3c4`), а состояние
 * показано фактически: живой ряд сбора, итог последнего сбора и сами находки.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$enabled_on = function ($v) {
    return $v === TRUE || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
};

$st_ru = [
    'queued'    => 'в очереди',
    'running'   => 'идёт сбор',
    'done'      => 'готово',
    'failed'    => 'ошибка',
    'cancelled' => 'остановлено',
];
$badge = [
    'queued'    => 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    'running'   => 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    'done'      => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    'failed'    => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
    'cancelled' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
];
?>
<div class="n2j-ds w-full space-y-5 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-nj605-code="<?php echo HTML::chars($w['code']); ?>">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <header>
        <nav class="flex items-center whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
            <a href="/radar" class="hover:text-blue-600 dark:hover:text-blue-400">Радар</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-mono font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($w['code']); ?></span>
        </nav>

        <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
            <h1 class="n2j-h1"><?php echo HTML::chars($w['title'] !== '' ? $w['title'] : $w['query']); ?></h1>
            <?php if ((string) $w['region'] !== ''): ?>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-100/90 px-3 py-1 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"><i class="fas fa-location-dot text-[11px]"></i><?php echo HTML::chars((string) $w['region']); ?></span>
            <?php endif; ?>
            <?php if ( ! $enabled_on($w['enabled'])): ?>
            <span class="n2j-badge n2j-badge-run">на паузе</span>
            <?php endif; ?>
            <?php if (isset($live)): ?>
            <span class="rounded-full px-3 py-1 text-xs font-bold <?php echo $badge[(string) $live['status']] ?? $badge['queued']; ?>" data-nj605-badge><?php echo $st_ru[(string) $live['status']] ?? (string) $live['status']; ?></span>
            <?php elseif (isset($last)): ?>
            <span class="rounded-full px-3 py-1 text-xs font-bold <?php echo $badge[(string) $last['status']] ?? $badge['done']; ?>"><?php echo $st_ru[(string) $last['status']] ?? (string) $last['status']; ?></span>
            <?php endif; ?>
            <span class="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">находок: <?php echo (int) $stats['total']; ?><?php if ((int) $stats['fresh'] > 0): ?> · новых <?php echo (int) $stats['fresh']; ?><?php endif; ?></span>
        </div>

        <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300"><?php echo HTML::chars((string) $w['query']); ?></p>
    </header>

    <!-- Действия по радару: одна главная кнопка, служебные — иконками -->
    <div class="<?php echo $card; ?>">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" data-nj605-run data-code="<?php echo HTML::chars($w['code']); ?>" title="Запустить сбор сейчас, не дожидаясь расписания"
                    class="n2j-btn-process flex-1 sm:flex-none sm:justify-start">
                <i class="fas fa-bolt text-xs"></i>Собрать сейчас
            </button>

            <form method="post" class="inline-flex">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="toggle">
                <input type="hidden" name="code" value="<?php echo HTML::chars($w['code']); ?>">
                <input type="hidden" name="enabled" value="<?php echo $enabled_on($w['enabled']) ? '0' : '1'; ?>">
                <button type="submit" class="n2j-btn-secondary">
                    <i class="fas <?php echo $enabled_on($w['enabled']) ? 'fa-pause' : 'fa-play'; ?> text-[11px]"></i><?php echo $enabled_on($w['enabled']) ? 'Пауза' : 'Возобновить'; ?>
                </button>
            </form>

            <?php if (isset($live)): ?>
            <form method="post" class="inline-flex">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="stop_run">
                <input type="hidden" name="code" value="<?php echo HTML::chars($w['code']); ?>">
                <button type="submit" class="n2j-btn-danger">
                    <i class="fas fa-stop text-[11px]"></i>Остановить сбор
                </button>
            </form>
            <?php endif; ?>

            <span class="ml-auto"></span>

            <form method="post" class="inline-flex">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="code" value="<?php echo HTML::chars($w['code']); ?>">
                <button data-n2j-confirm="Радар удалится вместе со всеми находками." data-n2j-title="Удалить радар?" type="submit" title="Удалить радар вместе с находками" aria-label="Удалить радар"
                        class="n2j-btn-icon-danger">
                    <i class="fas fa-trash-can"></i>
                </button>
            </form>
        </div>

        <div data-nj605-live-host class="mt-3 space-y-3">
            <?php if (isset($live)): ?>
            <?php echo View::factory('partials/live_status', [
                'url'     => '/radar/api/status/'.urlencode((string) $w['code']),
                'title'   => 'Собираем находки',
                'status'  => (string) $live['status'],
                'stage'   => (string) $live['stage'],
                'elapsed' => (int) $live['elapsed_sec'],
                'silent'  => $live['silent_sec'],
                'note'    => 'Находки появятся ниже — страница обновится сама',
                'steps'   => (array) ($live['stages'] ?? []),
            ]); ?>
            <?php elseif (isset($last)): ?>
            <div data-nj605-last>
                <?php echo View::factory('partials/last_status', [
                    'status'      => (string) $last['status'],
                    'finished_at' => (string) $last['finished_at'],
                    'error'       => (string) $last['error'],
                    'found'       => Service_Nj605_Radar::found_since((int) $w['id'], (string) $last['started_at']),
                    'found_label' => 'находок',
                    'steps'       => (array) ($last['stages'] ?? []),
                    'elapsed'     => (int) ($last['elapsed_sec'] ?? 0),
                ]); ?>
            </div>
            <?php else: ?>
            <p class="n2j-hint">Сбор ещё не запускался — нажмите «Собрать сейчас».</p>
            <?php endif; ?>
        </div>

        <?php /* Шаблон живого ряда: кнопка «Собрать» монтирует его мгновенно, без перезагрузки */ ?>
        <template data-nj605-live-tpl="<?php echo HTML::chars($w['code']); ?>">
            <?php echo View::factory('partials/live_status', [
                'url'     => '/radar/api/status/'.urlencode((string) $w['code']),
                'title'   => 'Собираем находки',
                'status'  => 'queued',
                'stage'   => '',
                'elapsed' => 0,
                'silent'  => NULL,
                'note'    => 'Находки появятся ниже — страница обновится сама',
            ]); ?>
        </template>

        <p class="n2j-hint mt-3 border-t border-gray-100 pt-2">Обход открытых источников — каждые 30 минут. Beta, бесплатно.</p>
    </div>

    <!-- Находки этого радара -->
    <div class="<?php echo $card; ?>">
        <h2 class="n2j-h3 flex items-center justify-between">
            <span>Находки по запросу</span>
            <span class="n2j-badge n2j-badge-wait"><?php echo count($items); ?></span>
        </h2>

        <?php if (empty($items)): ?>
        <p class="n2j-hint mt-2">Пока пусто: находки появятся здесь после ближайшего сбора. Если сбор завершился без находок — это тоже результат: запрос можно уточнить (продукт + ключевые слова).</p>
        <?php else: ?>
        <ul class="mt-1 divide-y divide-gray-50 dark:divide-gray-700/40">
            <?php foreach ($items as $it): ?>
            <li class="py-2.5" data-nj605-status="<?php echo HTML::chars($it['status']); ?>">
                <div class="flex items-start gap-2">
                    <span class="n2j-badge <?php echo (int) $it['score'] >= 70 ? 'n2j-badge-ok' : ((int) $it['score'] >= 40 ? 'n2j-badge-run' : 'n2j-badge-wait'); ?> shrink-0" title="Оценка «насколько подходит»"><?php echo (int) $it['score']; ?></span>
                    <?php $it_url = preg_match('~^https?://~i', (string) $it['url']) ? $it['url'] : 'https://'.preg_replace('~^//~', '', (string) $it['url']); ?>
                    <a href="<?php echo HTML::chars($it_url); ?>" target="_blank" rel="noopener nofollow" class="min-w-0 flex-1 break-words text-sm font-semibold text-blue-600 line-clamp-2 hover:underline sm:truncate dark:text-blue-400"><?php echo HTML::chars($it['title']); ?></a>
                    <span class="shrink-0 text-xs text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $it['created_at'], 'd.m H:i')); ?></span>
                </div>
                <p class="n2j-hint line-clamp-2 break-words sm:truncate">
                    <?php if ($it['customer'] !== ''): ?><i class="fas fa-building-columns mr-1 text-gray-300"></i><?php echo HTML::chars($it['customer']); ?> · <?php endif; ?>
                    <?php if ($it['price'] !== ''): ?><i class="fas fa-coins mr-1 text-gray-300"></i><?php echo HTML::chars($it['price']); ?> · <?php endif; ?>
                    <?php if ((string) $it['source'] !== ''): ?><i class="fas fa-tower-broadcast mr-1 text-gray-300"></i><?php echo HTML::chars((string) $it['source']); ?><?php endif; ?>
                </p>
                <?php if ((string) $it['why'] !== ''): ?><p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $it['why']); ?></p><?php endif; ?>
                <?php if ($it['status'] === 'new'): ?>
                <p class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                    <a class="n2j-btn-secondary n2j-btn-sm" href="/radar/<?php echo HTML::chars($w['code']); ?>?item=<?php echo (int) $it['id']; ?>&amp;st=taken">взял в работу</a>
                    <a class="n2j-btn-secondary n2j-btn-sm" href="/radar/<?php echo HTML::chars($w['code']); ?>?item=<?php echo (int) $it['id']; ?>&amp;st=dismissed">не интересно</a>
                </p>
                <?php elseif ($it['status'] === 'taken'): ?>
                <p class="mt-1 text-xs font-semibold text-emerald-600">взят в работу</p>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <p><a href="/radar" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400"><i class="fas fa-arrow-left text-[11px]"></i>Все радары</a></p>
</div>

<?php echo View::factory('partials/live_status_js'); ?>
<?php echo View::factory('605/js', ['csrf' => $csrf]); ?>
