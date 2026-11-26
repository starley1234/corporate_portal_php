<?php
    defined('SYSPATH') or die('No direct script access.');
    // Значение из конфига — только известный бренд-акцент, не произвольный CSS.
    $company_accent = strtolower(trim((string) Kohana::$config->load('site_config')->get('company_accent')));
    if ( ! in_array($company_accent, ['orange', 'blue', 'yellow'], TRUE))
    {
        $company_accent = 'orange';
    }
?>
<!DOCTYPE html>
<html lang="ru" class="scroll-smooth" data-company-accent="<?php echo HTML::chars($company_accent); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php /* CSRF-токен для AJAX: app.js добавляет заголовок X-CSRF-Token ко всем
   небезопасным запросам (серверная проверка — Prx_PostGuard). Только для
   вошедших: у гостя нет сессии, а POST гостя (вход/регистрация) несёт csrf в форме. */ ?>
<?php if (isset($current_user) AND $current_user): ?>
    <meta name="csrf-token" content="<?php echo HTML::chars(Security::token()); ?>">
<?php endif; ?>
<?php
    // SEO-шапка (21.09): канонический адрес, OG-теги, robots для служебных путей
    $__req = Request::current();
    $__path = ($__req !== NULL) ? '/'.ltrim($__req->uri(), '/') : '/';
    $__canonical = URL::base(true).ltrim($__path, '/');
    // Скобки обязательны: OR слабее присваивания — без них признак служебной
    // страницы зависел только от первого условия, и «/admin…» уезжал в индекс.
    $__is_service = ((bool) preg_match('~^/(prx[0-9]+|dashboard|auth|tasks|migrate_pg|prefs|update|prx[0-9a-z_]*|cron)(/|$)~i', $__path)
        OR stripos($__path, '/admin') !== FALSE);
?>
    <title><?php echo HTML::chars(isset($title) && $title ? $title : 'PRX — платформа ИИ-сервисов'); ?></title>
    <meta name="description" content="<?php echo HTML::chars(isset($meta['description']) && $meta['description'] ? $meta['description'] : 'Единое рабочее пространство сотрудников холдинга.'); ?>">
    <meta name="keywords" content="<?php echo HTML::chars(isset($meta['keywords']) && $meta['keywords'] ? $meta['keywords'] : 'ИИ-сервисы, PRX'); ?>">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="<?php echo HTML::chars($__canonical); ?>">

    <meta property="og:title" content="<?php echo HTML::chars(isset($title) && $title ? $title : 'PRX — платформа ИИ-сервисов'); ?>">
    <meta property="og:description" content="<?php echo HTML::chars(isset($meta['description']) && $meta['description'] ? $meta['description'] : 'Сервисы сотрудников, знания и коммуникации.'); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo HTML::chars($__canonical); ?>">
    <meta property="og:site_name" content="PRX.ru">
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
    <link rel="stylesheet" href="/assets/css/app.css?v=document-upload" />
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

	<?php // Версия в URL — единственный способ сбросить кэш браузера: правили файл — поднимай число. ?>
	<script src="/assets/js/template.js?v=1"></script>
	<script src="/assets/js/app.js?v=2"></script>
	<script src="/assets/js/search-layout.js?v=1"></script>
	<script src="/assets/js/navpalette.js?v=2"></script>

    <?php if (isset($styles) && is_array($styles)): ?>
        <?php foreach ($styles as $style): ?>
            <?php if (isset($style['file'])): ?>
                <link rel="stylesheet" href="<?php echo HTML::chars($style['file']); ?>" <?php echo (isset($style['media']) ? 'media="'.HTML::chars($style['media']).'"' : ''); ?>>
            <?php elseif (isset($style['code'])): ?>
                <?php /* design-audit: allow style — API add_inline_style() у Controller_Prx102_Base (сейчас никем не вызывается) */ ?>
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
