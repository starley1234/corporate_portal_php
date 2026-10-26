<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Генератор изображений» (nj641_imagegen) — серверный слой генерации
 * картинок через внешний image-API и канал воркера nj641.generate.
 *
 * Схема «запрос → очередь nj120 → воркер → внешний image-API → результат»:
 *   1. Пользователь задаёт режим: generate (по описанию), edit (по картинке
 *      + описание) или multi-reference (несколько картинок-референсов).
 *   2. Сайт валидирует вход, пишет прогон в nj_641_runs и ставит задачу
 *      очереди nj120 (канал nj641.generate) через Service_Nj120_JobQueue.
 *   3. Воркер проверяет видеопамять бекенда (GET /vram; при нехватке ждёт —
 *      на сервере память может быть занята другими процессами), генерирует
 *      картинку во внешнем API и возвращает её через /imagegen/api/worker-result.
 *   4. Сайт пишет картинку в nj_641_runs — дальше её можно переиспользовать
 *      из других модулей (презентации, статьи) через result_of().
 *
 * ФАЙЛЫ, А НЕ BASE64 В БД (владелец, 26.09: «процесс запускается, изображение
 * генерируется, а в сервисе я его не вижу, видимо где-то теряется; может, папку
 * завести надо… и процесс висит»). Причина потери: картинка 1024×1024 — это
 * 1.5-4 МБ, в base64 ещё на треть больше; такое тело не проходит ни через
 * nginx/PHP (client_max_body_size/post_max_size), ни через JSON-результат
 * очереди. Поэтому байты храним файлом в public_html/storage/nj641 (каталог в
 * .gitignore, как storage/nj570), а в image_info пишем относительный путь и URL
 * для показа. База держит только метаданные — историю и переиспользование это
 * не ломает: result_of() отдаёт как URL файла, так и (для старых прогонов)
 * base64.
 *
 * Безопасность: rate limit (nj100_platform), лимиты размеров/длин, секреты
 * и адрес внешнего image-API — только из .env воркера (NJ641_*).
 */
class Service_Nj641_Imagegen
{
    const RATE_PER_MINUTE = 5;
    const RATE_PER_HOUR   = 30;

    const CHANNEL_GENERATE = 'nj641.generate';
    const CHANNEL_CLEAR    = 'nj641.clear';

    const MODE_GENERATE = 'generate';
    const MODE_EDIT     = 'edit';
    const MODE_REFERENCE = 'multi-reference';

    const STATUS_QUEUED    = 'queued';
    const STATUS_DONE      = 'done';
    const STATUS_ERROR     = 'error';
    const STATUS_CANCELLED = 'cancelled';

    // Модерация изображений (миграция 002): «публикация» = перевод в библиотеку,
    // откуда картинку берут другие сервисы (статьи, презентации).
    const REVIEW_PENDING  = 'pending';   // на модерации
    const REVIEW_APPROVED = 'approved';  // в библиотеке
    const REVIEW_REJECTED = 'rejected';  // отклонено

    /** Сколько живёт задача генерации в очереди (секунд). Дольше — впустую. */
    const DEFAULT_TIMEOUT_SEC = 900;

    const NID_KIND = 'nj641_imagegen';

    /** Конфиг модуля. */
    public static function cfg($key, $default = NULL)
    {
        $cfg = Kohana::$config->load('nj641_imagegen');

        return $cfg ? $cfg->get($key, $default) : $default;
    }

