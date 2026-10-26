<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Label — чистая логика «Разбора состава»: без базы, без сети,
 * проверяется юнит-тестом.
 *
 * Конвейер разбора:
 *   1) parse()  — строка состава → позиции (названия, скобочные синонимы,
 *      проценты и «не более N %» отброшены);
 *   2) match()  — сопоставление позиций с базой веществ: точное имя,
 *      синоним, E-код, перестановка слов;
 *   3) score()  — оценка состава 0..100 и вердикт;
 *   4) advice() — персональные предупреждения (профиль) и общие замечания;
 *   5) kind_guess() — еда или косметика, если посетитель не выбрал сам.
 *
 * «Неизвестные» позиции не исчезают: их возвращает match(), а Store
 * складывает их в очередь на изучение — так база знаний растёт сама.
 */
class Service_Nj646_Label
{
    /** Вес риска для итоговой оценки (0 — полезно, 4 — избегать). */
    const RISK_PENALTY = [0 => 0.0, 1 => 0.6, 2 => 1.6, 3 => 3.0, 4 => 5.0];

    /** Слова, которые слишком общие для сопоставления «на пересечении слов». */
    protected static $_stopwords = [
        'масло', 'вода', 'соль', 'acid', 'oil', 'water', 'salt', 'powder',
        'extract', 'экстракт', 'эфирное', 'натуральный', 'натуральное',
        'food', 'cosmetic', 'и', 'для', 'без',
    ];

    protected static $_cosmetic_markers = [
        'aqua', 'parfum', 'fragrance', 'glycerin', 'glycol', 'siloxane', 'silicon',
        'sulfate', 'sulphate', 'laureth', 'cetearyl', 'phenoxyethanol', 'tocopherol',
        'butylene', 'dimethicone', 'panthenol', 'niacinamide', 'retinol', 'hyaluronic',
        'inci', 'eau', 'butter', 'sodium', 'cocamidopropyl', 'betaine', 'keratin',
        'collagen', 'lanolin', 'pyrithione', 'octinoxate', 'oxybenzone',
    ];

    protected static $_food_markers = [
        'сахар', 'соль', 'мука', 'молоко', 'сливк', 'масло', 'дрожж', 'какао',
        'ароматизатор', 'краситель', 'консервант', 'стабилизатор', 'сироп',
        'сок', 'вода', 'крупа', 'рис', 'пшениц', 'овсян', 'ячмен', 'кукуруз',
        'крахмал', 'яйц', 'сыр', 'мясо', 'рыб', 'фрукт', 'овощ', 'орех',
        'кислота', 'сода', 'пектин', 'желатин', 'мёд',
    ];

    // ------------------------------------------------------------------ разбор

