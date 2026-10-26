<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Vision — общий механизм сервисов «фото → vision-модель (VLM)».
 *
 * ЗАЧЕМ ОН ЕСТЬ. Первым фото к модели научился отправлять «Разбор состава»
 * (nj646_label): кадр ложится файлом в приватный каталог, в очередь nj120
 * уходит только id прогона, воркер забирает кадр отдельным запросом, отвечает
 * на приёмку сайта, а страница показывает общий живой статус-ряд. Механизм
 * проверен вживую на /label, и владелец (04.10.2026) попросил ещё три таких
 * сервиса — «их будет много». Переписывать те же 700 строк в каждом модуле
 * нельзя: чинить пришлось бы в четырёх местах.
 *
 * ЧТО ЗДЕСЬ. Только то, что ОДИНАКОВО у всех VLM-сервисов:
 *   * проверка кадра (JPEG/PNG/WebP), приватное хранение и чистка кадров;
 *   * прогон в таблице модуля `nj_<id>_runs` общей схемы: постановка задачи,
 *     приёмка результата, дожим из очереди, живой статус-ряд;
 *   * готовность vision-провайдера и токен воркера.
 *
 * ЧЕГО ЗДЕСЬ НЕТ и быть не должно: смысла сервиса. Промпт, разбор ответа
 * модели и показ результата — в самом модуле (`Service_Nj650_Receipt` и т. п.),
 * иначе фасад превратится в свалку чужой логики.
 *
 * ПОЧЕМУ УРОВЕНЬ ПРИЛОЖЕНИЯ, А НЕ МОДУЛЬ. Правило проекта (AGENTS §2.1):
 * модуль не читает чужие таблицы и не подключает чужие view. Поэтому общего
 * «модуля-витрины» нет: КОД общий (здесь, рядом со `Storage`, `Manifest`,
 * `Visitor`), а ДАННЫЕ у каждого свои — таблица `nj_<id>_runs` создаётся
 * миграцией самого модуля, и имя таблицы модуль передаёт сюда параметром.
 *
 * Схема таблицы прогонов (одинакова у всех; создаётся миграцией модуля):
 *   id bigserial, user_id int NULL, visitor varchar(64), client_ip varchar(64),
 *   job_id bigint, status varchar(16) (queued|running|done|error|cancelled),
 *   title varchar(200), model varchar(160), elapsed_ms int, error text,
 *   result jsonb, created_at timestamptz, finished_at timestamptz.
 *
 * @see Controller_Vision_Base общие точки API (photo/status/photo_file/worker_result)
 * @see workers/n2j_workers/vlm.py та же половина работы на стороне воркера
 */
class Vision
{
    /** Статусы прогона (status в таблице модуля). */
    const STATUSES = ['queued', 'running', 'done', 'error', 'cancelled'];

    /** Сколько живёт кадр, если задача умерла вместе с воркером, секунд. */
    const PHOTO_TTL = 21600;

    // ------------------------------------------------------------- окружение

    /**
     * Настроен ли хоть один LLM-провайдер (значит, воркер сможет спросить VLM).
     *
     * Страница обязана сказать об этом ЧЕСТНО и заранее: без провайдера фото
     * уедет в очередь и будет вечно «распознаваться».
     */
    public static function ready()
    {
        try
        {
            foreach ((array) Kohana::$config->load('llm.providers') as $provider)
            {
                $url = is_array($provider) ? (string) ($provider['base_url'] ?? '') : '';
                $key = is_array($provider) ? (string) ($provider['api_key'] ?? '') : '';

                if ($url !== '' AND $key !== '')
                {
                    return TRUE;
                }
            }
        }
        catch (Throwable $e)
        {
            // конфиг LLM не критичен для страницы
        }

        return FALSE;
    }

    /** Пришёл ли запрос от воркера (общий WORKER_API_TOKEN). */
    public static function worker_token_ok()
    {
        $token = (string) ($_SERVER['HTTP_X_WORKER_TOKEN'] ?? '');
        $want  = (string) getenv('WORKER_API_TOKEN');

        return ($want !== '' AND $token !== '' AND hash_equals($want, $token));
    }

    // ----------------------------------------------------------------- кадры

