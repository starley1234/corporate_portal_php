<?php // design-audit: allow-file style,h1 — изолированная публичная галерея: CSS встраивается в ответ, а единственный H1 является заголовком витрины. ?><!doctype html>
<html lang="ru" data-theme="<?= HTML::chars((string) ($theme ?? '')) ?>" data-company-accent="<?= HTML::chars((string) ($company_accent ?? '')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="design-lab-revision" content="<?= HTML::chars((string) ($revision ?? '')) ?>">
<title>Витрина дизайна PRX — <?= (int) ($variant_count ?? 0) ?> вариантов корпоративного портала</title>
<script>
(function () {
  try {
    var params = new URLSearchParams(window.location.search);
    var queryTheme = params.get('theme');
    var savedTheme = window.localStorage.getItem('prx-design-theme');
    var theme = (queryTheme === 'dark' || queryTheme === 'light') ? queryTheme :
      ((savedTheme === 'dark' || savedTheme === 'light') ? savedTheme :
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;

    var companies = ['orange', 'blue', 'yellow'];
    var queryCompany = params.get('company');
    var savedCompany = window.localStorage.getItem('prx-design-company');
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
<a class="dl-skip-link" href="#variants">Перейти к вариантам</a>

<header class="dl-header">
  <a class="dl-brand" href="#top" aria-label="PRX — витрина дизайна">
    <span class="dl-brand-mark">PRX</span>
    <span>
      <strong>Витрина дизайна</strong>
      <small>Корпоративный портал</small>
    </span>
  </a>
  <div class="dl-header-actions">
    <span class="dl-revision">Редакция <?= HTML::chars((string) ($revision ?? '')) ?></span>
    <div class="dl-company-switch" role="group" aria-label="Акцент компании холдинга">
      <span class="dl-company-label">Компания</span>
      <?php foreach (($company_options ?? []) as $company_option): ?>
        <a class="dl-company-option<?= !empty($company_option['active']) ? ' is-active' : '' ?>" href="<?= HTML::chars((string) ($company_option['href'] ?? '#')) ?>" data-company-choice="<?= HTML::chars((string) ($company_option['slug'] ?? '')) ?>" aria-pressed="<?= !empty($company_option['active']) ? 'true' : 'false' ?>"><span class="dl-company-dot" aria-hidden="true"></span><?= HTML::chars((string) ($company_option['title'] ?? '')) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="dl-theme-switch" role="group" aria-label="Тема оформления">
      <a class="dl-theme-option<?= (($theme ?? '') === 'light') ? ' is-active' : '' ?>" href="<?= HTML::chars((string) ($theme_urls['light'] ?? '#')) ?>" data-theme-choice="light" aria-pressed="<?= (($theme ?? '') === 'light') ? 'true' : 'false' ?>">День</a>
      <a class="dl-theme-option<?= (($theme ?? '') === 'dark') ? ' is-active' : '' ?>" href="<?= HTML::chars((string) ($theme_urls['dark'] ?? '#')) ?>" data-theme-choice="dark" aria-pressed="<?= (($theme ?? '') === 'dark') ? 'true' : 'false' ?>">Ночь</a>
    </div>
  </div>
</header>

<main id="top">
  <section class="dl-hero" aria-labelledby="landing-title">
    <div>
      <p class="dl-eyebrow"><?= (int) ($variant_count ?? 0) ?> направлений · одинаковое содержание · 3 акцента компаний</p>
      <h1 id="landing-title">Выберите характер корпоративного портала PRX</h1>
      <p class="dl-lead">Каждый вариант показывает портал целиком: навигацию, поиск, сервисы, задачи, сотрудников, новости, знания, аналитику и поддержку. Тексты и структура совпадают — сравнивается только дизайн.</p>
    </div>
    <aside class="dl-method" aria-label="Как сравнивать варианты">
      <h2>Как смотреть</h2>
      <ol>
        <li><strong>Откройте вариант</strong><span>и пролистайте страницу целиком.</span></li>
        <li><strong>Переключите день и ночь</strong><span>кнопками в верхней панели.</span></li>
        <li><strong>Выберите акцент компании</strong><span>оранжевый, синий или жёлтый; он меняет бренд-детали, а не семантику кнопок.</span></li>
        <li><strong>Проверьте мобильный вид</strong><span>на телефоне или уменьшив окно браузера.</span></li>
        <li><strong>Запомните номер</strong><span>чтобы назвать понравившееся направление.</span></li>
      </ol>
    </aside>
  </section>

  <section class="dl-facts" aria-label="Условия сравнения">
    <article><strong><?= (int) ($variant_count ?? 0) ?></strong><span>полноценных вариантов портала</span></article>
    <article><strong>1</strong><span>общий набор текстов и данных</span></article>
    <article><strong>2</strong><span>темы в каждом варианте: день и ночь</span></article>
    <article><strong>3</strong><span>акцента компаний холдинга</span></article>
  </section>

  <section class="dl-variants" id="variants" aria-labelledby="variants-title">
    <div class="dl-section-head">
      <div>
        <p class="dl-eyebrow">Галерея</p>
        <h2 id="variants-title">Варианты оформления</h2>
      </div>
      <p>Миниатюры показывают палитру и характер сетки. Полное впечатление даёт только открытая страница варианта.</p>
    </div>

    <div class="dl-card-grid">
      <?php foreach (($variants ?? []) as $variant): ?>
        <?php $preview = (isset($variant['preview']) && is_array($variant['preview'])) ? $variant['preview'] : []; ?>
        <article class="dl-card">
          <a class="dl-preview" href="<?= HTML::chars((string) ($variant['href'] ?? '#')) ?>" aria-label="Открыть вариант <?= HTML::chars((string) ($variant['title'] ?? '')) ?>" style="--preview-bg: <?= HTML::chars((string) ($preview['bg'] ?? '#eef2f7')) ?>; --preview-surface: <?= HTML::chars((string) ($preview['surface'] ?? '#ffffff')) ?>; --preview-ink: <?= HTML::chars((string) ($preview['ink'] ?? '#172033')) ?>; --preview-accent: <?= HTML::chars((string) ($preview['accent'] ?? '#2563eb')) ?>; --preview-accent2: <?= HTML::chars((string) ($preview['accent2'] ?? '#059669')) ?>">
            <span class="dl-preview-number"><?= HTML::chars(str_pad((string) ($variant['number'] ?? ''), 2, '0', STR_PAD_LEFT)) ?></span>
            <span class="dl-preview-window">
              <span class="dl-preview-side">
                <span></span><span></span><span></span><span></span>
              </span>
              <span class="dl-preview-main">
                <span class="dl-preview-title"></span>
                <span class="dl-preview-line"></span>
                <span class="dl-preview-cards"><span></span><span></span><span></span></span>
                <span class="dl-preview-panel"></span>
              </span>
            </span>
          </a>
          <div class="dl-card-body">
            <p class="dl-card-kicker">Вариант <?= HTML::chars(str_pad((string) ($variant['number'] ?? ''), 2, '0', STR_PAD_LEFT)) ?></p>
            <h3><?= HTML::chars((string) ($variant['title'] ?? '')) ?></h3>
            <p class="dl-card-strapline"><?= HTML::chars((string) ($variant['strapline'] ?? '')) ?></p>
            <p><?= HTML::chars((string) ($variant['description'] ?? '')) ?></p>
            <div class="dl-card-fit">
              <strong>Лучше всего подходит</strong>
              <span><?= HTML::chars((string) ($variant['best_for'] ?? '')) ?></span>
            </div>
            <a class="dl-card-link" href="<?= HTML::chars((string) ($variant['href'] ?? '#')) ?>">Смотреть вариант <span aria-hidden="true">→</span></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="dl-note" aria-labelledby="note-title">
    <div>
      <p class="dl-eyebrow">Важно</p>
      <h2 id="note-title">Это витрина, а не рабочий портал</h2>
    </div>
    <p>Страницы открываются без входа, не читают базу данных и не сохраняют введённые значения. После выбора направления его принципы можно перенести в общую дизайн-систему портала и затем последовательно обновить рабочие модули.</p>
  </section>
</main>

<footer class="dl-footer">
  <strong>PRX Group</strong>
  <span>Витрина обновляется без ручной очистки кэша: ответы запрещают сохранение, а ссылки содержат редакцию файлов.</span>
</footer>

<script>
(function () {
  var root = document.documentElement;
  var themeButtons = Array.prototype.slice.call(document.querySelectorAll('[data-theme-choice]'));
  var companyButtons = Array.prototype.slice.call(document.querySelectorAll('[data-company-choice]'));
  var companies = ['orange', 'blue', 'yellow'];

  function updateVariantLinks() {
    document.querySelectorAll('.dl-card-link, .dl-preview').forEach(function (link) {
      try {
        var href = new URL(link.href);
        href.searchParams.set('theme', root.dataset.theme);
        href.searchParams.set('company', root.dataset.companyAccent);
        link.href = href.toString();
      } catch (error) {}
    });
  }

  function paintTheme() {
    themeButtons.forEach(function (button) {
      var active = button.dataset.themeChoice === root.dataset.theme;
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.classList.toggle('is-active', active);
    });
  }

  function paintCompany() {
    companyButtons.forEach(function (button) {
      var active = button.dataset.companyChoice === root.dataset.companyAccent;
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.classList.toggle('is-active', active);
    });
  }

  themeButtons.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      var theme = button.dataset.themeChoice;
      root.dataset.theme = theme;
      root.style.colorScheme = theme;
      try { window.localStorage.setItem('prx-design-theme', theme); } catch (error) {}
      var url = new URL(window.location.href);
      url.searchParams.set('theme', theme);
      window.history.replaceState({}, '', url.toString());
      updateVariantLinks();
      paintTheme();
    });
  });

  companyButtons.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      var company = button.dataset.companyChoice;
      if (companies.indexOf(company) < 0) return;
      root.dataset.companyAccent = company;
      try { window.localStorage.setItem('prx-design-company', company); } catch (error) {}
      var url = new URL(window.location.href);
      url.searchParams.set('company', company);
      window.history.replaceState({}, '', url.toString());
      updateVariantLinks();
      paintCompany();
    });
  });

  paintTheme();
  paintCompany();
  updateVariantLinks();
})();
</script>
</body>
</html>
