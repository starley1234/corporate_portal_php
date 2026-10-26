<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Генератор паролей (модуль NJ-509) — чистые функции, БД и воркеры не нужны.
 *
 * ГСЧ: random_int (CSPRNG, равномерный без модульного смещения) — тот же
 * алгоритм, что в JS-зеркале views/509/script.php, но на crypto.getRandomValues
 * с отбраковкой. Пароли НИГДЕ не сохраняются: ни в БД, ни в логах, ни в localStorage.
 *
 *   Service_Nj509_Password::generate(['length' => 20, 'symbols' => TRUE]);
 *   // → ['items' => ['kR8!...'], 'entropy' => 122.6, 'pool_size' => 82]
 */
class Service_Nj509_Password {

    const MIN_LENGTH = 6;
    const MAX_LENGTH = 96;
    const MAX_COUNT  = 20;

    /** @var array наборы символов */
    protected static $_sets = [
        'upper'   => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'lower'   => 'abcdefghijklmnopqrstuvwxyz',
        'digits'  => '0123456789',
        'symbols' => '!@#$%^&*()-_=+[]{};:,.?/~',
    ];

    /** Похожие символы (0/O, 1/l/I, |, кавычки) — исключаются опцией */
    protected static $_ambiguous = '0O1lI|`\'"';

    /**
     * Нормализованные опции или NULL (некорректные).
     */
    public static function normalize_options(array $opts)
    {
        $length = isset($opts['length']) ? (int) $opts['length'] : 16;

        if ($length < self::MIN_LENGTH OR $length > self::MAX_LENGTH)
        {
            return NULL;
        }

        $sets = [];
        foreach (array_keys(self::$_sets) as $key)
        {
            $enabled = array_key_exists($key, $opts)
                ? (bool) $opts[$key]
                : in_array($key, ['upper', 'lower', 'digits'], TRUE); // symbols по умолчанию выкл.

            if ($enabled)
            {
                $sets[$key] = self::$_sets[$key];
            }
        }

        if (isset($opts['exclude_ambiguous']) AND $opts['exclude_ambiguous'])
        {
            foreach ($sets as $key => $chars)
            {
                $sets[$key] = str_replace(str_split(self::$_ambiguous), '', $chars);
            }
        }

        if ( ! $sets)
        {
            return NULL; // должен остаться хотя бы один набор
        }

        $count = isset($opts['count']) ? (int) $opts['count'] : 1;

        return [
            'length' => $length,
            'sets'   => $sets,
            'count'  => max(1, min(self::MAX_COUNT, $count)),
        ];
    }

    /** Размер пула символов. */
    public static function pool_size(array $norm)
    {
        $pool = '';

        foreach ($norm['sets'] as $chars)
        {
            $pool .= $chars;
        }

        return strlen($pool);
    }

    /** Один случайный символ из строки (uniform CSPRNG). */
    protected static function _rand_char($chars)
    {
        return $chars[random_int(0, strlen($chars) - 1)];
    }

    /** Перемешивание Фишера—Йетса на random_int (uniform). */
    protected static function _shuffle(array $chars)
    {
        for ($i = count($chars) - 1; $i > 0; $i--)
        {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return $chars;
    }

    /**
     * Сгенерировать пароли. Возвращает ['items' => string[], 'entropy' => float,
     * 'pool_size' => int] или NULL при некорректных опциях.
     */
    public static function generate(array $opts = [])
    {
        $norm = self::normalize_options($opts);

        if ($norm === NULL)
        {
            return NULL;
        }

        $pool_size = self::pool_size($norm);
        $sets = array_values($norm['sets']);
        $items = [];

        for ($c = 0; $c < $norm['count']; $c++)
        {
            $chars = [];

            // гарантируем минимум по одному символу из каждого набора
            foreach ($sets as $chars_set)
            {
                $chars[] = self::_rand_char($chars_set);
            }

            while (count($chars) < $norm['length'])
            {
                $chars[] = self::_rand_char(implode('', $sets));
            }

            $items[] = implode('', self::_shuffle(array_slice($chars, 0, $norm['length'])));
        }

        return [
            'items'     => $items,
            'entropy'   => round($norm['length'] * log($pool_size, 2), 1),
            'pool_size' => $pool_size,
        ];
    }

    /**
     * Оценка стойкости для UI: [подпись, css-классы БЕЙДЖА].
     *
     * Полосы-метра нет (владелец: «полосы убрать везде») — стойкость показывает
     * цветной бейдж, тот же приём, что у статусов-плашек. Зеркало —
     * `strengthOf()` в `views/509/script.php`: менять обе части вместе.
     */
    public static function strength($entropy)
    {
        if ($entropy < 40) return ['слабый', 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300'];
        if ($entropy < 60) return ['средний', 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'];
        if ($entropy < 80) return ['крепкий', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'];
        return ['бронированный', 'bg-emerald-600 text-white'];
    }

    /** Список наборов для UI/API-описания. */
    public static function sets_info()
    {
        return [
            ['key' => 'upper', 'label' => 'A–Z', 'default' => TRUE],
            ['key' => 'lower', 'label' => 'a–z', 'default' => TRUE],
            ['key' => 'digits', 'label' => '0–9', 'default' => TRUE],
            ['key' => 'symbols', 'label' => '!@#$…', 'default' => FALSE],
        ];
    }
}
