<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /content/<slug> — статья: место под иллюстрацию (пока промпт), текст,
 * mermaid-диаграмма, источники.
 */

/* Markdown-лайт: абзацы, ## / ###, списки, **жирный**, `код` — без внешних библиотек. */
$sources602 = json_decode((string) ($r['sources'] ?? ''), TRUE) ?: [];
$nj602_src_n = count($sources602);

$nj602_inline = function ($s) use ($nj602_src_n) {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('~\*\*(.+?)\*\*~s', '<strong class="font-semibold text-gray-900 dark:text-white">$1</strong>', $s);
    $s = preg_replace('~`([^`]+)`~', '<code class="rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-[0.85em] text-blue-700 dark:bg-gray-900 dark:text-blue-300">$1</code>', $s);
    // Ссылки на источники [n] (исследовательский конвейер): верхний индекс → якорь в списке «Источники»
    $s = preg_replace_callback('~\[(\d{1,2})\]~', function ($m) use ($nj602_src_n) {
        $n = (int) $m[1];

        if ($n < 1 OR $n > $nj602_src_n)
        {
            return $m[0];
        }

        return '<sup><a href="#nj602-src-'.$n.'" class="rounded px-1 font-semibold text-blue-600 no-underline hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-950/40" title="Источник '.$n.'">['.$n.']</a></sup>';
    }, $s);

    return $s;
};

$nj602_md = function ($src) use ($nj602_inline) {
    $html = '';
    $par  = [];
    $ul   = FALSE;
    $tbl  = FALSE;
    $close_tbl = function () use (&$tbl, &$html) {
        if ($tbl)
        {
            $html .= '</tbody></table></div>';
            $tbl = FALSE;
        }
    };
    $flush = function () use (&$par, &$html, $nj602_inline) {
        if ($par)
        {
            $html .= '<p class="mt-4 leading-relaxed">'.implode('<br>', array_map($nj602_inline, $par)).'</p>';
            $par = [];
        }
    };
    $close_ul = function () use (&$ul, &$html) {
        if ($ul)
        {
            $html .= '</ul>';
            $ul = FALSE;
        }
    };

    foreach (preg_split('~\r?\n~', (string) $src) as $ln)
    {
        $t = trim($ln);

        if ($t === '')
        {
            $flush();
            $close_ul();
            $close_tbl();
            continue;
        }

        // Markdown-таблица: строки «| a | b |», разделитель «|---|---|» пропускаем
        if (strpos($t, '|') === 0 AND substr($t, -1) === '|')
        {
            $flush();
            $close_ul();

            if (preg_match('~^\|[\s:\-|]+\|$~', $t))
            {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($t, '|')));

            if ( ! $tbl)
            {
                $html .= '<div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700"><table class="w-full text-left text-xs sm:text-sm"><thead class="bg-gray-50 text-gray-900 dark:bg-gray-800 dark:text-white"><tr>';

                foreach ($cells as $c)
                {
                    $html .= '<th class="px-3 py-2 font-semibold">'.$nj602_inline($c).'</th>';
                }

                $html .= '</tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">';
                $tbl = TRUE;
                continue;
            }

            $html .= '<tr>';

            foreach ($cells as $c)
            {
                $html .= '<td class="px-3 py-2 align-top">'.$nj602_inline($c).'</td>';
            }

            $html .= '</tr>';
            continue;
        }

        $close_tbl();

        if (preg_match('~^>\s?(.+)$~', $t, $m))
        {
            $flush();
            $close_ul();
            $html .= '<blockquote class="mt-4 border-l-4 border-blue-300 pl-4 italic text-gray-600 dark:border-blue-700 dark:text-gray-300">'.$nj602_inline($m[1]).'</blockquote>';
            continue;
        }

        if (preg_match('~^###\s+(.+)$~', $t, $m))
        {
            $flush();
            $close_ul();
            $html .= '<h3 class="mt-6 text-base font-bold text-gray-900 sm:text-lg dark:text-white">'.$nj602_inline($m[1]).'</h3>';
            continue;
        }

        if (preg_match('~^##\s+(.+)$~', $t, $m))
        {
            $flush();
            $close_ul();
            $html .= '<h2 class="mt-7 border-b border-gray-100 pb-2 text-lg font-bold text-gray-900 sm:text-xl dark:border-gray-700/60 dark:text-white">'.$nj602_inline($m[1]).'</h2>';
            continue;
        }

        if (preg_match('~^[-*]\s+(.+)$~', $t, $m))
        {
            $flush();

            if ( ! $ul)
            {
                $html .= '<ul class="mt-3 list-disc space-y-1.5 pl-5">';
                $ul = TRUE;
            }

            $html .= '<li>'.$nj602_inline($m[1]).'</li>';
            continue;
        }

        $par[] = $t;
    }

    $flush();
    $close_ul();
    $close_tbl();

    return $html;
};

