<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!doctype html>
<html lang="ru" class="nj655-html">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo HTML::chars(! empty($meta['title']) ? $meta['title'] : '3D-карта сервисов N2J'); ?></title>
    <meta name="description" content="<?php echo HTML::chars(! empty($meta['description']) ? $meta['description'] : 'Интерактивная 3D-карта сервисов N2J.'); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo HTML::chars(URL::base(TRUE).'orbit'); ?>">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
    <?php foreach ((array) $styles as $style): ?>
        <?php if (isset($style['file'])): ?>
            <link rel="stylesheet" href="<?php echo HTML::chars('/'.ltrim((string) $style['file'], '/')); ?>" media="<?php echo HTML::chars(isset($style['media']) ? $style['media'] : 'screen'); ?>">
        <?php elseif (isset($style['code'])): ?>
            <?php /* design-audit: allow style — свой макет страницы orbit: тот же цикл $styles, что у общего макета */ ?>
            <style><?php echo $style['code']; ?></style>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php foreach ((array) $scripts as $script): ?>
        <?php if (isset($script['file'])): ?>
            <script src="<?php echo HTML::chars('/'.ltrim((string) $script['file'], '/')); ?>"<?php echo ! empty($script['defer']) ? ' defer' : ''; ?>></script>
        <?php elseif (isset($script['code'])): ?>
            <script><?php echo $script['code']; ?></script>
        <?php endif; ?>
    <?php endforeach; ?>
</head>
<body class="nj655-body"><?php echo $content; ?></body>
</html>
