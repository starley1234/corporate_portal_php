<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Минимальный клиент MCP поверх Streamable HTTP (nj676_mcphub).
 *
 * Зачем свой, а не библиотека: сервер платформы работает на PHP без
 * composer-зависимостей, а нам нужны ровно четыре шага протокола —
 * initialize → notifications/initialized → tools/list → tools/call.
 *
 * Что важно и часто ломается у наивных прокси:
 *   * сервер отдаёт заголовок `Mcp-Session-Id` — его ОБЯЗАНО нужно вернуть в
 *     следующих запросах, иначе tools/call отвечает 400;
 *   * `Accept` должен разрешать и JSON, и `text/event-stream`: многие серверы
 *     (Cloudflare, Hono) принципиально отвечают потоком;
 *   * после initialize нужно отправить уведомление `notifications/initialized`
 *     (ответ 202 и пустое тело);
 *   * старые серверы знают только `2024-11-05`, поэтому версия протокола берётся
 *     из ответа на initialize, а не навязывается.
 *
 * Безопасность: только HTTPS и публичный хост (проверка на стороне вызывающего,
 * см. Service_Nj676_Gateway::public_https_url). Редиректы не выполняем,
 * размер и время ответа ограничены.
 */
class Service_Nj676_McpClient
{
    /** Что предлагаем серверу. Порядок — от нового к старому. */
    const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    const MAX_BYTES = 524288;
    const CONNECT_TIMEOUT = 6;

