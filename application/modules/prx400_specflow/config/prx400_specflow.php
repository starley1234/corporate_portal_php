<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Настройки prx400_specflow (env могут переопределять в bootstrap окружении).
 */
return [
    // Пороги доверия матчинга с НСИ (Confidence Scoring):
    //  >= auto_accept  — автоматическая привязка кода номенклатуры;
    //  >= manual_review — на подтверждение оператору (подсказки top-3);
    //  ниже            — «новая позиция» (заведение в НСИ).
    'match' => [
        'auto_accept'   => 0.92,
        'manual_review' => 0.65,
        'top_k'         => 3,
    ],
    // Хранилище исходных PDF (относительный путь — Storage_Path::absolute).
    'storage_dir' => 'storage/specflow',
    // Грубая отсечка загрузки (воркер сверяет точнее при скачивании).
    'max_upload_bytes' => 20971520,
    // Пределы строк результата при записи (воркер считает до 2000).
    'max_rows' => 2000,
];
