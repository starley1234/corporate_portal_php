<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj606_leads: /leads — форма потока, мои потоки, лента компаний с действиями.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
?>
<div class="n2j-ds w-full space-y-6 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="grid gap-6 md:grid-cols-3 md:items-start">
        <div class="<?php echo $card; ?>">
            <h1 class="n2j-h1"><i class="fas fa-user-plus mr-1.5 text-blue-500"></i>Кого искать</h1>
            <?php if ( ! $logged): ?>
            <?php echo Gate::wall('leads', [
                'icon'   => 'fas fa-user-plus',
                'title'  => 'Лиды — по бесплатному аккаунту',
                'text'   => 'Поток собирает компании под ваш оффер и оценивает перспективность по открытым данным. Аккаунт нужен, чтобы сбор не расходовался анонимами, а найденные компании и оценки оставались у вас.',
                'points' => [
                    'Список компаний под оффер: чем занимаются, сайт, зацепка',
                    'Оценка перспективности 0–100 по каждой компании',
                    'Профиль компании и черновик письма — в один клик',
                    'Мои потоки: повторный запуск и история подбора',
                ],
                'stats'  => ['Beta, бесплатно', '12 часов тишины между сборами'],
                'cta'    => 'Войти и запустить поток',
                'note'   => 'Один запуск — один сбор компаний; из лида дальше ведут «Холодное письмо» и «Серии касаний».',
            ]); ?>
            <?php else: ?>
            <form method="post" action="/leads" class="mt-3 space-y-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <div>
                    <label class="n2j-label">Название потока</label>
                    <input type="text" name="title" maxlength="120" placeholder="Оптовики — СЗФО"
                           class="n2j-input mt-1 w-full">
                </div>
                <div>
                    <label class="n2j-label">Какие компании <span class="text-rose-500">*</span></label>
                    <textarea name="industry" required rows="3" maxlength="300" placeholder="Например: производители упаковки из гофрокартона; оптовые поставщики стройматериалов"
                              autocomplete="off" autocapitalize="off" spellcheck="false"
                              class="n2j-textarea mt-1 w-full"><?php echo HTML::chars((string) Arr::get($_GET, 'industry', '')); ?></textarea>
                </div>
                <div>
                    <label class="n2j-label">Регион <span class="font-normal text-gray-400">(необязательно)</span></label>
                    <input type="text" name="region" maxlength="120" placeholder="Казань, Поволжье"
                           autocomplete="off" autocapitalize="off" spellcheck="false"
                           value="<?php echo HTML::chars((string) Arr::get($_GET, 'region', '')); ?>"
                           class="n2j-input mt-1 w-full">
                </div>
                <button type="submit" class="n2j-btn-process w-full">
                    <i class="fas fa-magnifying-glass mr-1"></i>Запустить поток<?php if (Service_Nj601_Billing::enabled()): ?><span class="ml-1 rounded bg-blue-500/40 px-1.5 py-0.5 text-xs">1 кредит</span><?php endif; ?>
                </button>
            </form>
            <p class="n2j-hint mt-3 border-t border-gray-100 pt-2">Один запуск = один сбор компаний (12 часов тишины между сборами потока). Из лида — в один клик профиль и письмо.</p>
            <?php endif; ?>
        </div>

        <div class="space-y-4 md:col-span-2">
            <?php if ( ! $logged): ?>
            <?php // Гостю — тот же рассказ о сервисе, что и вошедшему, только без
                  // пустых списков его потоков и компаний. ?>
            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3"><i class="fas fa-route mr-1.5 text-blue-500"></i>Как это работает</h2>
                <ol class="mt-3 space-y-2.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2.5"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">1</span>
                        <span>Описываете, какие компании нужны: отрасль, размер, регион — своими словами.</span></li>
                    <li class="flex gap-2.5"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">2</span>
                        <span>Сервис собирает компании по открытым источникам и оценивает перспективность 0–100.</span></li>
                    <li class="flex gap-2.5"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">3</span>
                        <span>Из лида — в один клик профиль компании и черновик письма; отметки «взял / не нужен» остаются в аккаунте.</span></li>
                </ol>
                <p class="n2j-hint mt-3 border-t border-gray-100 pt-3">
                    Уже собирали профиль компании в другом сервисе — он подтянется сюда и в письмо.
                </p>
            </div>
            <?php endif; ?>

            <?php if ($logged AND ! empty($flows)): ?>
            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3">Мои потоки</h2>
                <ul class="mt-2 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($flows as $f): $fcode = (string) $f['code']; ?>
                    <?php
                    /* Опции живого ряда — ОДНИ на оба применения (текущий ряд и
                     * шаблон мгновенного монтажа «Собрать»): подпись и кнопка
                     * «Остановить» правятся здесь, а не в двух местах. */
                    $row_opts = [
                        'url'   => '/leads/api/status/'.urlencode($fcode),
                        'title' => 'Собираем компании',
                        'note'  => 'Компании появятся в ленте — страница обновится сама',
                        'stop'  => '<form method="post" class="inline-flex">'
                            .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                            .'<input type="hidden" name="act" value="stop_run">'
                            .'<input type="hidden" name="code" value="'.HTML::chars($fcode).'">'
                            .'<button type="submit" title="Прервать текущий сбор" class="inline-flex min-h-[36px] items-center gap-1 rounded-lg border border-rose-200 px-2.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 sm:min-h-0 sm:py-1 dark:border-rose-800 dark:hover:bg-rose-950/40"><i class="fas fa-stop"></i>Остановить</button>'
                            .'</form>',
                    ];
                    ?>
                    <li class="py-1.5" data-nj606-flow-row="<?php echo (int) $f['id']; ?>">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                            <span class="min-w-0 flex-1 truncate font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($f['title']); ?><?php echo $f['region'] !== '' ? '<span class="font-normal text-gray-400"> · '.HTML::chars($f['region']).'</span>' : ''; ?></span>
                            <?php if ((int) $f['fresh'] > 0): ?><button type="button" data-nj606-fresh data-flow="<?php echo (int) $f['id']; ?>" title="Показать в ленте только новые этого потока (повторный клик — вернуть все)" class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-bold text-blue-600 transition hover:bg-blue-100 dark:bg-blue-950/60 dark:text-blue-300 dark:hover:bg-blue-900/60">новых: <?php echo (int) $f['fresh']; ?></button><?php endif; ?>
                            <span class="flex shrink-0 items-center gap-1">
                                <button type="button" data-nj606-run title="Запустить сбор сейчас, не дожидаясь расписания" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-bolt"></i>Собрать</button>
                                <form method="post" class="shrink-0">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <input type="hidden" name="act" value="delete">
                                    <input type="hidden" name="code" value="<?php echo HTML::chars($fcode); ?>">
                                    <button data-n2j-confirm="Поток удалится вместе со всеми лидами." data-n2j-title="Удалить поток?" type="submit" class="n2j-btn-icon-danger sm:w-auto"><i class="fas fa-trash-can"></i></button>
                                </form>
                            </span>
                        </div>

                        <div data-nj606-live-host>
                            <?php if (isset($live[$fcode])): $lv = $live[$fcode]; ?>
                            <?php echo View::factory('partials/live_status', array_merge($row_opts, [
                                'status'  => (string) $lv['status'],
                                'stage'   => (string) $lv['stage'],
                                'elapsed' => (int) $lv['elapsed_sec'],
                                'silent'  => $lv['silent_sec'],
                                'steps'   => (array) ($lv['stages'] ?? []),
                            ])); ?>
                            <?php elseif (isset($last[$fcode])): $ls = $last[$fcode]; ?>
                            <div data-nj606-last>
                                <?php echo View::factory('partials/last_status', [
                                    'status'      => (string) $ls['status'],
                                    'finished_at' => (string) $ls['finished_at'],
                                    'error'       => (string) $ls['error'],
                                    'found'       => Service_Nj606_Leads::found_since((int) $f['id'], (string) $ls['started_at']),
                                    'found_label' => 'компаний',
                                    // «собрано по эвристике» и прочие приписки воркера
                                    'note'        => (string) ($ls['note'] ?? ''),
                                    'steps'       => (array) ($ls['stages'] ?? []),
                                    'elapsed'     => (int) ($ls['elapsed_sec'] ?? 0),
                                ]); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php /* Шаблон живого ряда: «Собрать» монтирует его мгновенно (N2JLive.mount). */ ?>
                        <template data-nj606-live-tpl="<?php echo HTML::chars($fcode); ?>">
                            <?php echo View::factory('partials/live_status', array_merge($row_opts, [
                                'status'  => 'queued',
                                'stage'   => '',
                                'elapsed' => 0,
                                'silent'  => NULL,
                            ])); ?>
                        </template>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php // Пустая лента лидов гостю не показывается: «Пока пусто» — это
                  // утверждение о его аккаунте, а аккаунта у гостя ещё нет. ?>
            <?php if ($logged): ?>
            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3 flex items-center justify-between">Компании
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($feed); ?></span>
                </h2>
                <?php if (empty($feed)): ?>
                <p class="n2j-hint mt-3">Пока пусто. Создайте поток — компании появятся здесь.</p>
                <?php else: ?>
                <ul class="mt-1 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($feed as $l): ?>
                    <li class="py-3" data-lead-flow="<?php echo (int) $l['flow_id']; ?>" data-lead-status="<?php echo HTML::chars($l['status']); ?>">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="n2j-badge <?php echo (int) $l['score'] >= 70 ? 'n2j-badge-ok' : 'n2j-badge-wait'; ?>"><?php echo (int) $l['score']; ?></span>
                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($l['company']); ?></span>
                            <?php if ($l['site'] !== ''): $site_url = preg_match('~^https?://~i', $l['site']) ? $l['site'] : 'https://'.$l['site']; ?><a href="<?php echo HTML::chars($site_url); ?>" target="_blank" rel="noopener nofollow" class="text-xs font-mono text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars(preg_replace('~^https?://~i', '', $l['site'])); ?></a><?php endif; ?>
                            <?php if ($l['inn'] !== ''): ?><span class="text-xs text-gray-400">ИНН <?php echo HTML::chars($l['inn']); ?></span><?php endif; ?>
                        </div>
                        <?php if ((string) $l['what'] !== ''): ?><p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $l['what']); ?></p><?php endif; ?>
                        <?php if ((string) $l['hook'] !== ''): ?><p class="mt-1 text-sm text-blue-700 dark:text-blue-300"><i class="fas fa-anchor mr-1 text-blue-300"></i><?php echo HTML::chars((string) $l['hook']); ?></p><?php endif; ?>
                        <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                            <form method="post" action="/leads" class="inline">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="act" value="to_profile">
                                <input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>">
                                <button type="submit" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-id-card mr-1"></i>Профиль</button>
                            </form>
                            <?php $cm = $l['site'] !== '' ? $l['site'] : $l['company']; ?>
                            <a href="/coldmail?company=<?php echo urlencode($cm); ?>" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-envelope mr-1"></i>Письмо</a>
                            <?php if ($l['status'] === 'new'): ?>
                            <!-- AJAX-отметка: строка не теряется из-за перезагрузки -->
                            <button type="button" data-nj606-st="taken" data-nj606-id="<?php echo (int) $l['id']; ?>" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-check mr-1"></i>взял</button>
                            <button type="button" data-nj606-st="gone" data-nj606-id="<?php echo (int) $l['id']; ?>" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-xmark mr-1"></i>не нужен</button>
                            <?php else: ?>
                            <span class="rounded-md bg-gray-100 px-2 py-1 text-gray-500 dark:bg-gray-800 dark:text-gray-400"><?php echo $l['status'] === 'taken' ? 'взят' : 'не нужен'; ?></span>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($logged): ?>