    public static function client_ip()
    {
        if (class_exists('Service_Nj100_Ratelimit'))
        {
            return Service_Nj100_Ratelimit::client_ip();
        }

        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    protected static function _db()
    {
        return Database::instance();
    }

    // ------------------------------------------------------------ rate limit

    public static function rate_ok($ip)
    {
        self::ensure_tables();

        $counts = Service_Nj100_Ratelimit::window_counts(
            'nj_641_runs', (string) $ip, 'client_ip');

        return Service_Nj100_Ratelimit::allowed($counts,
            (int) self::cfg('rate.per_minute', self::RATE_PER_MINUTE),
            (int) self::cfg('rate.per_hour', self::RATE_PER_HOUR));
    }

    public static function rate_limit_text()
    {
        return Service_Nj100_Ratelimit::limit_text(
            (int) self::cfg('rate.per_minute', self::RATE_PER_MINUTE),
            (int) self::cfg('rate.per_hour', self::RATE_PER_HOUR),
            'генераций');
    }

    // ------------------------------------------------------------ справочники

    /** Пресеты размеров (px → [w, h]). */
    public static function sizes(): array
    {
        return (array) self::cfg('sizes', [1024 => [1024, 1024]]);
    }

    public static function default_size(): int
    {
        return (int) self::cfg('default_size', 1024);
    }

    /** Настройки проверки видеопамяти — для UI и API. */
    public static function vram_settings(): array
    {
        $v = (array) self::cfg('vram', []);

        return [
            'check'       => (bool) ($v['check'] ?? TRUE),
            'wait'        => (bool) ($v['wait'] ?? TRUE),
            'min_free_mb' => (int) ($v['min_free_mb'] ?? 4096),
            'wait_sec'    => (int) ($v['wait_sec'] ?? 240),
            'poll_sec'    => (int) ($v['poll_sec'] ?? 20),
            // Сайт умеет только ПОКАЗЫВАТЬ занятость бекенда (сам он рисует
            // воркер). Пусто — значит на сайте NJ641_IMAGE_API не задан: это
            // норма для прода, но в UI надо сказать прямо, иначе «ничего не
            // работает» выглядит необъяснимо.
            'backend_set' => trim((string) getenv('NJ641_IMAGE_API')) !== '',
        ];
    }

    // ------------------------------------------------------------ изображения

    /** Лимит входной картинки, байт. */
    public static function max_input_bytes(): int
    {
        return (int) self::cfg('max_input_mb', 10) * 1024 * 1024;
    }

    /**
     * Прочитать входные изображения запроса: data_urls[] (base64) или
     * multipart-файл. Возвращает ['ok'=>TRUE, 'images'=>[{b64,media_type}]]
     * либо ['ok'=>FALSE, 'error'=>...].
     *
     * @param array $req тело запроса ($this->_body контроллера)
     */
    public static function read_input_images(array $req): array
    {
        $images = [];

        // multipart-файл (один)
        if (isset($_FILES['file']) AND is_array($_FILES['file'])
            AND (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
        {
            $f = $_FILES['file'];

            if (is_array($f['error']))
            {
                return ['ok' => FALSE, 'error' => 'Ожидается один файл.'];
            }

            if ((int) $f['error'] !== UPLOAD_ERR_OK)
            {
                return ['ok' => FALSE, 'error' => 'Ошибка загрузки файла (код '.$f['error'].').'];
            }

            if ((int) $f['size'] > self::max_input_bytes())
            {
                return ['ok' => FALSE, 'error' => 'Файл больше лимита '.self::cfg('max_input_mb', 10).' МБ.'];
            }

            $bytes = @file_get_contents($f['tmp_name']);

            if ($bytes === FALSE OR ! self::looks_like_image($bytes))
            {
                return ['ok' => FALSE, 'error' => 'Файл не похож на изображение.'];
            }

            $images[] = ['b64' => base64_encode($bytes), 'media_type' => self::sniff_media_type($bytes)];

            return ['ok' => TRUE, 'images' => $images];
        }

        // data URL (может быть несколько — референсы)
        foreach ((array) ($req['data_urls'] ?? []) as $url)
        {
            $d = self::decode_data_url((string) $url);

            if ( ! $d['ok'])
            {
                return $d;
            }

            $images[] = ['b64' => base64_encode($d['bytes']), 'media_type' => $d['media_type']];
        }

        if ( ! $images)
        {
            return ['ok' => FALSE, 'error' => 'Приложите изображение (файл или data URL).'];
        }

        return ['ok' => TRUE, 'images' => $images];
    }

    /** Разбор data: URL изображения. */
    public static function decode_data_url($url): array
    {
        $s = (string) $url;

        if ( ! preg_match('~^data:([a-z0-9.+-]+/[a-z0-9.+-]+)?(?:;charset=[^;]+)?(?:;base64)?,(.*)$~is', $s, $m))
        {
            return ['ok' => FALSE, 'error' => 'Некорректный data URL.'];
        }

        $mt  = strtolower((string) ($m[1] ?? ''));
        $raw = (string) $m[2];

        $bytes = (strpos(strtolower($s), ';base64') !== FALSE)
            ? base64_decode($raw, TRUE)
            : rawurldecode($raw);

        if ($bytes === FALSE OR $bytes === '' OR strlen($bytes) > self::max_input_bytes())
        {
            return ['ok' => FALSE, 'error' => 'Размер изображения вне лимита ('.self::cfg('max_input_mb', 10).' МБ).'];
        }

        if ( ! self::looks_like_image($bytes))
        {
            return ['ok' => FALSE, 'error' => 'Данные не похожи на изображение.'];
        }

        return ['ok' => TRUE, 'bytes' => $bytes, 'media_type' => $mt !== '' ? $mt : self::sniff_media_type($bytes)];
    }

    /** Лёгкая проверка «похоже на изображение» по магии формата. */
    public static function looks_like_image($bytes)
    {
        if ( ! is_string($bytes) OR $bytes === '')
        {
            return FALSE;
        }

        $head = substr($bytes, 0, 12);

        if (strpos($head, "\xFF\xD8\xFF") === 0) return TRUE;                 // JPEG
        if (strpos($head, "\x89PNG\r\n\x1A\n") === 0) return TRUE;           // PNG
        if (strpos($head, 'GIF87a') === 0 OR strpos($head, 'GIF89a') === 0) return TRUE; // GIF
        if (strpos($bytes, 'BM') === 0) return TRUE;                         // BMP
        if (strpos(substr($bytes, 0, 16), 'RIFF') === 0 AND strpos(substr($bytes, 8, 8), 'WEBP') === 0) return TRUE; // WEBP

        return FALSE;
    }

    public static function sniff_media_type($bytes)
    {
        if (strpos((string) $bytes, "\xFF\xD8\xFF") === 0) return 'image/jpeg';
        if (strpos((string) $bytes, "\x89PNG\r\n\x1A\n") === 0) return 'image/png';
        if (strpos((string) $bytes, 'GIF8') === 0) return 'image/gif';
        if (strpos((string) $bytes, 'BM') === 0) return 'image/bmp';
        if (strpos(substr((string) $bytes, 0, 16), 'RIFF') === 0 AND strpos(substr((string) $bytes, 8, 8), 'WEBP') === 0) return 'image/webp';

        return 'application/octet-stream';
    }

    // ------------------------------------------------------------ файлы

    /**
     * Каталог файлов: public_html/storage/nj641.
     *
     * Именно внутри DOCROOT (как storage/nj570): вне public_html php-fpm обычно
     * не имеет прав на запись, а bin/setup.sh создаёт public_html/storage
     * заранее. Каталог в .gitignore — картинки пользователей в репозиторий не
     * попадают.
     */
    public static function storage_dir(): string
    {
        $dir = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj641';

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0775, TRUE);
        }

        return $dir;
    }

    /** Расширение файла по типу содержимого (или по магии байтов). */
    public static function ext_for($media_type, $bytes = ''): string
    {
        $mt = strtolower(trim((string) $media_type));

        if ($mt === '' AND is_string($bytes) AND $bytes !== '')
        {
            $mt = self::sniff_media_type($bytes);
        }

        $map = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'image/bmp'  => 'bmp',
        ];

        return isset($map[$mt]) ? $map[$mt] : 'png';
    }

    /**
     * Записать картинку прогона в файл.
     *
     * @return array|null метаданные (file, url, bytes, width, height, media_type)
     *                    или NULL, если base64 битый/пустой либо запись не удалась
     */
    public static function store_image(int $run_id, string $b64, string $media_type = ''): ?array
    {
        $bytes = base64_decode(preg_replace('~\s+~', '', $b64), TRUE);

        if ( ! is_string($bytes) OR $bytes === '')
        {
            return NULL;
        }

        return self::store_bytes($run_id, $bytes, $media_type);
    }

    /**
     * Записать уже декодированные байты картинки (приём multipart-файла от
     * воркера и обычный путь base64 сходятся здесь).
     *
     * @return array|null метаданные (file, url, bytes, width, height, media_type)
     */
    public static function store_bytes(int $run_id, string $bytes, string $media_type = ''): ?array
    {
        if (strlen($bytes) < 64)
        {
            return NULL;
        }

        $dir = self::storage_dir();

        if ( ! is_dir($dir) AND ! @mkdir($dir, 0775, TRUE))
        {
            return NULL;
        }

        $ext  = self::ext_for($media_type, $bytes);
        $name = 'nj641-'.$run_id.'-'.bin2hex(random_bytes(5)).'.'.$ext;
        $path = $dir.DIRECTORY_SEPARATOR.$name;

        if (@file_put_contents($path, $bytes, LOCK_EX) === FALSE)
        {
            return NULL;
        }

        $info = [
            'file'  => 'storage/nj641/'.$name,
            'url'   => '/imagegen/file/'.$name,
            'bytes' => strlen($bytes),
        ];

        $size = @getimagesize($path);

        if (is_array($size) AND ! empty($size[0]))
        {
            $info['width']  = (int) $size[0];
            $info['height'] = (int) $size[1];

            if ( ! empty($size['mime']))
            {
                $info['media_type'] = (string) $size['mime'];
            }
        }

        if (empty($info['media_type']))
        {
            $info['media_type'] = $media_type !== '' ? (string) $media_type : self::sniff_media_type($bytes);
        }

        return $info;
    }

    /** Проверка имени файла: только то, что создали мы (без выхода из каталога). */
    public static function file_path(string $name): ?string
    {
        if ( ! preg_match('~^nj641-\d+-[a-f0-9]{6,}\.(png|jpg|jpeg|webp|gif|bmp)$~', $name))
        {
            return NULL;
        }

        $path = self::storage_dir().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : NULL;
    }

