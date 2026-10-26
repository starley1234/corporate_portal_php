<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-656 «Показания счётчиков».
 *
 * Это не разовый калькулятор: ценность создаёт собственная история показаний.
 * Сервис хранит её за ключом браузера, считает расход между двумя реальными
 * датами, стоимость по тарифу на момент показания и прогноз текущего темпа.
 * Модель/ИИ может объяснить формулу, но не подменяет привязанную к владельцу
 * базу измерений, контроль скачков и экспорт его фактической истории.
 */
class Service_Nj656_Meters
{
    const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    const CODE_LEN = 10;
    const MAX_TITLE = 60;
    const MAX_TARIFF = 1000000.0;
    const MAX_BUDGET = 100000000.0;
    const MAX_READING = 1000000000.0;
    const FORECAST_DAYS = 30.4375;

    /** Единицы осмысленно ограничены: так история не смешает кВт⋅ч и м³. */
    const UNITS = [
        'm3' => ['title' => 'м³', 'hint' => 'вода, газ, теплоноситель'],
        'kwh' => ['title' => 'кВт⋅ч', 'hint' => 'электроэнергия'],
        'gcal' => ['title' => 'Гкал', 'hint' => 'тепло'],
        'unit' => ['title' => 'ед.', 'hint' => 'другая измеряемая услуга'],
    ];

    protected static function _db()
    {
        static $db = NULL;

        return $db !== NULL ? $db : $db = Database::instance();
    }

    protected static function _q($value)
    {
        return self::_db()->quote($value);
    }

    protected static function _one($sql)
    {
        $row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    protected static function _all($sql)
    {
        return self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();
    }

    protected static function _exec($type, $sql)
    {
        return self::_db()->query($type, $sql, FALSE);
    }

    /**
     * Владелец данных — единый ключ платформы, а не IP и не имя человека.
     * Если браузер запретил техническую cookie, не записываем данные под общим
     * «anon»: иначе один посетитель увидел бы историю другого.
     */
    public static function owner_key()
    {
        $key = class_exists('Visitor') ? (string) Visitor::uid() : '';

        return ($key !== '' AND $key !== 'anon') ? mb_substr($key, 0, 64) : '';
    }

    public static function units()
    {
        return self::UNITS;
    }

    public static function unit_title($unit)
    {
        return self::UNITS[$unit]['title'] ?? self::UNITS['unit']['title'];
    }

    /** Человеческие причины отказа: контроллер не строит бизнес-текст сам. */
    public static function error_label($code)
    {
        $labels = [
            'owner' => 'Не удалось создать защищённый ключ браузера. Разрешите технические cookie и обновите страницу.',
            'title' => 'Название счётчика — от 1 до 60 символов.',
            'unit' => 'Выберите единицу измерения из списка.',
            'tariff' => 'Тариф должен быть числом больше нуля.',
            'budget' => 'Месячный бюджет должен быть неотрицательным числом.',
            'date' => 'Введите существующую дату показания.',
            'future' => 'Показание нельзя датировать будущим днём.',
            'value' => 'Показание должно быть неотрицательным числом.',
            'date_exists' => 'На эту дату показание уже есть. Чтобы не исказить историю, выберите другую дату.',
            'not_later' => 'Новое показание должно быть позже предыдущего по дате.',
            'lower' => 'Новое показание меньше предыдущего. Проверьте цифру или создайте новый счётчик после замены прибора.',
            'unit_locked' => 'Единицу нельзя изменить после первого показания: так история остаётся сопоставимой.',
            'not_found' => 'Счётчик не найден или открыт в другом браузере.',
        ];

        return $labels[$code] ?? 'Не удалось сохранить данные. Проверьте введённые значения.';
    }

    /** Обычная запись числа: пробелы и запятая допустимы, «примерно» — нет. */
    public static function number($raw)
    {
        if (is_int($raw) OR is_float($raw))
        {
            return is_finite((float) $raw) ? (float) $raw : NULL;
        }

        if ( ! is_string($raw))
        {
            return NULL;
        }

        $value = trim(str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' '], '', $raw));
        $value = str_replace(',', '.', $value);

        if ($value === '' OR ! preg_match('/^\d+(?:\.\d+)?$/', $value))
        {
            return NULL;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : NULL;
    }

    public static function date($raw)
    {
        if ( ! is_string($raw) OR ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $match))
        {
            return NULL;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? $raw : NULL;
    }

