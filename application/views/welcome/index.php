<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php
    $actions = [['label' => 'План и архив портала', 'href' => '/portal-plan', 'icon' => 'fa-box-archive']];
    if ($can_test) $actions[] = ['label' => 'Проверить ИИ-сервисы', 'href' => '#service-checks', 'icon' => 'fa-flask'];
    echo View::factory('partials/page_header', [
        'crumbs'  => [['Главная']],
        'title'   => $title,
        'pill'    => ['План', 'fa-layer-group'],
        'actions' => $actions,
        'lead'    => 'Планируемые сервисы для разработки и изготовления конструкций и радиоэлектронной аппаратуры (РЭА): от ведения проектов до отработки рекламаций.',
    ]); ?>

    <?php echo View::factory('welcome/parts/enterprise_plan', ['phases' => $phases]); ?>

    <?php if ($can_test): ?>
    <section id="service-checks" class="prx-panel mt-6 scroll-mt-24">
        <div class="prx-panel-head"><h2 class="prx-h3">Проверить ИИ-сервисы</h2></div>
        <div class="flex flex-wrap gap-2.5 p-5">
            <a class="prx-btn-process" href="/document-extract"><i class="fas fa-file-pdf"></i>Распознавание PDF</a>
            <a class="prx-btn-secondary" href="/prx120/monitor"><i class="fas fa-list-check"></i>Монитор воркеров</a>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($can_test): ?>
    <section class="prx-panel mt-6">
        <div class="prx-panel-head"><h2 class="prx-h3">Документооборот · тестовый режим</h2></div>
        <?php if ($docflow_enabled): ?>
        <div class="flex flex-wrap gap-2.5 p-5">
            <a class="prx-btn-secondary" href="/inbox">Входящие документы</a>
            <a class="prx-btn-secondary" href="/prx391_documents">Реестр входящих</a>
            <a class="prx-btn-secondary" href="/analysis">ИИ-анализ входящих</a>
            <a class="prx-btn-secondary" href="/sla">Сроки и маршруты</a>
        </div>
        <?php else: ?>
        <p class="p-5">Выключен. Чтобы проверить на стенде, раскомментируйте блок старого документооборота в <code>application/bootstrap.php</code>.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="prx-panel mt-6">
        <div class="prx-panel-head">
            <h2 class="prx-h3"><i class="fas fa-compass mr-2 text-blue-500"></i>Быстрый переход</h2>
        </div>
        <div class="flex flex-wrap gap-2.5 p-5">
            <a class="prx-btn-secondary" href="/account/security"><i class="fas fa-shield-halved"></i>Мой аккаунт</a>
            <a class="prx-btn-secondary" href="/prx597"><i class="fas fa-bell"></i>Уведомления</a>
            <a class="prx-btn-secondary" href="/services"><i class="fas fa-layer-group"></i>Доступные сервисы</a>
        </div>
    </section>

</div>
