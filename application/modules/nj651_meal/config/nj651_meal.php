<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Настройки сервиса «Калории по фото тарелки» (nj651_meal).
 *
 * Vision-модель настраивается в `workers/.env`: общий ключ `VLM_MODEL` или,
 * если этому сервису нужна другая модель, `NJ651_VISION_MODEL`.
 */

return [
    'limits' => [
        'photo_mb' => 8,
        'per_hour' => 15,
    ],
];
