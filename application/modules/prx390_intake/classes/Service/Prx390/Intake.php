<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Приём входящих документов.
 *
 * Первый канал — папка на сервере. Конвейер:
 *   файл → проверка типа/размера → SHA-256 → дедупликация
 *        → prx212 (файл) → конверт prx_390_messages → очередь `doc.normalize`.
 *
 * Модуль не создаёт карточку: это делает prx391 по событию `prx390.text.ready`.
 * Так приём можно тестировать отдельно от реестра.
 */
class Service_Prx390_Intake
{
    const STATUS_RECEIVED  = 'received';
    const STATUS_REGISTERED = 'registered';
    const STATUS_TEXT_READY = 'text_ready';
    const STATUS_NEEDS_OCR = 'needs_ocr';
    const STATUS_ERROR     = 'error';

    protected static $_config;

    public static function config($key = NULL, $default = NULL)
    {
        if (self::$_config === NULL)
        {
            self::$_config = Kohana::$config->load('prx390_intake')->as_array();
        }

        if ($key === NULL)
        {
            return self::$_config;
        }

        return array_key_exists($key, self::$_config) ? self::$_config[$key] : $default;
    }

    // ------------------------------------------------------------------
    // Папка и сканирование
    // ------------------------------------------------------------------

    /** Абсолютный путь папки. Относительные пути считаются от корня проекта. */
    public static function path($key)
    {
        $path = (string) self::config($key, '');
        if ($path === '')
        {
            throw new InvalidArgumentException('Не настроен путь prx390: '.$key);
        }

        if ($path[0] !== '/' AND ! preg_match('~^[A-Za-z]:[\\\\/]~', $path))
        {
            $path = rtrim(dirname(APPPATH), '/').'/'.$path;
        }

        return rtrim($path, '/');
    }

    public static function ensure_dirs()
    {
        foreach (['inbox_dir', 'processed_dir', 'duplicates_dir', 'error_dir'] as $key)
        {
            $dir = self::path($key);
            if ( ! is_dir($dir) AND ! @mkdir($dir, 0775, TRUE) AND ! is_dir($dir))
            {
                throw new RuntimeException('Не удалось создать папку '.$dir);
            }
        }
    }

    public static function inbox_files()
    {
        $dir = self::path('inbox_dir');
        if ( ! is_dir($dir))
        {
            return [];
        }

        $out = [];
        foreach (scandir($dir) as $name)
        {
            if ($name === '.' OR $name === '..')
            {
                continue;
            }

            $full = $dir.'/'.$name;
            if ( ! is_file($full))
            {
                continue;
            }

            if ( ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::config('allowed_ext', ['pdf']), TRUE))
            {
                continue;
            }

            $out[] = $full;
        }

        sort($out);

