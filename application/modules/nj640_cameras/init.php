<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj640_cameras: «Камеры цеха» — VLM-контроль дисциплины/СИЗ/загрузки.
 *
 *   /cameras                    — страница сервиса (сценарии, камеры, журнал);
 *   /cameras/api/run            — ручная проверка кадра по сценарию;
 *   /cameras/api/...            — сценарии, камеры, журнал, подтверждения;
 *   /cameras/api/worker-result  — приём результата от воркера (X-Worker-Token);
 *   /cameras/webhook/<token>    — внешний webhook-триггер камеры (датчик/ПЛК).
 */

Route::set('nj640_page', 'cameras')
    ->defaults([
        'controller' => 'Nj640_Cameras',
        'action'     => 'index',
    ]);

/**
 * Дефис в адресе = подчёркивание в имени действия.
 *
 * Страница зовёт /cameras/api/camera-fire, метод контроллера называется
 * action_camera_fire. Kohana ищет ровно action_camera-fire, не находит и
 * отдаёт 404 — кнопка «Тест» у камеры молча не срабатывала. То же лечение,
 * что в nj639_qc и nj641_imagegen (init.php).
 */
Route::set('nj640_api', 'cameras/api(/<action>(/<id>))')
    ->filter(function ($route, $params, $request)
    {
        if ( ! empty($params['action']))
        {
            $params['action'] = str_replace('-', '_', (string) $params['action']);
        }

        return $params;
    })
    ->defaults([
        'controller' => 'Nj640_Api',
        'action'     => 'index',
        'id'         => NULL,
    ]);

Route::set('nj640_webhook', 'cameras/webhook/<token>')
    ->defaults([
        'controller' => 'Nj640_Webhook',
        'action'     => 'fire',
        'token'      => NULL,
    ]);
