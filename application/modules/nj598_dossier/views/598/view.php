<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Досье nj598: результат разведки (red flags / pros / шпаргалка / вопросы).
 */

$list = function (array $items, $icon, $color, $empty) {
    if (empty($items))
    {
        return '<p class="mt-2 text-xs text-gray-500 sm:text-sm dark:text-gray-400">'.$empty.'</p>';
    }

    $out = '<ul class="mt-2.5 space-y-2">';

    foreach ((array) $items as $it)
    {
        $t = (string) ($it['text'] ?? $it);
        $src = (string) ($it['source'] ?? '');

        $out .= '<li class="flex items-start gap-2.5 text-sm text-gray-700 sm:text-base dark:text-gray-200">'
            .'<i class="fas '.$icon.' '.$color.' mt-1 w-4 shrink-0 text-center text-xs"></i>'
            .'<span class="min-w-0">'.HTML::chars($t)
            .($src !== '' ? ' <span class="block truncate text-xs text-gray-400 sm:text-sm">— '.HTML::chars($src).'</span>' : '')
            .'</span></li>';
    }

    return $out.'</ul>';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <!-- Хедер -->
    <div class="n2j-card">
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/dossier" class="transition hover:text-blue-600 dark:hover:text-blue-400">AI Досье</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-mono font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($r['code']); ?></span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-4 dark:border-gray-700/60">
            <div class="min-w-0">
                <h1 class="n2j-h1 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <span class="min-w-0"><i class="fas fa-user-secret mr-2 text-blue-500"></i><?php echo HTML::chars($r['company'] ?: $r['query']); ?></span>
                    <span id="dos-badge" class="inline-flex shrink-0 items-center rounded-full px-3 py-1 text-xs font-semibold <?php echo $r['status'] === 'done' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : ($r['status'] === 'error' ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300' : 'bg-blue-600 text-white'); ?>">
                        <?php /* спиннер живёт в статус-ряде: два независимых
                         * «крутится» на странице противоречат друг другу */ ?>
                        <?php echo $r['status'] === 'done' ? 'Досье готово' : ($r['status'] === 'error' ? 'Ошибка разведки' : ($r['status'] === 'running' ? 'Разведка идёт' : 'В очереди')); ?>
                    </span>
                </h1>
                <p class="n2j-hint mt-1.5">
                    <span class="font-mono"><?php echo HTML::chars($r['code']); ?></span>
                    · создано: <?php echo HTML::chars(Date::formatted_time((string) $r['created_at'], 'd.m.Y в H:i')); ?>
                    <?php if ($r['status'] === 'done' AND (string) $r['model_used'] !== ''): ?>· модель: <?php echo HTML::chars($r['model_used']); ?><?php endif; ?>
                    <?php if ($r['finished_at'] AND $r['status'] === 'done'): $mins = max(1, (int) round((strtotime((string) $r['finished_at']) - strtotime((string) $r['created_at'])) / 60)); ?>· заняла ~<?php echo $mins; ?> мин<?php endif; ?>
                </p>
                <p class="mt-1 text-xs">
                    <?php $org594v = class_exists('Service_Nj594_Orgs') ? Service_Nj594_Orgs::org_for_item('nj598', (string) $r['code']) : NULL; ?>
                    <?php if ($org594v !== NULL): ?>
                    <a href="/orgs/<?php echo HTML::chars((string) $org594v['ogrn']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><i class="fas fa-building-columns mr-1"></i>Карточка в Справочнике организаций: <?php echo HTML::chars((string) (($org594v['short_name'] ?? '') ?: ($org594v['full_name'] ?? ''))); ?></a>
                    <?php else: ?>
                    <a href="/orgs?q=<?php echo urlencode((string) ($r['company'] ?: $r['query'])); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><i class="fas fa-building-columns mr-1"></i>Найти в Справочнике организаций</a>
                    <?php endif; ?>
                    <?php $co_for_links = (string) ($r['company'] ?: $r['query']); ?>
                    · <a href="/company?q=<?php echo urlencode($co_for_links); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><i class="fas fa-id-card mr-1"></i>Профиль по сайту компании</a>
                    · <a href="/coldmail?company=<?php echo urlencode($co_for_links); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><i class="fas fa-envelope mr-1"></i>Написать письмо</a>
                    <span class="ml-1.5 inline-block align-middle"><?php echo View::factory('partials/ontology_link', [
                        'title' => (string) ($r['company'] !== '' ? $r['company'] : $r['query']),
                        'kind'  => 'company',
                    ]); ?></span>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <?php if ($mine AND $r['status'] !== 'running'): ?>
                <form method="post" action="/dossier/delete/<?php echo HTML::chars($r['code']); ?>">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button data-n2j-arm="Удалить?" type="submit" class="n2j-btn-danger"><i class="fas fa-trash-can text-xs"></i>Удалить</button>
                </form>
                <?php endif; ?>
                <a href="/dossier" class="n2j-btn-secondary"><i class="fas fa-arrow-left text-xs text-gray-400"></i>К списку</a>
            </div>
        </div>
        <?php /* Живой статус-ряд — ОБЩИЙ механизм (эталон /trends/<code>): спиннер,
         * секундомер от фактического времени работы, этап воркера, «нет пульса»,
         * остановка. Раньше тут была своя строка прогресса со своим поллингом. */ ?>
        <?php if (in_array($r['status'], ['running', 'new'], TRUE)): ?>
        <?php echo View::factory('partials/live_status', [
            'url'     => '/dossier/api/status/'.urlencode((string) $r['code']),
            'title'   => 'Собираем AI-досье',
            'status'  => (string) $r['status'] === 'new' ? 'queued' : 'running',
            'stage'   => (string) $r['progress'],
            'elapsed' => 0,
            'silent'  => NULL,
            'steps'   => [],
            'note'    => 'Обычно 2–4 минуты. Досье появится здесь — страница обновится сама',
            'stop'    => $mine
                ? '<form method="post" action="/dossier/stop/'.HTML::chars($r['code']).'" class="inline-flex">'
                    .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                    .'<button type="submit" title="Прервать разведку" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950/40"><i class="fas fa-stop"></i>Остановить</button>'
                    .'</form>'
                : '',
        ]); ?>
        <?php endif; ?>
        <?php if ($r['status'] === 'done' AND ! empty($result)): $sum600 = trim((string) ($result['summary'] ?? '')); if ($sum600 === '') { $sum600 = trim((string) ($result['cheatsheet']['about'] ?? '')); } if ($sum600 !== ''): $ids600 = (array) ($result['identifiers'] ?? []); ?>
        <div class="mt-3 rounded-xl border border-blue-200/80 bg-blue-50/70 px-4 py-3 text-sm text-gray-800 shadow-xs dark:border-blue-800/60 dark:bg-blue-950/30 dark:text-gray-100 sm:text-base">
            <p class="text-xs font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300">Кратко</p>
            <p class="mt-1"><?php echo HTML::chars($sum600); ?></p>
            <?php if (count(array_filter(array_map('strval', $ids600))) > 0): ?>
            <p class="mt-2 flex flex-wrap gap-1.5">
                <?php foreach (['inn' => 'ИНН', 'ogrn' => 'ОГРН', 'ogrnip' => 'ОГРНИП'] as $k600 => $t600): if ((string) ($ids600[$k600] ?? '') === '') continue; ?>
                <span class="rounded-md bg-white px-1.5 py-0.5 font-mono text-xs text-gray-600 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700"><?php echo $t600; ?> <?php echo HTML::chars((string) $ids600[$k600]); ?></span>
                <?php endforeach; ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; endif; ?>
        <?php if ($r['status'] === 'error'): ?>
        <p class="n2j-flash n2j-flash-err mt-3 text-xs block"><i class="fas fa-triangle-exclamation mr-1.5"></i><?php echo HTML::chars((string) $r['error']); ?></p>
        <?php endif; ?>
    </div>

    <?php $age600 = Service_Nj598_Dossier::age_days((array) $r); ?>
    <?php if ($r['status'] === 'done' AND $age600 >= 14): ?>
    <div class="n2j-flash n2j-flash-warn mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs">
        <i class="fas fa-hourglass-half shrink-0 text-amber-500"></i>
        <span class="min-w-0 flex-1"><b>Досье от <?php echo HTML::chars(Date::formatted_time((string) $r['created_at'], 'd.m.Y')); ?> (<?php echo (int) $age600; ?> дн. назад)</b> — разведка могла устареть: новости, суды и инвестиции меняются.</span>
        <?php if ($mine): ?>
        <form method="post" action="/dossier/retry/<?php echo HTML::chars($r['code']); ?>" class="shrink-0">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <button data-n2j-arm="Повторить?" type="submit" class="n2j-btn-process"><i class="fas fa-rotate-right mr-1.5"></i><span data-n2j-arm-label>Повторить разведку</span></button>
        </form>
        <?php endif; ?>
        <a href="/dossier?company=<?php echo urlencode((string) ($r['company'] ?: $r['query'])); ?>&amp;force=1" class="n2j-btn-secondary shrink-0">Создать новое</a>
    </div>
    <?php endif; ?>

    <?php if ($r['status'] === 'done' AND empty($result)): ?>
    <div class="n2j-flash n2j-flash-warn mt-4 block">
        <p class="font-bold"><i class="fas fa-triangle-exclamation mr-1.5"></i>Статус «готово», но отчёт пуст</p>
        <p class="mt-1 text-xs sm:text-sm">Воркер вернул пустой результат — обычно это сбой модели. Запустите разведку заново кнопкой ниже или сверьте модель воркера (NJ598_DOSSIER_MODEL).</p>
        <?php if ($mine): ?>
        <form method="post" action="/dossier/retry/<?php echo HTML::chars($r['code']); ?>" class="mt-3">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <button data-n2j-arm="Повторить?" type="submit" class="n2j-btn-process"><i class="fas fa-rotate-right mr-2"></i><span data-n2j-arm-label>Повторить разведку</span></button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($r['status'] === 'done' AND ! empty($result)): ?>
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Разведка (2/3) ============ -->
        <div class="min-w-0 space-y-4 md:col-span-2">

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="n2j-card border-rose-200 dark:border-rose-800/60">
                    <h2 class="n2j-h3 border-b border-gray-100 pb-3"><i class="fas fa-triangle-exclamation mr-1.5 text-rose-500"></i>Красные флаги</h2>
                    <?php echo $list($result['red_flags'] ?? [], 'fa-flag', 'text-rose-500', 'Явных красных флагов в открытых источниках не найдено.'); ?>
                </div>
                <div class="n2j-card border-emerald-200 dark:border-emerald-800/60">
                    <h2 class="n2j-h3 border-b border-gray-100 pb-3"><i class="fas fa-thumbs-up mr-1.5 text-emerald-500"></i>Сильные стороны</h2>
                    <?php echo $list($result['pros'] ?? [], 'fa-circle-check', 'text-emerald-500', 'Плюсы из открытых источников не найдены — данных мало.'); ?>
                </div>
            </div>

            <div class="n2j-card">
                <h2 class="n2j-h3 border-b border-gray-100 pb-3"><i class="fas fa-clipboard-question mr-1.5 text-blue-500"></i>Шпаргалка к собеседованию</h2>
                <?php if ( ! empty($result['cheatsheet']['about']) AND trim((string) ($result['summary'] ?? '')) === ''): ?>
                <p class="mt-3 text-sm text-gray-700 sm:text-base dark:text-gray-200"><?php echo HTML::chars((string) $result['cheatsheet']['about']); ?></p>
                <?php endif; ?>
                <h3 class="mt-4 text-sm font-bold text-gray-900 dark:text-white">О чём спросить тимлида (их реальные боли)</h3>
                <?php echo $list($result['cheatsheet']['questions_to_ask'] ?? [], 'fa-circle-question', 'text-blue-500', 'Не найдено.'); ?>
                <h3 class="mt-4 text-sm font-bold text-gray-900 dark:text-white">Вероятные вопросы скрининга</h3>
                <?php echo $list($result['cheatsheet']['likely_screen_questions'] ?? [], 'fa-terminal', 'text-blue-500', 'Не найдено — подготовьтесь по описанию вакансии.'); ?>
            </div>
        </div>

        <!-- ============ Источники (1/3) ============ -->
        <div class="min-w-0 space-y-5 md:col-span-1">
            <div class="n2j-card">
                <h3 class="n2j-h3 border-b border-gray-100 pb-3"><i class="fas fa-link mr-1.5 text-blue-500"></i>Источники</h3>
                <?php if (empty($result['sources'])): ?>
                <p class="n2j-hint mt-2">Список не сохранён.</p>
                <?php else: ?>
                <ul class="mt-2.5 space-y-2">
                    <?php foreach ((array) $result['sources'] as $s): $u = (string) ($s['url'] ?? ''); $t = (string) ($s['title'] ?? $u); ?>
                    <li class="min-w-0">
                        <?php if ($u !== ''): ?>
                        <a href="<?php echo HTML::chars($u); ?>" target="_blank" rel="nofollow noopener" class="block truncate text-xs font-semibold text-blue-600 hover:underline sm:text-sm dark:text-blue-400"><?php echo HTML::chars($t); ?></a>
                        <?php else: ?>
                        <span class="block truncate text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($t); ?></span>
                        <?php endif; ?>
                        <?php if ( ! empty($s['kind'])): ?><span class="text-xs text-gray-400"><?php echo HTML::chars((string) $s['kind']); ?></span><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">Как читать досье</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Всё из открытых источников: отзывы могут быть субъективны — ищите повторяющиеся темы.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Данных мало — это тоже ответ: не ищите «страшилок» там, где их нет.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Вопросы тимлиду задавайте спокойно — это признак зрелого кандидата.</span></li>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php echo View::factory('partials/live_status_js'); ?>

