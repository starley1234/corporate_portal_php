<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Meta-MCP Gateway (nj676_mcphub).
 *
 * Агент подключает ОДИН эндпоинт `POST /mcphub/mcp` (Streamable HTTP) и видит
 * 7 корневых инструментов (`ROOT_TOOLS`) вместо сотен чужих схем:
 *
 *   hub_search_tools(query, filters?) — что вообще есть: сервисы N2J и серверы
 *                                       из каталога MCP, по смыслу задачи;
 *   hub_ready(category?, limit?)      — что готово к вызову прямо сейчас;
 *   hub_describe_tool(tool, server?)  — полная схема и пример вызова;
 *   hub_call_tool(...)                — вызов: инструмент N2J напрямую или
 *                                       проксирование в удалённый MCP-сервер;
 *   hub_feedback(call_id, rating, …)  — отзыв по call_id для рейтинга Wilson;
 *   hub_status()                      — что доступно именно мне, лимиты, профиль;
 *   hub_configure(...)                — «ищи только без ключей», категории,
 *                                       максимум задержки; запоминается за ключом.
 *
 * Почему так: десятки схем инструментов съедают 30–70 % контекстного окна и
 * ухудшают выбор. Здесь схема одного инструмента подгружается по требованию,
 * а не все сразу.
 *
 * Безопасность:
 *   * токены — те же, что у /mcp (персональные nj_160_tokens или MCP_TOKEN);
 *   * проксируются только HTTPS-адреса из опубликованных карточек каталога,
 *     после проверки DNS (никаких приватных и loopback-адресов);
 *   * редиректы не выполняются, ответ ограничен по размеру и времени;
 *   * локальные stdio-серверы НЕ запускаются на сервере — агент получает
 *     готовую конфигурацию для клиента.
 */
class Service_Nj676_Gateway
{
    /** Лимиты проксирования: секунд, байт, вызовов за прогон. */
    const PROXY_TIMEOUT = 20;
    const PROXY_MAX_BYTES = 524288;
    /** Сколько секунд доверять списку инструментов из карточки. */
    const TOOLS_TTL = 21600;
    const MAX_SEARCH_RESULTS = 8;

    /**
     * Абсолютные пороги допуска в выдачу при непустом запросе.
     *
     * Смысл: «нет совпадения — нет строки». Лексическое совпадение считается
     * доказанным с 0.33 (2 очка из 6: слово в описании). У смысла свой,
     * более строгий фильтр — три отсечки в `Semantic::select()` (порог,
     * уверенность, хвост): сюда попадают только те, кого он пропустил, поэтому
     * здесь достаточно нижней границы шкалы (0.2).
     *
     * История. Сначала порога не было вовсе, и в топ попадал готовый сервер,
     * ничем не связанный с задачей. Потом поставили один общий порог 0.12 —
     * и вместе с включённым векторным поиском он пропускал всё подряд:
     * «zzzqqq gibberish» давал 40 результатов, потому что `Semantic::relevance()`
     * никогда не опускается ниже 0.2 для дистанции ближе 0.75. Один порог не
     * может обслуживать две разные шкалы — поэтому их два.
     */
    const LEXICAL_FLOOR = 0.33;
    const SEMANTIC_FLOOR = 0.2;

    /**
     * Относительная отсечка хвоста: строка остаётся, если её итоговый скор не
     * меньше 60% от лучшего результата этой же выдачи.
     *
     * Зачем. Абсолютный порог отвечает на вопрос «это вообще про задачу?»,
     * относительный — «это настолько же про задачу, как лучшее?». Без второго
     * «распознать речь» отдавало 40 серверов, где на первом месте стоял
     * temporal-mcp: формально всё «похоже», практически — шум. Агенту нужен
     * один-два настоящих ответа, а не список всего, что прошло порог.
     */
    const SCORE_CUTOFF = 0.8;

    /** Корневые инструменты шлюза — единственное, что видит агент. */
    const ROOT_TOOLS = ['hub_search_tools', 'hub_ready', 'hub_describe_tool', 'hub_call_tool',
        'hub_feedback', 'hub_status', 'hub_configure'];
    /** Стабильные коды ошибок: агент ветвится по ним, а не по тексту. */
    const ERROR_CODES = ['AUTH_REQUIRED', 'TOOL_NOT_FOUND', 'INVALID_ARGUMENT', 'UPSTREAM_TIMEOUT',
        'UPSTREAM_ERROR', 'RATE_LIMITED', 'SCHEMA_INVALID'];

