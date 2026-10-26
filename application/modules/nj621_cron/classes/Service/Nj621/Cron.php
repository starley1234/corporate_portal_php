<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Cron-человечизатор (модуль NJ-621) — перевод crontab-выражения в слова.
 *
 * Одна точка истины для страницы (/cron), API (/nj621/api/*) и агентов.
 * Классический cron: минута час день месяц день_недели + необязательная команда.
 * Поддержка: *, числа, диапазоны a-b, шаги *&#47;n и a-b/n, списки через запятую.
 */
class Service_Nj621_Cron {

    const MAX_REASONABLE_YEARS = 20;

    /** Человекочитаемый разбор cron-выражения. */
    public static function explain($expr)
    {
        $expr = trim((string) $expr);

        if ($expr === '')
        {
            return ['ok' => FALSE, 'error' => 'Введите cron-выражение.'];
        }

        $parts = preg_split('/\s+/', $expr);

        if (count($parts) < 5)
        {
            return [
                'ok'    => FALSE,
                'error' => 'Нужны минимум 5 полей: минута час день месяц день_недели. Получено: '.count($parts),
            ];
        }

        $minute  = $parts[0];
        $hour    = $parts[1];
        $day     = $parts[2];
        $month   = $parts[3];
        $weekday = $parts[4];
        $command = array_slice($parts, 5);

        $errors = [];
        self::check_field($minute, 0, 59, 'минута', $errors);
        self::check_field($hour, 0, 23, 'час', $errors);
        self::check_field($day, 1, 31, 'день', $errors);
        self::check_field($month, 1, 12, 'месяц', $errors);
        self::check_field($weekday, 0, 7, 'день недели', $errors);

        if ($errors)
        {
            return ['ok' => FALSE, 'error' => implode(' ', $errors)];
        }

        $human = trim(preg_replace('/\s{2,}/', ' ', 'Выполняется '.self::describe_time($minute, $hour)
            . self::describe_days($day, $month, $weekday)));

        return [
            'ok'      => TRUE,
            'error'   => '',
            'human'   => $human,
            'fields'  => [
                'minute'  => $minute,
                'hour'    => $hour,
                'day'     => $day,
                'month'   => $month,
                'weekday' => $weekday,
            ],
            'command' => $command ? implode(' ', $command) : '',
            'next'    => self::next_runs($minute, $hour, $day, $month, $weekday),
        ];
    }

    protected static function check_field($field, $min, $max, $name, &$errors)
    {
        $field = trim($field);

        if ($field === '' && $name !== 'день недели')
        {
            $errors[] = "Поле «{$name}» пустое.";
            return;
        }

        foreach (explode(',', $field) as $piece)
        {
            if ($piece === '' || ! preg_match('/^\*$|^\d+$|^\d+-\d+$|^\*\/\d+$|^\d+-\d+\/\d+$|^\d+\/\d+$/', $piece))
            {
                $errors[] = "Некорректный фрагмент «{$piece}» в поле «{$name}».";
                continue;
            }

            // границы
            if (preg_match('/^(\d+)-(\d+)$/', $piece, $m)
                && ((int) $m[1] < $min || (int) $m[2] > $max || (int) $m[1] > (int) $m[2]))
            {
                $errors[] = "Диапазон «{$piece}» вне допустимых границ поля «{$name}» ({$min}–{$max}).";
            }
            elseif (preg_match('/^(\d+)$/', $piece, $m) && ((int) $m[1] < $min || (int) $m[1] > $max))
            {
                $errors[] = "Значение «{$piece}» вне допустимых границ поля «{$name}» ({$min}–{$max}).";
            }
        }
    }

    protected static function n($v)
    {
        return is_numeric($v) ? (int) $v : $v;
    }

    /** Склейка «минута + час» в естественную фразу. */
    protected static function describe_time($minute, $hour)
    {
        $minute = trim($minute);
        $hour   = trim($hour);

        $every_minute = ($minute === '*');
        $on_the_hour  = ($minute === '0');
        $step_min     = (preg_match('/^\*\/\d+$/', $minute)) ? self::n(substr($minute, 2)) : NULL;

        if ($hour === '*')
        {
            if ($every_minute)         return 'каждую минуту ';
            if ($on_the_hour)          return 'в начале каждого часа ';
            if ($step_min !== NULL)    return 'каждые '.$step_min.' минут ';
            return 'в минуту '.$minute.' ';
        }

        // час задан конкретно
        if ($hour === '0')
        {
            if ($on_the_hour)          return 'в полночь ';
            if ($every_minute)         return 'каждую минуту с 00:00 до 00:59 ';
            if ($step_min !== NULL)    return 'каждые '.$step_min.' минут с 00:00 до 00:59 ';
            return 'в 00:'.str_pad($minute, 2, '0', STR_PAD_LEFT).' ';
        }

        if (preg_match('/^\*\/\d+$/', $hour))
        {
            return 'каждые '.self::n(substr($hour, 2)).' часов ';
        }

        $hh = str_pad((string) self::n($hour), 2, '0', STR_PAD_LEFT);

        if ($on_the_hour)          return 'в '.$hh.':00 ';
        if ($every_minute)         return 'каждую минуту с '.$hh.':00 до '.$hh.':59 ';
        if ($step_min !== NULL)    return 'каждые '.$step_min.' минут с '.$hh.':00 до '.$hh.':59 ';

        return 'в '.$hh.':'.str_pad($minute, 2, '0', STR_PAD_LEFT).' ';
    }

    protected static function describe_days($day, $month, $weekday)
    {
        $day     = trim($day);
        $month   = trim($month);
        $weekday = trim($weekday);

        $m = '';

        if ($month === '*')  $m = ' каждый месяц';
        elseif (preg_match('/^\*\/\d+$/', $month)) $m = ' каждые '.self::n(substr($month, 2)).' месяцев';
        else $m = ' в месяцах '.$month;

        $wd = '';

        if ($weekday !== '*')
        {
            $wd = ' ('.self::describe_weekdays($weekday).')';
        }

        if ($day === '*')  return $m.$wd.'.';
        if (preg_match('/^\*\/\d+$/', $day)) return ' каждые '.self::n(substr($day, 2)).' дней'.$m.'.';

        return " по дням {$day}".$m.'.';
    }

    /** Числа дней недели (0,7=вс) → русские названия. */
    protected static function describe_weekdays($weekday)
    {
        static $names = [
            0 => 'воскресеньям', 1 => 'понедельникам', 2 => 'вторникам',
            3 => 'средам', 4 => 'четвергам', 5 => 'пятницам', 6 => 'субботам',
        ];

        $chunks = [];

        foreach (explode(',', $weekday) as $piece)
        {
            $piece = trim($piece);

            if (preg_match('/^(\d+)-(\d+)$/', $piece, $m))
            {
                $chunks[] = $names[(int) $m[1] % 7].'–'.$names[(int) $m[2] % 7];
                continue;
            }

            if (ctype_digit($piece))
            {
                $chunks[] = $names[(int) $piece % 7];
                continue;
            }

            $chunks[] = $piece; // списки/шаги оставляем как есть
        }

        return implode(', ', $chunks);
    }

    /** Следующие k запусков (жадный перебор вперёд, до MAX_REASONABLE_YEARS). */
    public static function next_runs($minute, $hour, $day, $month, $weekday, $limit = 5)
    {
        $limit = max(1, min(10, (int) $limit));

        $minute  = trim($minute);
        $hour    = trim($hour);
        $day     = trim($day);
        $month   = trim($month);
        $weekday = trim($weekday);

        $out = [];
        $t   = time();
        $dt  = new DateTime('@'.(string) $t, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Moscow'));
        $dt->setTime((int) $dt->format('G'), (int) $dt->format('i'), 0);

        while (count($out) < $limit)
        {
            $dt->modify('+1 minute');

            if ( ! self::match($minute, (int) $dt->format('i'), 0)) continue;
            if ( ! self::match($hour, (int) $dt->format('G'), 0)) continue;
            if ( ! self::match($day, (int) $dt->format('j'), 1)) continue;
            if ( ! self::match($month, (int) $dt->format('n'), 1)) continue;
            if ( ! self::match(str_replace('7', '0', $weekday), (int) $dt->format('w'), 0)) continue;

            $out[] = $dt->format('Y-m-d H:i');

            if (count($out) >= $limit) break;
        }

        return $out;
    }

    /** Соответствует ли значение полю cron (с поддержкой списков/диапазонов/шагов). */
    protected static function match($field, $value, $min = 0)
    {
        $field = trim((string) $field);

        foreach (explode(',', $field) as $piece)
        {
            $piece = trim($piece);

            if ($piece === '*' || $piece === '' || $piece === '?') return TRUE;

            if (preg_match('/^\*\/\d+$/', $piece))
            {
                $step = max(1, (int) substr($piece, 2));
                if ((($value - $min) % $step) === 0) return TRUE;
                continue;
            }

            if (preg_match('/^(\d+)-(\d+)\/(\d+)$/', $piece, $m))
            {
                $step = max(1, (int) $m[3]);
                if ($value >= (int) $m[1] && $value <= (int) $m[2] && (($value - (int) $m[1]) % $step) === 0) return TRUE;
                continue;
            }

            if (preg_match('/^(\d+)-(\d+)$/', $piece, $m))
            {
                if ($value >= (int) $m[1] && $value <= (int) $m[2]) return TRUE;
                continue;
            }

            if (ctype_digit($piece) && (int) $piece === $value) return TRUE;
        }

        return FALSE;
    }
}