    /**
     * Открыть сессию: initialize + notifications/initialized.
     *
     * @param  string $url
     * @param  array  $opts  timeout, token (Bearer), user_agent
     * @return array  ['ok' => bool, 'session' => array, 'error' => string, 'ms' => int, 'server' => array]
     */
    public static function open($url, array $opts = [])
    {
        $t0 = microtime(TRUE);
        $session = [
            'url' => (string) $url,
            'id' => '',
            'protocol' => '',
            'server' => [],
            'capabilities' => [],
            'token' => (string) ($opts['token'] ?? ''),
            'retry_after' => 0,
        ];

        $init = self::post($session, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => $opts['protocol'] ?? self::PROTOCOL_VERSIONS[0],
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'n2j-mcphub', 'version' => '1.0'],
            ],
        ], $opts);

        if ( ! $init['ok'])
        {
            return ['ok' => FALSE, 'session' => $session, 'error' => $init['error'],
                'ms' => self::ms($t0), 'server' => []];
        }

        if (isset($init['payload']['error']))
        {
            return ['ok' => FALSE, 'session' => $session,
                'error' => 'initialize: '.self::error_text($init['payload']['error']),
                'ms' => self::ms($t0), 'server' => []];
        }

        $result = (array) ($init['payload']['result'] ?? []);
        $session['protocol'] = (string) ($result['protocolVersion'] ?? '');
        $session['server'] = (array) ($result['serverInfo'] ?? []);
        $session['capabilities'] = (array) ($result['capabilities'] ?? []);

        // Уведомление об инициализации — без него часть серверов закрывает сессию.
        self::post($session, [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], $opts);

        return ['ok' => TRUE, 'session' => $session, 'error' => '', 'ms' => self::ms($t0), 'server' => $session['server']];
    }

    /**
     * tools/list. → ['ok', 'tools' => [...], 'error', 'ms', 'session']
     */
    public static function list_tools($url, array $opts = [])
    {
        $t0 = microtime(TRUE);
        $open = self::open($url, $opts);

        if ( ! $open['ok'])
        {
            return ['ok' => FALSE, 'tools' => [], 'error' => $open['error'], 'ms' => self::ms($t0), 'session' => $open['session']];
        }

        $list = self::post($open['session'], [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => new stdClass(),
        ], $opts);

        if ( ! $list['ok'])
        {
            return ['ok' => FALSE, 'tools' => [], 'error' => $list['error'], 'ms' => self::ms($t0), 'session' => $open['session']];
        }

        if (isset($list['payload']['error']))
        {
            return ['ok' => FALSE, 'tools' => [], 'error' => self::error_text($list['payload']['error']),
                'ms' => self::ms($t0), 'session' => $open['session']];
        }

        $tools = [];
        foreach ((array) ($list['payload']['result']['tools'] ?? []) as $tool)
        {
            if ( ! is_array($tool) OR empty($tool['name']))
            {
                continue;
            }

            $tools[] = [
                'name' => (string) $tool['name'],
                'description' => mb_substr(trim((string) ($tool['description'] ?? '')), 0, 600),
                'inputSchema' => isset($tool['inputSchema']) && is_array($tool['inputSchema'])
                    ? $tool['inputSchema'] : ['type' => 'object'],
            ];

            if (count($tools) >= 200)
            {
                break;
            }
        }

        return ['ok' => TRUE, 'tools' => $tools, 'error' => '', 'ms' => self::ms($t0),
            'session' => $open['session'], 'server' => $open['server']];
    }

    /**
     * tools/call. → ['ok', 'result' => array, 'error', 'ms']
     */
    public static function call_tool($url, $tool, array $arguments, array $opts = [])
    {
        $t0 = microtime(TRUE);
        $open = self::open($url, $opts);

        if ( ! $open['ok'])
        {
            return ['ok' => FALSE, 'result' => [], 'error' => $open['error'], 'ms' => self::ms($t0)];
        }

        $answer = self::post($open['session'], [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => (string) $tool,
                // Пустой объект, а не массив: иначе strict-серверы видят [] вместо {}.
                'arguments' => $arguments === [] ? new stdClass() : $arguments,
            ],
        ], $opts);

        if ( ! $answer['ok'])
        {
            return ['ok' => FALSE, 'result' => [], 'error' => $answer['error'], 'ms' => self::ms($t0),
                'status' => (int) ($answer['status'] ?? 0),
                'retry_after' => (int) ($answer['retry_after'] ?? 0)];
        }

        if (isset($answer['payload']['error']))
        {
            return ['ok' => FALSE, 'result' => [], 'error' => self::error_text($answer['payload']['error']),
                'ms' => self::ms($t0)];
        }

        return ['ok' => TRUE, 'result' => (array) ($answer['payload']['result'] ?? []), 'error' => '',
            'ms' => self::ms($t0)];
    }

    /**
     * Один POST JSON-RPC в рамках сессии. Заголовки ответа читаются вручную,
     * потому что Mcp-Session-Id нужен из них.
     *
     * @return array ['ok' => bool, 'payload' => array, 'error' => string, 'status' => int]
     */
    public static function post(array &$session, array $body, array $opts = [])
    {
        if ( ! function_exists('curl_init'))
        {
            return ['ok' => FALSE, 'payload' => [], 'error' => 'на сервере нет расширения curl', 'status' => 0];
        }

        $timeout = (int) ($opts['timeout'] ?? 20);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'User-Agent: '.(string) ($opts['user_agent'] ?? 'n2j-mcphub/1.0'),
        ];

        if ($session['id'] !== '')
        {
            $headers[] = 'Mcp-Session-Id: '.$session['id'];
        }
        if ($session['protocol'] !== '')
        {
            $headers[] = 'MCP-Protocol-Version: '.$session['protocol'];
        }
        if (($session['token'] ?? '') !== '')
        {
            $headers[] = 'Authorization: Bearer '.$session['token'];
        }

        $ch = curl_init((string) $session['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => TRUE,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => max(5, min(60, $timeout)),
            CURLOPT_SSL_VERIFYPEER => TRUE,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$session)
            {
                if (stripos($line, 'mcp-session-id:') === 0)
                {
                    $session['id'] = trim(substr($line, strlen('mcp-session-id:')));
                }
                elseif (stripos($line, 'mcp-protocol-version:') === 0)
                {
                    $session['protocol'] = trim(substr($line, strlen('mcp-protocol-version:')));
                }
                elseif (stripos($line, 'retry-after:') === 0)
                {
                    // Сервер просит подождать: агент должен узнать СКОЛЬКО, иначе
                    // повторяет сразу и снова получает 429.
                    $session['retry_after'] = max(0, min(3600,
                        (int) trim(substr($line, strlen('retry-after:')))));
                }

                return strlen($line);
            },
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === FALSE OR $error !== '')
        {
            return ['ok' => FALSE, 'payload' => [], 'error' => 'сеть: '.($error !== '' ? $error : 'нет ответа'), 'status' => $status];
        }
        if (strlen((string) $raw) > self::MAX_BYTES)
        {
            return ['ok' => FALSE, 'payload' => [], 'error' => 'ответ больше '.self::MAX_BYTES.' байт', 'status' => $status];
        }
        if ($status === 401 OR $status === 403)
        {
            return ['ok' => FALSE, 'payload' => [], 'error' => 'сервер требует авторизации (HTTP '.$status.')', 'status' => $status];
        }
        if ($status === 429)
        {
            $wait = (int) ($session['retry_after'] ?? 0);
            // Значение относится к этому ответу: следующий запрос ждёт столько,
            // сколько скажет он сам, а не сколько просил предыдущий.
            $session['retry_after'] = 0;

            return ['ok' => FALSE, 'payload' => [], 'status' => $status, 'retry_after' => $wait,
                'error' => 'HTTP 429'.($wait > 0 ? ' (повторить через '.$wait.' с)' : '').self::body_hint($raw)];
        }
        if ($status === 202 OR trim((string) $raw) === '')
        {
            // Уведомление или ответ без тела — это не ошибка.
            return ['ok' => $status >= 200 AND $status < 300, 'payload' => [], 'error' => '', 'status' => $status];
        }
        if ($status < 200 OR $status >= 300)
        {
            return ['ok' => FALSE, 'payload' => [], 'error' => 'HTTP '.$status.self::body_hint($raw), 'status' => $status];
        }

        $payload = json_decode((string) $raw, TRUE);

        if ( ! is_array($payload))
        {
            $payload = self::parse_sse((string) $raw, $body['id'] ?? NULL);
        }

        if ( ! is_array($payload))
        {
            return ['ok' => FALSE, 'payload' => [], 'error' => 'ответ не JSON и не SSE', 'status' => $status];
        }

        return ['ok' => TRUE, 'payload' => $payload, 'error' => '', 'status' => $status];
    }

    /** В SSE-потоке нужен объект с тем же id (или последний полный JSON). */
    protected static function parse_sse($raw, $wanted_id = NULL)
    {
        $best = NULL;

        foreach (preg_split('/\r?\n/', $raw) as $line)
        {
            if (strpos($line, 'data:') !== 0)
            {
                continue;
            }

            $candidate = json_decode(trim(substr($line, 5)), TRUE);
            if ( ! is_array($candidate))
            {
                continue;
            }

            $best = $candidate;

            if ($wanted_id !== NULL AND isset($candidate['id']) AND $candidate['id'] == $wanted_id)
            {
                return $candidate;
            }
        }

        return $best;
    }

    /** Текст ошибки JSON-RPC в человеческом виде. */
    public static function error_text($error)
    {
        if (is_string($error))
        {
            return $error;
        }
        if ( ! is_array($error))
        {
            return 'неизвестная ошибка';
        }

        $message = trim((string) ($error['message'] ?? ''));
        $data = $error['data'] ?? NULL;

        if (is_array($data) AND ! empty($data['message']))
        {
            $message = $data['message'].($message !== '' ? ' / '.$message : '');
        }

        return $message !== '' ? mb_substr($message, 0, 400) : json_encode($error, JSON_UNESCAPED_UNICODE);
    }

    /** Короткая подсказка из тела ошибочного ответа (первые 200 символов). */
    protected static function body_hint($raw)
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $raw)));

        return $text !== '' ? ' — '.mb_substr($text, 0, 200) : '';
    }

    protected static function ms($start)
    {
        return (int) round((microtime(TRUE) - (float) $start) * 1000);
    }
}
