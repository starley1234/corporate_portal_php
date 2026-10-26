<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Чека по фото» (NJ-650).
 *
 * Весь конвейер «кадр → очередь → воркер → приёмка → статус» общий
 * (`Controller_Vision_Base`): здесь только три своих ответа — канал, таблица
 * и как привести ответ модели к виду страницы.
 *
 *   POST /receipt/api/photo          кадр чека → задача nj650.receipt
 *   GET  /receipt/api/status?id=N    статус прогона и разбор
 *   POST /receipt/api/photo_file     кадр воркеру (X-Worker-Token)
 *   POST /receipt/api/worker_result  приёмка результата (X-Worker-Token)
 */
class Controller_Nj650_Api extends Controller_Vision_Base
{
    protected function _vision()
    {
        return [
            'module'      => 'nj650_receipt',
            'table'       => 'nj_650_runs',
            'channel'     => 'nj650.receipt',
            'title'       => 'Чек',
            // Создание прогона — только для зарегистрированных (решение
            // владельца 06.10.2026, «как опросы»). Гейт — в Controller_Vision_Base.
            'auth'        => TRUE,
            'photo_mb'    => (int) (Kohana::$config->load('nj650_receipt.limits.photo_mb') ?: 8),
            'per_hour'    => (int) (Kohana::$config->load('nj650_receipt.limits.per_hour') ?: 12),
            // Больше предела ожидания модели в воркере: иначе задачу снимут
            // ровно в момент ответа, и на сайт не уедет ни разбор, ни ошибка.
            'timeout_sec' => 300,
            'shape'       => ['Service_Nj650_Receipt', 'shape'],
        ];
    }
}
