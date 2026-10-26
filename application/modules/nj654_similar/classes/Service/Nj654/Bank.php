<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Банк картинок (NJ-654): файлы на диске, векторы в базе, поиск похожего.
 *
 * Весь модуль держится на одном сервисе: таблиц две, действий мало, и
 * размазывать их по трём классам — значит заставить читателя прыгать между
 * файлами ради одной строки SQL.
 *
 * Разделение труда то же, что у остальных сервисов с моделями: векторы
 * считает воркер (канал `nj654.embed`), сайт их только хранит и ищет по ним.
 * В HTTP-запросе к модели не ходят (AGENTS §2.6).
 */
class Service_Nj654_Bank
{
    const ITEMS   = 'nj_654_items';
    const QUERIES = 'nj_654_queries';
    const CHANNEL = 'nj654.embed';

    /** @var bool|NULL есть ли pgvector (иначе real[] + nj_654_vec_dist) */
    protected static $_pgvector = NULL;

    // ------------------------------------------------------------- базовое

    public static function cfg($key, $default = NULL)
    {
        $value = Kohana::$config->load('nj654_similar.'.$key);

        return ($value === NULL OR $value === '') ? $default : $value;
    }

    public static function db()
    {
        return Database::instance();
    }

    public static function ready()
    {
        return Vision::table_exists(self::ITEMS);
    }

    /** Словесная оценка близости: человеку нужны слова, а не только число. */
    public static function say($score)
    {
        $score = max(-1.0, min(1.0, (float) $score));

        foreach ((array) self::cfg('scale', []) as $step)
        {
            if ($score >= (float) $step['from'])
            {
                return ['value' => round($score, 4), 'title' => (string) $step['title'],
                    'tone' => (string) $step['tone']];
            }
        }

        return ['value' => round($score, 4), 'title' => 'непохоже', 'tone' => 'gray'];
    }

    // -------------------------------------------------------------- файлы

