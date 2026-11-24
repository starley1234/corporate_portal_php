<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Распознавание документов через внешний HTTP-шлюз.
 *
 * Контракт шлюза — как в его OpenAPI: POST /parse, multipart `file`, query-
 * параметры (engine, output_format, llm_fix, …), ответ {file, content, …}.
 * Модуль не знает, что за шлюз: боевой Docling/MinerU/PaddleOCR или локальная
 * обёртка над Tesseract. Переключение — только PRX450_OCR_BASE_URL.
 *
 * Что делает модуль сверх прокси:
 *   - проверяет файл (размер, MIME) и параметры (белый список overrides);
 *   - не отдаёт потребителю внутренние адреса, токены и HTML ошибок шлюза;
 *   - пишет событие и технический лог без текста документа;
 *   - возвращает тот же JSON, что и шлюз, — потребитель видит один контракт.
 */
class Service_Prx450_Ocr
{
    public const MODULE = 'prx450';
    public const SCOPE = 'prx450.parse';

    private const ENGINES = ['auto', 'docling-cpu', 'mineru', 'paddleocr-vl'];
    private const FORMATS = ['md', 'full'];

    /**
     * @param array|null $upload   строка $_FILES['file']
     * @param array      $options  query/post запроса; применяются только client_overrides
     * @return array {file, content, cached, cache_source, engine_used, llm_fixed, raw_result}
     */
    public static function parse($upload, array $options = [], ?int $initiator_id = NULL, ?int $api_client_id = NULL): array
    {
        $config = Kohana::$config->load('prx450_document_ocr')->as_array();
        self::assert_request_size((int) ($_SERVER['CONTENT_LENGTH'] ?? 0), (string) ini_get('post_max_size'));
        $file = self::_validate_upload($upload, $config);
        $values = self::_values($config, $options);
        $query = self::_query($values);

        $started = microtime(TRUE);

        [$status, $body] = self::_post($config['upstream'], $file, $query, (int) $config['limits']['max_response_bytes']);

        $result = self::_result($status, $body, $config, $values['output_format']);
        $ms = (int) round((microtime(TRUE) - $started) * 1000);

        Log_Plain::event(Log::INFO, 'prx450 parsed bytes=:bytes engine=:engine ms=:ms cached=:cached', [
            ':bytes' => $file['size'], ':engine' => $values['engine'],
            ':ms' => $ms, ':cached' => $result['cached'] ? 1 : 0,
        ]);
        Event::publish('prx450.document.parsed', [
            'api_client_id' => $api_client_id,
            'engine' => $result['engine_used'], 'duration_ms' => $ms, 'cached' => $result['cached'],
        ], $initiator_id);

        return $result;
    }

    /** PHP discards oversized multipart bodies before the action (including CSRF). */
    public static function assert_request_size(int $length, string $post_max): void
    {
        $value = trim($post_max);
        $limit = (int) $value;
        $unit = strtolower(substr($value, -1));
        if (in_array($unit, ['k', 'm', 'g'], TRUE)) $limit *= 1024 ** (strpos('kmg', $unit) + 1);
        if ($limit > 0 && $length > $limit) throw new Prx450_Error('file_too_large', 413, ['limit_bytes' => $limit]);
    }

    /** Настройки для страницы: адрес без секрета и лимиты. */
    public static function summary(): array
    {
        $config = Kohana::$config->load('prx450_document_ocr')->as_array();

        return [
            'base_url'   => (string) $config['upstream']['base_url'],
            'parse_path' => (string) $config['upstream']['parse_path'],
            'timeout'    => (int) $config['upstream']['timeout'],
            'has_key'    => $config['upstream']['api_key'] !== '',
            'defaults'   => $config['defaults'],
            'max_bytes'  => (int) $config['limits']['max_file_bytes'],
            'mime'       => $config['allowed_mime'],
            'overrides'  => $config['client_overrides'],
        ];
    }

    // ---------------------------------------------------------------------