    /** Поля нового счётчика, без БД — удобно тестировать отдельно. */
    public static function normalize_device(array $input)
    {
        $title = trim(preg_replace('/\s+/u', ' ', (string) ($input['title'] ?? '')));
        $unit = (string) ($input['unit'] ?? '');
        $tariff = self::number($input['tariff'] ?? NULL);
        $budget_raw = $input['budget_rub'] ?? '';
        $budget = ($budget_raw === '' OR $budget_raw === NULL) ? 0.0 : self::number($budget_raw);

        if ($title === '' OR mb_strlen($title) > self::MAX_TITLE)
        {
            return ['ok' => FALSE, 'error' => 'title'];
        }
        if ( ! isset(self::UNITS[$unit]))
        {
            return ['ok' => FALSE, 'error' => 'unit'];
        }
        if ($tariff === NULL OR $tariff <= 0 OR $tariff > self::MAX_TARIFF)
        {
            return ['ok' => FALSE, 'error' => 'tariff'];
        }
        if ($budget === NULL OR $budget < 0 OR $budget > self::MAX_BUDGET)
        {
            return ['ok' => FALSE, 'error' => 'budget'];
        }

        return [
            'ok' => TRUE,
            'data' => [
                'title' => $title,
                'unit' => $unit,
                'tariff' => round($tariff, 4),
                'budget_rub' => round($budget, 2),
            ],
        ];
    }

    /** Поля показания, без БД. */
    public static function normalize_reading(array $input)
    {
        $date = self::date($input['reading_on'] ?? NULL);
        $value = self::number($input['value'] ?? NULL);

        if ($date === NULL)
        {
            return ['ok' => FALSE, 'error' => 'date'];
        }
        if ($date > date('Y-m-d'))
        {
            return ['ok' => FALSE, 'error' => 'future'];
        }
        if ($value === NULL OR $value < 0 OR $value > self::MAX_READING)
        {
            return ['ok' => FALSE, 'error' => 'value'];
        }

        return ['ok' => TRUE, 'data' => ['reading_on' => $date, 'value' => round($value, 3)]];
    }

