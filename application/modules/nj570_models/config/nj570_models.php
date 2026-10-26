<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Настройки 3D-конструктора (NJ-570, «Sketch 2 Solid»).
 */

return [
    'code_prefix' => 'm3d',
    'code_bytes'  => 4,

    // Итераций создания по умолчанию и потолок (1..5)
    'iterations_default' => 3,
    'iterations_max'     => 5,

    // Лимит изображения: ширина 512px (экономим токены vision-модели),
    // base64 не больше ~700 КБ
    'image_max_width'    => 512,
    'image_max_bytes'    => 700000,

    // Лимиты хранения
    'max_file_bytes'     => 60 * 1024 * 1024,

    // Ревизий на модель — потолок (дальше старые можно чистить очисткой)
    'max_revisions'      => 30,
];
