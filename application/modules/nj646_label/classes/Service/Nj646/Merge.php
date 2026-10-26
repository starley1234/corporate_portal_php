<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Merge — слияние дублей производителей и товаров.
 *
 * Зачем. Карточки производителей и товаров растут из строк этикеток, а этикетки
 * пишут одну и ту же организацию по-разному: «ООО „Ромашка“», «Ромашка»,
 * «РОМАШКА-ТРЕЙД». Ключ карточки — нормализованное имя (`About::producer_key`),
 * поэтому «Ромашка» и «Ромашка-Трейд» расходятся, и один завод живёт в базе
 * двумя записями: разборы разложены по двум страницам, счётчики разъехались.
 *
 * Что здесь. Только ЧИСТАЯ логика: подсказка «эти две карточки — одна и та же»
 * (`duplicates()`) и план переноса (`plan()`, `describe()`) — человеческие шаги и
 * список полей «было → станет». В базу не пишет вовсе: запись делает
 * `Service_Nj646_Store::producer_merge()`/`product_merge()`, поэтому и правила
 * подозрений, и текст плана проверяются стендом без БД.
 *
 * ГЛАВНОЕ: слияние НЕ автоматическое. Мы лишь показываем пары-подозрения, а
 * решение принимает админ — «Ромашка» и «Ромашка-Трейд» действительно могут быть
 * двумя разными юрлицами (тогда в карточках разные ИНН, и мы об этом скажем).
 *
 * Принцип переноса: главная карточка побеждает, а её пустые поля заполняются из
 * второй; счётчики складываются; разборы и товары переезжают на главную.
 * Ничего не удаляется, кроме самой второй карточки.
 */
class Service_Nj646_Merge
{
    /** Формы юрлиц: на «кто это» не влияют, в подсказке не участвуют. */
    const LEGAL_FORMS = ['ооо', 'оао', 'зао', 'пао', 'ао', 'ип', 'кфх', 'нко', 'гуп', 'муп',
                         'мп', 'пк', 'спк', 'зao', 'тд', 'тк', 'гк', 'нпк', 'кх'];

    /** Слова-наполнители: без них «Ромашка» и «Торговый дом Ромашка» — одно. */
    const GENERIC_WORDS = ['компания', 'фирма', 'предприятие', 'торговый', 'дом', 'группа',
                           'производство', 'производитель', 'изготовитель', 'завод',
                           'комбинат', 'фабрика', 'кондитерская', 'пищевая', 'молочная',
                           'мясокомбинат', 'хлебозавод', 'хлебокомбинат', 'пивзавод',
                           'агрофирма', 'агрохолдинг', 'холдинг', 'корпорация', 'импортер',
                           'импортёр', 'поставщик'];

    /** Мера веса товара — в названии она к делу не относится («Молоко 3,2%»). */
    const PRODUCT_NOISE = ['г', 'гр', 'кг', 'мг', 'мл', 'л', 'шт', 'пач', 'упак', 'порц',
                           'вес', 'масса', 'объем', 'объём', 'ст', 'стекло', 'пл', 'пэт',
                           'бут', 'банка', 'бутылка', 'пакет', 'коробка'];

    /** Поля, которые заполняем из второй карточки, если у главной пусто. */
    const PRODUCER_FILL = ['name', 'role', 'address', 'country', 'inn', 'ogrn', 'phone', 'site',
                           'ontology_url'];
    const PRODUCT_FILL  = ['brand', 'barcode', 'weight', 'gost', 'shelf_life', 'storage', 'country'];

    /** Подписи полей — для предпросмотра «было → станет». */
    const LABELS = [
        'name' => 'название', 'role' => 'роль', 'address' => 'адрес', 'country' => 'страна',
        'inn' => 'ИНН', 'ogrn' => 'ОГРН', 'phone' => 'телефон', 'site' => 'сайт',
        'ontology_url' => 'онтология', 'brand' => 'бренд', 'barcode' => 'штрихкод',
        'weight' => 'масса', 'gost' => 'ГОСТ/ТУ', 'shelf_life' => 'срок годности',
        'storage' => 'хранение', 'producer_id' => 'производитель',
    ];

