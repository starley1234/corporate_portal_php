<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Вкладка «Поиск и текст»: смысловой индекс и русские названия/описания.
 *
 * Обе темы — про то, как карточка находится и читается, поэтому живут рядом:
 * сюда приходят после вопроса «почему поиск не находит новую карточку».
 */
$semantic = (array) ($semantic ?? []);
$translate = (array) ($translate ?? []);
?>
<div class="grid gap-5 md:grid-cols-3 md:items-start">
    <div class="min-w-0 space-y-5 md:col-span-2">
    <section class="n2j-card" id="semantic">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="n2j-h2">Смысловой поиск</h2>
                <p class="n2j-hint mt-1">
                    Индексирует названия, описания (включая русский перевод) и инструменты серверов векторами для поиска по смыслу.
                </p>
            </div>
            <span class="n2j-badge <?php echo ! empty($semantic['enabled']) ? 'n2j-badge-ok' : 'n2j-badge-wait'; ?>">
                <span class="n2j-dot"></span><?php echo ! empty($semantic['enabled']) ? 'включён' : 'только слова'; ?>
            </span>
        </div>

        <dl class="mt-4 grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <dt class="n2j-label">Модель</dt>
                <dd class="mt-1 font-mono text-xs text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) ($semantic['model'] ?? '')); ?></dd>
            </div>
            <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <dt class="n2j-label">Карточек в каталоге</dt>
                <dd class="mt-1 text-lg font-bold text-gray-900 dark:text-white"><?php echo $semantic['cards'] === NULL ? '—' : (int) $semantic['cards']; ?></dd>
            </div>
            <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <dt class="n2j-label">Векторов в индексе</dt>
                <dd class="mt-1 text-lg font-bold text-gray-900 dark:text-white"><?php echo $semantic['indexed'] === NULL ? '—' : (int) $semantic['indexed']; ?></dd>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 dark:border-emerald-900/60 dark:bg-emerald-950/20">
                <dt class="n2j-label">Ждут индексации</dt>
                <dd class="mt-1 text-lg font-bold text-emerald-800 dark:text-emerald-300"><?php echo $semantic['pending'] === NULL ? '—' : (int) $semantic['pending']; ?></dd>
            </div>
        </dl>

        <?php if ( ! empty($semantic['error'])): ?>
            <p class="n2j-hint mt-3 text-rose-700 dark:dark:text-rose-300">Последняя ошибка: <?php echo HTML::chars((string) $semantic['error']); ?></p>
        <?php endif; ?>

        <?php if ( ! empty($index_result) AND ! empty($index_result['error'])): ?>
            <p class="n2j-hint mt-3 text-rose-700 dark:dark:text-rose-300">
                <i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i>
                Индекс не поставлен: <?php echo HTML::chars((string) $index_result['error']); ?>
            </p>
        <?php elseif ( ! empty($index_result)): ?>
            <p class="n2j-hint mt-3">
                <i class="fas fa-circle-check mr-1 text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                Индекс: поставлено в очередь <?php echo (int) $index_result['queued']; ?>,
                без изменений <?php echo (int) $index_result['skipped']; ?> (текст не менялся).
            </p>
        <?php endif; ?>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <form method="post" action="/mcphub/admin/semantic">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <button class="n2j-btn-cta n2j-btn-sm" type="submit"
                        <?php echo empty($semantic['enabled']) ? 'disabled' : ''; ?>
                        title="Поставить в очередь индексацию карточек векторами (EMBEDDING_API_URL)">
                    <i class="fas fa-brain" aria-hidden="true"></i> Проиндексировать новые карточки
                </button>
            </form>
            <span class="n2j-hint">
                Пересчитываются только новые и изменённые карточки.
            </span>
        </div>

        <details class="mt-3 rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 dark:border-gray-700 dark:bg-gray-900/40">
            <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 dark:text-gray-200">
                <i class="fas fa-sliders mr-1.5 text-gray-400" aria-hidden="true"></i>
                Пересобрать индекс целиком и пороги близости
            </summary>
            <?php $thresholds = (array) ($semantic['thresholds'] ?? []); ?>
            <?php if ($thresholds !== []): ?>
                <p class="n2j-hint mt-2">
                    Отсечки смысла: максимум дистанции <?php echo HTML::chars((string) ($thresholds['max_distance'] ?? '')); ?>,
                    порог уверенности <?php echo HTML::chars((string) ($thresholds['confident_distance'] ?? '')); ?>,
                    разрыв с лучшим <?php echo HTML::chars((string) ($thresholds['spread'] ?? '')); ?>
                    (<span class="font-mono">NJ676_SEMANTIC_MAX_DISTANCE</span>, <span class="font-mono">NJ676_SEMANTIC_CONFIDENT</span>, <span class="font-mono">NJ676_SEMANTIC_SPREAD</span>).
                </p>
            <?php endif; ?>
            <p class="n2j-hint mt-1.5">
                Полная пересборка пригодится после смены модели эмбеддингов (<span class="font-mono">EMBEDDING_API_URL</span>): векторы пересчитаются заново для всех карточек через очередь <span class="font-mono">nj120</span>.
            </p>
            <form method="post" action="/mcphub/admin/semantic" class="mt-2.5">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="force" value="1">
                <button class="n2j-btn-secondary n2j-btn-sm" type="submit"
                        <?php echo empty($semantic['enabled']) ? 'disabled' : ''; ?>>
                    <i class="fas fa-rotate" aria-hidden="true"></i> Пересобрать всё (force)
                </button>
            </form>
        </details>
    </section>

    <?php
    // Русское склонение: «4 задачи», а не «4 задач» (тест 24.10.2026).
    $translate_jobs_word = static function ($n) {
        $m10 = $n % 10;
        $m100 = $n % 100;

        return ($m10 === 1 && $m100 !== 11) ? 'задача'
            : ((($m10 >= 2 && $m10 <= 4) && ($m100 < 12 || $m100 > 14)) ? 'задачи' : 'задач');
    };
    ?>

    <section class="n2j-card" id="translate">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="n2j-h2">Русские названия и описания</h2>
                <p class="n2j-hint mt-1">
                    Сайт русскоязычный, а карточки приходят из GitHub и реестра на английском.
                    Модель переводит название и описание; оригинал автора остаётся в карточке
                    сервера (раскрывается в блоке «Что умеет»). Ручной перевод модель не перезаписывает.
                </p>
            </div>
            <span class="n2j-badge <?php echo (int) ($translate['pending'] ?? 0) === 0 ? 'n2j-badge-ok' : 'n2j-badge-info'; ?>">
                <span class="n2j-dot"></span><?php echo (int) ($translate['translated'] ?? 0); ?> из <?php echo (int) ($translate['cards'] ?? 0); ?> переведено
            </span>
        </div>

        <dl class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 dark:border-emerald-900/60 dark:bg-emerald-950/20">
                <dt class="n2j-label">Ждут перевода</dt>
                <dd class="mt-1 text-lg font-bold text-emerald-800 dark:text-emerald-300"><?php echo (int) ($translate['pending'] ?? 0); ?></dd>
            </div>
            <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <dt class="n2j-label">Переведено</dt>
                <dd class="mt-1 text-lg font-bold text-gray-900 dark:text-white"><?php echo (int) ($translate['translated'] ?? 0); ?><?php if ((int) ($translate['manual'] ?? 0) > 0): ?> <span class="n2j-hint font-normal">(вручную: <?php echo (int) $translate['manual']; ?>)</span><?php endif; ?></dd>
            </div>
            <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <dt class="n2j-label">Последний перевод</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-700 dark:text-gray-200"><?php echo ($translate['last_at'] ?? '') !== '' ? HTML::chars($fmt_dt((string) $translate['last_at'])) : 'ещё не было'; ?></dd>
            </div>
        </dl>

        <?php if ( ! empty($translate_result) AND ! empty($translate_result['error'])): ?>
            <p class="n2j-hint mt-3 text-rose-700 dark:dark:text-rose-300">
                <i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i>
                Задача не поставлена: <?php echo HTML::chars((string) $translate_result['error']); ?>
            </p>
        <?php elseif ( ! empty($translate_result)): ?>
            <p class="n2j-hint mt-3">
                <i class="fas fa-circle-check mr-1 text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                <?php if ((int) ($translate_result['released'] ?? 0) > 0): ?>
                    Сняли клейм с <strong class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $translate_result['released']; ?></strong>
                    карточек, которые висели за закончившейся задачей.
                <?php endif; ?>
                <?php if ((int) ($translate_result['jobs'] ?? 0) > 1): ?>
                    Поставлена цепочка: <strong class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $translate_result['jobs']; ?></strong>
                    <?php echo $translate_jobs_word((int) $translate_result['jobs']); ?> по <?php echo (int) $translate_result['limit']; ?> карточек<?php if ((int) $translate_result['job_id'] > 0): ?>,
                    первая — <span class="font-mono">#<?php echo (int) $translate_result['job_id']; ?></span><?php endif; ?>.
                <?php else: ?>
                    Задача поставлена: <?php echo (int) $translate_result['limit']; ?> карточек<?php if ((int) $translate_result['job_id'] > 0): ?>,
                    номер в очереди <span class="font-mono">#<?php echo (int) $translate_result['job_id']; ?></span><?php endif; ?>.
                <?php endif; ?>
                Ход работы — в мониторе задач <a class="n2j-link" href="/nj120/monitor">nj120</a>.
            </p>
        <?php endif; ?>

        <?php // Отчёт последнего прогона: без него «переведено 5» — загадка.
              // Воркер присылает его на /mcphub/api/translate-finish. ?>
        <?php $last_run = (array) ($translate['last_result'] ?? []); ?>
        <?php if ($last_run !== []): ?>
            <div class="mt-3 rounded-xl border border-gray-200 bg-gray-50/60 px-3 py-2 dark:border-gray-700 dark:bg-gray-900/30">
                <p class="n2j-hint">
                    <i class="fas fa-clock-rotate-left mr-1" aria-hidden="true"></i>
                    Последний прогон перевода · <?php echo HTML::chars($fmt_dt((string) ($last_run['at'] ?? ''))); ?>:
                    переведено <strong class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) ($last_run['translated'] ?? 0); ?></strong>,
                    пропущено <strong class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) ($last_run['skipped'] ?? 0); ?></strong>
                    <?php if (($last_run['remaining'] ?? NULL) !== NULL): ?>
                        · осталось в очереди <?php echo (int) $last_run['remaining']; ?>
                    <?php endif; ?>
                    <?php if (trim((string) ($last_run['note'] ?? '')) !== ''): ?>
                        · <?php echo HTML::chars((string) $last_run['note']); ?>
                    <?php endif; ?>
                </p>
                <?php if ( ! empty($last_run['errors'])): ?>
                    <ul class="mt-1 space-y-0.5 text-xs text-rose-700 dark:text-rose-300">
                        <?php foreach ((array) $last_run['errors'] as $last_error): ?>
                            <li><i class="fas fa-circle-exclamation mr-1" aria-hidden="true"></i><?php echo HTML::chars((string) $last_error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        // Две кнопки — два разных вопроса: «догони по чуть-чуть» и «переведи всё».
        // Вторая ставит цепочку независимых задач: падение одной не обнуляет
        // остальные (23.10.2026 одна задача перевела 5 карточек и умерла).
        $translate_pending = (int) ($translate['pending'] ?? 0);
        // Свободные карточки — те, что ещё не взяты задачами (клейм): по ним
        // и работает кнопка, чтобы повторный клик не плодил пустые цепочки.
        // Сервис отпускает клеймы закончившихся задач, поэтому «0 свободных»
        // теперь значит «правда занято живой задачей», а не «час ждать».
        $translate_free = (int) ($translate['free'] ?? $translate_pending);
        $translate_claimed = (int) ($translate['claimed'] ?? 0);
        $translate_jobs = (int) ($translate['jobs_needed'] ?? 0);
        $translate_job_size = (int) ($translate['job_size'] ?? 40);
        // Живая очередь канала: без неё непонятно, работает модель или нет.
        $translate_queue = (array) ($translate['queue'] ?? []);
        $translate_active = (int) ($translate_queue['active'] ?? 0);
        $translate_last_job = $translate_queue['last'] ?? NULL;
        $translate_stuck = (( ! empty($translate['stuck'])) OR ($translate_free === 0 AND $translate_active === 0 AND $translate_pending > 0));
        ?>
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <form method="post" action="/mcphub/admin/translate">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="mode" value="all">
                <button class="n2j-btn-cta n2j-btn-sm" type="submit"
                        <?php echo $translate_free === 0 ? 'disabled' : ''; ?>>
                    <i class="fas fa-language" aria-hidden="true"></i> Перевести всё
                </button>
            </form>
            <form method="post" action="/mcphub/admin/translate">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="mode" value="batch">
                <input type="hidden" name="limit" value="20">
                <button class="n2j-btn-secondary n2j-btn-sm" type="submit"
                        <?php echo $translate_free === 0 ? 'disabled' : ''; ?>>
                    <i class="fas fa-forward" aria-hidden="true"></i> Порция 20
                </button>
            </form>
            <?php if ($translate_stuck): ?>
                <form method="post" action="/mcphub/admin/translate_reset">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="mode" value="all">
                    <button class="n2j-btn-secondary n2j-btn-sm" type="submit"
                            title="Снять клейм с карточек, чья задача закончилась, и поставить перевод заново">
                        <i class="fas fa-unlock" aria-hidden="true"></i> Разблокировать
                    </button>
                </form>
            <?php endif; ?>
            <span class="n2j-hint">
                <?php if ($translate_free > 0): ?>
                    <?php $translate_covered = (int) ($translate['covered'] ?? $translate_free); ?>
                    «Перевести всё» поставит <?php echo (int) $translate_jobs; ?> <?php echo $translate_jobs_word((int) $translate_jobs); ?> по <?php echo (int) $translate_job_size; ?>
                    карточек<?php if ($translate_covered >= $translate_free): ?> — все свободные<?php else: ?> (первые <?php echo (int) $translate_covered; ?> из <?php echo (int) $translate_free; ?>)<?php endif; ?>.
                <?php elseif ($translate_pending === 0): ?>
                    Все карточки переведены.
                <?php elseif ($translate_active > 0): ?>
                    Остальные карточки уже в работе (<?php echo (int) $translate_claimed; ?> шт.).
                <?php else: ?>
                    Клейм висит за закончившейся задачей — нажмите «Разблокировать».
                <?php endif; ?>
            </span>
        </div>

        <?php
        $translate_status_ru = [
            'pending' => 'ждёт воркера',
            'processing' => 'в работе',
            'done' => 'закончена',
            'failed' => 'ошибка',
            'cancelled' => 'отменена',
        ];
        ?>
        <details class="mt-3 rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 dark:border-gray-700 dark:bg-gray-900/40">
            <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 dark:text-gray-200">
                <i class="fas fa-list-check mr-1.5 text-gray-400" aria-hidden="true"></i>
                Очередь перевода, модель и автодогон
            </summary>
            <p class="n2j-hint mt-2">
                Модель: <span class="font-mono"><?php echo HTML::chars((string) ($translate['model'] ?? '')); ?></span>.
                После каждого прогона воркер автоматически ставит продолжение, а тик подхватывает остаток
                (<span class="font-mono">NJ676_AUTO_TRANSLATE=0</span> выключает автоперевод).
            </p>
            <?php if ($translate_last_job !== NULL): ?>
                <p class="n2j-hint mt-1.5">
                    Очередь перевода:
                    <?php if ($translate_active > 0): ?>
                        <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $translate_active; ?></span>
                        в работе,
                    <?php else: ?>
                        активных задач нет,
                    <?php endif; ?>
                    последняя —
                    <span class="font-mono">#<?php echo (int) $translate_last_job['id']; ?></span>
                    <?php echo HTML::chars($translate_status_ru[(string) $translate_last_job['status']] ?? (string) $translate_last_job['status']); ?>
                    <?php $translate_at = $translate_last_job['finished_at'] !== ''
                        ? $translate_last_job['finished_at']
                        : ($translate_last_job['started_at'] !== '' ? $translate_last_job['started_at'] : $translate_last_job['created_at']); ?>
                    <?php if ($translate_at !== ''): ?>(<?php echo HTML::chars($fmt_dt($translate_at)); ?>)<?php endif; ?>
                    <?php if (trim((string) $translate_last_job['error']) !== ''): ?>
                        · <span class="text-rose-700 dark:text-rose-300"><?php echo HTML::chars(mb_substr((string) $translate_last_job['error'], 0, 200)); ?></span>
                    <?php endif; ?>
                    · <a class="n2j-link" href="/nj120/monitor">монитор задач</a>
                </p>
            <?php elseif (empty($translate_queue['ok'])): ?>
                <p class="n2j-hint mt-1.5 text-amber-600 dark:text-amber-400">
                    <i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i>
                    Очередь nj120 недоступна<?php echo ($translate_queue['error'] ?? '') !== '' ? ': '.HTML::chars((string) $translate_queue['error']) : ''; ?> —
                    задачи не ставятся и не проверяются.
                </p>
            <?php endif; ?>
        </details>
    </section>
    </div>

    <aside class="w-full space-y-4">
        <section class="n2j-card">
            <h2 class="n2j-h3">Порядок работы</h2>
            <ol class="mt-3 space-y-3 text-sm text-gray-700 dark:text-gray-300">
                <li class="flex gap-2.5">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">1</span>
                    <span class="min-w-0"><span class="font-semibold text-gray-900 dark:text-white">Соберите карточки</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">вкладка «Сборка»: кнопка «Найти и добавить»</span></span>
                </li>
                <li class="flex gap-2.5">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">2</span>
                    <span class="min-w-0"><span class="font-semibold text-gray-900 dark:text-white">Переведите на русский</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">модель пишет имя и описание, оригинал остаётся</span></span>
                </li>
                <li class="flex gap-2.5">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">3</span>
                    <span class="min-w-0"><span class="font-semibold text-gray-900 dark:text-white">Проиндексируйте смысл</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">векторы ставятся в очередь nj120, лишнего не считают</span></span>
                </li>
            </ol>
        </section>
    </aside>
</div>
