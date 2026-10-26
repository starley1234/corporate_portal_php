<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-653 «Лаборатория векторов» — работа с таблицами модуля.
 *
 * Два хранилища:
 *   nj_653_runs  — прогоны (общая схема сервисов на классе `Vision`, поэтому
 *                  дожим задачи и живой статус-ряд достаются бесплатно);
 *   nj_653_items — коллекция: подпись, текст, превью и ВЕКТОР.
 *
 * Поиск по векторам делает PostgreSQL: `<=>` из pgvector, а если расширения
 * нет — функция `nj_653_vec_dist` из миграции 001. Оба режима дают одно и то
 * же: косинусную ДИСТАНЦИЮ (0 — совпадение), и сравнивать их между собой
 * можно свободно.
 *
 * Коллекция ЛИЧНАЯ: элемент принадлежит посетителю (`visitor` — обезличенная
 * метка браузера, см. `Visitor::uid()`), и поиск идёт только по своим
 * элементам. На странице лежат чужие картинки, и показывать их соседу нельзя.
 */
class Service_Nj653_Store
{
    /** Имя таблицы прогонов (его же принимают методы Vision). */
    const RUNS = 'nj_653_runs';

    /** Имя таблицы коллекции. */
    const ITEMS = 'nj_653_items';

    /** Каталог кадров модуля (Vision::photo_dir). */
    const MODULE = 'nj653_veclab';

    /** @var bool|NULL кэш «доступен ли pgvector» */
    protected static $_pgvector;

    // ------------------------------------------------------------- прогоны