<script>
/* nj606/leads: AJAX-отметка лида «взял/не нужен» — без перезагрузки ленты */
(function () {
    var CSRF = <?php echo json_encode(Security::token()); ?>;

    document.querySelectorAll('[data-nj606-st]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrap = btn.parentElement;
            var fd = new FormData();
            fd.set('csrf', CSRF);
            fd.set('act', 'lead_status');
            fd.set('id', btn.getAttribute('data-nj606-id'));
            fd.set('st', btn.getAttribute('data-nj606-st'));
            fd.set('ajax', '1');
            btn.disabled = true;
            fetch('/leads', { method: 'POST', body: fd, cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.ok) {
                        var chip = document.createElement('span');
                        chip.className = 'rounded-md bg-gray-100 px-2 py-1 text-gray-500 dark:bg-gray-800 dark:text-gray-400';
                        chip.textContent = btn.getAttribute('data-nj606-st') === 'taken' ? 'взят' : 'не нужен';
                        wrap.querySelectorAll('[data-nj606-st]').forEach(function (b) { b.remove(); });
                        wrap.appendChild(chip);
                    }
                })
                .catch(function () { btn.disabled = false; });
        });
    });
})();
</script>
<?php endif; ?>

<?php echo View::factory('partials/live_status_js'); ?>

