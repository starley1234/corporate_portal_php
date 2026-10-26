<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-652 «Растение по фото» — чистая логика: без базы, без сети.
 *
 * Модель отвечает на два вопроса: что это за растение и что с ним не так.
 * Всё остальное — расписание ухода — считается здесь, из чисел ухода
 * (как часто поливать летом и зимой, как часто подкармливать) и текущей даты.
 * Поэтому календарь не зависит от настроения модели и проверяется тестом.
 *
 * Сервис ничего не лечит и не ставит диагнозов: он переводит ответ модели в
 * понятный план «что сделать на этой неделе» и честно показывает, насколько
 * модель уверена.
 */
class Service_Nj652_Plant
{
    /** Состояния растения: ключ => [подпись, тон]. */
    const STATES = [
        'ok'      => ['Выглядит здоровым', 'emerald'],
        'warning' => ['Есть тревожные признаки', 'amber'],
        'problem' => ['Видна проблема', 'rose'],
        'unknown' => ['Понять по фото не удалось', 'gray'],
    ];

    /** Насколько всё плохо: 0 — ничего, 3 — спасать срочно. */
    const SEVERITY = [
        0 => 'Вмешательство не требуется',
        1 => 'Можно поправить уходом',
        2 => 'Нужно вмешаться на этой неделе',
        3 => 'Спасать прямо сейчас',
    ];

    /** Месяцы «зимнего» режима полива (северное полушарие). */
    const WINTER_MONTHS = [11, 12, 1, 2];

