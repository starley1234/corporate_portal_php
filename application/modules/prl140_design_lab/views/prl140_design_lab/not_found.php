<?php // design-audit: allow-file style,h1 — изолированная публичная 404-страница витрины со встроенным CSS галереи. ?><!doctype html>
<html lang="ru" data-theme="<?= HTML::chars((string) ($theme ?? '')) ?>" data-company-accent="<?= HTML::chars((string) ($company_accent ?? '')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="design-lab-revision" content="<?= HTML::chars((string) ($revision ?? '')) ?>">
<title>Вариант не найден — витрина дизайна PRL</title>
<script>
(function () {
  try {
    var params = new URLSearchParams(window.location.search);
    var queryTheme = params.get('theme');
    var savedTheme = window.localStorage.getItem('prl-design-theme');
    var theme = (queryTheme === 'dark' || queryTheme === 'light') ? queryTheme :
      ((savedTheme === 'dark' || savedTheme === 'light') ? savedTheme :
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;

    var companies = ['orange', 'blue', 'yellow'];
    var queryCompany = params.get('company');
    var savedCompany = window.localStorage.getItem('prl-design-company');
    var company = companies.indexOf(queryCompany) >= 0 ? queryCompany :
      (companies.indexOf(savedCompany) >= 0 ? savedCompany : 'blue');
    document.documentElement.dataset.companyAccent = company;
  } catch (error) {
    document.documentElement.dataset.theme = document.documentElement.dataset.theme || 'light';
    document.documentElement.dataset.companyAccent = document.documentElement.dataset.companyAccent || 'blue';
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
  <p class="dl-lead">Проверьте адрес или выберите один из <?= (int) ($variant_count ?? 0) ?> подготовленных вариантов корпоративного портала.</p>
  <nav class="dl-empty-links" aria-label="Доступные варианты дизайна">
    <a class="dl-card-link" href="<?= HTML::chars((string) ($home_url ?? '/design-lab')) ?>">Все варианты</a>
    <?php foreach (($variants ?? []) as $variant): ?>
      <a href="<?= HTML::chars((string) ($variant['href'] ?? '#')) ?>"><?= HTML::chars(str_pad((string) ($variant['number'] ?? ''), 2, '0', STR_PAD_LEFT)) ?> · <?= HTML::chars((string) ($variant['title'] ?? '')) ?></a>
    <?php endforeach; ?>
  </nav>
</main>
</body>
</html>
