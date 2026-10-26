<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ОБЩАЯ пагинация списков: «Показано X–Y из Z» + страницы + «строк на странице».
 *
 * Жила в модуле трендов (prl593), 08.10.2026 переехала в общие partials: тот же
 * список нужен каталогу обзоров (/trends) и каталогу отчётов
 * ИИ-радара (/airadar/catalog) — две копии разъехались бы на первой правке.
 *
 * Эталон v6: кнопки — компонент .prl-page (текущая синяя, aria-current), кегль
 * text-sm (просьба владельца «шрифт побольше»). Выбор «строк на странице» (10/20/50/100) запоминается
 * браузером (ключ prl:per:<путь>): владелец просил «не хватает количества строк
 * на страницу, шрифт побольше».
 *
 * Параметры: total, page, pages, per, url_base, params[], anchor, per_options[].
 * Скрипт печатается один раз на страницу ($GLOBALS['__prl_per_js']); стрелка
 * селекта — общий .prl-select из app.css.
 */
$total        = (int) $total;
$page         = max(1, (int) $page);
$pages        = max(1, (int) $pages);
$per          = (int) $per;
$per_options  = isset($per_options) ? (array) $per_options : [10, 20, 50, 100];
$anchor       = (string) (isset($anchor) ? $anchor : '');
$params       = (array) (isset($params) ? $params : []);

if ( ! in_array($per, $per_options, TRUE))
{
    $per_options[] = $per;
    sort($per_options);
}

$from = $total > 0 ? ($page - 1) * $per + 1 : 0;
$to   = min($total, $page * $per);

// Ссылка на страницу: сохраняем поиск (q) и выбранный размер страницы,
// возвращаем якорь раздела (список «Мои обзоры» живёт в #my).
$mk_link = function ($p) use ($url_base, $params, $per, $anchor) {
    $q = http_build_query($params + ['per' => $per, 'page' => (int) $p]);
    return HTML::chars($url_base.'?'.$q.($anchor !== '' ? '#'.$anchor : ''));
};

// Окно номеров: pages ≤ 7 — все; иначе 1 … p−1 p p+1 … last
$items = [];

if ($pages <= 7)
{
    for ($i = 1; $i <= $pages; $i++)
    {
        $items[] = $i;
    }
}
else
{
    $items[] = 1;

    if ($page > 3)
    {
        $items[] = '…';
    }

    for ($i = max(2, $page - 1); $i <= min($pages - 1, $page + 1); $i++)
    {
        $items[] = $i;
    }

    if ($page < $pages - 2)
    {
        $items[] = '…';
    }

    $items[] = $pages;
}

// База для селектора «строк на странице» — те же параметры, без page/per
$per_base = $url_base.($params ? '?'.http_build_query($params) : '');
$btn      = 'prl-page text-sm font-semibold';
$btn_off  = $btn.' pointer-events-none cursor-not-allowed text-gray-300 dark:text-gray-600';
?>
<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-700/60">

    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-gray-500 dark:text-gray-400">
        <span>Показано <strong class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $from; ?>–<?php echo (int) $to; ?></strong> из <strong class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $total; ?></strong></span>

        <label class="inline-flex items-center gap-2">
            <span>Строк:</span>
            <select data-prl-per data-prl-forget
                    data-base="<?php echo HTML::chars($per_base); ?>"
                    data-anchor="<?php echo HTML::chars($anchor); ?>"
                    aria-label="Сколько строк показывать на странице"
                    class="prl-select min-h-[36px] rounded-lg border border-gray-300 bg-white py-1 pl-3 text-sm font-semibold text-gray-700 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                <?php foreach ($per_options as $o): ?>
                <option value="<?php echo (int) $o; ?>"<?php echo (int) $o === $per ? ' selected' : ''; ?>><?php echo (int) $o; ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <?php if ($pages > 1): ?>
    <nav class="inline-flex items-center gap-1" aria-label="Страницы">
        <?php if ($page > 1): ?>
        <a href="<?php echo $mk_link($page - 1); ?>" rel="prev" aria-label="Предыдущая страница" class="<?php echo $btn; ?>">‹</a>
        <?php else: ?>
        <span class="<?php echo $btn_off; ?>" aria-hidden="true">‹</span>
        <?php endif; ?>

        <?php foreach ($items as $it): ?>
            <?php if ($it === '…'): ?>
            <span class="inline-flex h-9 items-center justify-center px-1 text-sm text-gray-400 dark:text-gray-500">…</span>
            <?php elseif ((int) $it === $page): ?>
            <span aria-current="page" class="<?php echo $btn; ?>"><?php echo (int) $it; ?></span>
            <?php else: ?>
            <a href="<?php echo $mk_link($it); ?>" class="<?php echo $btn; ?>"><?php echo (int) $it; ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($page < $pages): ?>
        <a href="<?php echo $mk_link($page + 1); ?>" rel="next" aria-label="Следующая страница" class="<?php echo $btn; ?>">›</a>
        <?php else: ?>
        <span class="<?php echo $btn_off; ?>" aria-hidden="true">›</span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</div>

<?php if (empty($GLOBALS['__prl_per_js'])): $GLOBALS['__prl_per_js'] = TRUE; ?>
<script>
/* «Строк: N» — управление страницей, а не поле ввода.
 *
 * Владелец: «при загрузке показывай фактически (а не выбор из браузера, а то
 * нелогично и не перещёлкнуть)». Поэтому:
 *   1) значение в селекте ВСЕГДА равно тому, сколько строк реально показано
 *      (data-prl-forget — общая память PRLPrefs его не подменяет);
 *   2) выбор пользователя запоминается, но применяется честным переходом: если
 *      в адресе per нет, а в браузере он сохранён — один раз переходим на
 *      сохранённое значение (селект и таблица снова совпадают);
 *   3) явный per в адресе сильнее памяти (ссылку можно передать коллеге). */
document.querySelectorAll('[data-prl-per]').forEach(function (sel) {
    var KEY = 'prl:per:' + location.pathname.replace(/\/+$/, '');

    function target(value) {
        var url;
        try {
            url = new URL(sel.getAttribute('data-base') || location.pathname, location.origin);
        } catch (e) {
            return null;
        }
        url.searchParams.set('per', value);
        url.searchParams.delete('page');
        var anchor = sel.getAttribute('data-anchor') || '';
        return url.toString() + (anchor ? '#' + anchor : '');
    }

    sel.addEventListener('change', function () {
        try { localStorage.setItem(KEY, String(sel.value)); } catch (e) { /* приватный режим */ }
        var url = target(sel.value);
        if (url) location.href = url;
    });

    var keep = null, has = false;
    try { keep = localStorage.getItem(KEY); } catch (e) { keep = null; }
    try { has = new URL(location.href).searchParams.has('per'); } catch (e) { has = false; }

    var known = keep && Array.prototype.some.call(sel.options, function (o) {
        return o.value === keep;
    });

    if (!has && known && keep !== sel.value) {
        var url = target(keep);
        if (url) location.replace(url);
    }
});
</script>
<?php endif; ?>
