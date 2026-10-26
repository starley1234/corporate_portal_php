<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-653: карта сходства — раскладка векторов коллекции на плоскость.
 *
 * Зачем. Косинус между двумя элементами человек прочитать может, а «как
 * устроена вся коллекция» по столбику чисел — нет. Карта отвечает на это
 * одним взглядом: что сбилось в кучку (похожее), что улетело в угол (чужое),
 * где картинка легла рядом со своей подписью, а где нет.
 *
 * Как считаем. Классический MDS (multidimensional scaling) по матрице
 * косинусных близостей:
 *
 *   1. S[i][j] = косинус между векторами i и j — это уже матрица Грама
 *      нормированных векторов, центрировать её по Торгерсону не нужно:
 *      хватает двойного центрирования, чтобы убрать общий сдвиг;
 *   2. два главных собственных вектора ищем степенным методом с
 *      ортогонализацией — это десяток строк и никаких зависимостей;
 *   3. координаты масштабируем в квадрат 0…1, чтобы вьюхе осталось только
 *      умножить на размер холста.
 *
 * Почему не на клиенте: в браузере пришлось бы гонять по сети сами векторы
 * (сотня точек × 2048 чисел — мегабайты), а на сервере остаются только
 * координаты. Сложность O(n²·dims) — для витрины на сотню элементов это
 * миллисекунды, поэтому список и ограничен сотней.
 */
class Service_Nj653_Map
{
    /** Сколько шагов делает степенной метод: больше — точнее, дороже. */
    const STEPS = 160;

    /**
     * Координаты точек и связи между ними.
     *
     * @param  array $items строки Store::items_with_vectors (поле vector)
     * @param  float $link  порог косинуса, выше которого рисуем связь
     * @return array ['points' => […], 'links' => […], 'pairs' => […], 'note' => '']
     */
    public static function build(array $items, $link = 0.6)
    {
        $items = array_values(array_filter($items, function ($row) {
            return is_array($row) AND ! empty($row['vector']);
        }));

        $n = count($items);

        if ($n < 2)
        {
            return ['points' => [], 'links' => [], 'pairs' => [], 'note' =>
                'Для карты нужно хотя бы два элемента с векторами.'];
        }

        $vectors = [];

        foreach ($items as $row)
        {
            $vectors[] = self::normalize((array) $row['vector']);
        }

        $sim = self::similarity_matrix($vectors);
        $xy  = self::project($sim);

        $points = [];

        foreach ($items as $i => $row)
        {
            $neighbour = self::closest($sim, $i);
            $near      = $items[$neighbour['index']] ?? [];
            $neighbour['id']    = (int) ($near['id'] ?? 0);
            $neighbour['label'] = trim((string) ($near['label'] ?? ''));

            $points[] = [
                'id'      => (int) ($row['id'] ?? 0),
                'label'   => mb_substr(trim((string) ($row['label'] ?? '')), 0, 120),
                'kind'    => (string) ($row['kind'] ?? 'text'),
                'preview' => Service_Nj653_Veclab::safe_preview((string) ($row['preview'] ?? '')),
                'x'       => round($xy[$i][0], 4),
                'y'       => round($xy[$i][1], 4),
                'near_id' => $neighbour['id'],
                'near'    => $neighbour['label'] === '' ? '' : mb_substr($neighbour['label'], 0, 60),
                'near_cos' => round($neighbour['cos'], 4),
            ];
        }

        // Связи: только заметно похожие пары, иначе карта зарастает паутиной.
        $links = [];
        $pairs = [];
        $link  = max(-1.0, min(1.0, (float) $link));

        for ($i = 0; $i < $n; $i++)
        {
            for ($j = $i + 1; $j < $n; $j++)
            {
                if ($sim[$i][$j] < $link)
                {
                    continue;
                }

                $links[] = ['a' => $i, 'b' => $j, 'cos' => round($sim[$i][$j], 4)];
            }
        }

        usort($links, function ($x, $y) { return $y['cos'] <=> $x['cos']; });
        $links = array_slice($links, 0, 240);

        foreach (array_slice($links, 0, 12) as $edge)
        {
            $pairs[] = [
                'a'   => $points[$edge['a']]['label'] ?: '#'.$points[$edge['a']]['id'],
                'b'   => $points[$edge['b']]['label'] ?: '#'.$points[$edge['b']]['id'],
                'cos' => $edge['cos'],
                'say' => Service_Nj653_Veclab::similarity($edge['cos']),
            ];
        }

        return ['points' => $points, 'links' => $links, 'pairs' => $pairs, 'note' => ''];
    }

    /** Единичная длина: косинус тогда считается обычным скалярным произведением. */
    public static function normalize(array $vector)
    {
        $sum = 0.0;

        foreach ($vector as $x)
        {
            $sum += (float) $x * (float) $x;
        }

        if ($sum <= 0)
        {
            return array_map('floatval', $vector);
        }

        $norm = sqrt($sum);

        return array_map(function ($x) use ($norm) { return (float) $x / $norm; }, $vector);
    }

