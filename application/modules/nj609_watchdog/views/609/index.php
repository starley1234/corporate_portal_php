<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj609_watchdog: /watchdog — форма добавления, субъекты, лента событий.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$sev  = ['risk' => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
         'warn' => 'bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-300',
         'info' => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'];
?>
<div class="n2j-ds w-full space-y-6 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="grid gap-6 md:grid-cols-3 md:items-start">
        <div class="<?php echo $card; ?>">
            <h1 class="n2j-h1"><i class="fas fa-shield-halved mr-1.5 text-blue-500"></i>Кого сторожить</h1>
            <?php if ( ! $logged): ?>
            <?php echo Gate::wall('watchdog', [
                'icon'   => 'fas fa-shield-halved',
                'title'  => 'Сторож контрагентов — по бесплатному аккаунту',
                'text'   => 'Сторож ходит по реестрам и новостям круглосуточно, а события копятся в вашем журнале. Аккаунт нужен, чтобы наблюдение не тратилось анонимами, а сигналы приходили именно вам.',
                'points' => [
                    'Суды, банкротство и резкие новости по контрагентам',
                    'Только реальные сигналы риска — без информационного шума',
                    'Проверка каждые 6 часов и письмо о новом событии',
                    'Заметки к контрагенту: почему наблюдаете и что уже проверяли',
                ],
                'stats'  => ['Beta, бесплатно', 'проверка каждые 6 часов'],
                'cta'    => 'Войти и поставить на наблюдение',
                'note'   => 'Одно наблюдение — один контрагент; уведомления приходят в колокольчик и на почту аккаунта.',
            ]); ?>
            <?php else: ?>
            <form method="post" action="/watchdog" class="mt-3 space-y-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="text" name="company" required maxlength="300" placeholder="ООО «Ромашка» или сайт компании"
                       class="n2j-input w-full">
                <input type="text" name="inn" maxlength="20" placeholder="ИНН (если знаете)"
                       class="n2j-input w-full">
                <input type="text" name="note" maxlength="300" placeholder="Заметка: почему наблюдаю"
                       class="n2j-input w-full">
                <button type="submit" class="n2j-btn-process w-full">
                    <i class="fas fa-eye mr-1"></i>Наблюдать <span class="ml-1 rounded bg-blue-500/40 px-1.5 py-0.5 text-xs">beta</span>
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="space-y-4 md:col-span-2">
            <?php if ( ! $logged): ?>
            <?php // Гостю — рассказ о сервисе вместо пустого журнала событий. ?>
            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3"><i class="fas fa-route mr-1.5 text-blue-500"></i>Как это работает</h2>
                <ol class="mt-3 space-y-2.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2.5"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">1</span>
                        <span>Добавляете контрагента: название, сайт или ИНН — и заметку, зачем наблюдаете.</span></li>
                    <li class="flex gap-2.5"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">2</span>
                        <span>Сторож проверяет реестры, суды и новости каждые 6 часов и сравнивает с прошлой проверкой.</span></li>
                    <li class="flex gap-2.5"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">3</span>
                        <span>Сообщает только о настоящих сигналах риска — письмом и в колокольчик, без информационного шума.</span></li>
                </ol>
                <p class="n2j-hint mt-3 border-t border-gray-100 pt-3">
                    Сигналы по контрагенту собираются из открытых источников: ЕГРЮЛ/ЕГРИП, картотека судов, новости.
                </p>
            </div>
            <?php endif; ?>

            <?php if ($logged AND ! empty($subjects)): ?>
            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3">Под наблюдением</h2>
                <ul class="mt-2 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($subjects as $s): ?>
                    <?php
                    // Живой ряд / плашка итога — общий механизм модулей (эталон
                    // /trends): пока проверка идёт — спиннер, секундомер и этапы;
                    // после — одна строка «Готово · 10 мин назад · событий: 2».
                    $scode = (string) $s['code'];
                    $slive = isset($live[$scode]) ? $live[$scode] : NULL;
                    $slast = isset($last[$scode]) ? $last[$scode] : NULL;
                    $sstop = '<form method="post" class="shrink-0">'
                        .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                        .'<input type="hidden" name="act" value="stop_run">'
                        .'<input type="hidden" name="code" value="'.HTML::chars($scode).'">'
                        .'<button type="submit" class="rounded-lg border border-gray-200 px-2 py-1 text-sm font-semibold text-gray-500 transition hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700/60"><i class="fas fa-circle-stop"></i> Остановить</button>'
                        .'</form>';
                    ?>
                    <li class="py-2" data-nj609-code="<?php echo HTML::chars($scode); ?>">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="min-w-0 flex-1 truncate font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($s['company']); ?><?php echo $s['inn'] !== '' ? ' <span class="text-sm text-gray-400">ИНН '.HTML::chars($s['inn']).'</span>' : ''; ?></span>
                        <?php if ((int) $s['fresh'] > 0): ?><button type="button" data-nj609-fresh data-subject="<?php echo (int) $s['id']; ?>" title="Показать только сигналы этой компании (повторный клик — вернуть все)" class="n2j-btn-danger-soft n2j-btn-sm">сигналов: <?php echo (int) $s['fresh']; ?></button><?php endif; ?>
                        <button type="button" data-nj609-run title="Проверить сейчас, не дожидаясь расписания" class="n2j-btn-icon text-sm"><i class="fas fa-bolt"></i></button>
                        <form method="post" class="shrink-0">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="act" value="delete">
                            <input type="hidden" name="code" value="<?php echo HTML::chars($s['code']); ?>">
                            <button data-n2j-arm="Снять с наблюдения?" type="submit" class="n2j-btn-icon-danger text-xs"><i class="fas fa-trash-can"></i></button>
                        </form>
                        </div>
                        <div data-nj609-live-host>
                        <?php if ($slive): ?>
                        <?php echo View::factory('partials/live_status', [
                            'url'    => '/watchdog/api/status/'.urlencode($scode),
                            'title'  => 'Проверяю контрагента',
                            'status' => (string) $slive['status'],
                            'stage'  => (string) $slive['stage'],
                            'elapsed' => (int) $slive['elapsed_sec'],
                            'silent' => $slive['silent_sec'],
                            'steps'  => (array) ($slive['stages'] ?? []),
                            'note'   => 'События появятся в ленте ниже — страница обновится сама.',
                            'stop'   => $sstop,
                        ]); ?>
                        <?php elseif ($slast): ?>
                        <?php echo View::factory('partials/last_status', [
                            'status'      => (string) $slast['status'],
                            'finished_at' => (string) $slast['finished_at'],
                            'error'       => (string) $slast['error'],
                            'found'       => (int) ($slast['found'] ?? 0),
                            'found_label' => 'событий',
                            'steps'       => (array) ($slast['stages'] ?? []),
                            'elapsed'     => (int) $slast['elapsed_sec'],
                        ]); ?>
                        <?php endif; ?>
                        </div>
                        <?php /* Шаблон ряда: «Проверить сейчас» монтирует его СРАЗУ, без
                         * перезагрузки (общий N2JLive.mount). */ ?>
                        <template data-n2j-live-tpl="<?php echo HTML::chars($scode); ?>">
                        <?php echo View::factory('partials/live_status', [
                            'url'     => '/watchdog/api/status/'.urlencode($scode),
                            'title'   => 'Проверяю контрагента',
                            'status'  => 'queued',
                            'elapsed' => 0,
                            'silent'  => NULL,
                            'note'    => 'События появятся в ленте ниже — страница обновится сама.',
                            'stop'    => $sstop,
                        ]); ?>
                        </template>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php // Лента событий гостю не показывается: пустой журнал — это
                  // утверждение об аккаунте, которого у него ещё нет. ?>
            <?php if ($logged): ?>
            <div class="<?php echo $card; ?>">
                <h2 class="n2j-h3 flex items-center justify-between">События
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($feed); ?></span>
                </h2>
                <?php if (empty($feed)): ?>
                <p class="n2j-hint mt-3">Пока тихо. Добавьте контрагентов — сигналы появятся здесь.</p>
                <?php else: ?>
                <ul class="mt-1 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php foreach ($feed as $e): ?>
                    <li class="py-2.5" data-nj609-subject="<?php echo (int) $e['subject_id']; ?>" data-nj609-seen="<?php echo (string) $e['status'] === 'new' ? '0' : '1'; ?>">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-bold uppercase <?php echo $sev[$e['severity']] ?? $sev['info']; ?>"><?php echo HTML::chars($e['severity']); ?></span>
                            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400"><?php echo HTML::chars($e['company']); ?></span>
                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($e['title']); ?></span>
                            <?php if ($e['status'] === 'new'): ?><button type="button" data-nj609-seen="<?php echo (int) $e['id']; ?>" title="Отметить прочитанным" class="rounded-md px-1.5 text-xs text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700">ок</button><?php endif; ?>
                        </div>
                        <?php if ((string) $e['summary'] !== ''): ?><p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $e['summary']); ?></p><?php endif; ?>
                        <?php if ($e['url'] !== ''): ?><a href="<?php echo HTML::chars($e['url']); ?>" target="_blank" rel="noopener nofollow" class="mt-1 inline-block text-xs text-blue-600 hover:underline dark:text-blue-400">источник</a><?php endif; ?>
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
/* nj609/watchdog: AJAX-отметка события прочитанным — без перезагрузки ленты */
(function () {
    var CSRF = <?php echo json_encode(Security::token()); ?>;

    document.querySelectorAll('[data-nj609-seen]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var fd = new FormData();
            fd.set('csrf', CSRF);
            fd.set('act', 'seen');
            fd.set('id', btn.getAttribute('data-nj609-seen'));
            fd.set('ajax', '1');
            btn.disabled = true;
            fetch('/watchdog', { method: 'POST', body: fd, cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) { if (res.ok) btn.remove(); else btn.disabled = false; })
                .catch(function () { btn.disabled = false; });
        }); // конец click-обработчика «увидел»
    }); // конец forEach «увидел»

    // «сигналов: N» — фильтр ленты: только сигналы этой компании; повторный клик — все
    document.querySelectorAll('[data-nj609-fresh]').forEach(function (badge) {
        badge.addEventListener('click', function () {
            var sid = badge.getAttribute('data-subject');
            var active = badge.getAttribute('data-active') === '1';
            document.querySelectorAll('[data-nj609-fresh]').forEach(function (b) {
                b.removeAttribute('data-active');
                b.classList.remove('ring-2', 'ring-rose-400');
            });
            document.querySelectorAll('[data-nj609-subject]').forEach(function (li) {
                li.classList.remove('hidden');
            });
            if (active) return;
            badge.setAttribute('data-active', '1');
            badge.classList.add('ring-2', 'ring-rose-400');
            document.querySelectorAll('[data-nj609-subject]').forEach(function (li) {
                var show = li.getAttribute('data-nj609-subject') === sid
                    && li.getAttribute('data-nj609-seen') === '0';
                li.classList.toggle('hidden', ! show);
            });
        });
    });
})();
</script>
<?php endif; ?>

