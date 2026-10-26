<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj601: финальная страница возврата плательщика (success/fail).
 * Автономный HTML (вне основного шаблона — Robokassa возвращает сюда напрямую).
 */
// полный класс, а не склейка «text-{$accent}-500»: Tailwind видит только целые имена
$accent_class = $color === 'teal' ? 'text-emerald-500' : 'text-gray-500';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo HTML::chars($title); ?> — N2J.ru</title>
<link rel="stylesheet" href="/assets/css/app.css" />
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900">
<div class="mx-auto max-w-lg px-4 py-16">
    <div class="n2j-card p-8 text-center">
        <i class="fas <?php echo $icon; ?> text-5xl <?php echo $accent_class; ?>"></i>
        <h1 class="n2j-h1 mt-4"><?php echo HTML::chars($title); ?></h1>
        <?php if ( ! empty($inv)): ?>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Счёт №<?php echo (int) $inv['id']; ?> · <?php echo HTML::chars($inv['description']); ?> · <?php echo HTML::chars($inv['out_sum']); ?> ₽ · статус: <?php echo HTML::chars($inv['status']); ?></p>
        <?php endif; ?>
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300"><?php echo HTML::chars($text); ?></p>
        <a href="/" class="n2j-btn-cta mt-6">На главную</a>
    </div>
    <p class="n2j-hint mt-4 text-center">N2J.ru · платежи через Robokassa<?php echo ! empty($is_test) ? ' (ТЕСТ)' : ''; ?></p>
</div>
</body>
</html>
