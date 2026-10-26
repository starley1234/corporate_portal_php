<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-650 «Чек по фото» — чистая логика: без базы, без сети, без модели.
 *
 * Модель умеет одно: ПРОЧИТАТЬ позиции с бумажки. Всё остальное — арифметика,
 * и доверять её модели нельзя: она «считает» текстом и охотно соглашается с
 * любым итогом. Поэтому суммы, сверка с итогом чека, категории и дележ счёта
 * считаются здесь и проверяются юнит-тестом.
 *
 * Конвейер:
 *   shape()      — сырой JSON модели → позиции с нормальными числами;
 *   check()      — сверка «сумма позиций vs итог на чеке» (главная защита от
 *                  пропущенной или удвоенной строки);
 *   categories() — траты по категориям (куда ушли деньги);
 *   split()      — дележ счёта: поровну или по тому, кто что брал, с чаевыми
 *                  и честной раздачей копеек.
 */
class Service_Nj650_Receipt
{
    /** Категории трат: ключ → подпись. Ключи приходят от модели. */
    const CATEGORIES = [
        'продукты'  => 'Продукты',
        'готовое'   => 'Готовая еда и кафе',
        'напитки'   => 'Напитки',
        'алкоголь'  => 'Алкоголь',
        'бытовое'   => 'Бытовая химия и дом',
        'аптека'    => 'Аптека и здоровье',
        'транспорт' => 'Транспорт и топливо',
        'одежда'    => 'Одежда и обувь',
        'техника'   => 'Техника и электроника',
        'услуги'    => 'Услуги',
        'прочее'    => 'Прочее',
    ];

    /** Больше этого расхождение «позиции vs итог» считаем настоящей ошибкой, ₽. */
    const TOLERANCE = 1.0;

    /** Сколько позиций максимум храним (чек из супермаркета бывает длинным). */
    const MAX_ITEMS = 120;

    /**
     * Сырой ответ модели → вид, который рисует страница.
     *
     * Правила разбора:
     *   * цена и сумма — числа, даже если модель прислала «89,90 ₽»;
     *   * нет суммы строки — считаем сами (кол-во × цена);
     *   * нет цены, но есть сумма и количество — восстанавливаем цену;
     *   * позиции без названия или с нулевой суммой отбрасываем: они только
     *     путают дележ.
     *
     * @param  array $raw ответ воркера (уже разобранный JSON)
     * @return array merchant|date|currency|items|items_total|total|check|categories|note
     */
    public static function shape(array $raw)
    {
        $items = [];

        foreach ((array) ($raw['items'] ?? []) as $row)
        {
            if ( ! is_array($row))
            {
                continue;
            }

            $name = trim(preg_replace('/\s+/u', ' ', (string) ($row['name'] ?? '')));

            if ($name === '')
            {
                continue;
            }

            $qty   = self::number($row['qty'] ?? 0);
            $price = self::number($row['price'] ?? 0);
            $sum   = self::number($row['sum'] ?? 0);

            if ($qty <= 0)
            {
                $qty = 1.0;
            }

            if ($sum <= 0 AND $price > 0)
            {
                $sum = round($qty * $price, 2);
            }

            if ($price <= 0 AND $sum > 0)
            {
                $price = round($sum / $qty, 2);
            }

            if ($sum <= 0)
            {
                continue;
            }

            $items[] = [
                'name'     => mb_substr($name, 0, 120),
                'qty'      => round($qty, 3),
                'price'    => round($price, 2),
                'sum'      => round($sum, 2),
                'category' => self::category($row['category'] ?? ''),
            ];

            if (count($items) >= self::MAX_ITEMS)
            {
                break;
            }
        }

        $items_total = round(array_sum(array_column($items, 'sum')), 2);
        $total       = round(self::number($raw['total'] ?? 0), 2);

        return [
            'merchant'    => mb_substr(trim((string) ($raw['merchant'] ?? '')), 0, 120),
            'date'        => self::date((string) ($raw['date'] ?? '')),
            'currency'    => self::currency((string) ($raw['currency'] ?? '')),
            'items'       => $items,
            'items_total' => $items_total,
            'total'       => $total > 0 ? $total : $items_total,
            'check'       => self::check($items_total, $total),
            'categories'  => self::categories($items),
            'note'        => mb_substr(trim((string) ($raw['note'] ?? '')), 0, 300),
        ];
    }

