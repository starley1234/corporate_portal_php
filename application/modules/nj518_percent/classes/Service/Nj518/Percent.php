<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Калькулятор процентов (модуль NJ-518) — чистые функции, БД и воркеры не нужны.
 *
 * Десять режимов, в каждом два числа (a, b). Метод calculate() — единая точка
 * истины для страницы, API и тестов; JS-зеркало живёт в views/518/script.php
 * (при изменении формул менять ОБА файла и гонять tests/Unit/PercentServiceTest).
 *
 *   of       a% от b              → a·b/100
 *   share    a — сколько % от b   → a/b·100
 *   change   от a до b, на сколько % изменилось → (b−a)/|a|·100
 *   add      b увеличить на a%    → b·(1+a/100)
 *   sub      b уменьшить на a%    → b·(1−a/100)
 *   whole    a — это b% от числа  → a/b·100
 *   markup   себестоимость a, наценка b%  → цена, маржа
 *   margin   себестоимость a, маржа b%    → цена, наценка
 *   price    себестоимость a, цена b      → наценка и маржа
 *   discount цена a, скидка b%            → цена со скидкой, экономия
 *
 * Наценка считается от себестоимости, маржа — от цены продажи: именно на их
 * путанице бизнес теряет деньги, поэтому режимы markup/margin/price рядом.
 */
class Service_Nj518_Percent {

    /** Знаков после запятой в выводе (лишние нули срезаются) */
    const SCALE = 4;

    /** Предел абсолютного значения входа (защита от абсурда) */
    const MAX_ABS = 1.0E+15;

    /**
     * Режимы для UI: code => title, шаблон фразы, подписи полей, пример.
     *
     * Фраза — предложение, в котором поля стоят на местах {a} и {b}.
     *
     * @return array
     */
    public static function modes()
    {
        return [
            'of' => [
                'title' => 'Процент от числа',
                'tpl'   => 'Сколько будет {a} % от {b}',
                'a'     => 'Процент', 'b' => 'Число',
                'ex'    => [15, 2000],
            ],
            'share' => [
                'title' => 'Сколько % составляет',
                'tpl'   => '{a} — это сколько процентов от {b}',
                'a'     => 'Часть', 'b' => 'Целое',
                'ex'    => [30, 120],
            ],
            'change' => [
                'title' => 'Прирост и убыль',
                'tpl'   => 'На сколько % изменилось число: было {a}, стало {b}',
                'a'     => 'Было', 'b' => 'Стало',
                'ex'    => [80, 100],
            ],
            'add' => [
                'title' => 'Увеличить на %',
                'tpl'   => 'Увеличить {b} на {a} %',
                'a'     => 'Процент', 'b' => 'Число',
                'ex'    => [20, 5000],
            ],
            'sub' => [
                'title' => 'Уменьшить на %',
                'tpl'   => 'Уменьшить {b} на {a} %',
                'a'     => 'Процент', 'b' => 'Число',
                'ex'    => [13, 100000],
            ],
            'whole' => [
                'title' => 'Найти число по части',
                'tpl'   => '{a} — это {b} % от какого числа',
                'a'     => 'Часть', 'b' => 'Процент',
                'ex'    => [45, 15],
            ],
            'markup' => [
                'title' => 'Наценка',
                'tpl'   => 'Себестоимость {a}, наценка {b} %',
                'a'     => 'Себестоимость', 'b' => 'Наценка, %',
                'ex'    => [700, 40],
            ],
            'margin' => [
                'title' => 'Маржа',
                'tpl'   => 'Себестоимость {a}, нужна маржа {b} %',
                'a'     => 'Себестоимость', 'b' => 'Маржа, %',
                'ex'    => [700, 30],
            ],
            'price' => [
                'title' => 'Наценка и маржа по цене',
                'tpl'   => 'Себестоимость {a}, цена продажи {b}',
                'a'     => 'Себестоимость', 'b' => 'Цена продажи',
                'ex'    => [700, 1000],
            ],
            'discount' => [
                'title' => 'Скидка',
                'tpl'   => 'Цена {a}, скидка {b} %',
                'a'     => 'Цена', 'b' => 'Скидка, %',
                'ex'    => [4990, 25],
            ],
        ];
    }

    /**
     * Разобрать число: «1 234,5» | '12.5' | 7 → float; мусор → NULL.
     *
     * Допустим ведущий минус (прирост/убыль бывают от отрицательных значений).
     *
     * @param  mixed $value
     * @return float|null
     */
    public static function parse_number($value)
    {
        if (is_int($value) OR is_float($value))
        {
            $f = (float) $value;
            return (is_finite($f) AND abs($f) <= self::MAX_ABS) ? $f : NULL;
        }

        if ( ! is_string($value))
        {
            return NULL;
        }

        $s = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', trim($value));
        $s = str_replace([',', '−', '–'], ['.', '-', '-'], $s);

        if ( ! preg_match('/^-?(\d{1,15}(\.\d{0,12})?|\.\d{1,12})$/', $s))
        {
            return NULL;
        }

        $f = (float) $s;

        return abs($f) <= self::MAX_ABS ? $f : NULL;
    }

    /**
     * Округление «от нуля» (как в JS-зеркале), до SCALE знаков.
     *
     * @param  float $x
     * @return float
     */
    public static function round($x)
    {
        $k = pow(10, self::SCALE);
        $r = floor(abs($x) * $k + 0.5 + 1.0E-9) / $k;

        return $x < 0 ? -$r : $r;
    }

