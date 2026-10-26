<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj610_inbox: лента входящих с черновиками ответов.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$prio = ['high' => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
         'normal' => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300',
         'low' => 'bg-gray-50 text-gray-400 dark:bg-gray-800 dark:text-gray-500'];
?>
<div class="n2j-ds w-full space-y-6 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-ok font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <?php if ( ! $configured): ?>
    <div class="n2j-flash n2j-flash-warn text-xs block">
        Ящик не настроен: задайте NJ610_INBOX_HOST/USER/PASS/FOLDER в .env (см. .env.example) — воркер начнёт разбирать входящие по расписанию nj603.
    </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-2">
        <h1 class="n2j-h1"><i class="fas fa-inbox mr-2 text-blue-500"></i>Входящие под контролем</h1>
        <button type="button" id="nj610-run" title="Прочитать ящик сейчас, не дожидаясь расписания" class="n2j-btn-process ml-auto">
            <i class="fas fa-envelope-open-text"></i>Проверить почту сейчас
        </button>
        <button type="button" id="nj610-refresh" title="Обновить ленту и счётчики" class="n2j-btn-secondary">
            <i class="fas fa-rotate"></i>Обновить
        </button>
    </div>
    <div data-nj610-live-host>
        <?php if ( ! empty($live)): ?>
        <?php echo View::factory('partials/live_status', [
            'url'     => '/inbox/api/status',
            'title'   => 'Читаю почту',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) ($live['stages'] ?? []),
            'note'    => 'Письма и черновики появятся в ленте — страница обновится сама.',
            'stop'    => '<form method="post" class="shrink-0" data-nj610-stop>'
                .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                .'<button type="submit" class="rounded-lg border border-gray-200 px-2 py-1 text-sm font-semibold text-gray-500 transition hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700/60"><i class="fas fa-circle-stop"></i> Остановить</button>'
                .'</form>',
        ]); ?>
        <?php elseif ( ! empty($last)): ?>
        <?php echo View::factory('partials/last_status', [
            'status'      => (string) $last['status'],
            'finished_at' => (string) $last['finished_at'],
            'error'       => (string) $last['error'],
            'found'       => Service_Nj610_Inbox::found_since($last['started_at']),
            'found_label' => 'писем',
            'steps'       => (array) ($last['stages'] ?? []),
            'elapsed'     => (int) $last['elapsed_sec'],
        ]); ?>
        <?php endif; ?>
        <?php /* Шаблон ряда: «Проверить почту сейчас» монтирует его сразу */ ?>
        <template data-n2j-live-tpl="inbox">
        <?php echo View::factory('partials/live_status', [
            'url'     => '/inbox/api/status',
            'title'   => 'Читаю почту',
            'status'  => 'queued',
            'elapsed' => 0,
            'silent'  => NULL,
            'note'    => 'Письма и черновики появятся в ленте — страница обновится сама.',
            'stop'    => '<form method="post" class="shrink-0" data-nj610-stop>'
                .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                .'<button type="submit" class="rounded-lg border border-gray-200 px-2 py-1 text-sm font-semibold text-gray-500 transition hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700/60"><i class="fas fa-circle-stop"></i> Остановить</button>'
                .'</form>',
        ]); ?>
        </template>
    </div>
    <p class="-mt-4 text-sm text-gray-500 dark:text-gray-400">Свежие письма попадают сюда после опроса ящика (расписание nj603) — черновик ответа ИИ прилагается.</p>

    <div class="flex flex-wrap gap-3" id="nj610-stats">
        <div class="<?php echo $card; ?> flex-1"><p class="text-xs font-bold uppercase text-gray-400">Новых</p><p class="text-2xl font-black text-gray-900 dark:text-white"><?php echo (int) $stats['new']; ?></p></div>
        <div class="<?php echo $card; ?> flex-1"><p class="text-xs font-bold uppercase text-gray-400">Черновиков</p><p class="text-2xl font-black text-blue-600 dark:text-blue-400"><?php echo (int) $stats['drafted']; ?></p></div>
        <div class="<?php echo $card; ?> flex-1"><p class="text-xs font-bold uppercase text-gray-400">Обработано</p><p class="text-2xl font-black text-gray-900 dark:text-white"><?php echo (int) $stats['done']; ?></p></div>
    </div>

    <div class="<?php echo $card; ?>" id="nj610-feed">
        <?php if (empty($feed)): ?>
        <p class="n2j-hint">Входящих пока нет.</p>
        <?php else: ?>
        <ul class="divide-y divide-gray-50 dark:divide-gray-700/40">
            <?php foreach ($feed as $m): ?>
            <li class="py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-2 py-0.5 text-xs font-bold uppercase <?php echo $prio[$m['priority']] ?? $prio['normal']; ?>"><?php echo HTML::chars($m['priority']); ?></span>
                    <?php if ((string) $m['category'] !== ''): ?><span class="n2j-badge n2j-badge-info"><?php echo HTML::chars($m['category']); ?></span><?php endif; ?>
                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $m['subject'] ?: '(без темы)'); ?></span>
                    <span class="text-xs text-gray-400"><?php echo HTML::chars((string) $m['from_addr']); ?></span>
                </div>
                <?php if ((string) $m['draft'] !== ''): ?>
                <div class="mt-2 rounded-lg border border-blue-100 bg-blue-50/50 p-2.5 dark:border-blue-900 dark:bg-blue-950/30">
                    <p class="text-xs font-bold uppercase text-blue-400">черновик ответа</p>
                    <p class="mt-1 whitespace-pre-wrap text-xs text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $m['draft']); ?></p>
                </div>
                <?php endif; ?>
                <form method="post" class="mt-2 flex gap-2">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>">
                    <button type="submit" name="st" value="done" class="n2j-btn-secondary n2j-btn-sm">ответил</button>
                    <button type="submit" name="st" value="ignored" class="n2j-btn-secondary n2j-btn-sm">в игнор</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>

