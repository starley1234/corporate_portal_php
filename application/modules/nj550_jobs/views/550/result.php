<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Работа 2 Оффер» (nj550_jobs) — статус и результат поиска.
 *
 * $row — строка nj_550_searches; $results — карточки вакансий; $meta — model/engine/queries.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="n2j-card">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="n2j-h1">Вакансии: <?php echo HTML::chars($row['direction']); ?></h1>
            <span class="n2j-badge n2j-badge-ok"><?php echo HTML::chars($row['city']); ?></span>
        </div>

        <?php if ( ! empty($_GET['cached'])): ?>
            <div class="n2j-flash n2j-flash-info mt-3 text-xs block">
                <i class="fas fa-bolt mr-1"></i>Такой поиск уже выполнялся недавно — показаны готовые результаты (без нового запуска ИИ).
            </div>
        <?php endif; ?>

        <?php if ( ! empty($just_created) AND $row['status'] === 'new'): ?>
            <div class="n2j-flash n2j-flash-ok mt-3 text-xs block">
                Поиск запущен — страница обновится сама, можно закрыть и вернуться по ссылке.
            </div>
        <?php endif; ?>

        <?php if ($row['status'] === 'new'): ?>
            <?php
            /* Живой статус-ряд — общий механизм (partials/live_status): этапы
             * воркера, секундомер, «нет пульса», авто-стоп, перезагрузка на
             * финале. Свой спиннер и поллинг отсюда убраны: при застрявшем
             * воркере страница бесконечно писала «ИИ ищет вакансии…». */
            echo View::factory('partials/live_status', [
                'url'   => '/nj550/api/status/'.urlencode((string) $row['code']),
                'title' => 'Ищу вакансии',
                'note'  => 'Обычно 20–60 секунд. Страница обновится сама.',
            ]);
            ?>
            <p class="n2j-hint mt-1.5">
                <a id="nj550-refresh" href="/jobs/<?php echo HTML::chars($row['code']); ?>" class="font-semibold text-emerald-600 hover:underline dark:text-emerald-400">Обновить сейчас</a>
            </p>

        <?php elseif ($row['status'] === 'error'): ?>
            <div class="n2j-flash n2j-flash-err mt-5 block">
                <p class="font-semibold">Поиск не удался</p>
                <p class="mt-1 text-xs opacity-80"><?php echo HTML::chars($row['error'] ?: 'Ошибка воркера'); ?></p>
                <a href="/jobs" class="n2j-btn-secondary n2j-btn-sm mt-3">Начать заново</a>
            </div>

        <?php else: ?>
            <!-- Резюме рынка от ИИ -->
            <?php if ( ! empty($row['summary'])): ?>
                <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">Коротко о найденном</p>
                    <p class="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-gray-800 dark:text-gray-200"><?php echo HTML::chars($row['summary']); ?></p>
                </div>
            <?php endif; ?>

            <!-- Карточки вакансий -->
            <?php if (empty($results)): ?>
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Подходящих вакансий не нашлось — попробуйте сделать направление шире или выбрать другой город.</p>
            <?php else: ?>
                <?php $companies = [];
                foreach ($results as $r) { $c = trim((string) $r['company']); if ($c !== '' AND ! isset($companies[$c])) $companies[$c] = TRUE; }
                $companies = array_slice(array_keys($companies), 0, 5); ?>
                <div class="mt-4 grid gap-3 xl:grid-cols-2">
                    <?php foreach ($results as $r): ?>
                        <div class="n2j-card flex flex-col justify-between transition hover:border-emerald-400 hover:shadow-xs">
                            <div>
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($r['title']); ?></p>
                                    <span class="n2j-badge n2j-badge-wait shrink-0 uppercase"><?php echo HTML::chars((string) $r['source']); ?></span>
                                </div>
                                <?php if ( ! empty($r['company'])): ?>
                                    <p class="mt-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400"><?php echo HTML::chars($r['company']); ?></p>
                                <?php endif; ?>
                                <?php if ( ! empty($r['snippet'])): ?>
                                    <p class="n2j-hint line-clamp-2"><?php echo HTML::chars($r['snippet']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                                <span class="text-xs text-gray-400" title="Найдено"><i class="far fa-clock mr-1"></i><?php echo HTML::chars(Date::formatted_time((string) $r['created_at'], 'd.m.y H:i')); ?></span>
                                <a href="<?php echo HTML::chars($r['url']); ?>" target="_blank" rel="nofollow noopener" class="n2j-btn-cta n2j-btn-sm ml-auto"><i class="fas fa-arrow-up-right-from-square mr-1.5"></i>Открыть</a>
                                <?php if ( ! empty($r['company'])): ?>
                                <a href="/dossier?company=<?php echo urlencode((string) $r['company']); ?>" class="n2j-btn-secondary n2j-btn-sm" title="AI-разведка работодателя: отзывы, стек, красные флаги"><i class="fas fa-user-secret mr-1.5"></i>Досье</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($companies): ?>
                <p class="n2j-hint mt-3">Компании в подборке: <?php foreach ($companies as $i => $c) echo ($i ? ' · ' : '').'<a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/dossier?company='.urlencode($c).'">'.HTML::chars($c).'</a>'; ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <a href="/jobs" class="n2j-btn-secondary">
                    <i class="fas fa-magnifying-glass"></i>Новый поиск
                </a>
                <?php if ( ! empty($meta['engine'])): ?>
                    <span class="text-xs text-gray-400 dark:text-gray-500">движок: <?php echo HTML::chars((string) $meta['engine']); ?><?php echo empty($meta['model']) ? '' : ' · '.HTML::chars((string) $meta['model']); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p class="n2j-hint mt-4">Ссылка: <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-gray-900">/jobs/<?php echo HTML::chars($row['code']); ?></code></p>
    </div>

</div>


<?php echo View::factory('partials/live_status_js'); ?>