        return $out;
    }

    /**
     * Просканировать папку и принять новые PDF.
     *
     * @return array{found:int,accepted:int,duplicates:int,errors:int,items:array}
     */
    public static function scan_folder(array $actor = [], $limit = NULL)
    {
        self::ensure_dirs();
        $limit = max(1, (int) ($limit ?: self::config('scan_limit', 50)));
        $files = array_slice(self::inbox_files(), 0, $limit);

        $report = ['found' => count(self::inbox_files()), 'accepted' => 0, 'duplicates' => 0, 'errors' => 0, 'items' => []];

        foreach ($files as $file)
        {
            try
            {
                $res = self::receive_path($file, 'folder', $actor);
                $report[$res['state'] === 'duplicate' ? 'duplicates' : 'accepted']++;
                $report['items'][] = ['file' => basename($file)] + $res;

                $target = $res['state'] === 'duplicate' ? self::path('duplicates_dir') : self::path('processed_dir');
                @rename($file, $target.'/'.basename($file));
            }
            catch (Exception $e)
            {
                $report['errors']++;
                $report['items'][] = ['file' => basename($file), 'state' => 'error', 'error' => $e->getMessage()];
                @rename($file, self::path('error_dir').'/'.basename($file));
                Log::instance()->add(Log::ERROR, 'prx390: файл :f не принят: :e', [
                    ':f' => basename($file), ':e' => $e->getMessage(),
                ]);
            }
        }

        self::_touch_channel($report['errors'] ? 'Принято '.$report['accepted'].', ошибок '.$report['errors'] : NULL);

        return $report;
    }

    /**
     * Принять загруженный пользователем файл.
     *
     * @param  array $file  элемент $_FILES
     * @return array
     */
    public static function receive_upload(array $file, array $actor = [])
    {
        if (empty($file['tmp_name']) OR ! is_uploaded_file($file['tmp_name']))
        {
            throw new InvalidArgumentException('Файл не загружен');
        }

        return self::receive_path($file['tmp_name'], 'upload', $actor, $file['name'] ?? 'upload.pdf');
    }

    /**
     * Принять один файл по пути. Возвращает state=accepted|duplicate.
     */
    public static function receive_path($path, $source = 'folder', array $actor = [], $original_name = NULL)
    {
        if ( ! is_file($path))
        {
            throw new InvalidArgumentException('Файл не найден: '.$path);
        }

        $name = $original_name ?: basename($path);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ( ! in_array($ext, self::config('allowed_ext', ['pdf']), TRUE))
        {
            throw new InvalidArgumentException('Поддерживаются только PDF');
        }

        $size = filesize($path);
        $max  = (int) self::config('max_bytes', 10 * 1024 * 1024);
        if ($size <= 0 OR $size > $max)
        {
            throw new InvalidArgumentException('Размер файла вне допустимого диапазона');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string) finfo_file($finfo, $path) : 'application/pdf';
        if ($finfo)
        {
            finfo_close($finfo);
        }
        if ( ! in_array($mime, self::config('allowed_mime', ['application/pdf']), TRUE))
        {
            throw new InvalidArgumentException('Тип файла не PDF: '.$mime);
        }

        $sha = hash_file('sha256', $path);
        $existing = self::_row("SELECT id FROM prx_390_messages WHERE sha256 = ".self::_q($sha));
        if ($existing)
        {
            return ['state' => 'duplicate', 'message_id' => (int) $existing['id'], 'sha256' => $sha];
        }

        $file_id = self::_store_file($path, $name, (int) ($actor['user_id'] ?? 0));

        $row = self::_row("
            INSERT INTO prx_390_messages
                (channel_id, source, source_ref, sha256, subject, status, created_by)
            VALUES (
                (SELECT id FROM prx_390_channels WHERE code = 'folder'),
                ".self::_q($source).", ".self::_q($name).", ".self::_q($sha).",
                ".self::_q(pathinfo($name, PATHINFO_FILENAME)).",
                ".self::_q(self::STATUS_RECEIVED).", ".self::_int_or_null($actor['user_id'] ?? NULL)."
            )
            RETURNING id, received_at
        ");

        $message_id = (int) $row['id'];
        self::_exec(
            "INSERT INTO prx_390_files (message_id, file_id, filename, mime, size_bytes, sha256) "
            ."VALUES (".(int) $message_id.", ".(int) $file_id.", ".self::_q($name).", "
            .self::_q($mime).", ".(int) $size.", ".self::_q($sha).")"
        );

        try
        {
            self::enqueue_normalize($message_id, (int) ($actor['user_id'] ?? 0));
            $state = 'accepted';
        }
        catch (Exception $e)
        {
            self::_exec("UPDATE prx_390_messages SET status = '".self::STATUS_ERROR."', error = ".self::_q($e->getMessage())." WHERE id = ".$message_id);
            Log::instance()->add(Log::ERROR, 'prx390: очередь распознавания недоступна: :e', [':e' => $e->getMessage()]);
            $state = 'accepted';
        }

        return [
            'state' => $state,
            'message_id' => $message_id,
            'file_id' => (int) $file_id,
            'sha256' => $sha,
        ];
    }

    public static function enqueue_normalize($message_id, $user_id = 0)
    {
        $job_id = Service_Prx120_JobQueue::push('doc.normalize', [
            'message_id' => (int) $message_id,
        ], [
            'module' => 'prx390',
            'priority' => 60,
            'max_attempts' => 3,
            'timeout_sec' => 300,
            'created_by' => (int) $user_id,
        ]);

        return (int) $job_id;
    }

    public static function retry($message_id, array $actor = [])
    {
        $message = self::message($message_id);
        if ( ! $message)
        {
            throw new InvalidArgumentException('Входящий не найден');
        }

        $text = self::text_for_message($message_id);
        if ($text AND (int) $text['chars'] >= (int) self::config('min_text_chars', 40))
        {
            self::_exec("UPDATE prx_390_messages SET status = '".self::STATUS_TEXT_READY."', error = NULL WHERE id = ".(int) $message_id);
            Event::fire('prx390.text.ready', ['message_id' => (int) $message_id]);

            return 'text_ready';
        }

        $job_id = self::enqueue_normalize($message_id, (int) ($actor['user_id'] ?? 0));
        self::_exec("UPDATE prx_390_messages SET status = '".self::STATUS_RECEIVED."', error = NULL WHERE id = ".(int) $message_id);

        return $job_id;
    }

    // ------------------------------------------------------------------
    // Чтение и запись результата воркера
    // ------------------------------------------------------------------

    public static function message($id)
    {
        $row = self::_row("
            SELECT m.*, c.code AS channel_code, c.title AS channel_title
            FROM prx_390_messages m
            LEFT JOIN prx_390_channels c ON c.id = m.channel_id
            WHERE m.id = ".(int) $id."
        ");

        if ( ! $row)
        {
            return NULL;
        }

        $row['files'] = self::_all("SELECT * FROM prx_390_files WHERE message_id = ".(int) $id." ORDER BY id");
        $row['text'] = self::text_for_message($id);

        return $row;
    }

    public static function messages(array $filters = [])
    {
        $where = ['1=1'];
        if ( ! empty($filters['status']))
        {
            $where[] = "m.status = ".self::_q($filters['status']);
        }
        if ( ! empty($filters['q']))
        {
            $q = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
            $where[] = "(m.subject ILIKE ".self::_q($q)." OR m.sender_name ILIKE ".self::_q($q)." OR m.source_ref ILIKE ".self::_q($q).")";
        }

        $limit = max(1, min(200, (int) ($filters['limit'] ?? self::config('page_size', 50))));

        return self::_all("
            SELECT m.*, c.title AS channel_title,
                   (SELECT count(*) FROM prx_390_files f WHERE f.message_id = m.id) AS files_count,
                   (SELECT chars FROM prx_390_texts t WHERE t.message_id = m.id ORDER BY t.id DESC LIMIT 1) AS text_chars
            FROM prx_390_messages m
            LEFT JOIN prx_390_channels c ON c.id = m.channel_id
            WHERE ".implode(' AND ', $where)."
            ORDER BY m.received_at DESC, m.id DESC
            LIMIT ".$limit
        );
    }

    public static function text_for_message($message_id)
    {
        return self::_row("SELECT * FROM prx_390_texts WHERE message_id = ".(int) $message_id." ORDER BY id DESC LIMIT 1");
    }

    public static function text_for_file($file_id)
    {
        return self::_row("SELECT * FROM prx_390_texts WHERE file_id = ".(int) $file_id." ORDER BY id DESC LIMIT 1");
    }

    public static function file($file_id)
    {
        return self::_row("SELECT * FROM prx_390_files WHERE file_id = ".(int) $file_id." ORDER BY id DESC LIMIT 1");
    }

    /**
     * Сохранить распознанный текст. Идемпотентно по (message_id, file_id).
     *
     * @param array $data text|file_id|pages|engine|confidence
     */
    public static function store_text($message_id, array $data)
    {
        $message = self::message($message_id);
        if ( ! $message)
        {
            throw new InvalidArgumentException('Входящий не найден');
        }

        $text = trim((string) ($data['text'] ?? ''));
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);
        $text = trim($text);
        $chars = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $file_id = (int) ($data['file_id'] ?? 0);
        $min = (int) self::config('min_text_chars', 40);
        $status = $chars >= $min ? self::STATUS_TEXT_READY : self::STATUS_NEEDS_OCR;

        $row = self::_row("
            INSERT INTO prx_390_texts (message_id, file_id, text, chars, pages, engine, status, confidence)
            VALUES (
                ".(int) $message_id.", ".$file_id.", ".self::_q($text).", ".$chars.",
                ".self::_int_or_null($data['pages'] ?? NULL).", ".self::_q((string) ($data['engine'] ?? 'pypdf')).",
                ".self::_q($status).", ".self::_num_or_null($data['confidence'] ?? NULL)."
            )
            ON CONFLICT (message_id, file_id) DO UPDATE SET
                text = EXCLUDED.text,
                chars = EXCLUDED.chars,
                pages = EXCLUDED.pages,
                engine = EXCLUDED.engine,
                status = EXCLUDED.status,
                confidence = EXCLUDED.confidence,
                updated_at = now()
            RETURNING id
        ");

        self::_exec("
            UPDATE prx_390_messages
            SET status = ".self::_q($status).", error = NULL
            WHERE id = ".(int) $message_id."
        ");

        if ($status === self::STATUS_TEXT_READY)
        {
            Event::fire('prx390.text.ready', ['message_id' => (int) $message_id]);
        }

        return ['text_id' => (int) $row['id'], 'chars' => $chars, 'status' => $status];
    }

    public static function link_document($message_id, $document_id)
    {
        self::_exec("
            UPDATE prx_390_messages
            SET document_id = ".(int) $document_id.", status = '".self::STATUS_REGISTERED."'
            WHERE id = ".(int) $message_id."
        ");
    }

    public static function stats()
    {
        $out = [
            'total' => 0,
            'text_ready' => 0,
            'needs_ocr' => 0,
            'registered' => 0,
            'error' => 0,
            'inbox_files' => count(self::inbox_files()),
        ];

        foreach (self::_all("SELECT status, count(*) AS n FROM prx_390_messages GROUP BY status") as $row)
        {
            $out[$row['status']] = (int) $row['n'];
            $out['total'] += (int) $row['n'];
        }

        return $out;
    }

    public static function channels()
    {
        return self::_all("SELECT * FROM prx_390_channels ORDER BY id");
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    protected static function _store_file($path, $name, $user_id)
    {
        if ( ! class_exists('Service_Prx212_Storage'))
        {
            throw new RuntimeException('Модуль хранения файлов prx212 не подключён');
        }

        $id = Service_Prx212_Storage::instance()->save([
            'tmp_name' => $path,
            'name' => $name,
            'size' => filesize($path),
            'type' => 'application/pdf',
        ], [
            'user_id' => $user_id ?: NULL,
            'module' => 'prx390',
            'is_public' => 0,
        ]);

        return (int) $id;
    }

    protected static function _touch_channel($error = NULL)
    {
        $set = "last_scan_at = now()";
        if ($error === NULL)
        {
            $set .= ", last_ok_at = now(), last_error = NULL";
        }
        else
        {
            $set .= ", last_error = ".self::_q($error);
        }

        self::_exec("UPDATE prx_390_channels SET ".$set." WHERE code = 'folder'");
    }

    // ------------------------------------------------------------------
    // Мини-слой БД: только свои таблицы
    // ------------------------------------------------------------------

    protected static function _db()
    {
        return Database::instance();
    }

    protected static function _q($value)
    {
        return self::_db()->quote((string) $value);
    }

    protected static function _int_or_null($value)
    {
        return ($value === NULL OR $value === '') ? 'NULL' : (string) (int) $value;
    }

    protected static function _num_or_null($value)
    {
        return ($value === NULL OR $value === '') ? 'NULL' : (string) (float) $value;
    }

    protected static function _row($sql)
    {
        $res = self::_db()->query(Database::SELECT, $sql, FALSE);
        $row = $res->current();

        return $row ?: NULL;
    }

    protected static function _all($sql)
    {
        $out = [];
        foreach (self::_db()->query(Database::SELECT, $sql, FALSE) as $row)
        {
            $out[] = $row;
        }

        return $out;
    }

    protected static function _exec($sql)
    {
        return self::_db()->query(Database::UPDATE, $sql, FALSE);
    }
}
