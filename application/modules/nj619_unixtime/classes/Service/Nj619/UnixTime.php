<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Unix-время (модуль NJ-619) — чистые функции: timestamp ↔ дата/время.
 *
 * Одна точка истины для страницы (/unixtime), API (/nj619/api/*) и агентов:
 * строгая арифметика эпох, обработка мс/с, «сейчас», ISO/человекочитаемый
 * вывод. JS-зеркало — во views/619/script.php.
 */
class Service_Nj619_UnixTime {

    /** Преобразовать timestamp (секунды ИЛИ миллисекунды) в читаемую дату. */
    public static function to_date($ts, $tz = 'Europe/Moscow')
    {
        $ts = (string) $ts;

        if ( ! preg_match('/^-?\d+$/', trim($ts)))
        {
            return ['ok' => FALSE, 'error' => 'Введите целое число (секунды или миллисекунды).'];
        }

        $n = self::as_seconds($ts);

        if (abs($n) > 82000000000) // > примерно 4600 года — защита
        {
            return ['ok' => FALSE, 'error' => 'Слишком большое значение — вероятно, это миллисекунды, а не секунды. Но и так вне диапазона.'];
        }

        $dt = self::format_in_tz($n, $tz);

        if ($dt === NULL)
        {
            return ['ok' => FALSE, 'error' => 'Не удалось отформатировать дату (часовой пояс?)'];
        }

        return [
            'ok'        => TRUE,
            'error'     => '',
            'seconds'   => (int) $n,
            'millis'    => (int) $n * 1000,
            'unit'      => self::unit_of($ts, $n),
            'iso'       => gmdate('Y-m-d\TH:i:s\Z', $n),
            'utc'       => gmdate('Y-m-d H:i:s', $n).' UTC',
            'local'     => $dt,
            'tz'        => $tz,
        ];
    }

    /** Интерпретировать ввод как секунды/миллисекунды. */
    public static function as_seconds($ts)
    {
        $ts = trim((string) $ts);

        if (preg_match('/^-?\d{1,10}$/', $ts))
        {
            return (int) $ts; // похоже на секунды
        }

        if (preg_match('/^-?\d{11,13}$/', $ts))
        {
            return (int) intdiv((int) $ts, 1000); // миллисекунды
        }

        return (int) $ts;
    }

    protected static function unit_of($raw, $seconds)
    {
        $raw = trim((string) $raw);

        if (preg_match('/^-?\d{11,13}$/', $raw))
        {
            return 'ms';
        }

        return 's';
    }

    /** Отформатировать дату в конкретном часовом поясе (fallback на UTC). */
    public static function format_in_tz($seconds, $tz)
    {
        try
        {
            $dtz = new DateTimeZone($tz ?: 'UTC');

            return (new DateTime('@'.(string) (int) $seconds))
                ->setTimezone($dtz)
                ->format('Y-m-d H:i:s');
        }
        catch (Throwable $e)
        {
            return NULL;
        }
    }

    /** Список популярных часовых поясов для интерфейса. */
    public static function timezones()
    {
        return [
            'Europe/Moscow' => 'Москва (UTC+3)',
            'Europe/Kaliningrad' => 'Калининград (UTC+2)',
            'Asia/Yekaterinburg' => 'Екатеринбург (UTC+5)',
            'Asia/Novosibirsk' => 'Новосибирск (UTC+7)',
            'Asia/Vladivostok' => 'Владивосток (UTC+10)',
            'Europe/Berlin' => 'Берлин',
            'Europe/London' => 'Лондон',
            'America/New_York' => 'Нью-Йорк',
            'Asia/Tokyo' => 'Токио',
            'UTC' => 'UTC',
        ];
    }

    /** Текущее время (для кнопки «сейчас» и API). */
    public static function now()
    {
        return self::to_date((string) time(), 'UTC');
    }
}
