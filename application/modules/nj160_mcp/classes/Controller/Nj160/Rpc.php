<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP JSON-RPC 2.0 эндпоинт (NJ-160).
 *
 *   POST /mcp/api/rpc
 *   Authorization: Bearer <MCP_TOKEN>
 *
 * Методы: initialize, tools/list, tools/call, ping.
 */
class Controller_Nj160_Rpc extends Controller {

    public $auto_render = FALSE;

    /** sha256 персонального токена (для счётчика вызовов), NULL у legacy-токена. */
    protected $_token_hash = NULL;

    /** user_id владельца персонального токена; legacy MCP_TOKEN → NULL (= серверный). */
    protected $_user_id = NULL;

    /** Уровень доступа ключа: legacy-токен и ключи админов получают инструмент управленияы. */
    protected $_is_admin = FALSE;

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        if ( ! $this->_authorize())
        {
            $this->_json(['jsonrpc' => '2.0', 'id' => NULL,
                          'error' => ['code' => -32001, 'message' => 'Недействительный токен MCP']], 403);
            $this->after();
            exit;
        }

        if ($this->request->method() !== Request::POST)
        {
            $this->_json(['jsonrpc' => '2.0', 'id' => NULL,
                          'error' => ['code' => -32600, 'message' => 'Только POST (JSON-RPC 2.0)']], 405);
            $this->after();
            exit;
        }
    }

    public function action_index()
    {
        $body = json_decode((string) $this->request->body(), TRUE);

        if ( ! is_array($body) OR ! isset($body['method']))
        {
            return $this->_error(NULL, -32600, 'Некорректный JSON-RPC запрос');
        }

        $id     = $body['id'] ?? NULL;
        $params = isset($body['params']) && is_array($body['params']) ? $body['params'] : [];

        try
        {
            switch ($body['method'])
            {
                case 'initialize':
                    // spec: возвращаем версию, которую попросил клиент (если указана)
                    $requested = (string) ($params['protocolVersion'] ?? '');
                    $result = [
                        'protocolVersion' => $requested !== '' ? $requested : '2024-11-05',
                        'capabilities'    => ['tools' => new stdClass],
                        'serverInfo'      => [
                            'name'    => (string) Kohana::$config->load('nj160_mcp.server_name'),
                            'version' => (string) Kohana::$config->load('nj160_mcp.server_version'),
                        ],
                    ];
                    break;

                case 'ping':
                    $result = new stdClass;
                    break;

                case 'assistant.find':
                    // Центральный роутер: по свободному тексту находит подходящие
                    // инструменты (только видимые ЭТОМУ ключу). Слабой модели
                    // возвращаем компактный человекочитаемый список.
                    $result = $this->_assistant_find((string) ($params['q'] ?? ''));
                    break;

                case 'assistant.call':
                    // Вызов через роутер: понятные ошибки (чего не хватило), чтобы
                    // слабая модель сама скорректировала запрос.
                    $result = $this->_assistant_call(
                        (string) ($params['tool'] ?? ''),
                        isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : []
                    );
                    break;

                default:
                    // notifications/* (например, notifications/initialized) — по
                    // спецификации не требуют ответа; отвечаем пустым OK, иначе
                    // строгие клиенты считают хендшейк упавшим
                    if (strpos((string) $body['method'], 'notifications/') === 0)
                    {
                        return $this->_json(['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass]);
                    }

                    return $this->_error($id, -32601, 'Метод не найден: '.$body['method']);

                case 'tools/list':
                    $result = ['tools' => Service_Nj160_Registry::mcp_list($this->_is_admin)];
                    break;

                case 'tools/call':
                    $name = (string) ($params['name'] ?? '');
                    $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

                    $t0 = microtime(TRUE);

                    try
                    {
                        $output = Service_Nj160_Registry::call($name, $args, $this->_is_admin, $this->_user_id);
                    }
                    catch (Kohana_Exception $e)
                    {
                        // ошибка уровня инструмента → isError-контент, чтобы агент
                        // прочитал причину и скорректировал вызов (spec MCP)
                        Kohana::$log->add(Log::WARNING, 'nj160: инструмент :n: :e',
                            [':n' => $name, ':e' => $e->getMessage()]);

                        return $this->_json(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                            'content' => [['type' => 'text', 'text' => 'Ошибка инструмента: '.$e->getMessage()]],
                            'isError' => TRUE,
                        ]]);
                    }
                    catch (Throwable $e)
                    {
                        Kohana::$log->add(Log::ERROR, 'nj160: падение инструмента :n: :e',
                            [':n' => $name, ':e' => $e->getMessage()]);

                        return $this->_json(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                            'content' => [['type' => 'text', 'text' => 'Инструмент упал: '.$e->getMessage()]],
                            'isError' => TRUE,
                        ]]);
                    }

                    if ($this->_token_hash !== NULL)
                    {
                        Service_Nj160_Tokens::touch($this->_token_hash);
                    }

                    Kohana::$log->add(Log::INFO, 'nj160: инструмент :n за :ms мс',
                        [':n' => $name, ':ms' => (int) round((microtime(TRUE) - $t0) * 1000)]);

                    $text   = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    $max    = (int) Kohana::$config->load('nj160_mcp.max_result_chars');

                    if ($max > 0 AND strlen($text) > $max)
                    {
                        $text = substr($text, 0, $max)."\n… (обрезано)";
                    }

                    // доменная ошибка обработчика ({"error": "…"}) — агент должен
                    // видеть её как неудачу вызова, а не как успешный результат
                    $result = ['content' => [['type' => 'text', 'text' => $text]]];

                    if (isset($output['error']))
                    {
                        $result['isError'] = TRUE;
                    }

                    break;
            }

            return $this->_json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
        }
        catch (Kohana_Exception $e)
        {
            return $this->_error($id, -32000, $e->getMessage());
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj160: ошибка выполнения MCP-инструмента: :e', [':e' => $e->getMessage()]);

            return $this->_error($id, -32603, 'Внутренняя ошибка инструмента: '.$e->getMessage());
        }
    }

    /**
     * assistant.find: ищем инструменты по словам запроса (имя, описание,
     * категория). Порог мягкий, порядок — по числу совпадений; слабой модели
     * хватает первых 3–5 подсказок. Пустой/бессмысленный запрос → каталог
     * категорий, чтобы модель уточнила вопрос.
     */
    protected function _assistant_find(string $q)
    {
        $q = trim(mb_strtolower($q));
        $words = preg_split('/[^a-zа-яё0-9]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, static fn($w) => mb_strlen($w) >= 3);

        $visible = Service_Nj160_Registry::tools_for($this->_is_admin);

        if ($q === '' OR $words === [])
        {
            $cats = [];
            foreach ($visible as $t)
            {
                $cat = (string) ($t['category'] ?? $t['module']);
                $cats[$cat] = ($cats[$cat] ?? 0) + 1;
            }
            ksort($cats);

            $lines = [];
            foreach ($cats as $cat => $cnt)
            {
                $lines[] = $cat.' ('.$cnt.' инстр.)';
            }

            return ['content' => [['type' => 'text', 'text' =>
                "Уточните запрос — что нужно сделать? Доступные категории инструментов:\n".implode("\n", $lines)]],
                'tools' => array_keys($cats)];
        }

        $scored = [];

        foreach ($visible as $name => $t)
        {
            $hay = mb_strtolower($name.' '.($t['description'] ?? '').' '.($t['category'] ?? $t['module']).' '.$t['module']);
            $score = 0;

            foreach ($words as $w)
            {
                if (mb_strpos($hay, $w) !== FALSE)
                {
                    $score += (mb_strpos(mb_strtolower((string) $name), $w) !== FALSE) ? 3 : 1;
                }
            }

            if ($score > 0)
            {
                $scored[] = ['score' => $score, 'name' => (string) $name, 't' => $t];
            }
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, 5);

        if ($scored === [])
        {
            return ['content' => [['type' => 'text', 'text' =>
                'По запросу «'.$q.'» подходящих инструментов нет. Напишите иначе или посмотрите tools/list.']], 'tools' => []];
        }

        $lines = [];
        $names = [];

        foreach ($scored as $s)
        {
            $names[] = $s['name'];
            $req = [];

            foreach ((array) ($s['t']['inputSchema']['required'] ?? []) as $rn) {
                $req[] = (string) $rn;
            }
            $lines[] = $s['name'].' — '.($s['t']['description'] ?? '')
                .($req ? '; обязательные аргументы: '.implode(', ', $req) : '; аргументы необязательны');
        }

        return ['content' => [['type' => 'text', 'text' =>
            "Подходящие инструменты (вызывайте через assistant.call {tool, arguments}):\n".implode("\n", $lines)]],
            'tools' => $names];
    }

    /**
     * assistant.call: вызов инструмента с понятными ошибками. Проверяем
     * существование, обязательные аргументы по inputSchema, доступ.
     */
    protected function _assistant_call(string $name, array $args)
    {
        $visible = Service_Nj160_Registry::tools_for($this->_is_admin);

        if ( ! isset($visible[$name]))
        {
            return ['content' => [['type' => 'text', 'text' =>
                'Инструмент «'.$name.'» вам недоступен (нет такого или запрещён настройками хаба). Найдите подходящий через assistant.find.']],
                'isError' => TRUE];
        }

        $schema = (array) ($visible[$name]['inputSchema'] ?? []);
        $required = (array) ($schema['required'] ?? []);

        $missing = [];

        foreach ($required as $r)
        {
            if ( ! isset($args[$r]) OR (is_string($args[$r]) AND trim($args[$r]) === ''))
            {
                $missing[] = (string) $r;
            }
        }

        if ($missing !== [])
        {
            $props = (array) ($schema['properties'] ?? []);
            $hint = [];

            foreach ($missing as $m)
            {
                $hint[] = $m.(isset($props[$m]['description']) ? ' ('.$props[$m]['description'].')' : '');
            }

            return ['content' => [['type' => 'text', 'text' =>
                'Не хватает обязательных аргументов: '.implode(', ', $hint).'. Повторите вызов assistant.call, добавив их.']],
                'isError' => TRUE];
        }

        $t0 = microtime(TRUE);

        try
        {
            $output = Service_Nj160_Registry::call($name, $args, $this->_is_admin, $this->_user_id);
        }
        catch (Kohana_Exception $e)
        {
            return ['content' => [['type' => 'text', 'text' => 'Ошибка инструмента: '.$e->getMessage()]], 'isError' => TRUE];
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj160: assistant.call упал :n: :e', [':n' => $name, ':e' => $e->getMessage()]);

            return ['content' => [['type' => 'text', 'text' => 'Инструмент упал: '.$e->getMessage()]], 'isError' => TRUE];
        }

        if ($this->_token_hash !== NULL)
        {
            Service_Nj160_Tokens::touch($this->_token_hash);
        }

        Kohana::$log->add(Log::INFO, 'nj160: assistant.call :n за :ms мс',
            [':n' => $name, ':ms' => (int) round((microtime(TRUE) - $t0) * 1000)]);

        $text = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $max  = (int) Kohana::$config->load('nj160_mcp.max_result_chars');

        if ($max > 0 AND strlen($text) > $max)
        {
            $text = substr($text, 0, $max)."\n… (обрезано)";
        }

        $result = ['content' => [['type' => 'text', 'text' => $text]]];

        if (isset($output['error']))
        {
            $result['isError'] = TRUE;
        }

        return $result;
    }

    /** Bearer: legacy MCP_TOKEN (.env) ИЛИ персональный токен nj_160_tokens. */
    protected function _authorize()
    {
        $given = $this->request->headers('authorization');

        if ( ! $given OR ! preg_match('/^Bearer\s+(.+)$/i', (string) $given, $m))
        {
            return FALSE;
        }

        $plain = trim($m[1]);

        // 1) персональный токен (self-service на /mcp): уровень доступа — по роли владельца
        if (class_exists('Service_Nj160_Tokens'))
        {
            [$ok, $user_id] = Service_Nj160_Tokens::authenticate($plain);

            if ($ok)
            {
                $this->_token_hash = hash('sha256', $plain); // для учёта вызовов
                $this->_user_id    = (int) $user_id;
                $this->_is_admin   = Service_Nj160_Tokens::is_admin($user_id);

                return TRUE;
            }
        }

        // 2) legacy: единый MCP_TOKEN из .env (пустой = закрыто, кроме development).
        // Серверный ключ — уровень админа (им настраивают хаб и пиналку).
        $token = trim((string) getenv('MCP_TOKEN'));

        if ($token === '')
        {
            if (Kohana::$environment === Kohana::DEVELOPMENT)
            {
                $this->_is_admin = TRUE;

                return TRUE;
            }

            return FALSE;
        }

        if (hash_equals($token, $plain))
        {
            $this->_is_admin = TRUE;

            return TRUE;
        }

        return FALSE;
    }

    protected function _error($id, $code, $message)
    {
        $this->_json(['jsonrpc' => '2.0', 'id' => $id,
                      'error' => ['code' => $code, 'message' => $message]]);
    }

    protected function _json(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