<script>
/* «Запустить сбор сейчас» — AJAX: задача сразу в очередь nj120 */
(function () {
    var CSRF = <?php echo json_encode(Security::token()); ?>;

    // «Собрать»: при клике — крутящееся колёсико вместо молнии
    document.querySelectorAll('[data-nj606-run]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var li = btn.closest('li');
            var code = li ? (li.querySelector('input[name="code"]') || {}).value : '';
            if (!code) return;
            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Запускаю…';
            var fd = new FormData();
            fd.set('csrf', CSRF); fd.set('act', 'run_now'); fd.set('code', code); fd.set('ajax', '1');
            fetch(location.pathname, { method: 'POST', body: fd, cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false;
                    btn.innerHTML = original;
                    N2J.toast(res.flash || (res.ok ? 'Запущено' : 'Не получилось'), res.ok ? 3500 : 0, res.ok ? 'ok' : 'err');

                    // Ряд «идёт сбор» появляется СРАЗУ, из шаблона страницы —
                    // раньше он был виден только после перезагрузки.
                    if (res.ok && window.N2JLive) {
                        var host = li.querySelector('[data-nj606-live-host]');
                        var prev = li.querySelector('[data-nj606-last]');
                        if (prev) prev.remove();
                        window.N2JLive.mount(host, code);
                    }
                })
                .catch(function () { btn.disabled = false; btn.innerHTML = original; });
        });
    });

    // «новых: N» — фильтр ленты: только новые выбранного потока; повторный клик — все
    document.querySelectorAll('[data-nj606-fresh]').forEach(function (badge) {
        badge.addEventListener('click', function () {
            var flow = badge.getAttribute('data-flow');
            var active = badge.getAttribute('data-active') === '1';
            // снять подсветку и фильтр со всех бейджей
            document.querySelectorAll('[data-nj606-fresh]').forEach(function (b) {
                b.removeAttribute('data-active');
                b.classList.remove('ring-2', 'ring-blue-400');
            });
            document.querySelectorAll('[data-lead-flow]').forEach(function (li) {
                li.classList.remove('hidden');
            });
            if (active) return; // повторный клик — сброс фильтра
            badge.setAttribute('data-active', '1');
            badge.classList.add('ring-2', 'ring-blue-400');
            document.querySelectorAll('[data-lead-flow]').forEach(function (li) {
                var show = li.getAttribute('data-lead-flow') === flow
                    && li.getAttribute('data-lead-status') === 'new';
                li.classList.toggle('hidden', ! show);
            });
        });
    });
})();
</script>