    public static function file_media_type(string $path): string
    {
        $size = @getimagesize($path);

        if (is_array($size) AND ! empty($size['mime']))
        {
            return (string) $size['mime'];
        }

        return self::sniff_media_type((string) @file_get_contents($path, FALSE, NULL, 0, 16));
    }

    /**
     * Найти в своём хранилище файл картинки прогона и вернуть его метаданные.
     *
     * Страховка на случай «воркер записал файл, а метаданные не дошли»: воркер
     * и сайт на одной машине (папка storage/nj641 из NJ641_MEDIA_DIR), имя
     * файла начинается с id прогона, поэтому сайт может поднять картинку сам —
     * без HTTP-доставки. Ровно этот случай у владельца 26.09: папка есть,
     * права 777, а прогон оставался без картинки («изображения не увидел»).
     */
    public static function adopt_file(int $run_id): ?array
    {
        if ($run_id <= 0)
        {
            return NULL;
        }

        $found = @glob(self::storage_dir().DIRECTORY_SEPARATOR.'nj641-'.$run_id.'-*');

        if ( ! is_array($found) OR ! $found)
        {
            return NULL;
        }

        $files = [];

        foreach ($found AS $path)
        {
            if (is_file($path))
            {
                $files[$path] = (int) @filemtime($path);
            }
        }

        if ( ! $files)
        {
            return NULL;
        }

        arsort($files);

        $path = (string) key($files);
        $name = basename($path);

        if (self::file_path($name) === NULL)
        {
            return NULL; // имя не нашего формата — не трогаем
        }

        $info = [
            'file'   => 'storage/nj641/'.$name,
            'url'    => '/imagegen/file/'.$name,
            'bytes'  => (int) filesize($path),
            'source' => 'disk',
        ];

        $size = @getimagesize($path);

        if (is_array($size) AND ! empty($size[0]))
        {
            $info['width']  = (int) $size[0];
            $info['height'] = (int) $size[1];

            if ( ! empty($size['mime']))
            {
                $info['media_type'] = (string) $size['mime'];
            }
        }

        if (empty($info['media_type']))
        {
            $info['media_type'] = self::file_media_type($path);
        }

        return $info;
    }

    /**
     * Удалить старые файлы (по умолчанию — старше NJ641_KEEP_DAYS, 60 дней).
     * 0 — не чистить. Вызывается при постановке новой генерации, не чаще раза
     * в час (маркер-файл), чтобы не сканировать каталог на каждом запросе.
     */
    public static function cleanup_files(): void
    {
        $days = (int) (getenv('NJ641_KEEP_DAYS') !== FALSE ? getenv('NJ641_KEEP_DAYS') : 60);

        if ($days <= 0)
        {
            return;
        }

        $dir    = self::storage_dir();
        $marker = $dir.DIRECTORY_SEPARATOR.'.cleanup';

        if (is_file($marker) AND (time() - (int) @filemtime($marker)) < 3600)
        {
            return;
        }

        @touch($marker);

        $limit = time() - $days * 86400;

        foreach ((array) @glob($dir.DIRECTORY_SEPARATOR.'nj641-*') as $file)
        {
            if (is_file($file) AND @filemtime($file) < $limit)
            {
                @unlink($file);
            }
        }
    }

    // ------------------------------------------------------------ постановка в очередь

