<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Калорий по фото тарелки» (NJ-651).
 *
 * Конвейер «кадр → очередь → воркер → приёмка → статус» общий
 * (`Controller_Vision_Base`); здесь только канал, таблица и разбор ответа.
 *
 *   POST /meal/api/photo          кадр тарелки → задача nj651.meal
 *   GET  /meal/api/status?id=N    статус прогона и разбор
 *   GET  /meal/api/preview?id=N   приватная миниатюра владельцу прогона
 *   POST /meal/api/photo_file     кадр воркеру (X-Worker-Token)
 *   POST /meal/api/worker_result  приёмка результата (X-Worker-Token)
 */
class Controller_Nj651_Api extends Controller_Vision_Base
{
    protected function _vision()
    {
        return [
            'module'       => 'nj651_meal',
            'table'        => 'nj_651_runs',
            'channel'      => 'nj651.meal',
            'title'        => 'Тарелка',
            // Создание прогона — только для зарегистрированных (решение
            // владельца 06.10.2026, «как опросы»). Гейт — в Controller_Vision_Base.
            'auth'         => TRUE,
            'photo_mb'     => (int) (Kohana::$config->load('nj651_meal.limits.photo_mb') ?: 8),
            'per_hour'     => (int) (Kohana::$config->load('nj651_meal.limits.per_hour') ?: 15),
            'timeout_sec'  => 300,
            'shape'        => ['Service_Nj651_Meal', 'shape'],
            'preview_save' => ['Service_Nj651_Meal', 'preview_save'],
        ];
    }

    /** Приватная миниатюра: выдаётся только владельцу прогона. */
    public function action_preview()
    {
        $id  = (int) ($this->_body['id'] ?? 0);
        $run = $id > 0 ? Vision::run_get('nj_651_runs', $id) : NULL;

        if ( ! $run OR ! Vision::run_owned($run, $this->_visitor(), $this->_user_id()))
        {
            return $this->_fail('Разбор не найден', 404);
        }

        $bytes = Service_Nj651_Meal::preview_bytes($id);

        if ($bytes === '')
        {
            return $this->_fail('Миниатюра не найдена', 404);
        }

        $this->response->status(200)
            ->headers('Content-Type', 'image/jpeg')
            ->headers('Cache-Control', 'private, no-store, max-age=0')
            ->headers('X-Content-Type-Options', 'nosniff')
            ->body($bytes);

        return $this->response;
    }
}
