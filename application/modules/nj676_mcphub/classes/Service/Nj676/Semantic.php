<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Семантический слой каталога MCP (nj676): «задача → нужный сервер».
 *
 * Зачем. Реальный тест 24.10.2026: на запрос «отправить письмо» первым
 * выходил набор иконок — потому что в его инструментах встречалось слово
 * email. Лексический поиск честно нашёл слово и честно ошибся смыслом.
 * Векторы решают именно это: «отправить письмо» ближе к почтовому серверу,
 * чем к набору иконок с иконкой конверта.
 *
 * Где живут векторы. Платформенное правило одно: эмбеддинги хранит
 * nj115_semantic, своих таблиц с векторами модули не заводят. Поэтому
 * каталог индексируется в nj115 (коллекция nj676_mcphub, сущность server),
 * а источник для покрытия/догона описан в application/config/nj115.php.
 *
 * Как это ведёт себя без сервера эмбеддингов (EMBEDDING_API_URL не задан):
 * МОЛЧА возвращаем пустой список — поиск продолжает работать лексически.
 * Семантика — усилитель, а не условие работы каталога.
 */
class Service_Nj676_Semantic
{
    /** Коллекция векторов каталога в nj115. */
    const COLLECTION = 'nj676_mcphub';

    /** Сущность: одна карточка сервера. */
    const ENTITY = 'server';

    /**
     * Косинусная дистанция, дальше которой совпадение считается чужим.
     *
     * 0.75 было слишком щедро: при 1000+ векторах ЛЮБОЙ запрос набирал 40
     * «похожих» (замер 24.10.2026 на `zzzqqq gibberish`), и относительная
     * отсечка хвоста резала мусор только внутри мусора. Порог — не «насколько
     * близко», а «настолько ли близко, чтобы вообще говорить о совпадении».
     */
    const MAX_DISTANCE = 0.62;

    /**
     * Дистанция лучшего попадания, дальше которой семантика молчит.
     *
     * У qwen3-embedding-0.6b выраженная анизотропия конуса: случайные тексты
     * дают косинусную дистанцию ~0.27–0.36 (сходство 0.64–0.73). Поэтому
     * безусловный допуск работает только на близкой дистанции (<= 0.34,
     * т.е. косинусное сходство >= 0.66), а для пула из 10+ кандидатов
     * дополнительно проверяется отрыв лидера от 10-го результата и медианы
     * (центрирование относительно фона каталога).
     */
    const CONFIDENT_DISTANCE = 0.36;

    /**
     * Минимальный отрыв 1-го кандидата от 10-го при большом пуле (центрирование
     * против анизотропного фона qwen3-embedding-0.6b). Если 1-й и 10-й векторы
     * отличаются меньше чем на MIN_GAP_10, в выдаче нет выраженного лидера.
     */
    const MIN_GAP_10 = 0.04;

    /**
     * Насколько хуже лучшего может быть кандидат, чтобы ещё считаться темой.
     *
     * Смысловой хвост куцый по природе: у косинуса нет «середины», поэтому
     * «так же про задачу» = «не дальше лучшего на SPREAD».
     */
    const SPREAD = 0.07;

    /** @var string последняя ошибка (для админки и самопроверки) */
    protected static $last_error = '';

    /** @var array последний разбор выдачи: замеры для админки и самопроверки */
    protected static $last_stats = [];

    /**
     * Есть ли чем считать векторы прямо сейчас.
     *
     * Проверяем три вещи: задан адрес эмбеддинг-сервера, подключён модуль
     * векторов и не выключили семантику флагом NJ676_SEMANTIC=0.
     */
    public static function enabled()
    {
        if ((string) getenv('NJ676_SEMANTIC') === '0')
        {
            return FALSE;
        }

        if (trim((string) getenv('EMBEDDING_API_URL')) === '')
        {
            return FALSE;
        }

        return class_exists('Service_Nj115_Semantic');
    }

    /** Последняя ошибка вызова (пустая строка — ошибок не было). */
    public static function last_error()
    {
        return self::$last_error;
    }

    /** Порог «вообще не про это»: NJ676_SEMANTIC_MAX_DISTANCE (0.62). */
    public static function max_distance()
    {
        $value = (float) getenv('NJ676_SEMANTIC_MAX_DISTANCE');

        return ($value > 0.3 && $value <= 0.95) ? $value : self::MAX_DISTANCE;
    }

