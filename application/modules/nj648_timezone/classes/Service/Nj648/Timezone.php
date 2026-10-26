<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Созвон по часовым поясам (модуль NJ-648) — чистые функции, БД не нужна.
 *
 * Отвечает на один вопрос: «когда всем удобно созвониться». Строит сетку суток
 * опорного города и для каждого часа показывает местное время остальных
 * участников, отмечая, у кого это рабочее время.
 *
 * Смещения берутся из базы часовых поясов PHP (IANA): переходы на летнее
 * время, отмена перевода стрелок и смена пояса регионом учитываются
 * автоматически — поэтому в конфиге лежат названия зон, а не «UTC+3».
 */
class Service_Nj648_Timezone {

    /** Сколько часов в сутках сетки */
    const HOURS = 24;

    /** @var array|NULL кэш конфига на запрос */
    protected static $_config = NULL;

    /**
     * Конфиг модуля (города, рабочий день, потолок участников).
     *
     * @return array
     */
    public static function config()
    {
        if (self::$_config === NULL)
        {
            self::$_config = (array) Kohana::$config->load('nj648_timezone')->as_array();
        }

        return self::$_config;
    }

    /**
     * Справочник городов: код => [city, tz, region].
     *
     * @return array
     */
    public static function cities()
    {
        return (array) Arr::get(self::config(), 'cities', []);
    }

    /**
     * Города, сгруппированные по регионам — для выпадающего списка.
     *
     * @return array регион => [код => название]
     */
    public static function grouped()
    {
        $out = [];

        foreach (self::cities() as $code => $c)
        {
            $out[$c['region']][$code] = $c['city'];
        }

        return $out;
    }

    /** Участников больше потолка в сетку не пускаем. */
    public static function max_people()
    {
        return (int) Arr::get(self::config(), 'max_people', 8);
    }

    /**
     * Разобрать код участника: либо код города из конфига, либо зона IANA
     * («Asia/Tokyo») — второе удобно для API и редких городов.
     *
     * @param  string $code
     * @return array|NULL ['code','city','tz','region']
     */
    public static function resolve($code)
    {
        $code = trim((string) $code);

        if ($code === '')
        {
            return NULL;
        }

        $cities = self::cities();

        if (isset($cities[$code]))
        {
            return ['code' => $code] + $cities[$code];
        }

        // Произвольная зона IANA — принимаем, если PHP её знает
        if (strpos($code, '/') !== FALSE AND in_array($code, timezone_identifiers_list(), TRUE))
        {
            return [
                'code'   => $code,
                'city'   => str_replace('_', ' ', substr($code, strrpos($code, '/') + 1)),
                'tz'     => $code,
                'region' => 'Другие',
            ];
        }

        return NULL;
    }

    /**
     * Нормализовать список участников: убрать пустые и неизвестные, развести
     * повторы, обрезать по потолку. Пустой список → города по умолчанию.
     *
     * @param  mixed $codes
     * @return array список resolve()-записей
     */
    public static function people($codes)
    {
        $codes = is_array($codes) ? $codes : [$codes];
        $out   = [];

        foreach ($codes as $code)
        {
            $one = self::resolve(is_scalar($code) ? (string) $code : '');

            if ($one !== NULL AND ! isset($out[$one['code']]))
            {
                $out[$one['code']] = $one;
            }

            if (count($out) >= self::max_people())
            {
                break;
            }
        }

        if ( ! $out)
        {
            foreach ((array) Arr::get(self::config(), 'default', []) as $code)
            {
                $one = self::resolve($code);

                if ($one !== NULL)
                {
                    $out[$one['code']] = $one;
                }
            }
        }

        return array_values($out);
    }

    /**
     * Текущее время участника.
     *
     * @param  array    $person resolve()
     * @param  int|NULL $ts     момент (по умолчанию сейчас)
     * @return array
     */
    public static function now(array $person, $ts = NULL)
    {
        $ts = ($ts === NULL) ? time() : (int) $ts;
        $dt = new DateTime('@'.$ts);
        $dt->setTimezone(new DateTimeZone($person['tz']));

        return [
            'code'    => $person['code'],
            'city'    => $person['city'],
            'tz'      => $person['tz'],
            'time'    => $dt->format('H:i'),
            'date'    => $dt->format('Y-m-d'),
            'weekday' => self::weekday($dt->format('N')),
            'offset'  => (int) $dt->getOffset(),
            'utc'     => self::offset_text((int) $dt->getOffset()),
            'dst'     => $dt->format('I') === '1',
        ];
    }

    /**
     * Смещение числом секунд → «UTC+3», «UTC−3:30».
     *
     * @param  int $seconds
     * @return string
     */
    public static function offset_text($seconds)
    {
        $seconds = (int) $seconds;
        $sign = $seconds < 0 ? "\xE2\x88\x92" : '+';   // настоящий минус, а не дефис
        $abs  = abs($seconds);
        $h    = intdiv($abs, 3600);
        $m    = intdiv($abs % 3600, 60);

        return 'UTC'.$sign.$h.($m ? sprintf(':%02d', $m) : '');
    }

    /**
     * Разница с опорным городом в часах (может быть дробной: Дели, Катманду).
     *
     * @param  int $offset
     * @param  int $base_offset
     * @return string «+2», «−4:30», «тот же час»
     */
    public static function diff_text($offset, $base_offset)
    {
        $d = (int) $offset - (int) $base_offset;

        if ($d === 0)
        {
            return 'тот же час';
        }

        $sign = $d < 0 ? "\xE2\x88\x92" : '+';
        $abs  = abs($d);
        $h    = intdiv($abs, 3600);
        $m    = intdiv($abs % 3600, 60);

        return $sign.$h.($m ? sprintf(':%02d', $m) : '').' ч';
    }