    /** Сколько пар показываем: дальше это уже не «проверить глазами». */
    const MAX_PAIRS = 12;

    /**
     * Подсказка для производителя: имя без форм юрлиц и слов-наполнителей.
     *
     * @param  string $name имя карточки
     * @return string нормализованное «ядро» имени (пустое — сравнивать нечего)
     */
    public static function producer_hint($name)
    {
        $name = self::_clean($name, self::LEGAL_FORMS, self::GENERIC_WORDS);

        return mb_strlen($name) >= 4 ? $name : '';
    }

    /**
     * Подсказка для товара: имя без веса/упаковки и слов-наполнителей бренда.
     *
     * @param  string $name  название товара
     * @param  string $brand бренд (если есть)
     * @return string нормализованное «ядро» названия
     */
    public static function product_hint($name, $brand = '')
    {
        $name = self::_clean($name, [], self::PRODUCT_NOISE);

        // Бренд из названия убираем: «Молоко Простоквашино» и «Молоко» одного
        // производителя — один товар, а разные бренды тут ни при чём.
        $brand = self::_clean($brand, [], []);

        if ($brand !== '' AND mb_strpos($name, $brand) !== FALSE)
        {
            $name = trim(str_replace($brand, ' ', $name));
            $name = trim(preg_replace('/\s+/u', ' ', $name));
        }

        return mb_strlen($name) >= 4 ? $name : '';
    }

    /**
     * Пары-подозрения на дубли среди карточек.
     *
     * Правила (по убыванию уверенности):
     *   1. одинаковый ИНН или ОГРН — точно одна организация;
     *   2. одинаковый штрихкод — точно один товар;
     *   3. совпало «ядро» имени — очень похоже на дубль;
     *   4. ядро одной карточки — начало ядра другой («Ромашка» ⊂ «Ромашка-Трейд»).
     *
     * Товары сравниваем только внутри одного производителя (или когда у одной из
     * карточек производитель не заполнен): «Молоко 3,2%» двух заводов — это
     * разные товары, и сливать их нельзя.
     *
     * @param  array  $rows строки карточек (лишние ключи не мешают)
     * @param  string $kind 'producer' | 'product'
     * @return array список ['keep' => row, 'drop' => row, 'why' => строка, 'score' => int]
     */
    public static function duplicates(array $rows, $kind)
    {
        $out = [];

        $rows = array_values(array_filter($rows, function ($row) { return is_array($row); }));

        for ($i = 0; $i < count($rows); $i++)
        {
            for ($j = $i + 1; $j < count($rows); $j++)
            {
                $pair = $kind === 'product'
                    ? self::_product_pair($rows[$i], $rows[$j])
                    : self::_producer_pair($rows[$i], $rows[$j]);

                if ($pair)
                {
                    $out[] = $pair + ['keep' => $rows[$i], 'drop' => $rows[$j]];
                }
            }
        }

        // Сначала самые уверенные, при равенстве — карточки «потяжелее»
        // (больше разборов/товаров): сливать выгоднее там, где история.
        usort($out, function ($a, $b) {
            if ($a['score'] !== $b['score'])
            {
                return $b['score'] - $a['score'];
            }

            return self::_weight($b['keep']) + self::_weight($b['drop'])
                 - self::_weight($a['keep']) - self::_weight($a['drop']);
        });

        $out = array_slice($out, 0, self::MAX_PAIRS);

        // Главной делаем карточку «потяжелее»: у неё ссылки и история.
        foreach ($out as $k => $pair)
        {
            if (self::_weight($pair['keep']) < self::_weight($pair['drop']))
            {
                $out[$k]['keep'] = $pair['drop'];
                $out[$k]['drop'] = $pair['keep'];
            }
        }

        return $out;
    }

