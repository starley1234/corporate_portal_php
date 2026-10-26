<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Кодировщик строк (модуль NJ-616) — чистые функции, БД и воркеры не нужны.
 *
 * Base64 (UTF-8-aware), URL-кодирование (стандартное и raw). Одна точка истины
 * для страницы (/convert), API (/nj616/api/*) и агентов. JS-зеркало — во
 * views/616/script.php.
 */
class Service_Nj616_Convert {

    const MAX_INPUT = 4 * 1024 * 1024;

    /** Доступные операции: [code => [label, icon]] для UI и API. */
    public static function modes()
    {
        return [
            'b64_encode'   => ['Base64 — закодировать', 'fas fa-lock-open'],
            'b64_decode'   => ['Base64 — раскодировать', 'fas fa-lock'],
            'url_encode'   => ['URL — закодировать', 'fas fa-link'],
            'url_decode'   => ['URL — раскодировать', 'fas fa-unlink'],
            'urlraw_encode'=> ['URL — закодировать (raw)', 'fas fa-link'],
            'urlraw_decode'=> ['URL — раскодировать (raw)', 'fas fa-unlink'],
        ];
    }

    /**
     * Применить операцию к тексту.
     *
     * @param  string $text
     * @param  string $mode код операции из modes()
     * @return array  ['ok','output','error']
     */
    public static function convert($text, $mode)
    {
        $text = (string) $text;

        if ($text === '')
        {
            return ['ok' => FALSE, 'output' => '', 'error' => 'Вставьте текст — поле пустое.'];
        }

        if (mb_strlen($text) > self::MAX_INPUT)
        {
            return ['ok' => FALSE, 'output' => '', 'error' => 'Слишком большой текст.'];
        }

        switch ($mode)
        {
            case 'b64_encode':
                return ['ok' => TRUE, 'output' => base64_encode($text), 'error' => ''];
            case 'b64_decode':
                $dec = base64_decode(str_replace(["\r", "\n", ' '], '', $text), TRUE);
                if ($dec === FALSE)
                {
                    return ['ok' => FALSE, 'output' => '', 'error' => 'Невалидный Base64 — проверьте входную строку.'];
                }
                return ['ok' => TRUE, 'output' => $dec, 'error' => ''];
            case 'url_encode':
                return ['ok' => TRUE, 'output' => urlencode($text), 'error' => ''];
            case 'url_decode':
                return ['ok' => TRUE, 'output' => urldecode($text), 'error' => ''];
            case 'urlraw_encode':
                return ['ok' => TRUE, 'output' => rawurlencode($text), 'error' => ''];
            case 'urlraw_decode':
                return ['ok' => TRUE, 'output' => rawurldecode($text), 'error' => ''];
        }

        return ['ok' => FALSE, 'output' => '', 'error' => 'Неизвестная операция: '.$mode];
    }
}
