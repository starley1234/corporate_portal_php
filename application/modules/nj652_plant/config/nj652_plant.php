<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Настройки сервиса «Растение по фото» (nj652_plant).
 *
 * Vision-модель настраивается в `workers/.env`: общий ключ `VLM_MODEL` или,
 * если этому сервису нужна другая модель, `NJ652_VISION_MODEL`.
 */

return [
    'limits' => [
        'photo_mb' => 8,
        'per_hour' => 12,
        // Горизонт календаря ухода, недель.
        'weeks'    => 4,
    ],
];
