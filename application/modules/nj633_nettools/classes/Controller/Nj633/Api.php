<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API сетевых инструментов (NJ-633) — для интеграций и агентов.
 *
 *   GET/POST /net/api/dns    ?host=example.com&type=A
 *   GET/POST /net/api/whois  ?host=example.com
 *   GET/POST /net/api/check  ?host=example.com&port=443
 *   GET/POST /net/api/ipconv ?value=192.168.0.1|3232235521  (IP↔integer)
 *   GET/POST /net/api/base64 ?text=...&mode=encode|decode
 *   POST      /net/api/llm   {target, prompt} → ставит LLM-объяснение в очередь
 *   POST      /net/api/llm_cb  — колбэк очереди nj120 (принимает job result)
 *
 * Безопасность на СЕРВЕРЕ: SSRF-щит по исходнику и по резолву DNS, никаких
 * shell-команд, rate limit по IP (счётчик в БД). Каждая проверка пишется в
 * nj_633_checks; LLM-пояснения — в nj_633_notes.
 */
class Controller_Nj633_Api extends Controller {

    /** @var bool API не рендерит шаблоны */
    public $auto_render = FALSE;

    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw = $this->request->body();

        if ($raw)
        {
            $decoded = json_decode($raw, TRUE);
            $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
        }
        else
        {
            $this->_body = (array) $this->request->post();
        }

        if (empty($this->_body) AND $this->request->query())
        {
            $this->_body = (array) $this->request->query();
        }
    }

    public function action_dns()
    {
        $host = (string) ($this->_body['host'] ?? $this->_body['domain'] ?? '');
        $type = strtoupper((string) ($this->_body['type'] ?? 'A'));

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj633_Nettools::dns_lookup($host, $type);

        Service_Nj633_Nettools::record_check('dns', $host, NULL, $result,
            ! empty($result['ok']) ? 'ok' : 'error',
            ! empty($result['error']) ? $result['error'] : NULL);

        $status = isset($result['ok']) && $result['ok'] ? 200 : 400;

        return $this->_respond(['ok' => isset($result['ok']) && $result['ok']] + $result, $status);
    }

    public function action_whois()
    {
        $host = (string) ($this->_body['host'] ?? $this->_body['domain'] ?? '');

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj633_Nettools::whois($host);

        Service_Nj633_Nettools::record_check('whois', $host, NULL,
            ['host' => $result['host'] ?? $host, 'server' => $result['server'] ?? '', 'lines' => $result['lines'] ?? 0],
            ! empty($result['ok']) ? 'ok' : 'error',
            ! empty($result['error']) ? $result['error'] : NULL);

        $status = isset($result['ok']) && $result['ok'] ? 200 : 400;

        return $this->_respond(['ok' => isset($result['ok']) && $result['ok']] + $result, $status);
    }

    public function action_check()
    {
        $host = (string) ($this->_body['host'] ?? '');
        $port = (int) ($this->_body['port'] ?? 80);

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj633_Nettools::tcp_check($host, $port > 0 ? $port : 80);

        Service_Nj633_Nettools::record_check('tcp', $host, $result['port'] ?? $port, $result,
            ! empty($result['ok']) ? 'ok' : 'error',
            ! empty($result['error']) ? $result['error'] : NULL);

        $status = isset($result['ok']) && $result['ok'] ? 200 : 400;

        return $this->_respond(['ok' => isset($result['ok']) && $result['ok']] + $result, $status);
    }

    /** Конвертер IP↔integer (вычисление, без сети — rate limit не требуется). */
    public function action_ipconv()
    {
        $value = (string) ($this->_body['value'] ?? $this->_body['ip'] ?? $this->_body['int'] ?? '');

        // ввод из одних цифр → int→IP, иначе IP→int
        if ($value !== '' AND ctype_digit($value))
        {
            $result = Service_Nj633_Nettools::int_to_ip($value);
        }
        else
        {
            $result = Service_Nj633_Nettools::ip_to_int($value);
        }

        $status = isset($result['ok']) && $result['ok'] ? 200 : 400;

        // конвертеры не сетевые: в rate limit не учитываются, но в историю пишем
        Service_Nj633_Nettools::record_check('ipconv', $value, NULL, $result,
            ! empty($result['ok']) ? 'ok' : 'error',
            ! empty($result['error']) ? $result['error'] : NULL);

        return $this->_respond(['ok' => isset($result['ok']) && $result['ok']] + $result, $status);
    }

    /** Конвертер Base64 encode/decode (вычисление, без сети — rate limit не требуется). */
    public function action_base64()
    {
        $text = (string) ($this->_body['text'] ?? '');
        $mode = (string) ($this->_body['mode'] ?? 'encode');

        $result = Service_Nj633_Nettools::base64_convert($text, $mode === 'decode' ? 'decode' : 'encode');

        $status = isset($result['ok']) && $result['ok'] ? 200 : 400;

        // факт преобразования пишем без самого текста (там может быть приватное)
        Service_Nj633_Nettools::record_check('base64', ($mode === 'decode' ? 'decode ' : 'encode ').mb_strlen($text).'c',
            NULL, ['ok' => isset($result['ok']) && $result['ok'], 'mode' => $mode, 'len' => mb_strlen($text)],
            ! empty($result['ok']) ? 'ok' : 'error',
            ! empty($result['error']) ? $result['error'] : NULL);

        return $this->_respond(['ok' => isset($result['ok']) && $result['ok']] + $result, $status);
    }

    public function action_llm()
    {
        $target = (string) ($this->_body['target'] ?? $this->_body['host'] ?? '');
        $prompt = (string) ($this->_body['prompt'] ?? '');

        $check_id = ! empty($this->_body['check_id']) ? (int) $this->_body['check_id'] : NULL;

        if ($prompt === '')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Задайте вопрос — что объяснить.'], 400);
        }

        $result = Service_Nj633_Nettools::queue_llm_note($check_id, $target, $prompt);

        $status = isset($result['ok']) && $result['ok'] ? 200 : 400;

        return $this->_respond(['ok' => isset($result['ok']) && $result['ok']] + $result, $status);
    }

    public function action_llm_note()
    {
        $note_id = (int) ($this->_body['note_id'] ?? $this->_body['id'] ?? 0);

        $note = Service_Nj633_Nettools::note_status($note_id);

        if ($note === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Объяснение не найдено.'], 404);
        }

        return $this->_respond(['ok' => TRUE] + $note);
    }

    /** Колбэк очереди nj120: результат LLM-объяснения. */
    public function action_llm_cb()
    {
        $job_id = (int) ($this->_body['job_id'] ?? 0);
        $status = (string) ($this->_body['status'] ?? '');
        $result = $this->_body['result'] ?? NULL;
        $error  = (string) ($this->_body['error'] ?? '');

        // находим заметку по job_id
        $note = Service_Nj633_Nettools::note_by_job($job_id);

        if ($note === NULL)
        {
            return $this->_respond(['ok' => TRUE]); // не наша задача — не шумим
        }

        Service_Nj633_Nettools::llm_callback($note['id'], $status, $result, $error);

        return $this->_respond(['ok' => TRUE]);
    }

    protected function _rate_ok()
    {
        return class_exists('Service_Nj633_Nettools')
            ? Service_Nj633_Nettools::rate_ok(Service_Nj633_Nettools::client_ip())
            : TRUE;
    }

    protected function _rate_error()
    {
        return 'Слишком много сетевых проверок с этого адреса. Лимит: '
            .Service_Nj633_Nettools::rate_limit_text().' — попробуйте позже.';
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