    /** Файл: ошибка загрузки, размер, MIME. Исходное имя не пишем в лог/БД. */
    protected static function _validate_upload($upload, array $config): array
    {
        if ( ! is_array($upload))
        {
            throw new Prx450_Error('file_required', 400);
        }

        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE))
        {
            throw new Prx450_Error('file_too_large', 413);
        }

        if ($error !== UPLOAD_ERR_OK)
        {
            throw new Prx450_Error($error === UPLOAD_ERR_NO_FILE ? 'file_required' : 'upload_unavailable', 400);
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');

        if ($tmp === '' OR ! is_uploaded_file($tmp))
        {
            throw new Prx450_Error('file_required', 400);
        }

        $size = (int) filesize($tmp);

        if ($size <= 0)
        {
            throw new Prx450_Error('empty_file', 400);
        }

        if ($size > (int) $config['limits']['max_file_bytes'])
        {
            throw new Prx450_Error('file_too_large', 413, [
                'limit_bytes' => (int) $config['limits']['max_file_bytes'],
            ]);
        }

        $mime = self::_mime($tmp);

        if ($mime === NULL OR ! in_array($mime, $config['allowed_mime'], TRUE))
        {
            throw new Prx450_Error('unsupported_file', 415, ['allowed' => $config['allowed_mime']]);
        }

        $signature = (string) file_get_contents($tmp, FALSE, NULL, 0, 12);
        if (!self::_signature($mime, $signature)) throw new Prx450_Error('unsupported_file', 415);
        $extensions = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg',
            'image/tiff' => 'tiff', 'image/webp' => 'webp', 'image/bmp' => 'bmp'];
        return ['tmp_name' => $tmp, 'name' => 'document.'.($extensions[$mime] ?? 'bin'), 'size' => $size, 'type' => $mime];
    }

    protected static function _signature(string $mime, string $bytes): bool
    {
        switch ($mime) {
            case 'application/pdf': return substr($bytes, 0, 5) === '%PDF-';
            case 'image/png': return substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n";
            case 'image/jpeg': return substr($bytes, 0, 3) === "\xff\xd8\xff";
            case 'image/tiff': return in_array(substr($bytes, 0, 4), ["II\x2a\x00", "MM\x00\x2a"], TRUE);
            case 'image/webp': return substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP';
            case 'image/bmp': return substr($bytes, 0, 2) === 'BM';
        }
        return FALSE;
    }

    protected static function _mime(string $path): ?string
    {
        if (class_exists('finfo'))
        {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);

            return is_string($mime) && $mime !== '' ? $mime : NULL;
        }

        $mime = mime_content_type($path);

        return is_string($mime) && $mime !== '' ? $mime : NULL;
    }

    /** Параметры запроса: defaults + разрешённые overrides с валидацией. */
    protected static function _values(array $config, array $options): array
    {
        $values = $config['defaults'];
        $allowed = (array) $config['client_overrides'];

        foreach ($allowed as $key)
        {
            if ( ! array_key_exists($key, $options))
            {
                continue;
            }

            $value = $options[$key];
            if (!is_scalar($value) && $value !== NULL) throw new Prx450_Error('invalid_option', 422, ['field' => $key]);

            if ($value === NULL OR $value === '')
            {
                continue;
            }

            $values[$key] = $value;
        }

        if ( ! in_array($values['engine'], self::ENGINES, TRUE))
        {
            throw new Prx450_Error('invalid_option', 422, ['field' => 'engine']);
        }

        if ( ! in_array($values['output_format'], self::FORMATS, TRUE))
        {
            throw new Prx450_Error('invalid_option', 422, ['field' => 'output_format']);
        }

        foreach (['preprocess', 'ocr', 'extract_images', 'vlm_pictures', 'extract_tables', 'llm_fix', 'force'] as $bool)
        {
            $value = $values[$bool] ?? FALSE;
            if (!in_array(strtolower((string) $value), ['', '0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], TRUE))
                throw new Prx450_Error('invalid_option', 422, ['field' => $bool]);
            $values[$bool] = self::_bool($value);
        }

        $side = filter_var($values['max_image_side'] ?? 2048, FILTER_VALIDATE_INT);
        if ($side === FALSE || $side < 512 || $side > 8192) throw new Prx450_Error('invalid_option', 422, ['field' => 'max_image_side']);
        $values['max_image_side'] = $side;
        $values['llm_model'] = mb_substr((string) ($values['llm_model'] ?? 'default'), 0, 64);

        if ($values['llm_model'] !== '' AND ! preg_match('/\A[A-Za-z0-9._:\/-]{1,64}\z/D', $values['llm_model']))
        {
            throw new Prx450_Error('invalid_option', 422, ['field' => 'llm_model']);
        }

        $values['llm_prompt'] = isset($values['llm_prompt']) ? mb_substr((string) $values['llm_prompt'], 0, 4000) : NULL;

        if ( ! empty($values['extra_config']))
        {
            $values['extra_config'] = mb_substr((string) $values['extra_config'], 0, 4000);
            $decoded = json_decode($values['extra_config'], TRUE);

            if ( ! is_array($decoded))
            {
                throw new Prx450_Error('invalid_option', 422, ['field' => 'extra_config']);
            }
        }
        else
        {
            $values['extra_config'] = NULL;
        }

        return $values;
    }

    protected static function _bool($value): bool
    {
        if (is_bool($value))
        {
            return $value;
        }

        if (is_string($value))
        {
            return ! in_array(strtolower(trim($value)), ['false', '0', 'no', 'off', ''], TRUE);
        }

        return (bool) $value;
    }

    /** Query-строка: bool как true/false, null и пустые — не отправляем. */
    protected static function _query(array $values): string
    {
        $parts = [];

        foreach ($values as $key => $value)
        {
            if ($value === NULL OR $value === '')
            {
                continue;
            }

            $parts[$key] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        return http_build_query($parts);
    }

    /** POST multipart на шлюз; cURL-ошибки → 503/504 без внутренних деталей. */
    protected static function _post(array $upstream, array $file, string $query, int $max_bytes = 16777216): array
    {
        $base = rtrim((string) $upstream['base_url'], '/');
        $url = $base.(string) $upstream['parse_path'].($query !== '' ? '?'.$query : '');

        if ( ! preg_match('#\Ahttps?://#i', $url))
        {
            throw new Prx450_Error('ocr_config', 503);
        }

        $headers = ['Accept: application/json'];

        if ($upstream['api_key'] !== '')
        {
            $scheme = trim((string) $upstream['auth_scheme']);
            $headers[] = $upstream['auth_header'].': '.($scheme !== '' ? $scheme.' ' : '').$upstream['api_key'];
        }

        $ch = curl_init($url);

        if ($ch === FALSE)
        {
            throw new Prx450_Error('ocr_unavailable', 503);
        }

        $body = ''; $oversized = FALSE;
        curl_setopt_array($ch, [
            CURLOPT_POST           => TRUE,
            CURLOPT_POSTFIELDS     => ['file' => new CURLFile($file['tmp_name'], $file['type'], $file['name'])],
            CURLOPT_RETURNTRANSFER => FALSE,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$oversized, $max_bytes): int {
                if (strlen($body) + strlen($chunk) > $max_bytes) { $oversized = TRUE; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADER         => FALSE,
            CURLOPT_TIMEOUT        => (int) $upstream['timeout'],
            CURLOPT_CONNECTTIMEOUT => (int) $upstream['connect_timeout'],
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => (bool) $upstream['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $upstream['verify_tls'] ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'PRX-OCR/1.0',
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($oversized) throw new Prx450_Error('result_too_large', 502, ['limit_bytes' => $max_bytes]);
        if ($errno !== 0)
        {
            if ($errno === CURLE_OPERATION_TIMEDOUT)
            {
                throw new Prx450_Error('ocr_timeout', 504);
            }

            if (in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], TRUE))
            {
                throw new Prx450_Error('ocr_unavailable', 503);
            }

            throw new Prx450_Error('ocr_unavailable', 503);
        }

        return [$status, is_string($body) ? $body : ''];
    }

    /** Ответ шлюза → публичный JSON; ошибки шлюза не проходят как есть. */
    protected static function _result(int $status, string $body, array $config, string $format): array
    {
        $json = json_decode($body, TRUE);

        if ($status === 413)
        {
            throw new Prx450_Error('file_too_large', 413);
        }

        if ($status === 415)
        {
            throw new Prx450_Error('unsupported_file', 415);
        }

        if (in_array($status, [401, 403], TRUE))
        {
            // Это наша настройка шлюза, не ключ потребителя: 503, без деталей.
            throw new Prx450_Error('ocr_auth', 503);
        }

        if ($status === 422)
        {
            throw new Prx450_Error('ocr_rejected', 422);
        }

        if ($status >= 500)
        {
            throw new Prx450_Error('ocr_upstream_error', 502, ['upstream_status' => $status]);
        }

        if ($status < 200 OR $status >= 300 OR ! is_array($json))
        {
            throw new Prx450_Error('ocr_invalid_response', 502, ['upstream_status' => $status]);
        }

        if ( ! isset($json['file'], $json['content']) OR ! is_string($json['file']) OR ! is_string($json['content']))
        {
            throw new Prx450_Error('ocr_invalid_response', 502);
        }

        if (strlen($json['content']) > (int) $config['limits']['max_content_bytes'])
            throw new Prx450_Error('result_too_large', 502, ['limit_bytes' => (int) $config['limits']['max_content_bytes']]);

        $raw = $format === 'full' ? ($json['raw_result'] ?? NULL) : NULL;
        if ($raw !== NULL && strlen(json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > (int) $config['limits']['max_raw_result_bytes'])
            throw new Prx450_Error('result_too_large', 502, ['limit_bytes' => (int) $config['limits']['max_raw_result_bytes']]);

        return [
            'file'         => mb_substr((string) $json['file'], 0, 255),
            'content'      => (string) $json['content'],
            'cached'       => self::_bool($json['cached'] ?? FALSE),
            'cache_source' => isset($json['cache_source']) && is_string($json['cache_source'])
                ? mb_substr($json['cache_source'], 0, 32) : NULL,
            'engine_used'  => isset($json['engine_used']) && is_string($json['engine_used'])
                ? mb_substr($json['engine_used'], 0, 64) : NULL,
            'llm_fixed'    => self::_bool($json['llm_fixed'] ?? FALSE),
            // Сырой JSON отдаём только при output_format=full, как в контракте.
            'raw_result'   => $raw,
        ];
    }
}
