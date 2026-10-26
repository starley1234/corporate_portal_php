<?php // design-audit: allow-file style,h1 — изолированная публичная 404-страница витрины со встроенным CSS галереи. ?><!doctype html>
<html lang="ru" data-theme="<?= HTML::chars((string) ($theme ?? '')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="design-lab-revision" content="<?= HTML::chars((string) ($revision ?? '')) ?>">
<title>Вариант не найден — витрина дизайна PRL</title>
<script>
(function () {
  try {
    var queryTheme = new URLSearchParams(window.location.search).get('theme');
    var savedTheme = window.localStorage.getItem('prl-design-theme');
    var theme = (queryTheme === 'dark' || queryTheme === 'light') ? queryTheme :
      ((savedTheme === 'dark' || savedTheme === 'light') ? savedTheme :
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;
  } catch (error) {
    document.documentElement.dataset.theme = document.documentElement.dataset.theme || 'light';
  }
})();
</script>
<style data-design-lab-css="landing">
<?= (string) ($css ?? '') ?>
</style>
</head>
<body>
<main class="dl-empty">
  <p class="dl-eyebrow">Ошибка 404</p>
  <h1>Такого варианта дизайна нет</h1>
  <p class="dl-lead">Проверьте адрес или выберите один из семи подготовленных вариантов корпоративного портала.</p>
  <nav class="dl-empty-links" aria-label="Доступные варианты дизайна">
    <a class="dl-card-link" href="<?= HTML::chars((string) ($home_url ?? '/design-lab')) ?>">Все варианты</a>
    <?php foreach (($variants ?? []) as $variant): ?>
      <a href="<?= HTML::chars((string) ($variant['href'] ?? '#')) ?>"><?= HTML::chars(str_pad((string) ($variant['number'] ?? ''), 2, '0', STR_PAD_LEFT)) ?> · <?= HTML::chars((string) ($variant['title'] ?? '')) ?></a>
    <?php endforeach; ?>
  </nav>
</main>
</body>
</html>