    protected static function _code()
    {
        $code = '';
        $max = strlen(self::CODE_ALPHABET) - 1;

        for ($i = 0; $i < self::CODE_LEN; $i++)
        {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** Создать счётчик и вернуть его непредсказуемый публичный код. */
    public static function create($owner, array $input)
    {
        if ($owner === '')
        {
            return ['ok' => FALSE, 'error' => 'owner'];
        }

        $normalized = self::normalize_device($input);

        if ( ! $normalized['ok'])
        {
            return $normalized;
        }

        for ($try = 0; $try < 5; $try++)
        {
            $code = self::_code();
            $d = $normalized['data'];
            $row = self::_one('INSERT INTO nj_656_meters (code, owner_key, title, unit, tariff, budget_rub) VALUES ('
                .self::_q($code).', '.self::_q($owner).', '.self::_q($d['title']).', '.self::_q($d['unit']).', '
                .$d['tariff'].', '.$d['budget_rub'].') ON CONFLICT (code) DO NOTHING RETURNING id, code');

            if ($row !== NULL)
            {
                return ['ok' => TRUE, 'code' => (string) $row['code']];
            }
        }

        return ['ok' => FALSE, 'error' => 'not_found'];
    }

    /** Список только своих счётчиков с последним показанием — без чужих данных. */
    public static function mine($owner)
    {
        if ($owner === '') return [];

        return self::_all('SELECT m.id, m.code, m.title, m.unit, m.tariff, m.budget_rub, m.created_at,
                r.reading_on::text AS latest_on, r.value::float8 AS latest_value
             FROM nj_656_meters m
             LEFT JOIN LATERAL (
                 SELECT reading_on, value FROM nj_656_readings
                 WHERE meter_id = m.id ORDER BY reading_on DESC, id DESC LIMIT 1
             ) r ON TRUE
             WHERE m.owner_key = '.self::_q($owner).'
             ORDER BY m.updated_at DESC, m.id DESC');
    }

    /** Счётчик владельца по коду или NULL. */
    public static function mine_one($code, $owner)
    {
        if ($owner === '') return NULL;

        return self::_one('SELECT id, code, title, unit, tariff::float8 AS tariff, budget_rub::float8 AS budget_rub,
                created_at, updated_at FROM nj_656_meters WHERE code = '.self::_q((string) $code)
                .' AND owner_key = '.self::_q($owner));
    }

    /** Полная хронология: SQL идёт по дате вверх, чтобы формула не гадала порядок. */
    public static function readings($meter_id)
    {
        return self::_all('SELECT id, reading_on::text AS reading_on, value::float8 AS value, tariff::float8 AS tariff,
                created_at FROM nj_656_readings WHERE meter_id = '.(int) $meter_id.' ORDER BY reading_on, id');
    }

    /** Добавить следующую точку истории. Дубли и откат прибора не прячем. */
    public static function add_reading(array $meter, array $input)
    {
        $normalized = self::normalize_reading($input);

        if ( ! $normalized['ok']) return $normalized;

        $last = self::_one('SELECT reading_on::text AS reading_on, value::float8 AS value FROM nj_656_readings
            WHERE meter_id = '.(int) $meter['id'].' ORDER BY reading_on DESC, id DESC LIMIT 1');
        $d = $normalized['data'];

        if ($last !== NULL)
        {
            if ($d['reading_on'] === (string) $last['reading_on']) return ['ok' => FALSE, 'error' => 'date_exists'];
            if ($d['reading_on'] < (string) $last['reading_on']) return ['ok' => FALSE, 'error' => 'not_later'];
            if ($d['value'] < (float) $last['value']) return ['ok' => FALSE, 'error' => 'lower'];
        }

        // Уникальность даты держит и БД: второй запрос из соседней вкладки
        // не может создать две разные точки одной даты между SELECT и INSERT.
        $inserted = self::_one('INSERT INTO nj_656_readings (meter_id, reading_on, value, tariff) VALUES ('
            .(int) $meter['id'].', '.self::_q($d['reading_on']).', '.$d['value'].', '.round((float) $meter['tariff'], 4).') '
            .'ON CONFLICT (meter_id, reading_on) DO NOTHING RETURNING id');
        if ($inserted === NULL) return ['ok' => FALSE, 'error' => 'date_exists'];

        self::_exec(Database::UPDATE, 'UPDATE nj_656_meters SET updated_at = now() WHERE id = '.(int) $meter['id']);

        return ['ok' => TRUE];
    }

    /** Тариф и месячный ориентир можно менять; единица после истории зафиксирована. */
    public static function update_settings(array $meter, array $input)
    {
        $normalized = self::normalize_device([
            'title' => $input['title'] ?? '',
            'unit' => $meter['unit'],
            'tariff' => $input['tariff'] ?? '',
            'budget_rub' => $input['budget_rub'] ?? '',
        ]);

        if ( ! $normalized['ok']) return $normalized;

        $d = $normalized['data'];
        self::_exec(Database::UPDATE, 'UPDATE nj_656_meters SET title = '.self::_q($d['title'])
            .', tariff = '.$d['tariff'].', budget_rub = '.$d['budget_rub'].', updated_at = now() WHERE id = '.(int) $meter['id']);

        return ['ok' => TRUE];
    }

    public static function delete(array $meter, $owner)
    {
        self::_exec(Database::DELETE, 'DELETE FROM nj_656_meters WHERE id = '.(int) $meter['id'].' AND owner_key = '.self::_q($owner));
    }

    /**
     * Главное вычисление модуля. Расход нельзя узнать по одному показанию;
     * он существует только на отрезке «предыдущее → текущее». Поэтому каждая
     * цена привязана к тарифу именно текущего показания — смена тарифа не
     * переписывает прошлое.
     */
    public static function dashboard(array $meter, array $readings)
    {
        $rows = [];

        foreach ($readings as $reading)
        {
            $date = self::date((string) ($reading['reading_on'] ?? ''));
            $value = self::number($reading['value'] ?? NULL);
            $tariff = self::number($reading['tariff'] ?? NULL);
            if ($date === NULL OR $value === NULL OR $tariff === NULL) continue;
            $rows[] = ['reading_on' => $date, 'value' => $value, 'tariff' => $tariff];
        }

        usort($rows, function ($a, $b) { return $a['reading_on'] <=> $b['reading_on']; });
        $intervals = [];

        for ($i = 1; $i < count($rows); $i++)
        {
            $from = new DateTimeImmutable($rows[$i - 1]['reading_on']);
            $to = new DateTimeImmutable($rows[$i]['reading_on']);
            $days = (int) $from->diff($to)->format('%a');
            $usage = round($rows[$i]['value'] - $rows[$i - 1]['value'], 3);

            if ($days <= 0 OR $usage < 0) continue;

            $cost = round($usage * $rows[$i]['tariff'], 2);
            $daily = $usage / $days;
            $intervals[] = [
                'from' => $rows[$i - 1]['reading_on'], 'to' => $rows[$i]['reading_on'], 'days' => $days,
                'from_value' => $rows[$i - 1]['value'], 'to_value' => $rows[$i]['value'],
                'usage' => $usage, 'tariff' => $rows[$i]['tariff'], 'cost' => $cost,
                'daily' => $daily, 'daily_cost' => $cost / $days,
            ];
        }

        $last = $intervals ? $intervals[count($intervals) - 1] : NULL;
        $previous = count($intervals) > 1 ? $intervals[count($intervals) - 2] : NULL;
        $lookback = array_slice($intervals, max(0, count($intervals) - 4), 3);
        $baseline_usage = array_sum(array_column($lookback, 'usage'));
        $baseline_days = array_sum(array_column($lookback, 'days'));
        $baseline_daily = $baseline_days > 0 ? $baseline_usage / $baseline_days : NULL;
        $change_percent = ($last !== NULL AND $baseline_daily !== NULL AND $baseline_daily > 0)
            ? round(($last['daily'] / $baseline_daily - 1) * 100, 1) : NULL;
        $forecast_usage = $last !== NULL ? round($last['daily'] * self::FORECAST_DAYS, 3) : NULL;
        $forecast_cost = $last !== NULL ? round($forecast_usage * $last['tariff'], 2) : NULL;
        $budget = (float) ($meter['budget_rub'] ?? 0);

        return [
            'readings' => $rows,
            'intervals' => $intervals,
            'interval_by_to' => array_column($intervals, NULL, 'to'),
            'count' => count($rows),
            'first' => $rows ? $rows[0] : NULL,
            'latest' => $rows ? $rows[count($rows) - 1] : NULL,
            'last' => $last,
            'previous' => $previous,
            'baseline_daily' => $baseline_daily,
            'change_percent' => $change_percent,
            'total_usage' => round(array_sum(array_column($intervals, 'usage')), 3),
            'total_cost' => round(array_sum(array_column($intervals, 'cost')), 2),
            'forecast_usage' => $forecast_usage,
            'forecast_cost' => $forecast_cost,
            'budget_delta' => ($forecast_cost !== NULL AND $budget > 0) ? round($budget - $forecast_cost, 2) : NULL,
        ];
    }

    /** Короткий вывод о тренде; пороги прозрачны и опираются на 3 прошлых интервала. */
    public static function insight(array $dashboard)
    {
        if ($dashboard['last'] === NULL)
        {
            return ['level' => 'neutral', 'title' => 'Нужны два показания', 'text' => 'После следующего показания появятся расход за период, стоимость и прогноз.'];
        }

        $change = $dashboard['change_percent'];
        if ($change !== NULL AND $change >= 50)
        {
            return ['level' => 'warn', 'title' => 'Расход заметно вырос', 'text' => 'Темп выше среднего трёх прошлых интервалов на '.self::fmt($change, 1).'%. Проверьте показание и возможную утечку.'];
        }
        if ($change !== NULL AND $change <= -35)
        {
            return ['level' => 'good', 'title' => 'Расход ниже привычного', 'text' => 'Темп ниже среднего трёх прошлых интервалов на '.self::fmt(abs($change), 1).'%.'];
        }
        if ($dashboard['budget_delta'] !== NULL AND $dashboard['budget_delta'] < 0)
        {
            return ['level' => 'warn', 'title' => 'Прогноз выше бюджета', 'text' => 'При текущем темпе месячный ориентир будет превышен на '.self::rub(abs($dashboard['budget_delta'])).'.'];
        }

        return ['level' => 'neutral', 'title' => 'Темп без резких отклонений', 'text' => 'Прогноз строится по последнему интервалу; с каждой новой точкой он становится надёжнее.'];
    }

    public static function fmt($number, $digits = 3)
    {
        return number_format((float) $number, $digits, ',', ' ');
    }

    public static function rub($number)
    {
        return number_format((float) $number, 2, ',', ' ').' ₽';
    }

    /** CSV — экспорт только своих данных, удобный при переезде без аккаунта. */
    public static function csv(array $meter, array $readings)
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Счётчик', 'Единица', 'Дата', 'Показание', 'Тариф, ₽/ед.'], ';');
        foreach ($readings as $row)
        {
            fputcsv($handle, [
                $meter['title'], self::unit_title($meter['unit']), (string) $row['reading_on'],
                number_format((float) $row['value'], 3, '.', ''), number_format((float) $row['tariff'], 4, '.', ''),
            ], ';');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".$csv;
    }
}
