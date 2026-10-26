<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj605_radar: /radar — форма наблюдения, мои радары, лента находок.
 *
 * Лаконично (владелец, 26.09): одна главная кнопка на радар, статус — точкой и
 * словом, служебные пояснения в одну строку; действия страницы уходят на чистый
 * GET (см. Controller_Nj605_Radar::_post) — перезагрузка ничего не повторяет.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
// PostgreSQL boolean приходит строкой 't'/'f' (или true/false) — нормализуем.
$enabled_on = function ($v) {
    return $v === TRUE || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
};
?>
<div class="n2j-ds w-full space-y-5 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="grid gap-5 md:grid-cols-3 md:items-start">
        <!-- Форма наблюдения -->
        <div class="<?php echo $card; ?>">
            <h1 class="n2j-h1"><i class="fas fa-satellite-dish mr-1.5 text-blue-500" aria-hidden="true"></i>Что искать</h1>
            <?php if ( ! $logged): ?>
            <?php
            // Запрос, если он был в ссылке, переносим в возврат после входа
            $qs = http_build_query(array_intersect_key($_GET, ['title' => 1, 'query' => 1, 'region' => 1]));
            echo Gate::wall('radar'.($qs !== '' ? '?'.$qs : ''), [
                'icon'   => 'fas fa-satellite-dish',
                'title'  => 'Радар спроса — по бесплатному аккаунту',
                'text'   => 'Радар каждые 30 минут обходит закупки, площадки и объявления и приносит находки с оценкой «насколько вам подходит». Аккаунт нужен, чтобы обход не расходовался анонимами, а лента находок была вашей.',
                'points' => [
                    'Находки из закупок, площадок и объявлений — без ручного поиска',
                    'Оценка «насколько вам подходит» у каждой находки',
                    'Обход открытых источников каждые 30 минут',
                    'Мои радары: несколько запросов, пауза и повторный запуск',
                ],
                'stats'  => ['Beta, бесплатно', 'обход каждые 30 минут'],
                'cta'    => 'Войти и поставить радар',
                'note'   => 'Открытые источники — без обхода чужих аккаунтов и закрытых баз: только то, что опубликовано.',
            ]);
            ?>
            <?php else: ?>
            <?php echo View::factory('605/form', ['csrf' => $csrf]); ?>
            <?php endif; ?>
            <p class="n2j-hint mt-3 border-t border-gray-100 pt-2">Обход открытых источников — каждые 30 минут. Beta, бесплатно.</p>
        </div>

        <!-- Радары и лента -->
        <div class="space-y-4 md:col-span-2">
            <?php if ($logged AND ! empty($watches)): ?>
            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3 flex items-center justify-between">
                    <span>Мои радары</span>
                    <span class="n2j-badge n2j-badge-wait"><?php echo (int) $watch_count; ?> / <?php echo (int) $max_watches; ?></span>
                </h2>
                <ul class="mt-1 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($watches as $w): $code = (string) $w['code']; ?>
                    <li class="py-1.5" data-nj605-watch-row="<?php echo (int) $w['id']; ?>">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 text-sm">
                            <a href="/radar/<?php echo HTML::chars($code); ?>" class="min-w-0 flex-1 truncate font-semibold text-gray-800 hover:text-blue-600 hover:underline dark:text-gray-200 dark:hover:text-blue-400" title="Открыть радар: находки по этому запросу"><?php echo HTML::chars($w['title']); ?><?php echo $w['region'] !== '' ? '<span class="font-normal text-gray-400"> · '.HTML::chars($w['region']).'</span>' : ''; ?></a>
                            <?php if ( ! $enabled_on($w['enabled'])): ?><span class="n2j-badge n2j-badge-run shrink-0" title="Наблюдение остановлено">пауза</span><?php endif; ?>
                            <?php if ((int) $w['fresh'] > 0): ?><button type="button" data-nj605-fresh data-watch="<?php echo (int) $w['id']; ?>" title="Показать в ленте только новые этого радара (повторный клик — вернуть все)" class="shrink-0 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-bold text-blue-600 transition hover:bg-blue-100 dark:bg-blue-950/60 dark:text-blue-300">новых <?php echo (int) $w['fresh']; ?></button><?php endif; ?>
                            <span class="flex shrink-0 items-center gap-1">
                                <button type="button" data-nj605-run data-code="<?php echo HTML::chars($code); ?>" title="Запустить сбор сейчас, не дожидаясь расписания" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-bolt"></i>Собрать</button>
                                <form method="post" class="shrink-0">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <input type="hidden" name="act" value="toggle">
                                    <input type="hidden" name="code" value="<?php echo HTML::chars($code); ?>">
                                    <input type="hidden" name="enabled" value="<?php echo $enabled_on($w['enabled']) ? '0' : '1'; ?>">
                                    <button type="submit" title="<?php echo $enabled_on($w['enabled']) ? 'Поставить наблюдение на паузу' : 'Возобновить наблюдение'; ?>" class="n2j-btn-icon"><i class="fas <?php echo $enabled_on($w['enabled']) ? 'fa-pause' : 'fa-play'; ?>"></i></button>
                                </form>
                                <form method="post" class="shrink-0">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <input type="hidden" name="act" value="delete">
                                    <input type="hidden" name="code" value="<?php echo HTML::chars($code); ?>">
                                    <button data-n2j-confirm="Радар удалится вместе со всеми находками." data-n2j-title="Удалить радар?" type="submit" title="Удалить радар вместе с находками" aria-label="Удалить радар" class="n2j-btn-icon-danger"><i class="fas fa-trash-can"></i></button>
                                </form>
                            </span>
                        </div>

                        <div data-nj605-live-host>
                            <?php if (isset($live[$code])): $lv = $live[$code]; ?>
                            <?php echo View::factory('partials/live_status', [
                                'url'     => '/radar/api/status/'.urlencode($code),
                                'title'   => 'Собираем находки',
                                'status'  => (string) $lv['status'],
                                'stage'   => (string) $lv['stage'],
                                'elapsed' => (int) $lv['elapsed_sec'],
                                'silent'  => $lv['silent_sec'],
                                'note'    => 'Находки появятся в ленте — страница обновится сама',
                                'steps'   => (array) ($lv['stages'] ?? []),
                                'stop'    => '<form method="post" class="inline-flex">'
                                    .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                                    .'<input type="hidden" name="act" value="stop_run">'
                                    .'<input type="hidden" name="code" value="'.HTML::chars($code).'">'
                                    .'<button type="submit" title="Прервать текущий сбор" class="inline-flex min-h-[36px] items-center gap-1 rounded-lg border border-rose-200 px-2.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 sm:min-h-0 sm:py-1 dark:border-rose-800 dark:hover:bg-rose-950/40"><i class="fas fa-stop"></i>Остановить</button>'
                                    .'</form>',
                            ]); ?>
                            <?php elseif (isset($last[$code])): $ls = $last[$code]; ?>
                            <div data-nj605-last>
                                <?php echo View::factory('partials/last_status', [
                                    'status'      => (string) $ls['status'],
                                    'finished_at' => (string) $ls['finished_at'],
                                    'error'       => (string) $ls['error'],
                                    'found'       => Service_Nj605_Radar::found_since((int) $w['id'], (string) $ls['started_at']),
                                    'found_label' => 'находок',
                                    // «отобрано без модели» и прочие приписки задачи
                                    'note'        => (string) ($ls['note'] ?? ''),
                                    'steps'       => (array) ($ls['stages'] ?? []),
                                    'elapsed'     => (int) ($ls['elapsed_sec'] ?? 0),
                                ]); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php /* Шаблон живого ряда: «Собрать» монтирует его мгновенно,
                                 без перезагрузки страницы (JS N2JLive.mount). */ ?>
                        <template data-nj605-live-tpl="<?php echo HTML::chars($code); ?>">
                            <?php echo View::factory('partials/live_status', [
                                'url'     => '/radar/api/status/'.urlencode($code),
                                'title'   => 'Собираем находки',
                                'status'  => 'queued',
                                'stage'   => '',
                                'elapsed' => 0,
                                'silent'  => NULL,
                                'note'    => 'Находки появятся в ленте — страница обновится сама',
                                'stop'    => '<form method="post" class="inline-flex">'
                                    .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                                    .'<input type="hidden" name="act" value="stop_run">'
                                    .'<input type="hidden" name="code" value="'.HTML::chars($code).'">'
                                    .'<button type="submit" title="Прервать текущий сбор" class="inline-flex min-h-[36px] items-center gap-1 rounded-lg border border-rose-200 px-2.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 sm:min-h-0 sm:py-1 dark:border-rose-800 dark:hover:bg-rose-950/40"><i class="fas fa-stop"></i>Остановить</button>'
                                    .'</form>',
                            ]); ?>
                        </template>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3 flex items-center justify-between">
                    <span>Лента находок</span>
                    <?php // Счётчик показываем только вошедшим: у гостя находок нет по определению. ?>
                    <?php if ($logged): ?>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($feed); ?></span>
                    <?php endif; ?>
                </h2>
                <?php if (empty($feed)): ?>
                <p class="n2j-hint mt-2">
                    <?php if ( ! $logged): ?>Войдите и создайте радар — находки появятся здесь автоматически.
                    <?php elseif (empty($watches)): ?>Пока пусто. Создайте радар — находки появятся здесь автоматически.
                    <?php else: ?>Находок пока нет: сбор идёт после создания радара. Нажмите «Собрать», чтобы запустить сейчас.<?php endif; ?>
                </p>
                <?php else: ?>
                <ul class="mt-1 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($feed as $it): ?>
                    <li class="py-2.5" data-nj605-watch="<?php echo (int) $it['watch_id']; ?>" data-nj605-status="<?php echo HTML::chars($it['status']); ?>">
                        <div class="flex items-start gap-2">
                            <span class="n2j-badge <?php echo (int) $it['score'] >= 70 ? 'n2j-badge-ok' : ((int) $it['score'] >= 40 ? 'n2j-badge-run' : 'n2j-badge-wait'); ?> shrink-0" title="Оценка «насколько подходит»"><?php echo (int) $it['score']; ?></span>
                            <?php $it_url = preg_match('~^https?://~i', (string) $it['url']) ? $it['url'] : 'https://'.preg_replace('~^//~', '', (string) $it['url']); ?><a href="<?php echo HTML::chars($it_url); ?>" target="_blank" rel="noopener nofollow" class="min-w-0 flex-1 break-words text-sm font-semibold text-blue-600 line-clamp-2 hover:underline sm:truncate dark:text-blue-400"><?php echo HTML::chars($it['title']); ?></a>
                            <span class="shrink-0 text-xs text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $it['created_at'], 'd.m H:i')); ?></span>
                        </div>
                        <p class="n2j-hint line-clamp-2 break-words sm:truncate">
                            <?php if ($it['customer'] !== ''): ?><i class="fas fa-building-columns mr-1 text-gray-300"></i><?php echo HTML::chars($it['customer']); ?> · <?php endif; ?>
                            <?php if ($it['price'] !== ''): ?><i class="fas fa-coins mr-1 text-gray-300"></i><?php echo HTML::chars($it['price']); ?> · <?php endif; ?>
                            <?php if ((string) $it['source'] !== ''): ?><i class="fas fa-tower-broadcast mr-1 text-gray-300"></i><?php echo HTML::chars((string) $it['source']); ?> · <?php endif; ?>
                            <?php if ($it['watch_title'] !== ''): ?><i class="fas fa-satellite-dish mr-1 text-gray-300"></i><?php echo HTML::chars($it['watch_title']); ?><?php endif; ?>
                        </p>
                        <?php if ((string) $it['why'] !== ''): ?><p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $it['why']); ?></p><?php endif; ?>
                        <?php if ($it['status'] === 'new'): ?>
                        <p class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                            <a class="n2j-btn-secondary n2j-btn-sm" href="/radar?item=<?php echo (int) $it['id']; ?>&amp;st=taken">взял в работу</a>
                            <a class="n2j-btn-secondary n2j-btn-sm" href="/radar?item=<?php echo (int) $it['id']; ?>&amp;st=dismissed">не интересно</a>
                        </p>
                        <?php elseif ($it['status'] === 'taken'): ?>
                        <p class="mt-1 text-xs font-semibold text-emerald-600">взят в работу</p>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>

<?php echo View::factory('605/js', ['csrf' => $csrf]); ?>
