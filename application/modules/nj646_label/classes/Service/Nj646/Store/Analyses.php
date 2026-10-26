<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — часть `Analyses`: разборы: сохранение, история, «мои разборы», рабочие имена товаров.
 *
 * Трейт класса `Service_Nj646_Store` (см. его шапку): разделы вынесены из одного
 * файла, публичные имена методов не менялись. Свойства и общие помощники
 * (`self::db()`, `self::_all()`, `self::ensure_*()`…) живут в самом классе.
 */
trait Service_Nj646_Store_Analyses
{

    public static function analysis_save(array $data)
    {
        self::ensure_tables();

        $db = self::db();

        // Предел на запись, а не только на форму: в `raw_text` едет ещё и
        // транскрипт модели, и при поднятом `text_max` страница иначе получила
        // бы «простыню» на мегабайт. Обрезка честная — она помечается в
        // `about.cut`, и страница разбора говорит об этом вслух.
        $raw_text = self::_text_cut(
            (string) ($data['raw_text'] ?? ''),
            self::limit('raw_text_kb', 64) * 1024,
            $raw_cut
        );

        $about = (array) ($data['about'] ?? []);

        if ($raw_cut)
        {
            $about['cut']['raw_text'] = [
                'kept' => strlen($raw_text),
                'was'  => strlen((string) ($data['raw_text'] ?? '')),
            ];

            Kohana::$log->add(Log::INFO, 'nj646: текст разбора обрезан на записи — сохранено :kept Б из :was Б', [
                ':kept' => strlen($raw_text),
                ':was'  => strlen((string) ($data['raw_text'] ?? '')),
            ]);
        }

        $sql = 'INSERT INTO nj_646_analyses
                    (user_id, visitor, kind, product_kind, title_auto, source, title, raw_text, score, verdict, counts, items, unknown, profile, about)
                VALUES (
                    '.(($data['user_id'] ?? NULL) !== NULL ? (int) $data['user_id'] : 'NULL').',
                    '.$db->quote((string) ($data['visitor'] ?? '')).',
                    '.$db->quote((string) ($data['kind'] ?? 'auto')).',
                    '.$db->quote(mb_substr((string) ($data['product_kind'] ?? ''), 0, 24)).',
                    '.($data['title_auto'] ?? FALSE ? 'TRUE' : 'FALSE').',
                    '.$db->quote((string) ($data['source'] ?? 'text')).',
                    '.$db->quote(mb_substr((string) ($data['title'] ?? ''), 0, 200)).',
                    '.$db->quote($raw_text).',
                    '.(int) ($data['score'] ?? 0).',
                    '.$db->quote((string) ($data['verdict'] ?? 'neutral')).',
                    '.self::_json($data['counts'] ?? []).',
                    '.self::_json(array_slice((array) ($data['items'] ?? []), 0, 200)).',
                    '.self::_json(array_slice((array) ($data['unknown'] ?? []), 0, 100)).',
                    '.self::_json($data['profile'] ?? []).',
                    '.self::_json($about).')
                RETURNING id';

        $row = self::db()->query(Database::SELECT, $sql, FALSE)->current();
        $id  = $row ? (int) $row['id'] : 0;

        // Товар и производитель привязываются к разбору сразу: страница разбора
        // показывает именно актуальные карточки, а снимок `about` остаётся в
        // колонке — по нему видно, что было на этикетке в момент разбора.
        if ($id > 0 AND ((int) ($data['product_id'] ?? 0) > 0 OR (int) ($data['producer_id'] ?? 0) > 0))
        {
            DB::update('nj_646_analyses')
                ->set([
                    'product_id'  => (int) ($data['product_id'] ?? 0) > 0
                        ? (int) $data['product_id'] : DB::expr('NULL'),
                    'producer_id' => (int) ($data['producer_id'] ?? 0) > 0
                        ? (int) $data['producer_id'] : DB::expr('NULL'),
                ])
                ->where('id', '=', $id)->execute();
        }

        return $id;
    }

    /**
     * Последние разборы посетителя — «Мои разборы» на странице /label.
     *
     * Отдаём и `raw_text`: посетитель должен узнавать свой разбор по составу,
     * а не по оценке, и иметь возможность разобрать его заново одним нажатием.
     */
    public static function analyses_mine($visitor, $limit = 12, $offset = 0)
    {
        self::ensure_tables();

        return self::_all(self::_list_sql().'
                           WHERE a.visitor = '.self::db()->quote((string) $visitor).'
                           ORDER BY a.id DESC LIMIT '.(int) max(1, min(50, $limit))
            .' OFFSET '.(int) max(0, $offset));
    }