    /** Корневые инструменты — единственное, что видит агент в tools/list. */
    public static function tool_list()
    {
        // Схемы прогоняем через McpHub::schemas_as_objects(): `properties => []`
        // (пустой PHP-массив) в JSON становится массивом, а спецификация
        // требует объект. OpenCode v2.1.4 из-за одного такого поля отклоняет
        // весь tools/list, и агент не видит ни одного инструмента.
        $tools = [
            [
                'name' => 'hub_search_tools',
                'description' => 'Найти инструменты под задачу: сервисы платформы N2J и серверы из каталога MCP. '
                    .'Ищи словами задачи («погода в Дубне», «прочитать PDF», «проверить компанию по ИНН»). '
                    .'Поиск понимает смысл: каталог индексирован векторами, поэтому неологизмы и синонимы '
                    .'тоже находят нужное. Порог отсекает случайные совпадения, а хвост режется по '
                    .'относительному скору — нет подходящего, так и скажем. '
                    .'В ответе — готовность к вызову (ready / needs_key / needs_install / stale / broken), '
                    .'сигнатуры аргументов и готовая строка «next» — что вызвать дальше. '
                    .'Фильтр only_ready=true оставляет только то, что можно вызвать прямо сейчас.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Что нужно сделать, обычными словами. Можно опустить — тогда вернётся всё готовое к вызову'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_SEARCH_RESULTS, 'description' => 'Сколько серверов (1–8, по умолчанию 5)'],
                        'only_ready' => ['type' => 'boolean', 'description' => 'Только готовые к вызову сейчас: отвечали недавно, установка не нужна, ключ (если требуется) уже настроен на стороне шлюза'],
                        'auth' => ['type' => 'string', 'enum' => ['none', 'optional', 'api_key', 'oauth', 'unknown'], 'description' => 'Фильтр по авторизации'],
                        'install' => ['type' => 'string', 'enum' => ['none', 'npx', 'uvx', 'manual'], 'description' => 'Фильтр по способу установки (none — вызов по ссылке)'],
                        'readiness' => ['type' => 'string', 'enum' => ['ready', 'needs_key', 'needs_install', 'degraded', 'stale', 'broken'], 'description' => 'Фильтр готовности'],
                        'max_latency_ms' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 600000, 'description' => 'Максимальная проверенная задержка, мс'],
                        'transport' => ['type' => 'string', 'enum' => ['stdio', 'sse', 'streamable_http'], 'description' => 'Транспорт'],
                        'explain' => ['type' => 'boolean', 'description' => 'true — почему сервер оказался в выдаче'],
                        'only_gems' => ['type' => 'boolean', 'description' => 'true — только «алмазы» каталога, исследованные полевым ИИ-агентом (высокая польза и безопасность)'],
                        'cursor' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 200, 'description' => 'Смещение для следующей страницы (из next_cursor прошлого ответа)'],
                        'sort' => ['type' => 'string', 'enum' => ['relevance', 'latency', 'reliability', 'trust', 'utility', 'agent_rating'], 'description' => 'Порядок: по совпадению, быстрые, надёжные / по доверию, по пользе (utility — алмазы), по рейтингу агентов (agent_rating)'],
                        'ignore' => [
                            'type' => 'array', 'items' => ['type' => 'string'],
                            'description' => 'Slug-и серверов, которые уже падали у вас: исключить из выдачи',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'hub_ready',
                'description' => 'Что можно вызвать прямо сейчас: серверы, которые недавно ответили или успешно '
                    .'сработали, без установки на вашей машине (ключи, которые есть у шлюза, уже учтены), '
                    .'их инструменты и готовые примеры аргументов. Вызывай в начале работы, если нужен '
                    .'не поиск по теме, а «что вообще доступно» — query не нужен. '
                    .'То же, что hub_search_tools с only_ready=true, но без обязательного запроса.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_SEARCH_RESULTS, 'description' => 'Сколько серверов (1–8, по умолчанию 8)'],
                        'only_ready' => ['type' => 'boolean', 'description' => 'true — только готовые к вызову сейчас (по умолчанию); false — показать и остальные серверы каталога'],
                        'only_gems' => ['type' => 'boolean', 'description' => 'true — только «алмазы» каталога, исследованные полевым ИИ-агентом'],
                        'auth' => ['type' => 'string', 'enum' => ['none', 'optional', 'api_key', 'oauth', 'unknown'], 'description' => 'Фильтр по авторизации'],
                        'transport' => ['type' => 'string', 'enum' => ['stdio', 'sse', 'streamable_http'], 'description' => 'Транспорт'],
                        'max_latency_ms' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 600000, 'description' => 'Максимальная проверенная задержка, мс'],
                        'explain' => ['type' => 'boolean', 'description' => 'true — включить разбор готовности и доверия в поле why (по умолчанию включено)'],
                        'cursor' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 200, 'description' => 'Смещение для следующей страницы (из next_cursor прошлого ответа)'],
                        'sort' => ['type' => 'string', 'enum' => ['relevance', 'latency', 'reliability', 'trust', 'utility', 'agent_rating'], 'description' => 'Порядок выдачи'],
                        'ignore' => [
                            'type' => 'array', 'items' => ['type' => 'string'],
                            'description' => 'Slug-и серверов, которые уже падали у вас',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'hub_describe_tool',
                'description' => 'Полная JSON Schema инструмента и готовый пример вызова. '
                    .'Бери сюда, когда hub_search_tools показал нужный инструмент, но аргументы неочевидны. '
                    .'Можно без server, если имя инструмента уникально в каталоге.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'server' => ['type' => 'string', 'description' => 'Slug сервера из hub_search_tools'],
                        'tool' => ['type' => 'string', 'description' => 'Имя инструмента'],
                    ],
                    'required' => ['tool'],
                ],
            ],
            [
                'name' => 'hub_call_tool',
                'description' => 'Вызвать найденный инструмент. Сервис N2J: {"tool":"имя","arguments":{…}}. '
                    .'Сервер каталога: {"server":"slug","tool":"имя","arguments":{…}}; если у сервера один инструмент, '
                    .'tool можно не передавать. Ответ содержит call_id — по нему оставьте отзыв через hub_feedback. '
                    .'Перед пишущим или платным вызовом добавьте dry_run:true — вернётся план без исполнения. '
                    .'Ошибки приходят с кодом (INVALID_ARGUMENT, AUTH_REQUIRED, UPSTREAM_TIMEOUT…) и подсказкой.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tool' => ['type' => 'string', 'description' => 'Имя инструмента'],
                        'server' => ['type' => 'string', 'description' => 'Slug MCP-сервера из каталога'],
                        'arguments' => ['type' => 'object', 'description' => 'Аргументы вызова'],
                        'dry_run' => ['type' => 'boolean', 'description' => 'true — проверить вызов без исполнения: что будет вызвано, готов ли сервер, меняет ли инструмент данные'],
                    ],
                ],
            ],
            [
                'name' => 'hub_feedback',
                'description' => 'Два режима: (1) Запись отзыва после реального вызова — {"call_id":"call_…","rating":"up"|"down"} '
                    .'(call_id приходит в ответе hub_call_tool; без call_id запись отклоняется). '
                    .'(2) Чтение отзывов и индекса доверия по серверу — {"server":"<slug>","limit":10} (без rating и call_id).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'call_id' => ['type' => 'string', 'description' => 'call_id из ответа hub_call_tool (обязателен для записи отзыва)'],
                        'rating' => ['type' => 'string', 'description' => 'up | down | 1..5 (только для записи отзыва вместе с call_id)'],
                        'outcome' => ['type' => 'string', 'enum' => ['success', 'wrong_data', 'slow', 'auth_error', 'crash'], 'description' => 'Что произошло'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Короткие метки: wrong_city, stale'],
                        'comment' => ['type' => 'string', 'description' => 'Что было не так, своими словами'],
                        'server' => ['type' => 'string', 'description' => 'Slug MCP-сервера для чтения отзывов агентов (режим чтения, без call_id и без rating)'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Сколько последних отзывов вернуть в режиме чтения (1–50, по умолчанию 10)'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'hub_status',
                'description' => 'Что доступно этому ключу: что готово к вызову сейчас (серверы и инструменты), '
                    .'что откроется после ключа, активные фильтры, состояние смыслового поиска и примеры вызовов. '
                    .'Вызывай в начале работы.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name' => 'hub_configure',
                'description' => 'Настроить выборку под задачу. Например, у агента нет ключей: '
                    .'{"no_auth_only":true}. Ограничить темы: {"categories":["development","web"]}. '
                    .'Сбросить: {"reset":true}. Настройка запоминается за вашим ключом и действует на ваши запросы.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'no_auth_only' => ['type' => 'boolean', 'description' => 'Только серверы без ключей и OAuth'],
                        'no_install' => ['type' => 'boolean', 'description' => 'Только серверы без установки (вызов по ссылке) — если у агента нет прав ставить пакеты'],
                        'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Категории каталога MCP'],
                        'transport' => ['type' => 'string', 'enum' => ['stdio', 'sse', 'streamable_http'], 'description' => 'Какой транспорт нужен'],
                        'latency_max' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 600000, 'description' => 'Максимальная задержка проверенного сервера, мс'],
                        'ignore' => [
                            'type' => 'array', 'items' => ['type' => 'string'],
                            'description' => 'Slug-и серверов, которые у вас не работают: скрывать их в выдаче (пустой массив — очистить)',
                        ],
                        'reset' => ['type' => 'boolean', 'description' => 'true — снять все фильтры'],
                    ],
                ],
            ],
        ];

        // additionalProperties: false — не формальность. С `nonsense_arg` клиент
        // раньше получал молчаливый успех и думал, что фильтр применён. Теперь
        // сервер сам проверяет аргументы (validate_arguments) и отвечает
        // INVALID_ARGUMENT с полем field и списком допустимых.
        foreach ($tools as & $tool)
        {
            if (isset($tool['inputSchema']['type']) AND $tool['inputSchema']['type'] === 'object')
            {
                $tool['inputSchema']['additionalProperties'] = FALSE;
            }
        }
        unset($tool);

        return Service_Nj676_McpHub::schemas_as_objects($tools);
    }

    /**
     * Проверить аргументы вызова по схеме инструмента.
     *
     * Что проверяем и почему именно так:
     *   * лишние поля — ошибка. Схема объявляет `additionalProperties: false`,
     *     значит клиент обязан их отфильтровать; если он этого не сделал,
     *     «молча проигнорировать» — худший ответ: агент думает, что фильтр
     *     `nonsense_arg` сработал (тест 24.10.2026);
     *   * тип и enum — ошибка: строка `limit:"10"` и `sort:"похожие"` ведут
     *     себя не так, как ждёт агент, и лучше сказать это сразу;
     *   * число вне диапазона — ОШИБКА внятная и с границами (`limit: 9` при
     *     схеме 1…8 → `INVALID_ARGUMENT`). Тихую подрезку агент читает как
     *     «схема разрешает 9» и продолжает присылать 9; замечание 24.10.2026.
     *
     * @return array{errors:array<int,array<string,string>>,arguments:array<string,mixed>,
     *               allowed:array<int,string>,hint:string}
     */
    public static function validate_arguments($tool_name, array $args)
    {
        $schema = self::tool_schema($tool_name);
        $out = [
            'errors' => [], 'arguments' => $args,
            'allowed' => [], 'hint' => '',
        ];

        // Инструмента нет в схеме — судить не о чем: ответит сам вызов.
        if ($schema === NULL)
        {
            return $out;
        }

        $props = (array) ($schema['properties'] ?? []);
        $out['allowed'] = array_keys($props);
        $out['hint'] = $props === []
            ? 'У инструмента «'.$tool_name.'» нет аргументов: вызовите его с пустым объектом {}.'
            : 'Допустимые аргументы: '.implode(', ', $out['allowed']).'. Полная схема — в tools/list.';

        // 1. Лишние поля: схема корневых инструментов запрещает их явно.
        $allow_extra = (array_key_exists('additionalProperties', $schema)
            && $schema['additionalProperties'] !== FALSE);
        $unknown = array_values(array_diff(array_keys($args), $out['allowed']));

        if ( ! $allow_extra && $unknown !== [])
        {
            $out['errors'][] = [
                'field' => implode(', ', $unknown),
                'message' => 'Неизвестные аргументы: '.implode(', ', $unknown)
                    .'. Инструмент «'.$tool_name.'» их не принимает'
                    .($out['allowed'] === [] ? ' (у него нет аргументов).' : '.'),
                'expected' => $out['allowed'] === [] ? '{}' : implode(', ', $out['allowed']),
            ];
        }

        // 2. Типы, enum и диапазоны — по объявленным свойствам.
        foreach ($props as $name => $rule)
        {
            if ( ! array_key_exists($name, $args))
            {
                continue;
            }

            $value = $args[$name];

            if ($value === NULL)
            {
                // null там, где значение необязательно, — это «не задано».
                unset($out['arguments'][$name]);
                continue;
            }

            $rule = (array) $rule;
            $type = (string) ($rule['type'] ?? '');

            if ($type === 'string' && ! is_string($value))
            {
                $out['errors'][] = self::_arg_error($name, $value, 'строка', $rule);
                continue;
            }

            if ($type === 'boolean' && ! is_bool($value))
            {
                $out['errors'][] = self::_arg_error($name, $value, 'true или false', $rule);
                continue;
            }

            if ($type === 'array' && ! is_array($value))
            {
                $out['errors'][] = self::_arg_error($name, $value, 'массив', $rule);
                continue;
            }

            if ($type === 'object' && ! is_array($value))
            {
                $out['errors'][] = self::_arg_error($name, $value, 'объект', $rule);
                continue;
            }

            if (in_array($type, ['integer', 'number'], TRUE))
            {
                if ( ! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value)))
                {
                    $out['errors'][] = self::_arg_error($name, $value, 'число', $rule);
                    continue;
                }

                $number = $type === 'integer' ? (int) $value : (float) $value;
                $min = isset($rule['minimum']) ? (float) $rule['minimum'] : NULL;
                $max = isset($rule['maximum']) ? (float) $rule['maximum'] : NULL;

                // Число вне диапазона схемы — ОШИБКА, а не тихая подрезка.
                // Раньше `limit: 9` молча становился 8, `limit: 0` — 1, и агент
                // считал, что схема разрешает 9 (замечание 24.10.2026). Схема —
                // контракт: не сошлось — скажем прямо и повторим с верным числом.
                $range = ($min !== NULL ? self::_num($min) : '−∞').'…'.($max !== NULL ? self::_num($max) : '∞');

                if (($min !== NULL AND $number < $min) OR ($max !== NULL AND $number > $max))
                {
                    $out['errors'][] = [
                        'field' => $name,
                        'message' => 'Аргумент «'.$name.'» = '.self::_num($number)
                            .' вне диапазона схемы ('.$range.').',
                        'expected' => $range,
                        'hint' => 'Допустимый диапазон для «'.$name.'»: '.$range.'.',
                    ];

                    continue;
                }

                $out['arguments'][$name] = $type === 'integer' ? (int) $number : $number;
                continue;
            }

            if (isset($rule['enum']) && is_array($rule['enum'])
                && ! in_array($value, $rule['enum'], TRUE))
            {
                $allowed_enum = implode(' | ', array_map('strval', $rule['enum']));
                $out['errors'][] = [
                    'field' => $name,
                    'message' => 'Аргумент «'.$name.'» = '.self::_arg_brief($value)
                        .' не входит в допустимые значения ('.$allowed_enum.').',
                    'expected' => $allowed_enum,
                    'hint' => 'Допустимые значения для «'.$name.'»: '.$allowed_enum.'.',
                ];
            }
        }

        return $out;
    }

    /** Ошибка одного аргумента: одна формулировка на все типы. */
    protected static function _arg_error($name, $value, $expected, array $rule)
    {
        return [
            'field' => (string) $name,
            'message' => 'Аргумент «'.$name.'» должен быть '.$expected.', а пришло '.self::_arg_brief($value).'.',
            'expected' => $expected.($rule['description'] ?? '' ? ' — '.$rule['description'] : ''),
            'hint' => 'Аргумент «'.$name.'» ожидает '.$expected
                .(isset($rule['enum']) && is_array($rule['enum']) ? ' ('.implode(' | ', array_map('strval', $rule['enum'])).')' : '').'.',
        ];
    }

    /** Число без хвостовых нулей: «8», а не «8.0» — для текстов ошибок. */
    protected static function _num($value)
    {
        $value = (float) $value;

        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    /** Короткое представление значения для сообщения об ошибке. */
    protected static function _arg_brief($value)
    {
        if (is_bool($value))
        {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value))
        {
            $text = (string) $value;

            return mb_strlen($text) > 40 ? mb_substr($text, 0, 40).'…' : $text;
        }

        return gettype($value);
    }

    /**
     * Схема аргументов корневого инструмента шлюза (NULL — такого нет).
     *
     * Одна точка правды: и проверка лишних аргументов, и подсказка «допустимые»
     * берут список свойств отсюда, а не ищут инструмент в `tool_list()` заново.
     */
    public static function tool_schema($tool_name)
    {
        foreach (self::tool_list() as $tool)
        {
            if ((string) $tool['name'] === (string) $tool_name)
            {
                return (array) ($tool['inputSchema'] ?? []);
            }
        }

        return NULL;
    }

    /**
     * Выполнить метод JSON-RPC шлюза.
     *
     * @param  string     $method
     * @param  array      $params
     * @param  array      $ctx    ['is_admin' => bool, 'user_id' => int|NULL, 'token_hash' => string|NULL]
     * @return array      результат JSON-RPC (result)
     * @throws Kohana_Exception
     */
    public static function call($method, array $params, array $ctx)
    {
        // Схема — не декорация в tools/list. Проверяем аргументы ДО работы:
        // лишнее поле и неверный тип раньше проглатывались, и агент считал,
        // что фильтр применён (nonsense_arg «работал»). Число вне диапазона —
        // тоже отказ, с границами схемы в `expected`.
        $checked = self::validate_arguments($method, $params);

        if ($checked['errors'] !== [])
        {
            $first = $checked['errors'][0];

            return self::error($first['message'], 'INVALID_ARGUMENT', [
                'field' => $first['field'],
                'expected' => $first['expected'] ?? '',
                'hint' => ! empty($first['hint']) ? $first['hint'] : $checked['hint'],
                'invalid_arguments' => $checked['errors'],
                'allowed_arguments' => $checked['allowed'],
            ]);
        }

        return self::dispatch($method, $checked['arguments'], $ctx);
    }

    /** Разложить вызов по инструментам (без проверки аргументов). */
    protected static function dispatch($method, array $params, array $ctx)
    {
        switch ($method)
        {
            case 'hub_search_tools':
                $result = self::search_tools($params, $ctx);
                break;
            case 'hub_ready':
                // Тот же движок, но без обязательного запроса и только готовое.
                $result = self::ready_tools($params, $ctx);
                break;
            case 'hub_describe_tool':
                $result = self::describe_tool($params, $ctx);
                break;
            case 'hub_call_tool':
                $result = self::call_tool($params, $ctx);
                break;
            case 'hub_feedback':
                $result = self::feedback_tool($params, $ctx);
                break;
            case 'hub_status':
                $result = self::status($ctx);
                break;
            case 'hub_configure':
                $result = self::configure($params, $ctx);
                break;
            default:
                throw new Kohana_Exception('Неизвестный инструмент шлюза: :m', [':m' => $method]);
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // hub_search_tools
    // ---------------------------------------------------------------------

    public static function search_tools(array $args, array $ctx)
    {
        $query = mb_substr(trim(self::_string($args['query'] ?? '')), 0, 200);
        $explicit_only_ready = array_key_exists('only_ready', $args);
        $ready_only = ! empty($args['only_ready']);

        $requested_limit = (int) ($args['limit'] ?? 5);
        $limit = max(1, min(self::MAX_SEARCH_RESULTS, $requested_limit));

        // Молчаливое усечение вводит в заблуждение: агент просит 999, получает
        // 8 и думает, что в каталоге всего 8. Говорим об ограничении прямо.
        $limit_note = $requested_limit > self::MAX_SEARCH_RESULTS
            ? 'limit ограничен '.self::MAX_SEARCH_RESULTS.' (запрошено '.$requested_limit
                .'): остальное — по cursor.'
            : '';
        // Курсор — простое смещение по уже отранжированной выдаче: агент
        // листает дальше, не повторяя запрос с другими словами.
        $cursor = max(0, min(200, (int) ($args['cursor'] ?? 0)));
        $filters = self::search_filters($args, $ctx);

        // Явные фильтры проверяем ДО того, как only_ready подставит readiness=ready
        // и install=none: иначе hub_ready (который передаёт only_ready=true)
        // ошибочно считал это «явным фильтром» и возвращал размер пула (60)
        // вместо полного числа готовых серверов (296).
        $raw_readiness = (string) ($args['readiness'] ?? '');
        $raw_auth = (string) ($args['auth'] ?? '');
        $raw_install = (string) ($args['install'] ?? '');
        $raw_transport = (string) ($args['transport'] ?? '');
        $raw_latency = (int) ($args['max_latency_ms'] ?? 0);
        $explicit_filters = ($raw_readiness !== ''
            OR $raw_auth !== ''
            OR $raw_install !== ''
            OR $raw_transport !== ''
            OR $raw_latency > 0
            OR ! empty($profile['no_auth_only'])
            OR ! empty($profile['no_install'])
            OR ! empty($profile['transport'])
            OR (int) ($profile['latency_max'] ?? 0) > 0);

        // Пустой запрос — законный режим «покажи, что готово прямо сейчас»
        // (hub_ready). Реальный тест 24.10.2026: чтобы узнать доступное, агенту
        // приходилось придумывать тему и получать нерелевантную выдачу.
        // Явные фильтры и явный only_ready=false уважаем.
        if ($query === '' AND ! $ready_only AND ! $explicit_filters AND ! $explicit_only_ready)
        {
            $ready_only = TRUE;
        }

        if ($ready_only)
        {
            $filters['only_ready'] = TRUE;
            $filters['readiness'] = 'ready';
            $filters['install'] = 'none';
        }

        $sort = (string) self::_string($args['sort'] ?? '');
        $sort = in_array($sort, ['relevance', 'latency', 'reliability', 'trust', 'utility', 'agent_rating'], TRUE) ? $sort : '';
        $only_gems = ! empty($args['only_gems']);
        $ignore = self::slug_list($args['ignore'] ?? []);
        $profile = self::profile($ctx);

        // К явному ignore добавляем «у меня уже падало» (журнал вызовов этого
        // ключа) и сохранённый профиль агента. Ручной список вести не нужно.
        $ignore = array_values(array_unique(array_merge(
            $ignore,
            self::slug_list($profile['ignore'] ?? []),
            Service_Nj676_Telemetry::failing_servers((string) ($ctx['token_hash'] ?? ''))
        )));

        // 1. Инструменты платформы N2J. У них нет транспорта и установки:
        // либо ключ есть (ready), либо нужен (needs_key). Раньше `only_ready`
        // всё равно показывал их, и агент тратил вызов на заведомый отказ.
        $platform = [];
        if (class_exists('Service_Nj160_Registry'))
        {

            foreach (Service_Nj160_Registry::tools_for((bool) $ctx['is_admin']) as $name => $tool)
            {
                $needs_key = ! empty($tool['needs_key']);
                $item = [
                    'tool' => (string) $name,
                    'auth' => $needs_key ? 'api_key' : 'none',
                    'install' => 'none',
                    'readiness' => $needs_key ? 'needs_key' : 'ready',
                ];

                if ( ! self::passes_filters($item, $filters))
                {
                    continue;
                }

                $matched = [];

                if ($query === '')
                {
                    $score = 1.0;
                }
                else
                {
                    $detail = self::match_detail([
                        'name' => (string) $name,
                        'slug' => (string) $name,
                        'description' => (string) ($tool['description'] ?? '').' '.(string) ($tool['category'] ?? ''),
                        'tools' => [],
                        'categories' => [],
                    ], $query);

                    $score = $detail['relevance'];
                    $matched = $detail['matched'];
                }

                // Порог 0.5 = «не меньше трёх очков»: один переводческий термин
                // («rate» в «rate_per_hour») не повод ставить инструмент в выдачу.
                if ($score >= ($query === '' ? 0.1 : 0.5))
                {
                    $platform[] = [
                        'score' => $score,
                        'name' => (string) $name,
                        'tool' => $tool,
                        'readiness' => $item['readiness'],
                        'auth' => $item['auth'],
                        'matched' => $matched,
                        'why' => ($needs_key ? 'нужен ключ N2J' : 'без ключа'),
                    ];
                }
            }

            // Готовое к вызову — выше: это и есть «сработает с первого раза».
            usort($platform, static function ($a, $b) {
                $ready = ($a['readiness'] === 'ready' ? 1 : 0) <=> ($b['readiness'] === 'ready' ? 1 : 0);

                return $ready !== 0 ? -$ready : ($b['score'] <=> $a['score']);
            });
            $platform = array_slice($platform, 0, min($limit, 5));
        }

        // 2. Серверы каталога. Берём с запасом: фильтры готовности/установки и
        // ранжирование применяются уже после SQL, иначе «ready» съедал бы всю
        // выдачу первыми же сырыми строками.
        // Строгий режим (only_ready) отсекает после SQL, поэтому берём широкий
        // пул: иначе первые 12 строк каталога не содержат готовых серверов и
        // агент видит «ничего нет» при живых серверах в базе.
        $pool = $ready_only ? 60 : min(40, max(($limit + $cursor) * 4, 12));

        // Категории из профиля — ПРЕДПОЧТЕНИЕ, а не фильтр: раньше
        // hub_configure {"categories":["ai"]} молча сужал выдачу до одной
        // категории, и агент переставал видеть подходящие серверы из других.
        $preferred = [];
        foreach ((array) ($profile['categories'] ?? []) as $category)
        {
            $category = trim((string) $category);

            if (isset(Service_Nj676_McpHub::CATEGORIES[$category]))
            {
                $preferred[] = $category;
            }
        }

        $sql_filters = [
            'q' => $query,
            'per' => $pool,
            'transport' => $filters['transport'] !== '' ? $filters['transport'] : (string) ($profile['transport'] ?? ''),
            'sort' => 'relevance',
        ];

        if ($filters['auth'] !== '')
        {
            $sql_filters['auth'] = $filters['auth'];
        }
        elseif ( ! empty($profile['no_auth_only']))
        {
            $sql_filters['auth'] = 'none';
        }

        // Смысловые кандидаты — ДО лексического пула. Вектор отвечает на
        // «о чём задача», а не «какие слова совпали»: на запрос «отправить
        // письмо» лексический поиск честно находил набор иконок с иконкой
        // конверта. Семантика включается, только если настроен
        // эмбеддинг-сервер; без него поиск работает как раньше.
        $semantic = ($query !== '') ? Service_Nj676_Semantic::hits($query, 40) : [];
        $semantic_rows = [];

        if ($semantic !== [])
        {
            $semantic_slugs = array_values(array_diff(array_keys($semantic), $ignore));
            $semantic_rows = Service_Nj676_McpHub::by_slugs(array_slice($semantic_slugs, 0, 40));
        }

        // Прямой поиск по точному slug или сегменту slug в списке готовых и в
        // каталоге: если агент ищет конкретный сервер (ai-echoloc-company-technographics,
        // huntboard, justidea, temporal-mcp), он обязан попасть в пул кандидатов
        // даже тогда, когда search_text ещё не пересчитан или в базе сотни
        // карточек с общими словами («ai», «company», «mcp»).
        if ($query !== '')
        {
            $q_slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($query)), '-');
            $direct_rows = [];

            if ($q_slug !== '' AND mb_strlen($q_slug) >= 2)
            {
                $exact_card = Service_Nj676_McpHub::get($q_slug);
                if (is_array($exact_card) AND ! empty($exact_card['slug']))
                {
                    $direct_rows[] = $exact_card;
                }

                foreach (Service_Nj676_McpHub::ready_servers(60) as $ready_card)
                {
                    $r_slug = (string) ($ready_card['slug'] ?? '');
                    if ($r_slug !== '' AND (
                        $r_slug === $q_slug
                        OR strpos($r_slug, $q_slug) !== FALSE
                        OR mb_stripos((string) ($ready_card['name'] ?? ''), $query) !== FALSE
                    ))
                    {
                        $direct_rows[] = $ready_card;
                    }
                }
            }

            if ($direct_rows !== [])
            {
                $semantic_rows = array_merge($direct_rows, $semantic_rows);
            }
        }

        // «Покажи готовое» — отдельный запрос по всей базе: страница из 60
        // популярных не содержит готовых серверов при большом каталоге.
        $catalog = [];
        $raw_total = 0;
        $want = $cursor + $limit + 1;

        if ($ready_only AND $query === '')
        {
            // Для hub_ready и пустого запроса с only_ready берём ВСЕ готовые
            // серверы (0 = без обрезки на 60), чтобы hub_ready и hub_search_tools
            // отдавали одно и то же число (296) и одну и ту же выборку.
            $chunk = Service_Nj676_McpHub::ready_servers(0);
            $has_more_rows = FALSE;
        }
        else
        {
            $chunk = [];
            $has_more_rows = TRUE;
        }

        $page = 1;
        $per = max(12, min(60, $want * 3));
        $seen = [];

        while (TRUE)
        {
            if (! $ready_only OR $query !== '')
            {
                $sql_filters['per'] = $per;
                $sql_filters['page'] = $page;
                $found = Service_Nj676_McpHub::search($sql_filters);
                $chunk = $found['servers'];
                $has_more_rows = ($page < (int) $found['pages'] AND count($chunk) >= $per);

                // Смысловые кандидаты попадают в тот же проход, что и
                // лексические: иначе их пришлось бы оценивать дважды и
                // дублировать в выдаче.
                if ($page === 1 AND $semantic_rows !== [])
                {
                    $chunk = array_merge($semantic_rows, $chunk);
                }

                // При only_ready: false без запроса (весь каталог) ставим
                // готовые серверы в начало выборки, чтобы первые позиции
                // оставались вызываемыми сразу, а следом шли needs_key/needs_install.
                if ($page === 1 AND $query === '' AND ! $ready_only)
                {
                    $chunk = array_merge(Service_Nj676_McpHub::ready_servers(0), $chunk);
                }
            }

            foreach ($chunk as $server)
            {
                $raw_total++;

                $slug = (string) $server['slug'];

                if (isset($seen[$slug]))
                {
                    continue;
                }

                $seen[$slug] = TRUE;

                if (in_array($slug, $ignore, TRUE))
                {
                    // «Этот сервер уже падал у меня» — прямой сигнал агента.
                    continue;
                }

                if ($only_gems AND empty($server['is_gem']))
                {
                    continue;
                }

                $server_filters = [
                    'auth' => (string) $server['auth'],
                    'install' => (string) $server['install'],
                    'readiness' => (string) $server['readiness']['readiness'],
                ];

                if (! self::passes_filters($server_filters, $filters))
                {
                    continue;
                }

                $latency = $server['latency_ms'] !== NULL ? (int) $server['latency_ms'] : NULL;
                $limit_latency = $filters['max_latency_ms'] > 0 ? $filters['max_latency_ms']
                    : (int) ($profile['latency_max'] ?? 0);

                if ($limit_latency > 0 AND $latency !== NULL AND $latency > $limit_latency)
                {
                    continue;
                }

                // Релевантность = max(слова, смысл). Порог обязателен: без него
                // «готовый» сервер без единого совпадения попадал в топ и
                // получал ложное «совпадение: …» в объяснении.
                $lexical = $query !== '' ? self::match_detail($server, $query) : ['relevance' => 0.0, 'matched' => [], 'exact' => FALSE, 'slug_match' => FALSE];
                $sem = (float) ($semantic[$slug] ?? 0.0);

                // Порогов два, по одному на шкалу: слово в описании — это
                // доказательство, «недалёкий» вектор — нет. Общий порог 0.12
                // пропускал любой векторный результат (у смысла есть свой пол
                // 0.2), и мусорный запрос возвращал 40 серверов.
                if ($query !== '')
                {
                    $lexical_ok = $lexical['relevance'] >= self::LEXICAL_FLOOR;
                    $semantic_ok = $sem >= self::SEMANTIC_FLOOR;

                    if ( ! $lexical_ok AND ! $semantic_ok)
                    {
                        continue;
                    }

                    // Заведомо сломанный сервер (offline / невалидный URL) не
                    // должен вытаскиваться исключительно векторным сходством,
                    // если агент не искал его по словам и не просил readiness=broken.
                    if ( ! $lexical_ok
                        AND (string) ($server['readiness']['readiness'] ?? '') === 'broken'
                        AND $filters['readiness'] !== 'broken')
                    {
                        continue;
                    }
                }

                $relevance = max($lexical['relevance'], $sem);

                $catalog[] = [
                    'server' => $server,
                    'relevance' => $relevance,
                    'matched' => $lexical['matched'],
                    'exact' => ! empty($lexical['exact']),
                    'slug_match' => ! empty($lexical['slug_match']),
                    'lexical_relevance' => (float) $lexical['relevance'],
                    'semantic' => $sem > $lexical['relevance'],
                ];
            }

            // Хватит на текущую страницу с запасом или строки кончились.
            // Строгий режим листает глубже: при тысячах карточек готовые
            // серверы стоят далеко в ранжировании, и «стоп после первой
            // страницы» давал «ничего нет» при живых серверах в базе.
            $max_pages = $ready_only ? 10 : 4;

            if (count($catalog) >= $want OR ! $has_more_rows OR $page >= $max_pages)
            {
                break;
            }

            $page++;
        }

        // $found может быть не определён в ветке «готовое»: дальше он не нужен.

        // 3. Надёжность и свежесть: то, чего в SQL нет и быть не может.
        $slugs = array_map(static function ($row) { return (string) $row['server']['slug']; }, $catalog);
        $stats = $slugs !== [] ? Service_Nj676_Telemetry::stats_by_server($slugs) : [];

        foreach ($catalog as & $row)
        {
            $server = $row['server'];
            $stat = $stats[$server['slug']] ?? [];

            $readiness_score = [
                'ready' => 1.0, 'degraded' => 0.45, 'stale' => 0.35,
                'needs_key' => 0.25, 'needs_install' => 0.2, 'broken' => 0.0,
            ][$server['readiness']['readiness']] ?? 0.2;

            $reliability = isset($stat['wilson']) ? (float) $stat['wilson'] : 0.5;

            $freshness = 0.15;
            $verified = (string) ($server['health_checked_at'] ?? '');
            if ($verified !== '')
            {
                $age = time() - (int) strtotime($verified);
                $freshness = $age <= 86400 ? 1.0 : ($age <= 7 * 86400 ? 0.7 : ($age <= 30 * 86400 ? 0.4 : 0.15));
            }

            $popularity = 0.0;
            $stars = (int) ($server['stars'] ?? 0);
            if ($stars > 0)
            {
                $popularity = min(1.0, log10($stars + 1) / 4);
            }

            // Веса зависят от того, есть ли запрос. С запросом решает
            // релевантность, готовность — лишь Advantage среди равных: раньше
            // «готовый» сервер обгонял подходящий, и агент уходил не туда.
            // Без запроса (hub_ready) наоборот: важно, что можно вызвать.
            if ($query !== '')
            {
                // Готовность в скор НЕ входит: «можно вызвать» — свойство
                // качества, а не ответ на вопрос. Из-за её веса temporal-mcp
                // вылезал первым на «распознать речь», хотя тема была не его
                // (тест 24.10.2026). Готовность осталась тайбрейкером ниже:
                // при равной уместности выше тот, кого можно позвать сейчас.
                $row['score'] = 0.75 * $row['relevance']
                    + 0.10 * $reliability + 0.10 * $freshness + 0.05 * $popularity;

                // Точное совпадение слага или имени (temporal-mcp, huntboard,
                // justidea, ai-echoloc-company-technographics) и сильное
                // совпадение заголовка + инструментов («иконки» → dashboard-icons)
                // получают явный приоритет над косвенными соседями.
                if ( ! empty($row['exact']))
                {
                    $row['score'] += 0.45;
                }
                elseif ( ! empty($row['slug_match']))
                {
                    $row['score'] += 0.22;
                }
                elseif (($row['lexical_relevance'] ?? 0.0) >= 0.85)
                {
                    $row['score'] += 0.12;
                }
            }
            else
            {
                $row['score'] = 0.45 * $row['relevance'] + 0.20 * $readiness_score
                    + 0.20 * $reliability + 0.10 * $freshness + 0.05 * $popularity;
            }

            // Готовность — тайбрейкер: при почти равной релевантности выше
            // тот, кого можно вызвать прямо сейчас.
            $row['tie_ready'] = $server['readiness']['ready'] ? 1 : 0;

            // Настроенные темы поднимают сервер в выдаче, но не прячут остальные.
            $matched_pref = [];
            if ($preferred !== [])
            {
                foreach ((array) ($server['categories'] ?? []) as $category)
                {
                    if (in_array((string) $category, $preferred, TRUE))
                    {
                        $matched_pref[] = (string) $category;
                    }
                }

                if ($matched_pref !== [])
                {
                    $row['score'] += 0.08;
                }
            }
            $row['reliability'] = $reliability;
            $row['calls'] = (int) ($stat['calls'] ?? 0);
            $row['success_rate'] = $row['calls'] > 0 ? round(100 * (float) ($stat['rate'] ?? 0), 1) : NULL;
            $row['feedback_count'] = (int) ($stat['feedback'] ?? 0);
            $row['feedback_up'] = (int) ($stat['up'] ?? 0);
            $row['feedback_down'] = (int) ($stat['down'] ?? 0);

            $stat_ar = $stat['agent_rating'] ?? NULL;
            $srv_ar = isset($server['agent_rating']) && (float) $server['agent_rating'] > 0
                ? round((float) $server['agent_rating'], 2) : NULL;
            $row['agent_rating'] = $stat_ar !== NULL ? (float) $stat_ar : $srv_ar;
            $row['agent_reviews_count'] = max($row['feedback_count'], (int) ($server['agent_reviews_count'] ?? 0));
            if ($row['agent_rating'] === NULL AND $row['feedback_count'] > 0)
            {
                $row['agent_rating'] = Service_Nj676_Telemetry::compute_agent_rating(
                    $row['feedback_up'], $row['feedback_down'], $row['calls'], (float) ($stat['rate'] ?? 0)
                );
            }

            $row['utility_score'] = max(0, min(100, (int) ($server['audit_score'] ?? 0)));
            $row['safety_score'] = max(0, min(100, (int) ($server['safety_score'] ?? 0)));
            $row['audit_verdict'] = (string) ($server['audit_verdict'] ?? '');
            $row['is_gem'] = ! empty($server['is_gem']);
            $row['audit_summary'] = (string) ($server['audit_summary'] ?? '');

            $base_trust = (int) ($server['trust'] ?? 0);
            $fb_delta = ($row['feedback_up'] * 2) - ($row['feedback_down'] * 5);
            $effective_trust = max(0, min(100, $base_trust + $fb_delta));
            if ($row['calls'] >= 3 AND (float) ($stat['rate'] ?? 0) >= 0.9)
            {
                $effective_trust = max($effective_trust, min(100, $base_trust + 5));
            }
            $row['trust'] = $effective_trust;
            $row['stars'] = $stars;
            $row['health_status'] = (string) ($server['health_status'] ?? 'unverified');

            $readiness_key = (string) ($server['readiness']['readiness'] ?? 'broken');
            // Эшелон исправности для sort=reliability/trust: все рабочие состояния
            // (ready, needs_key, needs_install, stale) идут в основном эшелоне (2)
            // и ранжируются по trust + success_rate + готовности, а сбоящие
            // (degraded = 1) и сломанные (broken = 0) всегда опускаются в конец.
            $row['reliability_tier'] = [
                'ready' => 2,
                'needs_key' => 2,
                'needs_install' => 2,
                'stale' => 2,
                'degraded' => 1,
                'broken' => 0,
            ][$readiness_key] ?? 1;

            $trust_norm = $effective_trust / 100.0;
            $live_norm = $row['calls'] > 0 ? $reliability : $trust_norm;
            $row['reliability_rank'] = round(
                0.55 * $trust_norm
                + 0.35 * $live_norm
                + 0.10 * $popularity
                + ($row['feedback_up'] > $row['feedback_down'] ? 0.05 : ($row['feedback_down'] > $row['feedback_up'] ? -0.15 : 0.0)),
                4
            );

            $why = [];
            if ($server['readiness']['readiness'] === 'ready')
            {
                $has_cred = ! empty($server['readiness']['has_credentials'])
                    || self::remote_token($server) !== '';
                if ($has_cred)
                {
                    $why[] = 'ключ настроен';
                }
                elseif ($server['auth'] === 'optional')
                {
                    $why[] = 'демо без ключа';
                }
                else
                {
                    $why[] = 'без ключа';
                }
                $why[] = $server['install'] === 'none' ? 'вызов по ссылке' : 'установка: '.$server['install'];
            }
            else
            {
                $why[] = 'готовность: '.$server['readiness']['readiness'].' ('.$server['readiness']['note'].')';
            }

            if ($verified !== '')
            {
                $why[] = 'проверен '.self::_age_text((int) strtotime($verified));
            }
            if (isset($stat['calls']) AND (int) $stat['calls'] > 0)
            {
                $why[] = (int) round(100 * (float) $stat['rate']).'% успеха / '.(int) $stat['calls'].' вызовов';
            }
            if ($row['agent_rating'] !== NULL)
            {
                $why[] = 'рейтинг агентов ★ '.$row['agent_rating'].'/5 ('.$row['agent_reviews_count'].')';
            }
            elseif ($row['feedback_count'] > 0)
            {
                $why[] = 'отзывы: +'.$row['feedback_up'].' / −'.$row['feedback_down'];
            }
            if ($row['is_gem'] OR $row['utility_score'] > 0)
            {
                $why[] = ($row['is_gem'] ? '💎 алмаз · ' : '').'польза '.$row['utility_score'].'/100 · безопасность '.$row['safety_score'].'/100';
            }
            if ($effective_trust > 0)
            {
                $why[] = 'доверие '.$effective_trust.'/100';
            }
            if ($stars > 0)
            {
                $why[] = '★ '.$stars;
            }
            if ($server['schema_valid'] === TRUE)
            {
                $why[] = 'схемы валидны';
            }
            elseif ($server['schema_valid'] === FALSE)
            {
                $why[] = 'схемы с ошибками';
            }
            if ($matched_pref !== [])
            {
                $why[] = 'ваша тема: '.implode(', ', array_map(static function ($category) {
                    return Service_Nj676_McpHub::CATEGORIES[$category] ?? $category;
                }, $matched_pref));
            }

            // Объяснение — только то, что реально совпало. Раньше здесь были
            // все слова запроса, и агент читал «совпадение: email» у набора
            // иконок: слово действительно есть среди его инструментов, но
            // к задаче это отношения не имеет.
            $matched_words = array_slice((array) $row['matched'], 0, 4);
            if ($matched_words !== [])
            {
                $why[] = 'совпадение: '.implode(' ', $matched_words);
            }
            elseif ( ! empty($row['semantic']))
            {
                $why[] = 'совпадение по смыслу';
            }

            $row['why'] = $why;
            $row['match'] = ! empty($row['semantic']) ? 'semantic' : ($matched_words !== [] ? 'keywords' : 'ready');
        }
        unset($row);

        if ($only_gems)
        {
            $catalog = array_values(array_filter($catalog, static function ($row) {
                return ! empty($row['is_gem']) OR (int) ($row['utility_score'] ?? 0) >= 80;
            }));
        }

        // Явный порядок важнее эвристики: «сначала быстрые» и «сначала
        // надёжные» — это вопросы, на которые агент не может ответить сам.
        usort($catalog, static function ($a, $b) use ($sort) {
            if ($sort === 'latency')
            {
                $left = $a['server']['latency_ms'] ?? NULL;
                $right = $b['server']['latency_ms'] ?? NULL;

                if ($left !== $right)
                {
                    if ($left === NULL) { return 1; }
                    if ($right === NULL) { return -1; }

                    return (int) $left <=> (int) $right;
                }

                return $b['score'] <=> $a['score'];
            }

            if ($sort === 'utility')
            {
                $tier_cmp = (int) ($b['reliability_tier'] ?? 0) <=> (int) ($a['reliability_tier'] ?? 0);
                if ($tier_cmp !== 0) { return $tier_cmp; }

                $gem_cmp = (! empty($b['is_gem']) ? 1 : 0) <=> (! empty($a['is_gem']) ? 1 : 0);
                if ($gem_cmp !== 0) { return $gem_cmp; }

                $util_cmp = (int) ($b['utility_score'] ?? 0) <=> (int) ($a['utility_score'] ?? 0);
                if ($util_cmp !== 0) { return $util_cmp; }

                $safe_cmp = (int) ($b['safety_score'] ?? 0) <=> (int) ($a['safety_score'] ?? 0);
                if ($safe_cmp !== 0) { return $safe_cmp; }

                $ar_a = isset($a['agent_rating']) && $a['agent_rating'] !== NULL ? (float) $a['agent_rating'] : 0.0;
                $ar_b = isset($b['agent_rating']) && $b['agent_rating'] !== NULL ? (float) $b['agent_rating'] : 0.0;
                if ($ar_a !== $ar_b) { return $ar_b <=> $ar_a; }

                return (int) ($b['trust'] ?? 0) <=> (int) ($a['trust'] ?? 0);
            }

            if ($sort === 'agent_rating')
            {
                $tier_cmp = (int) ($b['reliability_tier'] ?? 0) <=> (int) ($a['reliability_tier'] ?? 0);
                if ($tier_cmp !== 0) { return $tier_cmp; }

                $ar_a = isset($a['agent_rating']) && $a['agent_rating'] !== NULL ? (float) $a['agent_rating'] : 0.0;
                $ar_b = isset($b['agent_rating']) && $b['agent_rating'] !== NULL ? (float) $b['agent_rating'] : 0.0;
                if ($ar_a !== $ar_b) { return $ar_b <=> $ar_a; }

                $cnt_cmp = (int) ($b['agent_reviews_count'] ?? 0) <=> (int) ($a['agent_reviews_count'] ?? 0);
                if ($cnt_cmp !== 0) { return $cnt_cmp; }

                $util_cmp = (int) ($b['utility_score'] ?? 0) <=> (int) ($a['utility_score'] ?? 0);
                if ($util_cmp !== 0) { return $util_cmp; }

                return (int) ($b['trust'] ?? 0) <=> (int) ($a['trust'] ?? 0);
            }

            if (in_array($sort, ['reliability', 'trust'], TRUE))
            {
                // 1. Исправные серверы всегда выше degraded (1) и broken (0).
                $tier_cmp = (int) ($b['reliability_tier'] ?? 0) <=> (int) ($a['reliability_tier'] ?? 0);
                if ($tier_cmp !== 0)
                {
                    return $tier_cmp;
                }

                // 2. Рейтинг доверия каталога (trust 0..100, учитывает живые вызовы и отзывы).
                $trust_cmp = (int) ($b['trust'] ?? 0) <=> (int) ($a['trust'] ?? 0);
                if ($trust_cmp !== 0)
                {
                    return $trust_cmp;
                }

                // 3. Доля успешных вызовов через шлюз (если были вызовы).
                $sr_a = isset($a['success_rate']) && $a['success_rate'] !== NULL ? (float) $a['success_rate'] : -1.0;
                $sr_b = isset($b['success_rate']) && $b['success_rate'] !== NULL ? (float) $b['success_rate'] : -1.0;
                if ($sr_a !== $sr_b)
                {
                    return $sr_b <=> $sr_a;
                }

                // 4. При равном доверии готовый к вызову (ready) выше требующего ключа/установки.
                if (($a['tie_ready'] ?? 0) !== ($b['tie_ready'] ?? 0))
                {
                    return (int) ($b['tie_ready'] ?? 0) <=> (int) ($a['tie_ready'] ?? 0);
                }

                // 5. Звёзды GitHub и релевантность.
                $stars_cmp = (int) ($b['stars'] ?? 0) <=> (int) ($a['stars'] ?? 0);
                if ($stars_cmp !== 0)
                {
                    return $stars_cmp;
                }

                return $b['score'] <=> $a['score'];
            }

            // При почти равной релевантности (разница скора <= 0.20) готовый
            // к вызову сервер (ready) идёт выше требующего ключа или установки,
            // если оба действительно подходят по теме (relevance >= 0.45) и ни
            // один не является единственным точным совпадением слага/имени.
            if (($a['tie_ready'] ?? 0) !== ($b['tie_ready'] ?? 0)
                AND empty($a['exact']) AND empty($b['exact'])
                AND min((float) ($a['relevance'] ?? 0), (float) ($b['relevance'] ?? 0)) >= 0.45
                AND abs((float) $a['score'] - (float) $b['score']) <= 0.20)
            {
                return $b['tie_ready'] <=> $a['tie_ready'];
            }

            $cmp = $b['score'] <=> $a['score'];

            return $cmp !== 0 ? $cmp : ($b['tie_ready'] <=> $a['tie_ready']);
        });

        // Отсечка хвоста по относительному скору (score >= 0.8 * top).
        // Абсолютные пороги выше отвечают «это про задачу?», а этот — «это так
        // же про задачу, как лучшее?». Если же лидер по релевантности — точное
        // совпадение слага или имени (ai-huntboard-huntboard, huntboard, temporal-mcp),
        // убираем из хвоста чисто векторных соседей без лексического пересечения.
        if ($query !== '' AND $catalog !== [])
        {
            $best = 0.0;
            $top_is_id = FALSE;
            foreach ($catalog as $cand)
            {
                if ((float) $cand['score'] > $best)
                {
                    $best = (float) $cand['score'];
                    $top_is_id = ( ! empty($cand['exact']) OR ! empty($cand['slug_match']));
                }
            }

            if ($best > 0)
            {
                $cut = $best * self::SCORE_CUTOFF;
                $best_ready_alt = NULL;

                foreach ($catalog as $row)
                {
                    if ( ! empty($row['server']['readiness']['ready'])
                        AND (float) ($row['relevance'] ?? 0) >= 0.55
                        AND (float) $row['score'] >= $best * 0.65)
                    {
                        $best_ready_alt = $row;
                        break;
                    }
                }

                $catalog = array_values(array_filter($catalog, static function ($row) use ($cut, $top_is_id) {
                    if ((float) $row['score'] < $cut)
                    {
                        return FALSE;
                    }
                    if ($top_is_id AND empty($row['exact']) AND empty($row['slug_match']) AND empty($row['matched']))
                    {
                        return FALSE;
                    }

                    return TRUE;
                }));

                // Если в выдаче после отсечки остались только закрытые ключом
                // или локальные серверы, но по теме есть близкая ready-альтернатива —
                // поднимаем её в список, чтобы анонимный агент не упирался в тупик.
                $has_ready = FALSE;
                foreach ($catalog as $row)
                {
                    if ( ! empty($row['server']['readiness']['ready']))
                    {
                        $has_ready = TRUE;
                        break;
                    }
                }

                if ( ! $has_ready AND ! $top_is_id AND $best_ready_alt !== NULL)
                {
                    array_unshift($catalog, $best_ready_alt);
                }
            }
        }

        $catalog_total = count($catalog);

        // «Готово сейчас» — одно число во всех ответах. hub_ready считал только
        // то, что попало в его пул (16), а hub_status и лендинг — всё готовое
        // (17), потому что один сервер был скрыт личным ignore агента
        // (замечание 24.10.2026). Теперь total — это готовность целиком, а
        // разница называется словами: «1 скрыт как нерабочий у вас».
        $hidden_ready = 0;

        if ($ready_only AND $query === '' AND ! $explicit_filters)
        {
            $overview = Service_Nj676_McpHub::ready_overview();
            $reported = (int) $overview['servers'];

            if ($reported > $catalog_total)
            {
                $hidden_ready = $reported - $catalog_total;
                $catalog_total = $reported;
            }
        }
        elseif ( ! $ready_only AND $query === '' AND ! $explicit_filters)
        {
            // При only_ready: false без запроса total — это весь опубликованный
            // каталог (например, 1091), а не размер первого буфера выборки (27).
            $all_total = isset($found['total']) ? (int) $found['total'] : 0;
            if ($all_total <= 0)
            {
                $all_total = (int) (Service_Nj676_McpHub::stats()['servers'] ?? 0);
            }
            if ($all_total > $catalog_total)
            {
                $catalog_total = max($catalog_total, $all_total - count($ignore));
            }
        }

        $catalog = array_slice($catalog, $cursor, $limit);
        $next_cursor = ($cursor + $limit) < $catalog_total ? $cursor + $limit : NULL;

        // Одна тема часто закрыта тремя клонами («calculator», «calc-mcp»,
        // «precisioncalc»). Показываем лучший и перечисляем остальные: агенту
        // не нужны три одинаковых варианта, но и терять их нельзя.
        $families = [];
        foreach ($catalog as $row)
        {
            $families[self::family_key((string) $row['server']['name'])][] = (string) $row['server']['slug'];
        }

        foreach ($catalog as & $row)
        {
            $key = self::family_key((string) $row['server']['name']);
            $siblings = array_values(array_diff($families[$key] ?? [], [(string) $row['server']['slug']]));
            $row['variants'] = $siblings;
        }
        unset($row);

        // 4. Журнал спроса: тема без ответа становится темой автосборки.
        // Считаем совпадения БЕЗ фильтров готовности: иначе агент с
        // `only_ready` навсегда объявляет «погоду» отсутствующей темой и
        // запускает сборку там, где серверы уже есть — им нужен не поиск,
        // а проверка живости.
        if ($query !== '')
        {
            Service_Nj676_Demand::log($query, 'agent', $raw_total, count($platform),
                (string) ($ctx['token_hash'] ?? ''));
        }

        // 5. Мягкий fallback: пустая выдача — худший ответ. Показываем готовые
        // серверы и честно говорим, что это не точное совпадение.
        // Если лучшие результаты пришли только по семантике в «серой зоне»
        // (без совпадения слов и на пограничной дистанции), честно ставим
        // approximate: true, чтобы агент знал, что совпадение приблизительное.
        $approximate = FALSE;
        if ($query !== '' AND $catalog !== [])
        {
            $sem_stats = Service_Nj676_Semantic::last_stats();
            $top_row = $catalog[0];
            if ( ! empty($top_row['semantic'])
                AND empty($top_row['matched'])
                AND ( ! empty($sem_stats['grey_zone']) OR (float) ($top_row['relevance'] ?? 0.0) < 0.80))
            {
                $approximate = TRUE;
            }
        }
        if ($catalog === [] AND $platform === [] AND ! Service_Nj676_Semantic::is_gibberish($query))
        {
            // Если агент явно попросил только готовое — не подсовываем серверы,
            // которые всё равно не вызовутся: об этом честно скажет relax_hint.
            $strict = ( ! empty($filters['only_ready'])
                OR (string) $filters['readiness'] !== ''
                OR (string) $filters['auth'] !== ''
                OR (string) $filters['install'] !== '');

            // 5a. По словам: длинная фраза не должна обнулять выдачу.
            $word_hits = self::word_fallback($query, $ignore, $strict);
            $catalog = $word_hits;

            // 5b. Ближайшая категория по смыслу запроса. «курс валют»,
            // «время и часовые пояса» — темы, которых нет в словаре каталога
            // дословно; пустой ответ тут хуже, чем «вот финансы, вот время».
            $hinted = $catalog === [] ? self::category_fallback($query, $ignore, $strict) : [];

            foreach ($hinted as $fallback_row)
            {
                $catalog[] = $fallback_row;

                if (count($catalog) >= 3)
                {
                    break;
                }
            }

            // «Хоть что-нибудь готовое» допустимо, только когда смыслового
            // поиска нет. С работающими векторами пустая выдача — это честный
            // ответ «такого нет»: подсовывать случайные серверы значит снова
            // уводить агента не туда (тест 24.10.2026).
            $semantic_usable = (Service_Nj676_Semantic::enabled()
                AND Service_Nj676_Semantic::last_error() === '');

            $spare = ($catalog === [] AND ! $semantic_usable)
                ? Service_Nj676_McpHub::search(['per' => 10, 'sort' => 'trust'])['servers']
                : [];
            $rank = ['ready' => 0, 'degraded' => 1, 'stale' => 2, 'needs_key' => 3, 'needs_install' => 4, 'broken' => 5];

            usort($spare, static function ($a, $b) use ($rank) {
                $left = $rank[$a['readiness']['readiness']] ?? 9;
                $right = $rank[$b['readiness']['readiness']] ?? 9;

                if ($left !== $right)
                {
                    return $left <=> $right;
                }

                return (float) $b['trust'] <=> (float) $a['trust'];
            });

            foreach ($spare as $server)
            {
                if ($strict AND ! $server['readiness']['ready'])
                {
                    continue;
                }

                if ((string) ($server['readiness']['readiness'] ?? '') === 'broken')
                {
                    continue;
                }

                if (in_array((string) $server['slug'], $ignore, TRUE))
                {
                    continue;
                }

                $catalog[] = [
                    'server' => $server,
                    'relevance' => 0.0,
                    'score' => 1 - (($rank[$server['readiness']['readiness']] ?? 9) / 10),
                    'why' => [
                        $server['readiness']['ready'] ? 'готов к вызову' : 'готовность: '.$server['readiness']['readiness'],
                        'по запросу «'.$query.'» точного совпадения нет',
                    ],
                ];

                if (count($catalog) >= 3)
                {
                    break;
                }
            }
            $approximate = $catalog !== [];

            if ($approximate)
            {
                // Приблизительная выдача собирается уже после страницы:
                // total должен считать и её, иначе cursor указывает в пустоту.
                $catalog_total = count($catalog);
            }
        }

        // 6. Текст + structuredContent: агент читает текст, а ветвится по JSON.
        $lines = [];
        $platform_struct = [];
        $servers_struct = [];

        if ($platform !== [])
        {
            $lines[] = ($query === ''
                    ? 'Сервисы N2J, доступные сейчас (hub_call_tool {"tool":"…","arguments":{…}}):'
                    : 'Сервисы N2J (вызов: hub_call_tool {"tool":"…","arguments":{…}}):');
            foreach ($platform as $hit)
            {
                $required = array_map('strval', (array) ($hit['tool']['inputSchema']['required'] ?? []));
                $lines[] = '- '.$hit['name'].' ['.$hit['readiness'].'] — '
                    .mb_substr((string) ($hit['tool']['description'] ?? ''), 0, 200)
                    .($required !== [] ? '; обязательные аргументы: '.implode(', ', $required) : '; аргументы необязательны');

                $platform_struct[] = [
                    'tool' => $hit['name'],
                    'description' => mb_substr((string) ($hit['tool']['description'] ?? ''), 0, 300),
                    'readiness' => $hit['readiness'],
                    'auth' => $hit['auth'],
                    'required' => $required,
                    'call' => 'hub_call_tool {"tool":"'.$hit['name'].'","arguments":{…}}',
                ];
            }
        }

        if ($catalog !== [])
        {
            $lines[] = ($lines !== [] ? "\n" : '')
                .($approximate
                    ? 'Точных совпадений нет. Ближайшее, что можно вызвать:'
                    : 'MCP-серверы из каталога (готовность · доступ · задержка):');

            foreach ($catalog as $row)
            {
                $server = $row['server'];
                $meta = [$server['readiness']['readiness']];

                if ($server['auth'] === 'none')
                {
                    $meta[] = 'без ключа';
                }
                else
                {
                    $meta[] = 'ключ: '.$server['auth_label'];
                }

                if ($server['install'] !== 'none')
                {
                    $meta[] = 'установка: '.$server['install'];
                }
                if ($server['latency_ms'] !== NULL)
                {
                    $meta[] = (int) $server['latency_ms'].' мс';
                }
                if ((int) ($row['trust'] ?? $server['trust'] ?? 0) > 0)
                {
                    $meta[] = 'доверие '.((int) ($row['trust'] ?? $server['trust'] ?? 0)).'/100';
                }
                if ((int) ($server['stars'] ?? 0) > 0)
                {
                    $meta[] = '★ '.((int) $server['stars']);
                }

                // Описание приходит от автора сервера: чистим от инъекций и
                // режем по концу фразы, а не посреди слова (тест 24.10.2026).
                $title = trim((string) ($server['display_name'] ?? '')) !== ''
                    ? (string) $server['display_name'] : (string) $server['name'];

                $lines[] = '- '.$server['slug'].' «'.$title.'» ['.implode(' · ', $meta).'] — '
                    .self::clean_remote_text((string) ($server['display_description'] ?? $server['description']), 180)
                    .( ! empty($row['variants']) ? ' (похожие: '.implode(', ', $row['variants']).')' : '');

                if ( ! empty($args['explain']))
                {
                    $lines[] = '    почему здесь: '.implode(', ', $row['why']);
                }
                elseif ($approximate AND ! empty($row['why'][0]))
                {
                    // В приблизительной выдаче причина — не украшение: агент
                    // должен видеть, что сервер попал сюда по категории.
                    $lines[] = '    почему здесь: '.$row['why'][0];
                }

                $tools = self::compact_tools((array) $server['tools'], 3);
                $next = NULL;
                $total_tools = count((array) $server['tools']);

                foreach ($tools as $compact)
                {
                    $lines[] = '    '.$compact['signature']
                        .($compact['description'] !== '' ? ' — '.self::clean_remote_text($compact['description'], 160) : '')
                        .($compact['mutating'] === TRUE ? ' [изменяет данные]' : '')
                        .($compact['cost'] === 'paid' ? ' [платно]' : '');
                }

                // Один совет «дальше» вместо перечня всех схем: агенту нужен
                // конкретный вызов, а не 20 сигнатур. Предлагаем hub_call_tool
                // ТОЛЬКО для реально готовых к вызову серверов (ready).
                if ($tools !== [])
                {
                    $chosen = self::best_tool($tools, $query);
                    $next = [
                        'hub_call_tool' => [
                            'server' => $server['slug'],
                            'tool' => $chosen['name'],
                            'arguments' => self::args_object($chosen['schema']),
                        ],
                    ];

                    if ($query !== '' AND ! empty($server['readiness']['ready']))
                    {
                        $lines[] = '    → за этим: hub_call_tool '.json_encode($next['hub_call_tool'],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }

                if ($total_tools > count($tools))
                {
                    // Не вываливаем 40 инструментов: агенту нужен короткий
                    // список и точная дверь к остальным.
                    $lines[] = '    … ещё '.($total_tools - count($tools)).' инструментов: hub_describe_tool '
                        .json_encode(['server' => $server['slug']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                if ($server['readiness']['readiness'] !== 'ready' AND $server['install'] !== 'none')
                {
                    $lines[] = '    → локальный: готовая конфигурация на /mcphub/'.$server['slug'];
                }

                $servers_struct[] = [
                    'slug' => $server['slug'],
                    // Агенту — русский текст редакции, если он есть; оригинал
                    // автора остаётся рядом отдельными полями.
                    'name' => $server['display_name'] ?? $server['name'],
                    'name_original' => $server['name'],
                    'description' => self::clean_remote_text(
                        (string) ($server['display_description'] ?? $server['description']), 300),
                    'description_original' => ! empty($server['has_ru'])
                        ? self::clean_remote_text((string) $server['description'], 300) : '',
                    'translated' => ! empty($server['has_ru']),
                    'auth' => $server['auth'],
                    'install' => $server['install'],
                    'readiness' => $server['readiness']['readiness'],
                    'ready' => $server['readiness']['ready'],
                    'health_status' => (string) ($server['health_status'] ?? 'unverified'),
                    'trust' => (int) ($row['trust'] ?? $server['trust'] ?? 0),
                    'stars' => (int) ($server['stars'] ?? 0),
                    'verified_at' => $server['readiness']['verified_at'],
                    'latency_ms' => $server['readiness']['latency_ms'],
                    'calls' => (int) ($row['calls'] ?? 0),
                    'success_rate' => $row['success_rate'] ?? NULL,
                    'feedback_count' => (int) ($row['feedback_count'] ?? 0),
                    'feedback_up' => (int) ($row['feedback_up'] ?? 0),
                    'feedback_down' => (int) ($row['feedback_down'] ?? 0),
                    'agent_rating' => $row['agent_rating'] ?? NULL,
                    'agent_reviews_count' => (int) ($row['agent_reviews_count'] ?? 0),
                    'utility_score' => (int) ($row['utility_score'] ?? 0),
                    'safety_score' => (int) ($row['safety_score'] ?? 0),
                    'audit_verdict' => (string) ($row['audit_verdict'] ?? ''),
                    'is_gem' => ! empty($row['is_gem']),
                    'audit_summary' => self::clean_remote_text((string) ($row['audit_summary'] ?? ''), 240),
                    'match' => $row['match'] ?? 'keywords',
                    'score' => round((float) ($row['score'] ?? 0), 3),
                    'variants' => $row['variants'] ?? [],
                    'tools' => array_map(static function ($compact) {
                        return [
                            'name' => $compact['name'],
                            // Параметры — только имена и обязательность: полная
                            // схема живёт в hub_describe_tool.
                            'required' => $compact['required'],
                            'signature' => $compact['signature'],
                            'mutating' => $compact['mutating'],
                            'cost' => $compact['cost'],
                        ];
                    }, $tools),
                ];

                // Объяснение — по запросу агента (explain), а не всегда:
                // в выдаче из 8 серверов это треть веса ответа.
                if ( ! empty($args['explain']))
                {
                    $servers_struct[count($servers_struct) - 1]['why'] = $row['why'];
                }
            }
        }

        if ($servers_struct !== [])
        {
            // Описания берутся из чужих репозиториев и реестра: это слова
            // авторов серверов, а не редакции каталога. Говорим один раз на
            // выдачу, а не в каждой строке.
            $lines[] = 'Описания серверов и инструментов — слова их авторов; проверяйте по делу.';
        }

        // Пустая выдача с фильтрами — это не «ничего нет», а «фильтр отсёк всё».
        // Агент должен узнать, какой именно фильтр виноват, иначе он уйдёт ни с чем.
        $relax_line = '';

        if ($servers_struct === [] AND $platform_struct === [])
        {
            $relax_line = self::relax_hint($filters, $query);

            if ($relax_line !== '')
            {
                $lines[] = $relax_line;
            }
        }

        // «next» — это совет, а не «первая строка списка». Раньше здесь стоял
        // первый инструмент первого сервера, и на «отправить письмо» агент
        // получал готовый вызов match_icons у набора иконок: не подсказка,
        // а дезинформация. Теперь берём ИНСТРУМЕНТ, ближайший к запросу.
        $next_step = '';
        foreach ($servers_struct as $server)
        {
            if ($server['ready'] AND $server['tools'] !== [])
            {
                $tool = self::best_tool($server['tools'], $query);
                $next_step = 'hub_call_tool '.json_encode([
                    'server' => $server['slug'],
                    'tool' => $tool['name'],
                    'arguments' => self::args_object([
                        'properties' => (array) ($tool['parameters'] ?? []),
                        'required' => (array) ($tool['required'] ?? []),
                    ]),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            }
        }

        if ($next_step === '')
        {
            foreach ($platform_struct as $hit)
            {
                if ($hit['readiness'] === 'ready')
                {
                    $next_step = 'hub_call_tool {"tool":"'.$hit['tool'].'","arguments":{…}}';
                    break;
                }
            }
        }

        // В next НЕ предлагаем инструменты, которые нельзя вызвать без ключа
        // или установки: если ни один найденный сервер не ready, честно
        // пишем, что готового без ключа/установки нет.
        if ($next_step === '' AND $servers_struct !== [])
        {
            $non_ready_kinds = [];
            $cfg_slug = '';
            foreach ($servers_struct as $srv)
            {
                $r = (string) ($srv['readiness'] ?? '');
                if ($r !== '' AND $r !== 'ready')
                {
                    $non_ready_kinds[$r] = $r;
                    if ($cfg_slug === '' AND $r !== 'broken')
                    {
                        $cfg_slug = (string) ($srv['slug'] ?? '');
                    }
                }
            }

            $next_step = 'готового без ключа или установки нет (найдены только '
                .implode(', ', array_values($non_ready_kinds) ?: ['непроверенные'])
                .') — '
                .($cfg_slug !== '' ? 'конфигурация на /mcphub/'.$cfg_slug.' или ' : '')
                .'список готовых: hub_ready {}';
        }

        if ($next_step === '' AND $approximate)
        {
            $next_step = 'hub_status — что вообще доступно этому ключу';
        }

        if ($next_step === '')
        {
            $next_step = $relax_line !== ''
                ? 'hub_search_tools '.json_encode(['query' => $query], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    .' — без фильтров'
                : 'hub_search_tools с более простыми словами («поиск», «погода», «PDF», «база данных»).';
        }

        if ($next_cursor !== NULL)
        {
            // Валидный JSON, а не «{…, cursor}»: слабая модель копирует строку.
            $lines[] = 'Дальше: hub_search_tools '.json_encode([
                'query' => $query,
                'limit' => $limit,
                'cursor' => $next_cursor,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($hidden_ready > 0)
        {
            $lines[] = 'Ещё '.$hidden_ready.' готовых скрыто: это серверы, которые падали у вас '
                .'или не прошли ваши фильтры (вернуть: hub_configure {"ignore":[]}).';
        }

        if ($ignore !== [] AND $catalog === [])
        {
            $lines[] = 'Скрыты серверы, которые у вас уже падали: '.implode(', ', array_slice($ignore, 0, 8))
                .'. Вернуть: hub_configure {"ignore":[]}.';
        }

        if ($query === '' AND ! $ready_only)
        {
            $ready_total = (int) (Service_Nj676_McpHub::ready_overview()['servers'] ?? count(Service_Nj676_McpHub::ready_servers(0)));
            $ready_first_note = 'показаны первые '.$ready_total.' готовых, остальные — с cursor (или укажите filters.readiness="needs_key" / "needs_install").';
            $limit_note = $limit_note !== '' ? $limit_note.' '.ucfirst($ready_first_note) : ucfirst($ready_first_note);
        }

        if ($limit_note !== '')
        {
            $lines[] = $limit_note;
        }

        $returned_count = count($servers_struct);
        $truncated_count = max(0, $catalog_total - ($cursor + $returned_count));

        if ($returned_count > 0)
        {
            $lines[] = 'Выдача: returned='.$returned_count.', truncated='.$truncated_count
                .(($truncated_count > 0 AND $next_cursor !== NULL) ? ' (следующая страница: cursor='.$next_cursor.')' : '').'.';
        }

        // Пустой ответ должен быть ОБЪЯСНЁН. Замер 24.10.2026: на мусорный
        // запрос приходило 40 серверов — потому что подставлять хоть что-то
        // казалось безопаснее. Для агента это дороже: `next` в неверный
        // инструмент стоит вызова. Говорим прямо «нет» и почему.
        if ($query !== '' AND $servers_struct === [] AND $platform_struct === []
            AND mb_strpos((string) implode("\n", $lines), 'ничего') === FALSE)
        {
            $stats = Service_Nj676_Semantic::last_stats();
            $verdict = (string) ($stats['verdict'] ?? '');

            $lines[] = 'По запросу «'.$query.'» в каталоге ничего не нашлось — это честный ответ, '
                .'а не сбой: каталог не подставляет случайные серверы.'
                .(($verdict !== '' AND $verdict !== 'ок') ? ' Смысловой поиск промолчал: '.$verdict.'.' : '');
        }

        $lines[] = "\nnext: ".$next_step;

        if ($approximate)
        {
            $lines[] = 'Точного совпадения нет — это ближайшие серверы каталога, не обязательно по теме. '
                .'Если задача важная, опишите её проще: каталог доберёт недостающее по вашему запросу.';
        }

        $structured = [
            'ok' => TRUE,
            'query' => $query,
            'approximate' => $approximate,
            'filters' => array_filter($filters, static function ($value) { return $value !== '' AND $value !== FALSE AND $value !== 0; }),
            'counts' => [
                'platform' => count($platform_struct),
                'servers' => $returned_count,
                'returned' => $returned_count,
                'truncated' => $truncated_count,
                'page_servers' => $returned_count,
                'total_servers' => $catalog_total,
            ],
            'returned' => $returned_count,
            'truncated' => $truncated_count,
            'total' => $catalog_total,
            'hidden' => $hidden_ready,
            'limit' => $limit,
            'cursor' => $cursor,
            'next_cursor' => $next_cursor,
            'platform' => $platform_struct,
            'servers' => $servers_struct,
            'next' => $next_step,
        ];

        if ($limit_note !== '')
        {
            $structured['note'] = $limit_note;
        }

        if ($semantic !== [])
        {
            $structured['semantic'] = TRUE;
        }

        // «Почему пусто» не должно быть загадкой: показываем, что решила
        // семантика (лучшая дистанция, сколько отсеклось). Это же поле —
        // инструмент для настройки порогов владельцем.
        if ($query !== '')
        {
            $stats = Service_Nj676_Semantic::last_stats();

            if ($stats !== [])
            {
                $structured['semantic_check'] = $stats;
            }
        }

        return [
            'content' => [['type' => 'text', 'text' => implode("\n", $lines)]],
            'structuredContent' => (object) Service_Nj676_McpHub::schemas_as_objects($structured),
            // Обратная совместимость со старыми клиентами шлюза.
            'tools' => array_map(static function ($hit) { return $hit['name']; }, $platform),
            'servers' => array_map(static function ($row) { return $row['server']['slug']; }, $catalog),
        ];
    }

    /**
     * Что именно отсёкло выдачу и сколько было бы без фильтров.
     *
     * Реальный тест: `only_ready:true` вернул пустой список, потому что в
     * каталоге не было ни одного проверенного сервера, а ответ молчал об этом.
     */
    public static function relax_hint(array $filters, $query)
    {
        $cuts = [];

        // only_ready разворачивается в три фильтра; в подсказке достаточно
        // одного понятного имени, иначе агент чинит не то.
        if ( ! empty($filters['only_ready']))
        {
            $cuts[] = 'only_ready: true';
        }
        elseif ((string) $filters['readiness'] !== '')
        {
            $cuts[] = 'readiness='.$filters['readiness'];
        }
        if (empty($filters['only_ready']) AND (string) $filters['auth'] !== '')
        {
            $cuts[] = 'auth='.$filters['auth'];
        }
        if (empty($filters['only_ready']) AND (string) $filters['install'] !== '')
        {
            $cuts[] = 'install='.$filters['install'];
        }
        if ((string) $filters['transport'] !== '')
        {
            $cuts[] = 'transport='.$filters['transport'];
        }
        if ((int) $filters['max_latency_ms'] > 0)
        {
            $cuts[] = 'max_latency_ms='.$filters['max_latency_ms'];
        }

        if ($cuts === [])
        {
            return '';
        }

        $without = Service_Nj676_McpHub::search(['q' => (string) $query, 'per' => 3]);
        $any = (int) ($without['total'] ?? 0);

        $line = 'Пусто из-за фильтров: '.implode(', ', $cuts).'. ';

        if ($any > 0)
        {
            return $line.'Без них в каталоге найдётся: '.$any.'. Снимите фильтр и повторите.';
        }

        return $line.'Совпадений по словам нет и без фильтров: опишите задачу проще.';
    }

    /**
     * Фильтры поиска. Профиль агента (hub_configure) — значения по умолчанию,
     * явные аргументы запроса важнее: один агент не должен менять выдачу
     * другому, а разовый фильтр не обязан переживать следующий запрос.
     */
    public static function search_filters(array $args, array $ctx)
    {
        $profile = self::profile($ctx);

        $auth = (string) ($args['auth'] ?? '');
        $auth = in_array($auth, ['none', 'optional', 'api_key', 'oauth', 'unknown'], TRUE) ? $auth : '';

        $install = (string) ($args['install'] ?? '');
        $install = in_array($install, ['none', 'npx', 'uvx', 'manual'], TRUE) ? $install : '';

        $readiness = (string) ($args['readiness'] ?? '');
        $readiness = in_array($readiness, ['ready', 'needs_key', 'needs_install', 'degraded', 'stale', 'broken'], TRUE)
            ? $readiness : '';

        $transport = (string) ($args['transport'] ?? '');
        $transport = in_array($transport, ['stdio', 'sse', 'streamable_http'], TRUE) ? $transport : '';

        $only_ready = ! empty($args['only_ready']);

        if ($only_ready)
        {
            // Готовность считается через readiness() = ready + без локальной
            // установки. Жёсткий auth = 'none' здесь НЕ ставим: в ready входят
            // и серверы с настроенным на шлюзе ключом, и freemium-серверы
            // (auth = optional / unknown), которые реально отвечают на вызов.
            // Из-за принудительного auth = 'none' счётчик ready_overview (60)
            // расходился с выборкой only_ready (50), порождая ложные «10 скрыто».
            $readiness = 'ready';
            $install = 'none';
        }

        if ($auth === '' AND ! empty($profile['no_auth_only']))
        {
            $auth = 'none';
        }

        // Агент без прав установки: локальные пакеты ему бесполезны, даже если
        // карточка красивая. Явный install в запросе важнее профиля.
        if ($install === '' AND ( ! empty($args['no_install']) OR ! empty($profile['no_install'])))
        {
            $install = 'none';
        }

        if ($transport === '' AND ! empty($profile['transport']))
        {
            $transport = (string) $profile['transport'];
        }

        return [
            'only_ready' => $only_ready,
            'auth' => $auth,
            'install' => $install,
            'readiness' => $readiness,
            'transport' => $transport,
            'max_latency_ms' => max(0, min(60000, (int) ($args['max_latency_ms'] ?? 0))),
        ];
    }

    /** Проходит ли позиция через фильтры (auth/install/readiness). */
    /**
     * hub_ready: «что можно вызвать прямо сейчас», без обязательного запроса.
     *
     * Отдельное имя нужно слабым моделям: `hub_search_tools` требует тему, и
     * агент, которому нужен любой рабочий инструмент, придумывает запрос и
     * получает нерелевантную выдачу.
     */
    public static function ready_tools(array $args, array $ctx)
    {
        if ( ! array_key_exists('only_ready', $args))
        {
            $args['only_ready'] = TRUE;
        }
        if ( ! array_key_exists('explain', $args))
        {
            $args['explain'] = TRUE;
        }
        if ( ! array_key_exists('limit', $args))
        {
            $args['limit'] = 8;
        }
        $args['query'] = '';

        return self::search_tools($args, $ctx);
    }

    /** Список slug-ов из массива, строки через запятую или одного значения. */
    protected static function slug_list($value)
    {
        if (is_string($value))
        {
            $value = preg_split('/[,\s]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if ( ! is_array($value))
        {
            return [];
        }

        $out = [];
        foreach (array_slice($value, 0, 50) as $slug)
        {
            $slug = mb_strtolower(trim((string) $slug));

            if ($slug !== '' AND preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $slug))
            {
                $out[] = $slug;
            }
        }

        return array_values(array_unique($out));
    }

    /** Аргументы-пример: пустой набор обязан быть объектом `{}`, а не `[]`. */
    protected static function args_object(array $schema)
    {
        return self::example_arguments($schema);
    }

    /**
     * Поиск по отдельным словам запроса.
     *
     * «franchise Google Ads playbooks» целиком не находится, а «Google Ads»
     * находится: длинная фраза не должна обнулять выдачу. Берём слова по
     * одному, от длинных к коротким, и честно помечаем приблизительность.
     */
    protected static function word_fallback($query, array $ignore, $strict, $limit = 3)
    {
        $words = self::raw_words($query, 4);
        $out = [];
        $seen = [];

        foreach ($words as $word)
        {
            $rows = Service_Nj676_McpHub::search(['q' => $word, 'per' => 8, 'sort' => 'trust'])['servers'];

            foreach ($rows as $server)
            {
                $slug = (string) $server['slug'];

                if (isset($seen[$slug]) OR in_array($slug, $ignore, TRUE))
                {
                    continue;
                }

                // Слово должно реально быть в карточке. SQL уже фильтровал, но
                // проверка здесь дешёвая и не даёт «совпадению» появиться из
                // ошибки запроса: именно такие строки портили объяснения.
                $check = self::match_detail($server, $word);

                if ($check['relevance'] <= 0)
                {
                    continue;
                }

                if ($strict AND ! $server['readiness']['ready'])
                {
                    continue;
                }

                if ((string) ($server['readiness']['readiness'] ?? '') === 'broken')
                {
                    continue;
                }

                $seen[$slug] = TRUE;
                $out[] = [
                    'server' => $server,
                    'relevance' => 0.4,
                    'score' => $server['readiness']['ready'] ? 0.6 : 0.35,
                    'why' => [
                        'совпадение по слову «'.$word.'»',
                        'по запросу «'.$query.'» целиком совпадений нет',
                    ],
                ];

                if (count($out) >= $limit)
                {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Ближайшая категория каталога по смыслу запроса.
     *
     * Срабатывает, когда слов не нашлось ни в именах, ни в описаниях: лучше
     * показать финансы по запросу «курс валют», чем пустой список.
     */
    protected static function category_fallback($query, array $ignore, $strict)
    {
        $query = trim((string) $query);

        if ($query === '')
        {
            return [];
        }

        $categories = [];
        foreach (self::words($query) as $word)
        {
            foreach (Service_Nj676_McpHub::CATEGORY_HINTS as $stem => $category)
            {
                if ($word === $stem OR mb_strpos($word, $stem) === 0 OR mb_strpos($stem, $word) === 0)
                {
                    $categories[$category] = TRUE;
                }
            }
        }

        if ($categories === [])
        {
            return [];
        }

        $rank = ['ready' => 0, 'degraded' => 1, 'stale' => 2, 'needs_key' => 3, 'needs_install' => 4, 'broken' => 5];
        $out = [];
        $seen = [];

        foreach (array_slice(array_keys($categories), 0, 2) as $category)
        {
            $rows = Service_Nj676_McpHub::search([
                'category' => $category, 'per' => 12, 'sort' => 'trust',
            ])['servers'];

            usort($rows, static function ($a, $b) use ($rank) {
                $left = $rank[$a['readiness']['readiness']] ?? 9;
                $right = $rank[$b['readiness']['readiness']] ?? 9;

                return $left !== $right ? ($left <=> $right) : ((float) $b['trust'] <=> (float) $a['trust']);
            });

            foreach ($rows as $server)
            {
                $slug = (string) $server['slug'];

                if (isset($seen[$slug]) OR in_array($slug, $ignore, TRUE))
                {
                    continue;
                }

                if ($strict AND ! $server['readiness']['ready'])
                {
                    continue;
                }

                if ((string) ($server['readiness']['readiness'] ?? '') === 'broken')
                {
                    continue;
                }

                $seen[$slug] = TRUE;
                $out[] = [
                    'server' => $server,
                    'relevance' => 0.25,
                    'score' => 1 - (($rank[$server['readiness']['readiness']] ?? 9) / 10),
                    'why' => [
                        'ближайшая тема: '.(Service_Nj676_McpHub::CATEGORIES[$category] ?? $category),
                        $server['readiness']['ready'] ? 'готов к вызову' : 'готовность: '.$server['readiness']['readiness'],
                        'по запросу «'.$query.'» точных совпадений нет',
                    ],
                ];

                if (count($out) >= 3)
                {
                    return $out;
                }
            }
        }

        return $out;
    }

    protected static function passes_filters(array $item, array $filters)
    {
        if ($filters['auth'] !== '' AND (string) ($item['auth'] ?? '') !== $filters['auth'])
        {
            return FALSE;
        }

        if ($filters['install'] !== '' AND (string) ($item['install'] ?? '') !== $filters['install'])
        {
            return FALSE;
        }

        if ($filters['readiness'] !== '' AND (string) ($item['readiness'] ?? '') !== $filters['readiness'])
        {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Компактное описание инструментов сервера: имя, обязательные аргументы,
     * типы и побочные эффекты. Агенту этого хватает, чтобы вызвать с первого
     * раза; полная схема — в hub_describe_tool.
     */
    protected static function compact_tools(array $tools, $limit = 6)
    {
        $out = [];

        foreach (array_slice($tools, 0, $limit) as $tool)
        {
            if ( ! is_array($tool) OR empty($tool['name']))
            {
                continue;
            }

            $schema = is_array($tool['inputSchema'] ?? NULL) ? $tool['inputSchema'] : [];
            $props = is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [];
            $required = array_map('strval', (array) ($schema['required'] ?? []));
            $description = mb_substr(trim((string) ($tool['description'] ?? '')), 0, 200, 'UTF-8');

            $flags = self::tool_flags($tool, (string) $tool['name'], $description);

            $out[] = [
                'name' => (string) $tool['name'],
                'description' => $description,
                'required' => $required,
                'signature' => self::signature((string) $tool['name'], array_keys($props), $required),
                // Полные properties в поиске не отдаём: это ровно те «схемы
                // чужих серверов», от которых шлюз и должен избавлять. Один
                // hub_search_tools весил 13–20 КБ (тест 24.10.2026).
                'parameters' => $props,
                'schema' => ['type' => 'object', 'properties' => $props, 'required' => $required],
                'mutating' => $flags['mutating'],
                'mutating_source' => $flags['mutating_source'],
                'cost' => $flags['cost'],
            ];
        }

        return $out;
    }

    /** «get_forecast(latitude*, longitude*, days?)». */
    protected static function signature($name, array $properties, array $required)
    {
        $args = [];

        foreach ($properties as $property)
        {
            $property = (string) $property;
            $args[] = in_array($property, $required, TRUE) ? $property.'*' : $property;
        }

        return $name.'('.implode(', ', $args).')';
    }

    /** Инструмент меняет данные? Ошибка тут дороже: агент может записать мусор. */
    protected static function is_mutating($name, $description = '')
    {
        $name = mb_strtolower((string) $name);

        // sign_up / sign-up — это одно действие, а не два слова: иначе
        // «создать аккаунт» (и выдать секрет) выглядел безопасным чтением.
        $name = str_replace(['sign up', 'sign_up', 'sign-up'], 'signup', $name);

        $tokens = preg_split('/[^a-z0-9]+/i', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === [])
        {
            return FALSE;
        }

        // Читающий префикс сильнее любого глагола внутри имени: get_blog_post —
        // чтение, хотя в имени есть `post` (реальный ложный «изменяет данные»
        // из теста 24.10.2026).
        $read_prefixes = ['get', 'list', 'read', 'search', 'fetch', 'find', 'show', 'count',
            'describe', 'inspect', 'view', 'lookup', 'query', 'check', 'validate', 'export'];

        $read_prefix = in_array($tokens[0], $read_prefixes, TRUE);

        if ($read_prefix)
        {
            // ...но не когда пишущий глагол идёт следом: get_and_delete — пишет.
            $tokens = array_slice($tokens, 1);
        }

        $write = ['create', 'update', 'delete', 'remove', 'write', 'add', 'send', 'post',
            'put', 'patch', 'insert', 'upsert', 'save', 'publish', 'approve', 'pay', 'transfer',
            'execute', 'schedule', 'book', 'signup', 'register', 'subscribe', 'invite', 'cancel',
            'upload', 'import', 'merge', 'move', 'rename', 'grant', 'revoke', 'reset', 'submit'];

        // После читающего глагола «post», «set», «order» — обычно существительные:
        // get_blog_post читает пост, а не создаёт его. Неопределённость честнее
        // ложного «изменяет данные».
        $ambiguous_after_read = ['post', 'set', 'book', 'order', 'note', 'message', 'payment', 'comment'];

        foreach ($tokens as $token)
        {
            if (in_array($token, $write, TRUE)
                AND ! ($read_prefix AND in_array($token, $ambiguous_after_read, TRUE)))
            {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Признаки инструмента: меняет ли данные и стоит ли денег.
     *
     * Правило после теста 24.10.2026: лучше `unknown`, чем уверенная ложь.
     * `sign_up` (создаёт аккаунт и выдаёт секрет) считался `mutating: false`
     * и `cost: free` — а на этих флагах держится совет «перед пишущим вызовом
     * сделай dry_run». Источник истины — аннотации самого сервера
     * (`readOnlyHint` / `destructiveHint`), эвристика — только запасной вариант
     * и только в сторону осторожности.
     */
    protected static function tool_flags(array $tool, $name, $description)
    {
        $annotations = is_array($tool['annotations'] ?? NULL) ? $tool['annotations'] : [];
        $mutating = NULL;
        $source = 'unknown';

        if (array_key_exists('readOnlyHint', $annotations))
        {
            $mutating = ! self::_truthy($annotations['readOnlyHint']);
            $source = 'annotation';
        }
        elseif (array_key_exists('destructiveHint', $annotations) AND self::_truthy($annotations['destructiveHint']))
        {
            $mutating = TRUE;
            $source = 'annotation';
        }

        if ($mutating === NULL AND self::is_mutating($name, $description))
        {
            // Эвристика умеет уверенно сказать «пишет», но не «не пишет»:
            // «false» без аннотации — это догадка, а догадка здесь дороже
            // неопределённости.
            $mutating = TRUE;
            $source = 'name';
        }

        return [
            'mutating' => $mutating,
            'mutating_source' => $source,
            'cost' => self::cost_hint($name, $description),
        ];
    }

    /** Значение аннотации как boolean: true / 'true' / 1. */
    protected static function _truthy($value)
    {
        if (is_bool($value))
        {
            return $value;
        }

        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], TRUE);
    }

    /**
     * Платность конкретного инструмента.
     *
     * Раньше «price» в описании делал платным любой инструмент, и бесплатный
     * `list_products` у openweathermap помечался `paid` (тест 24.10.2026).
     * Теперь сначала смотрим явные признаки бесплатности, затем — только
     * сильные признаки платности; «price/pricing» к ним не относятся: это
     * обычное слово в описании бесплатного справочника.
     */
    protected static function cost_hint($name, $description = '')
    {
        $text = (string) $name.' '.(string) $description;

        // Только ЯВНОЕ упоминание денег. Раньше по умолчанию возвращалось
        // «free», и агент читал «бесплатно» там, где никто ничего не обещал
        // (тест 24.10.2026: `cost: free` у платного аккаунта). Неизвестно —
        // так и говорим.
        if (preg_match('/\b(paid|billing|subscription|stripe|tariff|premium|paywall|'
            .'per call|per request|credits? required|requires? (a )?(key|token|plan))\b/i', $text)
            OR preg_match('/\b(платн|подписк|тариф|абонентск)/iu', $text))
        {
            return 'paid';
        }

        if (preg_match('/\b(free|free tier|no charge|no cost|open data|public data|no api key)\b/i', $text)
            OR preg_match('/\b(бесплатн|без ключа|без регистрации)/iu', $text))
        {
            return 'free';
        }

        return 'unknown';
    }

    /**
     * Аргументы вызова из запроса. Слабая модель иногда присылает их JSON-строкой
     * («{"city":"Дубна"}») — принимаем и такой вид, это дешевле, чем ошибка.
     */
    protected static function arguments_of(array $args)
    {
        $arguments = $args['arguments'] ?? [];

        if (is_string($arguments))
        {
            $decoded = json_decode($arguments, TRUE);
            $arguments = is_array($decoded) ? $decoded : [];
        }

        return is_array($arguments) ? $arguments : [];
    }

    /**
     * Пример аргументов по схеме: агенту не нужно угадывать типы.
     *
     * Пустой набор возвращается объектом `{}`, а не `[]`: для JSON Schema
     * `arguments` — объект, и слабая модель копирует форму буквально.
     */
    public static function example_arguments(array $schema)
    {
        $examples = [
            'string' => '…', 'integer' => 0, 'number' => 0, 'boolean' => FALSE,
            'array' => [], 'object' => new stdClass(),
        ];

        $out = [];
        $properties = is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [];
        $required = array_map('strval', (array) ($schema['required'] ?? []));

        foreach ($properties as $name => $sub)
        {
            if ( ! in_array((string) $name, $required, TRUE))
            {
                continue;
            }

            $type = is_array($sub) ? (string) ($sub['type'] ?? 'string') : 'string';
            if (is_array($sub) AND isset($sub['enum']) AND is_array($sub['enum']) AND $sub['enum'] !== [])
            {
                $out[(string) $name] = reset($sub['enum']);
                continue;
            }

            $out[(string) $name] = $examples[$type] ?? '…';
        }

        return $out === [] ? new stdClass : $out;
    }

    /** Семейство серверов: имя без служебных слов — для группировки клонов. */
    protected static function family_key($name)
    {
        $name = mb_strtolower(trim((string) $name));
        $name = preg_replace('/[^a-zа-яё0-9]+/u', ' ', $name) ?? '';
        $tokens = [];

        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $token)
        {
            if ($token === '' OR in_array($token, ['mcp', 'server', 'api', 'ai', 'cloud', 'io', 'app', 'tool'], TRUE))
            {
                continue;
            }

            $tokens[] = $token;

            if (count($tokens) >= 2)
            {
                break;
            }
        }

        return implode('-', $tokens);
    }

    /** «2 ч назад» для объяснения ранжирования. */
    protected static function _age_text($timestamp)
    {
        $age = max(0, time() - (int) $timestamp);

        if ($age < 3600)
        {
            return max(1, (int) floor($age / 60)).' мин назад';
        }
        if ($age < 86400)
        {
            return (int) floor($age / 3600).' ч назад';
        }

        return (int) floor($age / 86400).' дн. назад';
    }

    // ---------------------------------------------------------------------
    // hub_call_tool
    // ---------------------------------------------------------------------

    /**
     * «Сухой прогон»: показать, что произойдёт, не выполняя вызов.
     *
     * Нужен для пишущих инструментов (`mutating`) и платных (`cost: paid`):
     * агент проверяет готовность, аргументы и побочные эффекты до траты
     * кредитов и до изменения данных.
     */
    public static function dry_run_plan(array $args, array $ctx)
    {
        $slug = trim(self::_string($args['server'] ?? ''));
        $tool = trim(self::_string($args['tool'] ?? ''));
        $arguments = self::arguments_of($args);

        $server = $slug !== ''
            ? Service_Nj676_McpHub::get($slug)
            : ($tool !== '' ? self::resolve_tool($tool) : NULL);

        $blockers = [];
        $schema = ['type' => 'object', 'properties' => []];
        $mutating = FALSE;
        $cost = 'free';
        $description = '';
        $is_platform = FALSE;

        if ( ! $server)
        {
            $visible = class_exists('Service_Nj160_Registry')
                ? Service_Nj160_Registry::tools_for((bool) $ctx['is_admin'])
                : [];

            if ($tool === '' OR ! isset($visible[$tool]))
            {
                return self::error('Не нашёл, что проверять: передайте server + tool или tool платформы.',
                    'TOOL_NOT_FOUND', ['tool' => $tool, 'server' => $slug,
                        'hint' => 'Список — hub_search_tools.']);
            }

            $is_platform = TRUE;
            $schema = (array) ($visible[$tool]['inputSchema'] ?? $schema);
            $description = mb_substr((string) ($visible[$tool]['description'] ?? ''), 0, 300);
            $mutating = self::is_mutating($tool, $description);
            $cost = self::cost_hint($tool, $description);

            if ( ! empty($ctx['anonymous']) OR empty($ctx['token_hash']))
            {
                $blockers[] = 'инструмент платформы N2J вызывается по ключу с /mcp';
            }
        }
        else
        {
            $known = self::compact_tools((array) $server['tools'], 20);

            if ($tool === '' AND count($known) === 1)
            {
                $tool = $known[0]['name'];
            }

            if ($tool === '')
            {
                $blockers[] = $known === []
                    ? 'схема инструментов сервера ещё не собрана'
                    : 'у сервера несколько инструментов — укажите tool';
            }

            $found = $tool !== '' ? self::find_tool((array) $server['tools'], $tool) : NULL;

            if ($found)
            {
                $compact = self::compact_tools([$found], 1);
                $compact = $compact[0];
                $mutating = $compact['mutating'];
                $cost = $compact['cost'];
                $description = $compact['description'];
                $schema = Service_Nj676_McpHub::schemas_as_objects([$found])[0]['inputSchema']
                    ?? $schema;
            }
            elseif ($tool !== '')
            {
                $blockers[] = 'у сервера нет инструмента «'.$tool.'»';
            }

            if ( ! $server['readiness']['ready'])
            {
                $blockers[] = 'готовность: '.$server['readiness']['readiness']
                    .($server['readiness']['note'] !== '' ? ' ('.$server['readiness']['note'].')' : '');
            }
            if ($server['install'] !== 'none')
            {
                $blockers[] = 'локальный сервер: нужна установка ('.$server['install'].')';
            }
            if (empty($server['is_remote']))
            {
                $blockers[] = 'сервер не удалённый — через шлюз не вызывается';
            }
        }

        // Обязательные аргументы проверяем так же, как при реальном вызове:
        // dry_run должен ловить именно ту ошибку, которая случится потом.
        $missing = [];
        foreach ((array) ($schema['required'] ?? []) as $required)
        {
            if ( ! array_key_exists((string) $required, $arguments))
            {
                $missing[] = (string) $required;
            }
        }
        if ($missing !== [])
        {
            $blockers[] = 'не хватает аргументов: '.implode(', ', $missing);
        }

        $call = ['tool' => $tool, 'arguments' => $arguments];
        if ( ! $is_platform AND isset($server['slug']))
        {
            $call = ['server' => (string) $server['slug']] + $call;
        }

        $lines = [
            'Проверка без вызова (dry_run)' ,
            ($is_platform ? 'Инструмент платформы N2J: ' : 'Сервер каталога: ').($server['slug'] ?? 'N2J').' › '.$tool,
            $description !== '' ? $description : '',
            $server ? 'Готовность: '.$server['readiness']['label'] : '',
            $mutating ? 'Инструмент меняет данные.' : '',
            $cost === 'paid' ? 'Инструмент платный (кредиты/подписка).' : '',
            $blockers === [] ? 'Вызов пройдёт: обязательные аргументы на месте.' : 'Вызов не пройдёт: '.implode('; ', $blockers).'.',
            'Реальный вызов: hub_call_tool '.json_encode($call, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $structured = [
            'ok' => TRUE,
            'dry_run' => TRUE,
            'would_call' => $call,
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'mutating' => $mutating,
            'cost' => $cost,
            'schema' => $schema,
            'missing' => $missing,
            'request_id' => self::request_id(),
        ];

        return [
            'content' => [['type' => 'text', 'text' => implode("\n", array_values(array_filter($lines, 'strlen')))]],
            'structuredContent' => (object) $structured,
        ];
    }

    public static function call_tool(array $args, array $ctx)
    {
        // dry_run ничего не исполняет — ни на сервере, ни у платформы.
        if ( ! empty($args['dry_run']))
        {
            return self::dry_run_plan($args, $ctx);
        }

        $server_slug = trim(self::_string($args['server'] ?? ''));
        $tool = trim(self::_string($args['tool'] ?? ''));
        $arguments = self::arguments_of($args);

        if ($server_slug !== '')
        {
            return self::proxy_remote($server_slug, $tool, $arguments, $ctx);
        }

        if ($tool === '')
        {
            return self::error('Передайте tool (сервис N2J или имя инструмента каталога) либо '
                .'server + tool (MCP-сервер каталога).',
                'INVALID_ARGUMENT', [
                    'field' => 'tool',
                    'expected' => 'tool или server + tool',
                    'hint' => 'Найдите нужное через hub_search_tools — в ответе будет готовая строка next.',
                ]);
        }

        if ( ! class_exists('Service_Nj160_Registry'))
        {
            return self::error('Реестр инструментов N2J недоступен.', 'UPSTREAM_ERROR');
        }

        $visible = Service_Nj160_Registry::tools_for((bool) $ctx['is_admin']);

        // Слабая модель помнит имя инструмента, но не slug сервера: ищем его
        // в каталоге MCP и вызываем там — это дешевле, чем заставлять агента
        // делать зондирующий вызов.
        if ( ! isset($visible[$tool]))
        {
            $server = self::resolve_tool($tool);

            if ($server)
            {
                return self::proxy_remote((string) $server['slug'], $tool, $arguments, $ctx);
            }

            // Платформенный инструмент закрыт анонимному агенту: он работает
            // от имени владельца ключа. Код ошибки должен это показывать.
            $is_platform = FALSE;
            try
            {
                $is_platform = isset(Service_Nj160_Registry::tools_for(FALSE)[$tool]);
            }
            catch (Throwable $e)
            {
                $is_platform = FALSE;
            }

            if ( ! empty($ctx['anonymous']) AND $is_platform)
            {
                return self::error('Инструмент «'.$tool.'» — сервис N2J, он доступен по ключу: выпустите его '
                    .'на /mcp и передайте Authorization: Bearer <ключ>.',
                    'AUTH_REQUIRED', ['tool' => $tool, 'hint' => 'Ключ выпускается на странице /mcp (не на самом эндпоинте); без ключа работают серверы '
                        .'каталога без авторизации.']);
            }

            return self::error('Инструмента «'.$tool.'» нет ни среди сервисов N2J, ни в каталоге MCP-серверов.',
                'TOOL_NOT_FOUND', ['tool' => $tool, 'hint' => 'Найдите подходящий через hub_search_tools — '
                    .'по описанию задачи, а не по имени.']);
        }

        // Анонимному агенту платформенные инструменты с ключом не вызовутся:
        // отвечаем сразу понятным кодом, а не «ошибкой инструмента» изнутри.
        if ( ! empty($ctx['anonymous']) AND ! empty($visible[$tool]['needs_key']))
        {
            return self::error('Инструмент «'.$tool.'» — сервис N2J и работает от имени владельца ключа.',
                'AUTH_REQUIRED', ['tool' => $tool, 'hint' => 'Ключ выдаётся на /mcp; '
                    .'без ключа доступны серверы каталога без авторизации.']);
        }

        $schema = (array) ($visible[$tool]['inputSchema'] ?? []);
        $missing = [];
        $props = (array) ($schema['properties'] ?? []);

        foreach ((array) ($schema['required'] ?? []) as $required)
        {
            if ( ! array_key_exists((string) $required, $arguments))
            {
                $missing[] = (string) $required;
            }
        }

        if ($missing !== [])
        {
            $hint = [];
            foreach ($missing as $field)
            {
                $hint[] = $field.(isset($props[$field]['description']) ? ' ('.$props[$field]['description'].')' : '');
            }

            return self::error('Не хватает аргументов: '.implode(', ', $hint).'.',
                'INVALID_ARGUMENT', [
                    'field' => implode(', ', $missing),
                    'expected' => 'arguments.'.implode(', arguments.', $missing),
                    'hint' => 'Повторите hub_call_tool с этими аргументами; полная схема — hub_describe_tool.',
                    'missing' => $missing,
                ]);
        }

        $t0 = microtime(TRUE);

        try
        {
            $output = Service_Nj160_Registry::call($tool, $arguments, (bool) $ctx['is_admin'], $ctx['user_id']);
        }
        catch (Kohana_Exception $e)
        {
            Service_Nj676_Telemetry::record('', $tool, FALSE, (int) round((microtime(TRUE) - $t0) * 1000),
                'UPSTREAM_ERROR', $ctx['token_hash'] ?? NULL);

            return self::error('Ошибка инструмента: '.$e->getMessage(), 'UPSTREAM_ERROR',
                ['tool' => $tool, 'hint' => 'Проверьте аргументы через hub_describe_tool и повторите.']);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj676: hub_call_tool :t: :e',
                [':t' => $tool, ':e' => $e->getMessage()]);
            Service_Nj676_Telemetry::record('', $tool, FALSE, (int) round((microtime(TRUE) - $t0) * 1000),
                'UPSTREAM_ERROR', $ctx['token_hash'] ?? NULL);

            return self::error('Инструмент упал: '.$e->getMessage(), 'UPSTREAM_ERROR', ['tool' => $tool]);
        }

        $ms = (int) round((microtime(TRUE) - $t0) * 1000);
        $ok = empty($output['error']);
        $text = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $max = (int) Kohana::$config->load('nj160_mcp.max_result_chars');
        $truncated = FALSE;

        if ($max > 0 AND strlen($text) > $max)
        {
            $text = substr($text, 0, $max);
            $truncated = TRUE;
        }

        $call_id = Service_Nj676_Telemetry::record('', $tool, $ok, $ms, $ok ? '' : 'UPSTREAM_ERROR',
            $ctx['token_hash'] ?? NULL);

        $structured = [
            'ok' => $ok,
            'tool' => $tool,
            'call_id' => $call_id,
            'latency_ms' => $ms,
            'truncated' => $truncated,
            'result' => is_array($output) ? $output : ['value' => $output],
            'next' => 'Оценить вызов: hub_feedback {"call_id":"'.$call_id.'","rating":"up|down"}',
        ];

        $result = [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => (object) Service_Nj676_McpHub::schemas_as_objects($structured),
        ];

        if ( ! $ok)
        {
            $result['isError'] = TRUE;
        }

        return $result;
    }

    /**
     * Проксирование вызова в удалённый MCP-сервер из каталога.
     *
     * Работает только с HTTPS-адресом опубликованной карточки: агент не может
     * подсунуть произвольный URL (это была бы SSRF-дыра).
     */
    public static function proxy_remote($slug, $tool, array $arguments, array $ctx)
    {
        $server = Service_Nj676_McpHub::get((string) $slug);

        if ( ! $server)
        {
            return self::error('Сервера «'.$slug.'» нет в опубликованном каталоге.', 'TOOL_NOT_FOUND',
                ['server' => (string) $slug, 'hint' => 'Slug берётся из hub_search_tools.']);
        }

        if ( ! $server['is_remote'] OR $server['remote_url'] === '')
        {
            $config = Service_Nj676_McpHub::config_snippet($server);

            return self::error(
                'Это локальный MCP-сервер (stdio): на сервере N2J он не запускается. '
                .'Готовая конфигурация для клиента: '
                .($config !== '' ? "\n".$config : 'пока недоступна — нужна закреплённая версия пакета.'),
                'INVALID_ARGUMENT', [
                    'server' => (string) $slug,
                    'hint' => 'Локальные серверы вызываются после установки у вас; удалённые — сразу через шлюз.',
                ]
            );
        }

        $has_server_token = self::remote_token($server) !== '';
        $is_optional_auth = ((string) ($server['auth'] ?? '') === 'optional')
            || Service_Nj676_McpHub::is_optional_auth($server);

        if ( ! empty($ctx['anonymous'])
            AND ! $has_server_token
            AND ! $is_optional_auth
            AND empty($server['readiness']['ready'])
            AND ! in_array((string) $server['auth_mode'], ['none', 'optional'], TRUE))
        {
            return self::error('Сервер «'.$slug.'» требует авторизации ('.$server['auth_label'].'). '
                .'Анонимно вызывать можно только серверы без ключа; персональный ключ выпускается на странице /mcp.',
                'AUTH_REQUIRED', ['server' => (string) $slug, 'auth_scope' => 'gateway',
                    'hint' => 'Ищите серверы с readiness=ready.']);
        }

        // Для серверов в режиме auth: optional (например, ai-echoloc-company-technographics)
        // разрешаем анонимный preview-вызов, но ограничиваем квоту на шлюзе,
        // чтобы не выжигать бесплатный лимит апстрима.
        if ( ! empty($ctx['anonymous']) AND ! $has_server_token AND $is_optional_auth)
        {
            $preview_error = self::check_preview_quota((string) $slug, $ctx);
            if ($preview_error !== NULL)
            {
                return $preview_error;
            }
        }

        if ( ! self::public_https_url($server['remote_url']))
        {
            return self::error('Адрес сервера не прошёл проверку безопасности (HTTPS и публичный хост).',
                'UPSTREAM_ERROR', ['server' => (string) $slug]);
        }

        // Имя инструмента необязательно, когда он один: лишний зондирующий
        // вызов «угадай имя» — ровно то, на что жаловались агенты.
        if (trim((string) $tool) === '')
        {
            $known = self::compact_tools((array) $server['tools'], 20);

            if (count($known) === 1)
            {
                $tool = $known[0]['name'];
            }
            else
            {
                $names = array_map(static function ($item) { return $item['name']; }, $known);

                if ($names === [])
                {
                    $live = self::remote_tools((string) $slug, FALSE);
                    $names = array_map(static function ($item) { return (string) $item['name']; }, $live['tools']);

                    if (count($names) === 1)
                    {
                        $tool = $names[0];
                    }
                }

                if (trim((string) $tool) === '')
                {
                    return self::error('Укажите tool: у сервера «'.$slug.'» не один инструмент. Доступно: '
                        .($names !== [] ? implode(', ', array_slice($names, 0, 20)) : 'список получить не удалось'),
                        'INVALID_ARGUMENT', [
                            'server' => (string) $slug,
                            'field' => 'tool',
                            'available_tools' => array_slice($names, 0, 20),
                            'hint' => 'Полная схема — hub_describe_tool {"server":"'.$slug.'","tool":"…"}.',
                        ]);
                }
            }
        }

        $t0 = microtime(TRUE);

        $answer = Service_Nj676_McpClient::call_tool($server['remote_url'], (string) $tool, $arguments, [
            'timeout' => self::PROXY_TIMEOUT,
            'token' => self::remote_token($server),
        ]);

        // Одна повторная попытка для ЧИТАЮЩИХ инструментов при сетевом сбое:
        // публичные MCP-серверы за CDN часто рвут первое соединение, и агент
        // получает ошибку там, где второй запрос проходит. Пишущие не
        // повторяем: двойное создание записи дороже неудачного вызова.
        $failure = (string) ($answer['error'] ?? '');
        $transient = ( ! $answer['ok']
            AND (stripos($failure, 'сеть:') === 0 OR stripos($failure, 'HTTP 5') === 0)
            AND ! self::is_mutating((string) $tool));

        if ($transient)
        {
            usleep(300000);

            $retry = Service_Nj676_McpClient::call_tool($server['remote_url'], (string) $tool, $arguments, [
                'timeout' => self::PROXY_TIMEOUT,
                'token' => self::remote_token($server),
            ]);

            if ($retry['ok'])
            {
                $answer = $retry;
                $answer['retried'] = TRUE;
            }
        }

        $ms = max(0, (int) ($answer['ms'] ?? round((microtime(TRUE) - $t0) * 1000)));

        if ( ! $answer['ok'])
        {
            Kohana::$log->add(Log::WARNING, 'nj676: вызов :s/:t не удался: :r',
                [':s' => (string) $slug, ':t' => (string) $tool, ':r' => $answer['error']]);

            $code = self::upstream_code((string) $answer['error']);
            $retry_after = (int) ($answer['retry_after'] ?? 0);

            if ($code === 'RATE_LIMITED' AND $retry_after <= 0)
            {
                // Сервер не сказал «когда», но лимит есть: даём разумную паузу.
                $retry_after = 30;
            }

            // «Недоступен» ставим только когда сломалась сеть или протокол.
            // Скобки обязательны: `OR` связывает слабее `=`.
            $network = ($code === 'UPSTREAM_TIMEOUT'
                OR strpos($answer['error'], 'HTTP ') === 0
                OR strpos($answer['error'], 'требует авторизации') !== FALSE);

            if ($network)
            {
                Service_Nj676_McpHub::apply_probe((string) $slug, FALSE, 0, 'вызов не прошёл: '.$answer['error']);
            }

            // Сервер потребовал ключ — запоминаем в карточке. Иначе витрина
            // «Отвечают прямо сейчас» и hub_ready продолжали обещать вызов,
            // который гарантированно вернёт AUTH_REQUIRED (temporal-mcp).
            if ($code === 'AUTH_REQUIRED')
            {
                Service_Nj676_McpHub::mark_auth_required((string) $slug);
            }

            $call_id = Service_Nj676_Telemetry::record((string) $slug, (string) $tool, FALSE, $ms, $code,
                $ctx['token_hash'] ?? NULL);

            return self::error('Удалённый сервер «'.$slug.'» не ответил: '.$answer['error'], $code, [
                'server' => (string) $slug,
                'tool' => (string) $tool,
                'call_id' => $call_id,
                'latency_ms' => $ms,
                'retry_after' => $retry_after > 0 ? $retry_after : NULL,
                'hint' => $code === 'UPSTREAM_TIMEOUT'
                    ? 'Сервер медленный. Повторите вызов или выберите другой сервер из hub_search_tools.'
                    : ($code === 'RATE_LIMITED'
                        ? 'Сервер ограничивает частоту: подождите '.$retry_after.' с и повторите, '
                            .'либо выберите другой сервер.'
                        : 'Проверьте аргументы через hub_describe_tool; если повторяется — сервер помечен недоступным.'),
            ]);
        }

        $result = $answer['result'];
        $text = self::flatten_result($result);

        if ($text === '')
        {
            $text = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $truncated = FALSE;
        if (strlen($text) > self::PROXY_MAX_BYTES)
        {
            $text = substr($text, 0, self::PROXY_MAX_BYTES);
            $truncated = TRUE;
        }

        // Удачный вызов — тоже сигнал живости, но проверку не подменяет:
        // health_checked_at обновляем только когда реально звали сервер.
        // last_ok_at — отдельная отметка «этот сервер реально сработал»: по ней
        // карточка не объявляется сломанной из-за одной сорвавшейся пробы.
        Service_Nj676_McpHub::apply_probe((string) $slug, TRUE, $ms);
        Service_Nj676_McpHub::mark_call_ok((string) $slug);
        // Успешный вызов доказывает, что ключ не нужен (или уже настроен):
        // старая отметка «требует авторизации» больше не правдива.
        Service_Nj676_McpHub::clear_auth_required((string) $slug);

        $is_error = ! empty($result['isError']);
        $error_code = $is_error ? self::upstream_code($text) : '';

        if ($error_code === 'AUTH_REQUIRED')
        {
            Service_Nj676_McpHub::mark_auth_required((string) $slug);
        }

        // Отказ по аргументам — не сбой сервера. Тест 24.10.2026: агент дважды
        // прислал неверный запрос, и open-meteo упал до 60% успеха, хотя был
        // полностью исправен. Такие вызовы пишем с кодом INVALID_ARGUMENT и
        // не считаем в надёжности (см. Service_Nj676_Telemetry).
        if ($is_error AND self::looks_like_validation_error($text))
        {
            $error_code = 'INVALID_ARGUMENT';
        }

        $call_id = Service_Nj676_Telemetry::record((string) $slug, (string) $tool, ! $is_error, $ms,
            $error_code, $ctx['token_hash'] ?? NULL);

        $structured = [
            'ok' => ! $is_error,
            'server' => (string) $slug,
            'tool' => (string) $tool,
            'call_id' => $call_id,
            'latency_ms' => $ms,
            'truncated' => $truncated,
            'retried' => ! empty($answer['retried']),
            'result' => $result,
            'next' => 'Оценить вызов: hub_feedback {"call_id":"'.$call_id.'","rating":"up|down"}',
        ];

        $text_out = $text;

        if ($is_error)
        {
            // Отказ сервера нормализуем так же, как свои ошибки: агенту нужен
            // code/hint/retryable, а не сырой текст апстрима («Input validation
            // error… request UTDWN-4TNNH»). Свой request_id у нас уже есть, а
            // чужой только путает.
            $norm = self::normalize_upstream_error($text, $error_code);

            $structured['code'] = $norm['code'];
            $structured['hint'] = $norm['hint'];
            $structured['retryable'] = $norm['retryable'];
            $structured['upstream_message'] = $norm['message'];
            $structured['next'] = $norm['next'];

            $text_out = 'Ошибка: '.$norm['code'].'. '.$norm['message']
                ."\nПодсказка: ".$norm['hint']
                ."\nПовторять: ".($norm['retryable'] ? 'да' : 'нет')
                ."\ncall_id для отзыва: ".$call_id
                ."\nrequest_id: ".self::request_id()
                ."\n--- ответ сервера ---\n".$text;
        }

        $out = [
            'content' => [['type' => 'text', 'text' => $text_out.($truncated
                ? "\n… (ответ обрезан, ".self::PROXY_MAX_BYTES." байт)" : '')]],
            'structuredContent' => (object) Service_Nj676_McpHub::schemas_as_objects($structured),
        ];

        if ($is_error)
        {
            $out['isError'] = TRUE;
        }

        return $out;
    }

    /**
     * Ошибка удалённого сервера → стабильные code/hint/retryable.
     *
     * Апстримы отвечают как угодно: «Input validation error: ... request
     * UTDWN-4TNNH», «401 Unauthorized», HTML-страница шлюза. Агент по такому
     * тексту не может решить, что делать. Здесь мы переводим ответ в наш
     * словарь и говорим, повторять ли вызов.
     *
     * @return array{code:string,hint:string,retryable:bool,message:string,next:string}
     */
    public static function normalize_upstream_error($text, $code = '')
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        $code = (string) $code;
        $lower = mb_strtolower($text);

        if ($code === '')
        {
            $code = self::upstream_code($text);
        }

        if ($code !== 'INVALID_ARGUMENT' AND self::looks_like_validation_error($text))
        {
            $code = 'INVALID_ARGUMENT';
        }

        // Текст показываем коротко: полный ответ уходит ниже, в «ответ сервера».
        $message = $text;
        if (mb_strlen($message) > 300)
        {
            $message = mb_substr($message, 0, 300).'…';
        }

        $hints = [
            'INVALID_ARGUMENT' => 'Аргументы не прошли проверку на стороне сервера. '
                .'Возьмите схему через hub_describe_tool и повторите с корректными полями; '
                .'сервер при этом исправен.',
            'AUTH_REQUIRED' => 'Этому серверу нужен ключ, у шлюза его нет. '
                .'Либо настройте ключ (hub_configure), либо выберите сервер без авторизации: '
                .'hub_search_tools {"only_ready":true}.',
            'UPSTREAM_TIMEOUT' => 'Сервер не ответил за отведённое время. '
                .'Повторите один раз; если снова таймаут — возьмите другой сервер.',
            'RATE_LIMITED' => 'Сервер ограничил частоту. Подождите и повторите; '
                .'если нужно сейчас — hub_search_tools подберёт замену.',
            'SCHEMA_INVALID' => 'Сервер отдал некорректную схему инструмента. '
                .'Выберите другой инструмент: hub_search_tools с тем же запросом.',
            'UPSTREAM_ERROR' => 'Сервер вернул ошибку. Повторите один раз, '
                .'затем возьмите другой сервер или сообщите через hub_feedback.',
        ];

        $retryable = in_array($code, ['UPSTREAM_TIMEOUT', 'UPSTREAM_ERROR', 'RATE_LIMITED'], TRUE);

        return [
            'code' => in_array($code, self::ERROR_CODES, TRUE) ? $code : 'UPSTREAM_ERROR',
            'hint' => $hints[$code] ?? $hints['UPSTREAM_ERROR'],
            'retryable' => $retryable,
            'message' => $message,
            'next' => $retryable
                ? 'Повторить вызов один раз; если не помогло — hub_search_tools без only_ready, чтобы найти замену'
                : 'Исправить аргументы через hub_describe_tool и повторить',
        ];
    }

    /**
     * Похоже ли сообщение об ошибке на отказ по аргументам.
     *
     * Такие ответы приходят как обычный результат с `isError: true`:
     * «missing required argument», «invalid params», «required field city».
     * Это ошибка клиента, сервер при этом полностью работоспособен.
     */
    protected static function looks_like_validation_error($text)
    {
        return (bool) preg_match(
            '/\b(missing|required|invalid|unexpected|unknown|extra)\b[^.]{0,40}'
            .'\b(arguments?|params?|parameters?|fields?|values?|property|properties|input)\b'
            .'/iu',
            (string) $text)
            OR (bool) preg_match('/\b(неверн|некорректн|обязательн|не хватает|отсутству)\w*\b/iu', (string) $text);
    }

    public static function upstream_code($error)
    {
        $error = mb_strtolower((string) $error);

        // Сетевой сбой (DNS, TLS) — не таймаут: коды разные, и агент по-разному
        // на них реагирует (повтор бессмысленен против сломанного TLS).
        if (strpos($error, 'таймаут') !== FALSE OR strpos($error, 'timeout') !== FALSE
            OR strpos($error, 'timed out') !== FALSE)
        {
            return 'UPSTREAM_TIMEOUT';
        }

        // Английские формулировки — не украшение. Раньше распознавались только
        // «авторизац» и коды 401/403, поэтому «OAuth authorization required»
        // и «authentication needed» уходили в UPSTREAM_ERROR: карточка не
        // помечалась, и витрина продолжала обещать вызов (temporal-mcp).
        if (strpos($error, 'авторизац') !== FALSE OR strpos($error, '401') !== FALSE
            OR strpos($error, '403') !== FALSE
            OR strpos($error, 'unauthorized') !== FALSE
            OR strpos($error, 'unauthenticated') !== FALSE
            OR strpos($error, 'authenticat') !== FALSE
            OR strpos($error, 'authoriz') !== FALSE
            OR strpos($error, 'forbidden') !== FALSE
            OR strpos($error, 'access denied') !== FALSE
            OR strpos($error, 'api key required') !== FALSE
            OR strpos($error, 'oauth') !== FALSE)
        {
            return 'AUTH_REQUIRED';
        }
        if (strpos($error, '429') !== FALSE OR strpos($error, 'rate limit') !== FALSE
            OR strpos($error, 'too many requests') !== FALSE)
        {
            return 'RATE_LIMITED';
        }
        if (strpos($error, 'схем') !== FALSE OR strpos($error, 'schema') !== FALSE)
        {
            return 'SCHEMA_INVALID';
        }

        return 'UPSTREAM_ERROR';
    }

    // ---------------------------------------------------------------------
    // hub_describe_tool
    // ---------------------------------------------------------------------

    /**
     * Полная схема инструмента и пример вызова.
     *
     * Зачем: агент трижды подбирал имена аргументов (`repo` / `repoName`) —
     * потому что в выдаче была только строка «инструменты: …». Здесь он
     * получает JSON Schema целиком и готовый вызов, без экспериментов.
     */
    public static function describe_tool(array $args, array $ctx)
    {
        $slug = trim(self::_string($args['server'] ?? ''));
        $tool = trim(self::_string($args['tool'] ?? ''));

        // Без tool, но с server — это не ошибка: показываем список инструментов
        // сервера. Так строка next из поиска всегда валидна, а агенту не нужно
        // угадывать имя инструмента до того, как он его увидел.
        if ($tool === '' AND $slug !== '')
        {
            return self::describe_server($slug);
        }

        if ($tool === '')
        {
            return self::error('Передайте tool — имя инструмента из hub_search_tools — или server, чтобы увидеть список.',
                'INVALID_ARGUMENT', ['field' => 'tool',
                    'hint' => 'Например: hub_describe_tool {"server":"openweathermap-mcp"}.']);
        }

        $server = $slug !== '' ? Service_Nj676_McpHub::get($slug, TRUE) : self::resolve_tool($tool);

        if ( ! $server)
        {
            $platform = self::describe_platform_fallback($tool, $slug, $ctx);
            if ($platform !== NULL)
            {
                return $platform;
            }

            return self::error($slug !== ''
                    ? 'Сервера «'.$slug.'» нет в опубликованном каталоге.'
                    : 'Инструмента «'.$tool.'» нет в каталоге.',
                'TOOL_NOT_FOUND', ['server' => $slug, 'tool' => $tool,
                    'hint' => 'Сначала найдите сервер через hub_search_tools.']);
        }

        $found = self::find_tool((array) $server['tools'], $tool);

        // Схема могла появиться после последней проверки: один живой
        // tools/list дешевле, чем «инструмент не найден» у живого сервера.
        if ( ! $found AND $server['is_remote'] AND $server['remote_url'] !== '')
        {
            $live = self::remote_tools((string) $server['slug'], FALSE);
            $found = self::find_tool((array) $live['tools'], $tool);
            if ($found)
            {
                $server = Service_Nj676_McpHub::get((string) $server['slug'], TRUE) ?: $server;
            }
        }

        if ( ! $found)
        {
            $platform = self::describe_platform_fallback($tool, $slug, $ctx);
            if ($platform !== NULL)
            {
                return $platform;
            }

            $known = array_map(static function ($item) { return (string) ($item['name'] ?? ''); },
                array_slice((array) $server['tools'], 0, 20));

            return self::error('У сервера «'.$server['slug'].'» нет инструмента «'.$tool.'».',
                'TOOL_NOT_FOUND', [
                    'server' => (string) $server['slug'],
                    'tool' => $tool,
                    'available_tools' => array_values(array_filter($known)),
                    'hint' => $known !== [] ? 'Возможно, вы имели в виду один из available_tools.' : '',
                ]);
        }

        $compact = self::compact_tools([$found], 1);
        $compact = $compact[0];
        $schema = Service_Nj676_McpHub::schemas_as_objects([$found])[0]['inputSchema'] ?? ['type' => 'object', 'properties' => new stdClass()];
        $example = self::example_arguments([
            'properties' => (array) $found['inputSchema']['properties'] ?? [],
            'required' => (array) ($found['inputSchema']['required'] ?? []),
        ]);

        $call = [
            'server' => $server['slug'],
            'tool' => $compact['name'],
            'arguments' => $example,
        ];

        $meta_trust = self::server_trust_and_feedback($server);

        $lines = [
            $server['slug'].' › '.$compact['signature'],
            $compact['description'] !== '' ? $compact['description'] : '',
            'Готовность: '.$server['readiness']['readiness'].($server['readiness']['note'] !== ''
                ? ' ('.$server['readiness']['note'].')' : ''),
            'Доверие каталога: '.$meta_trust['trust'].' / 100 · Звёзд: '.$meta_trust['stars']
                .' · Статус: '.$meta_trust['health_status']
                .($meta_trust['calls'] > 0 ? ' · Успех: '.$meta_trust['success_rate'].'% / '.$meta_trust['calls'].' вызовов' : '')
                .($meta_trust['agent_rating'] !== NULL ? ' · Рейтинг агентов: ★ '.$meta_trust['agent_rating'].'/5 ('.$meta_trust['agent_reviews_count'].')' : ''),
            ($meta_trust['is_gem'] || $meta_trust['utility_score'] > 0)
                ? 'Исследование агентом: '.($meta_trust['is_gem'] ? '💎 Алмаз каталога · ' : '')
                    .'польза '.$meta_trust['utility_score'].'/100 · безопасность '.$meta_trust['safety_score'].'/100'
                    .($meta_trust['audit_summary'] !== '' ? ' — '.$meta_trust['audit_summary'] : '')
                : '',
            $compact['mutating'] ? 'Внимание: инструмент меняет данные.' : '',
            $compact['cost'] === 'paid' ? 'Внимание: сервер платный (кредиты/подписка).' : '',
            'Обязательные аргументы: '.($compact['required'] !== [] ? implode(', ', $compact['required']) : 'нет'),
            'Полная схема: '.json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Пример вызова: hub_call_tool '.json_encode($call, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if ($meta_trust['recent_feedback'] !== [])
        {
            $fb_sum = $meta_trust['feedback_summary'];
            $lines[] = 'Отзывы агентов (всего '.$fb_sum['total'].': +'.$fb_sum['up'].' / −'.$fb_sum['down'].'):';
            foreach (array_slice($meta_trust['recent_feedback'], 0, 5) as $fb_item)
            {
                $fb_parts = [($fb_item['rating'] === 'up' ? '👍' : '👎').' '.$fb_item['rating']];
                if ($fb_item['tool'] !== '') { $fb_parts[] = $fb_item['tool']; }
                if ($fb_item['outcome'] !== '') { $fb_parts[] = $fb_item['outcome']; }
                if ($fb_item['tags'] !== []) { $fb_parts[] = '['.implode(', ', $fb_item['tags']).']'; }
                if ($fb_item['comment'] !== '') { $fb_parts[] = '«'.self::clean_remote_text($fb_item['comment'], 160).'»'; }
                $lines[] = '  • '.implode(' · ', $fb_parts);
            }
        }

        $structured = [
            'ok' => TRUE,
            'server' => $server['slug'],
            'tool' => $compact['name'],
            'description' => $compact['description'],
            'readiness' => $server['readiness']['readiness'],
            'health_status' => $meta_trust['health_status'],
            'trust' => $meta_trust['trust'],
            'stars' => $meta_trust['stars'],
            'latency_ms' => $server['readiness']['latency_ms'],
            'calls' => $meta_trust['calls'],
            'success_rate' => $meta_trust['success_rate'],
            'feedback_summary' => $meta_trust['feedback_summary'],
            'recent_feedback' => $meta_trust['recent_feedback'],
            'auth' => $server['auth'],
            'install' => $server['install'],
            'schema_valid' => $server['schema_valid'],
            'mutating' => $compact['mutating'],
            'cost' => $compact['cost'],
            'input_schema' => $schema,
            'example_arguments' => $example,
            'next' => 'hub_call_tool '.json_encode($call, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        return [
            'content' => [['type' => 'text', 'text' => implode("\n", array_values(array_filter($lines, 'strlen')))]],
            'structuredContent' => (object) Service_Nj676_McpHub::schemas_as_objects($structured),
        ];
    }

    /**
     * Поиск инструмента среди инструментов платформы N2J (при пустом slug).
     */
    protected static function describe_platform_fallback($tool, $slug, array $ctx)
    {
        if ($slug !== '' OR ! class_exists('Service_Nj160_Registry'))
        {
            return NULL;
        }

        $visible = Service_Nj160_Registry::tools_for( ! empty($ctx['is_admin']));

        if (isset($visible[$tool]))
        {
            return self::describe_platform($tool, (array) $visible[$tool], $ctx);
        }

        $public = Service_Nj160_Registry::tools_for(FALSE);
        if (isset($public[$tool]))
        {
            return self::error('Инструмент «'.$tool.'» — сервис N2J: описание и вызов доступны '
                .'по ключу с /mcp (Authorization: Bearer <ключ>).',
                'AUTH_REQUIRED', ['tool' => $tool,
                    'hint' => 'Без ключа работают серверы каталога без авторизации.']);
        }

        return NULL;
    }

    /**
     * Описание инструмента платформы N2J: та же форма ответа, что у серверов
     * каталога, чтобы агенту не приходилось различать два вида инструментов.
     */
    protected static function describe_platform($name, array $tool, array $ctx)
    {
        $schema = is_array($tool['inputSchema'] ?? NULL) ? $tool['inputSchema'] : ['type' => 'object'];
        $schema = Service_Nj676_McpHub::schemas_as_objects([
            ['name' => $name, 'inputSchema' => $schema],
        ])[0]['inputSchema'] ?? ['type' => 'object', 'properties' => new stdClass];

        $required = array_map('strval', (array) ($schema['required'] ?? []));
        $example = self::example_arguments([
            'properties' => (array) ($schema['properties'] ?? []),
            'required' => $required,
        ]);

        $needs_key = ! empty($tool['needs_key']);
        $readiness = ($needs_key AND empty($ctx['token_hash'])) ? 'needs_key' : 'ready';
        $description = trim((string) ($tool['description'] ?? ''));

        $call = [
            'tool' => $name,
            'arguments' => $example,
        ];

        $lines = [
            'N2J platform › '.$name,
            $description,
            'Готовность: '.($readiness === 'ready' ? 'ready' : 'needs_key (ключ с /mcp)'),
            $required !== [] ? 'Обязательные аргументы: '.implode(', ', $required) : 'Аргументы необязательны',
            'Вызов: hub_call_tool '.json_encode($call, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Проверить без исполнения: hub_call_tool {…, "dry_run":true}',
        ];

        $structured = [
            'ok' => TRUE,
            'kind' => 'platform',
            'server' => '',
            'server_label' => 'N2J platform',
            'tool' => $name,
            'description' => $description,
            'readiness' => $readiness,
            'auth' => $needs_key ? 'api_key' : 'none',
            'required' => $required,
            'schema' => $schema,
            'call' => $call,
            'next' => 'hub_call_tool '.json_encode($call, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        return [
            'content' => [['type' => 'text', 'text' => implode("\n", array_filter($lines,
                static function ($line) { return $line !== ''; }))]],
            'structuredContent' => (object) $structured,
        ];
    }

    public static function describe_server($slug)
    {
        $server = Service_Nj676_McpHub::get((string) $slug);

        if ( ! $server)
        {
            return self::error('Сервера «'.$slug.'» нет в опубликованном каталоге.', 'TOOL_NOT_FOUND',
                ['server' => (string) $slug, 'hint' => 'Slug берётся из hub_search_tools.']);
        }

        $tools = self::compact_tools((array) $server['tools'], 20);

        if ($tools === [] AND $server['is_remote'])
        {
            $live = self::remote_tools((string) $slug, FALSE);
            $tools = self::compact_tools((array) $live['tools'], 20);
        }

        // У больших серверов инструментов больше, чем влезает в ответ: схему
        // показываем для первых 20, но ИМЕНА перечисляем все — иначе агент
        // считает, что нужного инструмента у сервера нет.
        $all_names = [];
        foreach ((array) $server['tools'] as $item)
        {
            $name = is_array($item) ? trim((string) ($item['name'] ?? '')) : '';

            if ($name !== '')
            {
                $all_names[] = $name;
            }
        }
        $all_names = array_slice(array_values(array_unique($all_names)), 0, 200);

        if ($tools === [])
        {
            return self::error('Схема инструментов «'.$slug.'» пока пуста: сервер не отвечал на tools/list.',
                'SCHEMA_INVALID', [
                    'server' => (string) $slug,
                    'hint' => 'Сервер не прошёл проверку. Выберите другой из hub_search_tools.',
                ]);
        }

        $meta_trust = self::server_trust_and_feedback($server);

        $lines = [
            'Сервер '.$server['slug'].' · готовность: '.$server['readiness']['readiness']
                .($server['readiness']['note'] !== '' ? ' ('.$server['readiness']['note'].')' : ''),
            'Доверие каталога: '.$meta_trust['trust'].' / 100 · Звёзд: '.$meta_trust['stars']
                .' · Статус: '.$meta_trust['health_status']
                .($meta_trust['calls'] > 0 ? ' · Успех: '.$meta_trust['success_rate'].'% / '.$meta_trust['calls'].' вызовов' : ''),
            '',
        ];

        foreach ($tools as $compact)
        {
            $lines[] = '• '.$compact['signature'].' — '.$compact['description'];
            if ($compact['mutating'])
            {
                $lines[] = '  меняет данные';
            }
            if ($compact['cost'] === 'paid')
            {
                $lines[] = '  платный';
            }
        }

        if ($meta_trust['recent_feedback'] !== [])
        {
            $fb_sum = $meta_trust['feedback_summary'];
            $lines[] = '';
            $lines[] = 'Отзывы агентов (всего '.$fb_sum['total'].': +'.$fb_sum['up'].' / −'.$fb_sum['down'].'):';
            foreach (array_slice($meta_trust['recent_feedback'], 0, 5) as $fb_item)
            {
                $fb_parts = [($fb_item['rating'] === 'up' ? '👍' : '👎').' '.$fb_item['rating']];
                if ($fb_item['tool'] !== '') { $fb_parts[] = $fb_item['tool']; }
                if ($fb_item['outcome'] !== '') { $fb_parts[] = $fb_item['outcome']; }
                if ($fb_item['tags'] !== []) { $fb_parts[] = '['.implode(', ', $fb_item['tags']).']'; }
                if ($fb_item['comment'] !== '') { $fb_parts[] = '«'.self::clean_remote_text($fb_item['comment'], 160).'»'; }
                $lines[] = '• '.implode(' · ', $fb_parts);
            }
        }

        $next = 'hub_describe_tool '.json_encode(['server' => $server['slug'], 'tool' => $tools[0]['name']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $shown = array_map(static function ($compact) { return $compact['name']; }, $tools);
        $rest = array_values(array_diff($all_names, $shown));

        if ($rest !== [])
        {
            $lines[] = '';
            $lines[] = 'Ещё '.count($rest).' инструментов (полная схема — hub_describe_tool): '
                .implode(', ', array_slice($rest, 0, 40))
                .(count($rest) > 40 ? ' …' : '');
        }

        $lines[] = '';
        $lines[] = 'next: '.$next;

        $structured = [
            'ok' => TRUE,
            'server' => $server['slug'],
            'readiness' => $server['readiness']['readiness'],
            'health_status' => $meta_trust['health_status'],
            'trust' => $meta_trust['trust'],
            'stars' => $meta_trust['stars'],
            'latency_ms' => $server['readiness']['latency_ms'],
            'calls' => $meta_trust['calls'],
            'success_rate' => $meta_trust['success_rate'],
            'agent_rating' => $meta_trust['agent_rating'],
            'agent_reviews_count' => $meta_trust['agent_reviews_count'],
            'utility_score' => $meta_trust['utility_score'],
            'safety_score' => $meta_trust['safety_score'],
            'audit_verdict' => $meta_trust['audit_verdict'],
            'is_gem' => $meta_trust['is_gem'],
            'audit_summary' => $meta_trust['audit_summary'],
            'audit_report' => $meta_trust['audit_report'],
            'feedback_summary' => $meta_trust['feedback_summary'],
            'recent_feedback' => $meta_trust['recent_feedback'],
            'auth' => $server['auth'],
            'install' => $server['install'],
            'schema_valid' => $server['schema_valid'],
            'tools' => $tools,
            'tool_names' => $all_names,
            'total_tools' => count($all_names),
            'next' => $next,
        ];

        return [
            'content' => [['type' => 'text', 'text' => implode("\n", $lines)]],
            'structuredContent' => (object) $structured,
        ];
    }

    /**
     * Доверие, звёзды, статистика вызовов и последние отзывы по карточке сервера.
     *
     * @param  array $server
     * @param  int   $fb_limit
     * @return array<string,mixed>
     */
    public static function server_trust_and_feedback(array $server, $fb_limit = 5)
    {
        $slug = (string) ($server['slug'] ?? '');
        $stats = $slug !== '' ? Service_Nj676_Telemetry::stats_by_server([$slug]) : [];
        $stat = (array) ($stats[$slug] ?? []);
        $summary = $slug !== '' ? Service_Nj676_Telemetry::feedback_summary($slug)
            : ['total' => 0, 'up' => 0, 'down' => 0, 'positive_rate' => NULL, 'agent_rating' => NULL];
        $recent = $slug !== '' ? Service_Nj676_Telemetry::recent_feedback($slug, $fb_limit) : [];

        $calls = (int) ($stat['calls'] ?? 0);
        $rate = $calls > 0 ? round(100 * (float) ($stat['rate'] ?? 0), 1) : NULL;
        $base_trust = (int) ($server['trust'] ?? 0);
        $fb_up = (int) ($summary['up'] ?? ($stat['up'] ?? 0));
        $fb_down = (int) ($summary['down'] ?? ($stat['down'] ?? 0));
        $trust = max(0, min(100, $base_trust + ($fb_up * 2) - ($fb_down * 5)));
        if ($calls >= 3 AND (float) ($stat['rate'] ?? 0) >= 0.9)
        {
            $trust = max($trust, min(100, $base_trust + 5));
        }

        $agent_rating = $summary['agent_rating'] ?? ($stat['agent_rating'] ?? NULL);
        if ($agent_rating === NULL AND isset($server['agent_rating']) AND (float) $server['agent_rating'] > 0)
        {
            $agent_rating = round((float) $server['agent_rating'], 2);
        }
        $agent_reviews_count = max((int) ($summary['total'] ?? 0), (int) ($server['agent_reviews_count'] ?? 0));

        return [
            'trust' => $trust,
            'stars' => (int) ($server['stars'] ?? 0),
            'health_status' => (string) ($server['health_status'] ?? 'unverified'),
            'calls' => $calls,
            'success_rate' => $rate,
            'agent_rating' => $agent_rating !== NULL ? (float) $agent_rating : NULL,
            'agent_reviews_count' => $agent_reviews_count,
            'utility_score' => (int) ($server['audit_score'] ?? 0),
            'safety_score' => (int) ($server['safety_score'] ?? 0),
            'audit_verdict' => (string) ($server['audit_verdict'] ?? ''),
            'is_gem' => ! empty($server['is_gem']),
            'audit_summary' => (string) ($server['audit_summary'] ?? ''),
            'audit_report' => is_array($server['audit_report'] ?? NULL) && $server['audit_report'] !== []
                ? $server['audit_report'] : NULL,
            'feedback_summary' => $summary,
            'recent_feedback' => $recent,
        ];
    }

    /** Найти инструмент в снимке по имени (регистронезависимо). */
    protected static function find_tool(array $tools, $name)
    {
        foreach ($tools as $tool)
        {
            if (is_array($tool) AND isset($tool['name'])
                AND mb_strtolower((string) $tool['name']) === mb_strtolower(trim((string) $name)))
            {
                return $tool;
            }
        }

        return NULL;
    }

    // ---------------------------------------------------------------------
    // hub_feedback
    // ---------------------------------------------------------------------

    public static function feedback_tool(array $args, array $ctx)
    {
        $call_id = trim(self::_string($args['call_id'] ?? ''));
        $server_slug = trim(self::_string($args['server'] ?? ''));
        $has_write_fields = (trim(self::_string($args['rating'] ?? '')) !== ''
            OR trim(self::_string($args['outcome'] ?? '')) !== ''
            OR trim(self::_string($args['comment'] ?? '')) !== ''
            OR (isset($args['tags']) AND is_array($args['tags']) AND $args['tags'] !== []));

        // Пустой вызов без call_id и без server: объясняем оба режима работы (Round 10 P3 #5).
        if ($call_id === '' AND $server_slug === '')
        {
            return self::error(
                'Укажите режим работы hub_feedback: для записи отзыва после реального вызова передайте '
                .'{"call_id":"call_…","rating":"up"|"down"} (call_id приходит в ответе hub_call_tool); '
                .'для чтения отзывов и рейтинга доверия передайте {"server":"<slug>","limit":10}.',
                'INVALID_ARGUMENT',
                [
                    'field' => 'call_id | server',
                    'hint' => 'Запись: {"call_id":"call_…","rating":"up"|"down"}. Чтение: {"server":"<slug>","limit":10}.',
                ]
            );
        }

        // Передан server + пишущие поля (rating/outcome/comment/tags) без call_id:
        // нельзя молча превращать попытку записи отзыва в чтение (Round 10 P2 #1).
        if ($call_id === '' AND $server_slug !== '' AND $has_write_fields)
        {
            return self::error(
                'Для записи отзыва укажите call_id из ответа hub_call_tool (оценить можно только реальный вызов). '
                .'Для чтения отзывов по серверу передайте только server и опционально limit без rating.',
                'INVALID_ARGUMENT',
                [
                    'field' => 'call_id',
                    'server' => $server_slug,
                    'hint' => 'Запись: {"call_id":"call_…","rating":"up"|"down"}. Чтение: {"server":"'.$server_slug.'","limit":10}.',
                ]
            );
        }

        // Режим чтения отзывов: передан server без call_id и без пишущих полей.
        if ($call_id === '' AND $server_slug !== '')
        {
            $server = Service_Nj676_McpHub::get($server_slug);
            if ( ! $server)
            {
                return self::error('Сервера «'.$server_slug.'» нет в опубликованном каталоге.',
                    'TOOL_NOT_FOUND', ['server' => $server_slug, 'hint' => 'Slug берётся из hub_search_tools.']);
            }

            $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
            $meta = self::server_trust_and_feedback($server, $limit);
            $fb_sum = $meta['feedback_summary'];

            $lines = [
                'Отзывы и доверие по серверу «'.$server['slug'].'»:',
                'Доверие каталога: '.$meta['trust'].' / 100 · Звёзд: '.$meta['stars']
                    .' · Готовность: '.$server['readiness']['readiness']
                    .($meta['calls'] > 0 ? ' · Успех вызовов: '.$meta['success_rate'].'% / '.$meta['calls'] : ''),
                'Всего отзывов агентов: '.$fb_sum['total'].' (+'.$fb_sum['up'].' / −'.$fb_sum['down'].')',
            ];

            if ($meta['recent_feedback'] === [])
            {
                $lines[] = 'Текстовых отзывов по этому серверу пока нет.';
            }
            else
            {
                foreach ($meta['recent_feedback'] as $fb_item)
                {
                    $fb_parts = [($fb_item['rating'] === 'up' ? '👍' : '👎').' '.$fb_item['rating']];
                    if ($fb_item['tool'] !== '') { $fb_parts[] = $fb_item['tool']; }
                    if ($fb_item['outcome'] !== '') { $fb_parts[] = $fb_item['outcome']; }
                    if ($fb_item['tags'] !== []) { $fb_parts[] = '['.implode(', ', $fb_item['tags']).']'; }
                    if ($fb_item['comment'] !== '') { $fb_parts[] = '«'.self::clean_remote_text($fb_item['comment'], 200).'»'; }
                    if ($fb_item['at'] !== '') { $fb_parts[] = $fb_item['at']; }
                    $lines[] = '• '.implode(' · ', $fb_parts);
                }
            }

            return [
                'content' => [['type' => 'text', 'text' => implode("\n", $lines)]],
                'structuredContent' => (object) [
                    'ok' => TRUE,
                    'mode' => 'read',
                    'server' => $server['slug'],
                    'trust' => $meta['trust'],
                    'stars' => $meta['stars'],
                    'readiness' => $server['readiness']['readiness'],
                    'health_status' => $meta['health_status'],
                    'calls' => $meta['calls'],
                    'success_rate' => $meta['success_rate'],
                    'agent_rating' => $meta['agent_rating'],
                    'agent_reviews_count' => $meta['agent_reviews_count'],
                    'utility_score' => $meta['utility_score'],
                    'safety_score' => $meta['safety_score'],
                    'audit_verdict' => $meta['audit_verdict'],
                    'is_gem' => $meta['is_gem'],
                    'audit_summary' => $meta['audit_summary'],
                    'feedback_summary' => $fb_sum,
                    'recent_feedback' => $meta['recent_feedback'],
                ],
            ];
        }

        // Антинакрутка: один ключ не может засыпать сервер отзывами. Демот
        // считается по РАЗНЫМ агентам, но мусор в журнале тоже не нужен.
        try
        {
            $cache = Cache::instance('file');
            $who = substr(hash('sha256', (string) ($ctx['token_hash'] ?? 'anon')), 0, 24);
            $key = 'nj676_fb_'.$who.'_'.date('YmdHi');
            $burst = (int) $cache->get($key, 0) + 1;
            $cache->set($key, $burst, 120);

            if ($burst > 20)
            {
                return self::error('Слишком много отзывов за минуту (20). Повторите позже — '
                    .'рейтинг считается по разным агентам, а не по их числу.',
                    'RATE_LIMITED', ['retry_after' => max(1, 60 - (int) date('s'))]);
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: счётчик отзывов недоступен: :e', [':e' => $e->getMessage()]);
        }

        $answer = Service_Nj676_Telemetry::feedback(
            (string) ($args['call_id'] ?? ''),
            (string) ($args['rating'] ?? ''),
            (string) ($args['outcome'] ?? ''),
            is_array($args['tags'] ?? NULL) ? $args['tags'] : [],
            (string) ($args['comment'] ?? ''),
            $ctx['token_hash'] ?? NULL
        );

        // Авто-демот: несколько независимых агентов сказали «не работает» —
        // сервер уходит вниз и встаёт в начало очереди живой проверки.
        $message = $answer['message'];
        if ($answer['ok'] AND (int) ($answer['rating'] ?? 0) < 0 AND (string) ($answer['server_slug'] ?? '') !== '')
        {
            $threshold = (int) getenv('NJ676_DEMOTE_REPORTS');
            $threshold = $threshold > 0 ? min(20, $threshold) : 3;
            $agents = Service_Nj676_Telemetry::negative_agents((string) $answer['server_slug'], 7);

            if ($agents >= $threshold AND Service_Nj676_McpHub::demote_by_feedback((string) $answer['server_slug'], $agents))
            {
                $message .= ' Сервер понижен до «сбоит» и будет проверен вне очереди.';
            }
        }

        $structured = [
            'ok' => $answer['ok'],
            'code' => $answer['code'],
            'duplicate' => ! empty($answer['duplicate']),
            'call_id' => (string) ($args['call_id'] ?? ''),
            'message' => $message,
            'request_id' => self::request_id(),
        ];

        $result = [
            'content' => [['type' => 'text', 'text' => $message]],
            'structuredContent' => (object) $structured,
        ];

        if ( ! $answer['ok'])
        {
            $result['isError'] = TRUE;
        }

        return $result;
    }

    /**
     * Живой список инструментов удалённого сервера.
     *
     * Кэш — в самой карточке (tools + health_checked_at): если список уже
     * снимали меньше TOOLS_TTL назад, сеть не дёргаем. force = TRUE — обновить.
     *
     * @return array{ok:bool,tools:array,error:string,ms:int,cached:bool}
     */
    public static function remote_tools($slug, $force = FALSE)
    {
        $server = Service_Nj676_McpHub::get((string) $slug, TRUE);

        if ( ! $server)
        {
            return ['ok' => FALSE, 'tools' => [], 'error' => 'Сервера нет в каталоге', 'ms' => 0, 'cached' => FALSE];
        }

        if ( ! $server['is_remote'] OR $server['remote_url'] === '')
        {
            return ['ok' => FALSE, 'tools' => [], 'error' => 'Локальный сервер: инструменты видны только после установки', 'ms' => 0, 'cached' => FALSE];
        }

        $fresh = ! empty($server['health_checked_at'])
            && (time() - (int) strtotime((string) $server['health_checked_at'])) < self::TOOLS_TTL
            && ! empty($server['tools']);

        if ( ! $force AND $fresh)
        {
            return ['ok' => TRUE, 'tools' => (array) $server['tools'],
                'error' => '', 'ms' => (int) $server['latency_ms'], 'cached' => TRUE];
        }

        if ( ! self::public_https_url($server['remote_url']))
        {
            return ['ok' => FALSE, 'tools' => [], 'error' => 'Адрес не прошёл проверку безопасности', 'ms' => 0, 'cached' => FALSE];
        }

        $list = Service_Nj676_McpClient::list_tools($server['remote_url'], [
            'timeout' => self::PROXY_TIMEOUT,
            'token' => self::remote_token($server),
        ]);

        if ( ! $list['ok'])
        {
            Service_Nj676_McpHub::apply_probe((string) $slug, FALSE, 0, $list['error']);

            // Проба тоже может поймать «нужен ключ»: отмечаем сразу, чтобы
            // витрина не обещала вызов до первого вызова агента.
            if (self::upstream_code($list['error']) === 'AUTH_REQUIRED')
            {
                Service_Nj676_McpHub::mark_auth_required((string) $slug);
            }

            return ['ok' => FALSE, 'tools' => [], 'error' => $list['error'], 'ms' => $list['ms'], 'cached' => FALSE];
        }

        Service_Nj676_McpHub::apply_probe((string) $slug, TRUE, (int) $list['ms'], '', $list['tools']);
        Service_Nj676_McpHub::clear_auth_required((string) $slug);

        return ['ok' => TRUE, 'tools' => $list['tools'], 'error' => '', 'ms' => (int) $list['ms'], 'cached' => FALSE];
    }

    /**
     * Найти сервер по имени инструмента — путь для слабых моделей, которые
     * помнят «get_weather», но не помнят slug сервера.
     */
    public static function resolve_tool($tool)
    {
        $tool = trim((string) $tool);

        if ($tool === '')
        {
            return NULL;
        }

        $row = Service_Nj676_McpHub::find_by_tool($tool);

        return $row;
    }

    /** Текст из content[]: сначала text-элементы, иначе JSON. */
    public static function flatten_result(array $result)
    {
        $parts = [];

        foreach ((array) ($result['content'] ?? []) as $item)
        {
            if ( ! is_array($item))
            {
                continue;
            }

            if (($item['type'] ?? '') === 'text' AND isset($item['text']))
            {
                $parts[] = (string) $item['text'];
            }
            elseif (isset($item['resource']['text']))
            {
                $parts[] = (string) $item['resource']['text'];
            }
            else
            {
                $parts[] = json_encode($item, JSON_UNESCAPED_UNICODE);
            }
        }

        return trim(implode("\n", $parts));
    }

    /** Ключ сервера — только из окружения сайта, в БД его нет. */
    public static function remote_token(array $server)
    {
        $env_name = 'NJ676_REMOTE_TOKEN_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($server['slug'] ?? '')));

        return trim((string) getenv($env_name));
    }

    /**
     * Защита freemium-апстримов (auth: optional) от выжигания бесплатного
     * лимита анонимными клиентами: NJ676_ANON_PREVIEW_LIMIT вызовов в час на
     * один сервер с одного адреса (по умолчанию 10).
     */
    protected static function check_preview_quota($slug, array $ctx)
    {
        $limit = (int) getenv('NJ676_ANON_PREVIEW_LIMIT');
        $limit = $limit > 0 ? min(600, $limit) : 10;

        if ( ! class_exists('Cache'))
        {
            return NULL;
        }

        try
        {
            $cache = Cache::instance('file');
            $who = substr(hash('sha256', (string) ($ctx['token_hash'] ?? 'anon')), 0, 16);
            $key = 'nj676_prev_'.preg_replace('/[^a-z0-9_-]/i', '_', (string) $slug).'_'.$who.'_'.date('YmdH');
            $used = (int) $cache->get($key, 0) + 1;
            $cache->set($key, $used, 3600);

            if ($used > $limit)
            {
                return self::error(
                    'Исчерпана анонимная демо-квота для сервера «'.$slug.'» ('.$limit.' вызовов в час). '
                    .'Подключитесь с ключом /mcp или повторите позже.',
                    'RATE_LIMITED',
                    [
                        'server' => (string) $slug,
                        'retry_after' => max(60, 3600 - ((int) date('i') * 60 + (int) date('s'))),
                        'hint' => 'Персональный ключ выпускается на странице /mcp.',
                    ]
                );
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: preview-квота недоступна: :e', [':e' => $e->getMessage()]);
        }

        return NULL;
    }

    // ---------------------------------------------------------------------
    // hub_status / hub_configure
    // ---------------------------------------------------------------------

    public static function status(array $ctx)
    {
        $profile = self::profile($ctx);
        $stats = Service_Nj676_McpHub::stats();
        $facets = Service_Nj676_McpHub::facets();
        // Один расчёт готовности на весь ответ: из него берутся и счётчики,
        // и список, и живые примеры. Раньше здесь было три обращения к базе,
        // и любое из них могло дать своё число.
        $ready_overview = Service_Nj676_McpHub::ready_overview();
        $ready = array_slice((array) $ready_overview['list'], 0, 5);

        // Разделяем «можно вызвать сразу» и «откроется после ключа»: раньше
        // агент видел «30 инструментов» и узнавал про ключ только на вызове.
        $platform_free = 0;
        $platform_key = 0;

        if (class_exists('Service_Nj160_Registry'))
        {
            foreach (Service_Nj160_Registry::tools_for((bool) $ctx['is_admin']) as $tool)
            {
                if (empty($tool['needs_key']))
                {
                    $platform_free++;
                }
                else
                {
                    $platform_key++;
                }
            }
        }

        $platform_tools = $platform_free + $platform_key;

        // Счётчики — по всей базе, а не по странице выдачи: `search()` отдаёт
        // 60 строк, и при большом каталоге готовые в них не попадали.
        $ready_tools = (int) $ready_overview['tools'];
        $ready_count = (int) $ready_overview['servers'];
        $ready_probe_ok = (int) ($ready_overview['ready_probe_ok'] ?? $ready_count);
        $ready_anonymous = (int) ($ready_overview['ready_anonymous'] ?? $ready_count);
        $ready_keyed = (int) ($ready_overview['ready_keyed'] ?? 0);
        $ready_optional = (int) ($ready_overview['ready_optional'] ?? 0);
        $ready_error = (string) $ready_overview['error'];
        $semantic_status = Service_Nj676_Semantic::status();
        $unindexed_servers = (int) ($semantic_status['unindexed_servers'] ?? $semantic_status['pending'] ?? 0);
        $catalog_needs_key = max(0, (int) ($facets['auth']['api_key'] ?? 0) + (int) ($facets['auth']['oauth'] ?? 0) - $ready_keyed);
        $catalog_needs_install = (int) ($facets['transport']['stdio'] ?? 0);
        $healthy_but_keyed = max(0, (int) ($stats['healthy_verified'] ?? 0) - $ready_count);

        // Две разные строки вместо одной цифры: «30 инструментов» без ключа
        // не работает ни один, и агент не должен узнавать это на первом вызове.
        // Живые примеры «задача → вызов»: слабой модели нужен не каталог,
        // а два-три готовых рецепта, которые точно сработают.
        $examples = [];
        foreach (array_slice($ready, 0, 2) as $server)
        {
            $tools = self::compact_tools((array) $server['tools'], 1);

            if ($tools === [])
            {
                continue;
            }

            $examples[] = $server['slug'].' → hub_call_tool '.json_encode([
                'server' => $server['slug'],
                'tool' => $tools[0]['name'],
                'arguments' => self::args_object($tools[0]['schema']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $hidden = array_values(array_unique(array_merge(
            self::slug_list($profile['ignore'] ?? []),
            Service_Nj676_Telemetry::failing_servers((string) ($ctx['token_hash'] ?? ''))
        )));

        $lines = [
            'Шлюз MCP Hub · N2J',
            // «Доступно без ключа» было неточным: готовность считается по
            // readiness(), а там сервер с ключом в окружении сайта — тоже ready.
            'Готовы к вызову сейчас: серверов каталога '.$ready_count
                .' (без ключа: '.$ready_anonymous
                .($ready_keyed > 0 ? ', с ключом шлюза: '.$ready_keyed : '')
                .($ready_optional > 0 ? ', демо без ключа: '.$ready_optional : '')
                .') / инструментов '.$ready_tools
                .'; инструментов N2J без ключа '.$platform_free
                // Ноль из-за сбоя и ноль по делу — разные вещи: говорим прямо.
                .($ready_error !== '' ? '. Счётчик готовности не посчитался: '.$ready_error : ''),
            $platform_key > 0
                ? 'Доступно после ключа: '.$platform_key.' инструментов платформы N2J (Bearer-ключ с /mcp)'
                    .($catalog_needs_key > 0 ? '; в каталоге внешних серверов со статусом needs_key (требуют API-ключ/OAuth самого сервиса): '.$catalog_needs_key : '')
                : ($catalog_needs_key > 0
                    ? 'Доступно после ключа: собственных закрытых инструментов платформы N2J нет; в каталоге внешних серверов со статусом needs_key (требуют API-ключ или OAuth самого сервиса): '.$catalog_needs_key
                    : 'Доступно после ключа: закрытых инструментов платформы N2J нет (внешние серверы каталога с собственным API-ключом или OAuth помечены needs_key)'),
            // Две разные цифры вместо одной: «fresh: 1007» при «operational: 8»
            // читалось как «всё проверено» (реальный тест 24.10.2026).
            'Каталог: '.$stats['servers'].' серверов, '.$stats['tools'].' инструментов в схемах',
            'Записи обновлялись за 7 дней: '.$stats['records_updated'].' — это не значит «работают»',
            'Проба health прошла у '.$stats['healthy_verified'].' серверов (из них '.$ready_count.' готовы к вызову сейчас'
                .($healthy_but_keyed > 0 ? ', ещё '.$healthy_but_keyed.' прошли пробу, но требуют API-ключ/OAuth сервиса' : '')
                .'; без учёта живых вызовов)',
            'Поиск: '.(Service_Nj676_Semantic::enabled()
                ? 'смысл + слова (эмбеддинги '.Service_Nj676_Semantic::model().', '
                    .'смысл работает, только если лучший вектор ближе '.Service_Nj676_Semantic::confident_distance()
                    .'; иначе — честный ответ «такого нет»)'
                : 'по словам (эмбеддинг-сервер не настроен)'),
            'Рейтинг и отзывы: у каждого сервера есть индекс доверия trust (0–100, сортировка sort="trust" или sort="reliability") '
                .'и отзывы агентов; hub_feedback {"call_id":"call_…","rating":"up"|"down"} сохраняет оценку после вызова, '
                .'а hub_feedback {"server":"<slug>","limit":10} читает отзывы по серверу.',
            'Бюджеты анонимного доступа: '.self::budget_line(),
            'Активные фильтры: '.($profile === [] ? 'нет' : json_encode($profile, JSON_UNESCAPED_UNICODE)),
            'Категории каталога: '.implode(', ', array_slice(array_keys($facets['categories']), 0, 12)),
            'Как работать: hub_ready (что доступно) или hub_search_tools {"query":"…","sort":"trust"} → hub_describe_tool {"tool":"…"} → hub_call_tool {…} → hub_feedback {"call_id":"…","rating":"up"|"down"}',
            'Вызов сервиса N2J пишет в пространство владельца ключа; чужие данные недоступны.',
        ];

        // Список готового с сигнатурами: слабой модели нужен не перечень
        // названий, а готовые вызовы «инструмент(аргументы)».
        $ready_lines = [];
        foreach (array_slice($ready, 0, 5) as $server)
        {
            $tools = self::compact_tools((array) $server['tools'], 3);

            if ($tools === [])
            {
                continue;
            }

            $signatures = array_map(static function ($tool) {
                return $tool['signature'].($tool['cost'] === 'paid' ? ' [платно]' : '')
                    .($tool['mutating'] ? ' [изменяет данные]' : '');
            }, $tools);

            $meta = ['доверие '.max(0, min(100, (int) ($server['trust'] ?? 50))).'/100'];
            if ($server['latency_ms'] !== NULL)
            {
                $meta[] = (int) $server['latency_ms'].' мс';
            }

            $ready_lines[] = '- '.$server['slug'].': '.implode(', ', $signatures)
                .($meta !== [] ? ' ('.implode(', ', $meta).')' : '');
        }

        if ($ready_lines !== [])
        {
            $lines[] = 'Готовые серверы:';
            foreach ($ready_lines as $ready_line)
            {
                $lines[] = $ready_line;
            }
        }

        if ($hidden !== [])
        {
            $lines[] = 'Скрыто как нерабочее у вас: '.implode(', ', $hidden)
                .' (вернуть: hub_configure {"ignore":[]})';
        }

        if ($examples !== [])
        {
            $lines[] = 'Живые примеры: '.implode(' · ', $examples);
        }

        $structured = [
            'ok' => TRUE,
            'platform_tools' => $platform_tools,
            'platform_tools_free' => $platform_free,
            'platform_tools_key' => $platform_key,
            'ready_servers' => $ready_count,
            'ready_probe_ok' => $ready_probe_ok,
            'ready_anonymous' => $ready_anonymous,
            'ready_keyed' => $ready_keyed,
            'ready_optional' => $ready_optional,
            'healthy_but_keyed' => $healthy_but_keyed,
            'catalog_needs_key' => $catalog_needs_key,
            'catalog_needs_install' => $catalog_needs_install,
            'ready_tools' => $ready_tools,
            'unindexed_servers' => $unindexed_servers,
            'ready_error' => $ready_error !== '' ? $ready_error : NULL,
            // Те же числа, что и у витрины: одно понятие — один источник.
            'ready' => [
                'servers' => $ready_count,
                'tools' => $ready_tools,
                'probe_ok' => $ready_probe_ok,
                'anonymous' => $ready_anonymous,
                'keyed' => $ready_keyed,
                'optional' => $ready_optional,
                'healthy_but_keyed' => $healthy_but_keyed,
            ],
            'trust_scale' => '0..100',
            'feedback' => [
                'write' => 'hub_feedback {"call_id":"call_…","rating":"up"|"down"}',
                'read' => 'hub_feedback {"server":"<slug>","limit":10}',
                'rest_api' => '/mcphub/api/feedback?server=<slug>',
            ],
            // Сбой расчёта готовности — состояние шлюза, а не деталь: «0 готовы»
            // из-за упавшего запроса нельзя показывать как факт (24.10.2026
            // так прожило несколько дней).
            'degraded' => $ready_error !== '',
            'catalog' => $stats + [
                'unindexed_servers' => $unindexed_servers,
                'healthy_but_keyed' => $healthy_but_keyed,
            ],
            'semantic' => $semantic_status,
            'filters' => $profile,
            'budgets' => self::budgets(),
            'ignored' => $hidden,
            'ready_servers_list' => array_map(static function ($server) {
                return [
                    'slug' => (string) $server['slug'],
                    'trust' => max(0, min(100, (int) ($server['trust'] ?? 50))),
                    'tools' => array_map(static function ($tool) { return $tool['signature']; },
                        self::compact_tools((array) $server['tools'], 3)),
                    'latency_ms' => $server['latency_ms'],
                ];
            }, array_slice($ready, 0, 5)),
            'examples' => $examples,
        ];

        return [
            'content' => [['type' => 'text', 'text' => implode("\n", $lines)]],
            'structuredContent' => (object) $structured,
            'filters' => $profile,
            'platform_tools' => $platform_tools,
            'catalog' => $stats,
        ];
    }

    public static function configure(array $args, array $ctx)
    {
        // Анонимный ключ — это хеш IP, а не персональный ключ: сохранять за ним
        // фильтры нельзя. В тесте 24.10.2026 один агент поставил no_install и
        // молча сузил выдачу всем, кто пришёл с того же адреса.
        if (empty($ctx['token_hash']) OR ! empty($ctx['anonymous']))
        {
            return self::error('Настройка запоминается за персональным ключом. Выпустите ключ на /mcp '
                .'(страница «Ключи и инструменты N2J») и подключитесь с ним.',
                'AUTH_REQUIRED', ['hint' => 'Без ключа передавайте фильтры прямо в hub_search_tools: '
                    .'only_ready, readiness, auth, install, sort.']);
        }

        if ( ! empty($args['reset']))
        {
            self::save_profile((string) $ctx['token_hash'], []);

            return ['content' => [['type' => 'text', 'text' => 'Фильтры сброшены — видны все инструменты и серверы каталога.']]];
        }

        $filters = self::profile($ctx);

        if (array_key_exists('no_auth_only', $args))
        {
            if ($args['no_auth_only'])
            {
                $filters['no_auth_only'] = TRUE;
            }
            else
            {
                unset($filters['no_auth_only']);
            }
        }

        if (array_key_exists('no_install', $args))
        {
            if ($args['no_install'])
            {
                $filters['no_install'] = TRUE;
            }
            else
            {
                unset($filters['no_install']);
            }
        }

        if (array_key_exists('categories', $args))
        {
            if ( ! is_array($args['categories']))
            {
                return self::error('categories должен быть массивом имён категорий.', 'INVALID_ARGUMENT',
                    ['field' => 'categories', 'expected' => 'array']);
            }
            $categories = [];
            foreach ($args['categories'] as $category)
            {
                $category = trim(self::_string($category));
                if (isset(Service_Nj676_McpHub::CATEGORIES[$category]))
                {
                    $categories[] = $category;
                }
            }
            if ($categories === [])
            {
                return self::error('Неизвестные категории. Доступны: '.implode(', ', array_keys(Service_Nj676_McpHub::CATEGORIES)),
                    'INVALID_ARGUMENT', ['field' => 'categories']);
            }
            $filters['categories'] = array_values(array_unique($categories));
        }

        if (array_key_exists('ignore', $args))
        {
            $ignore = self::slug_list($args['ignore']);

            if ($ignore === [])
            {
                unset($filters['ignore']);
            }
            else
            {
                $filters['ignore'] = $ignore;
            }
        }

        if (array_key_exists('transport', $args))
        {
            $transport = trim(self::_string($args['transport']));
            if ( ! in_array($transport, ['stdio', 'sse', 'streamable_http'], TRUE))
            {
                return self::error('transport: stdio, sse или streamable_http.', 'INVALID_ARGUMENT',
                    ['field' => 'transport', 'expected' => 'stdio | sse | streamable_http']);
            }
            $filters['transport'] = $transport;
        }

        if (array_key_exists('latency_max', $args))
        {
            $latency = (int) $args['latency_max'];
            if ($latency > 0)
            {
                $filters['latency_max'] = max(50, min(60000, $latency));
            }
        }

        self::save_profile((string) $ctx['token_hash'], $filters);

        return ['content' => [['type' => 'text', 'text' => 'Готово. Активные фильтры: '
            .json_encode($filters, JSON_UNESCAPED_UNICODE)]], 'filters' => $filters];
    }

    /**
     * Самопроверка шлюза — то, что сделал бы агент, но внутри сайта.
     *
     * Зачем: после деплоя «подключите клиент и посмотрите» — плохая проверка.
     * Один GET отвечает на вопрос «шлюз живой?» целиком: инструменты на месте,
     * статус собирается, поиск находит, удалённый сервер отвечает на
     * tools/list, а вызов инструмента возвращает настоящий результат.
     *
     * Безопасность: набор шагов и аргументы вызовов зашиты в код — превратить
     * эндпоинт в универсальный прокси нельзя. Вызываются только серверы без
     * авторизации, а сама ручка ограничена по частоте в контроллере.
     *
     * @param  array $opts http => TRUE (проверить и настоящий POST на эндпоинт)
     * @return array{ok:bool,steps:array,ms:int,checked_at:string}
     */
    public static function selfcheck(array $opts = [])
    {
        $started = microtime(TRUE);
        $steps = [];

        $step = static function ($key, $title, callable $run) use (&$steps) {
            $t0 = microtime(TRUE);

            try
            {
                [$ok, $detail] = $run();
            }
            catch (Throwable $e)
            {
                $ok = FALSE;
                $detail = 'исключение: '.mb_substr($e->getMessage(), 0, 240);
            }

            $steps[] = [
                'key' => $key,
                'title' => $title,
                'ok' => (bool) $ok,
                'ms' => (int) round((microtime(TRUE) - $t0) * 1000),
                'detail' => mb_substr((string) $detail, 0, 400),
            ];
        };

        $ctx = ['is_admin' => FALSE, 'user_id' => NULL, 'anonymous' => TRUE, 'token_hash' => 'selfcheck'];

        // 1. Схема шлюза: семь инструментов — контракт, на который рассчитывает агент.
        $step('tools', 'Схема инструментов шлюза', static function () {
            $tools = self::tool_list();
            $names = [];
            foreach ($tools as $tool)
            {
                $names[] = (string) ($tool['name'] ?? '');
            }

            $missing = array_diff(self::ROOT_TOOLS, $names);
            $extra = array_diff($names, self::ROOT_TOOLS);

            // Та же проверка, что ловит баг OpenCode: `properties: []` вместо
            // `{}` в одном инструменте валит весь tools/list у строгих клиентов.
            $schema = Service_Nj676_McpHub::validate_tool_schemas($tools);
            $ok = ($missing === [] AND $extra === [] AND $schema['ok']);

            if ( ! $schema['ok'])
            {
                return [FALSE, 'JSON Schema: '.implode('; ', array_slice((array) $schema['problems'], 0, 3))];
            }

            return [$ok, $ok
                ? implode(', ', $names)
                : 'лишние: '.implode(', ', $extra).'; нет: '.implode(', ', $missing)];
        });

        // 1b. Смысловой поиск: включён ли эмбеддинг-сервер и сколько карточек
        // проиндексировано. Без него поиск работает по словам — это не ошибка,
        // но человек должен видеть, чем именно ищет каталог.
        $step('semantic', 'Семантический поиск (эмбеддинги)', static function () {
            $status = Service_Nj676_Semantic::status();

            if ( ! $status['enabled'])
            {
                return [TRUE, 'выключен: поиск по словам ('.$status['hint'].')'];
            }

            return [TRUE, 'включён, модель '.$status['model'].', векторов: '
                .($status['indexed'] === NULL ? 'нет данных' : (int) $status['indexed'])];
        });

        // 2. Статус: агент начинает с него — он должен отвечать без сети.
        $step('status', 'hub_status', static function () use ($ctx) {
            $result = self::status($ctx);
            $text = trim((string) ($result['content'][0]['text'] ?? ''));

            return [$text !== '', $text === '' ? 'пустой ответ' : mb_substr($text, 0, 160)];
        });

        // 3. Поиск по каталогу.
        $step('search', 'hub_search_tools «погода»', static function () use ($ctx) {
            $result = self::search_tools(['query' => 'погода', 'limit' => 3], $ctx);
            $servers = (array) ($result['servers'] ?? []);
            $text = trim((string) ($result['content'][0]['text'] ?? ''));

            return [$text !== '', $servers !== []
                ? 'найдено: '.implode(', ', array_slice($servers, 0, 3))
                : mb_substr($text, 0, 160)];
        });

        // 4. Живой удалённый сервер: initialize + tools/list настоящим клиентом.
        $step('remote', 'Удалённый сервер отвечает (tools/list)', static function () {
            $remote = self::_selfcheck_server();

            if ( ! $remote)
            {
                return [TRUE, 'пропущено: в каталоге нет опубликованных удалённых серверов без ключа'];
            }

            $list = self::remote_tools((string) $remote['slug'], TRUE);

            if ( ! $list['ok'])
            {
                return [FALSE, $remote['slug'].': '.$list['error']];
            }

            $names = [];
            foreach (array_slice($list['tools'], 0, 5) as $tool)
            {
                $names[] = (string) $tool['name'];
            }

            return [TRUE, $remote['slug'].' · '.count($list['tools']).' инстр. за '.$list['ms'].' мс: '
                .implode(', ', $names)];
        });

        // 5. Настоящий вызов инструмента через проксирование.
        $step('call', 'Вызов инструмента: 2^10 + sqrt(144)', static function () use ($ctx) {
            $result = self::call_tool([
                'tool' => 'calculate',
                'arguments' => ['expression' => '2^10 + sqrt(144)'],
            ], $ctx);

            $text = self::flatten_result((array) $result);

            if ( ! empty($result['isError']))
            {
                return [FALSE, mb_substr($text, 0, 240)];
            }

            return [strpos($text, '1036') !== FALSE, mb_substr(preg_replace('/\s+/', ' ', $text), 0, 200)];
        });

        // 6. Второй сервер — чтобы проверка не держалась на одном удачном адресе.
        $step('weather', 'Погодный сервер: список продуктов', static function () use ($ctx) {
            $slug = 'openweathermap-mcp';
            $server = Service_Nj676_McpHub::get($slug);

            if ( ! $server OR ! $server['is_remote'] OR $server['remote_url'] === '')
            {
                return [TRUE, 'пропущено: карточки нет в каталоге'];
            }

            $result = self::proxy_remote($slug, 'list_products', [], $ctx);
            $text = self::flatten_result((array) $result);

            if ( ! empty($result['isError']))
            {
                return [FALSE, mb_substr($text, 0, 240)];
            }

            return [$text !== '', mb_substr(preg_replace('/\s+/', ' ', $text), 0, 200)];
        });

        // 7. Только по запросу: настоящий POST на собственный эндпоинт.
        if ( ! empty($opts['http']))
        {
            $step('http', 'POST /mcphub/mcp (сеть, JSON-RPC)', static function () {
                if ( ! function_exists('curl_init'))
                {
                    return [TRUE, 'пропущено: ext-curl недоступен'];
                }

                $url = rtrim(URL::base(TRUE), '/').'/mcphub/mcp';
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => TRUE,
                    CURLOPT_RETURNTRANSFER => TRUE,
                    CURLOPT_TIMEOUT => 6,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_FOLLOWLOCATION => FALSE,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode([
                        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                        'params' => [
                            'protocolVersion' => '2025-06-18',
                            'capabilities' => new stdClass(),
                            'clientInfo' => ['name' => 'n2j-selfcheck', 'version' => '1.0'],
                        ],
                    ]),
                ]);
                $raw = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = (string) curl_error($ch);
                curl_close($ch);

                if ($raw === FALSE OR $status !== 200)
                {
                    return [FALSE, 'HTTP '.(int) $status.($error !== '' ? ' · '.$error : '')];
                }

                $body = json_decode((string) $raw, TRUE);
                $name = (string) ($body['result']['serverInfo']['name'] ?? '');
                $protocol = (string) ($body['result']['protocolVersion'] ?? '');

                return [$name !== '', $name.' · протокол '.$protocol];
            });
        }

        $ok = TRUE;
        foreach ($steps as $row)
        {
            if ( ! $row['ok'])
            {
                $ok = FALSE;
            }
        }

        return [
            'ok' => $ok,
            'steps' => $steps,
            'ms' => (int) round((microtime(TRUE) - $started) * 1000),
            'checked_at' => date('c'),
        ];
    }

    /**
     * Какой сервер проверить вживую: сначала вычислитель, затем любой
     * опубликованный удалённый сервер без авторизации.
     *
     * @return array|null
     */
    protected static function _selfcheck_server()
    {
        foreach (['calculator-mcp', 'open-meteo-mcp', 'everycalc'] as $slug)
        {
            $server = Service_Nj676_McpHub::get($slug);

            if ($server AND $server['is_remote'] AND $server['remote_url'] !== ''
                AND (string) $server['auth_mode'] === 'none')
            {
                return $server;
            }
        }

        $result = Service_Nj676_McpHub::search([
            'transport' => 'streamable_http',
            'auth' => 'none',
            'per' => 1,
            'sort' => 'trust',
        ]);

        foreach ($result['servers'] as $server)
        {
            if ($server['is_remote'] AND $server['remote_url'] !== '')
            {
                return $server;
            }
        }

        return NULL;
    }

    public static function profile(array $ctx)
    {
        // У анонима нет «своих» настроек: token_hash вида anon:<ip> — это
        // идентификатор адреса, а не агента.
        if (empty($ctx['token_hash']) OR ! empty($ctx['anonymous']))
        {
            return [];
        }

        try
        {
            $row = DB::query(Database::SELECT,
                'SELECT filters FROM nj_676_agent_profiles WHERE token_hash = :hash LIMIT 1')
                ->param(':hash', (string) $ctx['token_hash'])
                ->execute()->current();
        }
        catch (Throwable $e)
        {
            return [];
        }

        if ( ! $row)
        {
            return [];
        }

        $raw_filters = $row['filters'] ?? NULL;
        $filters = is_array($raw_filters) ? $raw_filters : json_decode((string) $raw_filters, TRUE);

        return is_array($filters) ? $filters : [];
    }

    protected static function save_profile($token_hash, array $filters)
    {
        $sql = 'INSERT INTO nj_676_agent_profiles (token_hash, filters, calls, updated_at)
                VALUES (:hash, CAST(:filters AS jsonb), 0, now())
                ON CONFLICT (token_hash) DO UPDATE SET
                    filters = EXCLUDED.filters, updated_at = now()';

        DB::query(Database::INSERT, $sql)
            ->param(':hash', (string) $token_hash)
            ->param(':filters', json_encode($filters, JSON_UNESCAPED_UNICODE))
            ->execute();
    }

    // ---------------------------------------------------------------------
    // Внутреннее
    // ---------------------------------------------------------------------

    /** HTTPS + публичный хост (проверяем и DNS, а не только литерал). */
    public static function public_https_url($url)
    {
        $url = trim((string) $url);
        if ($url === '' OR filter_var($url, FILTER_VALIDATE_URL) === FALSE)
        {
            return FALSE;
        }

        $parts = parse_url($url);
        if ( ! is_array($parts) OR strtolower((string) ($parts['scheme'] ?? '')) !== 'https')
        {
            // Локальный стенд (сторонние MCP в закрытой песочнице недостижимы):
            // разрешаем http://127.0.0.1 только вне production и только если
            // владелец явно включил NJ676_ALLOW_LOCAL_PROBE=1. По умолчанию —
            // и на бою всегда — только HTTPS.
            $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

            if (Kohana::$environment !== Kohana::PRODUCTION
                AND (string) getenv('NJ676_ALLOW_LOCAL_PROBE') === '1'
                AND in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http'], TRUE)
                AND in_array($host, ['127.0.0.1', '::1'], TRUE))
            {
                return TRUE;
            }

            return FALSE;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' OR $host === 'localhost' OR str_ends_with($host, '.localhost')
            OR str_ends_with($host, '.local') OR str_ends_with($host, '.internal'))
        {
            return FALSE;
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP))
        {
            $addresses[] = $host;
        }
        else
        {
            $resolved = @gethostbynamel($host);
            $addresses = is_array($resolved) ? $resolved : [];
        }

        if ($addresses === [])
        {
            return FALSE;
        }

        foreach ($addresses as $address)
        {
            if (filter_var($address, FILTER_VALIDATE_IP) === FALSE)
            {
                return FALSE;
            }
            if ( ! filter_var($address, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            {
                return FALSE;
            }
            // Облачные metadata-адреса запрещены при любых настройках.
            if (strpos($address, '169.254.') === 0)
            {
                return FALSE;
            }
        }

        return TRUE;
    }

    /**
     * Слова запроса для скоринга: стемы + английские понятия.
     *
     * Каталог англоязычный, запросы — русские: «курс валют» без перевода не
     * находил ничего (тест 24.10.2026). Слова-связки отбрасываем: «время и
     * часовые пояса» не должно искать «и».
     */
    protected static function words($query)
    {
        $query = mb_strtolower((string) $query);
        $words = preg_split('/[^a-zа-яё0-9]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($words as $word)
        {
            if (mb_strlen($word) < 3 OR in_array($word, Service_Nj676_McpHub::RU_STOPWORDS, TRUE))
            {
                continue;
            }

            $stem = Service_Nj676_McpHub::stem($word);
            $out[] = $stem !== '' ? $stem : $word;

            foreach (Service_Nj676_McpHub::RU_TO_EN as $ru => $en)
            {
                if ($stem === $ru OR mb_strpos($stem, $ru) === 0 OR mb_strpos($ru, $stem) === 0)
                {
                    foreach (array_slice($en, 0, 3) as $translated)
                    {
                        $out[] = $translated;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Варианты одного слова: свой стем + переводы из словаря RU_TO_EN.
     *
     * Нужен, чтобы объяснение «почему здесь» называло СВОЁ слово запроса
     * («письмо»), а не перевод, которого агент не писал.
     */
    protected static function variants($word)
    {
        $word = mb_strtolower((string) $word);
        $stem = Service_Nj676_McpHub::stem($word);
        $out = [$stem !== '' ? $stem : $word];

        foreach (Service_Nj676_McpHub::RU_TO_EN as $ru => $en)
        {
            if ($stem === $ru OR mb_strpos($stem, $ru) === 0 OR mb_strpos($ru, $stem) === 0)
            {
                foreach (array_slice($en, 0, 4) as $translated)
                {
                    $out[] = $translated;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Слово или его словоформа реально встречаются в тексте как токен, а не
     * как случайная подстрока внутри чужого корня («read» ≠ «readiness»,
     * «crypto» ≠ «cryptography», «rate» ≠ «generate»).
     */
    protected static function text_has_variant($text, $variant)
    {
        $text = mb_strtolower((string) $text);
        $variant = mb_strtolower(trim((string) $variant));

        if ($text === '' OR $variant === '')
        {
            return FALSE;
        }

        // Быстрая отсечка до разбиения на токены.
        $head = mb_substr($variant, 0, min(4, mb_strlen($variant)));
        if ($head !== '' AND mb_strpos($text, $head) === FALSE)
        {
            return FALSE;
        }

        $tokens = preg_split('/[^a-zа-яё0-9]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === [])
        {
            return FALSE;
        }

        // Кириллица: сравниваем по началу слова от стема (4–6 букв).
        if (preg_match('/[а-яё]/u', $variant))
        {
            $probe = mb_substr($variant, 0, min(6, max(3, mb_strlen($variant))));
            foreach ($tokens as $tok)
            {
                if ($tok === $variant OR mb_strpos($tok, $probe) === 0)
                {
                    return TRUE;
                }
            }

            return FALSE;
        }

        // Латиница: запрещаем ложные деривации («read» → «readiness/ready/readme»,
        // «crypto» → «cryptography», «rate» → «generate/accurate/ratelimit»).
        $false_friends = [
            'read' => ['readiness', 'readily', 'ready', 'readme', 'thread', 'threads', 'spread', 'already', 'bread', 'unread'],
            'crypto' => ['cryptography', 'cryptographic', 'cryptographer', 'cryptographers'],
            'rate' => ['generate', 'generates', 'generated', 'generator', 'integrate', 'integrated', 'integration',
                       'accurate', 'operate', 'operating', 'operator', 'migrate', 'migration', 'collaborate',
                       'orchestrate', 'separate', 'moderate', 'crate', 'pirate', 'curate', 'curated', 'federate', 'ratelimit'],
            'auth' => ['author', 'authors', 'authority', 'authorities'],
        ];

        $compound_roots = [
            'pdf', 'icon', 'mail', 'email', 'sql', 'git', 'ssh', 'aws', 'csv', 'xml', 'doc', 'docx',
            'xlsx', 'json', 'weather', 'bitcoin', 'btc', 'mongo', 'redis', 'postgres', 'sqlite',
            'browser', 'playwright', 'docker', 'kube', 'slack', 'notion', 'telegram', 'youtube',
            'stripe', 'figma', 'arxiv', 'wiki', 'calc', 'time', 'clock', 'voice', 'audio', 'speech',
            'video', 'image', 'photo', 'map', 'geo', 'dns', 'whois', 'coin',
        ];

        $v_len = strlen($variant);

        foreach ($tokens as $tok)
        {
            if (isset($false_friends[$variant]) AND in_array($tok, $false_friends[$variant], TRUE))
            {
                continue;
            }
            if ($variant === 'crypto' AND strpos($tok, 'cryptograph') === 0)
            {
                continue;
            }
            if ($variant === 'read' AND (strpos($tok, 'readi') === 0 OR strpos($tok, 'readm') === 0))
            {
                continue;
            }

            if ($tok === $variant
                OR $tok === $variant.'s'
                OR $tok === $variant.'es'
                OR $tok === $variant.'ed'
                OR $tok === $variant.'ing'
                OR $tok === $variant.'er'
                OR $variant === $tok.'s'
                OR $variant === $tok.'es')
            {
                return TRUE;
            }

            // Длинный латинский корень (>= 6 букв: currenc -> currency, translat -> translate).
            if ($v_len >= 6 AND strpos($tok, $variant) === 0)
            {
                return TRUE;
            }

            // Составные технические имена без дефиса (pdfmathtranslate, fastmail, mediawiki, precisioncalc).
            if (in_array($variant, $compound_roots, TRUE) AND strlen($tok) > $v_len
                AND (strpos($tok, $variant) === 0 OR substr($tok, -$v_len) === $variant))
            {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Насколько карточка отвечает на запрос — и КАКИЕ слова реально совпали.
     *
     * Три уровня доверия к совпадению:
     *   * имя сервера — сильный сигнал (своё слово 5, перевод 3);
     *   * описание — средний (3 / 1);
     *   * имена и описания инструментов — слабый (1 / 0.5). Набор иконок с
     *     иконкой «email» не должен обгонять почтовый сервер.
     *
     * Возвращает ['relevance' => 0..1, 'matched' => [слова запроса]].
     */
    protected static function match_detail(array $server, $query)
    {
        $slug_raw = mb_strtolower(trim((string) ($server['slug'] ?? '')));
        $name_only = mb_strtolower(trim((string) ($server['name'] ?? '')));
        $name_ru_only = mb_strtolower(trim((string) ($server['name_ru'] ?? '')));
        $query_lower = mb_strtolower(trim((string) $query));
        $query_slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', $query_lower), '-');

        // 1. Точное совпадение по слагу или заголовку (temporal-mcp, huntboard,
        // justidea, ai-echoloc-company-technographics) обязано давать 1.0 и
        // флаг exact, чтобы карточка всегда занимала топ-1.
        if ($query_lower !== '' AND (
            $query_lower === $slug_raw
            OR ($query_slug !== '' AND $query_slug === $slug_raw)
            OR $query_lower === $name_only
            OR ($name_ru_only !== '' AND $query_lower === $name_ru_only)
        ))
        {
            return [
                'relevance' => 1.0,
                'matched' => [$query_lower],
                'exact' => TRUE,
                'slug_match' => TRUE,
            ];
        }

        // Русский перевод входит в те же поля: запрос «браузер для агента»
        // должен совпадать с «автоматизация браузера», а не только с browser.
        $name = mb_strtolower((string) ($server['name'] ?? '').' '
            .(string) ($server['name_ru'] ?? '').' '.(string) ($server['slug'] ?? '').' '
            .(string) ($server['registry_name'] ?? ''));
        $audit_rep = is_array($server['audit_report'] ?? NULL) ? $server['audit_report'] : [];
        $description = mb_strtolower((string) ($server['description'] ?? '').' '
            .(string) ($server['description_ru'] ?? '').' '
            .(string) ($server['audit_summary'] ?? '').' '
            .implode(' ', (array) ($server['use_cases'] ?? [])).' '
            .implode(' ', (array) ($audit_rep['use_cases'] ?? [])).' '
            .(string) ($audit_rep['observations'] ?? ''));

        $tool_parts = [];
        foreach ((array) ($server['tools'] ?? []) as $tool)
        {
            if ( ! is_array($tool))
            {
                continue;
            }

            // Имя инструмента — сильнее описания: match_icons говорит о теме
            // больше, чем слово «email» в тексте описания.
            $tool_parts[] = (string) ($tool['name'] ?? '');
            $tool_parts[] = (string) ($tool['description'] ?? '');
        }

        foreach ((array) ($server['categories'] ?? []) as $category)
        {
            $tool_parts[] = Service_Nj676_McpHub::CATEGORIES[(string) $category] ?? '';
        }

        $tool_text = mb_strtolower(implode(' ', $tool_parts));

        // 2. Совпадение со специфичным сегментом слага (например, «echoloc» в
        // «ai-echoloc-company-technographics» или «huntboard» в «ai-huntboard-huntboard»).
        $slug_segments = array_values(array_diff(
            preg_split('/[^a-z0-9]+/i', $slug_raw, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ['mcp', 'server', 'servers', 'api', 'ai', 'io', 'app', 'com', 'org', 'dev']
        ));
        if ($query_slug !== '' AND (mb_strlen($query_slug) >= 5 OR strpos($query_slug, '-') !== FALSE)
            AND in_array($query_slug, $slug_segments, TRUE))
        {
            return [
                'relevance' => 1.0,
                'matched' => [$query_lower],
                'exact' => count(array_unique($slug_segments)) === 1,
                'slug_match' => TRUE,
            ];
        }

        $raw_tokens = array_values(array_unique(
            preg_split('/[^a-zа-яё0-9]+/u', $query_lower, -1, PREG_SPLIT_NO_EMPTY) ?: []
        ));
        $generic_noise = ['mcp', 'server', 'servers', 'stdio', 'http', 'sse', 'ai', 'api', 'io', 'app'];
        $non_generic = array_values(array_filter($raw_tokens, static function ($tok) use ($generic_noise) {
            return mb_strlen($tok) >= 3
                AND ! in_array($tok, Service_Nj676_McpHub::RU_STOPWORDS, TRUE)
                AND ! in_array($tok, $generic_noise, TRUE);
        }));
        $has_specific_terms = $non_generic !== [];

        // Общие глаголы и широкие модификаторы («read» в «read pdf», «отправить»
        // в «отправить письмо», «курс» в «курс биткоина», «сайта» в «скриншот сайта»):
        // если в запросе есть предметное существительное, совпадение ТОЛЬКО по
        // глаголу/модификатору без предмета не делает карточку релевантной.
        $helper_stems = [
            'read', 'write', 'get', 'set', 'fetch', 'list', 'search', 'find', 'check', 'make',
            'create', 'send', 'run', 'use', 'open', 'view', 'show', 'parse', 'convert', 'generate',
            'прочит', 'чита', 'чтен', 'получ', 'найт', 'провер', 'сдела', 'созд', 'отправ',
            'запуст', 'показ', 'вызв', 'использ', 'откр', 'сайт', 'страниц', 'курс',
        ];
        $subject_tokens = [];
        foreach ($non_generic as $tok)
        {
            $st = Service_Nj676_McpHub::stem($tok) ?: $tok;
            $is_helper = FALSE;
            foreach ($helper_stems as $hs)
            {
                if ($tok === $hs OR $st === $hs OR mb_strpos($st, $hs) === 0)
                {
                    $is_helper = TRUE;
                    break;
                }
            }
            if ( ! $is_helper)
            {
                $subject_tokens[] = $tok;
            }
        }

        $score = 0.0;
        $matched = [];
        $matched_subjects = 0;
        $slug_matched = FALSE;

        foreach ($raw_tokens as $original)
        {
            if (mb_strlen($original) < 3 OR in_array($original, Service_Nj676_McpHub::RU_STOPWORDS, TRUE))
            {
                continue;
            }

            // Служебное слово «mcp» или «server» в составном запросе
            // («temporal-mcp», «weather mcp») не должно давать 5 очков любой
            // карточке каталога со словом mcp в имени (amanmcp).
            if ($has_specific_terms AND in_array($original, $generic_noise, TRUE))
            {
                if (self::text_has_variant($name, $original))
                {
                    $score += 0.15;
                }
                continue;
            }

            $is_subject = in_array($original, $subject_tokens, TRUE);
            $best_name = 0.0;
            $best_desc = 0.0;
            $best_tool = 0.0;
            $is_strong = TRUE;

            foreach (self::variants($original) as $variant)
            {
                if ($variant === '')
                {
                    continue;
                }

                // Флаг совпадения со слагом ставим только по длинному
                // идентификаторному слову (>= 5 символов), а не по коротким
                // словам темы (pdf, sql, web) или глаголу «read».
                if (($is_subject OR $subject_tokens === [])
                    AND mb_strlen($variant) >= 5
                    AND $slug_raw !== '' AND self::text_has_variant($slug_raw, $variant))
                {
                    $slug_matched = TRUE;
                }

                if (self::text_has_variant($name, $variant))
                {
                    $best_name = max($best_name, $is_strong ? 5.0 : 4.5);
                }
                if (self::text_has_variant($description, $variant))
                {
                    $best_desc = max($best_desc, $is_strong ? 3.0 : 1.2);
                }
                if (self::text_has_variant($tool_text, $variant))
                {
                    $best_tool = max($best_tool, $is_strong ? 1.0 : 0.8);
                }

                $is_strong = FALSE;
            }

            // Если термин (или его словарный перевод, например «иконки» → icon)
            // совпал и в имени сервера, и в описании/инструментах (dashboard-icons:
            // Dashboard Icons + search_icons), сигналы складываются, чтобы
            // профильный сервер получал 6/6 = 1.0 даже по одному слову.
            $word_score = $best_name > 0
                ? min(6.0, $best_name + min(1.5, $best_desc * 0.5 + $best_tool))
                : ($best_desc > 0 ? min(4.0, $best_desc + min(1.0, $best_tool * 0.5)) : $best_tool);

            if ($word_score > 0)
            {
                $score += $word_score;
                $matched[] = $original;
                if ($is_subject)
                {
                    $matched_subjects++;
                }
            }
        }

        // Если в запросе есть конкретный предмет («pdf» в «read pdf», «биткоин»
        // в «курс биткоина», «скриншот» в «скриншот сайта»), а карточка совпала
        // ТОЛЬКО по глаголу/модификатору («read», «курс», «сайта»), обнуляем
        // лексический вес, чтобы «store-readiness» не обгонял PDF-серверы.
        if ($subject_tokens !== [] AND $matched_subjects === 0)
        {
            return [
                'relevance' => 0.0,
                'matched' => [],
                'exact' => FALSE,
                'slug_match' => FALSE,
            ];
        }

        // В многословном предметном запросе (например, «ai-echoloc-company-technographics»)
        // совпадение только по 1 из 3 предметных слов («company») понижается
        // пропорционально доле совпавших слов.
        if (count($subject_tokens) >= 2 AND $matched_subjects < count($subject_tokens))
        {
            $coverage = $matched_subjects / count($subject_tokens);
            $score *= (0.45 + 0.55 * $coverage);
            if ($coverage < 0.6)
            {
                $slug_matched = FALSE;
            }
        }

        return [
            // 6 очков = «два сильных совпадения» или имя + описание/инструменты:
            // полная релевантность. Дальше шкала не растёт — хватает.
            'relevance' => min(1.0, $score / 6),
            'matched' => $matched,
            'exact' => FALSE,
            'slug_match' => $slug_matched,
        ];
    }

    /**
     * Лучший инструмент сервера под задачу.
     *
     * Слабая модель берёт из ответа именно `next`, поэтому там должен быть
     * инструмент по теме, а не первый в списке: у набора иконок первым лежит
     * `match_icons`, и на вопрос «отправить письмо» это вело в тупик.
     * Пишущие инструменты при равном совпадении идут ниже читающих.
     */
    protected static function best_tool(array $tools, $query)
    {
        $best = NULL;
        $best_score = -1.0;

        foreach ($tools as $index => $tool)
        {
            if ( ! is_array($tool) OR empty($tool['name']))
            {
                continue;
            }

            $score = 0.0;

            if ((string) $query !== '')
            {
                $detail = self::match_detail([
                    'name' => (string) $tool['name'],
                    'slug' => (string) $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    'tools' => [],
                    'categories' => [],
                ], $query);

                $score = $detail['relevance'];
            }

            // Читающие выше пишущих: совет «сначала прочитай» безопаснее.
            if (empty($tool['mutating']))
            {
                $score += 0.05;
            }

            // При полном равенстве — порядок в схеме сервера.
            $score -= $index * 0.0001;

            if ($score > $best_score)
            {
                $best = $tool;
                $best_score = $score;
            }
        }

        return $best ?: ['name' => '', 'required' => [], 'parameters' => new stdClass()];
    }

    /** Исходные слова запроса — для строки «почему здесь» (без переводов). */
    protected static function raw_words($query, $limit = 3)
    {
        $words = preg_split('/[^a-zа-яё0-9]+/u', mb_strtolower((string) $query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($words as $word)
        {
            if (mb_strlen($word) >= 3 AND ! in_array($word, Service_Nj676_McpHub::RU_STOPWORDS, TRUE))
            {
                $out[] = $word;
            }

            if (count($out) >= $limit)
            {
                break;
            }
        }

        return $out;
    }



    /**
     * Чужой текст (описание сервера или инструмента) перед показом агенту.
     *
     * 943 карточки пришли из официального реестра, и их описания пишут чужие
     * люди. Это недоверенный ввод: в описании может стоять «игнорируй прежние
     * инструкции» или готовая команда вызвать другой инструмент. Мы не
     * исполняем эти описания, но и передавать их агенту как есть нельзя.
     * Поэтому: убираем управляющие символы, режем типовые инъекции, обрезаем
     * по границе предложения (а не посреди важной фразы).
     */
    public static function clean_remote_text($text, $limit = 300)
    {
        $text = (string) $text;

        // Управляющие символы и невидимые разделители — источник «мусора»
        // в JSON и в терминале агента.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}]/u', '', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        $text = trim((string) $text);

        // Классические попытки перехвата управления. Не «вычищаем всё
        // подозрительное», а убираем конкретные шаблоны — остальное остаётся
        // полезным текстом и не мешает.
        $text = preg_replace(
            '/\b(ignore|disregard|forget)\s+(all\s+)?(the\s+)?(previous|prior|above|earlier)\s+'
            .'(instructions?|prompts?|messages?|rules?)\b[^.!?]{0,120}[.!?]?/iu',
            ' ', $text);
        $text = preg_replace('/\b(you are|act as|from now on)\b[^.!?]{0,80}\b(assistant|system|admin)\b[^.!?]{0,80}[.!?]?/iu',
            ' ', $text);
        $text = preg_replace('/<\/?\s*(system|instructions?|prompt)\s*>/iu', ' ', $text);
        $text = preg_replace('/\b(system|assistant)\s*:\s*/iu', ' ', $text);

        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        $limit = max(40, (int) $limit);

        if (mb_strlen($text, 'UTF-8') <= $limit)
        {
            return $text;
        }

        // Обрезка по концу предложения: «…обязательный параметр city.» лучше,
        // чем «…обязательный параметр ci».
        $cut = mb_substr($text, 0, $limit, 'UTF-8');
        $pos = max(
            (int) mb_strrpos($cut, '. '),
            (int) mb_strrpos($cut, '! '),
            (int) mb_strrpos($cut, '? ')
        );

        if ($pos > $limit * 0.5)
        {
            return mb_substr($cut, 0, $pos + 1, 'UTF-8');
        }

        $space = mb_strrpos($cut, ' ');

        return rtrim($space ? mb_substr($cut, 0, $space, 'UTF-8') : $cut, " ,;:—-").'…';
    }

    protected static function _string($value)
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Ошибка в стабильном формате.
     *
     * Агенту бесполезен текст «что-то пошло не так»: ему нужен код, чтобы
     * принять решение (повторить, сменить сервер, попросить ключ), и
     * request_id, чтобы человек мог найти запрос в логах.
     */
    public static function error($message, $code = 'UPSTREAM_ERROR', array $extra = [])
    {
        $code = in_array($code, self::ERROR_CODES, TRUE) ? $code : 'UPSTREAM_ERROR';
        $request_id = self::request_id();

        // ОДНА точка правды для отметки «сервер требует ключа». Веток, которые
        // возвращают AUTH_REQUIRED, семь, и в каждой её легко забыть — именно
        // так temporal-mcp остался в «Отвечают прямо сейчас» после того, как
        // живой вызов уже сказал «нужен ключ» (замечание 24.10.2026).
        if ($code === 'AUTH_REQUIRED' AND ! empty($extra['server'])
            AND (string) ($extra['auth_scope'] ?? '') !== 'gateway')
        {
            // `auth_scope = gateway` — отказ поставил сам шлюз (анонимному
            // клиенту нельзя звать сервер с ключом). Это не свойство карточки:
            // с ключом она работает, и в «Отвечают сейчас» её прятать нельзя.
            Service_Nj676_McpHub::mark_auth_required((string) $extra['server']);
        }

        $payload = array_merge([
            'ok' => FALSE,
            'code' => $code,
            'message' => (string) $message,
            'retryable' => in_array($code, ['UPSTREAM_TIMEOUT', 'UPSTREAM_ERROR', 'RATE_LIMITED'], TRUE),
            'request_id' => $request_id,
        ], array_filter($extra, static function ($value) {
            return $value !== NULL AND $value !== '';
        }));

        $lines = ['Ошибка: '.$code.'. '.(string) $message];

        if ( ! empty($payload['field']))
        {
            $lines[] = 'Поле: '.$payload['field'].( ! empty($payload['expected'])
                ? ' (ожидалось: '.$payload['expected'].')' : '');
        }
        if ( ! empty($payload['hint']))
        {
            $lines[] = 'Подсказка: '.$payload['hint'];
        }
        if ( ! empty($payload['available_tools']) AND is_array($payload['available_tools']))
        {
            $lines[] = 'Доступные инструменты: '.implode(', ', $payload['available_tools']);
        }
        if ( ! empty($payload['call_id']))
        {
            $lines[] = 'call_id для отзыва: '.$payload['call_id'];
        }

        $lines[] = 'request_id: '.$request_id;

        return [
            'content' => [['type' => 'text', 'text' => implode("\n", $lines)]],
            'structuredContent' => (object) $payload,
            'isError' => TRUE,
        ];
    }

    /**
     * Бюджеты анонимного доступа — в одном месте.
     *
     * Замечание 24.10.2026: агент видел `X-RateLimit-Limit: 600` (чтение) и
     * узнавал про 12 вызовов в минуту только из `-32000 RATE_LIMITED`. Теперь
     * оба бюджета отдаются заголовками (`X-RateLimit-*`) и здесь, в hub_status:
     * правило должно быть известно до того, как в него упрёшься.
     *
     * @return array{read:array{hour:int,minute:int},call:array{hour:int,minute:int}}
     */
    public static function budgets()
    {
        $out = [];

        foreach (['read', 'call'] as $what)
        {
            $is_call = ($what === 'call');

            $hour = (int) getenv($is_call ? 'NJ676_ANON_CALL_LIMIT' : 'NJ676_ANON_READ_LIMIT');
            if ($hour <= 0)
            {
                $legacy = (int) getenv('NJ676_ANON_LIMIT');
                $hour = $legacy > 0 ? $legacy : ($is_call ? 60 : 600);
            }

            $minute = (int) getenv($is_call ? 'NJ676_ANON_CALL_BURST' : 'NJ676_ANON_READ_BURST');

            $out[$what] = [
                'hour' => $hour,
                'minute' => $minute > 0 ? $minute : ($is_call ? 12 : 60),
            ];
        }

        return $out;
    }

    /** Строка про бюджеты для текстового ответа hub_status. */
    public static function budget_line()
    {
        $b = self::budgets();

        return 'чтение — '.$b['read']['hour'].' в час / '.$b['read']['minute'].' в минуту, '
            .'вызовы (hub_call_tool) — '.$b['call']['hour'].' в час / '.$b['call']['minute'].' в минуту; '
            .'остаток — в заголовках '.implode(', ', self::budget_headers()).'; '
            .'ключ с /mcp снимает лимиты';
    }

    /**
     * Имена заголовков бюджета — ОДНО место и для отправки, и для текста.
     *
     * Замечание 24.10.2026: `hub_status` обещал `X-RateLimit-Call-*` /
     * `X-RateLimit-Read-*`, а клиент видел `X-RateLimit-Limit` (лимит чтения,
     * без слова read). Клиент, который поверил тексту, не находил ничего.
     * Теперь текст берёт имена отсюда, а контроллер отправляет ровно их (и
     * старые `X-RateLimit-Limit`/`Remaining`/`Budget` — для совместимости).
     *
     * @return array<int,string>
     */
    public static function budget_headers()
    {
        return [
            'X-RateLimit-Read-Remaining-Hour', 'X-RateLimit-Read-Remaining-Minute',
            'X-RateLimit-Call-Remaining-Hour', 'X-RateLimit-Call-Remaining-Minute',
        ];
    }

    /** Идентификатор запроса: один на весь JSON-RPC вызов. */
    public static function request_id()
    {
        static $id = NULL;

        if ($id === NULL)
        {
            $id = 'req_mcp_'.bin2hex(random_bytes(6));
        }

        return $id;
    }
}