    /** Порог «по смыслу ничего нет»: NJ676_SEMANTIC_CONFIDENT (0.55). */
    public static function confident_distance()
    {
        $value = (float) getenv('NJ676_SEMANTIC_CONFIDENT');

        return ($value > 0.2 && $value <= 0.9) ? $value : self::CONFIDENT_DISTANCE;
    }

    /** Допустимое отставание от лучшего: NJ676_SEMANTIC_SPREAD (0.07). */
    public static function spread()
    {
        $value = (float) getenv('NJ676_SEMANTIC_SPREAD');

        return ($value > 0.01 && $value <= 0.3) ? $value : self::SPREAD;
    }

    /** Модель эмбеддингов — для ответа «чем считали». */
    public static function model()
    {
        $model = trim((string) getenv('EMBEDDING_MODEL'));

        return $model !== '' ? $model : 'text-embedding-qwen3-embedding-0.6b';
    }

    /**
     * Текст карточки для вектора.
     *
     * Формула обязана совпадать с SQL источника в application/config/nj115.php
     * (иначе хеш не сойдётся и покрытие будет вечно неполным):
     *   name \n description \n name_ru \n description_ru \n
     *   категории через пробел \n «имя описание» инструментов через пробел
     *
     * Русский перевод входит в текст карточки: запрос «браузер для агента»
     * должен находить Playwright MCP, а не только карточки со словом browser.
     */
    public static function document(array $server)
    {
        $categories = [];
        foreach ((array) ($server['categories'] ?? []) as $category)
        {
            if (is_scalar($category))
            {
                $categories[] = (string) $category;
            }
        }

        $tools = [];
        foreach ((array) ($server['tools'] ?? []) as $tool)
        {
            if ( ! is_array($tool))
            {
                continue;
            }

            $tools[] = trim((string) ($tool['name'] ?? '').' '.(string) ($tool['description'] ?? ''));
        }

        $doc = (string) ($server['name'] ?? '')
            ."\n".(string) ($server['description'] ?? '')
            ."\n".(string) ($server['name_ru'] ?? '')
            ."\n".(string) ($server['description_ru'] ?? '')
            ."\n".implode(' ', $categories)
            ."\n".implode(' ', $tools);

        $audit_parts = [];
        $audit_summary = trim((string) ($server['audit_summary'] ?? ''));
        if ($audit_summary !== '')
        {
            $audit_parts[] = $audit_summary;
        }
        $audit_report = $server['audit_report'] ?? [];
        if (is_string($audit_report) AND $audit_report !== '')
        {
            $decoded_audit = json_decode($audit_report, TRUE);
            $audit_report = is_array($decoded_audit) ? $decoded_audit : [];
        }
        if (is_array($audit_report))
        {
            foreach ((array) ($audit_report['use_cases'] ?? []) as $uc)
            {
                if (is_scalar($uc) AND trim((string) $uc) !== '')
                {
                    $audit_parts[] = trim((string) $uc);
                }
            }
        }
        if ($audit_parts !== [])
        {
            $doc .= "\n".implode(' ', $audit_parts);
        }

        return $doc;
    }