    /**
     * Поставить генерацию в очередь. Валидирует вход, пишет прогон и
     * возвращает ['run_id'=>, 'job_id'=>] либо ['error'=>...].
     *
     * Переиспользуется из других модулей (презентации, статьи):
     *     $r = Service_Nj641_Imagegen::enqueue(Visitor::uid(), [
     *         'mode' => 'generate', 'prompt' => '...',
     *         'width' => 1024, 'height' => 1024, 'seed' => 0,
     *     ]);
     *     if (isset($r['run_id'])) { ... Service_Nj641_Imagegen::result_of($r['run_id']); }
     */
    public static function enqueue(string $uid, array $req): array
    {
        self::ensure_tables();
        self::cleanup_files();

        $mode = (string) ($req['mode'] ?? self::MODE_GENERATE);

        if ( ! in_array($mode, [self::MODE_GENERATE, self::MODE_EDIT, self::MODE_REFERENCE], TRUE))
        {
            return ['error' => 'Неизвестный режим генерации.'];
        }

        $prompt = trim((string) ($req['prompt'] ?? ''));

        if ($prompt === '')
        {
            return ['error' => 'Опишите, что нарисовать.'];
        }

        $max_chars = (int) self::cfg('max_prompt_chars', 2000);
        if (mb_strlen($prompt) > $max_chars)
        {
            return ['error' => 'Описание длиннее '.$max_chars.' символов.'];
        }

        $sizes = self::sizes();
        $width  = isset($sizes[(int) ($req['size'] ?? 0)]) ? $sizes[(int) $req['size']][0] : (int) ($req['width'] ?? self::default_size());
        $height = isset($sizes[(int) ($req['size'] ?? 0)]) ? $sizes[(int) $req['size']][1] : (int) ($req['height'] ?? self::default_size());
        $width  = max(64, min((int) $width, 2048));
        $height = max(64, min((int) $height, 2048));

        $seed     = (int) ($req['seed'] ?? 0);
        $strength = NULL;

        $images = [];
        $negative_prompt = trim((string) ($req['negative_prompt'] ?? ''));

        if ($mode === self::MODE_EDIT OR $mode === self::MODE_REFERENCE)
        {
            $read = self::read_input_images($req);

            if ( ! $read['ok'])
            {
                return $read;
            }

            $images = $read['images'];

            if ($mode === self::MODE_EDIT AND count($images) !== 1)
            {
                return ['error' => 'Для редактирования нужно ровно одно изображение.'];
            }

            $max_refs = (int) self::cfg('max_references', 3);
            if ($mode === self::MODE_REFERENCE AND count($images) > $max_refs)
            {
                return ['error' => 'Не больше '.$max_refs.' референсов.'];
            }

            $strength = (float) ($req['strength'] ?? 0.7);
            $strength = max(0.0, min((float) $strength, 1.0));
        }

        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_641_runs
                (visitor_uid, client_ip, mode, prompt, negative_prompt, width, height, seed, strength, status, created_at)
             VALUES ('.$db->quote($uid).', '.$db->quote(self::client_ip()).', '.$db->quote($mode).', '
            .$db->quote($prompt).', '.$db->quote($negative_prompt).', '.(int) $width.', '.(int) $height.', '
            .(int) $seed.', '.($strength === NULL ? 'NULL' : sprintf('%.3f', $strength)).', '
            .$db->quote(self::STATUS_QUEUED).', now())
             RETURNING id', FALSE)->current();

        $run_id = (int) $row['id'];

        $payload = [
            'run_id'          => $run_id,
            'mode'            => $mode,
            'prompt'          => $prompt,
            'negative_prompt' => $negative_prompt,
            'width'           => $width,
            'height'          => $height,
            'seed'            => $seed,
            'strength'        => $strength,
            'images'          => $images,
        ];

        try
        {
            $job_id = Service_Nj120_JobQueue::push(self::CHANNEL_GENERATE, $payload, [
                'max_attempts' => 2,
                'module'       => 'nj641',
                // 900 с (NJ641_JOB_TIMEOUT): генерация дольше четверти часа
                // почти всегда означает «бекенд завис». Ограничение — чтобы
                // прогон не висел вечно и владелец видел понятный финал.
                'timeout_sec'  => max(120, (int) (getenv('NJ641_JOB_TIMEOUT') ?: self::DEFAULT_TIMEOUT_SEC)),
            ]);

            $db->query(Database::UPDATE,
                'UPDATE nj_641_runs SET job_id = '.(int) $job_id.' WHERE id = '.(int) $run_id, FALSE);

            return ['run_id' => $run_id, 'job_id' => (int) $job_id];
        }
        catch (Exception $e)
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_641_runs SET status = '.$db->quote(self::STATUS_ERROR)
                .', error = '.$db->quote('Очередь недоступна: '.$e->getMessage())
                .', finished_at = now() WHERE id = '.(int) $run_id, FALSE);

            return ['error' => 'Не удалось поставить задачу в очередь.'];
        }
    }

    // ------------------------------------------------------------ результаты

    /** Прогон по id (для JS-поллинга и переиспользования из других модулей). */
    public static function result_of(int $run_id): ?array
    {
        if ($run_id <= 0)
        {
            return NULL;
        }

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_641_runs WHERE id = '.(int) $run_id, FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        return self::reconcile((array) $row);
    }

    /**
     * Ленивая подтяжка результата задачи из очереди nj120 (паттерн nj640).
     *
     * Воркер по итогу генерации пишет результат в nj_120_jobs.result через
     * queue.complete() — это происходит ВСЕГДА, в любом режиме (pg и http).
     * Прямые каналы (запись pg у pg-воркера, POST worker-result) могут не
     * сработать (http-режим, не задан NJ641_SITE_TOKEN) — поэтому /result
     * сам достаёт готовый результат из очереди, пока прогон ещё 'queued'.
     */
    public static function reconcile(array $run): array
    {
        // 1) Картинка могла лечь на диск раньше, чем пришли метаданные (воркер и
        //    сайт на одной машине) — подхватываем её САМИ, при любом статусе
        //    прогона. Это последняя страховка от «сгенерировалось, но не видно»:
        //    имя файла начинается с id прогона.
        if (self::image_url($run) === '')
        {
            $adopted = self::adopt_file((int) $run['id']);

            if ($adopted !== NULL)
            {
                self::apply_result((int) $run['id'], [
                    'status'     => self::STATUS_DONE,
                    'image_file' => $adopted,
                    'image_info' => ['media_type' => $adopted['media_type'] ?? 'image/png'],
                ]);

                $fresh = self::_db()->query(Database::SELECT,
                    'SELECT * FROM nj_641_runs WHERE id = '.(int) $run['id'], FALSE)->current();

                if ($fresh)
                {
                    $run = (array) $fresh;
                }
            }
        }

        if ((string) $run['status'] !== self::STATUS_QUEUED OR (int) ($run['job_id'] ?? 0) <= 0)
        {
            return $run;
        }

        // Живой этап воркера («Модель рисует 40 с») — статус-ряд страницы
        // показывает прогресс, а не безликое «Генерирую…». Ошибка очереди
        // прогресс не ломает: прогон просто отдаётся без этапа.
        $live = self::live_of((int) $run['job_id']);

        if ($live !== NULL)
        {
            $run['stage']       = (string) ($live['stage'] ?? '');
            $run['stages']      = (array) ($live['stages'] ?? []);
            $run['elapsed_sec'] = (int) ($live['elapsed_sec'] ?? 0);
            $run['silent_sec']  = $live['silent_sec'];
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return $run;
        }

        try
        {
            $job = Service_Nj120_JobQueue::instance()->get((int) $run['job_id']);
        }
        catch (Throwable $e)
        {
            return $run;
        }

        if ($job === NULL)
        {
            // Задачи нет вовсе (постановка не удалась или строку вычистили):
            // держать прогон вечно в «Генерирую…» нельзя — закрываем честно.
            $created = ! empty($run['created_at']) ? strtotime((string) $run['created_at']) : NULL;

            if ($created AND (time() - $created) > 600)
            {
                $db = self::_db();

                $db->query(Database::UPDATE,
                    "UPDATE nj_641_runs SET status = 'error',
                        error = 'Задача пропала из очереди — воркер её не взял. Запустите генерацию заново',
                        finished_at = now()
                     WHERE id = ".(int) $run['id']." AND status = '".self::STATUS_QUEUED."'", FALSE);

                $fresh = $db->query(Database::SELECT, 'SELECT * FROM nj_641_runs WHERE id = '.(int) $run['id'], FALSE)->current();

                return $fresh ? (array) $fresh : $run;
            }

            return $run;
        }

        $db = self::_db();

        if ($job['status'] === Service_Nj120_JobQueue::STATUS_DONE)
        {
            $res = $job['result'];
            is_string($res) AND $res = json_decode($res, TRUE);
            $res = is_array($res) ? $res : [];

            self::apply_result((int) $run['id'], $res);
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_FAILED)
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_641_runs SET status = 'error', error = ".$db->quote(mb_substr((string) $job['error'], 0, 900)).",
                 finished_at = now() WHERE id = ".(int) $run['id'], FALSE);
        }
        elseif (in_array($job['status'], [Service_Nj120_JobQueue::STATUS_PENDING, Service_Nj120_JobQueue::STATUS_PROCESSING], TRUE))
        {
            $started = $job['claimed_at'] ?: $job['created_at'];
            $limit   = (int) ($job['timeout_sec'] ?: 600) + 120;

            if ($started AND (time() - strtotime((string) $started)) > $limit)
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_641_runs SET status = 'error', error = 'Воркер не отдал результат за ".(int) round($limit / 60)." мин — генерация прервана. Проверьте image-API на воркере и попробуйте ещё раз',
                     finished_at = now() WHERE id = ".(int) $run['id'], FALSE);

                // Заодно снимаем мёртвую задачу с очереди, иначе она висит в
                // мониторе «Выполняется» и пугает владельца. Живую (пульс
                // свежее 4 минут) НЕ трогаем: воркер ещё может отдать картинку,
                // и apply_result примет её даже после ошибки.
                $beat = ! empty($job['heartbeat_at']) ? strtotime((string) $job['heartbeat_at']) : NULL;

                if (($beat === NULL OR (time() - $beat) > 240) AND (int) ($run['job_id'] ?? 0) > 0)
                {
                    try
                    {
                        Service_Nj120_JobQueue::instance()->cancel((int) $run['job_id'], TRUE);
                    }
                    catch (Throwable $e)
                    {
                        Kohana::$log->add(Log::WARNING, 'nj641: снять зависшую задачу не удалось: :e',
                            [':e' => $e->getMessage()]);
                    }
                }
            }
        }

        $row = $db->query(Database::SELECT, 'SELECT * FROM nj_641_runs WHERE id = '.(int) $run['id'], FALSE)->current();

        if ( ! $row)
        {
            return $run;
        }

        // Строка из базы НЕ знает про живое состояние воркера — возвращаем
        // этапы/таймер поверх неё (иначе страница показывала «Генерирую…» без
        // этапа, а таймер стоял на нуле).
        $fresh = (array) $row;

        foreach (['stage', 'stages', 'elapsed_sec', 'silent_sec'] AS $key)
        {
            if (array_key_exists($key, $run))
            {
                $fresh[$key] = $run[$key];
            }
        }

        return $fresh;
    }

    /**
     * Живое состояние задачи прогона (контракт статус-ряда /trends: status,
     * stage, stages[], elapsed_sec, silent_sec, error) или NULL, если задачи нет.
     */
    public static function live_of(int $job_id): ?array
    {
        if ($job_id <= 0 OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        try
        {
            $live = Service_Nj120_JobQueue::instance()
                ->live_status(self::CHANNEL_GENERATE, NULL, NULL, $job_id);
        }
        catch (Throwable $e)
        {
            return NULL;
        }

        return is_array($live) ? $live : NULL;
    }

    /** URL картинки прогона ('' — если картинки нет). */
    public static function image_url(array $run): string
    {
        $info = $run['image_info'] ?? NULL;
        is_string($info) AND $info = json_decode($info, TRUE);

        // скобки обязательны: без них AND слабее ?: и функция вернёт bool
        return (is_array($info) AND ! empty($info['url'])) ? (string) $info['url'] : '';
    }

    /**
     * Остановить свой прогон вручную (кнопка «Остановить» в статус-ряде).
     *
     * Задачу в очереди прерываем force-режимом (она может уже выполняться у
     * воркера), прогон помечаем cancelled. Тихо висящий процесс — это ровно то,
     * на что владелец жаловался: «процесс соответственно висит».
     *
     * @return bool TRUE — остановлен
     */
    public static function cancel_run(int $run_id, string $uid): bool
    {
        if ($run_id <= 0)
        {
            return FALSE;
        }

        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'SELECT id, visitor_uid, status, job_id FROM nj_641_runs WHERE id = '.(int) $run_id, FALSE)->current();

        if ( ! $row OR (string) $row['visitor_uid'] !== (string) $uid)
        {
            return FALSE;
        }

        if ((string) $row['status'] !== self::STATUS_QUEUED)
        {
            return FALSE; // уже завершён (готов/ошибка/остановлен)
        }

        if ((int) ($row['job_id'] ?? 0) > 0 AND class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                Service_Nj120_JobQueue::instance()->cancel((int) $row['job_id'], TRUE);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj641: прервать задачу в очереди не удалось: :e',
                    [':e' => $e->getMessage()]);
            }
        }

        $db->query(Database::UPDATE,
            "UPDATE nj_641_runs SET status = '".self::STATUS_CANCELLED."',
                error = 'Остановлено вручную', finished_at = now()
             WHERE id = ".(int) $run_id." AND status = '".self::STATUS_QUEUED."'", FALSE);

        return TRUE;
    }

    /**
     * Удалить свой прогон: строку и файл картинки.
     *
     * @return bool TRUE — удалён; FALSE — не найден или чужой
     */
    public static function delete_run(int $run_id, string $uid, bool $force = FALSE): bool
    {
        if ($run_id <= 0)
        {
            return FALSE;
        }

        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'SELECT id, visitor_uid, image_info FROM nj_641_runs WHERE id = '.(int) $run_id, FALSE)->current();

        // $force — модерация: администратор убирает чужое изображение
        // (страница «Изображения»). Владелец прогона удаляет своё как раньше.
        if ( ! $row OR ( ! $force AND (string) $row['visitor_uid'] !== (string) $uid))
        {
            return FALSE;
        }

        $info = $row['image_info'];
        is_string($info) AND $info = json_decode($info, TRUE);

        if (is_array($info) AND ! empty($info['file']))
        {
            $file = (string) $info['file'];

            // Путь бывает относительным (storage/nj641/…) и абсолютным
            // (воркер с своим NJ641_MEDIA_DIR) — поддержаны оба.
            $path = ($file !== '' AND $file[0] === '/')
                ? $file
                : DOCROOT.str_replace('/', DIRECTORY_SEPARATOR, $file);

            if (is_file($path))
            {
                @unlink($path);
            }
        }

        // вместе с прогоном убираем и его статус модерации (иначе строка
        // осталась бы в библиотеке мусором)
        if (self::_review_ok())
        {
            try
            {
                $db->query(Database::DELETE,
                    'DELETE FROM nj_641_review WHERE run_id = '.(int) $run_id, FALSE);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj641: статус модерации не удалён: :e', [':e' => $e->getMessage()]);
            }
        }

        $db->query(Database::DELETE,
            'DELETE FROM nj_641_runs WHERE id = '.(int) $run_id, FALSE);

        return TRUE;
    }

    /** Последние завершённые прогоны пользователя (картинки для истории). */
    public static function recent_runs(string $uid, int $limit = 12): array
    {
        $limit = max(1, min((int) $limit, 50));

        return self::_db()->query(Database::SELECT,
            'SELECT id, mode, prompt, width, height, status, model, duration_ms, error,
                    (image_b64 IS NOT NULL OR (image_info ->> \'url\') IS NOT NULL)::int AS has_image,
                    image_info ->> \'url\'   AS image_url,
                    image_info ->> \'bytes\' AS image_bytes,
                    created_at, finished_at
             FROM nj_641_runs
             WHERE visitor_uid = '.self::_db()->quote($uid).'
               AND (image_b64 IS NOT NULL OR (image_info ->> \'url\') IS NOT NULL
                    OR status IN ('.self::_db()->quote(self::STATUS_ERROR).', '.self::_db()->quote(self::STATUS_CANCELLED).'))
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /**
     * Приём результата от воркера (POST /imagegen/api/worker-result).
     * @param array $res ['status','image_b64','image_info','model','duration_ms','error']
     */
    public static function apply_result(int $run_id, array $res): bool
    {
        if ($run_id <= 0)
        {
            return FALSE;
        }

        $db = self::_db();

        // Идемпотентность колбэков: повторный вызов не перетирает готовый
        // результат. НО если прогон уже закрыт ошибкой, а картинка только
        // сейчас дошла (типовой сценарий «результат потерялся по дороге») —
        // принимаем её: иначе владелец так и не увидит сгенерированное.
        $existing = $db->query(Database::SELECT,
            'SELECT status, image_b64, image_info FROM nj_641_runs WHERE id = '.(int) $run_id, FALSE)->current();

        if ( ! $existing)
        {
            return FALSE;
        }

        $status = (string) ($res['status'] ?? self::STATUS_DONE);
        if ( ! in_array($status, [self::STATUS_DONE, self::STATUS_ERROR], TRUE))
        {
            $status = self::STATUS_DONE;
        }

        $image_b64  = (string) ($res['image_b64'] ?? '');
        // ВАЖНО: скобки обязательны — AND слабее ?:, без них выражение даёт bool
        // (живой случай 26.09: adopt_file → apply_result → array_merge(): true given).
        $image_info = (isset($res['image_info']) AND is_array($res['image_info'])) ? $res['image_info'] : [];

        $incoming_image = ($image_b64 !== '' OR ! empty($image_info['url']));

        $existing_info  = $existing['image_info'];
        is_string($existing_info) AND $existing_info = json_decode($existing_info, TRUE);
        // Скобки обязательны: OR слабее присваивания — без них картинка,
        // сохранённая только ссылкой, считалась отсутствующей, и результат
        // прогона мог перетереть готовое изображение.
        $has_image = ( ! empty($existing['image_b64'])
            OR (is_array($existing_info) AND ! empty($existing_info['url'])));

        if (in_array((string) $existing['status'], [self::STATUS_DONE, self::STATUS_ERROR], TRUE)
            AND ! ($incoming_image AND ! $has_image))
        {
            return TRUE;
        }

        if (strlen($image_b64) > 30 * 1024 * 1024)
        {
            $image_b64 = '';
            $status = self::STATUS_ERROR;
            $res['error'] = 'Картинка результата слишком большая.';
        }

        // Картинку пишем ФАЙЛОМ (public_html/storage/nj641), а не в TEXT базы:
        // base64 на 1.5-4 МБ — это и вес БД, и тормоза отдачи через JSON.
        $stored = NULL;

        // Файл уже сохранён (multipart-загрузка воркера): метаданные готовы.
        if ($status === self::STATUS_DONE AND isset($res['image_file']) AND is_array($res['image_file'])
            AND ! empty($res['image_file']['url']) AND ! empty($res['image_file']['file']))
        {
            $stored = $res['image_file'];
            $image_info = array_merge($image_info, $stored);
        }
        elseif ($status === self::STATUS_DONE AND $image_b64 !== '')
        {
            $stored = self::store_image($run_id, $image_b64, (string) ($image_info['media_type'] ?? ''));

            if ($stored !== NULL)
            {
                $image_info = array_merge($image_info, $stored);
            }
        }

        // Источник доставки (pg / upload / json) — диагностика для следующей
        // жалобы «где потерялось»: виден прямо в image_info прогона.
        if ( ! empty($res['source']))
        {
            $image_info['source'] = (string) $res['source'];
        }

        // «Готово» без картинки — это не результат: честная ошибка вместо
        // пустого места в интерфейсе.
        if ($status === self::STATUS_DONE AND $stored === NULL AND $image_b64 === ''
            AND empty($image_info['url']))
        {
            $status = self::STATUS_ERROR;
            $image_info['error'] = 'Воркер не передал картинку (пустой результат).';
        }

        $set_image = ($image_b64 !== '' AND $stored === NULL)
            ? ", image_b64 = ".$db->quote($image_b64)
            : '';

        $set_image_info = $image_info
            ? ', image_info = '.$db->quote(json_encode($image_info, JSON_UNESCAPED_UNICODE)).'::jsonb'
            : '';

        $db->query(Database::UPDATE,
            'UPDATE nj_641_runs SET
                status = '.$db->quote($status)
                .$set_image
                .$set_image_info
                .', model = '.$db->quote(trim((string) ($res['model'] ?? '')))
                .', duration_ms = '.(int) ($res['duration_ms'] ?? 0)
                .', error = '.$db->quote(mb_substr(trim((string) ($res['error'] ?? '')), 0, 900))
                .', finished_at = now()
             WHERE id = '.(int) $run_id, FALSE);

        // Дожим очереди (образец nj602): картинка записана сайтом — задача nj120
        // становится «Готово», даже если воркер не успел complete() или свипер
        // отказал её за секунды до доставки. Только для успешного прогона.
        if ($status === self::STATUS_DONE)
        {
            try
            {
                $jrow = $db->query(Database::SELECT,
                    'SELECT job_id FROM nj_641_runs WHERE id = '.(int) $run_id, FALSE)->current();

                if (class_exists('Service_Nj120_JobQueue') AND $jrow AND (int) ($jrow['job_id'] ?? 0) > 0)
                {
                    Service_Nj120_JobQueue::instance()->mark_delivered(
                        (int) $jrow['job_id'],
                        'Изображение принято сайтом — задача закрыта из приёмки nj641',
                    );
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj641: связать задачу с готовым прогоном не удалось: :e',
                    [':e' => $e->getMessage()]);
            }
        }

        return TRUE;
    }

    // ------------------------------------------- модерация и библиотека (002)

    /**
     * Кто может модерировать: право `nj641.admin` (config/acl.php) — по умолчанию
     * роль admin. Тот же ключ проверяет страница /imagegen/gallery, поэтому
     * доступ страницы и API не расходятся.
     */
    public static function can_moderate(): bool
    {
        if ( ! class_exists('Auth'))
        {
            return FALSE;
        }

        try
        {
            if (Auth::instance()->logged_in('admin'))
            {
                return TRUE;
            }

            if (class_exists('ACL') AND Auth::instance()->logged_in('login'))
            {
                $roles = [];
                $user  = Auth::instance()->get_user();

                if ($user)
                {
                    foreach ($user->roles->find_all() AS $role)
                    {
                        $roles[] = $role->name;
                    }
                }

                return ACL::check($roles, 'nj641.admin');
            }
        }
        catch (Throwable $e)
        {
            return FALSE;
        }

        return FALSE;
    }

    /**
     * Хранилище модерации — ОТДЕЛЬНАЯ таблица nj_641_review.
     *
     * Почему не колонки в nj_641_runs (миграция 002): ADD COLUMN требует прав
     * владельца таблицы, и на проде (26.09) колонок так и не появилось — запрос
     * списка падал, а страница «Изображения» показывала «ничего нет». Таблицу
     * приложение создаёт САМО (CREATE TABLE доступен шире, чем ALTER чужой
     * таблицы, и этим же путём живут таблицы остальных модулей), поэтому
     * модерация работает сразу после обновления кода. Колонки из 002 остаются
     * в схеме как есть — код на них больше не опирается.
     */
    public static function ensure_review_table(): void
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        $done = TRUE;

        $stmts = [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_641_review" (
    "run_id"      BIGINT       PRIMARY KEY,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'pending',
    "reviewed_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "reviewed_by" VARCHAR(40)  NOT NULL DEFAULT ''
)
SQL,
            'CREATE INDEX IF NOT EXISTS "nj_641_review_status_idx" ON "nj_641_review" ("status", "run_id" DESC)',
        ];

        foreach ($stmts AS $sql)
        {
            try
            {
                DB::query(NULL, $sql)->execute();
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj641: хранилище модерации: :e', [':e' => $e->getMessage()]);
            }
        }
    }

    /** Статусы модерации, которые принимает API. */
    protected static function _review_statuses(): array
    {
        return [self::REVIEW_PENDING, self::REVIEW_APPROVED, self::REVIEW_REJECTED];
    }

    /**
     * Есть ли в таблице колонки модерации.
     *
     * NULL — ещё не знаем, TRUE/FALSE — знаем. Страница «Изображения» обязана
     * показывать картинки даже там, где миграцию 002 не применили и прав на
     * ALTER нет: тогда она работает в упрощённом режиме (всё «На модерации»),
     * а не отдаёт пустой список (живой случай 26.09: «картинка сгенерировалась,
     * а на странице ничего нет»).
     */
    protected static $_review_ok = NULL;

    /** Причина упрощённого режима ('' — всё штатно) — показывается на странице. */
    protected static $_library_note = '';

    /** Колонки модерации доступны? (дешёвая проверка каталога, без DDL) */
    protected static function _review_ok()
    {
        if (self::$_review_ok !== NULL)
        {
            return self::$_review_ok;
        }

        self::ensure_review_table();
        self::$_review_ok = self::_review_store_exists();

        // хранилища нет — в списке всё равно покажем картинки (упрощённый режим)
        if ( ! self::$_review_ok)
        {
            self::$_library_note = 'Хранилище модерации ещё не создано — список показан в упрощённом режиме, '
                .'все картинки «На модерации». Администратору: примените миграции модуля 641 (/migrate_pg).';

            Kohana::$log->add(Log::WARNING, 'nj641: нет таблицы nj_641_review — упрощённый режим списка изображений');
        }

        return self::$_review_ok;
    }

    protected static function _review_store_exists(): bool
    {
        try
        {
            $row = DB::query(Database::SELECT,
                "SELECT 1 FROM information_schema.tables
                  WHERE table_name = 'nj_641_review'", FALSE)->current();

            return (bool) $row;
        }
        catch (Throwable $e)
        {
            // каталог недоступен — считаем, что хранилище есть (штатный путь),
            // ошибку покажет сам запрос списка
            return TRUE;
        }
    }

    /** Колонки модерации недоступны: запоминаем причину и уходим в упрощённый режим. */
    protected static function _review_downgrade(Throwable $e)
    {
        self::$_review_ok = FALSE;
        self::$_library_note = 'Хранилище модерации недоступно — список показан в упрощённом режиме, '
            .'все картинки «На модерации». Администратору: примените миграции модуля 641 (/migrate_pg).';

        Kohana::$log->add(Log::WARNING, 'nj641: хранилище модерации недоступно, упрощённый режим: :e',
            [':e' => $e->getMessage()]);
    }

    /** Подсказка странице, почему список упрощённый ('' — всё штатно). */
    public static function library_note(): string
    {
        return self::$_library_note;
    }

    /** Диагностика пустой страницы: сколько прогонов и сколько с картинкой. */
    public static function library_diag(): array
    {
        self::_review_ok();

        $diag = ['runs' => 0, 'with_image' => 0, 'review_store' => (bool) self::$_review_ok];

        try
        {
            $row = self::_db()->query(Database::SELECT,
                "SELECT count(*) AS runs,
                        count(*) FILTER (WHERE image_b64 IS NOT NULL
                                          OR (image_info ->> 'url') IS NOT NULL) AS with_image
                   FROM nj_641_runs", FALSE)->current();

            if ($row)
            {
                $diag['runs']       = (int) $row['runs'];
                $diag['with_image'] = (int) $row['with_image'];
            }
        }
        catch (Throwable $e)
        {
            $diag['error'] = mb_substr($e->getMessage(), 0, 200);
        }

        return $diag;
    }

    /** Поля модерации в SELECT (в упрощённом режиме — «все на модерации»). */
    protected static function _review_select_sql(): string
    {
        return self::_review_ok()
            ? "COALESCE(rv.status, 'pending') AS review_status, rv.reviewed_at, rv.reviewed_by"
            : "'".self::REVIEW_PENDING."' AS review_status, NULL::timestamptz AS reviewed_at, ''::varchar AS reviewed_by";
    }

    /** JOIN хранилища модерации ('' — если его нет). */
    protected static function _review_join_sql(): string
    {
        return self::_review_ok() ? ' LEFT JOIN nj_641_review rv ON rv.run_id = r.id' : '';
    }

    /** WHERE списка изображений: только прогоны с картинкой + поиск + статус. */
    protected static function _library_where($q, $review)
    {
        $db = self::_db();

        $where = "(r.image_b64 IS NOT NULL OR (r.image_info ->> 'url') IS NOT NULL)";

        // фильтр по статусу — только когда хранилище модерации реально есть
        if (self::_review_ok() AND in_array((string) $review, self::_review_statuses(), TRUE))
        {
            $where .= " AND COALESCE(rv.status, 'pending') = ".$db->quote((string) $review);
        }

        $q = trim((string) $q);

        if ($q !== '')
        {
            $like = $db->quote('%'.$q.'%');

            $where .= ' AND (r.prompt ILIKE '.$like.' OR r.mode ILIKE '.$like.' OR r.model ILIKE '.$like;

            if (ctype_digit($q))
            {
                $where .= ' OR r.id = '.(int) $q;
            }

            $where .= ')';
        }

        return $where;
    }

    /**
     * Список изображений для модерации (страница /imagegen/gallery).
     *
     * @return array строки: id, mode, prompt, image_url, image_bytes, review_*, created_at
     */
    public static function library_list($q = '', $review = '', $limit = 20, $offset = 0)
    {
        self::_review_ok();

        $limit  = max(1, min((int) $limit, 100));
        $offset = max(0, (int) $offset);

        try
        {
            return self::_library_list_rows($q, $review, $limit, $offset);
        }
        catch (Throwable $e)
        {
            // Первый запрос не прошёл (например, колонок модерации нет):
            // уходим в упрощённый режим и повторяем — пустой страницы не будет.
            if (self::$_review_ok)
            {
                self::_review_downgrade($e);
            }
            else
            {
                Kohana::$log->add(Log::WARNING, 'nj641: список изображений: :e', [':e' => $e->getMessage()]);
            }
        }

        try
        {
            return self::_library_list_rows($q, $review, $limit, $offset);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj641: список изображений: :e', [':e' => $e->getMessage()]);

            return [];
        }
    }

    protected static function _library_list_rows($q, $review, $limit, $offset): array
    {
        return self::_db()->query(Database::SELECT,
            "SELECT r.id, r.visitor_uid, r.mode, r.prompt, r.width, r.height, r.model, r.duration_ms,
                    r.image_info ->> 'url'   AS image_url,
                    r.image_info ->> 'bytes' AS image_bytes,
                    ".self::_review_select_sql().", r.created_at
               FROM nj_641_runs r".self::_review_join_sql()."
              WHERE ".self::_library_where($q, $review)."
              ORDER BY r.id DESC
              LIMIT ".$limit.' OFFSET '.$offset, FALSE)->as_array();
    }

    /** Сколько изображений под фильтром (пагинация). */
    public static function library_count($q = '', $review = ''): int
    {
        self::_review_ok();

        try
        {
            return self::_library_count_rows($q, $review);
        }
        catch (Throwable $e)
        {
            if (self::$_review_ok)
            {
                self::_review_downgrade($e);
            }
        }

        try
        {
            return self::_library_count_rows($q, $review);
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    protected static function _library_count_rows($q, $review): int
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS n FROM nj_641_runs r'.self::_review_join_sql()
            .' WHERE '.self::_library_where($q, $review), FALSE)->current();

        return (int) ($row['n'] ?? 0);
    }

    /** Счётчики по статусам — чипы страницы «Изображения». */
    public static function library_counts(): array
    {
        self::_review_ok();

        try
        {
            return self::_library_counts_rows();
        }
        catch (Throwable $e)
        {
            if (self::$_review_ok)
            {
                self::_review_downgrade($e);
            }
        }

        try
        {
            return self::_library_counts_rows();
        }
        catch (Throwable $e)
        {
            return ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
        }
    }

    protected static function _library_counts_rows(): array
    {
        $has_image = "(r.image_b64 IS NOT NULL OR (r.image_info ->> 'url') IS NOT NULL)";
        $from      = 'FROM nj_641_runs r'.self::_review_join_sql().' WHERE '.$has_image;

        if (self::_review_ok())
        {
            $sql = "SELECT count(*) AS all_n,
                           count(*) FILTER (WHERE COALESCE(rv.status, 'pending') = 'pending')  AS pending_n,
                           count(*) FILTER (WHERE COALESCE(rv.status, 'pending') = 'approved') AS approved_n,
                           count(*) FILTER (WHERE COALESCE(rv.status, 'pending') = 'rejected') AS rejected_n
                      ".$from;
        }
        else
        {
            $sql = "SELECT count(*) AS all_n, count(*) AS pending_n,
                           0 AS approved_n, 0 AS rejected_n
                      ".$from;
        }

        $row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

        if ( ! $row)
        {
            return ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
        }

        return [
            'all'      => (int) $row['all_n'],
            'pending'  => (int) $row['pending_n'],
            'approved' => (int) $row['approved_n'],
            'rejected' => (int) $row['rejected_n'],
        ];
    }

    /**
     * Модерация одного изображения: pending (вернуть на проверку), approved
     * (в библиотеку), rejected (отклонить).
     */
    public static function review_run($run_id, $status, $uid = ''): bool
    {
        $run_id = (int) $run_id;

        if ($run_id <= 0 OR ! in_array((string) $status, self::_review_statuses(), TRUE))
        {
            return FALSE;
        }

        // Хранилища модерации нет — не молчим: страница покажет причину,
        // API вернёт понятную ошибку вместо «не найдено».
        if ( ! self::_review_ok())
        {
            return FALSE;
        }

        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            "SELECT id FROM nj_641_runs
              WHERE id = ".$run_id."
                AND (image_b64 IS NOT NULL OR (image_info ->> 'url') IS NOT NULL)", FALSE)->current();

        if ( ! $row)
        {
            return FALSE;
        }

        try
        {
            $db->query(NULL,
                "INSERT INTO nj_641_review (run_id, status, reviewed_at, reviewed_by)
                 VALUES (".$run_id.", ".$db->quote((string) $status).", now(), "
                    .$db->quote(mb_substr((string) $uid, 0, 40)).")
                 ON CONFLICT (run_id) DO UPDATE
                    SET status = EXCLUDED.status,
                        reviewed_at = now(),
                        reviewed_by = EXCLUDED.reviewed_by")->execute();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj641: модерация не сохранилась: :e', [':e' => $e->getMessage()]);

            return FALSE;
        }

        return TRUE;
    }

    /**
     * Пакетная модерация (bulk-кнопки страницы «Изображения»).
     * @return int сколько изображений реально изменилось
     */
    public static function review_many(array $ids, $status, $uid = ''): int
    {
        if ( ! in_array((string) $status, self::_review_statuses(), TRUE))
        {
            return 0;
        }

        if ( ! self::_review_ok())
        {
            return 0;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; })));

        if ( ! $ids)
        {
            return 0;
        }

        $db   = self::_db();
        $list = implode(',', $ids);

        $rows = $db->query(Database::SELECT,
            "SELECT id FROM nj_641_runs
              WHERE id IN (".$list.")
                AND (image_b64 IS NOT NULL OR (image_info ->> 'url') IS NOT NULL)", FALSE)->as_array();

        if ( ! $rows)
        {
            return 0;
        }

        $ok_ids = [];

        foreach ($rows AS $r)
        {
            $ok_ids[] = (int) $r['id'];
        }

        $ok_list = implode(',', $ok_ids);

        try
        {
            $db->query(NULL,
                "INSERT INTO nj_641_review (run_id, status, reviewed_at, reviewed_by)
                 SELECT id, ".$db->quote((string) $status).", now(), "
                    .$db->quote(mb_substr((string) $uid, 0, 40))."
                   FROM nj_641_runs
                  WHERE id IN (".$ok_list.")
                    AND (image_b64 IS NOT NULL OR (image_info ->> 'url') IS NOT NULL)
                 ON CONFLICT (run_id) DO UPDATE
                    SET status = EXCLUDED.status,
                        reviewed_at = now(),
                        reviewed_by = EXCLUDED.reviewed_by")->execute();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj641: пакетная модерация не сохранилась: :e', [':e' => $e->getMessage()]);

            return 0;
        }

        return count($ok_ids);
    }

    /**
     * Библиотека для других сервисов: одобренные изображения (по умолчанию 12).
     * Статьи и презентации берут картинку отсюда, а не из истории пользователя.
     */
    public static function library_pick($limit = 12, $offset = 0, $q = '')
    {
        return self::library_list($q, self::REVIEW_APPROVED, $limit, $offset);
    }

    // ------------------------------------------------------------ служебное

    /** Автосоздание таблицы (только при NJ641_SCHEMA_AUTOCREATE=1, как nj640). */
    public static function ensure_tables()
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        $done = TRUE;

        if (trim((string) getenv('NJ641_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        DB::query(NULL, <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_641_runs" (
    "id"                BIGSERIAL    PRIMARY KEY,
    "visitor_uid"       VARCHAR(40)  NOT NULL DEFAULT '',
    "client_ip"         VARCHAR(64)  NOT NULL DEFAULT '',
    "mode"              VARCHAR(16)  NOT NULL DEFAULT 'generate',
    "prompt"            TEXT         NOT NULL DEFAULT '',
    "negative_prompt"   TEXT         NOT NULL DEFAULT '',
    "width"             INTEGER      NOT NULL DEFAULT 1024,
    "height"            INTEGER      NOT NULL DEFAULT 1024,
    "seed"              BIGINT       NOT NULL DEFAULT 0,
    "strength"          NUMERIC(4,3) NULL,
    "image_b64"         TEXT         NULL,
    "image_info"        JSONB        NULL,
    "status"            VARCHAR(12)  NOT NULL DEFAULT 'queued',
    "model"             VARCHAR(160) NULL,
    "duration_ms"       INTEGER      NOT NULL DEFAULT 0,
    "error"             TEXT         NULL,
    "job_id"            BIGINT       NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"       TIMESTAMPTZ  NULL,
    "review_status"     VARCHAR(12)  NOT NULL DEFAULT 'pending',
    "reviewed_at"       TIMESTAMPTZ  NULL,
    "reviewed_by"       VARCHAR(40)  NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS "nj_641_runs_uid_idx" ON "nj_641_runs" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_641_runs_ip_idx"  ON "nj_641_runs" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_641_runs_review_idx" ON "nj_641_runs" ("review_status", "id" DESC);
SQL
        )->execute();
    }
}
