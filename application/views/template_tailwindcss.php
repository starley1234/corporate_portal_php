<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!DOCTYPE html>
<html lang="ru" class="scroll-smooth">
<head>
    <meta charset="UTF-8"> 
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
    // SEO-шапка (21.09): канонический адрес, OG-теги, robots для служебных путей
    $__req = Request::current();
    $__path = ($__req !== NULL) ? '/'.ltrim($__req->uri(), '/') : '/';
    $__canonical = URL::base(true).ltrim($__path, '/');
    // Скобки обязательны: OR слабее присваивания — без них признак служебной
    // страницы зависел только от первого условия, и «/admin…» уезжал в индекс.
    $__is_service = ((bool) preg_match('~^/(nj[0-9]+|dashboard|auth|tasks|migrate_pg|prefs|update|erp[0-9a-z_]*|cron)(/|$)~i', $__path)
        OR stripos($__path, '/admin') !== FALSE);
?>
    <title><?php echo HTML::chars(isset($title) && $title ? $title : 'N2J.ru — ИИ-инструменты для работы и карьеры'); ?></title>
    <meta name="description" content="<?php echo HTML::chars(isset($meta['description']) && $meta['description'] ? $meta['description'] : 'Коллекция ИИ-сервисов N2J.ru: документы организации по реквизитам, AI-досье на работодателя, вакансии, 3D-модели словами, тренды GitHub, справочник организаций ЕГРЮЛ.'); ?>">
    <meta name="keywords" content="<?php echo HTML::chars(isset($meta['keywords']) && $meta['keywords'] ? $meta['keywords'] : 'ИИ инструменты, документы по ОГРН, досье на работодателя, поиск вакансий, 3D модель по описанию, справочник организаций, ЕГРЮЛ, тренды github'); ?>">
    <meta name="robots" content="<?php echo $__is_service ? 'noindex, follow' : 'index, follow'; ?>">
    <link rel="canonical" href="<?php echo HTML::chars($__canonical); ?>">

    <meta property="og:title" content="<?php echo HTML::chars(isset($title) && $title ? $title : 'N2J.ru — ИИ-инструменты для работы и карьеры'); ?>">
    <meta property="og:description" content="<?php echo HTML::chars(isset($meta['description']) && $meta['description'] ? $meta['description'] : 'Коллекция ИИ-сервисов: документы, досье, вакансии, 3D, тренды.'); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo HTML::chars($__canonical); ?>">
    <meta property="og:site_name" content="N2J.ru">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:image" content="<?php echo HTML::chars(isset($meta['og_image']) && $meta['og_image'] ? $meta['og_image'] : URL::site('assets/images/default_og_image.jpg', true)); ?>">
    <meta name="twitter:card" content="summary_large_image">
    
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon/favicon-16x16.png">
    
    <!--
        Ни одного внешнего хоста. Раньше страница тянула Tailwind, Font Awesome
        и jQuery с трёх чужих CDN без SRI и без фолбэка: блокировка любого из
        них оставляла сайт без стилей, иконок или скриптов.

        app.css — СОБРАННЫЙ Tailwind (npm run build:css), а не компилятор в
        браузере: Play CDN разбирал вёрстку заново на каждой загрузке страницы.
        Настройки темы лежат в tailwind.config.js в корне — инлайновых
        tailwind.config больше нет, их было четыре штуки и они расходились.

        CI пересобирает css и падает, если он разошёлся с вёрсткой; отдельная
        проверка сверяет, что каждый класс из разметки в сборке есть
        (.tools/tailwind_classes_lint.py). Возврат на CDN ловит
        .tools/assets_cdn_lint.py.
    -->
    <link rel="stylesheet" href="/assets/css/app.css" />
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" />
    <script src="/assets/vendor/jquery/jquery-3.7.1.min.js"></script>
    
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'dark';
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
    
    <?php /* стили макета (панель, тема, уведомления, ползунки, печать) — в .tools/tailwind/input.css, раздел 4 */ ?>
	
	<script src="/assets/js/template.js"></script>
	<script src="/assets/js/app.js"></script>
	<script src="/assets/js/navpalette.js"></script>

    <?php if (isset($styles) && is_array($styles)): ?>
        <?php foreach ($styles as $style): ?>
            <?php if (isset($style['file'])): ?>
                <link rel="stylesheet" href="<?php echo HTML::chars($style['file']); ?>" <?php echo (isset($style['media']) ? 'media="'.HTML::chars($style['media']).'"' : ''); ?>>
            <?php elseif (isset($style['code'])): ?>
                <?php /* design-audit: allow style — API add_inline_style() у Controller_Erp100_Base (сейчас никем не вызывается) */ ?>
                <style><?php echo $style['code']; ?></style>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
	
	  <?php if (isset($scripts)): ?>
		<?php foreach ($scripts as $script): ?>
		  <?php if (isset($script['file'])): ?>
			<?php echo HTML::script($script['file'], array('defer' => $script['defer'] ?? false)); ?>
		  <?php elseif (isset($script['code'])): ?>
			<script><?php echo $script['code']; ?></script>
		  <?php elseif (isset($script['src'])): ?>
			<script src="<?php echo HTML::chars($script['src']); ?>"></script>
		  <?php endif; ?>
		<?php endforeach; ?>
	  <?php endif; ?>
	  
		<?php 
		if (isset ($javascript_libraries))
		{
			$main_lib = View::factory('/main_lib');
			$main_lib->javascript_libraries = $javascript_libraries;
			echo $main_lib;
		}
		?>

    <?php 
    try {
        echo View::factory('metrika');
    } catch (Exception $e) {
        // Вид метрики не найден
    }
    ?>
	  
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 min-h-screen transition-colors duration-200">
    <script>/* состояние панели — ДО первого рендера, иначе «разъезжается» */
        try { if (localStorage.getItem('n2j.sidebar.collapsed') === '1') document.body.classList.add('sb-collapsed'); } catch (e) { }
    </script>
    <?php echo View::factory('partials/sidebar')
            ->set('menu_items', isset($menu_items) ? $menu_items : [])
            ->set('module_nav', isset($module_nav) ? $module_nav : []); ?>
    <div id="page-wrapper" class="flex min-h-screen flex-col">
    <header class="sticky top-0 z-40 w-full bg-white/90 dark:bg-gray-800/90 backdrop-blur-md border-b border-gray-200 dark:border-gray-700">
        <div class="container-fluid mx-auto px-4 h-16 flex items-center justify-between">
            <?php // левая группа тянется (flex-1 + min-w-0): чипы раздела скроллятся внутри себя, а не распирают шапку ?>
            <div class="flex min-w-0 flex-1 items-center">
                <button id="mobile-menu-button" class="md:hidden mr-3 p-2 rounded-md text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <a href="/" class="text-xl font-bold flex items-center text-blue-600 dark:text-blue-400 shrink-0 mr-2 md:hidden">
				
					<?php echo isset($site_logo) ? $site_logo : '<i class="fas fa-brain mr-2"></i>'; ?>
					
                    <?php // имя сайта на телефонах (до 380px) уезжало за край — шапка на этой
                   		// ширине умещает только иконку; «xs:» в конфига Tailwind нет, класс не работал ?>
                    <span class="hidden min-[380px]:inline text-gray-900 dark:text-white"><?php echo HTML::chars(isset($site_name) ? $site_name : ''); ?></span>
                </a>
              
                <!-- Чипы текущего раздела (nj110_catalog); навигация — в левой панели -->
                <?php $nj_header = class_exists('Service_Nj110_Catalog') ? Service_Nj110_Catalog::header_nav() : NULL; ?>
                <?php
                // href'ы чипов раздела: меню модуля их НЕ повторяет. Иначе на /trends
                // рядом стоят две «Тренды GitHub» и две «Управление» (владелец, 26.09:
                // «убери дубликаты страниц»). Навигация на мобильных — в левой панели,
                // поэтому фильтруем только показ чипов в шапке, не сам module_nav.
                $nj_hrefs = [];

                if ($nj_header !== NULL)
                {
                    foreach ($nj_header['items'] as $nj_it)
                    {
                        $nj_hrefs[rtrim((string) $nj_it['href'], '/')] = TRUE;
                    }
                }
                ?>
                <?php if ($nj_header !== NULL): ?>
                    <div class="hidden md:flex items-center gap-1.5 min-w-0 overflow-x-auto">
                        <span class="mr-1 inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                            <i class="<?php echo HTML::chars($nj_header['icon']); ?>"></i><?php echo HTML::chars($nj_header['group']); ?>
                        </span>
                        <?php foreach ($nj_header['items'] as $nj_item): ?>
                            <a href="<?php echo HTML::chars($nj_item['href']); ?>"
                               class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition <?php echo ! empty($nj_item['active'])
                                   ? 'bg-blue-600 text-white shadow-sm'
                                   : 'bg-gray-100 text-gray-600 hover:bg-gray-200 hover:text-gray-900 dark:bg-gray-700/60 dark:text-gray-300 dark:hover:bg-gray-700'; ?>">
                                <i class="<?php echo HTML::chars($nj_item['icon']); ?> mr-1 text-xs"></i><?php echo HTML::chars($nj_item['title']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php // Меню модуля (md+): рядом с чипами раздела; на малых экранах — в левой панели ?>
                <?php
                // меню модуля без повторов чипов раздела (см. $nj_hrefs выше)
                $mn_chips = [];

                foreach ((array) (isset($module_nav) ? $module_nav : []) as $mn_item)
                {
                    if (isset($nj_hrefs[rtrim((string) $mn_item['href'], '/')]))
                    {
                        continue;
                    }

                    $mn_chips[] = $mn_item;
                }
                ?>
                <?php if ( ! empty($mn_chips)): ?>
                    <div class="hidden md:flex items-center gap-1.5 min-w-0 overflow-x-auto <?php echo $nj_header !== NULL ? 'ml-2 pl-2 border-l border-gray-200 dark:border-gray-700' : ''; ?>">
                        <?php foreach ($mn_chips as $mn_item): ?>
                            <a href="<?php echo HTML::chars($mn_item['href']); ?>"
                               class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition <?php echo ! empty($mn_item['active'])
                                   ? 'bg-blue-600 text-white shadow-sm'
                                   : 'bg-gray-100 text-gray-600 hover:bg-gray-200 hover:text-gray-900 dark:bg-gray-700/60 dark:text-gray-300 dark:hover:bg-gray-700'; ?>">
                                <?php if ( ! empty($mn_item['icon'])): ?><i class="<?php echo HTML::chars($mn_item['icon']); ?> mr-1 text-xs"></i><?php endif; ?><?php echo HTML::chars($mn_item['title']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="flex min-w-0 items-center space-x-1.5 sm:space-x-4">

                <?php // Глобальный поиск (nj100): по моим материалам, базе знаний, организациям
                // и вакансиям. На телефоне окно ПРИВЯЗАНО К ШАПКЕ (max-sm:fixed), а не к кнопке:
                // кнопка стоит в середине строки, и 22rem вправо от неё не влезали — панель
                // уезжала за левый край. Шапка — sticky + backdrop-blur, поэтому fixed считается
                // от неё: то же самое и при прокрутке страницы.
                // 16px у поля — чтобы iOS не приближала страницу на фокусе (text-sm = 14px). ?>
                <div class="relative" id="gsearch">
                    <button type="button" id="gsearch-btn" class="p-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors" aria-label="Поиск по содержимому сайта" title="Поиск по содержимому сайта: досье, компании, база знаний, вакансии">
                        <i class="fas fa-magnifying-glass text-xl"></i>
                    </button>
                    <div id="gsearch-drop" class="hidden absolute right-0 z-50 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg max-sm:fixed max-sm:inset-x-3 max-sm:top-[4.25rem] max-sm:mt-0 max-sm:w-auto max-sm:max-w-none dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-gray-100 p-2 dark:border-gray-700/60">
                            <input type="search" id="gsearch-q" aria-label="Поиск по содержимому сайта, минимум два символа" placeholder="Поиск по содержимому… (от 2 символов)" autocomplete="off"
                                   class="min-h-[40px] w-full rounded-lg border border-gray-300 bg-white px-3 text-[16px] text-gray-900 outline-none focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 sm:text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        </div>
                        <div id="gsearch-results" class="max-h-[60vh] overflow-y-auto sm:max-h-96">
                            <p class="px-4 py-6 text-center text-xs text-gray-400">Ищет досье, компании, 3D-модели, базу знаний, организации и вакансии.</p>
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    'use strict';
                    var wrap = document.getElementById('gsearch');
                    if (!wrap) return;
                    var btn = document.getElementById('gsearch-btn'),
                        drop = document.getElementById('gsearch-drop'),
                        input = document.getElementById('gsearch-q'),
                        box = document.getElementById('gsearch-results'),
                        timer = null, lastQ = '', items = [], active = -1;

                    function esc(s) {
                        var d = document.createElement('div');
                        d.textContent = s == null ? '' : String(s);
                        return d.innerHTML;
                    }

                    function close() {
                        drop.classList.add('hidden');
                    }

                    function open() {
                        drop.classList.remove('hidden');
                        input.focus();
                    }

                    function setActive(n) {
                        if (!items.length) return;
                        active = (active + n + items.length) % items.length;
                        items.forEach(function (el, i) {
                            el.classList.toggle('bg-blue-50', i === active);
                            el.classList.toggle('dark:bg-blue-950/40', i === active);
                        });
                        if (items[active]) items[active].scrollIntoView({block: 'nearest'});
                    }

                    function render(data) {
                        if (!data || data.error) {
                            box.innerHTML = '<p class="px-4 py-6 text-center text-xs text-rose-500">' + esc((data && data.error) || 'Поиск не удался') + '</p>';
                            return;
                        }
                        if (!data.results || !data.results.length) {
                            box.innerHTML = '<p class="px-4 py-6 text-center text-xs text-gray-400">Ничего не найдено по «' + esc(lastQ) + '»</p>';
                            return;
                        }
                        var html = '';
                        data.results.forEach(function (r, i) {
                            html += '<a href="' + esc(r.url) + '" data-gsi="' + i + '" class="block px-4 py-2.5 transition hover:bg-gray-50 dark:hover:bg-gray-700/40">'
                                + '<div class="flex items-center gap-2">'
                                + '<span class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-300">' + esc(r.kind) + '</span>'
                                + '<span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white">' + esc(r.title) + '</span>'
                                + '</div>'
                                + (r.sub ? '<p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">' + esc(r.sub) + '</p>' : '')
                                + '</a>';
                        });
                        html += '<p class="border-t border-gray-100 px-4 py-2 text-center text-xs text-gray-400 dark:border-gray-700/60">' + data.total + ' результатов · ' + data.took_ms + ' мс · Enter — перейти</p>';
                        box.innerHTML = html;
                        items = Array.prototype.slice.call(box.querySelectorAll('[data-gsi]'));
                        active = -1;
                    }

                    function run() {
                        var q = input.value.trim();
                        if (q.length < 2) {
                            box.innerHTML = '<p class="px-4 py-6 text-center text-xs text-gray-400">Минимум 2 символа…</p>';
                            items = [];
                            return;
                        }
                        if (q === lastQ) return;
                        lastQ = q;
                        box.innerHTML = '<p class="px-4 py-6 text-center text-xs text-gray-400"><i class="fas fa-circle-notch fa-spin mr-1"></i>Ищу…</p>';
                        fetch('/search/api?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
                            .then(function (r) { return r.json(); })
                            .then(function (data) { if (input.value.trim() === lastQ) render(data); })
                            .catch(function () {
                                box.innerHTML = '<p class="px-4 py-6 text-center text-xs text-rose-500">Сеть недоступна — попробуйте ещё раз</p>';
                            });
                    }

                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        if (drop.classList.contains('hidden')) { open(); } else { close(); }
                    });

                    input.addEventListener('input', function () {
                        if (timer) clearTimeout(timer);
                        timer = setTimeout(run, 300);
                    });

                    input.addEventListener('keydown', function (e) {
                        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(1); }
                        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(-1); }
                        else if (e.key === 'Enter' && items[active]) { e.preventDefault(); location.href = items[active].getAttribute('href'); }
                        else if (e.key === 'Escape') { close(); }
                    });

                    wrap.addEventListener('click', function (e) { e.stopPropagation(); });
                    document.addEventListener('click', function () { close(); });
                    // Ctrl+K здесь больше НЕ перехватываем: он принадлежит поиску
                    // по сервисам в левой панели (там же поле и подсказка). Этот
                    // блок — поиск по содержимому (досье, компании, база знаний),
                    // открывается кнопкой-лупой.
                })();
                </script>

				<?php 
					// Проверяем, включен ли модуль
					if (array_key_exists('erp118_links', Kohana::modules())) {
						// Выполняем внутренний под-запрос к контроллеру виджета
						echo Request::factory('erp118_widget/bookmark_button')->execute();
					}
				?>

                <?php if (Auth::instance()->logged_in()): ?>
                <div class="relative" id="nj597-wrap">
                    <button type="button" id="nj597-bell" class="relative p-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors" aria-label="Уведомления">
                        <i class="fas fa-bell text-xl"></i>
                        <span id="nj597-badge" class="absolute top-0.5 right-0.5 inline-flex min-w-[1.1rem] items-center justify-center px-1 py-0.5 text-xs font-bold leading-none text-white bg-rose-600 rounded-full" style="display: none;">0</span>
                    </button>
                    <div id="nj597-drop" class="hidden absolute right-0 z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg max-sm:fixed max-sm:inset-x-3 max-sm:top-[4.25rem] max-sm:mt-0 max-sm:w-auto max-sm:max-w-none dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700/60">
                            <span class="text-sm font-bold text-gray-900 dark:text-white">Уведомления</span>
                            <button type="button" id="nj597-readall" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Прочитано всё</button>
                        </div>
                        <div id="nj597-list" class="max-h-80 overflow-y-auto">
                            <p class="px-4 py-6 text-center text-xs text-gray-400">Загружаю…</p>
                        </div>
                        <a href="/nj597" class="block border-t border-gray-100 px-4 py-2.5 text-center text-xs font-semibold text-blue-600 transition hover:bg-gray-50 dark:border-gray-700/60 dark:text-blue-400 dark:hover:bg-gray-700/40">Все уведомления и настройки</a>
                    </div>
                </div>
                <script>
                (function () {
                    'use strict';
                    var CSRF = '<?php echo Security::token(); ?>';
                    var wrap = document.getElementById('nj597-wrap');
                    if (!wrap) return;
                    var bell = document.getElementById('nj597-bell'),
                        drop = document.getElementById('nj597-drop'),
                        list = document.getElementById('nj597-list'),
                        badge = document.getElementById('nj597-badge');
                    var esc = function (s) {
                        var d = document.createElement('div');
                        d.textContent = String(s == null ? '' : s);
                        return d.innerHTML;
                    };
                    function setBadge(n) {
                        if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.style.display = ''; }
                        else { badge.style.display = 'none'; }
                    }
                    function icon(kind) {
                        if (kind === 'job_done') return ['fa-circle-check', 'text-emerald-500'];
                        if (kind === 'job_failed') return ['fa-triangle-exclamation', 'text-rose-500'];
                        return ['fa-circle-info', 'text-blue-500'];
                    }
                    function render(items) {
                        if (!items.length) {
                            list.innerHTML = '<p class="px-4 py-6 text-center text-xs text-gray-400">Пока пусто — здесь появятся готовые задачи и ошибки.</p>';
                            return;
                        }
                        var html = '';
                        items.forEach(function (n) {
                            var ic = icon(n.kind);
                            html += '<a href="' + esc(n.href || '/nj597') + '" data-id="' + n.id + '"'
                                + ' class="nj597-item block border-b border-gray-50 px-4 py-2.5 transition-colors hover:bg-gray-50 dark:border-gray-700/40 dark:hover:bg-gray-700/40'
                                + (n.unread ? ' bg-blue-50/50 dark:bg-blue-950/20' : '') + '">'
                                + '<p class="flex items-start gap-2 text-sm font-semibold text-gray-900 dark:text-white">'
                                + '<i class="fas ' + ic[0] + ' ' + ic[1] + ' mt-0.5 w-4 shrink-0 text-center text-xs"></i>'
                                + '<span class="min-w-0 flex-1">' + esc(n.title)
                                + (n.unread ? ' <span class="ml-1 inline-block h-2 w-2 rounded-full bg-blue-600 align-middle"></span>' : '')
                                + '</span><span class="shrink-0 font-normal text-xs text-gray-400">' + esc(n.ago) + '</span></p>'
                                + (n.body ? '<p class="mt-0.5 pl-6 truncate text-xs text-gray-500 dark:text-gray-400">' + esc(n.body) + '</p>' : '')
                                + '</a>';
                        });
                        list.innerHTML = html;
                    }
                    function refresh() {
                        // visible=1 — «человек смотрит на вкладку»: сервер пишет
                        // присутствие (nj_597_presence). Свёрнутая вкладка молчит,
                        // иначе уведомление о своём же событии пропадало бы зря.
                        var vis = (document.visibilityState === 'visible') ? '1' : '0';
                        fetch('/nj597/api/unread?visible=' + vis + '&bust=' + Date.now(), {headers: {'Accept': 'application/json'}})
                            .then(function (r) {
                                if (r.status === 401) { location.href = '/auth/logout'; return null; }
                                return r.ok ? r.json() : null;
                            })
                            .then(function (j) {
                                if (!j) return;
                                setBadge(j.unread);
                                render(j.items || []);
                            })
                            .catch(function () {});
                    }
                    function postRead(payload, btn) {
                        var body = new URLSearchParams();
                        body.set('csrf', CSRF);
                        Object.keys(payload).forEach(function (k) { body.set(k, payload[k]); });
                        if (btn) { btn.disabled = true; btn.classList.add('opacity-60'); }
                        fetch('/nj597/api/read', {method: 'POST', body: body})
                            .then(function (r) { return r.ok ? r.json() : null; })
                            .then(function (j) { if (j) setBadge(j.unread); })
                            .catch(function () {})
                            .then(function () { if (btn) { btn.disabled = false; btn.classList.remove('opacity-60'); } });
                    }
                    bell.addEventListener('click', function () {
                        drop.classList.toggle('hidden');
                        if (!drop.classList.contains('hidden')) refresh();
                    });
                    document.addEventListener('click', function (e) {
                        if (!wrap.contains(e.target)) drop.classList.add('hidden');
                    });
                    list.addEventListener('click', function (e) {
                        var a = e.target.closest('a.nj597-item');
                        if (!a) return;
                        e.preventDefault();
                        var href = a.getAttribute('href');
                        postRead({id: JSON.stringify([a.getAttribute('data-id')])}, null);
                        if (href && href !== '#') location.href = href;
                    });
                    document.getElementById('nj597-readall').addEventListener('click', function () {
                        postRead({all: 1}, this);
                        list.querySelectorAll('.bg-blue-50\\/50, .bg-blue-950\\/20').forEach(function (el) {
                            el.classList.remove('bg-blue-50/50', 'bg-blue-950/20');
                        });
                        badge.style.display = 'none';
                    });
                    refresh();
                    setInterval(refresh, 45000);
                    // Вернулся на вкладку — сразу и отметился, и посмотрел ленту.
                    document.addEventListener('visibilitychange', function () {
                        if (document.visibilityState === 'visible') refresh();
                    });
                })();
                </script>
                <?php endif; ?>
				
                
                <div class="shrink-0">
                    <label class="theme-toggle">
                        <input type="checkbox" id="theme-toggle">
                        <span class="slider">
                            <i class="fas fa-sun sun-icon"></i>
                            <i class="fas fa-moon moon-icon"></i>
                        </span>
                    </label>
                </div>
                
                <?php if (isset($current_user_roles) && in_array('login', $current_user_roles)): ?>
                    <div class="relative">
                        <button type="button" id="userDropdownButton" aria-label="Меню аккаунта" aria-controls="userDropdownMenu" aria-expanded="false" class="flex items-center space-x-2 sm:space-x-3 focus:outline-none group p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                            <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-blue-600 flex items-center justify-center text-white shadow-sm text-sm font-bold">
                                <?php echo HTML::chars(strtoupper(substr($current_user->username, 0, 1))); ?>
                            </div>
                            <div class="hidden lg:block text-left">
                                <p class="text-sm font-semibold leading-none"><?php echo HTML::chars($current_user->username); ?></p>
                            </div>
                            <i class="fas fa-chevron-down text-xs text-gray-400 group-hover:text-gray-600 transition-transform" id="dropdownArrow"></i>
                        </button>
                        
                        <div id="userDropdownMenu" class="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden opacity-0 transition-all duration-200 transform scale-95 origin-top-right z-50">
                            <div class="p-3 border-b border-gray-100 dark:border-gray-700 lg:hidden">
                                <p class="text-sm font-bold text-gray-900 dark:text-white truncate"><?php echo HTML::chars($current_user->username); ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?php echo HTML::chars($current_user->email); ?></p>
                            </div>
                            <div class="p-2 space-y-1">
                                <div class="sm:hidden flex items-center justify-between px-3 py-2 text-sm text-gray-600 dark:text-gray-300">
                                    <span>Тема</span>
                                    <label class="theme-toggle scale-75">
                                        <input type="checkbox" id="theme-toggle-mobile">
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <a href="/auth/profile" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                    <i class="fas fa-user-circle mr-3 text-gray-400"></i> Профиль
                                </a>
                                <a href="/dashboard" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                    <i class="fas fa-gauge-high mr-3 text-gray-400"></i> Рабочий стол
                                </a>
                                <?php if (in_array('admin', $current_user_roles)): ?>
                                    <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
                                    <a href="/admin" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 text-amber-600 dark:text-amber-400 transition-colors">
                                        <i class="fas fa-screwdriver-wrench mr-3"></i> Панель управления
                                    </a>
                                    <a href="/nj595/admin" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 text-amber-600 dark:text-amber-400 transition-colors">
                                        <i class="fas fa-users-gear mr-3"></i> Пользователи и роли
                                    </a>
                                    <a href="/nj595/admin/activity" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 text-amber-600 dark:text-amber-400 transition-colors">
                                        <i class="fas fa-chart-line mr-3"></i> Активность системы
                                    </a>
                                    <a href="/nj120/monitor" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 text-amber-600 dark:text-amber-400 transition-colors">
                                        <i class="fas fa-heart-pulse mr-3"></i> Воркеры · монитор
                                    </a>
                                	<a href="/migrate_pg" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 text-amber-600 dark:text-amber-400 transition-colors">
                                        <i class="fas fa-database mr-3"></i> Миграции PG
                                    </a>
                                <?php endif; ?>
                                <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
                                <a href="/auth/logout" class="flex items-center px-3 py-2.5 text-sm rounded-xl text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors">
                                    <i class="fas fa-sign-out-alt mr-3"></i> Выйти
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="/auth/login" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">Вход</a>
                <?php endif; ?>
            </div>
        </div>

        </header>
    
    <?php  // echo View::factory('profiler/stats') ?>
	
    <div class="flex-grow">
        <?php /* запасной путь для контроллеров: $breadcrumbs/$h1 у шаблона; модули
                 используют partials/page_header — те же классы v6 */ ?>
        <?php $tpl_crumbs = (isset($breadcrumbs) && ! empty($breadcrumbs)) ? $breadcrumbs : []; ?>
        <?php if ($tpl_crumbs || ( ! empty($h1))): ?>
        <div class="n2j-ds w-full px-3.5 pt-4 sm:px-6 lg:px-8">
            <?php if ($tpl_crumbs): ?>
            <nav class="n2j-crumbs overflow-x-auto whitespace-nowrap" aria-label="Хлебные крошки">
                <?php foreach (array_values($tpl_crumbs) as $key => $crumb): ?>
                    <?php if ($key > 0): ?><span class="n2j-crumbs-sep">/</span><?php endif; ?>
                    <?php if ( ! empty($crumb['active'])): ?>
                        <span class="n2j-crumbs-current" aria-current="page"><?php echo HTML::chars($crumb['text']); ?></span>
                    <?php else: ?>
                        <a href="<?php echo HTML::chars($crumb['href']); ?>"><?php echo HTML::chars($crumb['text']); ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <?php if ( ! empty($h1)): ?>
            <h1 class="n2j-h1<?php echo $tpl_crumbs ? ' mt-2.5' : ''; ?>"><?php echo HTML::chars($h1); ?></h1>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <main class="">
            <?php echo $content; ?>
        </main>
		
		<?php 
			// Проверяем, включен ли модуль
			if (array_key_exists('cookie_consent', Kohana::modules())) {
				echo Controller_CookieConsent::get_popup_html();
			}
		?>
		
    </div>

    </div><!-- /#page-wrapper -->


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('theme-toggle');
            const themeToggleMobile = document.getElementById('theme-toggle-mobile');
            const html = document.documentElement;
            
            function updateTheme(isDark) {
                if (isDark) {
                    html.classList.add('dark');
                    localStorage.setItem('theme', 'dark');
                } else {
                    html.classList.remove('dark');
                    localStorage.setItem('theme', 'light');
                }
                if(themeToggle) themeToggle.checked = isDark;
                if(themeToggleMobile) themeToggleMobile.checked = isDark;
            }

            if (html.classList.contains('dark')) {
                if(themeToggle) themeToggle.checked = true;
                if(themeToggleMobile) themeToggleMobile.checked = true;
            }

            themeToggle?.addEventListener('change', (e) => updateTheme(e.target.checked));
            themeToggleMobile?.addEventListener('change', (e) => updateTheme(e.target.checked));

            // Мобильное меню заменено левой панелью (см. скрипт сайдбара ниже)

            const btn = document.getElementById('userDropdownButton');
            const menu = document.getElementById('userDropdownMenu');
            const arrow = document.getElementById('dropdownArrow');

            if (btn && menu) {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isHidden = menu.classList.contains('hidden');
                    if (isHidden) {
                        btn.setAttribute('aria-expanded', 'true');
                        menu.classList.remove('hidden');
                        setTimeout(() => {
                            menu.classList.remove('opacity-0', 'scale-95');
                            arrow?.classList.add('rotate-180');
                        }, 10);
                    } else {
                        closeDropdown();
                    }
                });

                document.addEventListener('click', closeDropdown);
            }

            function closeDropdown() {
                if (!menu || menu.classList.contains('hidden')) return;
                btn?.setAttribute('aria-expanded', 'false');
                menu.classList.add('opacity-0', 'scale-95');
                arrow?.classList.remove('rotate-180');
                setTimeout(() => menu.classList.add('hidden'), 200);
            }

            <?php /* nj597: колокольчик обновляется собственным скриптом (см. шапку) — legacy-бейдж erp217 удалён */ ?>
        });
    </script>
    <script>
        // Левая панель: сворачивание (localStorage), мобильный drawer, активный пункт
        (function () {
            var sidebar = document.getElementById('sidebar');
            if (!sidebar) return;

            var body = document.body;
            var overlay = document.getElementById('sidebar-overlay');
            var collapseBtn = document.getElementById('sidebar-collapse');
            var burger = document.getElementById('mobile-menu-button');
            // класс sb-collapsed ставится ранним boot-скриптом сразу после <body>

            collapseBtn && collapseBtn.addEventListener('click', function () {
                var collapsed = body.classList.toggle('sb-collapsed');
                localStorage.setItem('n2j.sidebar.collapsed', collapsed ? '1' : '0');
            });

            function drawer(open) {
                sidebar.classList.toggle('open', open);
                overlay && overlay.classList.toggle('hidden', !open);
                // открытая шторка держит страницу: фон под ней не прокручивается
                body.classList.toggle('sb-drawer-open', open);
                var icon = burger && burger.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-bars', !open);
                    icon.classList.toggle('fa-times', open);
                }
            }

            // Планшет повернули или окно развернули до md — шторка уступает
            // место постоянной колонке, иначе оверлей остался бы поверх страницы.
            window.addEventListener('resize', function () {
                if (window.innerWidth >= 768 && sidebar.classList.contains('open')) drawer(false);
            });

            burger && burger.addEventListener('click', function (e) {
                e.stopPropagation();
                drawer(!sidebar.classList.contains('open'));
            });
            overlay && overlay.addEventListener('click', function () { drawer(false); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') drawer(false);
            });
            var nav = document.getElementById('sidebar-nav');
            var sidebarScrollKey = 'n2j.sidebar.scrollTop';

            sidebar.addEventListener('click', function (e) {
                var link = e.target.closest('a');

                // Позиция меню живёт между переходами в этой вкладке: нижний
                // пункт не заставляет снова прокручивать длинный список сверху.
                if (link && nav && link.closest('#sidebar-nav')) {
                    try { sessionStorage.setItem(sidebarScrollKey, String(nav.scrollTop)); } catch (ignore) { }
                }

                if (link && window.innerWidth < 768) drawer(false);
            });

            var path = location.pathname.replace(/\/+$/, '') || '/';
            var best = null, bestLen = -1;
            if (nav) {
                nav.querySelectorAll('a.sidebar-item').forEach(function (a) {
                    // Сервер знает об алиасах страниц объектов; при прямом
                    // совпадении с href оставляем fallback для старых пунктов.
                    if (a.getAttribute('data-active') === '1') {
                        best = a;
                        bestLen = Number.MAX_SAFE_INTEGER;
                        return;
                    }

                    var href = a.getAttribute('href') || '';
                    if (!href || href === '#') return;
                    var h = href.replace(/\/+$/, '') || '/';
                    if (h === path || (h !== '/' && path.indexOf(h + '/') === 0)) {
                        if (h.length > bestLen) { bestLen = h.length; best = a; }
                    }
                });
            }
            if (best) {
                best.classList.add('bg-blue-50', 'text-blue-700', 'dark:bg-blue-900/40', 'dark:text-blue-300');
                best.classList.remove('text-gray-600', 'dark:text-gray-400');
            }

            if (nav) {
                var savedScroll = null;
                try {
                    savedScroll = sessionStorage.getItem(sidebarScrollKey);
                    if (savedScroll !== null) sessionStorage.removeItem(sidebarScrollKey);
                } catch (ignore) { }

                window.requestAnimationFrame(function () {
                    if (savedScroll !== null && isFinite(Number(savedScroll))) {
                        nav.scrollTop = Math.max(0, Number(savedScroll));
                    } else if (best) {
                        var navRect = nav.getBoundingClientRect();
                        var itemRect = best.getBoundingClientRect();
                        if (itemRect.top < navRect.top || itemRect.bottom > navRect.bottom) {
                            best.scrollIntoView({ block: 'nearest' });
                        }
                    }
                });
            }

            // Поиск по сервисам в левой панели (Ctrl+K / Cmd+K / «/»): поле
            // фильтрует ТЕКУЩЕЕ меню по data-search, как было изначально.
            // Палитра всех сервисов открывается кнопкой рядом с полем —
            // см. navpalette.js (там же персонализация меню).
            // Подсказка сочетания клавиш соответствует платформе пользователя.
            var platformName = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
            var modifierShortcut = /mac|iphone|ipad|ipod/i.test(platformName) ? '⌘K' : 'Ctrl+K';
            document.querySelectorAll('[data-shortcut="mod-k"]').forEach(function (hint) {
                hint.textContent = modifierShortcut;
            });

            var search = document.getElementById('nav-search');
            var empty = document.getElementById('nav-search-empty');

            if (search) {
                var applyFilter = function () {
                    var q = search.value.trim().toLowerCase();
                    var anyVisible = false;

                    sidebar.querySelectorAll('.sidebar-group').forEach(function (groupEl) {
                        var groupVisible = false;

                        groupEl.querySelectorAll('a.sidebar-item').forEach(function (a) {
                            var hay = a.getAttribute('data-search') || '';
                            var show = !q || hay.indexOf(q) !== -1;
                            a.parentElement.classList.toggle('hidden', !show);
                            if (show) groupVisible = true;
                        });

                        groupEl.classList.toggle('hidden', !groupVisible);
                        if (groupVisible) anyVisible = true;
                    });

                    if (empty) empty.classList.toggle('hidden', anyVisible || !q);
                };

                search.addEventListener('input', applyFilter);
                search.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        search.value = '';
                        applyFilter();
                        search.blur();
                    }
                });

                document.addEventListener('keydown', function (e) {
                    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement && document.activeElement.tagName || '');

                    if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) {
                        e.preventDefault();

                        // Поиск остаётся доступен, даже если панель свернута
                        // или на телефоне закрыта за кнопкой-гамбургером.
                        // Свёрнутость снимаем только на ПК: в шторке (<md) она
                        // и так не действует, а выбор пользователя должен
                        // дождаться его возвращения к широкому окну.
                        if (window.innerWidth < 768) {
                            drawer(true);
                        } else if (body.classList.contains('sb-collapsed')) {
                            body.classList.remove('sb-collapsed');
                            try { localStorage.setItem('n2j.sidebar.collapsed', '0'); } catch (ignore) { }
                        }

                        search.focus();
                        search.select();
                    } else if (e.key === '/' && !typing) {
                        e.preventDefault();
                        search.focus();
                    }
                });
            }
        })();
    </script>

    <?php // Память выбора пользователя в элементах управления (localStorage):
          // select, дата, число, переключатели. Владелец: «запоминай в браузере». ?>
    <?php echo View::factory('partials/prefs_js'); ?>
</body>
</html>