    /**
     * План объединения: что перенесётся, какие поля заполнятся, о чём предупредить.
     *
     * @param  string $kind   'producer' | 'product'
     * @param  array  $keep   главная карточка (остаётся)
     * @param  array  $drop   вторая карточка (исчезнет)
     * @param  array  $counts сколько переезжает: ['analyses' => N, 'products' => M, 'glued' => K]
     * @return array план для предпросмотра и запуска
     */
    public static function plan($kind, array $keep, array $drop, array $counts = [])
    {
        $kind   = $kind === 'product' ? 'product' : 'producer';
        $fill   = $kind === 'product' ? self::PRODUCT_FILL : self::PRODUCER_FILL;
        $fields = [];

        foreach ($fill as $field)
        {
            $before = trim((string) ($keep[$field] ?? ''));
            $after  = trim((string) ($drop[$field] ?? ''));

            if ($before === '' AND $after !== '')
            {
                $fields[] = ['field' => $field, 'label' => self::LABELS[$field] ?? $field,
                             'before' => '', 'after' => $after];
            }
        }

        // Товар: производитель главной карточки тоже может быть пустым.
        if ($kind === 'product'
            AND empty($keep['producer_id']) AND ! empty($drop['producer_id']))
        {
            $fields[] = ['field' => 'producer_id', 'label' => 'производитель', 'before' => '',
                         'after' => (string) ($drop['producer_name'] ?? '#'.(int) $drop['producer_id'])];
        }

        $steps = [];
        $steps[] = 'Разборов переедет: '.(int) ($counts['analyses'] ?? 0).'.';

        if ($kind === 'producer')
        {
            $steps[] = 'Товаров переедет: '.(int) ($counts['products'] ?? 0).'.';

            if ( ! empty($counts['glued']))
            {
                $steps[] = 'Сольются с уже имеющимися у главного товарами: '.(int) $counts['glued'].'.';
            }
        }

        if ($fields)
        {
            $steps[] = 'Заполнится пустых полей главной: '.count($fields).'.';
        }

        $steps[] = 'Встреч (hits) сложатся: '
            .((int) ($keep['hits'] ?? 0) + (int) ($drop['hits'] ?? 0)).'.';

        $warnings = [];

        $keep_inn = trim((string) ($keep['inn'] ?? ''));
        $drop_inn = trim((string) ($drop['inn'] ?? ''));

        if ($kind === 'producer' AND $keep_inn !== '' AND $drop_inn !== '' AND $keep_inn !== $drop_inn)
        {
            $warnings[] = 'ИНН различаются ('.$keep_inn.' и '.$drop_inn.') — проверьте, не разные ли это юрлица.';
        }

        if ($kind === 'product'
            AND ! empty($keep['barcode']) AND ! empty($drop['barcode'])
            AND (string) $keep['barcode'] !== (string) $drop['barcode'])
        {
            $warnings[] = 'Штрихкоды различаются ('.(string) $keep['barcode'].' и '.(string) $drop['barcode'].').';
        }

        return [
            'kind'     => $kind,
            'keep'     => $keep,
            'drop'     => $drop,
            'fields'   => $fields,
            'steps'    => $steps,
            'warnings' => $warnings,
            'counts'   => ['analyses' => (int) ($counts['analyses'] ?? 0),
                           'products' => (int) ($counts['products'] ?? 0),
                           'glued'    => (int) ($counts['glued'] ?? 0)],
        ];
    }

    /** План одной фразой: для flash-сообщения и заголовка. */
    public static function describe(array $plan)
    {
        $c = $plan['counts'] ?? [];

        $what = ($plan['kind'] ?? '') === 'product' ? 'товар' : 'производителя';

        return 'Объединяем '.$what.': разборов переедет '.(int) ($c['analyses'] ?? 0)
            .', полей заполнится '.count((array) ($plan['fields'] ?? []))
            .(! empty($c['products']) ? ', товаров переедет '.(int) $c['products'] : '');
    }

    /** «Потяжелее» — больше связей: у такой карточки остаётся история и ссылки. */
    protected static function _weight(array $row)
    {
        return (int) ($row['analyses'] ?? 0) * 10
             + (int) ($row['products'] ?? 0) * 5
             + (int) ($row['hits'] ?? 0);
    }