<?php /* Общий JS живого ряда: «Проверить сейчас» монтирует ряд сразу, без
 * перезагрузки, а страница сама обновляется на терминальном статусе. */ ?>
<?php echo View::factory('partials/live_status_js'); ?>

<script>
/* «Проверить сейчас» — AJAX: задача сразу в очередь nj120 + живой ряд */
(function () {
    var CSRF = <?php echo json_encode(Security::token()); ?>;

    document.querySelectorAll('[data-nj609-run]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Проверяю…';
            var li = btn.closest('li');
            var code = li ? (li.querySelector('input[name="code"]') || {}).value : '';
            if (!code) return;
            var fd = new FormData();
            fd.set('csrf', CSRF); fd.set('act', 'run_now'); fd.set('code', code); fd.set('ajax', '1');
            btn.disabled = true;
            fetch(location.pathname, { method: 'POST', body: fd, cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false; btn.innerHTML = original;
                    N2J.toast(res.flash || (res.ok ? 'Запущено' : 'Не получилось'), res.ok ? 3500 : 0, res.ok ? 'ok' : 'err');

                    // ряд «идёт проверка» виден СРАЗУ (владелец: «почему я его
                    // увидел только после перезагрузки?»)
                    if (res.ok && window.N2JLive) {
                        var host = li ? li.querySelector('[data-nj609-live-host]') : null;

                        if (host) {
                            var prev = host.querySelector('[data-n2j-live]');
                            if (prev) prev.remove();
                            window.N2JLive.mount(host, code);
                        }
                    }
                })
                .catch(function () { btn.disabled = false; btn.innerHTML = original; });
        });
    });
})();
</script>