    /**
     * Сверка «сумма позиций» и «итог на чеке».
     *
     * Это главная проверка сервиса: если модель пропустила строку или
     * прочитала «1 199» как «1 19», расхождение видно сразу — и человек
     * понимает, что строки надо перечитать, а не доверять дележу.
     *
     * @return array ok|delta|message
     */
    public static function check($items_total, $total)
    {
        $items_total = round((float) $items_total, 2);
        $total       = round((float) $total, 2);

        if ($total <= 0)
        {
            return [
                'ok'      => TRUE,
                'delta'   => 0.0,
                'message' => 'Итог на чеке не прочитан — считаем по сумме позиций.',
            ];
        }

        $delta = round($items_total - $total, 2);

        if (abs($delta) <= self::TOLERANCE)
        {
            return [
                'ok'      => TRUE,
                'delta'   => $delta,
                'message' => 'Сумма позиций сходится с итогом чека.',
            ];
        }

        return [
            'ok'      => FALSE,
            'delta'   => $delta,
            'message' => $delta > 0
                ? 'Позиции дают на '.self::money(abs($delta)).' больше итога чека: возможно, строка прочиталась дважды или в чеке была скидка.'
                : 'Позиции дают на '.self::money(abs($delta)).' меньше итога чека: какая-то строка не прочиталась — проверьте фото.',
        ];
    }

    /** Траты по категориям: [['key','title','sum','share'], …] по убыванию. */
    public static function categories(array $items)
    {
        $by = [];

        foreach ($items as $item)
        {
            $key       = (string) ($item['category'] ?? 'прочее');
            $by[$key]  = round(($by[$key] ?? 0) + (float) $item['sum'], 2);
        }

        $total = array_sum($by);
        $out   = [];

        foreach ($by as $key => $sum)
        {
            $out[] = [
                'key'   => $key,
                'title' => self::CATEGORIES[$key] ?? 'Прочее',
                'sum'   => $sum,
                'share' => $total > 0 ? round($sum * 100 / $total, 1) : 0.0,
            ];
        }

        usort($out, function ($a, $b) {
            return $b['sum'] <=> $a['sum'];
        });

        return $out;
    }

    /**
     * Дележ счёта.
     *
     * Два способа:
     *   * поровну — `$assign` пуст: все позиции делятся на всех;
     *   * по-честному — `$assign[индекс позиции] = [номера участников]`:
     *     позиция делится только между теми, кто её брал (одна бутылка вина
     *     на двоих из четверых — это и есть обычный спор за столом).
     *
     * Копейки не теряются: остаток от округления раздаётся первым участникам
     * по одной копейке, поэтому сумма долей ВСЕГДА равна итогу (эта мелочь и
     * отличает калькулятор от «примерно поделили»).
     *
     * @param  array $items   позиции из shape()
     * @param  int   $people  сколько человек (1..20)
     * @param  array $assign  индекс позиции => список номеров участников (с 1)
     * @param  float $tip     чаевые, % к итогу
     * @return array people|tip_sum|total|shares|per_person
     */
    public static function split(array $items, $people, array $assign = [], $tip = 0.0)
    {
        $people = max(1, min(20, (int) $people));
        $tip    = max(0.0, min(50.0, (float) $tip));

        // Доли считаем в копейках: так округление честное и сумма сходится.
        $cents = array_fill(1, $people, 0);
        $total = 0;

        foreach ($items as $i => $item)
        {
            $sum = (int) round(((float) $item['sum']) * 100);
            $total += $sum;

            $who = [];

            foreach ((array) ($assign[$i] ?? []) as $person)
            {
                $person = (int) $person;

                if ($person >= 1 AND $person <= $people)
                {
                    $who[$person] = TRUE;
                }
            }

            $who = $who ? array_keys($who) : range(1, $people);

            $base = intdiv($sum, count($who));
            $rest = $sum - $base * count($who);

            foreach ($who as $n => $person)
            {
                $cents[$person] += $base + ($n < $rest ? 1 : 0);
            }
        }

        $tip_cents = (int) round($total * $tip / 100);

        if ($tip_cents > 0)
        {
            // Чаевые — общая радость: делим поровну, остаток первым участникам.
            $base = intdiv($tip_cents, $people);
            $rest = $tip_cents - $base * $people;

            for ($person = 1; $person <= $people; $person++)
            {
                $cents[$person] += $base + ($person <= $rest ? 1 : 0);
            }
        }

        $shares = [];

        for ($person = 1; $person <= $people; $person++)
        {
            $shares[$person] = round($cents[$person] / 100, 2);
        }

        return [
            'people'     => $people,
            'tip'        => $tip,
            'tip_sum'    => round($tip_cents / 100, 2),
            'total'      => round(($total + $tip_cents) / 100, 2),
            'shares'     => $shares,
            'per_person' => round(array_sum($shares) / $people, 2),
        ];
    }

