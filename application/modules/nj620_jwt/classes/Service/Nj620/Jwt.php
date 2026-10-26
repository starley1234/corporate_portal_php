<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JWT-декодер (модуль NJ-620) — разбор токена на секции и claims.
 *
 * Одна точка истины для страницы (/jwt), API (/nj620/api/*) и агентов.
 * Только расшифровка и анализ структуры (не проверка подписи, не выпуск токенов):
 * header, payload (с JSON-ошибками, aud, iss, exp/iat/nbf с переводом в дату).
 */
class Service_Nj620_Jwt {

    /**
     * Декодировать одну base64url-секцию JWT.
     * @return ['ok', 'data' => array|object|null, 'raw', 'error']
     */
    public static function decode_segment($segment, $json = TRUE)
    {
        $segment = (string) $segment;

        if ($segment === '')
        {
            return ['ok' => FALSE, 'error' => 'Пустая секция.', 'raw' => ''];
        }

        // перевести base64url в base64
        $b64 = str_replace(['-', '_'], ['+', '/'], $segment);
        switch (strlen($b64) % 4)
        {
            case 0: break;
            case 2: $b64 .= '=='; break;
            case 3: $b64 .= '='; break;
            default: return ['ok' => FALSE, 'error' => 'Некорректная длина base64url.', 'raw' => ''];
        }

        $dec = base64_decode($b64, TRUE);

        if ($dec === FALSE)
        {
            return ['ok' => FALSE, 'error' => 'Невалидный base64url.', 'raw' => ''];
        }

        if ( ! $json)
        {
            return ['ok' => TRUE, 'raw' => $dec, 'error' => ''];
        }

        $data = json_decode($dec);

        if (json_last_error() !== JSON_ERROR_NONE)
        {
            return [
                'ok'    => FALSE,
                'error' => 'Секция не является корректным JSON: '.json_last_error_msg(),
                'raw'   => $dec,
            ];
        }

        return ['ok' => TRUE, 'data' => $data, 'raw' => $dec, 'error' => ''];
    }

    /** Убедиться, что это JWT/index: три секции через точку. */
    protected static function parts($token)
    {
        $token = trim((string) $token);

        return explode('.', $token);
    }

    /** Полный разбор токена. */
    public static function decode($token)
    {
        $token = trim((string) $token);

        if ($token === '')
        {
            return ['ok' => FALSE, 'error' => 'Вставьте JWT-токен.'];
        }

        $parts = self::parts($token);

        if (count($parts) !== 3)
        {
            return [
                'ok'    => FALSE,
                'error' => 'Токен должен состоять из трёх секций, разделённых точкой (header.payload.signature). Найдено: '.count($parts),
            ];
        }

        $head = self::decode_segment($parts[0]);
        $pay  = self::decode_segment($parts[1]);
        $sig  = self::decode_segment($parts[2], FALSE);

        $out = [
            'ok'         => TRUE,
            'error'      => '',
            'header_ok'  => $head['ok'],
            'header'     => isset($head['data']) ? $head['data'] : NULL,
            'header_err' => $head['ok'] ? '' : $head['error'],
            'payload_ok' => $pay['ok'],
            'claims'     => isset($pay['data']) ? $pay['data'] : NULL,
            'payload_err'=> $pay['ok'] ? '' : $pay['error'],
            'payload_raw'=> isset($pay['raw']) ? $pay['raw'] : '',
            'signature'  => $parts[2],
            'alg'        => (isset($head['data']->alg)) ? $head['data']->alg : NULL,
            'typ'        => (isset($head['data']->typ)) ? $head['data']->typ : NULL,
        ];

        // перевод временных claims в дату
        foreach (['exp', 'nbf', 'iat'] as $key)
        {
            $out['dates'][$key] = NULL;

            if ($pay['ok'] && isset($pay['data']->{$key}))
            {
                $v = $pay['data']->{$key};

                if (is_numeric($v))
                {
                    $out['dates'][$key] = [
                        'value' => (int) $v,
                        'iso'   => gmdate('Y-m-d\TH:i:s\Z', (int) $v),
                        'local' => Service_Nj619_UnixTime::format_in_tz((int) $v, 'Europe/Moscow'),
                    ];
                }
            }
        }

        return $out;
    }
}
