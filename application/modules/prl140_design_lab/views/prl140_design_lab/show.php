<?php // design-audit: allow-file style,h1 — изолированная публичная витрина: CSS варианта встраивается в ответ, а единственный H1 является заголовком демонстрационного портала. ?><!doctype html>
<html lang="ru" data-theme="<?= HTML::chars((string) ($theme ?? '')) ?>" data-company-accent="<?= HTML::chars((string) ($company_accent ?? '')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="design-lab-revision" content="<?= HTML::chars((string) ($revision ?? '')) ?>">
<title><?= HTML::chars((string) ($variant['title'] ?? 'Вариант дизайна')) ?> — витрина PRL</title>
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
<style data-design-lab-css="<?= HTML::chars((string) ($variant['slug'] ?? '')) ?>">
<?= (string) ($css ?? '') ?>
</style>
</head>
<body data-variant="<?= HTML::chars((string) ($variant['slug'] ?? '')) ?>">
<?php
// Точный эталон v6 использует локальный inline-SVG набор вместо Font Awesome:
// витрина остаётся автономной, без общего CSS, шрифтов и внешних файлов.
$dl_show_icons = (($variant['slug'] ?? '') === 'required-design-sample');
$dl_icon = static function ($name) use ($dl_show_icons) {
    if (!$dl_show_icons) {
        return '';
    }
    $name = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $name));
    if ($name === '') {
        return '';
    }
    return '<svg class="dl-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#dl-icon-' . $name . '"></use></svg>';
};
?>
<?php if ($dl_show_icons): ?>
<svg class="dl-visually-hidden" aria-hidden="true" focusable="false">
  <symbol id="dl-icon-home" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.8V21h14V9.8"/><path d="M9.5 21v-6h5v6"/></symbol>
  <symbol id="dl-icon-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
  <symbol id="dl-icon-check-square" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="m8.5 12.2 2.4 2.4 4.8-5"/></symbol>
  <symbol id="dl-icon-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.4 3.2-5 6.5-5s5.7 1.6 6.5 5"/><circle cx="17.5" cy="9" r="2.5"/><path d="M16.2 15.2c2.6.3 4.5 1.9 5.3 4.8"/></symbol>
  <symbol id="dl-icon-newspaper" viewBox="0 0 24 24"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H17v16H6.5A2.5 2.5 0 0 1 4 17.5z"/><path d="M17 9h2.5A1.5 1.5 0 0 1 21 10.5V18a2 2 0 0 1-2 2"/><path d="M7 8h6M7 12h6M7 16h6"/></symbol>
  <symbol id="dl-icon-book" viewBox="0 0 24 24"><path d="M5 4.5A2.5 2.5 0 0 1 7.5 2H20v17.5H7.5A2.5 2.5 0 0 0 5 22z"/><path d="M5 17a2.5 2.5 0 0 1 2.5-2.5H20"/></symbol>
  <symbol id="dl-icon-headset" viewBox="0 0 24 24"><path d="M4 13a8 8 0 0 1 16 0"/><rect x="3" y="13" width="4" height="7" rx="2"/><rect x="17" y="13" width="4" height="7" rx="2"/><path d="M19 20a4 4 0 0 1-4 2h-2"/></symbol>
  <symbol id="dl-icon-file-check" viewBox="0 0 24 24"><path d="M6 2.5h8L19 7v14.5H6z"/><path d="M14 2.5V7h5"/><path d="m9 14 2 2 4-4.5"/></symbol>
  <symbol id="dl-icon-briefcase" viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="12" rx="2"/><path d="M9 8V6a3 3 0 0 1 3-3 3 3 0 0 1 3 3v2"/><path d="M3 13h18"/></symbol>
  <symbol id="dl-icon-graduation" viewBox="0 0 24 24"><path d="m2.5 9 9.5-4.5L21.5 9 12 13.5z"/><path d="M6 11.5V16c0 1.7 2.7 3 6 3s6-1.3 6-3v-4.5"/><path d="M21.5 9v5"/></symbol>
  <symbol id="dl-icon-comments" viewBox="0 0 24 24"><path d="M21 12a8 8 0 0 1-8 8H4l-1 1V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8z"/><path d="M8 11h8M8 14.5h5"/></symbol>
  <symbol id="dl-icon-chart" viewBox="0 0 24 24"><path d="M4 3v18h17"/><path d="M8 17v-5M12 17V8M16 17v-8M20 17V6"/></symbol>
  <symbol id="dl-icon-plug" viewBox="0 0 24 24"><path d="M9 7V3M15 7V3"/><path d="M7 7h10v4a5 5 0 0 1-10 0z"/><path d="M12 16v5"/></symbol>
  <symbol id="dl-icon-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.8-3.8"/></symbol>
  <symbol id="dl-icon-bell" viewBox="0 0 24 24"><path d="M18 9a6 6 0 1 0-12 0c0 7-2.5 8-2.5 8h17S18 16 18 9"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></symbol>
  <symbol id="dl-icon-bars" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
  <symbol id="dl-icon-arrow-right" viewBox="0 0 24 24"><path d="M4 12h16"/><path d="m14 6 6 6-6 6"/></symbol>
  <symbol id="dl-icon-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="dl-icon-send" viewBox="0 0 24 24"><path d="m21 3-9.5 9.5"/><path d="M21 3 14 21l-2.5-8.5L3 10z"/></symbol>
  <symbol id="dl-icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="dl-icon-layers" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/></symbol>
  <symbol id="dl-icon-check" viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"/></symbol>