    /**
     * Хранилище: public_html/storage/nj654.
     *
     * Внутри докрута, потому что php-fpm пишет только туда (урок nj570), и
     * потому что картинку банка всё равно надо отдавать браузеру — лишний
     * контроллер-раздатчик ради этого не нужен.
     */
    public static function storage_dir()
    {
        $dir = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj654';

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0775, TRUE);
        }

        return $dir;
    }

    public static function file_path($rel)
    {
        $rel = trim((string) $rel);

        // Путь приходит из базы, но проверка стоит копейки, а стоимость
        // ошибки — чтение любого файла сервера.
        if ($rel === '' OR strpos($rel, '..') !== FALSE OR $rel[0] === '/')
        {
            return '';
        }

        return self::storage_dir().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    public static function file_url($rel)
    {
        $rel = trim((string) $rel);

        return $rel === '' ? '' : '/storage/nj654/'.$rel;
    }

    /**
     * Сохранить картинку: уменьшить до `image_max_width` и положить на диск.
     *
     * @return array ['file','mime','width','height','bytes'] или [] при отказе
     */
    public static function save_image($bytes, $name = '')
    {
        $bytes = (string) $bytes;

        if ($bytes === '' OR ! Vision::looks_like_image($bytes))
        {
            return [];
        }

        $max_bytes = (int) self::cfg('photo_mb', 12) * 1024 * 1024;

        if (strlen($bytes) > $max_bytes)
        {
            return [];
        }

        $shrunk = self::shrink($bytes);
        $mime   = $shrunk['mime'];
        $ext    = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $rel    = date('Ym').'/'.sha1($shrunk['bytes']).'.'.$ext;
        $path   = self::file_path($rel);

        if ($path === '')
        {
            return [];
        }

        if ( ! is_dir(dirname($path)))
        {
            @mkdir(dirname($path), 0775, TRUE);
        }

        if ( ! is_file($path) AND @file_put_contents($path, $shrunk['bytes']) === FALSE)
        {
            Log_Plain::event(Log::ERROR, 'nj654: не удалось сохранить :file', [':file' => $rel]);

            return [];
        }

        return [
            'file'   => $rel,
            'mime'   => $mime,
            'width'  => (int) $shrunk['width'],
            'height' => (int) $shrunk['height'],
            'bytes'  => strlen($shrunk['bytes']),
            'name'   => mb_substr(trim((string) $name), 0, 120),
        ];
    }

    /**
     * Уменьшить картинку до разрешённой ширины.
     *
     * Без GD отдаём исходник как есть: лучше сохранить крупный файл, чем
     * отказать человеку в загрузке из-за несобранного расширения PHP.
     */
    public static function shrink($bytes)
    {
        $mime = Vision::mime($bytes) ?: 'image/jpeg';
        $max  = max(64, (int) self::cfg('image_max_width', 1024));
        $out  = ['bytes' => $bytes, 'mime' => $mime, 'width' => 0, 'height' => 0];

        if ( ! function_exists('imagecreatefromstring'))
        {
            return $out;
        }

        $img = @imagecreatefromstring($bytes);

        if ($img === FALSE)
        {
            return $out;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        if ($w <= $max)
        {
            imagedestroy($img);

            return ['bytes' => $bytes, 'mime' => $mime, 'width' => $w, 'height' => $h];
        }

        $height = max(1, (int) round($h * $max / $w));
        $dst    = imagecreatetruecolor($max, $height);

        imagecopyresampled($dst, $img, 0, 0, 0, 0, $max, $height, $w, $h);
        imagedestroy($img);

        ob_start();
        imagejpeg($dst, NULL, max(40, min(95, (int) self::cfg('jpeg_quality', 82))));
        $small = (string) ob_get_clean();
        imagedestroy($dst);

        // Уменьшённая картинка всегда JPEG: прозрачность банку не нужна, а
        // PNG на фотографии весит втрое больше.
        return ['bytes' => $small, 'mime' => 'image/jpeg', 'width' => $max, 'height' => $height];
    }

    /** Картинка с диска для воркера: base64. */
    public static function file_b64($rel)
    {
        $path = self::file_path($rel);

        if ($path === '' OR ! is_file($path))
        {
            return ['', ''];
        }

        $bytes = (string) file_get_contents($path);

        return [base64_encode($bytes), Vision::mime($bytes) ?: 'image/jpeg'];
    }

    // -------------------------------------------------------------- приём

    /** Отпечаток: один и тот же файл (или текст) в банк дважды не кладём. */
    public static function fingerprint($text, $bytes = '')
    {
        $text = trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $text)));

        return hash('sha256', $text.'|'.($bytes === '' ? '' : hash('sha256', (string) $bytes)));
    }

    /**
     * Положить элемент в банк (вектор посчитает воркер).
     *
     * @return int id, 0 — такой элемент уже есть
     */
    public static function add_item(array $in)
    {
        $db    = self::db();
        $file  = (array) ($in['image'] ?? []);
        $note  = mb_substr(trim((string) ($in['note'] ?? '')), 0, (int) self::cfg('text_max', 1000));
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
        $kind  = $file ? ($note !== '' ? 'both' : 'image') : 'text';

        if ($title === '')
        {
            $title = $note !== '' ? $note : (string) ($file['name'] ?? 'Картинка');
            $title = mb_substr($title, 0, 200);
        }

        $sql = 'INSERT INTO '.self::ITEMS.'
                (user_id, visitor, title, note, kind, file, mime, width, height, bytes, fingerprint)
                VALUES ('
            .((isset($in['user_id']) AND $in['user_id'] !== NULL) ? (int) $in['user_id'] : 'NULL').', '
            .$db->quote(mb_substr((string) ($in['visitor'] ?? ''), 0, 64)).', '
            .$db->quote($title).', '
            .$db->quote($note).', '
            .$db->quote($kind).', '
            .$db->quote((string) ($file['file'] ?? '')).', '
            .$db->quote((string) ($file['mime'] ?? '')).', '
            .(int) ($file['width'] ?? 0).', '
            .(int) ($file['height'] ?? 0).', '
            .(int) ($file['bytes'] ?? 0).', '
            .$db->quote((string) ($in['fingerprint'] ?? '')).')
                ON CONFLICT DO NOTHING RETURNING id';

        $row = $db->query(Database::SELECT, $sql, FALSE)->current();

        return $row ? (int) $row['id'] : 0;
    }

    /** Поставить задачу воркеру: посчитать векторы для элементов банка. */
    public static function queue_items(array $ids)
    {
        $items = [];

        foreach (self::items(['ids' => $ids], count($ids)) as $item)
        {
            $items[] = [
                'id'        => (int) $item['id'],
                'text'      => (string) $item['note'],
                'has_image' => (string) $item['file'] !== '',
            ];
        }

        return $items ? self::push(['kind' => 'items', 'items' => $items]) : 0;
    }

    /** Завести поиск: вектор запроса посчитает воркер. */
    public static function query_create(array $in)
    {
        $db   = self::db();
        $file = (array) ($in['image'] ?? []);
        $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, (int) self::cfg('text_max', 1000));
        $kind = $file ? ($text !== '' ? 'both' : 'image') : 'text';
        $top  = max(1, min((int) self::cfg('top_max', 10),
            (int) ($in['top'] ?? self::cfg('top_default', 1))));

        $sql = 'INSERT INTO '.self::QUERIES.'
                (user_id, visitor, client_ip, kind, text, file, top)
                VALUES ('
            .((isset($in['user_id']) AND $in['user_id'] !== NULL) ? (int) $in['user_id'] : 'NULL').', '
            .$db->quote(mb_substr((string) ($in['visitor'] ?? ''), 0, 64)).', '
            .$db->quote(mb_substr((string) ($in['client_ip'] ?? ''), 0, 64)).', '
            .$db->quote($kind).', '
            .$db->quote($text).', '
            .$db->quote((string) ($file['file'] ?? '')).', '
            .$top.') RETURNING id';

        $row = $db->query(Database::SELECT, $sql, FALSE)->current();
        $id  = $row ? (int) $row['id'] : 0;

        if ($id > 0)
        {
            self::push(['kind' => 'query', 'id' => $id, 'text' => $text,
                'has_image' => (string) ($file['file'] ?? '') !== '']);
        }

        return $id;
    }

    /** Задача в общую очередь; отказ очереди виден в статусе, а не в тишине. */
    protected static function push(array $payload)
    {
        try
        {
            $job_id = (int) Service_Nj120_JobQueue::push(self::CHANNEL, $payload, [
                'module'       => 'nj654_similar',
                'timeout_sec'  => (int) self::cfg('timeout_sec', 300),
                'max_attempts' => 2,
            ]);

            if ($job_id > 0)
            {
                return $job_id;
            }

            $reason = 'очередь не вернула ID задачи';
        }
        catch (Throwable $e)
        {
            $reason = 'очередь недоступна: '.$e->getMessage();
        }

        $where = ($payload['kind'] ?? '') === 'query' ? self::QUERIES : self::ITEMS;
        $ids   = ($payload['kind'] ?? '') === 'query'
            ? [(int) ($payload['id'] ?? 0)]
            : array_column((array) ($payload['items'] ?? []), 'id');

        self::fail($where, $ids, $reason);

        return 0;
    }

    // ------------------------------------------------------------- приёмка

    /**
     * Приёмка результата воркера: векторы элементов или вектор запроса.
     *
     * @return array ['ok' => bool, 'note' => string]
     */
    public static function accept(array $body)
    {
        $kind  = (string) ($body['kind'] ?? 'items');
        $model = mb_substr((string) ($body['model'] ?? ''), 0, 160);

        if ($kind === 'query')
        {
            return self::accept_query($body, $model);
        }

        $done = 0;

        foreach ((array) ($body['items'] ?? []) as $one)
        {
            $id     = (int) ($one['id'] ?? 0);
            $vector = array_map('floatval', (array) ($one['vector'] ?? []));

            if ($id <= 0)
            {
                continue;
            }

            if ( ! $vector)
            {
                self::fail(self::ITEMS, [$id], (string) ($one['error'] ?? 'вектор не посчитан'));
                continue;
            }

            self::db()->query(Database::UPDATE,
                'UPDATE '.self::ITEMS.'
                    SET embedding = '.self::db()->quote(self::vector_literal($vector)).'::'
                        .(self::has_pgvector() ? 'vector' : 'real[]').',
                        dims = '.count($vector).',
                        model = '.self::db()->quote($model).",
                        status = 'ready', error = '', ready_at = now()
                  WHERE id = ".$id, FALSE);

            $done++;
        }

        return ['ok' => TRUE, 'note' => 'готово элементов: '.$done];
    }

    protected static function accept_query(array $body, $model)
    {
        $id     = (int) ($body['id'] ?? 0);
        $vector = array_map('floatval', (array) ($body['vector'] ?? []));
        $query  = self::query($id);

        if ( ! $query)
        {
            return ['ok' => FALSE, 'note' => 'поиск не найден'];
        }

        if ( ! $vector)
        {
            self::fail(self::QUERIES, [$id], (string) ($body['error'] ?? 'вектор запроса не посчитан'));

            return ['ok' => TRUE, 'note' => 'поиск закрыт отказом'];
        }

        $hits = self::search($vector, (int) $query['top']);

        self::db()->query(Database::UPDATE,
            'UPDATE '.self::QUERIES.'
                SET hits = '.self::db()->quote(json_encode($hits, JSON_UNESCAPED_UNICODE)).'::jsonb,
                    elapsed_ms = '.(int) ($body['elapsed_ms'] ?? 0).',
                    status = \'ready\', error = \'\', ready_at = now()
              WHERE id = '.$id, FALSE);

        return ['ok' => TRUE, 'note' => 'находок: '.count($hits)];
    }

    /** Пометить элементы или поиск отказом — молча терять задачу нельзя. */
    public static function fail($table, array $ids, $why)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ( ! $ids)
        {
            return 0;
        }

        return (int) self::db()->query(Database::UPDATE,
            'UPDATE '.$table." SET status = 'error', error = "
            .self::db()->quote(mb_substr((string) $why, 0, 500)).'
              WHERE id IN ('.implode(',', $ids).')', FALSE);
    }

    // --------------------------------------------------------------- поиск

    /**
     * Ближайшие элементы банка к вектору запроса.
     *
     * Считает PostgreSQL: вытаскивать тысячу векторов в PHP ради сортировки —
     * это мегабайты на запрос и секунды на перебор.
     */
    public static function search(array $vector, $top = 1)
    {
        if ( ! $vector OR ! self::ready())
        {
            return [];
        }

        $top     = max(1, min((int) self::cfg('top_max', 10), (int) $top));
        $literal = self::db()->quote(self::vector_literal($vector));
        $dist    = self::has_pgvector()
            ? 'embedding <=> '.$literal.'::vector'
            : 'nj_654_vec_dist(embedding, '.$literal.'::real[])';

        $rows = self::db()->query(Database::SELECT,
            'SELECT id, title, note, kind, file, width, height, '.$dist.' AS distance
               FROM '.self::ITEMS."
              WHERE embedding IS NOT NULL AND status = 'ready'
              ORDER BY distance ASC
              LIMIT ".$top, FALSE)->as_array();

        $out = [];

        foreach ($rows as $row)
        {
            $row = (array) $row;
            $out[] = [
                'id'    => (int) $row['id'],
                'title' => (string) $row['title'],
                'kind'  => (string) $row['kind'],
                'file'  => (string) $row['file'],
                'score' => round(1 - (float) $row['distance'], 4),
            ];
        }

        return $out;
    }

    /**
     * Находки → карточки для сетки плиток.
     *
     * Одну и ту же форму видят серверная отрисовка и догрузка без перезагрузки
     * (`/similar/api/status` отдаёт `cards`): плитка обязана выглядеть одинаково,
     * откуда бы она ни пришла. Здесь же считается словесная оценка близости —
     * рядом с числом она читается без расшифровки шкалы.
     */
    public static function cards(array $hits)
    {
        $out = [];

        foreach ($hits as $hit)
        {
            $hit  = (array) $hit;
            $file = trim((string) ($hit['file'] ?? ''));
            $say  = self::say($hit['score'] ?? 0);

            $out[] = [
                'id'         => (int) ($hit['id'] ?? 0),
                'title'      => (string) ($hit['title'] ?? ''),
                'kind'       => (string) ($hit['kind'] ?? 'image'),
                'file'       => $file !== '' ? self::file_url($file) : '',
                'score'      => (float) ($hit['score'] ?? 0),
                'score_text' => number_format((float) ($say['value'] ?? 0), 3, ',', ' '),
                'say'        => (string) $say['title'],
                'tone'       => (string) $say['tone'],
            ];
        }

        return $out;
    }

    // ----------------------------------------------------------- выборки

    /** Элементы банка по отбору: search, visitor, status, ids. */
    public static function items(array $filter = [], $limit = 48, $offset = 0)
    {
        if ( ! self::ready())
        {
            return [];
        }

        $rows = self::db()->query(Database::SELECT,
            'SELECT id, user_id, visitor, title, note, kind, file, mime, width, height,
                    bytes, model, dims, status, error, created_at
               FROM '.self::ITEMS.'
              WHERE '.self::where($filter).'
              ORDER BY id DESC
              LIMIT '.(int) max(1, min(200, $limit)).' OFFSET '.(int) max(0, $offset), FALSE)
            ->as_array();

        return array_map(function ($row) { return (array) $row; }, $rows);
    }

    public static function items_count(array $filter = [])
    {
        if ( ! self::ready())
        {
            return 0;
        }

        $row = self::db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM '.self::ITEMS.' WHERE '.self::where($filter), FALSE)->current();

        return (int) ($row['c'] ?? 0);
    }

    public static function item($id)
    {
        $rows = self::items(['ids' => [(int) $id]], 1);

        return $rows ? $rows[0] : NULL;
    }

    /** Условие отбора. Пустой отбор — «всё». */
    protected static function where(array $filter)
    {
        $db    = self::db();
        $parts = ['TRUE'];

        // Ключ ЗАДАН, но пуст — это «ничего не выбрано», а не «всё»: иначе
        // удаление с пустым списком галочек снесло бы первый попавшийся
        // элемент банка.
        if (array_key_exists('ids', $filter))
        {
            $ids = array_values(array_filter(array_map('intval', (array) $filter['ids'])));
            $parts[] = $ids ? 'id IN ('.implode(',', $ids).')' : 'FALSE';
        }

        if (trim((string) ($filter['visitor'] ?? '')) !== '')
        {
            $parts[] = 'visitor = '.$db->quote(trim((string) $filter['visitor']));
        }

        if (in_array((string) ($filter['status'] ?? ''), ['queued', 'ready', 'error'], TRUE))
        {
            $parts[] = 'status = '.$db->quote((string) $filter['status']);
        }

        if (trim((string) ($filter['search'] ?? '')) !== '')
        {
            $like = $db->quote('%'.trim((string) $filter['search']).'%');
            $parts[] = '(title ILIKE '.$like.' OR note ILIKE '.$like.')';
        }

        return implode(' AND ', $parts);
    }

    public static function query($id)
    {
        if ( ! self::ready())
        {
            return NULL;
        }

        $row = self::db()->query(Database::SELECT,
            'SELECT * FROM '.self::QUERIES.' WHERE id = '.(int) $id, FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        $row = (array) $row;
        $row['hits'] = (array) Vision::json_decode($row['hits'] ?? NULL);

        return $row;
    }

    /** Поиск доступен только тому же аккаунту или тому же браузеру. */
    public static function query_owned($id, $visitor, $user_id = NULL)
    {
        $query = self::query((int) $id);

        return ($query AND Vision::run_owned($query, $visitor, $user_id))
            ? $query
            : NULL;
    }

    /** Последние поиски — журнал для админа. */
    public static function queries($limit = 50)
    {
        if ( ! Vision::table_exists(self::QUERIES))
        {
            return [];
        }

        $rows = self::db()->query(Database::SELECT,
            'SELECT id, visitor, client_ip, kind, text, file, top, status, error,
                    hits, elapsed_ms, created_at
               FROM '.self::QUERIES.'
              ORDER BY id DESC LIMIT '.(int) max(1, min(200, $limit)), FALSE)->as_array();

        return array_map(function ($row) {
            $row = (array) $row;
            $row['hits'] = (array) Vision::json_decode($row['hits'] ?? NULL);

            return $row;
        }, $rows);
    }

    /** Сводка для шапки и админки. */
    public static function stats()
    {
        $out = ['ready' => self::ready(), 'pgvector' => FALSE, 'total' => 0, 'waiting' => 0,
            'failed' => 0, 'images' => 0, 'bytes' => 0, 'queries' => 0, 'model' => ''];

        if ( ! $out['ready'])
        {
            return $out;
        }

        $out['pgvector'] = self::has_pgvector();

        $row = self::db()->query(Database::SELECT,
            "SELECT count(*) AS total,
                    count(*) FILTER (WHERE status = 'queued') AS waiting,
                    count(*) FILTER (WHERE status = 'error')  AS failed,
                    count(*) FILTER (WHERE file <> '')        AS images,
                    COALESCE(sum(bytes), 0) AS bytes,
                    COALESCE(max(model), '') AS model
               FROM ".self::ITEMS, FALSE)->current();

        foreach ((array) $row as $key => $value)
        {
            $out[$key] = is_numeric($value) ? (int) $value : (string) $value;
        }

        if (Vision::table_exists(self::QUERIES))
        {
            $q = self::db()->query(Database::SELECT,
                'SELECT count(*) AS c FROM '.self::QUERIES, FALSE)->current();
            $out['queries'] = (int) ($q['c'] ?? 0);
        }

        return $out;
    }

    /** Сколько обращений с адреса за час — защита от случайного потока. */
    public static function per_hour($client_ip)
    {
        if ((string) $client_ip === '' OR ! Vision::table_exists(self::QUERIES))
        {
            return 0;
        }

        $row = self::db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM '.self::QUERIES.'
              WHERE client_ip = '.self::db()->quote((string) $client_ip)."
                AND created_at > now() - interval '1 hour'", FALSE)->current();

        return (int) ($row['c'] ?? 0);
    }

    // ------------------------------------------------------------ правка

    /** Переименовать элемент. Чужое правит только админ. */
    public static function rename($id, $title, $note, $visitor, $admin = FALSE)
    {
        $item = self::item($id);

        if ( ! $item OR ( ! $admin AND (string) $item['visitor'] !== (string) $visitor))
        {
            return FALSE;
        }

        $db    = self::db();
        $title = mb_substr(trim((string) $title), 0, 200);
        $note  = mb_substr(trim((string) $note), 0, (int) self::cfg('text_max', 1000));

        $db->query(Database::UPDATE,
            'UPDATE '.self::ITEMS.' SET title = '.$db->quote($title !== '' ? $title : $item['title']).',
                    note = '.$db->quote($note).'
              WHERE id = '.(int) $id, FALSE);

        // Текст — часть смысла элемента, значит вектор устарел: пересчитываем.
        if ($note !== (string) $item['note'])
        {
            $db->query(Database::UPDATE,
                'UPDATE '.self::ITEMS." SET status = 'queued', error = '' WHERE id = ".(int) $id, FALSE);
            self::queue_items([(int) $id]);
        }

        return TRUE;
    }

    /** Убрать элементы вместе с файлами: осиротевший файл — мусор на диске. */
    public static function delete(array $ids, $visitor, $admin = FALSE)
    {
        $gone = 0;

        foreach (self::items(['ids' => $ids], count($ids) ?: 1) as $item)
        {
            if ( ! $admin AND (string) $item['visitor'] !== (string) $visitor)
            {
                continue;
            }

            self::db()->query(Database::DELETE,
                'DELETE FROM '.self::ITEMS.' WHERE id = '.(int) $item['id'], FALSE);

            self::drop_file((string) $item['file']);
            $gone++;
        }

        return $gone;
    }

    /** Файл удаляем, только если на него больше никто не ссылается. */
    protected static function drop_file($rel)
    {
        $path = self::file_path($rel);

        if ($path === '' OR ! is_file($path))
        {
            return;
        }

        $row = self::db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM '.self::ITEMS.' WHERE file = '.self::db()->quote($rel), FALSE)
            ->current();

        if ((int) ($row['c'] ?? 0) === 0)
        {
            @unlink($path);
        }
    }

    /** Очистить журнал поисков: он нужен админу, но не вечно. */
    public static function clear_queries()
    {
        if ( ! Vision::table_exists(self::QUERIES))
        {
            return 0;
        }

        $row = self::db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM '.self::QUERIES, FALSE)->current();

        self::db()->query(Database::DELETE, 'DELETE FROM '.self::QUERIES, FALSE);

        return (int) ($row['c'] ?? 0);
    }

    // ------------------------------------------------------------ вектор

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

    /** Литерал вектора: [1,2] для pgvector, {1,2} для real[]; формат без локали. */
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
}
