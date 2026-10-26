<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj600/admin — «Аккумулятор предпочтений»: как работает, KPI, топы,
 * профили владельцев (у кого какие предпочтения), ИИ-сжатие воркером.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dashboard" class="transition hover:text-blue-600 dark:hover:text-blue-400">Кабинет</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Предпочтения · управление</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-brain mr-2 text-blue-500"></i>Аккумулятор предпочтений
        </h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-database text-[11px]"></i>БД компактная
        </span>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">
        Пассивный сбор контекста: разделы, поисковые фразы, источники прихода.
        Сырьё живёт <?php echo (int) $stats['raw_ttl_days']; ?> дн. и чистится автоматически; в профилях — только компактная суммаризация.
    </p>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <?php /* Живой статус-ряд сжатия профилей (общий механизм, эталон /trends):
     * видно этапы воркера и секундомер, а после — плашку итога. */ ?>
    <div class="mt-4" data-nj600-live-host>
        <?php if ( ! empty($live)): ?>
        <?php echo View::factory('partials/live_status', [
            'url'     => '/prefs/api/status',
            'title'   => 'Сжимаю профили',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) ($live['stages'] ?? []),
            'note'    => 'ИИ-резюме появятся в таблице профилей — страница обновится сама.',
            'stop'    => '<form method="post" class="shrink-0" data-nj600-stop>'
                .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                .'<button type="submit" class="rounded-lg border border-gray-200 px-2 py-1 text-sm font-semibold text-gray-500 transition hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700/60"><i class="fas fa-circle-stop"></i> Остановить</button>'
                .'</form>',
        ]); ?>
        <?php elseif ( ! empty($last)): ?>
        <?php echo View::factory('partials/last_status', [
            'status'      => (string) $last['status'],
            'finished_at' => (string) $last['finished_at'],
            'error'       => (string) $last['error'],
            'found'       => NULL,
            'steps'       => (array) ($last['stages'] ?? []),
            'elapsed'     => (int) $last['elapsed_sec'],
        ]); ?>
        <?php endif; ?>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Событий всего</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400"><i class="fas fa-chart-line text-sm"></i></span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) $stats['events_total']; ?></span>
            </div>
            <p class="n2j-hint">за 24 ч: <?php echo (int) $stats['events_24h']; ?></p>
        </div>
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Профилей</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400"><i class="fas fa-users text-sm"></i></span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) $stats['profiles']; ?></span>
            </div>
            <p class="n2j-hint">пользователи + анонимы (ubid)</p>
        </div>
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Размер сырья</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400"><i class="fas fa-box-archive text-sm"></i></span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo HTML::chars($stats['size_events']); ?></span>
            </div>
            <p class="n2j-hint">TTL <?php echo (int) $stats['raw_ttl_days']; ?> дн.</p>
        </div>
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Размер профилей</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400"><i class="fas fa-feather text-sm"></i></span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo HTML::chars($stats['size_profile']); ?></span>
            </div>
            <p class="n2j-hint">компактная суммаризация</p>
        </div>
    </div>

        <!-- Популярность модулей: заходы vs результат -->
        <div class="n2j-card p-0 mt-6">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                <div class="flex items-center gap-2.5">
                    <h2 class="n2j-h3">Популярность модулей (7 дней)</h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($mods); ?></span>
                </div>
                <span class="text-xs text-gray-400">заходы против результата: кто «приходит и уходит, не добившись»</span>
            </div>
            <div class="overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
                <table class="n2j-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pr-3 pl-4 sm:pl-5">Раздел</th>
                            <th class="py-2.5 px-3">Заходы (7д)</th>
                            <th class="py-2.5 px-3">Задачи</th>
                            <th class="py-2.5 px-3">Готово</th>
                            <th class="py-2.5 px-3">Ошибки</th>
                            <th class="py-2.5 pr-4 pl-3 sm:pr-5">Сигнал</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                        <?php foreach ($mods as $m): ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="py-2.5 pr-3 pl-4 font-semibold text-gray-900 sm:pl-5 dark:text-white"><a href="/<?php echo HTML::chars($m['section']); ?>" class="hover:text-blue-600 dark:hover:text-blue-400"><?php echo HTML::chars($m['section']); ?></a></td>
                            <td class="py-2.5 px-3 text-xs text-gray-600 dark:text-gray-300"><?php echo (int) $m['views']; ?></td>
                            <td class="py-2.5 px-3 text-xs text-gray-600 dark:text-gray-300"><?php echo (int) $m['jobs']; ?></td>
                            <td class="py-2.5 px-3 text-xs <?php echo $m['done'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400'; ?>"><?php echo (int) $m['done']; ?></td>
                            <td class="py-2.5 px-3 text-xs <?php echo $m['failed'] > 0 ? 'text-rose-500' : 'text-gray-400'; ?>"><?php echo (int) $m['failed']; ?></td>
                            <td class="py-2.5 pr-4 pl-3 sm:pr-5">
                                <?php if ($m['lost']): ?>
                                <span class="n2j-badge n2j-badge-run" title="Заходы есть, а готовых задач нет — модуль не доводит до результата"><i class="fas fa-user-slash mr-1 text-[10px]"></i>уходят не добившись</span>
                                <?php elseif ($m['done'] > 0): ?>
                                <span class="text-xs text-emerald-600 dark:text-emerald-400"><i class="fas fa-check mr-1 text-[10px]"></i>работает</span>
                                <?php else: ?><span class="text-xs text-gray-300 dark:text-gray-600">мало данных</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($mods)): ?>
                        <tr><td colspan="6" class="py-4 pl-4 text-xs text-gray-400 sm:pl-5">Данных пока нет — копятся из переходов и задач.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <div class="space-y-6 md:col-span-2">
            <div class="n2j-card p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <div class="flex items-center gap-2.5">
                        <h2 class="n2j-h3">Профили владельцев: у кого какие предпочтения</h2>
                        <span class="n2j-badge n2j-badge-wait"><?php echo count($profiles); ?></span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                    <a href="/nj600/admin/reco" class="n2j-btn-secondary"><i class="fas fa-wand-magic-sparkles text-xs text-gray-400"></i>Рекомендации · надзор</a>
                    <form method="post" action="/nj600/admin">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="compact">
                        <button data-n2j-arm="Запустить сжатие?" type="submit" class="n2j-btn-process">
                            <i class="fas fa-wand-magic-sparkles"></i><span data-n2j-arm-label>Сжать профили воркером</span>
                        </button>
                    </form>
                    </div>
                </div>

                <div class="overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
                    <table class="n2j-table whitespace-nowrap">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                                <th class="py-2.5 pr-3 pl-4 sm:pl-5">Владелец</th>
                                <th class="py-2.5 px-3">Активность</th>
                                <th class="py-2.5 px-3">Топ-раздел / фраза</th>
                                <th class="py-2.5 px-3">Был(а)</th>
                                <th class="py-2.5 pr-4 pl-3 sm:pr-5">ИИ-резюме</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                            <?php foreach ($profiles as $p): ?>
                            <tr class="align-top transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                                <td class="py-2.5 pr-3 pl-4 sm:pl-5"><a href="/nj600/admin/owner/<?php echo rawurlencode($p['owner_key']); ?>" class="font-mono text-xs font-bold text-blue-600 hover:underline dark:text-blue-400" title="Предпочтения и действия владельца"><?php echo HTML::chars($p['owner_key']); ?></a></td>
                                <td class="py-2.5 px-3 text-xs text-gray-600 dark:text-gray-300"><?php echo (int) $p['pages']; ?> стр · <?php echo (int) $p['searches']; ?> поиск.</td>
                                <td class="max-w-[260px] py-2.5 px-3 text-xs">
                                    <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo $p['top_section'] !== '' ? HTML::chars($p['top_section']) : '—'; ?></span>
                                    <span class="block truncate text-gray-400" title="<?php echo HTML::chars($p['top_phrase']); ?>"><?php echo $p['top_phrase'] !== '' ? HTML::chars($p['top_phrase']) : ''; ?></span>
                                </td>
                                <td class="py-2.5 px-3 text-xs text-gray-500 dark:text-gray-400"><?php echo $p['last_seen'] !== '' ? HTML::chars(Date::formatted_time($p['last_seen'], 'd.m.y H:i')) : '—'; ?></td>
                                <td class="max-w-[320px] py-2.5 pr-4 pl-3 sm:pr-5">
                                    <?php if ($p['llm_summary'] !== ''): ?>
                                    <p class="whitespace-normal text-xs text-gray-700 dark:text-gray-300"><?php echo HTML::chars(mb_substr($p['llm_summary'], 0, 160)); ?></p>
                                    <p class="n2j-hint mt-0.5 font-mono"><?php echo HTML::chars($p['llm_model']); ?> · <?php echo HTML::chars(Date::formatted_time($p['llm_at'], 'd.m.y')); ?></p>
                                    <?php else: ?>
                                    <span class="text-xs text-gray-300 dark:text-gray-600">ещё не сжато</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($profiles)): ?>
                            <tr><td colspan="5" class="py-4 pl-4 text-xs text-gray-400 sm:pl-5">Профилей пока нет — они появятся после первых переходов.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="n2j-card">
                    <p class="text-sm font-bold text-gray-900 dark:text-white">Топ разделов платформы</p>
                    <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                        <?php foreach ($stats['top_sections'] as $r): ?>
                        <li class="flex items-center justify-between gap-3 border-b border-gray-50 pb-1.5 dark:border-gray-700/50">
                            <span class="font-semibold"><?php echo HTML::chars($r['section']); ?></span>
                            <span class="text-xs text-gray-400"><?php echo (int) $r['cnt']; ?></span>
                        </li>
                        <?php endforeach; ?>
                        <?php if ( ! $stats['top_sections']): ?><li class="text-xs text-gray-400">Пока пусто — данные появятся после первых переходов.</li><?php endif; ?>
                    </ul>
                </div>
                <div class="n2j-card">
                    <p class="text-sm font-bold text-gray-900 dark:text-white">Топ поисковых фраз</p>
                    <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                        <?php foreach ($stats['top_phrases'] as $r): ?>
                        <li class="flex items-center justify-between gap-3 border-b border-gray-50 pb-1.5 dark:border-gray-700/50">
                            <span class="min-w-0 truncate font-semibold"><?php echo HTML::chars($r['phrase']); ?></span>
                            <span class="text-xs text-gray-400"><?php echo (int) $r['cnt']; ?></span>
                        </li>
                        <?php endforeach; ?>
                        <?php if ( ! $stats['top_phrases']): ?><li class="text-xs text-gray-400">Пока пусто — фразы копит глобальный поиск.</li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="space-y-5">
            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-circle-info text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold">Как работает модуль</h3>
                </div>
                <ol class="mt-2.5 list-decimal space-y-1.5 pl-4 text-xs text-gray-600 dark:text-gray-300">
                    <li>Каждая страница (GET) и каждый поиск пишут событие: раздел, фраза, referrer/utm.</li>
                    <li>Сразу при записи счётчики инкрементально сливаются в компактный профиль владельца (u:id / g:ubid) — таблица не растёт.</li>
                    <li>Сырьё (nj_600_events) живёт <?php echo (int) $stats['raw_ttl_days']; ?> дн., чистится само (~1% запросов) и кнопкой.</li>
                    <li>«Сжать профили воркером»: скрипты склеивают близкие фразы/разделы (0 токенов), затем LLM пишет человеческое резюме — задел под проактивный ИИ.</li>
                    <li>Профили доступны и агентам: MCP-инструменты платформы (mcp_profile_by_key, nj600_recommend).</li>
                    <li>Поверх профилей — <a href="/nj600/admin/reco" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">рекомендации под надзором</a>: правила администратора, похожие посетители, CTR. Карточка владельца — по клику на ключ в таблице.</li>
                </ol>
            </div>

            <div class="n2j-card hidden md:block">
                <p class="text-sm font-bold text-gray-900 dark:text-white">Обслуживание</p>
                <p class="n2j-hint">Очистить сырьё сейчас — старше <?php echo (int) $stats['raw_ttl_days']; ?> дней. Суммаризация не затрагивается.</p>
                <form method="post" action="/nj600/admin" class="mt-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="prune">
                    <button data-n2j-arm="Очистить сырьё?" type="submit"
                            class="n2j-btn-danger-soft">
                        <i class="fas fa-broom mr-2"></i><span data-n2j-arm-label>Очистить сырьё</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>

<script>
/* «Остановить» в ряду: форма без JS осталась бы обычным POST'ом на страницу,
 * где такого действия нет — отправляем её в API сжатия. */
(function () {
    'use strict';

    document.addEventListener('submit', function (e) {
        var form = e.target && e.target.closest ? e.target.closest('[data-nj600-stop]') : null;

        if (!form) return;

        e.preventDefault();

        fetch('/prefs/api/cancel', { method: 'POST', cache: 'no-store', credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) })
            .then(function () { window.location.reload(); })
            .catch(function () { window.location.reload(); });
    });
})();
</script>