    /** Сколько разборов сделал этот посетитель (для «показано N из M»). */
    public static function analyses_mine_count($visitor)
    {
        self::ensure_tables();

        return (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_analyses
                                  WHERE visitor = '.self::db()->quote((string) $visitor), 'c');
    }

    /**
     * Название разбора для списка: «Утренний кефир» вместо простыни состава.
     *
     * Владелец (03.10.2026): «итемы желательно чтобы имели название продукта (в
     * этикетке оно обычно бывает; если нет названия — то LLM пускай даёт рабочее
     * название с префиксом Неопределено_)».
     *
     * Порядок: название, которое прислал клиент → разбор строк этикетки без
     * модели (About::product_title) → рабочее имя «<Тип продукта> без названия».
     * Пустым название не бывает НИКОГДА: строка без подписи в списке бесполезна,
     * а рабочее имя честно говорит, что придумано модулем (см. title_improve —
     * модель заменит его на осмысленное, если сможет).
     */
    /**
     * Название товара для списков: с этикетки (без модели) или РАБОЧЕЕ.
     *
     * Владелец (03.10.2026): «товар определяй лучше… если названия логичного нет,
     * то придумай рабочее название (но должен быть атрибут и в ui префикс, чтобы
     * было видно что название придумано)». Поэтому возвращаем пару:
     *   title — что показывать;
     *   auto  — TRUE, если имя придумал модуль (прежний «Неопределено_<дата>»,
     *           теперь «<Тип продукта> без названия»: человеку это полезнее —
     *           видно, ЧТО за товар).
     *
     * Признак едет в БД (`nj_646_analyses.title_auto`, `nj_646_products.name_auto`)
     * и в интерфейс: списки рисуют пометку «имя рабочее». Модель по-прежнему
     * может дать настоящее имя отдельной задачей — тогда пометка снимается.
     *
     * @return array ['title' => string, 'auto' => bool]
     */
    public static function title_auto_info($text, $given = '', $kind_title = '')
    {
        $given = trim((string) $given);

        if ($given !== '')
        {
            return ['title' => mb_substr($given, 0, 200), 'auto' => FALSE];
        }

        $found = class_exists('Service_Nj646_About')
            ? Service_Nj646_About::product_title((string) $text) : '';

        if ($found !== '')
        {
            return ['title' => mb_substr($found, 0, 200), 'auto' => FALSE];
        }

        // Тип продукта модуль определяет сам (Label::product_kind) — по нему и
        // строим рабочее имя: «Мыло без названия» понятнее, чем «Неопределено».
        if ($kind_title === '' AND class_exists('Service_Nj646_Label'))
        {
            $kind_title = (string) Service_Nj646_Label::product_kind((string) $text)['title'];
        }

        $kind_title = trim((string) $kind_title);

        return [
            'title' => ($kind_title !== '' ? $kind_title.' без названия' : 'Товар без названия'),
            'auto'  => TRUE,
        ];
    }

    /** Название рабочее (поставлено нами, а не найдено на этикетке)? */
    /**
     * Поставить название, которое придумала модель (задача режима `title`).
     *
     * Заменяем ТОЛЬКО рабочее имя: если человек успел переименовать разбор
     * сам или название нашлось на этикетке — ответ модели уже не нужен.
     */
    public static function analysis_title_set($id, $title)
    {
        self::ensure_tables();

        $title = trim(preg_replace('/\s+/u', ' ', (string) $title));
        $id    = (int) $id;

        if ($id <= 0 OR $title === '')
        {
            return FALSE;
        }

        // Меняем только РАБОЧЕЕ имя (признак — колонка title_auto): своё название
        // человека и найденное на этикетке не трогаем.
        $changed = DB::update('nj_646_analyses')
            ->set(['title' => mb_substr($title, 0, 200), 'title_auto' => DB::expr('FALSE')])
            ->where('id', '=', $id)
            ->where('title_auto', '=', DB::expr('TRUE'))
            ->execute();

        // То же название — на карточку товара, если там до сих пор рабочее
        // «<Тип> без названия». Без этого следующий разбор той же этикетки
        // считал ключ от настоящего имени, не находил товар и заводил дубль.
        if ($changed)
        {
            $row = self::_one('SELECT product_id FROM nj_646_analyses WHERE id = '.$id);

            if ((int) ($row['product_id'] ?? 0) > 0)
            {
                self::product_name_from_model((int) $row['product_id'], $title);
            }
        }

        return TRUE;
    }

    /**
     * Попросить модель дать рабочее название товара по строкам этикетки.
     *
     * Отдельная задача в ТОЙ ЖЕ очереди `nj646.label` (канал уже подписан
     * воркером, новых настроек разворачивать не нужно): payload.mode = title,
     * воркер отвечает `{title}` на /label/api/worker_result. Ход виден в
     * /nj120/monitor и в логах воркера, как у любой другой задачи.
     *
     * Вызывается ТОЛЬКО когда название не нашлось без модели: лишний запрос к
     * LLM на каждый разбор не нужен, а рабочее имя «<Тип> без названия» уже
     * стоит в базе и никого не подведёт, если модель промолчит.
     *
     * @return int id прогона названия (0 — не поставили)
     */
    public static function title_improve($analysis_id, $text, array $opts = [])
    {
        $analysis_id = (int) $analysis_id;
        $text        = trim((string) $text);

        if ($analysis_id <= 0 OR mb_strlen($text) < 20 OR ! self::llm_ready())
        {
            return 0;
        }

        $run_id = self::run_create([
            'user_id'   => $opts['user_id'] ?? NULL,
            'visitor'   => (string) ($opts['visitor'] ?? ''),
            'mode'      => 'title',
            'client_ip' => (string) ($opts['client_ip'] ?? ''),
        ]);

        if ( ! $run_id)
        {
            return 0;
        }

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj646.label', [
                'run_id'      => $run_id,
                'mode'        => 'title',
                'analysis_id' => $analysis_id,
                'text'        => mb_substr($text, 0, 4000),
            ], [
                'module'       => 'nj646_label',
                // Название — вещь полезная, но не срочная: не даём задаче
                // держать очередь и не пытаемся второй раз.
                'timeout_sec'  => 120,
                'max_attempts' => 1,
            ]);

