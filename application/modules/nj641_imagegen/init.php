<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj641_imagegen: «Генератор изображений».
 *
 *   /imagegen                          — страница сервиса;
 *   /imagegen/gallery                  — «Изображения»: модерация и библиотека (админ);
 *   /imagegen/api/run                  — поставить генерацию в очередь (nj641.generate);
 *   /imagegen/api/result/<id>          — статус/результат прогона (картинка base64);
 *   /imagegen/api/status/<id>          — лёгкий статус-ряд прогона (этап, таймер);
 *   /imagegen/api/cancel/<id>          — остановить свой прогон;
 *   /imagegen/api/delete/<id>          — удалить свой прогон (строка + файл);
 *   /imagegen/file/<имя>               — картинка прогона (файл из storage/nj641);
 *   /imagegen/api/runs                 — последние прогоны;
 *   /imagegen/api/vram                 — видеопамять бекенда (инфо, прокси);
 *   /imagegen/api/clear                — очистка кэша бекенда (X-Worker-Token);
 *   /imagegen/api/worker-result        — приём результата от воркера (X-Worker-Token).
 */

Route::set('nj641_page', 'imagegen')
    ->defaults([
        'controller' => 'Nj641_Imagegen',
        'action'     => 'index',
    ]);

// Страница «Изображения»: модерация и публикация в библиотеку (только админ).
Route::set('nj641_gallery', 'imagegen/gallery')
    ->defaults([
        'controller' => 'Nj641_Imagegen',
        'action'     => 'gallery',
    ]);

/**
 * Дефис в адресе = подчёркивание в имени действия.
 *
 * Воркер и страница зовут /imagegen/api/worker-result, /worker-upload и
 * /review-many, а методы контроллера — action_worker_result, action_worker_upload,
 * action_review_many. Kohana ищет ровно action_worker-result, не находит и
 * отдаёт 404: приём картинки от воркера и пакетная модерация молча не
 * работали. Та же причина и то же лечение, что в nj639_qc (init.php).
 */
Route::set('nj641_api', 'imagegen/api(/<action>(/<id>))')
    ->filter(function ($route, $params, $request)
    {
        if ( ! empty($params['action']))
        {
            $params['action'] = str_replace('-', '_', (string) $params['action']);
        }

        return $params;
    })
    ->defaults([
        'controller' => 'Nj641_Api',
        'action'     => 'index',
        'id'         => NULL,
    ]);

// Картинка прогона отдаётся файлом: имя уникально и одноразово (капабилити).
Route::set('nj641_file', 'imagegen/file/<id>', ['id' => '[A-Za-z0-9._-]+'])
    ->defaults([
        'controller' => 'Nj641_Api',
        'action'     => 'file',
        'id'         => NULL,
    ]);
