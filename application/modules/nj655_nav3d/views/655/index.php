<?php defined('SYSPATH') or die('No direct script access.'); ?>
<main id="nj655-orbit" class="nj655-orbit" data-catalog-url="<?php echo HTML::chars($catalog_api); ?>">
    <h1 id="orbit-title" class="n2j-h1 nj655-visually-hidden">3D-карта сервисов N2J</h1>
    <div id="orbit-stage" class="nj655-orbit__stage" tabindex="0" role="application" aria-labelledby="orbit-title" aria-label="Интерактивная 3D-карта сервисов. Кликните маяк, перетаскивайте для обзора, колесо меняет масштаб, стрелки выбирают сервис, Enter открывает его.">
        <canvas id="orbit-canvas" aria-hidden="true"></canvas>
        <div id="orbit-tooltip" class="nj655-orbit__tooltip" role="status" aria-hidden="true"></div>
        <div id="orbit-loading" class="nj655-orbit__loading"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span>Собираем пространство сервисов…</span></div>
    </div>

    <section id="orbit-panel" class="nj655-orbit__panel" hidden aria-live="polite" aria-labelledby="orbit-detail-title">
        <button id="orbit-close" type="button" class="nj655-orbit__close" aria-label="Закрыть карточку">×</button>
        <span id="orbit-kind" class="nj655-orbit__kind">сигнал</span>
        <h2 id="orbit-detail-title" class="nj655-orbit__detail-title"></h2>
        <p id="orbit-detail-text" class="nj655-orbit__detail-text"></p>
        <div id="orbit-detail-meta" class="nj655-orbit__detail-meta"></div>
        <div id="orbit-detail-api" class="nj655-orbit__detail-api" hidden></div>
        <a id="orbit-open" href="/services" class="nj655-orbit__open" hidden>Открыть сервис <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    </section>

    <!-- Скрытые семантические controls сохраняют управление без canvas. -->
    <input id="orbit-search" class="nj655-visually-hidden" type="search" autocomplete="off" aria-label="Найти сервис на карте">
    <button id="orbit-reset" class="nj655-visually-hidden" type="button">Вернуться к обзору</button>
    <span id="orbit-count" class="nj655-visually-hidden" aria-live="polite">Загружаем карту…</span>
    <div id="orbit-groups" class="nj655-visually-hidden" aria-label="Разделы сервисов"></div>

    <div id="orbit-fallback" class="nj655-orbit__fallback" hidden aria-live="polite">
        <h2>Откройте сервис списком</h2>
        <p id="orbit-fallback-note">3D-режим недоступен, но каталог остаётся доступным.</p>
        <div id="orbit-fallback-list" class="nj655-orbit__fallback-list"></div>
        <a href="/services">Обычный каталог</a>
    </div>
    <details class="nj655-visually-hidden">
        <summary>Текстовый список сервисов</summary>
        <div id="orbit-accessible-list"></div>
    </details>
    <noscript><p class="nj655-orbit__fallback">Для 3D-карты нужен JavaScript. <a href="/services">Откройте каталог сервисов</a>.</p></noscript>
</main>
