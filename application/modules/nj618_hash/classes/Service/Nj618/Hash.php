<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Хеш-инструмент (модуль NJ-618) — MD5/SHA хеши строк и файлов.
 *
 * Одна точка истины для страницы (/hash), API (/nj618/api/*) и агентов.
 * Чистые функции поверх стандартного hash()/hash_file().
 */
class Service_Nj618_Hash {

    const MAX_INPUT = 4 * 1024 * 1024;

    /** Доступные алгоритмы: [code => label]. */
    public static function algos()
    {
        return [
            'md5'    => 'MD5 (32)',
            'sha1'   => 'SHA-1 (40)',
            'sha256' => 'SHA-256 (64)',
            'sha384' => 'SHA-384 (96)',
            'sha512' => 'SHA-512 (128)',
        ];
    }

    /** Хеш строки. */
    public static function hash_text($text, $algo, $upper = FALSE)
    {
        $text = (string) $text;

        if ($text === '')
        {
            return ['ok' => FALSE, 'hash' => '', 'error' => 'Вставьте текст — поле пустое.'];
        }

        if (mb_strlen($text) > self::MAX_INPUT)
        {
            return ['ok' => FALSE, 'hash' => '', 'error' => 'Слишком большой текст.'];
        }

        if ( ! in_array($algo, array_keys(self::algos()), TRUE))
        {
            return ['ok' => FALSE, 'hash' => '', 'error' => 'Неизвестный алгоритм: '.$algo];
        }

        $hex = hash($algo, $text);

        if ($hex === FALSE)
        {
            return ['ok' => FALSE, 'hash' => '', 'error' => 'Не удалось посчитать хеш.'];
        }

        return ['ok' => TRUE, 'hash' => $upper ? strtoupper($hex) : $hex, 'error' => ''];
    }

    /** Хеш загруженного файла (путь к временному файлу прогоняется hash_file). */
    public static function hash_file_path($path, $algo, $upper = FALSE)
    {
        if ( ! is_file($path))
        {
            return ['ok' => FALSE, 'hash' => '', 'error' => 'Файл не найден.'];
        }

        if ( ! in_array($algo, array_keys(self::algos()), TRUE))
        {
            return ['ok' => FALSE, 'hash' => '', 'error' => 'Неизвестный алгоритм: '.$algo];
        }

        $hex = hash_file($algo, $path);

        if ($hex === FALSE)
        {
            return ['ok' => FALSE, 'hash' => '', 'error' => 'Не удалось прочитать файл.'];
        }

        return ['ok' => TRUE, 'hash' => $upper ? strtoupper($hex) : $hex, 'error' => ''];
    }
}