    /**
     * Строка состава → позиции.
     *
     * Понимает «Состав:», «Ingredients:», запятые/точки с запятой/переносы,
     * скобки вида «Sodium Laureth Sulfate (SLES)», проценты «не более 5 %».
     *
     * @return array [['raw' => строка как на этикетке, 'name' => имя для поиска,
     *                 'syn' => [скобочные синонимы], 'percent' => float|NULL], ...]
     */
    /**
     * Вырезать из текста этикетки ТОЛЬКО блок состава.
     *
     * Человек вставляет в форму не список ингредиентов, а всё, что видит на
     * упаковке: название, изготовителя, пищевую ценность, массу, ГОСТ. До
     * этого метода вся эта проза уезжала в `parse()` и становилась
     * «ингредиентами»: в неизвестных оседали «5 г», «ГОСТ 24901-2014»,
     * «Изготовитель: ООО …» — и каждая такая строка ещё и ставилась в
     * очередь изучения моделью (деньги за разбор мусора и мусор в базе).
     *
     * Правило простое и предсказуемое:
     *   1. если в тексте есть «Состав:» / «Ingredients:» / «INCI:» — состав
     *      начинается сразу после него (всё до — это название и продавец);
     *   2. состав кончается на первом служебном слове этикетки («пищевая
     *      ценность», «срок годности», «изготовитель», «ГОСТ», «масса нетто»…).
     *
     * Если после обрезки ничего не осталось (например, прислали одну строку
     * с пищевой ценностью) — возвращаем исходный текст: лучше показать
     * человеку разбор с мусором, чем пустой экран.
     *
     * @param  string $text текст с упаковки как есть
     * @return string часть текста, которую имеет смысл разбирать на вещества
     */
    /**
     * Похоже ли «вещество» на служебную строку этикетки, а не на ингредиент.
     *
     * До `composition_block()` разбор брал весь вставленный текст, и в очередь
     * изучения (а она ОПЛАЧИВАЕТСЯ моделью) уезжали «5 г», «соль 0»,
     * «ГОСТ 24901-2014», «Изготовитель: ООО „Хлебный дом“» — по десятку
     * мусорных строк с каждой этикетки. Правила собраны здесь, чтобы чистка
     * очереди и обрезка текста говорили об одном и том же, и чтобы их можно
     * было проверить тестами без базы.
     *
     * Осторожность важнее полноты: лучше оставить сомнительную строку в
     * очереди, чем удалить настоящее вещество. Поэтому никаких «коротких» и
     * «непонятных» — только явные признаки упаковочной прозы.
     *
     * @param  string $name строка из очереди незнакомых
     * @return bool TRUE — это проза с упаковки, её место в корзине
     */
    public static function looks_like_service($name)
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));

        if ($name === '')
        {
            return TRUE;
        }

        // Ни одной буквы: «5», «0,4», «2014-01» — это цифры с этикетки.
        if ( ! preg_match('/\p{L}/u', $name))
        {
            return TRUE;
        }

        // Число с единицей измерения и ничего больше: «5 г», «170 мл», «480 ккал».
        if (preg_match('/^\d+(?:[.,]\d+)?\s*(?:г|гр|кг|мг|мкг|мл|л|%|ккал|кдж|шт|g|kg|mg|ml|l|kcal|kj)\.?$/iu', $name))
        {
            return TRUE;
        }

        // Служебное слово этикетки — где угодно в строке: настоящее вещество
        // не называется «пищевая ценность на 100 г» и не начинается с «ГОСТ».
        $service = '/(пищева\p{L}*\s+ценност|энергетическ\p{L}*\s+ценност|калорийност'
            .'|масса\s+нетто|срок\s+годност|годен\s+до|услови\p{L}*\s+хранени|хранить\s+при'
            .'|изготовител|производител|импорт[её]р|упаковщик|дата\s+изготовлен|штрих'
            .'|(?<!\p{L})гост(?!\p{L})|(?<!\p{L})ту\s*\d|тел\.|www\.|https?:|@'
            .'|net\s+weight|best\s+before|manufactured|nutrition\s+(?:facts|information|per))/iu';

        if (preg_match($service, $name))
        {
            return TRUE;
        }

        // Хвост таблицы: «белки 7», «соль 0», «углеводы 58 г» — название поля
        // с числом. Настоящий ингредиент числом не заканчивается.
        if (preg_match('/^(белк|жир|углевод|сахар|сол[ьи]|натри|волокн|клетчатк|насыщ|protein|fat|carb|sugar|salt|sodium|fibre|fiber)'
            .'\p{L}*(\s+\p{L}+){0,3}\s*[:\-]?\s*\d/iu', $name))
        {
            return TRUE;
        }

        // Целое предложение: длинно И с точкой внутри. Самые длинные настоящие
        // названия («экстракт листьев гинкго билоба») точек не содержат.
        if (mb_strlen($name) > 80 AND preg_match('/\.\s+\p{L}/u', $name))
        {
            return TRUE;
        }

        return FALSE;
    }

    public static function composition_block($text)
    {
        $text  = (string) $text;
        $block = str_replace(["\r\n", "\r"], "\n", $text);

        // 1) Начало: слово-метка состава (может стоять не в первой строке).
        if (preg_match('/(состав(?:\s+продукта)?|ингредиенты|ingredients|inci|composition)\s*[:\-\x{2013}\x{2014}]\s*/iu',
                $block, $m, PREG_OFFSET_CAPTURE))
        {
            $block = substr($block, (int) $m[0][1] + strlen((string) $m[0][0]));
        }

        // 2) Конец: служебный блок упаковки. «ГОСТ»/«ТУ» — только с номером,
        //    иначе «ту» поймалось бы в середине обычного слова.
        $stop = '/(пищева\p{L}*\s+и?\s*энергетическ\p{L}*\s+ценност|пищева\p{L}*\s+ценност'
            .'|энергетическ\p{L}*\s+ценност|калорийност|масса\s+нетто|объ[еёЁ]м\s*:?\s*\d'
            .'|срок\s+годност|годен\s+до|услови\p{L}*\s+хранени|хранить\s+при|изготовител'
            .'|производител|импорт[её]р|упаковщик|дата\s+изготовлен|штрих'
            .'|(?<!\p{L})гост(?!\p{L})|(?<!\p{L})ту\s*\d|тел\.|www\.'
            .'|nutrition\s+(?:facts|information|per)|net\s+weight|best\s+before|manufactured)/iu';

        if (preg_match($stop, $block, $m, PREG_OFFSET_CAPTURE))
        {
            $block = substr($block, 0, (int) $m[0][1]);
        }

        $block = trim($block, " \t\n.,;:-");

        return $block !== '' ? $block : $text;
    }

    public static function parse($text)
    {
        $text = (string) $text;

        // «Состав:», «Ingredients:», «INCI:» — служебные префиксы.
        $text = preg_replace('/^\s*(состав|ингредиенты|ingredients|inci|composition)\s*[:\-]\s*/iu', '', $text);
        $text = str_replace(["\r\n", "\r", "\n", '·', '•', '|'], ',', $text);
        $text = str_replace(';', ',', $text);

        $items = [];

        foreach (explode(',', $text) as $chunk)
        {
            $chunk = trim(preg_replace('/\s+/u', ' ', $chunk));

            if ($chunk === '')
            {
                continue;
            }

            // «и др.», «прочее» — не ингредиент.
            if (preg_match('/^(и\s+др\.?|прочее|другое)$/iu', $chunk))
            {
                continue;
            }

            $percent = NULL;

            if (preg_match('/(?:не\s+более|до|≈|~)?\s*(\d+(?:[.,]\d+)?)\s*%/u', $chunk, $m))
            {
                $percent = (float) str_replace(',', '.', $m[1]);
                $chunk   = trim(preg_replace('/(?:не\s+более|до|≈|~)?\s*\d+(?:[.,]\d+)?\s*%/u', '', $chunk));
            }

            // Скобочные уточнения: синоним/расшифровка («(SLES)», «(диоксид титана)»).
            $syn = [];

            if (preg_match_all('/\(([^()]{2,80})\)/u', $chunk, $mm))
            {
                foreach ($mm[1] as $inner)
                {
                    $inner = trim($inner);

                    if ($inner !== '' AND ! preg_match('/^\d+([.,]\d+)?$/', $inner))
                    {
                        $syn[] = $inner;
                    }
                }

                $chunk = trim(preg_replace('/\([^()]{2,80}\)/u', ' ', $chunk));
            }

            // Обрезаем посимвольно (preg), а не trim() с многобайтовыми
            // байтами: trim() режет строку по байтам и ломает последний
            // символ («сахар» → «саха�»).
            $chunk = preg_replace('/^[\s.\-\x{2013}\x{2014}]+|[\s.\-\x{2013}\x{2014}]+$/u', '', $chunk);
            $chunk = trim(preg_replace('/\s+/u', ' ', $chunk));

            if ($chunk === '')
            {
                continue;
            }

            $items[] = [
                'raw'     => $chunk,
                'name'    => $chunk,
                'syn'     => $syn,
                'percent' => $percent,
            ];
        }

        return $items;
    }

    /**
     * Нормализованный ключ названия: нижний регистр, ё→е, дефисы/дроби в пробелы,
     * без пунктуации, одиночные пробелы.
     */
    public static function normalize($name)
    {
        $name = (string) $name;
        $name = mb_strtolower($name, 'UTF-8');
        $name = str_replace(['ё', '–', '—', '/', '\\', '_'], ['е', ' ', ' ', ' ', ' ', ' '], $name);
        $name = preg_replace('/[^a-zа-я0-9\s\+-]+/u', ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name);

        return trim($name);
    }

    /** E-код в любом написании («E 211», «Е211», «INS 211») → 'e211'. */
    public static function ecode($name)
    {
        // Кириллическая «Е» тоже встречается на этикетках.
        if (preg_match('/(?:^|[^a-z0-9])(?:e|е|ins)\s?-?\s?(\d{3,4}[a-z]?)(?:$|[^a-z0-9])/iu', ' '.$name.' ', $m))
        {
            return 'e'.$m[1];
        }

        return '';
    }

    /** Слова длиной от 4 символов, без стоп-слов. */
    public static function words($name)
    {
        $words = [];

        foreach (explode(' ', self::normalize($name)) as $word)
        {
            if (mb_strlen($word) >= 4 AND ! in_array($word, self::$_stopwords, TRUE))
            {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * Найти вещество для позиции.
     *
     * @param string $name  имя для поиска
     * @param array  $index ['rows' => slug => карточка, 'map' => ключ => slug]
     * @return array|NULL карточка + 'how' (как нашли)
     */
    public static function find($name, array $index)
    {
        $rows = $index['rows'] ?? [];
        $map  = $index['map'] ?? [];

        if ( ! $rows)
        {
            return NULL;
        }

        // 1. E-код — самый надёжный признак.
        $e = self::ecode($name);

        if ($e !== '' AND isset($map[$e]))
        {
            return $rows[$map[$e]] + ['how' => 'e-code'];
        }

        $key = self::normalize($name);

        if ($key === '')
        {
            return NULL;
        }

        // 2. Точное совпадение с именем/синонимом.
        if (isset($map[$key]))
        {
            return $rows[$map[$key]] + ['how' => 'exact'];
        }

        // 3. Пересечение слов: «натрия бензоат» ↔ «бензоат натрия».
        $words = self::words($name);

        if ($words)
        {
            $best = NULL;
            $best_score = 0.0;

            foreach ($rows as $slug => $row)
            {
                $hit = 0;

                foreach ($words as $word)
                {
                    foreach ($row['keys'] as $rkey)
                    {
                        if (self::_has_word($rkey, $word))
                        {
                            $hit++;
                            break;
                        }
                    }
                }

                $score = $hit / max(1, count($words));

                if ($score > $best_score)
                {
                    $best_score = $score;
                    $best = $slug;
                }
            }

            if ($best !== NULL AND $best_score >= 0.6)
            {
                return $rows[$best] + ['how' => 'words'];
            }
        }

        return NULL;
    }

    /**
     * Сопоставить позиции состава с базой.
     *
     * @return array ['matched' => [...карточка + raw/percent/how...], 'unknown' => [имя, ...]]
     */
    public static function match(array $items, array $index)
    {
        $matched = [];
        $unknown = [];

        foreach ($items as $item)
        {
            $candidates = array_merge([$item['name']], $item['syn'] ?? []);
            $found      = NULL;

            foreach ($candidates as $candidate)
            {
                $found = self::find($candidate, $index);

                if ($found)
                {
                    $found['query'] = $candidate;
                    break;
                }
            }

            if ($found)
            {
                $found['raw']     = $item['raw'] ?? $item['name'];
                $found['percent'] = $item['percent'] ?? NULL;
                $matched[]        = $found;
            }
            else
            {
                $unknown[] = $item['raw'] ?? $item['name'];
            }
        }

        return ['matched' => $matched, 'unknown' => $unknown];
    }

    // ------------------------------------------------------------------ оценка

    /**
     * Оценка состава: 0..100 (больше — спокойнее) и вердикт.
     *
     * Оценка = 100 − средний взвешенный риск − штраф за неизвестные позиции.
     * Это объяснимая эвристика, а не медицинская истина.
     *
     * @return array ['score' => int, 'verdict' => string, 'counts' => [...]]
     */
    public static function score(array $matched, array $unknown = [])
    {
        $counts = ['useful' => 0, 'neutral' => 0, 'caution' => 0, 'risky' => 0, 'avoid' => 0];
        $total  = 0;

        foreach ($matched as $row)
        {
            $verdict = (string) ($row['verdict'] ?? 'neutral');

            if ( ! isset($counts[$verdict]))
            {
                $verdict = 'neutral';
            }

            $counts[$verdict]++;
            $risk = max(0, min(4, (int) ($row['risk'] ?? 1)));
            $total += self::RISK_PENALTY[$risk];
        }

        $known = max(1, count($matched));
        $mean  = $total / $known;

        // Средний риск 0 → 100, 2 → ≈64, 3 → ≈46, 4 → 25.
        $score = 100 - ($mean * 18);

        // Половина состава неизвестна — честно снижаем уверенность.
        $unknown_share = count($unknown) / max(1, count($matched) + count($unknown));
        $score -= $unknown_share * 20;

        $score = (int) max(0, min(100, (int) round($score)));

        if ($score >= 78)
        {
            $verdict = 'useful';
        }
        elseif ($score >= 58)
        {
            $verdict = 'neutral';
        }
        elseif ($score >= 38)
        {
            $verdict = 'caution';
        }
        elseif ($score >= 20)
        {
            $verdict = 'risky';
        }
        else
        {
            $verdict = 'avoid';
        }

        return ['score' => $score, 'verdict' => $verdict, 'counts' => $counts];
    }

    /**
     * Советы и предупреждения.
     *
     * @param array $matched  найденные карточки (с 'how', 'raw', 'percent')
     * @param array $unknown  неизвестные позиции
     * @param array $profile  ['skin' => …, 'diet' => …, 'conditions' => [...], 'allergies' => строка]
     * @param array $reasons  avoid-код => человекочитаемая причина (конфиг)
     * @return array [['level' => 'danger'|'warn'|'info'|'good', 'title' => …, 'text' => …], ...]
     */
    public static function advice(array $matched, array $unknown, array $profile, array $reasons)
    {
        $out = [];

        $conditions = array_filter(array_map('strval', (array) ($profile['conditions'] ?? [])));
        $skin       = (string) ($profile['skin'] ?? '');
        $diet       = (string) ($profile['diet'] ?? 'none');
        $allergies  = trim((string) ($profile['allergies'] ?? ''));

        // 1. Личные предупреждения: avoid-коды карточки против профиля.
        $personal = [];

        foreach ($matched as $row)
        {
            $codes = array_map('strval', (array) ($row['avoid'] ?? []));

            foreach ($codes as $code)
            {
                // Скобки обязательны: у «OR» приоритет ниже присваивания — без
                // них $hit получил бы только первый операнд, и проверки кожи и
                // диеты молча не работали бы (см. .tools/php_ternary_lint.py).
                $hit = (
                    in_array($code, $conditions, TRUE)
                    OR ($code === $skin)
                    OR (in_array($code, ['vegan', 'vegetarian'], TRUE) AND $code === $diet)
                );

                if ($hit)
                {
                    $personal[$row['name_ru'] !== '' ? $row['name_ru'] : $row['slug']] = $reasons[$code] ?? $code;
                }
            }
        }

        if ($personal)
        {
            $out[] = [
                'level' => 'danger',
                'title' => 'С учётом вашего профиля',
                'text'  => 'Стоит обратить внимание: '.implode('; ', array_map(
                    function ($name, $why) { return $name.' — '.$why; },
                    array_keys($personal), array_values($personal)
                )).'.',
            ];
        }

        // 2. Вещества высокого риска в составе.
        $risky = [];

        foreach ($matched as $row)
        {
            if ((int) $row['risk'] >= 3)
            {
                $risky[] = ($row['name_ru'] !== '' ? $row['name_ru'] : $row['slug'])
                    .(! empty($row['e_code']) ? ' ('.$row['e_code'].')' : '');
            }
        }

        if ($risky)
        {
            $out[] = [
                'level' => 'warn',
                'title' => count($risky) > 1 ? 'Вещества с повышенным риском' : 'Вещество с повышенным риском',
                'text'  => implode(', ', array_slice($risky, 0, 6)).'. Оценки осторожные: ориентируйтесь на регулярность и количество.',
            ];
        }

        // 3. Сахар/сироп в начале состава — ингредиенты перечисляют по убыванию.
        $first = array_slice($matched, 0, 3);
        $sugar = [];

        foreach ($first as $row)
        {
            $cat = (string) ($row['category'] ?? '');

            if ($cat === 'сахар и сиропы')
            {
                $sugar[] = $row['name_ru'] !== '' ? $row['name_ru'] : $row['slug'];
            }
        }

        if ($sugar)
        {
            $out[] = [
                'level' => 'warn',
                'title' => 'Сахар в начале списка',
                'text'  => 'Ингредиенты указывают по убыванию: '.implode(', ', $sugar).' — в первых трёх позициях, значит, их в продукте много.',
            ];
        }

        // 4. Аллерген из профиля встречается в составе.
        if ($allergies !== '')
        {
            $needle = self::normalize($allergies);

            foreach ($matched as $row)
            {
                $hay = $row['keys'] ?? [self::normalize($row['name_ru'])];

                foreach ($hay as $key)
                {
                    if ($needle !== '' AND $key !== '' AND (self::_has_word($key, $needle) OR self::_has_word($needle, $key)))
                    {
                        $out[] = [
                            'level' => 'danger',
                            'title' => 'Аллерген в составе',
                            'text'  => 'Вы отметили аллергию на «'.$allergies.'», а в составе есть «'.($row['name_ru'] !== '' ? $row['name_ru'] : $row['slug']).'». Если это совпадение слов — проверьте упаковку.',
                        ];
                        break 2;
                    }
                }
            }
        }

        // 5. Пропорция состава, которую база ещё не знает.
        if (count($unknown) > 0)
        {
            $out[] = [
                'level' => count($unknown) > count($matched) ? 'warn' : 'info',
                'title' => 'База ещё не знает '.count($unknown).' '.self::_plural(count($unknown), 'вещество', 'вещества', 'веществ'),
                'text'  => 'Незнакомые позиции: '.implode(', ', array_slice($unknown, 0, 5))
                    .'. Они поставлены в очередь изучения — со временем база их разберёт.',
            ];
        }

        // 6. Похвала, если есть за что.
        $counts = self::score($matched, $unknown)['counts'];

        if ($counts['useful'] >= 3 AND $counts['risky'] + $counts['avoid'] === 0)
        {
            $out[] = [
                'level' => 'good',
                'title' => 'Состав выглядит спокойным',
                'text'  => 'Полезных для профиля веществ — '.$counts['useful'].', веществ повышенного риска не найдено.',
            ];
        }

        return $out;
    }

    /** Еда или косметика — по маркерам состава. */
    public static function kind_guess($text, array $matched = [])
    {
        $hay = ' '.self::normalize($text).' ';

        $food = 0;

        foreach (self::$_food_markers as $marker)
        {
            if ($marker !== '' AND mb_strpos($hay, $marker) !== FALSE)
            {
                $food++;
            }
        }

        $cosmetic = 0;

        foreach (self::$_cosmetic_markers as $marker)
        {
            if (mb_strpos($hay, $marker) !== FALSE)
            {
                $cosmetic++;
            }
        }

        // E-коды и категории карточек — сильные признаки еды.
        if (preg_match('/(?:^|\s)(?:e|е)\s?-?\d{3}/u', $hay))
        {
            $food += 2;
        }

        foreach ($matched as $row)
        {
            if (($row['kind'] ?? '') === 'food')
            {
                $food++;
            }
            elseif (($row['kind'] ?? '') === 'cosmetic')
            {
                $cosmetic++;
            }
        }

        if ($food === 0 AND $cosmetic === 0)
        {
            return 'auto';
        }

        return $cosmetic > $food ? 'cosmetic' : 'food';
    }

    /**
     * Тип продукта по тексту этикетки: «зубная паста», «печенье», «пиво».
     *
     * Владелец (03.10.2026): «убери select — пускай сама определяет (продукт
     * может быть разной категории… с таким квантованием)». Квантование грубое
     * и намеренно: нужен понятный ярлык для списка разборов, статистики и
     * будущих сравнений товаров, а не классификатор. Слова типов и их веса
     * живут в конфиге (`product_kinds`), логика — здесь.
     *
     * ГДЕ ИЩЕМ. «Голова» — название продукта до слова «состав»: в самом составе
     * встречаются слова чужих типов, и без этого деления печенье уезжало в
     * «Масла и жиры» («масло растительное»), а водка — в «Воду» («вода
     * питьевая»). Проверено на 37 образцах этикеток (см. памятку модуля).
     *
     * ВЕСА: фраза из двух слов специфичнее одного (вдвое), слово в названии
     * продукта важнее слова из состава (втрое против одного очка).
     *
     * @param  string $text      текст этикетки или состав
     * @param  string $kind_hint вид состава (food|cosmetic|auto) для запасного типа
     * @return array ['slug','title','icon','kind','score'] — score 0 значит «тип запасной»
     */
    public static function product_kind($text, $kind_hint = '')
    {
        $norm = self::normalize((string) $text);
        $cut  = mb_strlen($norm);

        foreach ([' состав', ' ингредиенты', ' ingredients'] as $key)
        {
            $pos = mb_strpos($norm, $key);

            if ($pos !== FALSE AND $pos > 0)
            {
                $cut = min($cut, $pos);
            }
        }

        $hay  = ' '.$norm.' ';
        $head = ' '.mb_substr($norm, 0, min($cut, 120)).' ';
        $best = NULL;
        $best_score = 0;

        foreach ((array) Service_Nj646_Store::cfg('product_kinds', []) as $slug => $spec)
        {
            $score = 0;

            foreach ((array) ($spec['words'] ?? []) as $word)
            {
                $word = self::normalize((string) $word);

                if ($word === '')
                {
                    continue;
                }

                $weight = mb_strpos($word, ' ') !== FALSE ? 2 : 1;

                if (mb_strpos($head, $word) !== FALSE)
                {
                    $score += 3 * $weight;
                }
                elseif (mb_strpos($hay, $word) !== FALSE)
                {
                    $score += $weight;
                }
            }

            if ($score > $best_score)
            {
                $best       = (string) $slug;
                $best_score = $score;
            }
        }

        // Ни одно слово не сработало — показываем честный запасной тип по виду
        // состава, а не пустое место: «Продукт» или «Косметика».
        if ($best === NULL)
        {
            $best = ((string) $kind_hint === 'cosmetic') ? 'cosmetics' : 'food';
        }

        return self::product_kind_info($best) + ['score' => $best_score];
    }

    /** Описание типа продукта по слагу: название, иконка, вид состава. */
    public static function product_kind_info($slug)
    {
        $slug = (string) $slug;
        $spec = (array) Service_Nj646_Store::cfg('product_kinds.'.$slug, []);

        return [
            'slug'  => $slug,
            'title' => (string) ($spec['title'] ?? 'Продукт'),
            'icon'  => (string) ($spec['icon'] ?? 'fa-basket-shopping'),
            'kind'  => (string) ($spec['kind'] ?? ''),
        ];
    }

    /**
     * Дата по-человечески: «сегодня в 14:20», «вчера», «на прошлой неделе».
     *
     * Нужна списку «Мои разборы»: владелец описал его словами «зубную пасту
     * “Название” разбирал на прошлой неделе» — то есть по дате человек думает
     * именно так, а не «03.10.2026 14:20».
     */
    public static function ago_ru($when, $now = NULL)
    {
        $ts = is_numeric($when) ? (int) $when : strtotime((string) $when);

        if ( ! $ts)
        {
            return '';
        }

        $now  = $now === NULL ? time() : (int) $now;
        $diff = $now - $ts;

        if ($diff < 0)
        {
            return date('d.m.Y', $ts);
        }

        if ($diff < 60)
        {
            return 'только что';
        }

        if ($diff < 3600)
        {
            return self::_plural((int) floor($diff / 60), 'минуту', 'минуты', 'минут').' назад';
        }

        $days = (int) floor((strtotime('today', $now) - strtotime('today', $ts)) / 86400);

        if ($days <= 0)
        {
            return 'сегодня в '.date('H:i', $ts);
        }

        if ($days === 1)
        {
            return 'вчера в '.date('H:i', $ts);
        }

        if ($days < 7)
        {
            return self::_plural($days, 'день', 'дня', 'дней').' назад';
        }

        if ($days < 14)
        {
            return 'на прошлой неделе';
        }

        if ($days < 60)
        {
            return self::_plural((int) floor($days / 7), 'неделю', 'недели', 'недель').' назад';
        }

        return date('d.m.Y', $ts);
    }

    /** Похоже ли содержимое на картинку (для загрузки фото этикетки). */
    public static function looks_like_image($bytes)
    {
        $bytes = (string) $bytes;

        if (strlen($bytes) < 12)
        {
            return FALSE;
        }

        // JPEG, PNG, WebP, HEIC — форматы, которые делают телефоны.
        return strncmp($bytes, "\xFF\xD8\xFF", 3) === 0
            OR strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0
            OR (strncmp($bytes, 'RIFF', 4) === 0 AND substr($bytes, 8, 4) === 'WEBP')
            OR (strncmp($bytes, '\x00\x00\x00', 3) === 0 AND substr($bytes, 4, 4) === 'ftyp');
    }

    /** MIME по содержимому; пусто — если не распознали. */
    public static function mime($bytes)
    {
        $bytes = (string) $bytes;

        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
        if (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0) return 'image/png';
        if (strncmp($bytes, 'RIFF', 4) === 0 AND substr($bytes, 8, 4) === 'WEBP') return 'image/webp';
        if (strlen($bytes) > 12 AND substr($bytes, 4, 4) === 'ftyp') return 'image/heic';

        return '';
    }

    /** Человекочитаемые названия вердиктов (для вьюх и отчётов). */
    public static function verdict_label($verdict)
    {
        $map = [
            'useful'  => 'полезно',
            'neutral' => 'нейтрально',
            'caution' => 'с осторожностью',
            'risky'   => 'рискованно',
            'avoid'   => 'избегать',
        ];

        return $map[$verdict] ?? (string) $verdict;
    }

    /**
     * Статус по-русски: перевод через i18n (`modules/nj646_label/i18n/ru.php`).
     *
     * Владелец (03.10.2026): «на /nj646/admin и на других страницах статусы
     * переводи на русский с помощью i18n». Статус — это ЗНАЧЕНИЕ КОЛОНКИ
     * (`nj_120_jobs.status`, `nj_646_runs.status`, `nj_646_unknowns.status`,
     * `nj_646_ingredients.status`): в базе и в API он остаётся латиницей,
     * переводится только то, что видит человек.
     *
     * Группа обязательна: одно и то же слово у разных сущностей значит разное
     * («queued» у прогона — «в очереди», у незнакомца — «ждёт изучения»).
     * Неизвестный статус возвращается КАК ЕСТЬ: пустая ячейка в админке хуже
     * английского слова, а новый статус в базе появляется раньше перевода.
     *
     * @param string $status значение из базы (pending, studied, draft…)
     * @param string $group  job | run | unknown | card
     */
    public static function status_label($status, $group = 'run')
    {
        $status = trim((string) $status);

        if ($status === '')
        {
            return '';
        }

        $key   = 'nj646.'.(string) $group.'.'.$status;
        $label = __($key);

        // `__()` возвращает сам ключ, когда перевода нет (см. I18n::get).
        return $label === $key ? $status : $label;
    }

    /**
     * Статусы группы разом — для JS и `json_encode` (карта «код → подпись»).
     *
     * Страницы модуля рисуют часть списков в браузере (очередь изучения,
     * справочник деревом): карта уезжает в разметку один раз, и JS не
     * занимается переводами.
     */
    public static function status_labels($group = 'run')
    {
        $groups = [
            'job'     => ['pending', 'claimed', 'running', 'done', 'failed', 'canceled'],
            'run'     => ['queued', 'running', 'done', 'error'],
            'unknown' => ['new', 'queued', 'studied', 'ignored'],
            'card'    => ['published', 'draft', 'hidden'],
        ];

        $out = [];

        foreach ((array) ($groups[(string) $group] ?? []) as $status)
        {
            $out[$status] = self::status_label($status, $group);
        }

        return $out;
    }

    /** Подпись шкалы 0..100 для итогового вердикта. */
    public static function score_label($score)
    {
        if ($score >= 78) return 'спокойный состав';
        if ($score >= 58) return 'умеренный состав';
        if ($score >= 38) return 'спорный состав';
        if ($score >= 20) return 'много поводов для внимания';
        return 'лучше избегать';
    }

    /**
     * Сравнение двух составов: что общего, что только у одного — и чей чище.
     *
     * Чистая функция, как `match()` и `score()`: база приходит индексом, поэтому
     * её можно проверить без БД. Риски берутся по АКТУАЛЬНЫМ карточкам базы —
     * вещество могло быть изучено позже разбора, и «сегодня» это честнее:
     * оба состава меряются одной линейкой на один момент.
     *
     * Возвращает обе стороны (`a`, `b`), три набора веществ (`common`,
     * `only_a`, `only_b`), разницу оценок и то, чья взяла (`leader`).
     *
     * @param  array $items_a позиции состава A (результат parse())
     * @param  array $items_b позиции состава B
     * @param  array $index   индекс веществ (Store::index())
     * @return array
     */
    public static function compare(array $items_a, array $items_b, array $index)
    {
        $a = self::_compare_side($items_a, $index);
        $b = self::_compare_side($items_b, $index);

        $common = $only_a = $only_b = [];

        foreach ($a['known'] as $key => $row)
        {
            if (isset($b['known'][$key]))
            {
                $row['other_risk'] = (int) ($b['known'][$key]['risk'] ?? 0);
                $common[] = $row;
            }
            else
            {
                $only_a[] = $row;
            }
        }

        foreach ($b['known'] as $key => $row)
        {
            if ( ! isset($a['known'][$key]))
            {
                $only_b[] = $row;
            }
        }

        // Сначала то, к чему больше поводов присмотреться, потом по алфавиту:
        // в списке различий человек ищет не «что вообще разное», а «что опаснее».
        $by_risk = function ($x, $y) {
            $rx = max((int) ($x['risk'] ?? 0), (int) ($x['other_risk'] ?? 0));
            $ry = max((int) ($y['risk'] ?? 0), (int) ($y['other_risk'] ?? 0));

            return $rx === $ry
                ? strcmp((string) ($x['name_ru'] ?? ''), (string) ($y['name_ru'] ?? ''))
                : ($ry <=> $rx);
        };

        usort($common, $by_risk);
        usort($only_a, $by_risk);
        usort($only_b, $by_risk);

        return [
            'a'      => $a,
            'b'      => $b,
            'common' => $common,
            'only_a' => $only_a,
            'only_b' => $only_b,
            'diff'   => $a['score'] - $b['score'],
            'leader' => $a['score'] === $b['score'] ? 'draw' : ($a['score'] > $b['score'] ? 'a' : 'b'),
        ];
    }

    /** Одна сторона сравнения: разобранные позиции, оценка и риски по базе. */
    protected static function _compare_side(array $items, array $index)
    {
        $match = self::match($items, $index);
        $score = self::score($match['matched'], $match['unknown']);

        // Одно вещество — одна строка: в составе его могли назвать дважды
        // (то по имени, то по E-коду), а в сравнении это дубль.
        $known = [];

        foreach ($match['matched'] as $row)
        {
            $known[(int) $row['id']] = $row;
        }

        $risk_sum = 0;
        $high     = 0;

        foreach ($known as $row)
        {
            $risk = max(0, min(4, (int) ($row['risk'] ?? 1)));
            $risk_sum += $risk;

            if ($risk >= 3)
            {
                $high++;
            }
        }

        return [
            'known'     => $known,
            'unknown'   => array_values(array_unique($match['unknown'])),
            'score'     => (int) $score['score'],
            'verdict'   => (string) $score['verdict'],
            'counts'    => (array) $score['counts'],
            'risk_mean' => $known ? round($risk_sum / count($known), 1) : 0.0,
            'high'      => $high,
        ];
    }

    // ------------------------------------------------------------------ служебное

    protected static function _has_word($haystack, $needle)
    {
        $haystack = ' '.trim((string) $haystack).' ';
        $needle   = trim((string) $needle);

        if ($needle === '')
        {
            return FALSE;
        }

        // Слово целиком или как начало слова («лавр» ↔ «лавровый лист»).
        return mb_strpos($haystack, ' '.$needle.' ') !== FALSE
            OR mb_strpos($haystack, ' '.$needle) !== FALSE;
    }

    protected static function _plural($n, $one, $few, $many)
    {
        $n = abs((int) $n % 100);

        if ($n >= 11 AND $n <= 19)
        {
            return $many;
        }

        $n = $n % 10;

        if ($n === 1)
        {
            return $one;
        }

        if ($n >= 2 AND $n <= 4)
        {
            return $few;
        }

        return $many;
    }
}