</svg>
<?php endif; ?>
<a class="dl-skip-link" href="#main">Перейти к содержанию</a>

<div class="dl-labbar" aria-label="Управление витриной дизайна">
  <a class="dl-labbar-home" href="<?= HTML::chars((string) ($home_url ?? '/design-lab')) ?>">← Все варианты</a>
  <nav class="dl-variant-switch" aria-label="Выбор варианта дизайна">
    <?php foreach (($variants ?? []) as $item): ?>
      <a class="dl-variant-link<?= (($item['slug'] ?? '') === ($variant['slug'] ?? '')) ? ' is-active' : '' ?>" href="<?= HTML::chars((string) ($item['href'] ?? '#')) ?>"<?php if (($item['slug'] ?? '') === ($variant['slug'] ?? '')): ?> aria-current="page"<?php endif; ?>>
        <span><?= HTML::chars(str_pad((string) ($item['number'] ?? ''), 2, '0', STR_PAD_LEFT)) ?></span>
        <span><?= HTML::chars((string) ($item['title'] ?? '')) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
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

<div class="dl-shell">
  <aside class="dl-sidebar">
    <div class="dl-sidebar-head">
      <a class="dl-brand" href="#overview" aria-label="PRL — корпоративный портал">
        <span class="dl-brand-mark">PRL</span>
        <span class="dl-brand-copy">
          <strong>Корпоративный портал</strong>
          <small>PRL Group</small>
        </span>
      </a>
      <button class="dl-menu-button" type="button" aria-expanded="false" aria-controls="primary-navigation"><?= $dl_icon('bars') ?><span>Меню</span></button>
    </div>

    <nav class="dl-primary-nav" id="primary-navigation" aria-label="Основная навигация портала">
      <?php foreach (($content['navigation'] ?? []) as $item): ?>
        <a class="dl-nav-link<?= !empty($item['active']) ? ' is-active' : '' ?>" href="<?= HTML::chars((string) ($item['href'] ?? '#')) ?>"<?php if (!empty($item['active'])): ?> aria-current="page"<?php endif; ?>>
          <span class="dl-nav-label"><?= $dl_icon((string) ($item['icon'] ?? '')) ?><span><?= HTML::chars((string) ($item['label'] ?? '')) ?></span></span>
          <?php if (!empty($item['badge'])): ?><span class="dl-nav-badge"><?= HTML::chars((string) $item['badge']) ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <section class="dl-sidebar-profile" aria-label="Профиль сотрудника">
      <span class="dl-avatar"><?= HTML::chars((string) ($content['person']['initials'] ?? '')) ?></span>
      <div>
        <strong><?= HTML::chars((string) ($content['person']['name'] ?? '')) ?></strong>
        <small><?= HTML::chars((string) ($content['person']['role'] ?? '')) ?></small>
        <span class="dl-presence"><span class="dl-presence-dot"></span><?= HTML::chars((string) ($content['person']['status'] ?? '')) ?></span>
      </div>
    </section>

    <section class="dl-sidebar-note">
      <strong>Пилотный запуск</strong>
      <p>Кадровый справочник уже доступен командам центрального офиса и площадки «Север».</p>
      <a href="#services">Посмотреть сервисы<?= $dl_icon('arrow-right') ?></a>
    </section>
  </aside>

  <div class="dl-workspace">
    <header class="dl-topbar">
      <div class="dl-date-block">
        <span>Понедельник, 26 октября 2026</span>
        <strong><?= HTML::chars((string) ($content['person']['org'] ?? '')) ?></strong>
      </div>

      <form class="dl-search" role="search" data-demo-search>
        <label class="dl-visually-hidden" for="portal-search">Поиск по порталу</label>
        <input id="portal-search" name="q" type="search" placeholder="Найти сотрудника, документ или сервис" autocomplete="off">
        <button type="submit"><?= $dl_icon('search') ?><span>Найти</span></button>
        <output class="dl-search-status" aria-live="polite"></output>
      </form>

      <a class="dl-notification-link" href="#notifications" aria-label="Уведомления, 3 новых">
        <?= $dl_icon('bell') ?><span class="dl-notification-label">Уведомления</span>
        <strong>3</strong>
      </a>

      <div class="dl-user-chip">
        <span class="dl-avatar"><?= HTML::chars((string) ($content['person']['initials'] ?? '')) ?></span>
        <span>
          <strong><?= HTML::chars((string) ($content['person']['name'] ?? '')) ?></strong>
          <small><?= HTML::chars((string) ($content['person']['org'] ?? '')) ?></small>
        </span>
      </div>
    </header>

    <main id="main" class="dl-main">
      <section class="dl-hero" id="overview" aria-labelledby="portal-title">
        <div class="dl-hero-copy">
          <p class="dl-eyebrow">Вариант <?= HTML::chars(str_pad((string) ($variant['number'] ?? ''), 2, '0', STR_PAD_LEFT)) ?> · <?= HTML::chars((string) ($variant['title'] ?? '')) ?></p>
          <h1 id="portal-title">Добрый день, Анна. Всё важное для работы — в одном месте.</h1>
          <p class="dl-hero-lead">Сотрудники, документы, проекты, знания и сервисы компании собраны в едином цифровом рабочем месте для всех площадок PRL Group.</p>
          <div class="dl-hero-actions">
            <a class="dl-button dl-button-primary" href="#services"><?= $dl_icon('grid') ?><span>Открыть сервисы</span></a>
            <a class="dl-button dl-button-secondary" href="#support"><?= $dl_icon('plus') ?><span>Создать обращение</span></a>
          </div>
          <p class="dl-hero-caption"><?= HTML::chars((string) ($variant['strapline'] ?? '')) ?></p>
        </div>

        <aside class="dl-today-card" aria-labelledby="today-title">
          <div class="dl-panel-head">
            <div>
              <p class="dl-section-kicker">Мой день</p>
              <h2 id="today-title"><?= $dl_icon('clock') ?><span>Сегодня в фокусе</span></h2>
            </div>
            <span class="dl-status dl-tone-attention">3 события</span>
          </div>
          <ol class="dl-today-list">
            <li><time>10:00</time><div><strong>Демо кадрового справочника</strong><small>Переговорная «Восток» и онлайн</small></div></li>
            <li><time>13:30</time><div><strong>Проверка интеграций</strong><small>Кадровая система и документооборот</small></div></li>
            <li><time>16:00</time><div><strong>Срок согласования плана</strong><small>Документ ожидает решения</small></div></li>
          </ol>
        </aside>
      </section>

      <section class="dl-metrics" aria-label="Ключевые показатели портала">
        <?php foreach (($content['metrics'] ?? []) as $metric): ?>
          <article class="dl-metric-card">
            <span><?= HTML::chars((string) ($metric['label'] ?? '')) ?></span>
            <strong><?= HTML::chars((string) ($metric['value'] ?? '')) ?></strong>
            <small class="dl-tone-<?= HTML::chars((string) ($metric['tone'] ?? 'neutral')) ?>"><?= HTML::chars((string) ($metric['note'] ?? '')) ?></small>
          </article>
        <?php endforeach; ?>
      </section>

      <section class="dl-section" aria-labelledby="domains-title">
        <div class="dl-section-head">
          <div>
            <p class="dl-section-kicker">Архитектура портала</p>
            <h2 id="domains-title">Семь доменов корпоративных сервисов</h2>
          </div>
          <p>Единая структура для 50 сервисов: от справочника сотрудников до аналитики и интеграций.</p>
        </div>
        <div class="dl-domain-grid">
          <?php foreach (($content['domains'] ?? []) as $domain): ?>
            <article class="dl-domain-card">
              <span class="dl-domain-number"><?php if ($dl_show_icons): ?><?= $dl_icon((string) ($domain['icon'] ?? '')) ?><?php else: ?><?= HTML::chars((string) ($domain['number'] ?? '')) ?><?php endif; ?></span>
              <div>
                <h3><?= HTML::chars((string) ($domain['title'] ?? '')) ?></h3>
                <p><?= HTML::chars((string) ($domain['note'] ?? '')) ?></p>
              </div>
              <strong><?= HTML::chars((string) ($domain['count'] ?? '')) ?></strong>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="dl-section" id="services" aria-labelledby="services-title">
        <div class="dl-section-head">
          <div>
            <p class="dl-section-kicker">Каталог</p>
            <h2 id="services-title">Часто используемые сервисы</h2>
          </div>
          <a class="dl-section-link" href="#support">Запросить новый сервис<?= $dl_icon('arrow-right') ?></a>
        </div>
        <div class="dl-service-grid">
          <?php foreach (($content['services'] ?? []) as $service): ?>
            <article class="dl-service-card">
              <div class="dl-service-top">
                <span class="dl-service-code"><?php if ($dl_show_icons): ?><?= $dl_icon((string) ($service['icon'] ?? '')) ?><?php else: ?><?= HTML::chars((string) ($service['code'] ?? '')) ?><?php endif; ?></span>
                <span class="dl-status dl-tone-<?= HTML::chars((string) ($service['tone'] ?? 'neutral')) ?>"><?= HTML::chars((string) ($service['status'] ?? '')) ?></span>
              </div>
              <p class="dl-service-domain"><?= HTML::chars((string) ($service['domain'] ?? '')) ?></p>
              <h3><?= HTML::chars((string) ($service['title'] ?? '')) ?></h3>
              <p><?= HTML::chars((string) ($service['note'] ?? '')) ?></p>
              <div class="dl-service-footer">
                <span><?= HTML::chars((string) ($service['users'] ?? '')) ?></span>
                <button type="button" data-demo-action><span>Открыть</span><?= $dl_icon('arrow-right') ?></button>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="dl-two-column" id="work">
        <section class="dl-panel dl-tasks-panel" aria-labelledby="tasks-title">
          <div class="dl-panel-head">
            <div>
              <p class="dl-section-kicker">Работа</p>
              <h2 id="tasks-title">Мои задачи и согласования</h2>
            </div>
            <span class="dl-count-badge">8 активных</span>
          </div>
          <ol class="dl-task-list">
            <?php foreach (($content['tasks'] ?? []) as $task): ?>
              <li class="dl-task-row">
                <span class="dl-task-marker dl-tone-<?= HTML::chars((string) ($task['tone'] ?? 'neutral')) ?>"></span>
                <div>
                  <h3><?= HTML::chars((string) ($task['title'] ?? '')) ?></h3>
                  <p><?= HTML::chars((string) ($task['meta'] ?? '')) ?></p>
                </div>
                <span class="dl-status dl-tone-<?= HTML::chars((string) ($task['tone'] ?? 'neutral')) ?>"><?= HTML::chars((string) ($task['status'] ?? '')) ?></span>
                <button type="button" data-demo-action><span><?= HTML::chars((string) ($task['action'] ?? 'Открыть')) ?></span><?= $dl_icon('arrow-right') ?></button>
              </li>
            <?php endforeach; ?>
          </ol>
          <output class="dl-demo-status" aria-live="polite"></output>
        </section>

        <section class="dl-panel" id="notifications" aria-labelledby="notifications-title">
          <div class="dl-panel-head">
            <div>
              <p class="dl-section-kicker">Сигналы</p>
              <h2 id="notifications-title">Уведомления</h2>
            </div>
            <span class="dl-count-badge">3 новых</span>
          </div>
          <ul class="dl-notification-list">
            <?php foreach (($content['notifications'] ?? []) as $notification): ?>
              <li>
                <span class="dl-notification-dot dl-tone-<?= HTML::chars((string) ($notification['tone'] ?? 'neutral')) ?>"></span>
                <div>
                  <h3><?= HTML::chars((string) ($notification['title'] ?? '')) ?></h3>
                  <p><?= HTML::chars((string) ($notification['meta'] ?? '')) ?></p>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
          <div class="dl-panel-callout">
            <strong>Настройка доставки</strong>
            <p>Важные согласования приходят сразу, остальные события — в ежедневной сводке в 09:00.</p>
          </div>
        </section>
      </div>

      <section class="dl-section" id="people" aria-labelledby="people-title">
        <div class="dl-section-head">
          <div>
            <p class="dl-section-kicker">Люди</p>
            <h2 id="people-title">Команда и эксперты</h2>
          </div>
          <a class="dl-section-link" href="#knowledge">Найти эксперта<?= $dl_icon('arrow-right') ?></a>
        </div>
        <div class="dl-people-grid">
          <?php foreach (($content['people'] ?? []) as $person): ?>
            <article class="dl-person-card">
              <span class="dl-avatar"><?= HTML::chars((string) ($person['initials'] ?? '')) ?></span>
              <div>
                <h3><?= HTML::chars((string) ($person['name'] ?? '')) ?></h3>
                <p><?= HTML::chars((string) ($person['role'] ?? '')) ?></p>
                <small><span class="dl-presence-dot dl-tone-<?= HTML::chars((string) ($person['tone'] ?? 'neutral')) ?>"></span><?= HTML::chars((string) ($person['meta'] ?? '')) ?></small>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="dl-two-column dl-news-layout" id="news">
        <section class="dl-panel" aria-labelledby="news-title">
          <div class="dl-panel-head">
            <div>
              <p class="dl-section-kicker">Коммуникации</p>
              <h2 id="news-title">Новости компании</h2>
            </div>
            <a class="dl-section-link" href="#knowledge">Все публикации<?= $dl_icon('arrow-right') ?></a>
          </div>
          <div class="dl-news-list">
            <?php foreach (($content['news'] ?? []) as $news): ?>
              <article class="dl-news-item">
                <div class="dl-news-meta">
                  <span><?= HTML::chars((string) ($news['tag'] ?? '')) ?></span>
                  <time><?= HTML::chars((string) ($news['date'] ?? '')) ?></time>
                </div>
                <h3><?= HTML::chars((string) ($news['title'] ?? '')) ?></h3>
                <p><?= HTML::chars((string) ($news['note'] ?? '')) ?></p>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="dl-panel" aria-labelledby="events-title">
          <div class="dl-panel-head">
            <div>
              <p class="dl-section-kicker">Календарь</p>
              <h2 id="events-title">Ближайшие события</h2>
            </div>
          </div>
          <ul class="dl-event-list">
            <?php foreach (($content['events'] ?? []) as $event): ?>
              <li>
                <span class="dl-event-date">
                  <strong><?= HTML::chars((string) ($event['day'] ?? '')) ?></strong>
                  <small><?= HTML::chars((string) ($event['month'] ?? '')) ?></small>
                </span>
                <div>
                  <h3><?= HTML::chars((string) ($event['title'] ?? '')) ?></h3>
                  <p><?= HTML::chars((string) ($event['meta'] ?? '')) ?></p>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      </div>

      <div class="dl-three-column" id="knowledge">
        <section class="dl-panel" aria-labelledby="knowledge-title">
          <div class="dl-panel-head">
            <div>
              <p class="dl-section-kicker">Знания</p>
              <h2 id="knowledge-title">Документы и обучение</h2>
            </div>
          </div>
          <ul class="dl-knowledge-list">
            <?php foreach (($content['knowledge'] ?? []) as $item): ?>
              <li>
                <span><?= $dl_icon('book') ?><span><?= HTML::chars((string) ($item['type'] ?? '')) ?></span></span>
                <h3><?= HTML::chars((string) ($item['title'] ?? '')) ?></h3>
                <p><?= HTML::chars((string) ($item['meta'] ?? '')) ?></p>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>

        <section class="dl-panel" aria-labelledby="analytics-title">
          <div class="dl-panel-head">
            <div>
              <p class="dl-section-kicker">Аналитика</p>
              <h2 id="analytics-title">Показатели портала</h2>
            </div>
          </div>
          <ul class="dl-analytics-list">
            <?php foreach (($content['analytics'] ?? []) as $item): ?>
              <li>
                <div>
                  <span><?= HTML::chars((string) ($item['label'] ?? '')) ?></span>
                  <strong><?= HTML::chars((string) ($item['value'] ?? '')) ?></strong>
                </div>
                <span class="dl-meter" aria-hidden="true"><span style="--dl-value: <?= (int) ($item['percent'] ?? 0) ?>%"></span></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>

        <section class="dl-panel" aria-labelledby="integrations-title">
          <div class="dl-panel-head">
            <div>
              <p class="dl-section-kicker">Платформа</p>
              <h2 id="integrations-title">Состояние интеграций</h2>
            </div>
          </div>
          <ul class="dl-integration-list">
            <?php foreach (($content['integrations'] ?? []) as $integration): ?>
              <li>
                <div>
                  <h3><?= HTML::chars((string) ($integration['name'] ?? '')) ?></h3>
                  <p><?= HTML::chars((string) ($integration['note'] ?? '')) ?></p>
                </div>
                <span class="dl-status dl-tone-<?= HTML::chars((string) ($integration['tone'] ?? 'neutral')) ?>"><?= HTML::chars((string) ($integration['status'] ?? '')) ?></span>
                <strong><?= (int) ($integration['value'] ?? 0) ?>%</strong>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      </div>

      <section class="dl-support" id="support" aria-labelledby="support-title">
        <div class="dl-support-copy">
          <p class="dl-section-kicker">Поддержка</p>
          <h2 id="support-title">Нужна помощь или новый доступ?</h2>
          <p>Единая точка обращения для техники, доступов, помещений и корпоративных сервисов. Среднее время первого ответа — 38 минут.</p>
          <ul>
            <li><strong>38 минут</strong><span>средний первый ответ</span></li>
            <li><strong>94%</strong><span>обращений закрыто в срок</span></li>
            <li><strong>24/7</strong><span>критичные производственные события</span></li>
          </ul>
        </div>

        <form class="dl-support-form" data-demo-support>
          <div>
            <label for="support-topic">Тема обращения</label>
            <select id="support-topic" name="topic" required>
              <option value="access">Доступ к сервису или данным</option>
              <option value="equipment">Оборудование и рабочее место</option>
              <option value="document">Документы и согласование</option>
              <option value="other">Другой вопрос</option>
            </select>
          </div>
          <div>
            <label for="support-priority">Приоритет</label>
            <select id="support-priority" name="priority" required>
              <option value="normal">Обычный</option>
              <option value="high">Высокий</option>
              <option value="critical">Критичный</option>
            </select>
          </div>
          <div class="dl-form-wide">
            <label for="support-message">Что нужно сделать?</label>
            <textarea id="support-message" name="message" rows="4" required placeholder="Например: нужен доступ к аналитической панели для команды проекта"></textarea>
          </div>
          <button class="dl-button dl-button-primary" type="submit"><?= $dl_icon('send') ?><span>Отправить обращение</span></button>
          <output class="dl-support-status" aria-live="polite"></output>
        </form>
      </section>
    </main>

    <footer class="dl-footer">
      <div>
        <strong>PRL Group · Корпоративный портал</strong>
        <p>Демонстрационный контент. Данные, люди и показатели на этой странице вымышлены и не связаны с рабочей системой.</p>
      </div>
      <nav class="dl-pager" aria-label="Навигация между вариантами дизайна">
        <a href="<?= HTML::chars((string) ($previous['href'] ?? '#')) ?>">← <?= HTML::chars((string) ($previous['title'] ?? 'Предыдущий')) ?></a>
        <a href="<?= HTML::chars((string) ($home_url ?? '/design-lab')) ?>">Все <?= (int) ($variant_count ?? 0) ?> вариантов</a>
        <a href="<?= HTML::chars((string) ($next['href'] ?? '#')) ?>"><?= HTML::chars((string) ($next['title'] ?? 'Следующий')) ?> →</a>
      </nav>
    </footer>
  </div>