    /** Короткие дни недели: date('D') отдаёт английские, а сервис русский. */
    const WEEKDAYS = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];

    /**
     * Сырой ответ модели → карточка растения.
     *
     * Числа режем по здравому смыслу: полив раз в 90 дней и подкормка раз в
     * год — это уже не уход, а опечатка модели.
     *
     * @param  array $raw ответ воркера
     * @return array plant|condition|care|actions|note|title
     */
    public static function shape(array $raw)
    {
        $plant     = (array) ($raw['plant'] ?? []);
        $condition = (array) ($raw['condition'] ?? []);
        $care      = (array) ($raw['care'] ?? []);

        $name_ru = trim((string) ($plant['name_ru'] ?? ''));

        if ($name_ru === '')
        {
            $name_ru = 'Растение не определено';
        }

        $state = (string) ($condition['state'] ?? 'unknown');

        if ( ! isset(self::STATES[$state]))
        {
            $state = 'unknown';
        }

        $out = [
            'plant' => [
                'name_ru'    => mb_substr($name_ru, 0, 120),
                'name_lat'   => mb_substr(trim((string) ($plant['name_lat'] ?? '')), 0, 120),
                'family'     => mb_substr(trim((string) ($plant['family'] ?? '')), 0, 80),
                'confidence' => (int) max(0, min(100, self::num($plant['confidence'] ?? 50))),
            ],
            'condition' => [
                'state'    => $state,
                'problem'  => mb_substr(trim((string) ($condition['problem'] ?? '')), 0, 300),
                'causes'   => self::strings($condition['causes'] ?? [], 6, 160),
                'severity' => (int) max(0, min(3, self::num($condition['severity'] ?? 0))),
            ],
            'care' => [
                'light'       => mb_substr(trim((string) ($care['light'] ?? '')), 0, 160),
                'water_summer' => self::days($care['water_summer_days'] ?? 0, 1, 30, 5),
                'water_winter' => self::days($care['water_winter_days'] ?? 0, 2, 60, 10),
                'feed'        => self::days($care['feed_days'] ?? 0, 7, 120, 21),
                'temp'        => mb_substr(trim((string) ($care['temp'] ?? '')), 0, 80),
                'humidity'    => mb_substr(trim((string) ($care['humidity'] ?? '')), 0, 80),
                'soil'        => mb_substr(trim((string) ($care['soil'] ?? '')), 0, 160),
                'repot'       => mb_substr(trim((string) ($care['repot'] ?? '')), 0, 160),
                'toxic_pets'  => ! empty($care['toxic_pets']),
            ],
            'actions' => self::strings($raw['actions'] ?? [], 6, 200),
            'note'    => mb_substr(trim((string) ($raw['note'] ?? '')), 0, 300),
        ];

        $out['title'] = $out['plant']['name_ru'];

        return $out;
    }

    /**
     * План ухода на ближайшие недели: когда поливать и когда подкармливать.
     *
     * Зимой растение пьёт заметно реже — это и есть самая частая причина
     * «залил и загубил», поэтому интервал полива берётся по месяцу КАЖДОЙ
     * даты, а не один раз на весь план: план, составленный в октябре, к
     * декабрю сам растягивается.
     *
     * @param  array  $care  раздел care из shape()
     * @param  string $from  дата отсчёта (Y-m-d), пусто — сегодня
     * @param  int    $weeks горизонт плана в неделях
     * @return array [['date' => 'Y-m-d', 'dow' => 'пн', 'what' => 'water|feed', 'label' => …], …]
     */
    public static function schedule(array $care, $from = '', $weeks = 4)
    {
        $start = $from !== '' ? strtotime($from.' 00:00:00') : strtotime(date('Y-m-d').' 00:00:00');

        if ($start === FALSE)
        {
            $start = strtotime(date('Y-m-d').' 00:00:00');
        }

        $until = $start + max(1, min(12, (int) $weeks)) * 7 * 86400;
        $plan  = [];

        // Полив: шаг зависит от сезона на момент очередной даты.
        $water_summer = (int) ($care['water_summer'] ?? 5);
        $water_winter = (int) ($care['water_winter'] ?? 10);
        $cursor       = $start;

        for ($i = 0; $i < 60; $i++)
        {
            $step   = self::is_winter($cursor) ? $water_winter : $water_summer;
            $cursor += max(1, $step) * 86400;

            if ($cursor > $until)
            {
                break;
            }

            $plan[] = [
                'date'  => date('Y-m-d', $cursor),
                'dow'   => self::WEEKDAYS[(int) date('N', $cursor) - 1],
                'what'  => 'water',
                'label' => 'Полив',
            ];
        }

        // Подкормка: зимой большинство комнатных растений не кормят вовсе.
        $feed   = max(7, (int) ($care['feed'] ?? 21));
        $cursor = $start;

        for ($i = 0; $i < 20; $i++)
        {
            $cursor += $feed * 86400;

            if ($cursor > $until)
            {
                break;
            }

            if (self::is_winter($cursor))
            {
                continue;
            }

            $plan[] = [
                'date'  => date('Y-m-d', $cursor),
                'dow'   => self::WEEKDAYS[(int) date('N', $cursor) - 1],
                'what'  => 'feed',
                'label' => 'Подкормка',
            ];
        }

        usort($plan, function ($a, $b) {
            return [$a['date'], $a['what']] <=> [$b['date'], $b['what']];
        });

        return $plan;
    }

    /** Зимний режим полива для этой даты? */
    public static function is_winter($timestamp)
    {
        return in_array((int) date('n', (int) $timestamp), self::WINTER_MONTHS, TRUE);
    }

    /** Подпись и тон состояния для интерфейса. */
    public static function state_label($state)
    {
        return self::STATES[(string) $state] ?? self::STATES['unknown'];
    }

    /** Подпись срочности. */
    public static function severity_label($severity)
    {
        return self::SEVERITY[max(0, min(3, (int) $severity))];
    }

    /**
     * Насколько можно доверять определению вида.
     *
     * Для человека важна не цифра, а ответ «идти ли проверять по справочнику».
     */
    public static function confidence_label($confidence)
    {
        $confidence = (int) $confidence;

        if ($confidence >= 80)
        {
            return 'модель уверена в виде';
        }

        if ($confidence >= 55)
        {
            return 'вид определён предположительно — сверьтесь со справочником';
        }

        return 'вид под вопросом: снимите лист и общий вид растения крупнее';
    }

    // ------------------------------------------------------------ помощники

    /** Интервал в днях с разумными границами и значением по умолчанию. */
    public static function days($value, $min, $max, $default)
    {
        $days = (int) round(self::num($value));

        if ($days < $min OR $days > $max)
        {
            return (int) $default;
        }

        return $days;
    }

    /**
     * Список строк из ответа модели: чистим, режем, отбрасываем пустое.
     *
     * Повторы выкидываем: модель любит назвать «Перелив» и первой причиной,
     * и третьей — в списке на экране это выглядит как две разные беды.
     */
    public static function strings($value, $limit, $max_len)
    {
        $out = [];

        foreach ((array) $value as $item)
        {
            if (is_array($item))
            {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', (string) $item));

            if ($text === '')
            {
                continue;
            }

            $text = mb_substr($text, 0, (int) $max_len);

            if ( ! in_array($text, $out, TRUE))
            {
                $out[] = $text;
            }

            if (count($out) >= (int) $limit)
            {
                break;
            }
        }

        return $out;
    }

    /** Число из ответа модели («раз в 7 дней» → 7). */
    public static function num($value)
    {
        if (is_int($value) OR is_float($value))
        {
            return (float) $value;
        }

        if (is_bool($value))
        {
            return $value ? 1.0 : 0.0;
        }

        $text = str_replace(',', '.', (string) $value);

        return preg_match('/-?\d+(?:\.\d+)?/', $text, $m) ? (float) $m[0] : 0.0;
    }
}