    /**
     * Число → «1 234,56» (до SCALE знаков, нули срезаны); NULL → «—».
     *
     * @param  float|null $x
     * @return string
     */
    public static function fmt($x)
    {
        if ($x === NULL)
        {
            return '—';
        }

        $r = self::round($x);
        $s = number_format(abs($r), self::SCALE, ',', "\xC2\xA0");

        if (strpos($s, ',') !== FALSE)
        {
            $s = rtrim(rtrim($s, '0'), ',');
        }

        return ($r < 0 ? '−' : '').$s;
    }

    /**
     * Расчёт. Единая точка истины.
     *
     * @param  string $mode  ключ modes()
     * @param  mixed  $a
     * @param  mixed  $b
     * @return array|NULL  NULL — режим неизвестен/числа не разобраны/деление на ноль
     *   ['mode','value','main','unit','headline','formula','rows'=>[[label,value]]]
     */
    public static function calculate($mode, $a, $b)
    {
        $modes = self::modes();

        if ( ! is_string($mode) OR ! isset($modes[$mode]))
        {
            return NULL;
        }

        $a = self::parse_number($a);
        $b = self::parse_number($b);

        if ($a === NULL OR $b === NULL)
        {
            return NULL;
        }

        $f = function ($x) { return Service_Nj518_Percent::fmt($x); };
        $rows = [];

        switch ($mode)
        {
            case 'of':
                $v = $a * $b / 100;
                $res = [$v, '', 'Это '.$f($v), $f($a).' % × '.$f($b).' ÷ 100'];
                $rows[] = ['Число без этого процента', $f($b - $v)];
                $rows[] = ['Число плюс этот процент', $f($b + $v)];
                break;

            case 'share':
                if ($b == 0.0) return NULL;
                $v = $a / $b * 100;
                $res = [$v, '%', $f($a).' — это '.$f($v).' % от '.$f($b), $f($a).' ÷ '.$f($b).' × 100'];
                $rows[] = ['Остаток до целого', $f($b - $a).' ('.$f(100 - $v).' %)'];
                break;

            case 'change':
                if ($a == 0.0) return NULL;
                $v = ($b - $a) / abs($a) * 100;
                $word = $v > 0 ? 'Прирост' : ($v < 0 ? 'Убыль' : 'Без изменений');
                $res = [$v, '%', $word.' '.$f(abs($v)).' %', '('.$f($b).' − '.$f($a).') ÷ '.$f(abs($a)).' × 100'];
                $rows[] = ['Абсолютное изменение', $f($b - $a)];
                if ($b != 0.0)
                {
                    $rows[] = ['Чтобы вернуться обратно', $f(($a - $b) / abs($b) * 100).' %'];
                }
                break;

            case 'add':
                $v = $b * (1 + $a / 100);
                $res = [$v, '', 'Получится '.$f($v), $f($b).' × (1 + '.$f($a).' ÷ 100)'];
                $rows[] = ['Прибавка', $f($v - $b)];
                break;

            case 'sub':
                $v = $b * (1 - $a / 100);
                $res = [$v, '', 'Получится '.$f($v), $f($b).' × (1 − '.$f($a).' ÷ 100)'];
                $rows[] = ['Уменьшение', $f($b - $v)];
                break;

            case 'whole':
                if ($b == 0.0) return NULL;
                $v = $a / $b * 100;
                $res = [$v, '', 'Число — '.$f($v), $f($a).' ÷ '.$f($b).' × 100'];
                $rows[] = ['Остальные '.$f(100 - $b).' %', $f($v - $a)];
                break;

            case 'markup':
                $price = $a * (1 + $b / 100);
                $profit = $price - $a;
                $res = [$price, '', 'Цена продажи '.$f($price), $f($a).' × (1 + '.$f($b).' ÷ 100)'];
                $rows[] = ['Прибыль с единицы', $f($profit)];
                $rows[] = ['Маржа (доля прибыли в цене)', $price != 0.0 ? $f($profit / $price * 100).' %' : '—'];
                break;

            case 'margin':
                if ($b >= 100) return NULL;
                $price = $a / (1 - $b / 100);
                $profit = $price - $a;
                $res = [$price, '', 'Цена продажи '.$f($price), $f($a).' ÷ (1 − '.$f($b).' ÷ 100)'];
                $rows[] = ['Прибыль с единицы', $f($profit)];
                $rows[] = ['Наценка на себестоимость', $a != 0.0 ? $f($profit / $a * 100).' %' : '—'];
                break;

            case 'price':
                if ($a == 0.0 OR $b == 0.0) return NULL;
                $markup = ($b - $a) / $a * 100;
                $margin = ($b - $a) / $b * 100;
                $res = [$markup, '%', 'Наценка '.$f($markup).' %, маржа '.$f($margin).' %', '('.$f($b).' − '.$f($a).') ÷ '.$f($a).' × 100'];
                $rows[] = ['Маржа (от цены продажи)', $f($margin).' %'];
                $rows[] = ['Прибыль с единицы', $f($b - $a)];
                break;

            case 'discount':
                $v = $a * (1 - $b / 100);
                $res = [$v, '', 'Цена со скидкой '.$f($v), $f($a).' × (1 − '.$f($b).' ÷ 100)'];
                $rows[] = ['Экономия', $f($a - $v)];
                break;

            default:
                return NULL;
        }

        return [
            'mode'     => $mode,
            'value'    => self::round($res[0]),
            'main'     => $f($res[0]),
            'unit'     => $res[1],
            'headline' => $res[2],
            'formula'  => $res[3],
            'rows'     => $rows,
        ];
    }
}
