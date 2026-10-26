<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj639_qc: «Контроль качества» — VLM-сервис проверки изделий.
 *
 *   /qc                    — страница сервиса (ручной режим, источники, история);
 *   /qc/api/run            — ручной прогон (фото + эталон + правило + схема);
 *   /qc/api/result/<id>    — статус/результат прогона (lazy reconcile по nj120);
 *   /qc/api/source         — создание/правка источника кадров (авторежим);
 *   /qc/api/worker-result  — приём результата от воркера (X-Worker-Token);
 *   /qc/webhook/<token>    — внешний webhook-триггер (аппаратный датчик/ПЛК).
 */

Route::set('nj639_page', 'qc')
    ->defaults([
        'controller' => 'Nj639_Qc',
        'action'     => 'index',
    ]);

/**
 * Дефис в адресе = подчёркивание в имени действия.
 *
 * Страница и документация зовут /qc/api/source-fire и /qc/api/worker-result,
 * а методы контроллера называются action_source_fire / action_worker_result.
 * Kohana ищет ровно action_source-fire, не находит и отдаёт 404 — кнопка
 * «Тест» у источника молча не работала, вердикт воркера не доезжал. Чиним
 * в маршруте, чтобы обе формы адреса вели в один метод.
 */
Route::set('nj639_api', 'qc/api(/<action>(/<id>))')
    ->filter(function ($route, $params, $request)
    {
        if ( ! empty($params['action']))
        {
            $params['action'] = str_replace('-', '_', (string) $params['action']);
        }

        return $params;
    })
    ->defaults([
        'controller' => 'Nj639_Api',
        'action'     => 'index',
        'id'         => NULL,
    ]);

Route::set('nj639_webhook', 'qc/webhook/<token>')
    ->defaults([
        'controller' => 'Nj639_Webhook',
        'action'     => 'fire',
        'token'      => NULL,
    ]);