    /** Это похоже на изображение? Сигнатура, а не имя файла из браузера. */
    public static function looks_like_image($bytes)
    {
        $bytes = (string) $bytes;

        return (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0            // JPEG
            OR strncmp($bytes, "\x89PNG", 4) === 0                  // PNG
            OR (strncmp($bytes, 'RIFF', 4) === 0 AND substr($bytes, 8, 4) === 'WEBP'));
    }

    /** MIME кадра по сигнатуре. */
    public static function mime($bytes)
    {
        $bytes = (string) $bytes;

        if (strncmp($bytes, "\x89PNG", 4) === 0)
        {
            return 'image/png';
        }

        if (strncmp($bytes, 'RIFF', 4) === 0 AND substr($bytes, 8, 4) === 'WEBP')
        {
            return 'image/webp';
        }

        return 'image/jpeg';
    }

    /**
     * data URL или «голый» base64 → байты. Пустая строка — не разобрали.
     *
     * Браузер шлёт `data:image/jpeg;base64,…` (canvas.toDataURL), сторонние
     * клиенты — чистый base64: принимаем оба вида, иначе сервис работает
     * только из нашей страницы.
     */
    public static function decode($raw)
    {
        $raw = (string) $raw;

        if ($raw === '')
        {
            return '';
        }

        if (strncmp($raw, 'data:', 5) === 0)
        {
            $comma = strpos($raw, ',');
            $raw   = $comma !== FALSE ? substr($raw, $comma + 1) : '';
        }

        $bytes = base64_decode(preg_replace('/\s+/', '', $raw), TRUE);

        return $bytes === FALSE ? '' : $bytes;
    }

