<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты модуля nj653_veclab («Лаборатория векторов»).
 * Собираются хабом nj160_mcp и отдаются агентам по /mcp/api/rpc.
 *
 * Описания написаны для слабых моделей: коротко, по-русски, с примером и с
 * прямым указанием, что делать дальше (счёт вектора асинхронный, как в
 * nj550_jobs: сначала run_id, потом результат).
 */

return [
    'tools' => [

        [
            'name'        => 'nj653_status',
            'category'    => 'nj653_veclab',
            'description' => 'Состояние сервиса эмбеддингов N2J.ru: какая модель считает векторы, какая у них размерность, сколько элементов в коллекции и прошла ли модель последнюю проверку. Вызывай ПЕРЕД остальными nj653_*, чтобы понять, жив ли сервис.',
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'handler'     => 'Service_Nj653_Mcp::status',
        ],

        [
            'name'        => 'nj653_collection',
            'category'    => 'nj653_veclab',
            'description' => 'Что лежит в векторной коллекции агентов: подписи, вид элемента (текст или картинка), когда добавлен. По этой коллекции работает nj653_search.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'description' => 'сколько вернуть (по умолчанию 20)'],
                ],
            ],
            'handler'     => 'Service_Nj653_Mcp::collection',
        ],

        [
            'name'        => 'nj653_remember',
            'category'    => 'nj653_veclab',
            'description' => 'Положить текст в векторную коллекцию, чтобы потом искать его по смыслу, а не по словам. Пример: {"text": "рыжий кот спит на подоконнике", "label": "кот"}. Вернёт run_id; через 3-5 секунд позови nj653_result. Одинаковый текст второй раз не добавляется.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'text'  => ['type' => 'string', 'description' => 'что запомнить (до 2000 символов)'],
                    'label' => ['type' => 'string', 'description' => 'короткая подпись для списка (необязательно)'],
                ],
                'required' => ['text'],
            ],
            'handler'     => 'Service_Nj653_Mcp::remember',
        ],

        [
            'name'        => 'nj653_search',
            'category'    => 'nj653_veclab',
            'description' => 'Поиск по смыслу в векторной коллекции агентов: запрос обычными словами, ответ — ближайшие элементы с косинусной близостью. Пример: {"query": "домашнее животное", "top": 5}. Вернёт run_id; через 3-5 секунд позови nj653_result.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'что искать, обычными словами'],
                    'top'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'сколько находок вернуть (по умолчанию 6)'],
                ],
                'required' => ['query'],
            ],
            'handler'     => 'Service_Nj653_Mcp::search',
        ],

        [
            'name'        => 'nj653_compare',
            'category'    => 'nj653_veclab',
            'description' => 'Насколько два текста про одно и то же: косинусная близость от -1 до 1 плюс оценка словами. В коллекцию ничего не попадает. Пример: {"first": "счёт-фактура за квартал", "second": "налоговая декларация"}. Вернёт run_id; через 3-5 секунд позови nj653_result.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'first'  => ['type' => 'string', 'description' => 'первый текст'],
                    'second' => ['type' => 'string', 'description' => 'второй текст'],
                ],
                'required' => ['first', 'second'],
            ],
            'handler'     => 'Service_Nj653_Mcp::compare',
        ],

        [
            'name'        => 'nj653_result',
            'category'    => 'nj653_veclab',
            'description' => 'Результат прогона nj653 по run_id: находки поиска, близость пары или итог проверки модели. Если ответ говорит «ещё считается» — подожди 3-5 секунд и позови снова.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'run_id' => ['type' => 'integer', 'description' => 'номер прогона из ответа nj653_search / nj653_remember / nj653_compare'],
                ],
                'required' => ['run_id'],
            ],
            'handler'     => 'Service_Nj653_Mcp::result',
        ],

        [
            'name'        => 'nj653_probe',
            'category'    => 'nj653_veclab',
            'access'      => 'admin', // проба дёргает модель вхолостую — не для чужих ключей
            'description' => 'Проверить мультимодальную эмбеддинг-модель: какой формат запроса она понимает, какая размерность вектора, различает ли смысл и связывает ли картинку с текстом. Вернёт run_id; результат через 10-20 секунд по nj653_result.',
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'handler'     => 'Service_Nj653_Mcp::probe',
        ],

    ],
];
