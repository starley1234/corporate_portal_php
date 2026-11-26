<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Платформа'], ['О платформе']],
        'title'  => 'О платформе',
        'pill'   => ['Платформа PRX', 'fa-building'],
        'lead'   => 'Единое рабочее пространство холдинга: сервисы, данные, процессы и ассистент в одном интерфейсе.',
    ]); ?>

    <section class="prx-panel mt-6">
        <div class="prx-prose p-6">
            <p>PRX — единое рабочее пространство сотрудников холдинга. Учётные записи предоставляет администратор организации. Прикладные сервисы подключаются по мере реализации.</p>
        </div>
    </section>
</div>