    // ------------------------------------------------------------ помощники

    /**
     * Число из чего угодно: «1 199,50 ₽», «89.90», 12 — всё это деньги.
     *
     * Модель отвечает JSON-ом, но в поле цены регулярно кладёт строку с
     * пробелом-разделителем разрядов и запятой — обычный (float) дал бы 1.
     */
    public static function number($value)
    {
        if (is_int($value) OR is_float($value))
        {
            return (float) $value;
        }

        $text = (string) $value;
        $text = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' '], '', $text);
        $text = str_replace(',', '.', $text);
        $text = preg_replace('/[^0-9.\-]/', '', $text);

        // «1.199.50» — модель оставила разделитель разрядов точкой.
        if (substr_count($text, '.') > 1)
        {
            $parts = explode('.', $text);
            $last  = array_pop($parts);
            $text  = implode('', $parts).'.'.$last;
        }

        return $text === '' ? 0.0 : (float) $text;
    }

    /** Категория из ответа модели → известный ключ (иначе «прочее»). */
    public static function category($value)
    {
        $key = mb_strtolower(trim((string) $value));

        return isset(self::CATEGORIES[$key]) ? $key : 'прочее';
    }

    /** Дата чека: принимаем ДД.ММ.ГГГГ и ГГГГ-ММ-ДД, отдаём ГГГГ-ММ-ДД. */
    public static function date($value)
    {
        $value = trim((string) $value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
            AND checkdate((int) $m[2], (int) $m[3], (int) $m[1]))
        {
            return $value;
        }

        if (preg_match('/^(\d{2})[.\/](\d{2})[.\/](\d{4})$/', $value, $m)
            AND checkdate((int) $m[2], (int) $m[1], (int) $m[3]))
        {
            return $m[3].'-'.$m[2].'-'.$m[1];
        }

        return '';
    }

    /** Валюта: трёхбуквенный код или пусто. */
    public static function currency($value)
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{3}$/', $value) ? $value : 'RUB';
    }

    /** Деньги для интерфейса: «1 199,50 ₽». */
    public static function money($value, $currency = 'RUB')
    {
        $sign = ['RUB' => ' ₽', 'USD' => ' $', 'EUR' => ' €', 'KZT' => ' ₸'][$currency] ?? ' '.$currency;

        return number_format((float) $value, 2, ',', ' ').$sign;
    }

    /** Склонение «1 позиция / 2 позиции / 5 позиций». */
    public static function items_word($n)
    {
        $n   = abs((int) $n);
        $ten = $n % 100;
        $one = $n % 10;

        if ($ten >= 11 AND $ten <= 14)
        {
            return 'позиций';
        }

        if ($one === 1)
        {
            return 'позиция';
        }

        if ($one >= 2 AND $one <= 4)
        {
            return 'позиции';
        }

        return 'позиций';
    }
}