    /**
     * Поставить карточки на индексацию (через очередь nj120 — правило платформы:
     * длительное не считается в HTTP-запросе).
     *
     * @param  int $limit сколько карточек взять (0 — все опубликованные)
     * @return array ['queued' => N, 'skipped' => M, 'error' => '']
     */
    public static function index($limit = 300, $force = FALSE)
    {
        $out = ['queued' => 0, 'skipped' => 0, 'error' => ''];

        if ( ! self::enabled())
        {
            $out['error'] = 'эмбеддинг-сервер не настроен (EMBEDDING_API_URL)';

            return $out;
        }

        $need = (int) $limit > 0 ? max(1, min(2000, (int) $limit)) : 2000;

        try
        {
            // Раньше SQL отдавал первые N карточек по updated_at, а «уже
            // проиндексировано» отсекалось ПОСЛЕ этого. При каталоге больше N
            // кнопка каждый раз пересматривала те же верхние строки и никогда
            // не доходила до старых: они оставались без вектора навсегда.
            // Теперь идём страницами, пока не наберём N реально новых.
            $pending = [];
            $scanned = 0;
            $page = 0;
            $per = 500;

            while (count($pending) < $need AND $page < 40)
            {
                $rows = DB::query(Database::SELECT,
                    'SELECT slug, name, description, name_ru, description_ru, categories, tools, audit_summary, audit_report
                       FROM nj_676_servers
                      WHERE is_published = TRUE
                      ORDER BY updated_at DESC
                      LIMIT '.$per.' OFFSET '.($page * $per))
                    ->execute()->as_array();

                if ($rows === [])
                {
                    break;
                }

                $items = [];

                foreach ($rows as $row)
                {
                    $row = (array) $row;
                    $items[] = [
                        'id' => (string) $row['slug'],
                        'text' => self::document($row),
                        'metadata' => ['slug' => (string) $row['slug'], 'name' => (string) $row['name']],
                    ];
                }

                $scanned += count($items);

                // pending_items() сравнивает хеши текста с уже записанными
                // векторами: неизменившиеся не тратят токены эмбеддинг-сервера.
                // force — пересчитать всё заново (сменили модель эмбеддингов
                // или чинили индекс): тогда фильтр не нужен.
                $fresh = $force ? $items : Service_Nj115_Semantic::pending_items(self::COLLECTION, self::ENTITY, $items);

                foreach ($fresh as $item)
                {
                    $pending[] = $item;
                }

                $page++;
            }

            if ($pending === [])
            {
                $out['skipped'] = $scanned;

                return $out;
            }

            $pending = array_slice($pending, 0, $need);

            // Задача попадает в монитор nj120 каналом semantic.index; модуль —
            // nj676, чтобы владелец видел, чей это индекс (nj115 пускает
            // вызывающего переопределить владельца).
            $result = Service_Nj115_Semantic::queue_index(self::COLLECTION, self::ENTITY, $pending,
                ['module' => 'nj676'] + ($force ? ['force' => TRUE] : []));

            $out['queued'] = (int) ($result['queued'] ?? 0);
            $out['skipped'] = max(0, $scanned - $out['queued']);
        }
        catch (Throwable $e)
        {
            self::$last_error = $e->getMessage();
            $out['error'] = mb_substr($e->getMessage(), 0, 300, 'UTF-8');

            Log_Plain::event(Log::WARNING, 'nj676: индекс каталога не поставлен: :e', [':e' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Переиндексировать конкретные карточки (после перевода).
     *
     * Зачем отдельный вход. Текст вектора включает `name_ru` / `description_ru`
     * (см. document()), поэтому карточка, проиндексированная ДО перевода, на
     * русский запрос отвечает английским текстом: «иконки» не находит
     * `dashboard-icons`, хотя «логотипы» находит. Догон «раз в сутки» тоже
     * лечит, но с задержкой до суток; здесь карточка попадает в индекс сразу
     * после того, как воркер сохранил перевод.
     *
     * @param  array<int,string> $slugs
     * @return array{queued:int,error:string}
     */
    public static function index_slugs(array $slugs)
    {
        $out = ['queued' => 0, 'error' => ''];

        if ( ! self::enabled())
        {
            return $out;
        }

        $clean = [];
        foreach (array_slice($slugs, 0, 200) as $slug)
        {
            $slug = mb_substr(trim((string) $slug), 0, 100);

            if ($slug !== '' AND preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $slug))
            {
                $clean[] = $slug;
            }
        }

        if ($clean === [])
        {
            return $out;
        }

        try
        {
            $items = [];

            foreach (array_chunk(array_values(array_unique($clean)), 100) as $part)
            {
                $placeholders = [];

                foreach (array_values($part) as $i => $slug)
                {
                    $placeholders[] = ':s'.$i;
                }

                $params = [];
                foreach (array_values($part) as $i => $slug)
                {
                    $params[':s'.$i] = $slug;
                }

                $rows = DB::query(Database::SELECT,
                    'SELECT slug, name, description, name_ru, description_ru, categories, tools
                       FROM nj_676_servers
                      WHERE is_published = TRUE AND slug IN ('.implode(', ', $placeholders).')', $params)
                    ->execute()->as_array();

                foreach ($rows as $row)
                {
                    $row = (array) $row;
                    $items[] = [
                        'id' => (string) $row['slug'],
                        'text' => self::document($row),
                        'metadata' => ['slug' => (string) $row['slug'], 'name' => (string) $row['name']],
                    ];
                }
            }

            if ($items === [])
            {
                return $out;
            }

            // pending_items() сравнивает хеши: неизменившийся текст не тратит
            // токены эмбеддинг-сервера. Здесь это ровно то, что нужно —
            // вызывающий может передать и уже переведённые карточки.
            $fresh = Service_Nj115_Semantic::pending_items(self::COLLECTION, self::ENTITY, $items);

            if ($fresh === [])
            {
                return $out;
            }

            $result = Service_Nj115_Semantic::queue_index(self::COLLECTION, self::ENTITY, $fresh,
                ['module' => 'nj676']);

            $out['queued'] = (int) ($result['queued'] ?? 0);
        }
        catch (Throwable $e)
        {
            self::$last_error = $e->getMessage();
            $out['error'] = mb_substr($e->getMessage(), 0, 300, 'UTF-8');

            Log_Plain::event(Log::WARNING, 'nj676: переиндексация карточек не поставлена: :e',
                [':e' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Семантические кандидаты для запроса: slug => релевантность 0..1.
     *
     * Три отсечки, все — про честность, а не про красоту выдачи:
     *
     *   1. `max_distance()` — «это вообще про другое»;
     *   2. `confident_distance()` — если даже лучший вектор далёк, по смыслу
     *      ничего нет: возвращаем ПУСТО, и слово ищет лексика. Раньше без этой
     *      отсечки любой запрос возвращал ровно `$limit` серверов (24.10.2026:
     *      `zzzqqq gibberish` → 40 результатов, топ-1 `funasr`);
     *   3. `spread()` — держим только тех, кто не дальше лучшего на SPREAD.
     *      Хвост косинуса однородный, и «похоже на 0.2» — это не «про задачу».
     *
     * Ошибки не пробрасываем: если сервер эмбеддингов лёг, поиск обязан
     * продолжиться по словам, а не отдать агенту 500.
     *
     * @return array<string,float> slug => релевантность (пусто — «по смыслу нет»)
     */
    /**
     * Является ли строка набором случайных букв без смысла (gibberish).
     *
     * На мусор вида `xqzvbnmplkjhgf` или `zzzqqq` эмбеддинг-модель возвращает
     * плоское плато средних дистанций (~0.45–0.54), потому что вектор отражает
     * только шаблон инструкции. Отсекаем явный клавиатурный мусор до похода в
     * векторный индекс, не трогая короткие аббревиатуры (aws, pdf, sql, ssh)
     * и составные технические слаги.
     */
    public static function is_gibberish($query)
    {
        $query = mb_strtolower(trim((string) $query));
        if ($query === '')
        {
            return FALSE;
        }

        // Если запрос похож на составной слаг через дефис (ai-echoloc-...),
        // это технический идентификатор, а не мусор.
        if (strpos($query, '-') !== FALSE AND preg_match('/^[a-z0-9]+(-[a-z0-9]+)+$/', $query))
        {
            return FALSE;
        }

        $tokens = preg_split('/[^a-zа-яё0-9]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === [])
        {
            return TRUE;
        }

        $known_nonsense = [
            'gibberish', 'abracadabra', 'абракадабра', 'бессмыслица', 'тарабарщина',
            'абырвалг', 'qwerty', 'qwertyuiop', 'asdfgh', 'asdfghjkl', 'zxcvbn',
            'zxcvbnm', 'йцукен', 'йцукенг', 'фывапр', 'фывапролдж', 'ячсмит',
            'ячсмить', 'foobar', 'blahblah', 'nonsense',
        ];

        $kbd_rows = [
            'qwertyuiopasdfghjklzxcvbnm',
            strrev('qwertyuiopasdfghjklzxcvbnm'),
            'йцукенгшщзхъфывапролджэячсмитьбю',
            'юбьтимсчяэждлорпавыфъхзщшгнекуцй',
        ];

        $gibberish_count = 0;

        foreach ($tokens as $token)
        {
            $len = mb_strlen($token);

            if (in_array($token, $known_nonsense, TRUE))
            {
                $gibberish_count++;
                continue;
            }

            // Проход по клавиатурному ряду (qwertyuiopasdfgh, фывапролдж и т.п.):
            // подстрока из 5+ соседних клавиш подряд не встречается в реальных словах.
            if ($len >= 5)
            {
                $is_kbd = FALSE;
                for ($i = 0; $i <= $len - 5; $i++)
                {
                    $sub = mb_substr($token, $i, 5);
                    foreach ($kbd_rows as $row)
                    {
                        if (mb_strpos($row, $sub) !== FALSE)
                        {
                            $is_kbd = TRUE;
                            break 2;
                        }
                    }
                }
                if ($is_kbd)
                {
                    $gibberish_count++;
                    continue;
                }
            }

            // Короткие токены или токены с цифрами могут быть аббревиатурой (pdf, s3, oauth2).
            if ($len <= 5 OR preg_match('/\d/', $token))
            {
                return FALSE;
            }

            $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $unique = count(array_unique($chars));

            // Повтор одного-двух символов или трёх символов при длине >= 9 (zzzqqq, абракадабра).
            if (($len >= 6 AND $unique <= 2) OR ($len >= 9 AND $unique <= 4 AND ($unique / $len) < 0.4))
            {
                $gibberish_count++;
                continue;
            }

            // Длинное слово вообще без гласных (xqzvbnmplkjhgf).
            $has_vowel = (bool) preg_match('/[aeiouyаеёиоуыэюя]/u', $token);
            if ($len >= 6 AND ! $has_vowel)
            {
                $gibberish_count++;
                continue;
            }

            // 5 и более согласных подряд в длинном токене без дефисов.
            if ($len >= 8 AND preg_match('/[bcdfghjklmnpqrstvwxzбвгджзйклмнпрстфхцчшщ]{5,}/u', $token))
            {
                $gibberish_count++;
                continue;
            }
        }

        return $gibberish_count === count($tokens);
    }

    public static function hits($query, $limit = 40)
    {
        $query = trim((string) $query);

        self::$last_stats = [];

        if ($query === '' OR ! self::enabled())
        {
            return [];
        }

        if (self::is_gibberish($query))
        {
            self::$last_stats = [
                'best_distance' => NULL,
                'candidates' => 0,
                'kept' => 0,
                'verdict' => 'запрос не похож на осмысленные слова, переформулируйте запрос',
            ];

            return [];
        }

        $max_distance = self::max_distance();

        try
        {
            $rows = Service_Nj115_Semantic::search(
                self::COLLECTION, self::query_text($query), max(1, min(100, (int) $limit)), self::ENTITY,
                ['hybrid' => FALSE, 'max_distance' => $max_distance]
            );
        }
        catch (Throwable $e)
        {
            self::$last_error = $e->getMessage();

            Log_Plain::event(Log::DEBUG, 'nj676: семантический поиск недоступен: :e', [':e' => $e->getMessage()]);

            return [];
        }

        // 1. Отбираем только векторы и только в пределах порога.
        $candidates = [];

        foreach ((array) $rows as $row)
        {
            $row = (array) $row;

            if ((string) ($row['match'] ?? 'vector') !== 'vector')
            {
                continue;
            }

            $slug = (string) ($row['entity_id'] ?? '');
            $distance = $row['distance'] !== NULL ? (float) $row['distance'] : 1.0;

            if ($slug === '' OR $distance > $max_distance)
            {
                continue;
            }

            $candidates[$slug] = $distance;
        }

        $selected = self::select($candidates);
        self::$last_stats = $selected['stats'];

        return $selected['hits'];
    }

    /**
     * Отсечки «по смыслу» — чистая функция: slug => дистанция → slug => вес.
     *
     * Отдельно от `hits()` нарочно: правила (порог, уверенность, хвост) — это
     * то, что надо проверять стендом без эмбеддинг-сервера и базы. Каждый
     * вызов возвращает и вердикт: «почему пусто» должно быть видно снаружи.
     *
     * @param  array<string,float> $distances slug => косинусная дистанция
     * @return array{hits:array<string,float>,stats:array<string,mixed>}
     */
    public static function select(array $distances)
    {
        $max_distance = self::max_distance();

        $candidates = [];

        foreach ($distances as $slug => $distance)
        {
            $slug = (string) $slug;
            $distance = (float) $distance;

            if ($slug === '' OR $distance > $max_distance)
            {
                continue;
            }

            $candidates[$slug] = $distance;
        }

        if ($candidates === [])
        {
            return ['hits' => [], 'stats' => [
                'best_distance' => NULL, 'candidates' => 0, 'kept' => 0,
                'verdict' => 'нет попаданий ближе '.$max_distance,
            ]];
        }

        asort($candidates);

        $best = (float) reset($candidates);
        $confident = self::confident_distance();

        // Уверенность: самый близкий вектор всё равно далёк — «такого нет».
        // Это и есть ответ на «любой запрос возвращал 40 серверов».
        if ($best > $confident)
        {
            return ['hits' => [], 'stats' => [
                'best_distance' => round($best, 4), 'candidates' => count($candidates),
                'kept' => 0,
                'verdict' => 'по смыслу ничего близкого: лучший '.round($best, 3)
                    .' дальше порога уверенности '.$confident.', переформулируйте запрос',
            ]];
        }

        $vals = array_values($candidates);
        $count_all = count($vals);
        $d10 = $count_all >= 10 ? (float) $vals[9] : NULL;
        $median = $count_all >= 10 ? (float) $vals[(int) floor($count_all / 2)] : NULL;
        $gap10 = $d10 !== NULL ? round($d10 - $best, 4) : NULL;
        $gap_median = $median !== NULL ? round($median - $best, 4) : NULL;

        // Центрирование относительно фона запроса (борьба с анизотропией
        // qwen3-embedding-0.6b): если в пуле >= 10 векторов, 10-й и медианный
        // кандидаты показывают фоновую дистанцию каталога к этому тексту.
        // На бессмыслице («zzzqqq gibberish», «qwertyuiopasdfgh», «фывапролдж»,
        // «абракадабра») все 26–40 кандидатов лежат плотным конусом (gap10 < 0.04,
        // gap_median < 0.055), тогда как у реальных тем («иконки» → 6,
        // «read pdf» → 7, «отправить письмо» → 6) лидер заметно отрывается от
        // 10-го кандидата.
        if ($count_all >= 10 AND $best > 0.20 AND ($gap10 < self::MIN_GAP_10 OR $gap_median < 0.055))
        {
            return ['hits' => [], 'stats' => [
                'best_distance' => round($best, 4),
                'd10_distance' => round((float) $d10, 4),
                'gap10' => $gap10,
                'gap_median' => $gap_median,
                'candidates' => $count_all,
                'kept' => 0,
                'verdict' => 'анизотропный шум без выраженного лидера (зазор 1..10 = '.$gap10.'), переформулируйте запрос',
            ]];
        }

        $ceiling = min($max_distance, $best + self::spread());
        $hits = [];
        $kept_distances = [];

        foreach ($candidates as $slug => $distance)
        {
            if ($distance > $ceiling)
            {
                continue;
            }

            $kept_distances[] = (float) $distance;
            $rel = self::relevance($distance);

            // Если это большой пул и отрыв от медианы умеренный (серая зона),
            // центрируем вес относительно медианы пула, чтобы далёкие кандидаты
            // не получали искусственный score 0.9.
            if ($median !== NULL AND $distance > 0.24)
            {
                $centered_gain = max(0.0, $median - (float) $distance);
                $centered_rel = min(1.0, max(0.2, $centered_gain / 0.14));
                $rel = round(min($rel, ($rel * 0.5) + ($centered_rel * 0.5)), 3);
            }

            $hits[$slug] = $rel;
        }

        // Если в узкий коридор попало сразу 12+ карточек (размытый конус без
        // конкретной темы), это не точный ответ: отсекаем шумовое плато.
        if (count($kept_distances) >= 12 OR (count($kept_distances) >= 8 AND $best > 0.26 AND ($kept_distances[7] - $best) < 0.03))
        {
            return ['hits' => [], 'stats' => [
                'best_distance' => round($best, 4),
                'ceiling' => round($ceiling, 4),
                'gap10' => $gap10,
                'candidates' => $count_all,
                'kept' => 0,
                'verdict' => 'все кандидаты на одном среднем расстоянии (шум без выраженного лидера), переформулируйте запрос',
            ]];
        }

        arsort($hits);

        $grey_zone = (($best > 0.26) OR ($gap10 !== NULL AND $gap10 < 0.06));

        return ['hits' => $hits, 'stats' => [
            'best_distance' => round($best, 4),
            'ceiling' => round($ceiling, 4),
            'gap10' => $gap10,
            'grey_zone' => $grey_zone,
            'candidates' => $count_all,
            'kept' => count($hits),
            'verdict' => $grey_zone ? 'серая зона (приблизительно)' : 'ок',
        ]];
    }

    /**
     * Текст запроса для эмбеддинг-сервера.
     *
     * Qwen3-Embedding (и родственные instruct-модели) различают «документ» и
     * «запрос»: без инструкции кросс-языковое выравнивание слабее, и русское
     * «иконки» не находит английское `dashboard-icons`, хотя «логотипы» находит.
     * Дополнительно для русскоязычных запросов подмешиваем английские понятия
     * из словаря каталога, чтобы находить даже ещё не переведённые карточки.
     */
    public static function query_text($query)
    {
        $query = trim((string) $query);
        $expanded = $query;

        if ($query !== '' AND preg_match('/[а-яё]/iu', $query) AND class_exists('Service_Nj676_McpHub'))
        {
            $en_terms = [];
            foreach (Service_Nj676_McpHub::terms($query) as $term)
            {
                if (preg_match('/^[a-z0-9_-]+$/i', (string) $term)
                    AND mb_stripos($query, (string) $term) === FALSE)
                {
                    $en_terms[] = (string) $term;
                }
            }

            if ($en_terms !== [])
            {
                $expanded .= ' ('.implode(', ', array_slice(array_values(array_unique($en_terms)), 0, 5)).')';
            }
        }

        $instruct = trim((string) getenv('NJ676_SEMANTIC_INSTRUCT'));

        if ($instruct === '')
        {
            return $expanded;
        }

        return 'Instruct: '.$instruct."\nQuery: ".$expanded;
    }

    /** Последний разбор выдачи — для админки и самопроверки («почему пусто»). */
    public static function last_stats()
    {
        return self::$last_stats;
    }

    /**
     * Косинусная дистанция → релевантность 0..1.
     *
     * Шкала привязана к реальному порогу, а не к константе: поменяв
     * `NJ676_SEMANTIC_MAX_DISTANCE`, владелец меняет и растяжку. Ближе 0.35 —
     * «это оно», на пороге — «на грани», дальше — ноль (такие уже отсечены).
     */
    public static function relevance($distance)
    {
        $distance = max(0.0, (float) $distance);
        $max = self::max_distance();

        if ($distance > $max)
        {
            return 0.0;
        }

        $near = min(0.22, $max - 0.05);

        if ($distance <= $near)
        {
            return 1.0;
        }

        $span = max(0.01, $max - $near);

        return max(0.2, 1 - (($distance - $near) / $span) * 0.8);
    }

    /** Состояние семантики — для админки и самопроверки шлюза. */
    public static function status()
    {
        $enabled = self::enabled();
        $indexed = NULL;
        $cards = NULL;

        // Сколько карточек в каталоге: без этого «векторов 1000» ничего не
        // говорит — много это или мало.
        try
        {
            $row = DB::query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_676_servers WHERE is_published = TRUE')
                ->execute()->current();

            $cards = (int) ($row['c'] ?? 0);
        }
        catch (Throwable $e)
        {
            $cards = NULL;
        }

        if ($enabled)
        {
            try
            {
                $row = DB::query(Database::SELECT,
                    'SELECT count(*) AS c FROM nj_115_vectors v JOIN nj_115_collections c ON c.id = v.collection_id
                      WHERE c.name = :name')
                    ->param(':name', self::COLLECTION)
                    ->execute()->current();

                $indexed = (int) ($row['c'] ?? 0);
            }
            catch (Throwable $e)
            {
                $indexed = NULL;
            }
        }

        // «Осталось» — по количеству: векторы переживают удаление карточек,
        // поэтому отрицательное значение не показываем.
        $pending = ($cards !== NULL AND $indexed !== NULL) ? max(0, $cards - $indexed) : NULL;

        return [
            'enabled' => $enabled,
            'model' => self::model(),
            // Пороги видны снаружи: «почему пусто» — вопрос, на который должен
            // отвечать ответ шлюза, а не догадки владельца.
            'thresholds' => [
                'max_distance' => self::max_distance(),
                'confident_distance' => self::confident_distance(),
                'spread' => self::spread(),
                'instruct' => trim((string) getenv('NJ676_SEMANTIC_INSTRUCT')) !== '',
            ],
            'collection' => self::COLLECTION,
            'indexed' => $indexed,
            'cards' => $cards,
            'pending' => $pending,
            'unindexed_servers' => $pending,
            'error' => self::$last_error,
            'hint' => $enabled
                ? 'Семантика включена: поиск объединяет слова и смысл.'
                : 'Семантика выключена (нет EMBEDDING_API_URL или nj115) — поиск работает по словам.',
        ];
    }
}