            self::run_set_job($run_id, $job_id);
        }
        catch (Throwable $e)
        {
            self::run_apply($run_id, ['status' => 'error',
                'error' => 'Очередь недоступна: '.$e->getMessage()]);

            return 0;
        }

        return $run_id;
    }

    /**
     * Страница истории разборов для админа: последние N записей, свежие сверху.
     *
     * Владелец (03.10.2026): «добавь страницу для админа с перечнем что
     * анализировали (последние n записей)». Раньше в админке были только
     * счётчики: что именно разбирали люди — не видно. Отдаём сырой текст
     * (по нему видно, что человек прислал), найденные незнакомые вещества и
     * вердикт — без этого список бесполезен.
     */
    public static function analyses_page($limit = 50, $offset = 0)
    {
        self::ensure_tables();

        return self::_all(self::_list_sql(['a.user_id', 'a.visitor', 'a.items']).'
                           ORDER BY a.id DESC LIMIT '.(int) max(1, min(200, $limit))
            .' OFFSET '.(int) max(0, $offset));
    }

    /**
     * Один разбор по id — страница разбора (`/label/analysis/<id>`).
     *
     * Отдаём строку целиком и раскодируем jsonb: страница показывает и текст,
     * который человек прислал, и снимок разбора, и профиль, по которому он
     * считался. Доступ проверяет контроллер: свой разбор видит посетитель,
     * любой — админ.
     */
    public static function analysis_get($id)
    {
        self::ensure_tables();

        $row = self::_one('SELECT * FROM nj_646_analyses WHERE id = '.(int) $id);

        if ( ! $row)
        {
            return NULL;
        }

        foreach (['counts', 'items', 'unknown', 'profile', 'about'] as $field)
        {
            $row[$field] = self::_json_decode($row[$field] ?? []);
        }

        // Товар и производитель — актуальные карточки (их могли поправить
        // после разбора), снимок `about` остаётся подстраховкой.
        $row['product']  = (int) ($row['product_id'] ?? 0) > 0 ? self::product_get((int) $row['product_id']) : NULL;
        $row['producer'] = (int) ($row['producer_id'] ?? 0) > 0 ? self::producer_get((int) $row['producer_id']) : NULL;

        return $row;
    }

    /**
     * Сколько разборов у этого посетителя — и его ли это разбор.
     *
     * Метка посетителя анонимна (uid браузера), у вошедшего есть user_id:
     * считаем своим, если совпало ИЛИ то, ИЛИ другое. Админа пускает контроллер
     * отдельно — ему разборы нужны для модерации.
     */
    public static function analysis_owned(array $row, $visitor, $user_id = NULL)
    {
        $row_visitor = (string) ($row['visitor'] ?? '');
        $row_user    = (int) ($row['user_id'] ?? 0);

        if ($visitor !== '' AND $row_visitor !== '' AND $row_visitor === (string) $visitor)
        {
            return TRUE;
        }

        return ((int) $user_id > 0 AND $row_user > 0 AND $row_user === (int) $user_id);
    }
}
