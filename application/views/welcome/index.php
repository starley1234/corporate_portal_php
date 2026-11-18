<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная']],
        'title'  => 'Платформа ИИ-сервисов',
        'pill'   => ['Для корпоративных систем', 'fa-layer-group'],
        'lead'   => 'Проверяйте обработку документов здесь или подключайте корпоративные системы через API.',
    ]); ?>

    <?php if ($can_test): ?>
    <section class="prx-panel mt-6">
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
            <a class="prx-btn-secondary" href="/documents">Реестр входящих</a>
            <a class="prx-btn-secondary" href="/analysis">ИИ-анализ входящих</a>
            <a class="prx-btn-secondary" href="/sla">Сроки и маршруты</a>
        </div>
        <?php else: ?>
        <p class="p-5">Выключен. Чтобы проверить на стенде, установите <code>PRX_DOCFLOW_ENABLED</code> в <code>TRUE</code> в <code>application/bootstrap.php</code>.</p>
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

    <details class="mt-8">
        <summary class="prx-h2">Корпоративный портал: отложенный план и архив</summary>

        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($domains as $key => $domain): ?>
            <?php if ($key === 'docflow' && $docflow_enabled) continue; ?>
            <section class="prx-card">
                <h3 class="prx-h3"><?php echo HTML::chars($domain['title']); ?><?php if (($domain['status'] ?? NULL) === 'archived') echo ' · архив'; ?></h3>
                <ul class="mt-3 space-y-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <?php foreach ($domain['services'] as $service): ?>
                    <li>
                        <?php if ($service['status'] === 'active' AND $service['module'] === 'prx300_employee'): ?>
                            <a class="prx-link font-semibold" href="/employee"><?php echo HTML::chars($service['title']); ?></a>
                            <span class="prx-badge prx-badge-ok ml-2"><span class="prx-dot"></span>доступно</span>
                        <?php else: ?>
                            <?php echo HTML::chars($service['title']); ?>
                            <?php if ($service['status'] === 'archived'): ?>
                                <span class="prx-badge ml-2">Требует доработки</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endforeach; ?>
        </div>
    </details>
</div>