    /** Пара производителей — дубль или нет. */
    protected static function _producer_pair(array $a, array $b)
    {
        foreach (['inn' => 'ИНН', 'ogrn' => 'ОГРН'] as $field => $label)
        {
            $one = preg_replace('/\D/', '', (string) ($a[$field] ?? ''));
            $two = preg_replace('/\D/', '', (string) ($b[$field] ?? ''));

            if ($one !== '' AND $one === $two)
            {
                return ['why' => $label.' совпадает', 'score' => 4];
            }
        }

        $one = self::producer_hint($a['name'] ?? '');
        $two = self::producer_hint($b['name'] ?? '');

        if ($one === '' OR $two === '')
        {
            return NULL;
        }

        if ($one === $two)
        {
            return ['why' => 'Названия совпадают', 'score' => 3];
        }

        // «Ромашка» ⊂ «Ромашка Трейд»: короткая часть должна быть осмысленной.
        $short = mb_strlen($one) <= mb_strlen($two) ? $one : $two;
        $long  = $short === $one ? $two : $one;

        if (mb_strlen($short) >= 6 AND mb_strpos($long, $short) === 0)
        {
            return ['why' => 'Название «'.$short.'» — часть второго', 'score' => 2];
        }

        return NULL;
    }

    /** Пара товаров — дубль или нет. */
    protected static function _product_pair(array $a, array $b)
    {
        $one_bar = preg_replace('/\D/', '', (string) ($a['barcode'] ?? ''));
        $two_bar = preg_replace('/\D/', '', (string) ($b['barcode'] ?? ''));

        if (strlen($one_bar) >= 8 AND $one_bar === $two_bar)
        {
            return ['why' => 'Штрихкод совпадает', 'score' => 4];
        }

        $one_prod = (int) ($a['producer_id'] ?? 0);
        $two_prod = (int) ($b['producer_id'] ?? 0);

        // Разные производители — разные товары, даже с одинаковым названием.
        if ($one_prod > 0 AND $two_prod > 0 AND $one_prod !== $two_prod)
        {
            return NULL;
        }

        $one = self::product_hint($a['name'] ?? '', $a['brand'] ?? '');
        $two = self::product_hint($b['name'] ?? '', $b['brand'] ?? '');

        if ($one === '' OR $two === '')
        {
            return NULL;
        }

        if ($one === $two)
        {
            return ['why' => 'Названия совпадают', 'score' => 3];
        }

        $short = mb_strlen($one) <= mb_strlen($two) ? $one : $two;
        $long  = $short === $one ? $two : $one;

        if (mb_strlen($short) >= 6 AND mb_strpos($long, $short) === 0)
        {
            return ['why' => 'Название «'.$short.'» — часть второго', 'score' => 2];
        }

        return NULL;
    }

    /**
     * «Ядро» строки: нижний регистр, без кавычек и пунктуации, без служебных слов.
     *
     * @param  string $value строка
     * @param  array  $drop  слова, которые выбрасываем целиком (формы юрлиц)
     * @param  array  $noise слова, которые выбрасываем и как отдельные, и как часть слова
     * @return string
     */
    protected static function _clean($value, array $drop, array $noise)
    {
        $value = mb_strtolower(trim((string) $value));
        $value = str_replace(['ё'], ['е'], $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value));

        if ($value === '')
        {
            return '';
        }

        $words = [];

        foreach (explode(' ', $value) as $word)
        {
            if (in_array($word, $drop, TRUE))
            {
                continue;
            }

            // «250г», «1л», «3,2%» — вес и объём в ядро не входят.
            if (preg_match('/^\d+([.,]\d+)?$/', $word))
            {
                continue;
            }

            $noisy = FALSE;

            foreach ($noise as $part)
            {
                if (mb_substr($word, 0, mb_strlen($part)) === $part)
                {
                    $noisy = TRUE;
                    break;
                }
            }

            $words[] = $noisy ? '' : $word;
        }

        $value = trim(preg_replace('/\s+/u', ' ', implode(' ', $words)));

        // Однобуквенные обрывки («о», «в») — мусор сравнения.
        $keep = [];

        foreach (explode(' ', $value) as $word)
        {
            if (mb_strlen($word) > 1)
            {
                $keep[] = $word;
            }
        }

        return trim(implode(' ', $keep));
    }
}