</div>

<script>
(function () {
  var root = document.documentElement;
  var themeButtons = Array.prototype.slice.call(document.querySelectorAll('[data-theme-choice]'));
  var companyButtons = Array.prototype.slice.call(document.querySelectorAll('[data-company-choice]'));
  var companies = ['orange', 'blue', 'yellow'];

  function updateLabLinks() {
    document.querySelectorAll('.dl-variant-link, .dl-labbar-home, .dl-pager a').forEach(function (link) {
      try {
        var href = new URL(link.href);
        href.searchParams.set('theme', root.dataset.theme);
        href.searchParams.set('company', root.dataset.companyAccent);
        link.href = href.toString();
      } catch (error) {}
    });
  }

  function revealActiveVariant() {
    var variantSwitch = document.querySelector('.dl-variant-switch');
    var activeVariant = document.querySelector('.dl-variant-link.is-active');
    if (!variantSwitch || !activeVariant) return;
    var switchRect = variantSwitch.getBoundingClientRect();
    var activeRect = activeVariant.getBoundingClientRect();
    variantSwitch.scrollLeft += activeRect.left - switchRect.left - ((variantSwitch.clientWidth - activeRect.width) / 2);
  }

  function paintThemeButtons() {
    themeButtons.forEach(function (button) {
      button.setAttribute('aria-pressed', button.dataset.themeChoice === root.dataset.theme ? 'true' : 'false');
      button.classList.toggle('is-active', button.dataset.themeChoice === root.dataset.theme);
    });
  }

  function paintCompanyButtons() {
    companyButtons.forEach(function (button) {
      button.setAttribute('aria-pressed', button.dataset.companyChoice === root.dataset.companyAccent ? 'true' : 'false');
      button.classList.toggle('is-active', button.dataset.companyChoice === root.dataset.companyAccent);
    });
  }

  function setTheme(theme, save) {
    if (theme !== 'light' && theme !== 'dark') return;
    root.dataset.theme = theme;
    root.style.colorScheme = theme;
    if (save) {
      try { window.localStorage.setItem('prl-design-theme', theme); } catch (error) {}
      var url = new URL(window.location.href);
      url.searchParams.set('theme', theme);
      window.history.replaceState({}, '', url.toString());
    }
    paintThemeButtons();
    updateLabLinks();
  }

  function setCompany(company) {
    if (companies.indexOf(company) < 0) return;
    root.dataset.companyAccent = company;
    try { window.localStorage.setItem('prl-design-company', company); } catch (error) {}
    var url = new URL(window.location.href);
    url.searchParams.set('company', company);
    window.history.replaceState({}, '', url.toString());
    paintCompanyButtons();
    updateLabLinks();
  }

  themeButtons.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      setTheme(button.dataset.themeChoice, true);
    });
  });
  companyButtons.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      setCompany(button.dataset.companyChoice);
    });
  });
  paintThemeButtons();
  paintCompanyButtons();
  updateLabLinks();
  revealActiveVariant();

  var media = window.matchMedia('(prefers-color-scheme: dark)');
  if (media.addEventListener) {
    media.addEventListener('change', function (event) {
      var saved = null;
      try { saved = window.localStorage.getItem('prl-design-theme'); } catch (error) {}
      if (saved !== 'light' && saved !== 'dark' && !new URLSearchParams(window.location.search).has('theme')) {
        setTheme(event.matches ? 'dark' : 'light', false);
      }
    });
  }

  var menuButton = document.querySelector('.dl-menu-button');
  root.dataset.menuOpen = 'false';
  if (menuButton) {
    menuButton.addEventListener('click', function () {
      var open = root.dataset.menuOpen !== 'true';
      root.dataset.menuOpen = open ? 'true' : 'false';
      menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.querySelectorAll('.dl-nav-link').forEach(function (link) {
      link.addEventListener('click', function () {
        root.dataset.menuOpen = 'false';
        menuButton.setAttribute('aria-expanded', 'false');
      });
    });
  }

  var search = document.querySelector('[data-demo-search]');
  if (search) {
    search.addEventListener('submit', function (event) {
      event.preventDefault();
      var input = search.querySelector('input[type="search"]');
      var output = search.querySelector('.dl-search-status');
      var query = input ? input.value.trim() : '';
      if (output) output.textContent = query ? 'Демо-поиск: «' + query + '»' : 'Введите запрос для демонстрации поиска.';
    });
  }

  var support = document.querySelector('[data-demo-support]');
  if (support) {
    support.addEventListener('submit', function (event) {
      event.preventDefault();
      var output = support.querySelector('.dl-support-status');
      if (output) output.textContent = 'Демонстрация: обращение не отправлено и данные не сохранены.';
    });
  }

  var demoStatus = document.querySelector('.dl-demo-status');
  document.querySelectorAll('[data-demo-action]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (demoStatus) demoStatus.textContent = 'Демонстрация: действие не выполняет операций в рабочей системе.';
    });
  });
})();
</script>
</body>
</html>
