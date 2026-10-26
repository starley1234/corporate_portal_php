<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «CAD-детали» (nj643_cad) для nj110_catalog.
 */

return [
    'id'        => 643,
    'slug'      => 'nj643_cad',
    'title'     => 'CAD-детали',
    'short'     => 'Деталь по описанию: ИИ пишет параметрическую модель, движок проверяет геометрию и отдаёт STEP, STL, GLB и ракурсы',
    'icon'      => 'fas fa-cube',
    'href'      => '/cad',
    'group'     => 'Производство',
    'sort'      => 643,
    'status'    => 'beta',
    // раздел управления модуля: чип в шапке (только администратору)
    'admin'     => ['title' => 'CAD-детали · управление', 'href' => '/cad/admin'],
    'keywords'  => 'cad деталь 3d модель step stl glb build123d параметры печать чертёж производство оснастка кронштейн',
    'api'       => ['/cad/api/status/<code>', '/cad/api/run', '/cad/api/result'],
    'card_view' => '643/card',

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj643.cad' => [

                // CAD-деталь (nj643): приёмка /cad/api/result перевела деталь в 'done' с
                // finished_at — файлы и отчёт лежат на сайте, работа воркера окончена.
                // Без этого хука строка оставалась processing: страница рисовала живой
                // ряд «Собираю деталь» поверх готовой детали (владелец 27.09: «Всё
                // отработал, но процесс залип»), а свипер мог вернуть её в очередь и
                // запустить сборку второй раз — за токены владельца. Только 'done':
                // неудача закрывается приёмкой (mark_failed_from_site) или
                // авто-остановкой, и подменять её на «Готово» нельзя.
                'reconcile' => "SELECT 1 FROM nj_643_parts p
             WHERE p.id = CAST(j.payload->>'part_id' AS bigint)
               AND p.status = 'done'
               AND p.finished_at IS NOT NULL",
            ],
        ],
    ],
];