    /**
     * Приватный каталог кадров модуля (вне docroot, в .gitignore).
     *
     * Почему файл, а не payload задачи: base64 кадра (8 МБ → ~10,7 МБ) ложился
     * бы в jsonb ОБЩЕЙ очереди проекта, его тянул бы каждый опрос воркера, а
     * мёртвая задача держала бы фото вечно (урок nj646). Воркер работает с
     * другого хоста и забирает кадр отдельным запросом по токену.
     */
    public static function photo_dir($module)
    {
        $dir = APPPATH.'cache/'.self::_safe_module($module).'_photo';

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0775, TRUE);
        }

        return $dir;
    }

    /** Положить кадр прогона. Возвращает расширение или '' (не смогли). */
    public static function photo_save($module, $run_id, $bytes)
    {
        $run_id = (int) $run_id;
        $bytes  = (string) $bytes;

        if ($run_id <= 0 OR $bytes === '')
        {
            return '';
        }

        $ext = ['image/png' => 'png', 'image/webp' => 'webp'][self::mime($bytes)] ?? 'jpg';

        self::photo_drop($module, $run_id);

        $path = self::photo_dir($module).'/'.$run_id.'.'.$ext;

        return @file_put_contents($path, $bytes) !== FALSE ? $ext : '';
    }

    /** Кадр прогона: [байты, mime] или [NULL, ''] — файла нет. */
    public static function photo_bytes($module, $run_id)
    {
        $run_id = (int) $run_id;
        $found  = $run_id > 0 ? glob(self::photo_dir($module).'/'.$run_id.'.*') : [];

        if ( ! $found)
        {
            return [NULL, ''];
        }

        $bytes = @file_get_contents($found[0]);

        if ($bytes === FALSE OR $bytes === '')
        {
            return [NULL, ''];
        }

        return [$bytes, self::mime($bytes)];
    }

    /** Убрать кадр: результат уже на сайте, само фото больше не нужно. */
    public static function photo_drop($module, $run_id)
    {
        $run_id = (int) $run_id;

        if ($run_id <= 0)
        {
            return;
        }

        foreach ((array) glob(self::photo_dir($module).'/'.$run_id.'.*') as $file)
        {
            @unlink($file);
        }
    }

    /**
     * Подчистить давние кадры: задача могла умереть вместе с воркером.
     *
     * Зовётся при приёме нового фото (не по крону: отдельная ручка ради
     * файлов, которых обычно нет, — работа впустую). Ограничена `$limit`
     * файлами, чтобы приём фото не ждал каталог в тысячи записей.
     */
    public static function photo_gc($module, $max_age_sec = self::PHOTO_TTL, $limit = 40)
    {
        $gone = 0;

        foreach ((array) glob(self::photo_dir($module).'/*') as $file)
        {
            if ($gone >= $limit)
            {
                break;
            }

            if (is_file($file) AND (time() - (int) @filemtime($file)) > (int) $max_age_sec)
            {
                @unlink($file);
                $gone++;
            }
        }

        return $gone;
    }

    // --------------------------------------------------------------- прогоны

    /**
     * Создать прогон. Возвращает id или 0.
     *
     * @param string $table nj_<id>_runs — имя таблицы МОДУЛЯ (код, не ввод)
     */
    public static function run_create($table, array $data)
    {
        $table = self::_safe_table($table);
        $db    = self::db();

        $sql = 'INSERT INTO '.$table.' (user_id, visitor, client_ip, title, status)
                VALUES (
                    '.((isset($data['user_id']) AND $data['user_id'] !== NULL) ? (int) $data['user_id'] : 'NULL').',
                    '.$db->quote((string) ($data['visitor'] ?? '')).',
                    '.$db->quote((string) ($data['client_ip'] ?? '')).',
                    '.$db->quote(mb_substr((string) ($data['title'] ?? ''), 0, 200)).',
                    \'queued\') RETURNING id';

        $row = $db->query(Database::SELECT, $sql, FALSE)->current();

        return $row ? (int) $row['id'] : 0;
    }

    public static function run_set_job($table, $id, $job_id)
    {
        DB::query(NULL, 'UPDATE '.self::_safe_table($table).'
                         SET job_id = '.(int) $job_id.' WHERE id = '.(int) $id)->execute();
    }

    /** Прогон по id (result уже массивом) или NULL. */
    public static function run_get($table, $id)
    {
        $row = self::db()
            ->query(Database::SELECT, 'SELECT * FROM '.self::_safe_table($table).'
                                       WHERE id = '.(int) $id.' LIMIT 1', FALSE)
            ->current();

        if ( ! $row)
        {
            return NULL;
        }

        $row            = (array) $row;
        $row['result']  = self::json_decode($row['result'] ?? NULL);

        return $row;
    }

    /**
     * Проверить владение прогоном до чтения его статуса/результата или файла.
     *
     * Если у записи уже есть user_id, доступ получает только тот же аккаунт;
     * совпадения браузерной метки недостаточно. Старые/анонимные записи без
     * user_id доступны только из того же браузера. Это правило общее для
     * страниц результата, API статуса и приватных миниатюр.
     */
    public static function run_owned(array $run, $visitor, $user_id = NULL)
    {
        $run_user_id = (int) ($run['user_id'] ?? 0);
        $user_id     = (int) $user_id;

        if ($run_user_id > 0)
        {
            return $user_id > 0 AND $run_user_id === $user_id;
        }

        $run_visitor = (string) ($run['visitor'] ?? '');
        $visitor     = (string) $visitor;

        return $run_visitor !== '' AND $visitor !== '' AND hash_equals($run_visitor, $visitor);
    }

    /**
     * Записать результат прогона. Идемпотентно: повторный POST не ломает.
     *
     * ВНИМАНИЕ: result — jsonb, а `DB::update()->set()` экранирует значение ещё
     * раз. Поэтому значение уходит через `DB::expr()` с уже закавыченным
     * литералом — иначе PostgreSQL отвечает «invalid input syntax for type
     * json», приёмка падает с 500, и страница навсегда остаётся в «Распознаю…»
     * (грабля nj645 и nj646, повторять её в четвёртый раз незачем).
     *
     * @return string итоговый статус прогона
     */
    public static function run_apply($table, $id, array $result)
    {
        $table  = self::_safe_table($table);
        $status = (string) ($result['status'] ?? 'done');

        if ( ! in_array($status, ['done', 'error', 'cancelled'], TRUE))
        {
            $status = 'done';
        }

        $result = self::_fit($result);

        $set = [
            'status'      => $status,
            'result'      => DB::expr(self::db()->quote(json_encode($result, JSON_UNESCAPED_UNICODE))),
            'error'       => mb_substr((string) ($result['error'] ?? ''), 0, 1000),
            'model'       => mb_substr((string) ($result['model'] ?? ''), 0, 160),
            'elapsed_ms'  => (int) ($result['elapsed_ms'] ?? 0),
            'finished_at' => DB::expr('now()'),
        ];

        $title = trim((string) ($result['title'] ?? ''));

        if ($title !== '')
        {
            $set['title'] = mb_substr($title, 0, 200);
        }

        DB::update($table)->set($set)->where('id', '=', (int) $id)->execute();

        return $status;
    }

    /**
     * Свежее состояние прогона + честная подсказка, если он не двигается.
     *
     * Это ДОЖИМ: страница опрашивает таблицу модуля, а заполняет её только
     * приёмка сайта. Если доставка не прошла (нет токена у воркера, сеть, 500),
     * прогон висел бы «распознаётся» вечно, хотя работа сделана и лежит в
     * `nj_120_jobs.result`. Поэтому результат при необходимости забирается из
     * задачи очереди.
     *
     * @param  string $channel канал очереди — для подсказки про WORKER_CHANNELS
     * @return array ['run' => array|NULL, 'hint' => string]
     */
    public static function run_watch($table, $id, $channel = '')
    {
        $run = self::run_get($table, $id);

        if ( ! $run)
        {
            return ['run' => NULL, 'hint' => ''];
        }

        $hint = '';

        try
        {
            $job = self::_job_of($run);

            if ($job)
            {
                $run = self::_catch_up($table, $run, $job);
            }

            if ($job AND in_array((string) $run['status'], ['queued', 'running'], TRUE))
            {
                $waited = time() - strtotime((string) ($job['created_at'] ?? 'now'));

                if ((string) ($job['status'] ?? '') === 'pending' AND empty($job['claimed_at']) AND $waited > 150)
                {
                    $hint = 'Задача в очереди уже больше 2 минут, но её никто не взял: '
                          .'проверьте, что воркер запущен'
                          .($channel !== '' ? ', а канал '.$channel.' есть в WORKER_CHANNELS' : '').'.';
                }
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'vision: дожим прогона :i (:t): :e',
                [':i' => (int) $id, ':t' => $table, ':e' => $e->getMessage()]);
        }

        return ['run' => $run, 'hint' => $hint];
    }

    /**
     * Живое состояние прогона — контракт ОБЩЕГО статус-ряда платформы
     * (`partials/live_status`): спиннер, этап воркера, секундомер, «нет пульса».
     *
     * @return array status|stage|stages|elapsed_sec|silent_sec|error|note|attempts
     */
    public static function run_live(array $run)
    {
        $status   = (string) ($run['status'] ?? 'queued');
        $created  = ! empty($run['created_at']) ? strtotime((string) $run['created_at']) : time();
        $finished = ! empty($run['finished_at']) ? strtotime((string) $run['finished_at']) : NULL;

        $out = [
            'job_id'      => (int) ($run['job_id'] ?? 0),
            'status'      => ['queued' => 'queued', 'running' => 'running', 'done' => 'done',
                              'error' => 'failed', 'cancelled' => 'cancelled'][$status] ?? 'queued',
            'stage'       => '',
            'stages'      => [],
            'elapsed_sec' => max(0, ($finished ?: time()) - $created),
            'silent_sec'  => NULL,
            'error'       => trim((string) ($run['error'] ?? '')),
            'note'        => '',
            'attempts'    => 0,
        ];

        if ($out['job_id'] <= 0 OR ! in_array($status, ['queued', 'running'], TRUE)
            OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return $out;
        }

        try
        {
            $live = Service_Nj120_JobQueue::instance()->status_of($out['job_id']);

            if ( ! empty($live['found']))
            {
                $out['stage']       = (string) ($live['stage'] ?? '');
                $out['stages']      = (array) ($live['stages'] ?? []);
                $out['elapsed_sec'] = max($out['elapsed_sec'], (int) ($live['elapsed_sec'] ?? 0));
                $out['silent_sec']  = $live['silent_sec'] ?? NULL;
                $out['note']        = (string) ($live['note'] ?? '');
                $out['attempts']    = (int) ($live['attempts'] ?? 0);

                if ($out['error'] === '')
                {
                    $out['error'] = (string) ($live['error'] ?? '');
                }

                // Задача уже отказалась, а прогон ещё «running» — ряд не врёт.
                if (in_array((string) $live['status'], ['failed', 'cancelled', 'done'], TRUE))
                {
                    $out['status'] = (string) $live['status'];
                }
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'vision: живой статус прогона :i: :e',
                [':i' => (int) ($run['id'] ?? 0), ':e' => $e->getMessage()]);
        }

        return $out;
    }

    /** Сколько прогонов с этого IP за последний час (лимит на распознавание). */
    public static function runs_last_hour($table, $client_ip)
    {
        $row = self::db()
            ->query(Database::SELECT, 'SELECT COUNT(*) AS c FROM '.self::_safe_table($table).'
                                       WHERE client_ip = '.self::db()->quote((string) $client_ip)."
                                         AND created_at > now() - interval '1 hour'", FALSE)
            ->current();

        return (int) ($row['c'] ?? 0);
    }

    /** Последние прогоны посетителя — «мои разборы» на странице сервиса. */
    public static function runs_mine($table, $visitor, $limit = 6, $user_id = NULL)
    {
        $visitor = (string) $visitor;
        $user_id = (int) $user_id;
        $where   = [];

        // История должна совпадать с run_owned(): аккаунтные записи доступны
        // только аккаунту, а анонимные — только тому же браузеру. При входе
        // показываем и старые анонимные записи этого браузера, но не чужие
        // аккаунтные записи с совпавшей visitor-меткой.
        if ($visitor !== '')
        {
            $where[] = '(visitor = '.self::db()->quote($visitor).' AND COALESCE(user_id, 0) = 0)';
        }

        if ($user_id > 0)
        {
            $where[] = 'user_id = '.$user_id;
        }

        if ( ! $where)
        {
            return [];
        }

        $rows = self::db()
            ->query(Database::SELECT, 'SELECT id, status, title, result, error, created_at
                                       FROM '.self::_safe_table($table).'
                                       WHERE '.implode(' OR ', $where).'
                                       ORDER BY id DESC LIMIT '.(int) max(1, min(20, $limit)), FALSE)
            ->as_array();

        foreach ($rows as $i => $row)
        {
            $rows[$i]           = (array) $row;
            $rows[$i]['result'] = self::json_decode($row['result'] ?? NULL);
        }

        return $rows;
    }

    /** Есть ли таблица прогонов (страница не должна падать до миграции). */
    public static function table_exists($table)
    {
        try
        {
            $row = self::db()
                ->query(Database::SELECT, 'SELECT to_regclass('
                    .self::db()->quote('public.'.self::_safe_table($table)).') AS t', FALSE)
                ->current();

            return ! empty($row['t']);
        }
        catch (Throwable $e)
        {
            return FALSE;
        }
    }

    // ------------------------------------------------------------- помощники

    public static function db()
    {
        static $db = NULL;

        return $db !== NULL ? $db : $db = Database::instance();
    }

    /** jsonb из базы → массив (драйвер отдаёт строку). */
    public static function json_decode($value)
    {
        if (is_array($value))
        {
            return $value;
        }

        $decoded = (is_string($value) AND $value !== '') ? json_decode($value, TRUE) : NULL;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Урезать результат до разумного размера перед записью в jsonb.
     *
     * Модель иногда возвращает простыню (длинные пояснения, повтор текста).
     * Пишем соразмерное и ЧЕСТНО помечаем обрезку: страница не должна выдавать
     * сокращённый ответ за полный.
     */
    protected static function _fit(array $result, $max_bytes = 262144)
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);

        if ($json !== FALSE AND strlen($json) <= $max_bytes)
        {
            return $result;
        }

        foreach ($result as $key => $value)
        {
            if (is_string($value) AND strlen($value) > 2000)
            {
                $result[$key] = mb_substr($value, 0, 2000);
            }
            elseif (is_array($value) AND count($value) > 60)
            {
                $result[$key] = array_slice($value, 0, 60);
            }
        }

        $result['_cut'] = TRUE;

        return $result;
    }

    /** Задача очереди, привязанная к прогону, или NULL. */
    protected static function _job_of(array $run)
    {
        $job_id = (int) ($run['job_id'] ?? 0);

        if ($job_id <= 0 OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        return Service_Nj120_JobQueue::instance()->get($job_id);
    }

    /**
     * Догнать прогон по задаче: done → применить результат, терминальный
     * отказ → показать ошибку. Незавершённую задачу не трогаем — её ведёт воркер.
     */
    protected static function _catch_up($table, array $run, array $job)
    {
        $plan = self::catch_up_plan($run, $job);

        if ($plan['action'] === 'none')
        {
            return $run;
        }

        if ($plan['action'] === 'fail')
        {
            $error = trim((string) ($job['error'] ?? ''));

            self::run_apply($table, (int) $run['id'], [
                'status' => 'error',
                'error'  => $error !== ''
                    ? 'Задача распознавания не выполнена: '.$error
                    : 'Задача распознавания не выполнена (причина не записана).',
            ]);

            return self::run_get($table, (int) $run['id']) ?: $run;
        }

        self::run_apply($table, (int) $run['id'], $plan['result']);

        return self::run_get($table, (int) $run['id']) ?: $run;
    }

    /**
     * Что делать с прогоном по его задаче — без единого запроса к БД.
     *
     * Вынесено из `_catch_up()` отдельным методом, потому что это ровно тот
     * случай, когда ошибку видно только на живой странице: правила решают,
     * затрём ли мы уже показанный результат.
     *
     *  none   — оставить прогон как есть;
     *  apply  — прогон не закрыт, а задача уже отдала результат;
     *  heal   — прогон закрыт, но хранит только сводку, а в задаче есть отчёт;
     *  fail   — задача провалена окончательно или снята — показать причину.
     *
     * ПОЧЕМУ НЕ ПЕРЕЗАПИСЫВАЕМ ТЕРМИНАЛЬНЫЙ ПРОГОН. Что доставил воркер, то и
     * показываем. Результат задачи — это ИТОГ ЗАДАЧИ, и он бывает короче
     * отчёта: 22.10.2026 `nj671.bench` доставил полный отчёт, страница при
     * первом же просмотре заменила его сводкой {winner, text_ok, mm_ok} и
     * написала владельцу «Ни один путь не ответил — причина не записана» при
     * полностью настроенных серверах. Обратный случай (сайт не получил ничего)
     * по-прежнему чинится: прогон ещё queued/running — его и догоняем.
     *
     * @return array action|result|note
     */
    public static function catch_up_plan(array $run, array $job)
    {
        $run_status = (string) ($run['status'] ?? '');
        $stored     = (array) ($run['result'] ?? []);
        $status     = (string) ($job['status'] ?? '');
        $result     = self::json_decode($job['result'] ?? NULL);

        // «В записи есть отчёт, а не только сводка»: отчёт считает по нескольким
        // путям/входам и приносит раздел engines; у сводки его нет.
        $stored_full = ! empty($stored['engines']) && is_array($stored['engines']);
        $job_full    = ! empty($result['engines']) && is_array($result['engines']);

        $terminal = in_array($run_status, ['done', 'error', 'cancelled'], TRUE);

        if ($terminal AND $run_status !== 'done')
        {
            // Прогон уже закрыт отказом или отменой — переписывать нечем и
            // незачем: причина на странице уже есть.
            return ['action' => 'none', 'result' => [], 'note' => 'прогон закрыт'];
        }

        if ($terminal)
        {
            // Закрыт успехом. Чиним только испорченную запись: сводка на месте
            // отчёта (следы прежнего дожима).
            if ( ! $stored_full AND $status === 'done' AND $job_full)
            {
                return ['action' => 'heal', 'result' => $result,
                        'note' => 'в записи была сводка, в задаче — отчёт'];
            }

            return ['action' => 'none', 'result' => [], 'note' => 'отчёт на месте'];
        }

        if ($status === 'done' AND $result)
        {
            return ['action' => 'apply', 'result' => $result, 'note' => 'результат из задачи'];
        }

        if ($status === 'cancelled'
            OR ($status === 'failed'
                AND (int) ($job['max_attempts'] ?? 0) > 0
                AND (int) ($job['attempts'] ?? 0) >= (int) $job['max_attempts']))
        {
            return ['action' => 'fail', 'result' => [], 'note' => 'задача провалена окончательно'];
        }

        return ['action' => 'none', 'result' => [], 'note' => 'задача ещё в работе'];
    }

    /**
     * Имя таблицы — это код модуля, а не пользовательский ввод; но раз оно
     * попадает в SQL строкой, форма проверяется явно.
     */
    protected static function _safe_table($table)
    {
        $table = (string) $table;

        if ( ! preg_match('/^nj_\d{3}_[a-z_]+$/', $table))
        {
            throw new InvalidArgumentException('Vision: недопустимое имя таблицы прогонов: '.$table);
        }

        return $table;
    }

    /** Имя модуля в пути каталога кадров: только латиница, цифры и «_». */
    protected static function _safe_module($module)
    {
        $module = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $module));

        if ($module === '')
        {
            throw new InvalidArgumentException('Vision: пустое имя модуля для каталога кадров');
        }

        return $module;
    }
}