    /** Матрица попарных косинусов (симметричная, на диагонали единицы). */
    public static function similarity_matrix(array $vectors)
    {
        $n   = count($vectors);
        $sim = [];

        for ($i = 0; $i < $n; $i++)
        {
            $sim[$i][$i] = 1.0;

            for ($j = $i + 1; $j < $n; $j++)
            {
                $dot = 0.0;
                $len = min(count($vectors[$i]), count($vectors[$j]));

                for ($k = 0; $k < $len; $k++)
                {
                    $dot += $vectors[$i][$k] * $vectors[$j][$k];
                }

                $sim[$i][$j] = $sim[$j][$i] = max(-1.0, min(1.0, $dot));
            }
        }

        return $sim;
    }

    /**
     * Матрица близостей → координаты на плоскости (MDS).
     *
     * Двойное центрирование убирает «общий смысл» коллекции: у эмбеддингов
     * все векторы заметно похожи друг на друга (косинус 0,3–0,6 между совсем
     * разными текстами), и без центрирования все точки слиплись бы в одну.
     */
    public static function project(array $sim)
    {
        $n = count($sim);

        if ($n < 2)
        {
            return [[0.5, 0.5]];
        }

        $gram = self::double_center($sim);

        $first  = self::power_iteration($gram, []);
        $second = self::power_iteration($gram, [$first['vector']]);

        $x = [];
        $y = [];

        for ($i = 0; $i < $n; $i++)
        {
            $x[$i] = $first['vector'][$i] * sqrt(max(0.0, $first['value']));
            $y[$i] = $second['vector'][$i] * sqrt(max(0.0, $second['value']));
        }

        return self::to_unit_square($x, $y);
    }

    /** B = -(1/2) J S J — двойное центрирование матрицы близостей. */
    protected static function double_center(array $sim)
    {
        $n    = count($sim);
        $rows = [];
        $all  = 0.0;

        for ($i = 0; $i < $n; $i++)
        {
            $rows[$i] = array_sum($sim[$i]) / $n;
            $all     += $rows[$i];
        }

        $all /= $n;
        $out  = [];

        for ($i = 0; $i < $n; $i++)
        {
            for ($j = 0; $j < $n; $j++)
            {
                $out[$i][$j] = $sim[$i][$j] - $rows[$i] - $rows[$j] + $all;
            }
        }

        return $out;
    }

    /**
     * Главный собственный вектор степенным методом.
     *
     * $against — уже найденные направления: от них очищаемся на каждом шаге,
     * иначе второй вектор сойдётся к первому.
     */
    protected static function power_iteration(array $matrix, array $against)
    {
        $n = count($matrix);
        $v = [];

        // Старт не случайный: карта обязана получаться одинаковой при каждом
        // открытии страницы, иначе человек решит, что данные «пляшут».
        for ($i = 0; $i < $n; $i++)
        {
            $v[$i] = sin($i + 1.0) + 0.1;
        }

        $v = self::unit(self::orthogonalize($v, $against));
        $value = 0.0;

        for ($step = 0; $step < self::STEPS; $step++)
        {
            $next = array_fill(0, $n, 0.0);

            for ($i = 0; $i < $n; $i++)
            {
                $sum = 0.0;

                for ($j = 0; $j < $n; $j++)
                {
                    $sum += $matrix[$i][$j] * $v[$j];
                }

                $next[$i] = $sum;
            }

            $next = self::orthogonalize($next, $against);
            $len  = self::length($next);

            if ($len < 1e-12)
            {
                return ['vector' => array_fill(0, $n, 0.0), 'value' => 0.0];
            }

            $value = $len;
            $v     = self::unit($next);
        }

        return ['vector' => $v, 'value' => $value];
    }

    protected static function orthogonalize(array $v, array $against)
    {
        foreach ($against as $u)
        {
            $dot = 0.0;

            foreach ($v as $i => $x)
            {
                $dot += $x * $u[$i];
            }

            foreach ($v as $i => $x)
            {
                $v[$i] = $x - $dot * $u[$i];
            }
        }

        return $v;
    }

    protected static function length(array $v)
    {
        $sum = 0.0;

        foreach ($v as $x)
        {
            $sum += $x * $x;
        }

        return sqrt($sum);
    }

    protected static function unit(array $v)
    {
        $len = self::length($v);

        if ($len < 1e-12)
        {
            return $v;
        }

        return array_map(function ($x) use ($len) { return $x / $len; }, $v);
    }

    /** Координаты → квадрат 0…1 с полями, чтобы точки не липли к краю. */
    protected static function to_unit_square(array $x, array $y)
    {
        $scale = function (array $values) {
            $min = min($values);
            $max = max($values);
            $gap = $max - $min;

            if ($gap < 1e-9)
            {
                return array_map(function () { return 0.5; }, $values);
            }

            return array_map(function ($v) use ($min, $gap) {
                return 0.06 + 0.88 * (($v - $min) / $gap);
            }, $values);
        };

        $x = $scale($x);
        $y = $scale($y);
        $out = [];

        foreach ($x as $i => $value)
        {
            $out[$i] = [$value, $y[$i]];
        }

        return $out;
    }

    /** Ближайший сосед точки — чтобы подпись отвечала «а рядом с чем он». */
    protected static function closest(array $sim, $index)
    {
        $best = ['id' => 0, 'label' => '', 'cos' => -1.0, 'index' => -1];

        foreach ($sim[$index] as $j => $value)
        {
            if ($j === $index)
            {
                continue;
            }

            if ($value > $best['cos'])
            {
                $best = ['id' => 0, 'label' => '', 'cos' => (float) $value, 'index' => (int) $j];
            }
        }

        return $best;
    }
}