    /**
     * Создать прогон и запомнить, ЧТО именно считаем.
     *
     * `pending` — описание входов глазами сайта: подпись, текст, превью.
     * Воркеру они не нужны (он считает вектор по тексту и кадру), а сайту
     * нужны потом: именно из них собирается элемент коллекции или карточка
     * сравнения. Храним их в result прогона, чтобы не заводить третью
     * таблицу ради двух строк.
     *
     * @return int id прогона или 0
     */
    public static function run_create($mode, array $pending, array $data = [])
    {
        $db   = self::db();
        $mode = isset(Service_Nj653_Veclab::MODES[(string) $mode]) ? (string) $mode : 'probe';

        $result = json_encode([
            'mode'    => $mode,
            'pending' => array_values($pending),
        ], JSON_UNESCAPED_UNICODE);

        $sql = 'INSERT INTO '.self::RUNS.' (user_id, visitor, client_ip, title, mode, status, result)
                VALUES (
                    '.((isset($data['user_id']) AND $data['user_id'] !== NULL) ? (int) $data['user_id'] : 'NULL').',
                    '.$db->quote((string) ($data['visitor'] ?? '')).',
                    '.$db->quote((string) ($data['client_ip'] ?? '')).',
                    '.$db->quote(mb_substr((string) ($data['title'] ?? ''), 0, 200)).',
                    '.$db->quote($mode).",
                    'queued',
                    ".$db->quote((string) $result).'::jsonb) RETURNING id';

        $row = $db->query(Database::SELECT, $sql, FALSE)->current();

        return $row ? (int) $row['id'] : 0;
    }

    /** Входы прогона, сохранённые при создании (см. run_create). */
    public static function pending_of(array $run)
    {
        $result = Vision::json_decode($run['result'] ?? NULL);

        return array_values((array) ($result['pending'] ?? []));
    }

    /** Последние прогоны владельца аккаунта и/или того же браузера. */
    public static function runs_mine($visitor, $limit = 8, $user_id = NULL)
    {
        $visitor = (string) $visitor;
        $user_id = (int) $user_id;
        $where   = [];

        // Список истории совпадает с Vision::run_owned(): аккаунтная запись
        // никогда не раскрывается только из-за общей visitor-cookie.
        if ($visitor !== '')
        {
            $where[] = '(visitor = '.self::db()->quote($visitor).' AND COALESCE(user_id, 0) = 0)';
        }

        if ($user_id > 0)
        {
            $where[] = 'user_id = '.$user_id;
        }

        if ( ! $where OR ! self::table_ready(self::RUNS))
        {
            return [];
        }

        $rows = self::db()
            ->query(Database::SELECT,
                'SELECT id, mode, status, title, error, elapsed_ms, created_at
                 FROM '.self::RUNS.'
                 WHERE '.implode(' OR ', $where).'
                 ORDER BY id DESC LIMIT '.(int) max(1, min(30, $limit)), FALSE)
            ->as_array();

        return array_map(function ($row) { return (array) $row; }, $rows);
    }

    /**
     * Последняя удачная проверка модели — чья угодно.
     *
     * Паспорт модели (формат запроса, размерность, скорость) одинаков для
     * всех посетителей: это свойство сервера, а не человека. Поэтому
     * страница показывает последний известный результат сразу, не заставляя
     * каждого первым делом жать «Проверить модель».
     *
     * @return array содержимое result или []
     */
    public static function probe_last()
    {
        if ( ! self::table_ready(self::RUNS))
        {
            return [];
        }

        $row = self::db()
            ->query(Database::SELECT,
                "SELECT result, finished_at FROM ".self::RUNS."
                  WHERE mode = 'probe' AND status = 'done'
                  ORDER BY id DESC LIMIT 1", FALSE)
            ->current();

        if ( ! $row)
        {
            return [];
        }

        $result = (array) Vision::json_decode($row['result'] ?? NULL);

        if ( ! $result)
        {
            return [];
        }

        $result['finished_at'] = (string) ($row['finished_at'] ?? '');

        return $result;
    }

    // ------------------------------------------------------------ коллекция

    /**
     * Положить элемент коллекции. Возвращает id или 0.
     *
     * Вектор уже посчитан воркером и нормирован: здесь только проверка
     * размерности (чужая размерность в общей таблице = мусор в поиске,
     * который потом долго искать) и запись.
     */
    public static function item_add(array $item, array $vector)
    {
        if ( ! $vector)
        {
            return 0;
        }

        $dims = self::dims();

        if ($dims > 0 AND count($vector) !== $dims)
        {
            throw new Kohana_Exception(
                'Вектор на :got чисел не подходит коллекции (:want). '
                .'Проверьте EMBEDDING_DIMENSIONS и миграцию nj653.',
                [':got' => count($vector), ':want' => $dims]);
        }

        $db   = self::db();
        $cast = self::has_pgvector() ? '::vector' : '::real[]';
        $kind = isset(Service_Nj653_Veclab::KINDS[(string) ($item['kind'] ?? '')])
            ? (string) $item['kind'] : 'text';

        $meta = $item['meta'] ?? [];
        $meta = is_array($meta) ? $meta : [];

        // ON CONFLICT DO NOTHING вместо проверки «а нет ли уже такого»:
        // между проверкой и вставкой успевает вклиниться вторая вкладка, а
        // уникальный индекс из миграции 002 не ошибается никогда. Пустой
        // ответ = такой элемент у посетителя уже есть.
        $row = $db->query(Database::SELECT,
            'INSERT INTO '.self::ITEMS.'
                (user_id, visitor, run_id, label, kind, content, preview, model, dims,
                 fingerprint, meta, embedding)
             VALUES (
                '.((isset($item['user_id']) AND $item['user_id'] !== NULL) ? (int) $item['user_id'] : 'NULL').',
                '.$db->quote((string) ($item['visitor'] ?? '')).',
                '.((int) ($item['run_id'] ?? 0) > 0 ? (int) $item['run_id'] : 'NULL').',
                '.$db->quote(mb_substr((string) ($item['label'] ?? ''), 0, 200)).',
                '.$db->quote($kind).',
                '.$db->quote(mb_substr((string) ($item['content'] ?? ''), 0, 4000)).',
                '.$db->quote((string) ($item['preview'] ?? '')).',
                '.$db->quote(mb_substr((string) ($item['model'] ?? ''), 0, 160)).',
                '.(int) count($vector).',
                '.$db->quote(mb_substr((string) ($item['fingerprint'] ?? ''), 0, 64)).',
                '.$db->quote((string) json_encode($meta, JSON_UNESCAPED_UNICODE)).'::jsonb,
                '.$db->quote(self::vector_literal($vector)).$cast.'
             )
             ON CONFLICT (visitor, fingerprint) WHERE fingerprint <> \'\'
             DO NOTHING
             RETURNING id', FALSE)->current();

        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Отпечаток содержимого: по нему и ловятся дубли.
     *
     * Картинка — sha256 самих байтов (браузер жмёт один и тот же файл
     * одинаково, поэтому повторная загрузка даёт тот же хеш). Текст —
     * sha256 от нормализованной записи: регистр и лишние пробелы не делают
     * текст другим. У элемента «текст и картинка» в отпечаток идёт и то и
     * другое: подпись меняет смысл вектора.
     */
    public static function fingerprint($text, $bytes = '')
    {
        $text  = trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $text)));
        $bytes = (string) $bytes;

        if ($text === '' AND $bytes === '')
        {
            return '';
        }

        return hash('sha256', $text.'|'.($bytes === '' ? '' : hash('sha256', $bytes)));
    }

    /**
     * Паспорт исходной картинки: что это был за файл.
     *
     * Превью 96 px — всё, что остаётся от кадра (сам он удаляется после
     * подсчёта вектора), и по нему не ответишь на простой вопрос «а что
     * это было». Поэтому сохраняем имя, тип, вес и размеры — серверные
     * берём из самих байтов, клиентские (имя файла, дата съёмки) с
     * браузера, проверяя и обрезая.
     */
    public static function image_meta($bytes, array $client = [])
    {
        $bytes = (string) $bytes;

        if ($bytes === '')
        {
            return [];
        }

        $out = [
            'mime'  => (string) Vision::mime($bytes),
            'bytes' => strlen($bytes),
        ];

        $size = @getimagesizefromstring($bytes);

        if (is_array($size) AND ! empty($size[0]))
        {
            $out['width']  = (int) $size[0];
            $out['height'] = (int) $size[1];
        }

        $name = trim((string) ($client['name'] ?? ''));

        if ($name !== '')
        {
            $out['name'] = mb_substr($name, 0, 160);
        }

        foreach (['size' => 'source_bytes', 'width' => 'source_width',
                  'height' => 'source_height'] as $from => $to)
        {
            $value = (int) ($client[$from] ?? 0);

            if ($value > 0)
            {
                $out[$to] = $value;
            }
        }

        $taken = (int) ($client['modified'] ?? 0);

        if ($taken > 0)
        {
            // Браузер отдаёт миллисекунды, а дата файла из будущего —
            // признак кривых часов, а не находка: такую не записываем.
            $taken = (int) round($taken / 1000);
            $out['modified_at'] = ($taken > 0 AND $taken < time() + 86400)
                ? date('c', $taken) : '';
        }

        return array_filter($out, function ($v) { return $v !== '' AND $v !== NULL; });
    }

    /** Есть ли уже такой отпечаток у посетителя. */
    public static function fingerprint_exists($visitor, $fingerprint)
    {
        $fingerprint = (string) $fingerprint;

        if ($fingerprint === '' OR (string) $visitor === '' OR ! self::table_ready(self::ITEMS))
        {
            return FALSE;
        }

        if ( ! self::has_column('fingerprint'))
        {
            return FALSE;
        }

        $row = self::db()->query(Database::SELECT,
            'SELECT id FROM '.self::ITEMS.'
             WHERE visitor = '.self::db()->quote((string) $visitor).'
               AND fingerprint = '.self::db()->quote($fingerprint).'
             LIMIT 1', FALSE)->current();

        return $row ? TRUE : FALSE;
    }

    /**
     * Есть ли колонка (миграция 002 могла быть ещё не прогнана).
     *
     * Модуль обязан работать и на старой схеме: страница без дедупликации
     * лучше, чем страница с ошибкой SQL.
     */
    public static function has_column($name)
    {
        static $cache = [];

        $name = preg_replace('/[^a-z_]/', '', (string) $name);

        if ($name === '')
        {
            return FALSE;
        }

        if ( ! isset($cache[$name]))
        {
            try
            {
                $row = self::db()->query(Database::SELECT,
                    "SELECT 1 AS ok FROM information_schema.columns
                      WHERE table_name = '".self::ITEMS."' AND column_name = '".$name."'
                      LIMIT 1", FALSE)->current();

                $cache[$name] = (bool) $row;
            }
            catch (Throwable $e)
            {
                $cache[$name] = FALSE;
            }
        }

        return $cache[$name];
    }

    /**
     * Поиск по коллекции посетителя: ближайшие к вектору запроса.
     *
     * @param  array  $vector  вектор запроса
     * @param  string $visitor чья коллекция
     * @param  int    $limit   сколько находок
     * @param  int    $except  какой элемент пропустить (сам себя не ищем)
     * @return array строки с полем distance (0 — совпадение)
     */
    public static function search(array $vector, $visitor, $limit = 6, $except = 0)
    {
        if ( ! $vector OR (string) $visitor === '' OR ! self::table_ready(self::ITEMS))
        {
            return [];
        }

        $db      = self::db();
        $literal = $db->quote(self::vector_literal($vector));
        $order   = self::has_pgvector()
            ? 'i.embedding <=> '.$literal.'::vector'
            : 'nj_653_vec_dist(i.embedding, '.$literal.'::real[])';

        $where = 'WHERE i.visitor = '.$db->quote((string) $visitor)
            .' AND i.dims = '.(int) count($vector);

        if ((int) $except > 0)
        {
            $where .= ' AND i.id <> '.(int) $except;
        }

        $rows = $db->query(Database::SELECT,
            'SELECT i.id, i.label, i.kind, i.content, i.preview, i.created_at,
                    '.$order.' AS distance
             FROM '.self::ITEMS.' i
             '.$where.'
             ORDER BY '.$order.' ASC
             LIMIT '.(int) max(1, min(30, $limit)), FALSE)->as_array();

        return array_map(function ($row) { return (array) $row; }, $rows);
    }

    /** Коллекция посетителя для списка на странице (без векторов). */
    public static function items_mine($visitor, $limit = 60)
    {
        $visitor = (string) $visitor;

        if ($visitor === '' OR ! self::table_ready(self::ITEMS))
        {
            return [];
        }

        $meta = self::has_column('meta') ? ', meta' : ", '{}'::jsonb AS meta";

        $rows = self::db()
            ->query(Database::SELECT,
                'SELECT id, label, kind, content, preview, dims, model, created_at'.$meta.'
                 FROM '.self::ITEMS.'
                 WHERE visitor = '.self::db()->quote($visitor).'
                 ORDER BY id DESC LIMIT '.(int) max(1, min(200, $limit)), FALSE)
            ->as_array();

        return array_map(function ($row) { return (array) $row; }, $rows);
    }

    /** Сколько элементов у посетителя (предел коллекции). */
    public static function items_count($visitor)
    {
        if ((string) $visitor === '' OR ! self::table_ready(self::ITEMS))
        {
            return 0;
        }

        $row = self::db()
            ->query(Database::SELECT,
                'SELECT count(*) AS c FROM '.self::ITEMS.'
                 WHERE visitor = '.self::db()->quote((string) $visitor), FALSE)
            ->current();

        return (int) ($row['c'] ?? 0);
    }

    /** Убрать элемент (только свой) или всю коллекцию посетителя. */
    public static function forget($visitor, $id = 0)
    {
        if ((string) $visitor === '' OR ! self::table_ready(self::ITEMS))
        {
            return 0;
        }

        $where = 'visitor = '.self::db()->quote((string) $visitor);

        if ((int) $id > 0)
        {
            $where .= ' AND id = '.(int) $id;
        }

        return (int) DB::query(Database::DELETE,
            'DELETE FROM '.self::ITEMS.' WHERE '.$where)->execute();
    }

    // ------------------------------------------------------------- админка

    /**
     * Выражение косинусной ДИСТАНЦИИ между двумя строками таблицы.
     *
     * Один и тот же оператор у pgvector (`<=>`) и функция-замена из миграции
     * 001 для режима real[]: админские отчёты обязаны работать в обоих, иначе
     * «найти дубли» превратится в «ошибка SQL» на половине инсталляций.
     */
    protected static function pair_distance($a, $b)
    {
        return self::has_pgvector()
            ? $a.'.embedding <=> '.$b.'.embedding'
            : 'nj_653_vec_dist('.$a.'.embedding, '.$b.'.embedding)';
    }

    /**
     * Коллекция ЦЕЛИКОМ, с отбором — для админа.
     *
     * $filter: visitor, kind, model, search (по подписи и тексту),
     * from/limit. Вектор не отдаём: это десятки тысяч чисел на строку.
     */
    public static function items_all(array $filter = [], $limit = 50, $offset = 0)
    {
        if ( ! self::table_ready(self::ITEMS))
        {
            return [];
        }

        $db    = self::db();
        $where = self::items_where($filter);
        $extra = self::has_column('meta') ? ', i.meta, i.fingerprint' : '';

        $rows = $db->query(Database::SELECT,
            'SELECT i.id, i.visitor, i.user_id, i.run_id, i.label, i.kind, i.content,
                    i.preview, i.model, i.dims, i.created_at'.$extra.'
             FROM '.self::ITEMS.' i
             '.$where.'
             ORDER BY i.id DESC
             LIMIT '.(int) max(1, min(200, $limit)).' OFFSET '.(int) max(0, $offset), FALSE)
            ->as_array();

        return array_map(function ($row) { return (array) $row; }, $rows);
    }

    /** Сколько элементов попадает под тот же отбор (для «показано N из M»). */
    public static function items_total(array $filter = [])
    {
        if ( ! self::table_ready(self::ITEMS))
        {
            return 0;
        }

        $row = self::db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM '.self::ITEMS.' i '.self::items_where($filter), FALSE)
            ->current();

        return (int) ($row['c'] ?? 0);
    }

    /** Условие отбора для админских выборок (общее у списка и счётчика). */
    protected static function items_where(array $filter)
    {
        $db   = self::db();
        $and  = [];

        if (trim((string) ($filter['visitor'] ?? '')) !== '')
        {
            $and[] = 'i.visitor = '.$db->quote(trim((string) $filter['visitor']));
        }

        if (isset(Service_Nj653_Veclab::KINDS[(string) ($filter['kind'] ?? '')]))
        {
            $and[] = 'i.kind = '.$db->quote((string) $filter['kind']);
        }

        if (trim((string) ($filter['model'] ?? '')) !== '')
        {
            $and[] = 'i.model = '.$db->quote(trim((string) $filter['model']));
        }

        $search = trim((string) ($filter['search'] ?? ''));

        if ($search !== '')
        {
            $like  = $db->quote('%'.mb_strtolower($search).'%');
            $and[] = '(lower(i.label) LIKE '.$like.' OR lower(i.content) LIKE '.$like.')';
        }

        $days = (int) ($filter['older_days'] ?? 0);

        if ($days > 0)
        {
            $and[] = "i.created_at < now() - interval '".$days." days'";
        }

        return $and ? 'WHERE '.implode(' AND ', $and) : '';
    }

    /** Кто и сколько накопил: сводка по посетителям. */
    public static function visitors($limit = 40)
    {
        if ( ! self::table_ready(self::ITEMS))
        {
            return [];
        }

        $rows = self::db()->query(Database::SELECT,
            'SELECT i.visitor, count(*) AS items,
                    count(*) FILTER (WHERE i.kind <> \'text\') AS images,
                    max(i.created_at) AS last_at
             FROM '.self::ITEMS.' i
             GROUP BY i.visitor
             ORDER BY count(*) DESC, max(i.created_at) DESC
             LIMIT '.(int) max(1, min(200, $limit)), FALSE)->as_array();

        return array_map(function ($row) { return (array) $row; }, $rows);
    }

    /**
     * Пары почти одинаковых элементов одного посетителя.
     *
     * Отпечаток ловит только побайтово те же файлы, а пересохранённая в
     * другом качестве картинка — уже другой файл с тем же смыслом. Такие
     * пары видно только по вектору: косинусная дистанция меньше порога.
     *
     * @param  float $eps порог дистанции (0,02 ≈ косинус 0,98)
     * @return array [['keep' => id, 'drop' => id, 'distance' => float, …], …]
     */
    public static function duplicate_pairs($eps = 0.02, $limit = 100)
    {
        if ( ! self::table_ready(self::ITEMS))
        {
            return [];
        }

        $eps      = max(0.0, min(0.5, (float) $eps));
        $distance = self::pair_distance('a', 'b');

        $rows = self::db()->query(Database::SELECT,
            'SELECT a.id AS keep_id, b.id AS drop_id, a.visitor,
                    a.label AS keep_label, b.label AS drop_label,
                    b.preview AS drop_preview,
                    '.$distance.' AS distance
             FROM '.self::ITEMS.' a
             JOIN '.self::ITEMS.' b
               ON b.visitor = a.visitor AND b.dims = a.dims AND b.id > a.id
             WHERE '.$distance.' <= '.$eps.'
             ORDER BY '.$distance.' ASC, b.id ASC
             LIMIT '.(int) max(1, min(500, $limit)), FALSE)->as_array();

        return array_map(function ($row) { return (array) $row; }, $rows);
    }

    /**
     * Убрать дубли, оставив САМЫЙ РАННИЙ элемент каждой группы.
     *
     * Старший по времени остаётся не из вежливости: на него уже могли
     * сослаться результаты прошлых поисков и прогонов.
     */
    public static function prune_duplicates($eps = 0.02)
    {
        $pairs = self::duplicate_pairs($eps, 500);
        $drop  = [];

        foreach ($pairs as $pair)
        {
            $keep = (int) $pair['keep_id'];
            $id   = (int) $pair['drop_id'];

            // Если «старший» сам уже помечен к удалению, значит это цепочка
            // копий — удаляем и младшего, первый в группе всё равно уцелеет.
            if ($id > 0 AND $id !== $keep)
            {
                $drop[$id] = TRUE;
            }
        }

        return self::delete_ids(array_keys($drop));
    }

    /** Удалить элементы по списку id (админ: без привязки к посетителю). */
    public static function delete_ids(array $ids)
    {
        $clean = [];

        foreach ($ids as $id)
        {
            if ((int) $id > 0)
            {
                $clean[(int) $id] = (int) $id;
            }
        }

        if ( ! $clean OR ! self::table_ready(self::ITEMS))
        {
            return 0;
        }

        return (int) DB::query(Database::DELETE,
            'DELETE FROM '.self::ITEMS.' WHERE id IN ('.implode(',', $clean).')')->execute();
    }

    /** Удалить всё по отбору (посетитель, вид, модель, возраст). */
    public static function delete_where(array $filter)
    {
        $where = self::items_where($filter);

        if ($where === '' OR ! self::table_ready(self::ITEMS))
        {
            // Пустой отбор = «удалить вообще всё»: такое делается отдельной
            // кнопкой с явным подтверждением, а не случайной формой.
            return 0;
        }

        return (int) DB::query(Database::DELETE,
            'DELETE FROM '.self::ITEMS.' i '.$where)->execute();
    }

    /** Удалить коллекцию целиком (кнопка «очистить всё» в админке). */
    public static function truncate_items()
    {
        if ( ! self::table_ready(self::ITEMS))
        {
            return 0;
        }

        $before = self::items_total([]);

        DB::query(Database::DELETE, 'DELETE FROM '.self::ITEMS)->execute();

        return $before;
    }

    /** Список моделей, которыми считались векторы (для фильтра админки). */
    public static function models()
    {
        if ( ! self::table_ready(self::ITEMS))
        {
            return [];
        }

        $rows = self::db()->query(Database::SELECT,
            'SELECT model, count(*) AS c FROM '.self::ITEMS.'
             WHERE model <> \'\' GROUP BY model ORDER BY count(*) DESC LIMIT 20', FALSE)
            ->as_array();

        return array_map(function ($row) { return (array) $row; }, $rows);
    }

    /**
     * Элементы с векторами — для карты сходства.
     *
     * Вектор здесь нужен целиком: карту рисует страница, а не база. Поэтому
     * предел жёсткий — на сотне точек и 2048 числах это уже мегабайты.
     */
    public static function items_with_vectors($visitor, $limit = 60)
    {
        if ( ! self::table_ready(self::ITEMS))
        {
            return [];
        }

        $db    = self::db();
        $where = (string) $visitor !== ''
            ? 'WHERE i.visitor = '.$db->quote((string) $visitor) : '';

        $rows = $db->query(Database::SELECT,
            'SELECT i.id, i.label, i.kind, i.content, i.preview, i.created_at,
                    i.embedding::text AS vector
             FROM '.self::ITEMS.' i
             '.$where.'
             ORDER BY i.id DESC
             LIMIT '.(int) max(2, min(120, $limit)), FALSE)->as_array();

        $out = [];

        foreach ($rows as $row)
        {
            $row = (array) $row;
            $raw = trim((string) ($row['vector'] ?? ''), "[]{} \n\r\t");

            $row['vector'] = $raw === ''
                ? [] : array_map('floatval', explode(',', $raw));

            $out[] = $row;
        }

        return $out;
    }

    // -------------------------------------------------------------- кадры

    /** Сколько кадров может быть у одного прогона (слоты 1…N). */
    const SLOT_MAX = 24;

    /**
     * Кадр входа: у прогона их бывает несколько (пара в сравнении, пачка
     * картинок при добавлении в коллекцию), поэтому к имени файла добавляется
     * номер слота. Сам `Vision` хранит один кадр на прогон, но каталог и
     * проверки кадра у нас общие с ним.
     */
    public static function photo_path($run_id, $slot, $ext = '')
    {
        $name = (int) $run_id.'_'.max(1, min(self::SLOT_MAX, (int) $slot));

        return Vision::photo_dir(self::MODULE).'/'.$name.($ext !== '' ? '.'.$ext : '');
    }

    /** Положить кадр слота. Возвращает расширение или '' (не смогли). */
    public static function photo_save($run_id, $slot, $bytes)
    {
        $bytes = (string) $bytes;

        if ((int) $run_id <= 0 OR $bytes === '')
        {
            return '';
        }

        $ext = ['image/png' => 'png', 'image/webp' => 'webp'][Vision::mime($bytes)] ?? 'jpg';

        self::photo_drop($run_id, $slot);

        return @file_put_contents(self::photo_path($run_id, $slot, $ext), $bytes) !== FALSE
            ? $ext : '';
    }

    /** Кадр слота: [байты, mime] или [NULL, '']. */
    public static function photo_bytes($run_id, $slot)
    {
        $found = (int) $run_id > 0 ? glob(self::photo_path($run_id, $slot).'.*') : [];

        if ( ! $found)
        {
            return [NULL, ''];
        }

        $bytes = @file_get_contents($found[0]);

        if ($bytes === FALSE OR $bytes === '')
        {
            return [NULL, ''];
        }

        return [$bytes, Vision::mime($bytes)];
    }

    /** Убрать кадры прогона: вектор посчитан, оригинал больше не нужен. */
    public static function photo_drop($run_id, $slot = 0)
    {
        if ((int) $run_id <= 0)
        {
            return;
        }

        $mask = (int) $slot > 0
            ? self::photo_path($run_id, $slot).'.*'
            : Vision::photo_dir(self::MODULE).'/'.(int) $run_id.'_*';

        foreach ((array) glob($mask) as $file)
        {
            @unlink($file);
        }
    }

    // ------------------------------------------------------------- справки

    /**
     * Паспорт хранилища для страницы: режим поиска, размерность, объём.
     *
     * Страница обязана честно говорить, в каком режиме живёт поиск: с HNSW
     * (pgvector) или полным перебором. Это не мелочь — от этого зависит,
     * сколько элементов в коллекцию класть осмысленно.
     */
    public static function stats()
    {
        $out = [
            'ready'    => self::table_ready(self::ITEMS),
            'pgvector' => FALSE,
            'dims'     => 0,
            'total'    => 0,
            'model'    => '',
        ];

        if ( ! $out['ready'])
        {
            return $out;
        }

        $out['pgvector'] = self::has_pgvector();
        $out['dims']     = self::dims();

        $row = self::db()
            ->query(Database::SELECT,
                'SELECT count(*) AS c, max(model) AS model FROM '.self::ITEMS, FALSE)
            ->current();

        $out['total'] = (int) ($row['c'] ?? 0);
        $out['model'] = (string) ($row['model'] ?? '');

        return $out;
    }

    /** Размерность, на которую создана колонка вектора (0 — не определили). */
    public static function dims()
    {
        static $dims = NULL;

        if ($dims !== NULL)
        {
            return $dims;
        }

        $dims = 0;

        if ( ! self::table_ready(self::ITEMS))
        {
            return $dims;
        }

        try
        {
            if (self::has_pgvector())
            {
                // atttypmod у pgvector хранит объявленную размерность.
                $row = self::db()->query(Database::SELECT,
                    "SELECT a.atttypmod AS dims
                       FROM pg_attribute a
                       JOIN pg_class c ON c.oid = a.attrelid
                      WHERE c.relname = '".self::ITEMS."' AND a.attname = 'embedding'", FALSE)
                    ->current();

                $dims = max(0, (int) ($row['dims'] ?? 0));
            }
            else
            {
                // real[] размерности не знает — берём её из уже записанных строк.
                $row = self::db()->query(Database::SELECT,
                    'SELECT max(dims) AS dims FROM '.self::ITEMS, FALSE)->current();

                $dims = (int) ($row['dims'] ?? 0);
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj653: не удалось узнать размерность: :e',
                [':e' => $e->getMessage()]);
        }

        return $dims;
    }

    /** Есть ли pgvector (иначе — режим real[] с nj_653_vec_dist). */
    public static function has_pgvector()
    {
        if (self::$_pgvector === NULL)
        {
            try
            {
                $row = self::db()->query(Database::SELECT,
                    "SELECT data_type FROM information_schema.columns
                      WHERE table_name = '".self::ITEMS."' AND column_name = 'embedding'", FALSE)
                    ->current();

                self::$_pgvector = ($row AND strtolower((string) $row['data_type']) !== 'array');
            }
            catch (Throwable $e)
            {
                self::$_pgvector = FALSE;
            }
        }

        return self::$_pgvector;
    }

    /**
     * Литерал вектора для SQL: [1,2,3] для pgvector, {1,2,3} для real[].
     * Числа печатаем через sprintf: от локали формат зависеть не должен.
     */
    public static function vector_literal(array $vector)
    {
        $floats = [];

        foreach ($vector as $value)
        {
            $floats[] = sprintf('%.7F', (float) $value);
        }

        return self::has_pgvector()
            ? '['.implode(',', $floats).']'
            : '{'.implode(',', $floats).'}';
    }

    /** Есть ли таблица (страница не должна падать до миграции). */
    public static function table_ready($table)
    {
        return Vision::table_exists((string) $table);
    }

    public static function db()
    {
        return Database::instance();
    }
}
