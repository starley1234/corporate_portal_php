<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Import — словарь веществ из CSV: разбор файла и ПЛАН правки базы.
 *
 * Зачем. База наполняется сама (посев, правки посетителей, изучение моделью), но
 * у владельца есть свои списки веществ — из справочников, из таблиц, из чужой
 * выгрузки. Экспорт из админки уже был, импорта не было: словарь приходилось
 * забивать руками по карточке. Здесь обратный ход.
 *
 * ГЛАВНОЕ ПРАВИЛО: сначала ПОКАЗАТЬ, потом писать. `parse()` читает файл,
 * `plan()` сверяет его с базой и раскладывает по четырём корзинам — добавится,
 * обновится, без изменений, с ошибками. Ничего не пишется, пока админ не нажал
 * «применить»; строки с ошибкой не пишутся вовсе (половина файла — это худший
 * исход, человек не поймёт, что применилось).
 *
 * Класс чистый: база приходит снаружи (`plan()` принимает уже найденные карточки),
 * поэтому и разбор, и план проверяются стендом без БД и без PHP-сервера.
 *
 * Формат — тот же, что отдаёт экспорт (`Admin::action_export`), поэтому круг
 * «выгрузил → поправил в таблице → загрузил» работает без переучивания:
 * разделитель `;`, первой строкой шапка, строки с `#` игнорируются, а вложенные
 * `;` и запятые берутся в кавычки, как это делает любой редактор таблиц.
 *
 * Чего импорт НЕ делает: не удаляет карточки (для этого модерация), не трогает
 * счётчик встреч `hits` (иначе статистика «что людям важно» поехала бы от
 * импорта) и не перетирает поля пустыми значениями — пустая ячейка значит
 * «здесь ничего не меняем».
 */
class Service_Nj646_Import
{
    /** Потолок строк за один раз: план должен читаться человеком. */
    const MAX_ROWS = 2000;

    /** Колонки файла → другие написания в шапке (русские и короткие). */
    const COLUMNS = [
        'slug'       => ['slug', 'слаг', 'код'],
        'name_ru'    => ['name_ru', 'название', 'имя', 'ru'],
        'name_int'   => ['name_int', 'латиница', 'inci', 'int'],
        'kind'       => ['kind', 'вид', 'тип'],
        'risk'       => ['risk', 'риск'],
        'verdict'    => ['verdict', 'вердикт'],
        'category'   => ['category', 'категория', 'раздел'],
        'e_code'     => ['e_code', 'e', 'екод', 'код_e'],
        'status'     => ['status', 'статус'],
        'confidence' => ['confidence', 'уверенность'],
        'summary'    => ['summary', 'пояснение', 'кратко'],
        'details'    => ['details', 'подробности', 'детали'],
        'synonyms'   => ['synonyms', 'синонимы'],
        'avoid'      => ['avoid', 'ограничения', 'кому нельзя'],
        'sources'    => ['sources', 'источники'],
    ];

    /** Порядок колонок в выгрузке — он же порядок для файла БЕЗ шапки. */
    const EXPORT_ORDER = ['slug', 'name_ru', 'name_int', 'kind', 'risk', 'verdict', 'category',
                          'e_code', 'status', 'source', 'confidence', 'hits', 'summary',
                          'synonyms', 'avoid'];

    /** Подписи полей для предпросмотра «было → станет». */
    const LABELS = [
        'name_ru' => 'название', 'name_int' => 'латиница', 'kind' => 'вид состава',
        'risk' => 'риск', 'verdict' => 'вердикт', 'category' => 'категория',
        'e_code' => 'E-код', 'status' => 'статус', 'confidence' => 'уверенность',
        'summary' => 'пояснение', 'details' => 'подробности', 'synonyms' => 'синонимы',
        'avoid' => 'ограничения', 'sources' => 'источники',
    ];

    const KIND_ALIASES = ['food' => 'food', 'еда' => 'food', 'пища' => 'food',
                          'cosmetic' => 'cosmetic', 'косметика' => 'cosmetic',
                          'both' => 'both', 'оба' => 'both', 'все' => 'both'];

    const STATUS_ALIASES = ['published' => 'published', 'опубликовано' => 'published', 'опубликовать' => 'published',
                            'draft' => 'draft', 'черновик' => 'draft',
                            'hidden' => 'hidden', 'скрыто' => 'hidden'];

    const VERDICT_ALIASES = ['useful' => 'useful', 'полезно' => 'useful',
                             'neutral' => 'neutral', 'нейтрально' => 'neutral',
                             'caution' => 'caution', 'с осторожностью' => 'caution', 'осторожно' => 'caution',
                             'risky' => 'risky', 'рискованно' => 'risky',
                             'avoid' => 'avoid', 'избегать' => 'avoid'];