$quality602 = json_decode((string) ($r['quality'] ?? ''), TRUE) ?: [];
$tags602 = array_slice(array_map('strval', json_decode((string) ($r['tags'] ?? '[]'), TRUE) ?: []), 0, 6);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <a href="/content" class="transition hover:text-blue-600 dark:hover:text-blue-400">Журнал</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars(mb_substr($r['title'] !== '' ? $r['title'] : $r['topic'], 0, 60)); ?></span>
    </nav>

    <header class="mt-2 border-b border-transparent">
        <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
            <h1 class="n2j-h1"><?php echo HTML::chars($r['title'] !== '' ? $r['title'] : $r['topic']); ?></h1>
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
                <i class="fas fa-feather-pointed text-[11px]"></i>Редакция N2J
            </span>
        </div>
        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 sm:text-sm dark:text-gray-400">
            <span class="inline-flex items-center gap-1.5"><i class="fas fa-calendar-check text-xs"></i><?php echo $r['published_at'] ? HTML::chars(Date::formatted_time((string) $r['published_at'], 'd.m.Y')) : '—'; ?></span>
            <span>•</span>
            <span class="inline-flex items-center gap-1.5"><i class="fas fa-clock text-xs"></i><?php echo max(1, (int) ceil(mb_strlen((string) $r['body_md']) / 1200)); ?> мин чтения</span>
        </div>
        <?php if ( ! empty($tags602)): ?>
        <div class="mt-2 flex flex-wrap gap-1.5">
            <?php foreach ($tags602 as $tg): ?>
            <span class="n2j-badge n2j-badge-info"><?php echo HTML::chars($tg); ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </header>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
        <article class="min-w-0 text-sm leading-relaxed text-gray-700 dark:text-gray-300 lg:col-span-2 sm:text-base">
            <?php if ((string) $r['summary'] !== ''): ?>
            <p class="text-base font-medium leading-relaxed text-gray-900 dark:text-gray-100 sm:text-lg"><?php echo HTML::chars($r['summary']); ?></p>
            <?php endif; ?>

            <!-- Иллюстрация: показываем только готовый файл (промпты — служебные, публично не светим) -->
            <?php if ((string) $r['image_file'] !== ''): ?>
            <figure class="mt-5">
                <img src="<?php echo HTML::chars($r['image_file']); ?>" alt="<?php echo HTML::chars(mb_substr($r['image_prompt'] ?: $r['title'], 0, 200)); ?>" class="w-full rounded-xl border border-gray-200 dark:border-gray-700">
                <?php if ((string) $r['summary'] !== ''): ?><figcaption class="mt-1.5 text-xs text-gray-400"><?php echo HTML::chars(mb_substr($r['summary'], 0, 160)); ?></figcaption><?php endif; ?>
            </figure>
            <?php endif; ?>

            <div class="nj602-md-body"><?php echo $nj602_md($r['body_md']); ?></div>

            <?php if (trim((string) $r['mermaid']) !== ''): ?>
            <section class="n2j-card mt-8">
                <h2 class="n2j-h3"><i class="fas fa-diagram-project mr-1.5 text-blue-500"></i>Схема</h2>
                <div id="nj602-mermaid-box" class="mt-3 overflow-x-auto text-sm"><?php echo HTML::chars($r['mermaid']); ?></div>
                <pre id="nj602-mermaid-src" class="hidden"><?php echo HTML::chars($r['mermaid']); ?></pre>
            </section>
            <?php endif; ?>

        </article>

        <?php
        /** nj602_cross: перелинковка — 2-3 инструмента N2J, подходящих к теме статьи. */
        $nj602_rel = function (string $title, string $tags): array {
            $hay = mb_strtolower($title . ' ' . $tags);
            $map = [
                ['/radar', 'Радар заказов', 'Находит в интернете свежие закупки и заказы по вашему профилю.', 'закуп|тендер|заказ|подряд|44-фз|223-фз'],
                ['/leads', 'Поток заказчиков', 'ИИ ищет компании под ваш оффер и готовит зацепки для писем.', 'лид|клиент|продаж|b2b|оффер|партнёр'],
                ['/industry', 'Карта отрасли', 'Авто-отчёт: кто на рынке, что и как производят, где ниши.', 'отрасл|рынок|производств|обзор|конкурент|аналитик'],
                ['/watchdog', 'Сторож контрагентов', 'Следит за контрагентами: суды, банкротство, новости риска.', 'контрагент|риск|проверк|банкрот|арбитраж|должник'],
                ['/dossier', 'Досье компании', 'Профиль организации: реквизиты, активности, чем живёт.', 'компани|организац|инн|огрн|компаньон|фирм'],
                ['/profile', 'Профиль компании', 'Опишите свой бизнес — сервисы будут работать под вас.', 'профил|бизнес|описан|своё|свой'],
                ['/coldmail', 'Холодные письма', 'Персональные письма новым клиентам от вашего имени.', 'письм|email|рассылк|холодн|mail|обращен'],
                ['/inbox', 'Входящие под контролем', 'ИИ разбирает почту и готовит черновики ответов.', 'почт|входящ|переписк|инбокс|письм|ответ'],
            ];
            $out = [];

            foreach ($map as [$url, $name, $desc, $re]) {
                if (preg_match('/' . $re . '/u', $hay)) {
                    $out[] = [$url, $name, $desc];
                }

                if (count($out) >= 3) {
                    break;
                }
            }

            // ничего по теме не подошло — общий набор
            if ( ! $out) {
                $out = [[$map[0][0], $map[0][1], $map[0][2]], [$map[2][0], $map[2][1], $map[2][2]]];
            }

            return $out;
        };
        $nj602_rel_items = $nj602_rel((string) $r['title'], (string) ($r['tags'] ?? ''));
        ?>
        <aside class="space-y-4">
            <?php if ( ! empty($is_admin)): ?>
            <!-- Модераторская карточка: видна только тем, у кого право nj602.admin -->
            <div class="rounded-xl border border-amber-200 bg-amber-50/70 p-4 shadow-xs dark:border-amber-800/60 dark:bg-amber-950/30 sm:p-5" id="nj602-mod">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 text-amber-900 dark:text-amber-100">
                        <i class="fas fa-user-shield text-amber-600 dark:text-amber-400"></i>
                        <h3 class="text-sm font-bold">Модерация</h3>
                    </div>
                    <span class="n2j-badge n2j-badge-wait" id="nj602-mod-status"><?php echo HTML::chars((string) $r['status']); ?></span>
                </div>
                <div class="mt-3 flex flex-wrap gap-2" id="nj602-mod-actions">
                    <?php if (in_array($r['status'], ['review'], TRUE)): ?>
                    <button type="button" data-nj602-mod-act="publish" class="n2j-btn-cta n2j-btn-sm">Опубликовать</button>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['published'], TRUE)): ?>
                    <button data-n2j-arm="Снять с публикации?" type="button" data-nj602-mod-act="reject" class="n2j-btn-secondary n2j-btn-sm">Снять с публикации</button>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['error', 'rejected', 'collecting'], TRUE)): ?>
                    <button type="button" data-nj602-mod-act="retry" class="n2j-btn-secondary n2j-btn-sm">Перезапустить сбор</button>
                    <?php endif; ?>
                    <?php if (trim((string) $r['body_md']) !== '' AND $r['status'] !== 'collecting'): ?>
                    <button type="button" data-nj602-mod-act="regen_mermaid" class="n2j-btn-secondary n2j-btn-sm">
                        <i class="fas fa-diagram-project text-[11px]"></i><span data-regen-text>Перегенерировать схему</span>
                    </button>
                    <?php endif; ?>
                    <a href="/nj602/admin" class="n2j-btn-secondary n2j-btn-sm">Вся модерация</a>
                    <button data-n2j-arm="Удалить статью?" type="button" data-nj602-mod-act="delete" class="n2j-btn-danger n2j-btn-sm">Удалить</button>
                </div>
                <?php if ( ! empty($quality602)): ?>
                <?php $qs = (float) ($quality602['score'] ?? 0); $qc = $qs >= 7 ? 'text-emerald-700 dark:text-emerald-300' : ($qs >= 4.5 ? 'text-amber-700 dark:text-amber-300' : 'text-rose-700 dark:text-rose-300'); ?>
                <div class="mt-3 border-t border-amber-200/70 pt-3 text-xs text-amber-900 dark:border-amber-800/50 dark:text-amber-100" title="Метрика предметности исследовательского конвейера: ссылки на источники, плотность цифр, штампы, оценка ИИ-рецензента">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span>Предметность: <b class="<?php echo $qc; ?>"><?php echo HTML::chars((string) $qs); ?>/10</b></span>
                        <?php if ((int) ($quality602['critic'] ?? -1) >= 0): ?><span>рецензент <b><?php echo (int) $quality602['critic']; ?></b>/10</span><?php endif; ?>
                        <span>фактов <b><?php echo (int) ($quality602['facts'] ?? 0); ?></b> (с цифрами <?php echo (int) ($quality602['facts_numeric'] ?? 0); ?>)</span>
                        <span>ссылок <b><?php echo (int) ($quality602['citations'] ?? 0); ?></b> на <?php echo (int) ($quality602['sources_cited'] ?? 0); ?> ист.</span>
                        <?php if ( ! empty($quality602['revised'])): ?><span class="n2j-badge n2j-badge-info">переписана по рецензии</span><?php endif; ?>
                    </div>
                    <?php if ( ! empty($quality602['banned'])): ?>
                    <p class="mt-1 text-rose-700 dark:text-rose-300">Штампы: <?php echo HTML::chars(implode(', ', array_map('strval', (array) $quality602['banned']))); ?></p>
                    <?php endif; ?>
                    <?php if ((string) ($quality602['thesis'] ?? '') !== ''): ?>
                    <p class="mt-1 text-amber-800 dark:text-amber-200">Тезис: <?php echo HTML::chars((string) $quality602['thesis']); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <script>
            (function () {
                var box = document.getElementById('nj602-mod');
                if (!box) return;
                var CSRF = <?php echo json_encode(Security::token()); ?>,
                    ID = <?php echo (int) $r['id']; ?>,
                    CODE = <?php echo json_encode((string) $r['code']); ?>;

                function toast(text, ok) {
                    // Общий тост app.js; результат действия держим дольше обычного «ok».
                    N2J.toast(text, ok ? 3500 : 0, ok ? 'ok' : 'err');
                }

                box.querySelectorAll('[data-nj602-mod-act]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var act = btn.getAttribute('data-nj602-mod-act');

                        if (act === 'regen_mermaid') { window.nj602RegenMermaid(btn, { csrf: CSRF, id: ID, code: CODE, toast: toast }); return; }

                        /* опасные действия (data-n2j-arm): первый клик взводит кнопку
                           в app.js и сюда не доходит, второй — выполняет */
                        var fd = new FormData();
                        fd.set('csrf', CSRF); fd.set('act', act); fd.set('id', ID); fd.set('ajax', '1');
                        btn.disabled = true;
                        fetch('/nj602/admin', { method: 'POST', body: fd, cache: 'no-store',
                                headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(function (r) { return r.json(); })
                            .then(function (res) {
                                toast(res.flash || (res.ok ? 'Готово' : 'Не получилось'), !!res.ok);
                                if (res.ok && act === 'delete') { location.href = '/nj602/admin'; return; }
                                if (res.ok && act === 'publish') {
                                    var st = document.getElementById('nj602-mod-status');
                                    if (st) st.textContent = 'published';
                                    btn.remove();
                                }
                                btn.disabled = false;
                            })
                            .catch(function () { toast('Сеть не ответила', false); btn.disabled = false; });
                    });
                });
            })();
            </script>
            <?php endif; ?>
            <div class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 shadow-xs dark:border-blue-800/60 dark:bg-blue-950/30 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-toolbox text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold">Инструменты N2J по теме</h3>
                </div>
                <ul class="mt-3 space-y-3">
                    <?php foreach ($nj602_rel_items as [$ru, $rn, $rd]): ?>
                    <li>
                        <a href="<?php echo $ru; ?>" class="text-sm font-bold text-blue-700 hover:underline dark:text-blue-300"><?php echo $rn; ?></a>
                        <p class="mt-0.5 text-xs leading-snug text-gray-600 dark:text-gray-400"><?php echo $rd; ?></p>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="n2j-card">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-link text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold">Источники</h3>
                </div>
                <?php if (empty($sources602)): ?>
                <p class="n2j-hint mt-2">Источники не сохранены.</p>
                <?php else: ?>
                <ol class="mt-3 space-y-2 text-xs">
                    <?php foreach (array_values($sources602) as $i => $s): ?>
                    <li class="flex items-start gap-2 scroll-mt-24" id="nj602-src-<?php echo $i + 1; ?>">
                        <span class="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded bg-blue-50 px-1 font-mono text-xs font-bold text-blue-700 dark:bg-blue-950/50 dark:text-blue-300"><?php echo $i + 1; ?></span>
                        <span class="min-w-0">
                            <a href="<?php echo HTML::chars((string) ($s['url'] ?? '#')); ?>" rel="nofollow noopener" target="_blank" class="break-words font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) (($s['title'] ?? '') !== '' ? $s['title'] : ($s['url'] ?? ''))); ?></a>
                            <span class="block truncate text-gray-400 dark:text-gray-500"><?php echo HTML::chars((string) parse_url((string) ($s['url'] ?? ''), PHP_URL_HOST)); ?></span>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
            </div>

            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-feather-pointed text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold">О наших материалах</h3>
                </div>
                <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                    Мы пишем коротко и по делу: каждый материал опирается на открытые источники
                    (список — ниже), а перед публикацией текст и схемы выверяет редакция.
                    Инструменты N2J, о которых мы пишем, — <a href="/" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">на главной</a>.
                </p>
            </div>

            <div class="n2j-card hidden md:block">
                <a href="/content" class="n2j-btn-secondary w-full"><i class="fas fa-arrow-left mr-1.5 text-xs text-gray-400"></i>Все статьи</a>
            </div>
        </aside>
    </div>
</div>

<?php if ( ! empty($is_admin)): ?><?php echo View::factory('602/regen_js'); ?><?php endif; ?>
<?php echo View::factory('602/script'); ?>
