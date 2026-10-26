<?php // design-audit: allow-file style,h1 — изолированная публичная галерея: CSS встраивается в ответ, а единственный H1 является заголовком витрины. ?><!doctype html>
<html lang="ru" data-theme="<?= HTML::chars((string) ($theme ?? '')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="design-lab-revision" content="<?= HTML::chars((string) ($revision ?? '')) ?>">
<title>Витрина дизайна PRL — 7 вариантов корпоративного портала</title>
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
<a class="dl-skip-link" href="#variants">Перейти к вариантам</a>

<header class="dl-header">
  <a class="dl-brand" href="#top" aria-label="PRL — витрина дизайна">
    <span class="dl-brand-mark">PRL</span>
    <span>
      <strong>Витрина дизайна</strong>
      <small>Корпоративный портал</small>
    </span>
  </a>
  <div class="dl-header-actions">
    <span class="dl-revision">Редакция <?= HTML::chars((string) ($revision ?? '')) ?></span>
    <div class="dl-theme-switch" role="group" aria-label="Тема оформления">
      <a class="dl-theme-option<?= (($theme ?? '') === 'light') ? ' is-active' : '' ?>" href="<?= HTML::chars((string) ($theme_urls['light'] ?? '#')) ?>" data-theme-choice="light" aria-pressed="<?= (($theme ?? '') === 'light') ? 'true' : 'false' ?>">День</a>
      <a class="dl-theme-option<?= (($theme ?? '') === 'dark') ? ' is-active' : '' ?>" href="<?= HTML::chars((string) ($theme_urls['dark'] ?? '#')) ?>" data-theme-choice="dark" aria-pressed="<?= (($theme ?? '') === 'dark') ? 'true' : 'false' ?>">Ночь</a>
    </div>
  </div>
</header>

<main id="top">
  <section class="dl-hero" aria-labelledby="landing-title">
    <div>
      <p class="dl-eyebrow">Семь направлений · одинаковое содержание</p>
      <h1 id="landing-title">Выберите характер корпоративного портала PRL</h1>
      <p class="dl-lead">Каждый вариант показывает портал целиком: навигацию, поиск, сервисы, задачи, сотрудников, новости, знания, аналитику и поддержку. Тексты и структура совпадают — сравнивается только дизайн.</p>
    </div>
    <aside class="dl-method" aria-label="Как сравнивать варианты">
      <h2>Как смотреть</h2>
      <ol>
        <li><strong>Откройте вариант</strong><span>и пролистайте страницу целиком.</span></li>
        <li><strong>Переключите день и ночь</strong><span>кнопками в верхней панели.</span></li>
        <li><strong>Проверьте мобильный вид</strong><span>на телефоне или уменьшив окно браузера.</span></li>
        <li><strong>Запомните номер</strong><span>чтобы назвать понравившееся направление.</span></li>
      </ol>
    </aside>
  </section>

  <section class="dl-facts" aria-label="Условия сравнения">
    <article><strong>7</strong><span>полноценных вариантов портала</span></article>
    <article><strong>1</strong><span>общий набор текстов и данных</span></article>
    <article><strong>2</strong><span> темы в каждом варианте: день и ночь</span></article>
    <article><strong>0</strong><span>зависимостей от общего CSS портала</span></article>
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
  <strong>PRL Group</strong>
  <span>Витрина обновляется без ручной очистки кэша: ответы запрещают сохранение, а ссылки содержат редакцию файлов.</span>
</footer>

<script>
(function () {
  var root = document.documentElement;
  var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-theme-choice]'));
  function paint() {
    buttons.forEach(function (button) {
      var active = button.dataset.themeChoice === root.dataset.theme;
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.classList.toggle('is-active', active);
    });
  }
  buttons.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      var theme = button.dataset.themeChoice;
      root.dataset.theme = theme;
      root.style.colorScheme = theme;
      try { window.localStorage.setItem('prl-design-theme', theme); } catch (error) {}
      var url = new URL(window.location.href);
      url.searchParams.set('theme', theme);
      window.history.replaceState({}, '', url.toString());
      document.querySelectorAll('.dl-card-link, .dl-preview').forEach(function (link) {
        var href = new URL(link.href);
        href.searchParams.set('theme', theme);
        link.href = href.toString();
      });
      paint();
    });
  });
  paint();
})();
</script>
</body>
</html>