    /**
     * Разобрать CSV в строки словаря.
     *
     * @param  string $text содержимое файла
     * @return array ['rows' => [...], 'errors' => [...], 'delimiter' => ';', 'header' => TRUE, 'total' => N]
     */
    public static function parse($text)
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", (string) $text));
        $out  = ['rows' => [], 'errors' => [], 'delimiter' => ';', 'header' => FALSE, 'total' => 0];

        if (trim($text) === '')
        {
            $out['errors'][] = ['line' => 0, 'message' => 'Файл пуст — вставьте строки словаря.'];
            return $out;
        }

        $out['delimiter'] = self::_sniff($text);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);

        $map   = NULL;
        $line  = 0;
        $seen  = [];

        while (($cells = fgetcsv($fh, 0, $out['delimiter'], '"', '\\')) !== FALSE)
        {
            $line++;

            // Комментарий (`# …`) — только отдельной строкой и только до шапки.
            if ($map === NULL AND count($cells) === 1 AND strpos(ltrim((string) $cells[0]), '#') === 0)
            {
                continue;
            }

            if (self::_blank($cells))
            {
                continue;
            }

            if ($map === NULL)
            {
                $head = self::_header_map($cells);

                if (self::_looks_like_header($head))
                {
                    // Первая осмысленная строка — шапка: дальше читаем по ней.
                    $map = $head;
                    $out['header'] = TRUE;
                    continue;
                }

                // Шапки нет — берём порядок выгрузки: «скачал и правил в Excel».
                $map = self::_header_map(self::EXPORT_ORDER);
            }

            $out['total']++;

            if ($out['total'] > self::MAX_ROWS)
            {
                $out['errors'][] = ['line' => $line, 'message' => 'Строк больше '.self::MAX_ROWS.' — разбейте файл на части.'];
                break;
            }

            $row = self::_row($cells, $map);

            foreach (self::_validate($row) as $message)
            {
                $out['errors'][] = ['line' => $line, 'message' => $message, 'name' => self::_name($row)];
            }

            // Дубль ловим только по ЯВНОМУ слагу: если колонка пуста, слаг ещё
            // будет выведен из названия (и два одинаковых названия плана получат
            // номера `-2`), а пустой ключ «склеивал» бы все такие строки в одну.
            if ($row['slug'] !== '' AND isset($seen[$row['slug']]))
            {
                $out['errors'][] = ['line' => $line, 'message' => 'Такой slug уже есть выше в файле (строка '.$seen[$row['slug']].') — оставьте одну строку.', 'name' => self::_name($row)];
                continue;
            }

            if ($row['slug'] !== '')
            {
                $seen[$row['slug']] = $line;
            }
            $row['line'] = $line;
            $out['rows'][] = $row;
        }

        fclose($fh);

        return $out;
    }

    /**
     * План правки базы: что добавится, что обновится, что без изменений и что не
     * прошло проверку.
     *
     * @param  array $rows     строки из parse()
     * @param  array $existing карточки базы по slug: ['slug' => строка карточки]
     * @return array ['create' => [], 'update' => [], 'same' => [], 'errors' => [], 'counts' => []]
     */
    public static function plan(array $rows, array $existing = [])
    {
        $plan = ['create' => [], 'update' => [], 'same' => [], 'errors' => [], 'counts' => []];

        // Какую карточку уже разобрали и на какой строке файла: две строки об
        // одной карточке — не «последняя победила», а конфликт, о котором надо
        // сказать вслух (иначе вторая правка молча затрёт первую).
        $touched = [];

        foreach ($rows as $row)
        {
            $slug = (string) ($row['slug'] ?? '');

            if ($slug === '')
            {
                $slug = self::slug_from($row);
            }

            $card = $existing[$slug] ?? NULL;

            if ($card AND isset($touched[$slug]))
            {
                $plan['errors'][] = [
                    'line'    => (int) ($row['line'] ?? 0),
                    'name'    => self::_name($row),
                    'message' => 'Карточка «'.$slug.'» уже разбирается строкой '.$touched[$slug].' — оставьте одну строку.',
                ];
                continue;
            }

            if ($card)
            {
                $touched[$slug] = (int) ($row['line'] ?? 0);
            }

            if ( ! $card)
            {
                // Новых строк с одинаково выведенным слагом может быть несколько:
                // два «Сахара» из разных списков — это одна карточка, вторую
                // строку отдаём с номером, а не теряем.
                $slug = self::_free_slug($slug, $existing, $plan['create']);

                $row['slug'] = $slug;
                $plan['create'][] = $row;
                continue;
            }

            $changed = self::_diff($row, $card);

            if ( ! $changed)
            {
                $plan['same'][] = ['slug' => $slug, 'name' => self::_name($row), 'line' => (int) ($row['line'] ?? 0)];
                continue;
            }

            // Значения для записи — только изменённые поля: остальное в карточке
            // остаётся как было (пустая ячейка значит «не меняем»).
            $apply = [];

            foreach (array_keys($changed) as $field)
            {
                $apply[$field] = $row[$field];
            }

            $plan['update'][] = [
                'slug'    => $slug,
                'id'      => (int) $card['id'],
                'name'    => self::_name($row),
                'line'    => (int) ($row['line'] ?? 0),
                'changed' => $changed,
                'apply'   => $apply,
                'fields'  => array_keys($changed),
            ];
        }

        $plan['counts'] = [
            'create' => count($plan['create']),
            'update' => count($plan['update']),
            'same'   => count($plan['same']),
            'errors' => count($plan['errors']),
        ];

        return $plan;
    }

    /** Строка плана одной фразой: для flash-сообщения и заголовка предпросмотра. */
    public static function describe(array $plan)
    {
        $c = $plan['counts'] ?? [];

        return 'добавится: '.(int) ($c['create'] ?? 0)
            .', обновится: '.(int) ($c['update'] ?? 0)
            .', без изменений: '.(int) ($c['same'] ?? 0)
            .', с ошибками: '.(int) ($c['errors'] ?? 0);
    }

    /** Значения «было → станет» для одной изменённой строки плана (для вьюхи). */
    public static function human($value)
    {
        if (is_array($value))
        {
            return implode(', ', array_map('strval', $value));
        }

        return trim((string) $value) === '' ? '—' : (string) $value;
    }

    /** Шаблон файла: шапка выгрузки + две строки-образца. Он же — справка по формату. */
    public static function template()
    {
        $rows = [
            self::EXPORT_ORDER,
            ['e211', 'Бензоат натрия', 'Sodium benzoate', 'both', '3', 'risky', 'консерванты', 'E211',
             'draft', 'csv', '90', '0', 'Синтетический консервант, спорен для аллергиков',
             'бензоат натрия|E211', 'аллергия на салицилаты', 'https://example.org/e211'],
            ['sugar', 'Сахар', 'Sucrose', 'food', '1', 'caution', 'сахар и сладости', '',
             'published', 'csv', '100', '0', 'Обычный сахар: безопасен, но злоупотребление вредно',
             'сахароза|сахарный песок', 'диабет', ''],
        ];

        $out = fopen('php://temp', 'r+');

        foreach ($rows as $row)
        {
            fputcsv($out, $row, ';');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    // ---------------------------------------------------------------- разбор

    /** Разделитель первой осмысленной строки: тот, что даёт больше знакомых колонок. */
    protected static function _sniff($text)
    {
        foreach (explode("\n", $text) as $line)
        {
            $line = trim($line);

            if ($line === '' OR strpos($line, '#') === 0)
            {
                continue;
            }

            $best  = ';';
            $score = -1;

            foreach ([';', ',', "\t", '|'] as $delim)
            {
                $cells = self::_header_map(self::_split($line, $delim));

                // Сколько колонок узнали — тем и меряем; при равенстве выигрывает
                // тот же порядок перебора, то есть `;` (наш формат выгрузки).
                if (count($cells) > $score)
                {
                    $best  = $delim;
                    $score = count($cells);
                }
            }

            return $best;
        }

        return ';';
    }

    /** Разбить строку тем же правилом, что и fgetcsv (для «принюхивания»). */
    protected static function _split($line, $delim)
    {
        $cells = str_getcsv($line, $delim, '"', '\\');

        return array_map('strval', (array) $cells);
    }

    /** Строка пустая: все ячейки — пробелы. */
    protected static function _blank(array $cells)
    {
        foreach ($cells as $cell)
        {
            if (trim((string) $cell) !== '')
            {
                return FALSE;
            }
        }

        return TRUE;
    }

    /**
     * Какие канонические колонки есть в этой строке и под какими номерами.
     *
     * @param  array $cells ячейки шапки или порядок выгрузки
     * @return array ['risk' => 4, …] (пусто — ни одной знакомой колонки)
     */
    protected static function _header_map(array $cells)
    {
        $map = [];

        foreach ($cells as $i => $cell)
        {
            $key = self::_key((string) $cell);

            if ($key === '' OR isset($map[$key]))
            {
                continue;
            }

            foreach (self::COLUMNS as $column => $aliases)
            {
                if ($key === $column OR in_array($key, $aliases, TRUE))
                {
                    $map[$column] = (int) $i;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Это шапка, а не строка данных?
     *
     * Одного совпадения мало: в файле без шапки строка данных может задеть
     * название колонки значением (`avoid` — это и вердикт, и список
     * ограничений), и такая строка «съедала» первую карточку. Поэтому шапкой
     * считаем строку, где узнали минимум две колонки и среди них — колонка
     * имени или слага.
     */
    protected static function _looks_like_header(array $map)
    {
        if (count($map) < 2)
        {
            return FALSE;
        }

        return isset($map['slug']) OR isset($map['name_ru']) OR isset($map['name_int']);
    }

    /** Ключ шапки: нижний регистр, без пробелов и подчёркиваний по краям. */
    protected static function _key($value)
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        $value = str_replace(['ё', ' '], ['е', '_'], $value);
        $value = trim(preg_replace('/_+/', '_', $value), '_');

        return $value;
    }

    /** Строка файла → нормализованная карточка (нет значения — NULL, «не меняем»). */
    protected static function _row(array $cells, array $map)
    {
        $row = [
            'slug' => '', 'name_ru' => '', 'name_int' => '', 'kind' => NULL, 'risk' => NULL,
            'verdict' => NULL, 'category' => '', 'e_code' => '', 'status' => NULL,
            'confidence' => NULL, 'summary' => '', 'details' => '',
            'synonyms' => [], 'avoid' => [], 'sources' => [],
        ];

        foreach (self::COLUMNS as $column => $aliases)
        {
            $i = $map[$column] ?? NULL;

            if ($i === NULL OR ! array_key_exists($i, $cells))
            {
                continue;
            }

            $value = trim((string) $cells[$i]);

            if ($value === '')
            {
                continue;
            }

            if (in_array($column, ['synonyms', 'avoid', 'sources'], TRUE))
            {
                $row[$column] = self::_list($value);
                continue;
            }

            $row[$column] = $value;

            if (in_array($column, ['kind', 'verdict', 'status'], TRUE))
            {
                $row[$column] = self::_alias($column, $value);
            }

            if ($column === 'risk' OR $column === 'confidence')
            {
                $row[$column] = (int) $value;
            }
        }

        $row['slug'] = self::slugify((string) $row['slug']);
        $row['e_code'] = self::e_code((string) $row['e_code']);

        return $row;
    }

    /** Значение-псевдоним («еда» → food). Незнакомое — как есть, чтобы упасть в проверке. */
    protected static function _alias($column, $value)
    {
        $table = ['kind' => self::KIND_ALIASES, 'verdict' => self::VERDICT_ALIASES, 'status' => self::STATUS_ALIASES];
        $key   = self::_key($value);

        return $table[$column][$key] ?? $key;
    }

    /** Список из ячейки: `|`, перевод строки, `;` — разделители; запятая — если нет других. */
    protected static function _list($value)
    {
        $value = trim((string) $value);

        if ($value === '')
        {
            return [];
        }

        $parts = preg_match('/[|\n;]/u', $value)
            ? preg_split('/\s*[|\n;]+\s*/u', $value)
            : preg_split('/\s*,\s*/u', $value);

        $list = [];

        foreach ((array) $parts as $part)
        {
            $part = trim((string) $part);

            if ($part !== '')
            {
                $list[$part] = $part;
            }
        }

        return array_slice(array_values($list), 0, 12);
    }

    /** Что не так со строкой: пустое имя, риск вне 0–4, незнакомый вид/вердикт/статус. */
    protected static function _validate(array $row)
    {
        $errors = [];

        if (self::_name($row) === '')
        {
            $errors[] = 'Нет названия: нужна хотя бы одна колонка имени (название или латиница).';
        }

        if ($row['risk'] !== NULL AND ($row['risk'] < 0 OR $row['risk'] > 4))
        {
            $errors[] = 'Риск '.$row['risk'].' — допустимо 0–4.';
        }

        if ($row['confidence'] !== NULL AND ($row['confidence'] < 0 OR $row['confidence'] > 100))
        {
            $errors[] = 'Уверенность '.$row['confidence'].' — допустимо 0–100.';
        }

        foreach ([
            ['kind', 'Вид состава', 'food|cosmetic|both', self::KIND_ALIASES],
            ['verdict', 'Вердикт', 'useful|neutral|caution|risky|avoid', self::VERDICT_ALIASES],
            ['status', 'Статус', 'published|draft|hidden', self::STATUS_ALIASES],
        ] as $rule)
        {
            $value = $row[$rule[0]];

            if ($value !== NULL AND ! in_array($value, array_values($rule[3]), TRUE))
            {
                $errors[] = $rule[1].' «'.$value.'» не распознан — допустимо '.$rule[2].'.';
            }
        }

        return $errors;
    }

    /** Имя для показа: русское, иначе латиница. */
    protected static function _name(array $row)
    {
        return trim((string) ($row['name_ru'] ?? '')) !== ''
            ? trim((string) $row['name_ru'])
            : trim((string) ($row['name_int'] ?? ''));
    }

    // ------------------------------------------------------------------ план

    /**
     * Что изменится в карточке: только заполненные в файле поля, и только те,
     * что отличаются от базы.
     *
     * @return array ['risk' => ['было', 'станет'], …] (пусто — менять нечего)
     */
    protected static function _diff(array $row, array $card)
    {
        $changed = [];

        foreach (self::LABELS as $field => $label)
        {
            if ( ! array_key_exists($field, $row) OR $row[$field] === NULL)
            {
                continue;
            }

            $have = $card[$field] ?? '';

            if (in_array($field, ['synonyms', 'avoid', 'sources'], TRUE))
            {
                $have = (array) (is_array($have) ? $have : []);
                $want = (array) $row[$field];

                if ($have === [] AND $want === [])
                {
                    continue;
                }

                if ($want === [] OR $have === $want)
                {
                    continue;
                }

                $changed[$field] = [implode(', ', $have), implode(', ', $want)];
                continue;
            }

            $want = (string) $row[$field];

            if ($want === '')
            {
                continue;
            }

            if ((string) $have === $want)
            {
                continue;
            }

            // Дополняем пробелы: если в базе пусто — это не «изменение», а
            // заполнение, но админу всё равно полезно видеть.
            $changed[$field] = [(string) $have, $want];
        }

        return $changed;
    }

    /** Слаг, которого нет ни в базе, ни среди уже добавленных строк плана. */
    protected static function _free_slug($slug, array $existing, array $create)
    {
        $taken = [];

        foreach ($create as $row)
        {
            $taken[(string) $row['slug']] = TRUE;
        }

        if ($slug !== '' AND ! isset($existing[$slug]) AND ! isset($taken[$slug]))
        {
            return $slug;
        }

        $base = $slug !== '' ? $slug : 'csv';
        $base = preg_replace('/-\d+$/', '', $base);

        for ($i = 2; $i < 100; $i++)
        {
            $candidate = $base.'-'.$i;

            if ( ! isset($existing[$candidate]) AND ! isset($taken[$candidate]))
            {
                return $candidate;
            }
        }

        return $base.'-'.substr(md5(uniqid('', TRUE)), 0, 6);
    }

    /** Слаг строки: из явного поля, иначе из E-кода, иначе из названия. */
    public static function slug_from(array $row)
    {
        $slug = self::slugify((string) ($row['slug'] ?? ''));

        if ($slug !== '')
        {
            return $slug;
        }

        $e = self::e_code((string) ($row['e_code'] ?? ''));

        if ($e !== '')
        {
            return $e;
        }

        // Латиница лучше русского (её и в ссылке читать легче), но словарь,
        // заполненный по-русски, не должен превращаться в «csv-3f9a2b…»:
        // название транслитерируется тем же словарём букв.
        foreach ([$row['name_int'] ?? '', $row['name_ru'] ?? ''] as $name)
        {
            $slug = self::slugify((string) $name);

            if ($slug !== '')
            {
                return $slug;
            }
        }

        return 'csv-'.substr(md5(self::_name($row).'|'.(string) ($row['category'] ?? '')), 0, 10);
    }

    /** E-код в нижний регистр без пробелов и кириллической «Е»: «Е 211» → «e211». */
    public static function e_code($value)
    {
        return str_replace(['е', ' '], ['e', ''], mb_strtolower(trim((string) $value), 'UTF-8'));
    }

    /**
     * Русское название → слаг латиницей. Без внешних таблиц: буква в букву.
     *
     * Кириллица в адресе `/label/ingredient/<slug>` выглядела бы сырой ссылкой
     * (её пришлось бы кодировать), поэтому словарь импорта переводится в латиницу
     * здесь же — как это делает любой «человеческий» слаг-мейкер.
     */
    public static function slugify($value)
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');

        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            'і' => 'i', 'ї' => 'i', 'є' => 'e', 'ґ' => 'g',
        ];

        $value = strtr($value, $map);

        // Остальное — только ASCII-буквы, цифры, точка, дефис и подчёркивание.
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value);
        $value = trim(preg_replace('/-{2,}/', '-', $value), '-_.');

        return mb_substr($value, 0, 120);
    }
}