<script>
/* «Проверить почту сейчас»: задача в очередь + живой ряд сразу, без перезагрузки */
(function () {
    var btn = document.getElementById('nj610-run');
    var host = document.querySelector('[data-nj610-live-host]');

    if (btn) {
        btn.addEventListener('click', function () {
            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Ставлю задачу…';

            fetch('/inbox/api/run', {
                method: 'POST', cache: 'no-store', credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: (function () { var fd = new FormData(); fd.set('csrf', <?php echo json_encode((string) $csrf); ?>); return fd; })()
            })
                .then(function (r) { return r.json().catch(function () { return {}; }); })
                .then(function (res) {
                    btn.disabled = false;
                    btn.innerHTML = original;

                    if (res.ok && window.N2JLive && host) {
                        var prev = host.querySelector('[data-n2j-live]');
                        if (prev) prev.remove();
                        window.N2JLive.mount(host, 'inbox');
                    } else if (!res.ok) {
                        N2J.toast(res.error || 'Не получилось поставить задачу', 0, 'err');
                    }
                })
                .catch(function () { btn.disabled = false; btn.innerHTML = original; });
        });
    }

    /* «Остановить» в ряду — форма без JS, но с подтверждением через AJAX-ответ */
    document.addEventListener('submit', function (e) {
        var form = e.target.closest ? e.target.closest('[data-nj610-stop]') : null;
        if (!form) return;
        e.preventDefault();
        fetch('/inbox/api/cancel', { method: 'POST', cache: 'no-store', credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) })
            .then(function () { window.location.reload(); })
            .catch(function () { window.location.reload(); });
    });
})();
</script>

<script>
/* «Обновить»: подтягиваем свежую ленту и счётчики без перезагрузки страницы */
(function () {
    var btn = document.getElementById('nj610-refresh');

    if (!btn) return;

    var original = btn.innerHTML;

    btn.addEventListener('click', function () {
        if (btn.disabled) return;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Обновляю…';

        fetch(location.pathname, { cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');

                var stats = doc.getElementById('nj610-stats');
                var feed  = doc.getElementById('nj610-feed');
                var mineS = document.getElementById('nj610-stats');
                var mineF = document.getElementById('nj610-feed');

                if (stats && mineS) mineS.replaceWith(stats);
                if (feed && mineF) mineF.replaceWith(feed);

                btn.disabled = false;
                btn.innerHTML = original;
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = original;
            });
    });
})();
</script>
