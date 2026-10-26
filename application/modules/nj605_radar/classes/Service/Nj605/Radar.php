<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj605_radar: «Радар заказов» — наблюдения пользователя за спросом.
 *
 * Пользователь описывает, что ищет («поставка металлоконструкций», регион).
 * Пиналка nj603 (каждые 30 мин) → /radar/api/collect → задачи nj120 →
 * python-воркер ищет по открытым источникам, LLM оценивает релевантность
 * (score 0-100 + почему) → items появляются в /radar.
 */
class Service_Nj605_Radar {

    /** Схема ставится миграциями (project law); страховка на dev-среды. */
    public static function ensure_tables()
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        $done = TRUE;

        try
        {
            DB::query(NULL, 'SELECT 1 FROM "nj_605_watches" LIMIT 1')->execute();

            // идемпотентные добавления (дублируют миграции)
            DB::query(NULL, 'ALTER TABLE "nj_605_items" ADD COLUMN IF NOT EXISTS "source" VARCHAR(40) NOT NULL DEFAULT \'\'')->execute();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj605_radar: таблиц нет — примените миграции: :e',
                [':e' => $e->getMessage()]);
        }
    }

    protected static function _db()
    {
        return Database::instance();
    }

    protected static function _cfg($key, $default = '')
    {
        return Kohana::$config->load('nj605_radar.'.$key) ?: $default;
    }

    // ------------------------------------------------------------------ CRUD

    /** Создать наблюдение. @return array [ok/code] | [error] */
    public static function create_watch($user_id, $title, $query, $region)
    {
        self::ensure_tables();

        if ((int) $user_id <= 0)
        {
            return ['error' => 'Сервис для зарегистрированных — войдите.'];
        }

        $query = trim((string) $query);

        if (mb_strlen($query) < 5)
        {
            return ['error' => 'Опишите, что искать (от 5 знаков): продукт, работы или услугу.'];
        }

        // Дубли не плодим: тот же запрос у того же пользователя (с учётом
        // региона) — это ТОТ ЖЕ радар. Повторная отправка формы (F5, «назад»)
        // раньше создавала копию и новый сбор «по кругу»; теперь включаем
        // существующий и запускаем сбор заново.
        $db = Database::instance();
        $region = trim((string) $region);

        $dup = $db->query(Database::SELECT,
            'SELECT id, code FROM nj_605_watches
             WHERE user_id = '.(int) $user_id.'
               AND lower(btrim(query)) = lower(btrim('.$db->quote($query).'))
               AND lower(btrim(region)) = lower(btrim('.$db->quote($region).'))
             ORDER BY id DESC LIMIT 1', FALSE)->current();

        if ( ! empty($dup))
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_605_watches SET enabled = TRUE WHERE id = '.(int) $dup['id'], FALSE);

            self::_enqueue((string) $dup['code']);

            return ['ok' => TRUE, 'code' => (string) $dup['code'], 'existing' => TRUE];
        }

        // лимит наблюдений — чтобы лента не превращалась в несводимый шум
        $max_watches = (int) self::_cfg('max_watches', 10);

        if (self::count_of((int) $user_id) >= $max_watches)
        {
            return ['error' => 'Радаров уже '.$max_watches.' — удалите неактуальные, чтобы добавить новый.'];
        }

        $code = 'rad'.bin2hex(random_bytes(4));

        $db->query(Database::INSERT,
            'INSERT INTO nj_605_watches (code, user_id, title, query, region)
             VALUES ('.$db->quote($code).', '.(int) $user_id.', '
            .$db->quote(mb_substr(trim((string) $title) ?: $query, 0, 120)).', '
            .$db->quote(mb_substr($query, 0, 300)).', '
            .$db->quote(mb_substr(trim((string) $region), 0, 120)).')', FALSE);

        // первый сбор — СРАЗУ (не ждём пиналку); периодичность дальше держит nj603
        self::_enqueue($code);

        return ['ok' => TRUE, 'code' => $code];
    }

    /** Задача сбора конкретного радара в очередь nj120 (payload самодостаточен). */
    protected static function _enqueue($code)
    {
        $db = Database::instance();
        $w  = $db->query(Database::SELECT,
            'SELECT id, code, query, region FROM nj_605_watches WHERE code = '.$db->quote((string) $code), FALSE)->current();

        if (empty($w))
        {
            return FALSE;
        }

        // Уже идёт? Вторую задачу не ставим: иначе кнопка «Собрать»/пиналка
        // плодит дубли, а страница показывает самую свежую — «ждём воркера» вечно.
        if (method_exists('Service_Nj120_JobQueue', 'find_active')
            AND Service_Nj120_JobQueue::instance()->find_active('nj605.radar', 'watch_id', (int) $w['id']) > 0)
        {
            return FALSE;
        }

        Service_Nj120_JobQueue::push('nj605.radar', [
            'watch_id' => (int) $w['id'],
            'code'     => (string) $w['code'],
            'query'    => (string) $w['query'],
            'region'   => (string) $w['region'],
        ], ['module' => 'nj605', 'timeout_sec' => 900]);

        $db->query(Database::UPDATE,
            'UPDATE nj_605_watches SET last_run_at = now() WHERE id = '.(int) $w['id'], FALSE);

        return TRUE;
    }

    /** Радар по коду (для статус-ряда и проверок владельца) или NULL. */
    public static function by_code($code)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_605_watches WHERE code = '.self::_db()->quote((string) $code), FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /**
     * Живой статус сбора для страницы (эталон /trends/<code>): статус задачи,
     * ЭТАП воркера (ctx.progress → события очереди), секундомер. Доступ — только
     * владельцу радара или админу (иначе NULL: этапы содержат пользовательский
     * запрос).
     *
     * @return array|null
     */
    public static function live_for($code, $user_id, $is_admin = FALSE)
    {
        $w = self::by_code($code);

        if ($w === NULL)
        {
            return NULL;
        }

        if ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id)
        {
            return NULL;
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['found' => FALSE, 'status' => 'none', 'stage' => '', 'stages' => [], 'elapsed_sec' => 0, 'silent_sec' => NULL, 'error' => ''];
        }

        return Service_Nj120_JobQueue::instance()->live_status('nj605.radar', 'watch_id', (int) $w['id']);
    }

    /**
     * Последняя задача радара, включая ЗАВЕРШЁННУЮ — постоянная плашка
     * «Готово»/«Ошибка» в списке радаров (после того, как живой статус-ряд
     * погас, пользователь всё равно должен видеть итог: «нет готово» — типовая
     * жалоба). Права те же, что у live_for().
     */
    public static function last_for($code, $user_id, $is_admin = FALSE)
    {
        $w = self::by_code($code);

        if ($w === NULL OR ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id))
        {
            return NULL;
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        $st = Service_Nj120_JobQueue::instance()->last_status('nj605.radar', 'watch_id', (int) $w['id']);

        return ($st AND $st['found']) ? $st : NULL;
    }

    /**
     * Остановить идущий сбор радара (владелец или управление).
     *
     * Зачем: сбор бывает долгим (поиск + чтение площадок), а воркер — недоступен
     * или молчит. Без кнопки «Остановить» пользователь бессилен: страница
     * показывает «идёт» и ждать приходится до таймаута очереди.
     *
     * force = TRUE: прерываем и ЗАХВАЧЕННУЮ задачу — воркер свой complete()
     * потом не применит (статус уже cancelled), это штатно.
     *
     * @return array [ok] | [error]
     */
    public static function cancel_run($code, $user_id, $is_admin = FALSE)
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'Очередь задач недоступна'];
        }

        $w = self::by_code((string) $code);

        if ($w === NULL OR ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id))
        {
            return ['error' => 'Радар не найден'];
        }

        $queue  = Service_Nj120_JobQueue::instance();
        // Останавливаем только РЕАЛЬНО активную задачу (pending/processing):
        // свежий failed — это уже итог, его «остановка» лишь путала бы статус.
        $job_id = method_exists($queue, 'find_active')
            ? $queue->find_active('nj605.radar', 'watch_id', (int) $w['id'])
            : $queue->find_for_delivery('nj605.radar', 'watch_id', (int) $w['id']);

        if ($job_id <= 0)
        {
            return ['error' => 'Активного сбора нет — останавливать нечего'];
        }

        try
        {
            $queue->cancel($job_id, TRUE);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось остановить сбор'];
        }

        return ['ok' => TRUE, 'job_id' => $job_id];
    }

    /**
     * Сколько находок радара появилось с момента $since — живой счётчик для
     * статус-ряда («находок собрано: N»), эталон /trends/<code>.
     */
    public static function found_since($watch_id, $since = NULL)
    {
        self::ensure_tables();

        $since = trim((string) $since);
        $where = $since !== '' ? ' AND created_at >= '.self::_db()->quote($since) : '';

        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_605_items WHERE watch_id = '.(int) $watch_id.$where, FALSE)->current();

        return $row ? (int) $row['c'] : 0;
    }

    /** «Собрать сейчас» (владелец или управление). @return array [ok] | [error] */
    public static function run_now($code, $user_id, $is_admin = FALSE)
    {
        $db = Database::instance();
        $w  = $db->query(Database::SELECT,
            'SELECT user_id FROM nj_605_watches WHERE code = '.$db->quote((string) $code), FALSE)->current();

        if (empty($w))
        {
            return ['error' => 'Радар не найден'];
        }

        if ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id)
        {
            return ['error' => 'Это не ваш радар'];
        }

        return self::_enqueue($code)
            ? ['ok' => TRUE]
            : ['error' => 'Сбор этого радара уже идёт — дождитесь результата или нажмите «Остановить»'];
    }

    public static function watches_of($user_id)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT w.*, (SELECT COUNT(*) FROM nj_605_items i
                          WHERE i.watch_id = w.id AND i.status = \'new\') AS fresh,
                    (SELECT COUNT(*) FROM nj_605_items i WHERE i.watch_id = w.id) AS total
             FROM nj_605_watches w WHERE w.user_id = '.(int) $user_id.'
             ORDER BY w.id DESC', FALSE)->as_array();
    }

    /** Сколько наблюдений у пользователя. */
    public static function count_of($user_id)
    {
        self::ensure_tables();
        $row = self::_db()->query(Database::SELECT,
            'SELECT COUNT(*) AS c FROM nj_605_watches WHERE user_id = '.(int) $user_id, FALSE)->current();

        return (int) ($row['c'] ?? 0);
    }

    /** Пауза/включение наблюдения (владелец или управление). */
    public static function set_enabled($code, $user_id, $enabled, $is_admin = FALSE)
    {
        $db = self::_db();
        $w  = $db->query(Database::SELECT,
            'SELECT user_id FROM nj_605_watches WHERE code = '.$db->quote((string) $code), FALSE)->current();

        if (empty($w))
        {
            return ['error' => 'Радар не найден'];
        }

        if ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id)
        {
            return ['error' => 'Это не ваш радар'];
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_605_watches SET enabled = '.($enabled ? 'TRUE' : 'FALSE')
            .' WHERE code = '.$db->quote((string) $code), FALSE);

        return ['ok' => TRUE, 'enabled' => (bool) $enabled];
    }

    /** Удалить своё наблюдение вместе с найденным. */
    public static function delete_watch($code, $user_id)
    {
        $db = self::_db();
        $db->query(Database::DELETE,
            'DELETE FROM nj_605_items WHERE watch_id IN
                (SELECT id FROM nj_605_watches WHERE code = '.$db->quote((string) $code).' AND user_id = '.(int) $user_id.')', FALSE);

        $res = $db->query(Database::DELETE,
            'DELETE FROM nj_605_watches WHERE code = '.$db->quote((string) $code).' AND user_id = '.(int) $user_id, FALSE);

        return (is_int($res) AND $res > 0);
    }

    // ----------------------------------------------------------------- управление

    /** Сводка для /radar/admin: все радары, свежие находки, счётчики. */
    public static function admin_overview($limit = 100)
    {
        self::ensure_tables();
        $db = self::_db();

        return [
            'watches' => $db->query(Database::SELECT,
                'SELECT w.*, u.username AS owner_name,
                        (SELECT COUNT(*) FROM nj_605_items i WHERE i.watch_id = w.id) AS total
                 FROM nj_605_watches w LEFT JOIN users u ON u.id = w.user_id
                 ORDER BY w.id DESC LIMIT '.(int) $limit, FALSE)->as_array(),
            'items' => $db->query(Database::SELECT,
                'SELECT i.*, w.title AS watch_title, u.username AS owner_name
                 FROM nj_605_items i
                 JOIN nj_605_watches w ON w.id = i.watch_id
                 LEFT JOIN users u ON u.id = i.user_id
                 ORDER BY i.id DESC LIMIT '.(int) $limit, FALSE)->as_array(),
            'counts' => $db->query(Database::SELECT,
                'SELECT (SELECT COUNT(*) FROM nj_605_watches) AS watches,
                        (SELECT COUNT(*) FROM nj_605_items) AS items,
                        (SELECT COUNT(*) FROM nj_605_items WHERE status = \'new\') AS new,
                        (SELECT COUNT(*) FROM nj_605_items WHERE score >= 85) AS hot', FALSE)->as_array()[0] ?? [],
        ];
    }

    /** Удалить радар без ограничения по владельцу (управление). */
    public static function admin_delete_watch($code)
    {
        $db = self::_db();
        $db->query(Database::DELETE,
            'DELETE FROM nj_605_items WHERE watch_id IN
                (SELECT id FROM nj_605_watches WHERE code = '.$db->quote((string) $code).')', FALSE);

        $res = $db->query(Database::DELETE,
            'DELETE FROM nj_605_watches WHERE code = '.$db->quote((string) $code), FALSE);

        return (is_int($res) AND $res > 0);
    }

    /**
     * Находки одного радара — для страницы /radar/<code>. Раньше находки были
     * только общей лентой: владелец хотел «другую страницу с находками по
     * запросу», чтобы видеть результат конкретного радара, а не поток.
     */
    public static function items_of($watch_id, $user_id, $limit = 100)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_605_items
             WHERE watch_id = '.(int) $watch_id.' AND user_id = '.(int) $user_id.'
             ORDER BY score DESC, id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Счётчики радара для шапки страницы: всего / новых / взятых в работу. */
    public static function stats_of($watch_id)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'new')   AS fresh,
                    COUNT(*) FILTER (WHERE status = 'taken') AS taken
             FROM nj_605_items WHERE watch_id = ".(int) $watch_id, FALSE)->current();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'fresh' => (int) ($row['fresh'] ?? 0),
            'taken' => (int) ($row['taken'] ?? 0),
        ];
    }

    /** Радар по коду для владельца/админа: NULL, если нет или доступ закрыт. */
    public static function by_code_for($code, $user_id, $is_admin = FALSE)
    {
        $w = self::by_code($code);

        if ($w === NULL)
        {
            return NULL;
        }

        if ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id)
        {
            return NULL;
        }

        return $w;
    }

    /** Лента пользователя (со всеми радарами), свежее сверху. */
    public static function feed($user_id, $limit = 60)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT i.*, w.title AS watch_title FROM nj_605_items i
             JOIN nj_605_watches w ON w.id = i.watch_id
             WHERE i.user_id = '.(int) $user_id.'
             ORDER BY i.score DESC, i.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    public static function item_status($id, $user_id, $status)
    {
        if ( ! in_array($status, ['new', 'taken', 'dismissed'], TRUE))
        {
            return FALSE;
        }

        $db = self::_db();
        $res = $db->query(Database::UPDATE,
            'UPDATE nj_605_items SET status = '.$db->quote($status)
            .' WHERE id = '.(int) $id.' AND user_id = '.(int) $user_id, FALSE);

        return (is_int($res) AND $res > 0);
    }

    // ------------------------------------------------------- API для воркера

    /**
     * /radar/api/collect: поставить задачи сбора по всем включённым радарам.
     * Дубли не ставим: у радара задача в очереди или сбор младше 25 минут.
     *
     * @return array {ok, pushed}
     */
    public static function api_collect()
    {
        self::ensure_tables();
        $db = self::_db();

        $watches = $db->query(Database::SELECT,
            "SELECT id, code, query, region FROM nj_605_watches
             WHERE enabled = 't'
               AND (last_run_at IS NULL OR last_run_at < now() - interval '25 minutes')", FALSE)->as_array();

        $pushed = 0;

        foreach ($watches as $w)
        {
            try
            {
                // НЕ дублируем сбор: если задача этого радара уже в очереди или
                // исполняется — пропускаем. Иначе каждая пиналка (30 мин) добавляла
                // новую задачу поверх застрявшей, и страница показывала ВЕЧНОЕ
                // «ждём свободного воркера», а старые задачи копились в мониторе.
                if (method_exists('Service_Nj120_JobQueue', 'find_active')
                    AND Service_Nj120_JobQueue::instance()->find_active('nj605.radar', 'watch_id', (int) $w['id']) > 0)
                {
                    continue;
                }

                // параметры везём в payload — воркеру не нужен обратный запрос
                Service_Nj120_JobQueue::push('nj605.radar', [
                    'watch_id' => (int) $w['id'],
                    'code'     => (string) $w['code'],
                    'query'    => (string) $w['query'],
                    'region'   => (string) $w['region'],
                ], ['module' => 'nj605', 'timeout_sec' => 900]);

                $db->query(Database::UPDATE,
                    'UPDATE nj_605_watches SET last_run_at = now() WHERE id = '.(int) $w['id'], FALSE);
                $pushed++;
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj605_radar: push: :e', [':e' => $e->getMessage()]);
            }
        }

        return ['ok' => TRUE, 'pushed' => $pushed];
    }

    /**
     * /radar/api/items: принять находки воркера. Дубли по (watch_id, ext_id) молча пропускаем.
     *
     * @return array {ok, saved}
     */
    public static function api_items($watch_id, array $items)
    {
        self::ensure_tables();
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT id, user_id FROM nj_605_watches WHERE id = '.(int) $watch_id, FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'watch not found'];
        }

        $saved = 0;
        $hot  = 0; // максимум score среди реально НОВЫХ находок (для уведомления)

        foreach ($items as $it)
        {
            $url = trim((string) ($it['url'] ?? ''));

            if ($url === '' OR strpos($url, 'http') !== 0)
            {
                continue;
            }

            $ext = sha1($url);
            $title = mb_substr(trim((string) ($it['title'] ?? '')), 0, 300) ?: $url;
            $score = min(100, max(0, (int) ($it['score'] ?? 0)));

            // источник: host площадки (передаёт воркер); иначе вычислим из URL
            $source = trim((string) ($it['source'] ?? ''));
            if ($source === '' OR mb_strlen($source) > 40)
            {
                $source = mb_substr((string) parse_url($url, PHP_URL_HOST), 0, 40);
            }

            try
            {
                // RETURNING: affected считает только ВСТАВЛЕННЫЕ строки
                // (ON CONFLICT DO NOTHING дубликаты не возвращает)
                $res = $db->query(Database::INSERT,
                    'INSERT INTO nj_605_items (watch_id, user_id, ext_id, title, url, customer, price, published_at, score, why, source)
                     VALUES ('.(int) $row['id'].', '.(int) $row['user_id'].', '.$db->quote($ext).', '
                    .$db->quote($title).', '.$db->quote(mb_substr($url, 0, 500)).', '
                    .$db->quote(mb_substr(trim((string) ($it['customer'] ?? '')), 0, 300)).', '
                    .$db->quote(mb_substr(trim((string) ($it['price'] ?? '')), 0, 60)).', '
                    .$db->quote(mb_substr(trim((string) ($it['date'] ?? '')), 0, 40)).', '
                    .$score.', '
                    .$db->quote(mb_substr(trim((string) ($it['why'] ?? '')), 0, 1000)).', '
                    .$db->quote($source).')
                     ON CONFLICT (watch_id, ext_id) DO NOTHING RETURNING id', FALSE);

                if (is_array($res) AND (int) ($res[1] ?? 0) > 0)
                {
                    $saved++;
                    $hot = max($hot, $score);
                }
            }
            catch (Throwable $e)
            {
                // битая строка — не валим весь батч
            }
        }

        // nj605_radar → nj597: горячие находки достойны колокольчика
        // (не чаще раза в час, чтобы радар не «звенел» на каждый сбор)
        if ($hot >= 85 AND class_exists('Service_Nj597_Notify'))
        {
            $recent = self::_db()->query(Database::SELECT,
                'SELECT id FROM nj_597_notifications
                 WHERE user_id = '.(int) $row['user_id']." AND kind = 'nj605_radar'
                 AND created_at > now() - interval '1 hour' LIMIT 1", FALSE)->current();

            if ( ! $recent)
            {
                // склонение — штатный хелпер nj502_words
                $word = class_exists('Service_Nj502_Words')
                    ? Service_Nj502_Words::plural((int) $saved, 'находку', 'находки', 'находок')
                    : 'находок';

                Service_Nj597_Notify::notify((int) $row['user_id'], 'nj605_radar',
                    'Радар заказов: нашёл '.(int) $saved.' '.$word,
                    'Есть находки с релевантностью '.$hot.'/100. Посмотрите, пока конкуренты не увидели.',
                    '/radar');
            }
        }

        // Дожим очереди (образец nj602): находки приняты сайтом — задача nj120
        // обязана стать «Готово». Своей колонки job_id у радара нет, поэтому
        // задачу ищем по payload.watch_id (find_for_delivery) и оживляем в том
        // числе свежий failed: свипер мог отказать её до доставки.
        try
        {
            if (class_exists('Service_Nj120_JobQueue'))
            {
                $queue  = Service_Nj120_JobQueue::instance();
                $job_id = method_exists($queue, 'find_for_delivery')
                    ? $queue->find_for_delivery('nj605.radar', 'watch_id', (int) $watch_id)
                    : 0;

                if ($job_id > 0)
                {
                    $queue->mark_delivered($job_id,
                        'Находки радара приняты сайтом — задача закрыта из приёмки nj605');
                }
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj605: связать задачу с приёмкой находок не удалось: :e',
                [':e' => $e->getMessage()]);
        }

        return ['ok' => TRUE, 'saved' => $saved];
    }
}
