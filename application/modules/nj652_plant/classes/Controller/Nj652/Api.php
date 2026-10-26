<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Растения по фото» (NJ-652).
 *
 * Конвейер «кадр → очередь → воркер → приёмка → статус» общий
 * (`Controller_Vision_Base`); здесь только канал, таблица и разбор ответа.
 *
 *   POST /plant/api/photo          кадр растения → задача nj652.plant
 *   GET  /plant/api/status?id=N    статус прогона и разбор
 *   POST /plant/api/photo_file     кадр воркеру (X-Worker-Token)
 *   POST /plant/api/worker_result  приёмка результата (X-Worker-Token)
 */
class Controller_Nj652_Api extends Controller_Vision_Base
{
    protected function _vision()
    {
        return [
            'module'      => 'nj652_plant',
            'table'       => 'nj_652_runs',
            'channel'     => 'nj652.plant',
            'title'       => 'Растение',
            // Создание прогона — только для зарегистрированных (решение
            // владельца 06.10.2026, «как опросы»). Гейт — в Controller_Vision_Base.
            'auth'        => TRUE,
            'photo_mb'    => (int) (Kohana::$config->load('nj652_plant.limits.photo_mb') ?: 8),
            'per_hour'    => (int) (Kohana::$config->load('nj652_plant.limits.per_hour') ?: 12),
            'timeout_sec' => 300,
            'shape'       => ['Service_Nj652_Plant', 'shape'],
        ];
    }
}