    /**
     * Сетка суток: 24 часа опорного города × местное время участников.
     *
     * @param  array  $people список resolve()
     * @param  string $date   Y-m-d (дата в опорном городе)
     * @param  int    $from   начало рабочего дня (час местного времени)
     * @param  int    $to     конец рабочего дня (час, не включается)
     * @return array ['date','base','people','hours','best','none']
     */
    public static function grid(array $people, $date, $from = NULL, $to = NULL)
    {
        $work = (array) Arr::get(self::config(), 'work', ['from' => 9, 'to' => 18]);
        $from = self::hour($from === NULL ? $work['from'] : $from, 9);
        $to   = self::hour($to === NULL ? $work['to'] : $to, 18);

        if ($to <= $from)
        {
            $to = min(24, $from + 1);   // рабочий день через полночь не считаем
        }

        $date = self::date($date);
        $base = $people[0];

        // Полночь опорного города: дальше идём по отметкам времени, поэтому
        // перевод стрелок у любого участника отражается сам собой.
        $start = new DateTime($date.' 00:00:00', new DateTimeZone($base['tz']));
        $start_ts = $start->getTimestamp();

        $hours = [];
        $best_mask = [];

        for ($h = 0; $h < self::HOURS; $h++)
        {
            $ts = $start_ts + $h * 3600;
            $cells = [];
            $all = TRUE;

            foreach ($people as $p)
            {
                $dt = new DateTime('@'.$ts);
                $dt->setTimezone(new DateTimeZone($p['tz']));

                $local_h = (int) $dt->format('G');
                $work_ok = ($local_h >= $from AND $local_h < $to);
                $all = ($all AND $work_ok);

                $cells[] = [
                    'code'  => $p['code'],
                    'city'  => $p['city'],
                    'time'  => $dt->format('H:i'),
                    'hour'  => $local_h,
                    'day'   => $dt->format('Y-m-d'),
                    'shift' => self::_day_shift($date, $dt->format('Y-m-d')),
                    'work'  => $work_ok,
                    'night' => ($local_h < 7 OR $local_h >= 22),
                ];
            }

            $base_dt = new DateTime('@'.$ts);
            $base_dt->setTimezone(new DateTimeZone($base['tz']));

            $hours[] = [
                'h'     => (int) $base_dt->format('G'),
                'label' => $base_dt->format('H:i'),
                'ts'    => $ts,
                'cells' => $cells,
                'all'   => $all,
            ];

            $best_mask[] = $all;
        }

        return [
            'date'   => $date,
            'from'   => $from,
            'to'     => $to,
            'base'   => $base,
            'people' => $people,
            'hours'  => $hours,
            'best'   => self::_windows($hours, $best_mask, $base['tz']),
            'none'   => ! in_array(TRUE, $best_mask, TRUE),
        ];
    }

    /**
     * Непрерывные окна, где рабочее время у всех.
     *
     * @return array [['from'=>'09:00','to'=>'12:00','hours'=>3], …]
     */
    protected static function _windows(array $hours, array $mask, $tz)
    {
        $zone = new DateTimeZone($tz);

        $out = [];
        $open = NULL;

        foreach ($mask as $i => $ok)
        {
            if ($ok AND $open === NULL)
            {
                $open = $i;
            }

            $closing = ( ! $ok OR $i === count($mask) - 1);

            if ($open !== NULL AND $closing)
            {
                $last = $ok ? $i : $i - 1;

                // Конец окна — начало следующего часа в опорном городе
                $end = new DateTime('@'.($hours[$last]['ts'] + 3600));
                $end->setTimezone($zone);

                $out[] = [
                    'from'  => $hours[$open]['label'],
                    'to'    => $end->format('H:i'),
                    'hours' => $last - $open + 1,
                    'index' => $open,
                ];

                $open = NULL;
            }
        }

        return $out;
    }

    /** На сколько суток местная дата отличается от даты опорного города. */
    protected static function _day_shift($base_date, $local_date)
    {
        $a = strtotime($base_date.' 12:00:00');
        $b = strtotime($local_date.' 12:00:00');

        if ($a === FALSE OR $b === FALSE)
        {
            return 0;
        }

        return (int) round(($b - $a) / 86400);
    }

    /** Час из ввода: целое 0..24, иначе значение по умолчанию. */
    public static function hour($value, $default)
    {
        if (is_string($value))
        {
            $value = trim($value);
        }

        if ($value === '' OR $value === NULL OR ! is_numeric($value))
        {
            return (int) $default;
        }

        $h = (int) $value;

        return ($h >= 0 AND $h <= 24) ? $h : (int) $default;
    }

    /** Дата из ввода: корректная Y-m-d или сегодня. */
    public static function date($value)
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
        {
            $t = strtotime($value.' 12:00:00');

            if ($t !== FALSE AND date('Y-m-d', $t) === $value)
            {
                return $value;
            }
        }

        return date('Y-m-d');
    }

    /** День недели по-русски (1 = понедельник). */
    public static function weekday($n)
    {
        $names = [1 => 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'];

        return isset($names[(int) $n]) ? $names[(int) $n] : '';
    }

    /** Выходной ли это день у участника (сб/вс). */
    public static function is_weekend($date)
    {
        $t = strtotime($date.' 12:00:00');

        return $t !== FALSE AND (int) date('N', $t) >= 6;
    }
}
