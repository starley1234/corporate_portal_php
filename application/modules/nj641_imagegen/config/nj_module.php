<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Генератор изображений» (nj641_imagegen) для nj110_catalog.
 */

return [
    'id'        => 641,
    'slug'      => 'imagegen',
    'title'     => 'Генератор изображений',
    'short'     => 'Картинки по описанию, редактирование и мульти-референсы: серверная генерация через воркера, готовая для презентаций и статей',
    'icon'      => 'fas fa-wand-magic-sparkles',
    'href'      => '/imagegen',
    'group'     => 'Дизайн',
    'sort'      => 641,
    'keywords'  => 'изображение картинка генерация ai референс редактирование фото презентация статья иллюстрация seed',
    'api'       => ['/imagegen/api/run', '/imagegen/api/result/1', '/imagegen/api/vram', '/imagegen/api/runs'],
    'card_view' => 'nj641/card',

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj641.generate' => [
                'reconcile' => "SELECT 1 FROM nj_641_runs r
             WHERE r.job_id = j.id AND r.status = 'done'",
            ],
        ],
    ],
];
