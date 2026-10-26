<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Спрос агентов на MCP-инструменты (nj676_mcphub).
 *
 * Зачем этот класс. Каталог MCP пополнялся по инициативе редактора: «нажми
 * кнопку — воркер поищет, что найдёт». Но ценность каталога измеряется не
 * числом карточек, а тем, нашёл ли агент инструмент под свою задачу. Шлюз
 * `hub_search_tools` видит каждый запрос и его результат — это готовый,
 * бесплатный и самый честный источник тем.
 *
 * Цикл получается замкнутым:
 *   1. агент ищет «курс валют ЦБ» → запрос падает в nj_676_search_log;
 *   2. если ничего не нашлось, шлюз сразу ставит прогон автосборки с этой
 *      темой (с ограничениями: не чаще раза в 30 минут и не больше 6 в сутки);
 *   3. даже без мгновенной постановки тема попадает в «спрос» и её забирает
 *      ночная автосборка (Service_Nj676_Demand::next_theme) — в порядке
 *      частоты, а не в случайном;
 *   4. воркер ищет по теме на GitHub, README и реестре, карточка появляется
 *      в каталоге, и следующий такой запрос уже находит ответ.
 *
 * Приватность: пишем только текст запроса (до 200 символов) и sha256 ключа —
 * ни токена, ни аргументов вызова, ни ответа. Журнал чистится по возрасту
 * (NJ676_DEMAND_KEEP_DAYS, по умолчанию 180 дней).
 *
 * Все методы «мягкие»: журналирование и постановка прогона не должны ломать
 * поиск агента, поэтому ошибки БД/очереди только пишутся в лог.
 */
class Service_Nj676_Demand
{
    /** Окно по умолчанию для «спроса» (дни). */
    const WINDOW_DAYS = 30;
    /** Сколько строк спроса показывать в админке. */
    const TOP_LIMIT = 10;
    /** Тема короче этого — шум («а», «да», «1»), её не собираем. */
    const MIN_NORM_LEN = 3;
    /** Сколько дней не повторять автосборку по одной теме. */
    const REPEAT_DAYS = 14;

    /**
     * Нормализация запроса для группировки.
     *
     * «Погода!», «погода», «  Погода  » — один спрос. Убираем пунктуацию,
     * схлопываем пробелы, приводим ё→е: агенты пишут по-разному, а тема
     * автосборки должна быть одна. Регистр и лишние знаки при этом не должны
     * склеивать разные задачи, поэтому слова не режем и не стеммим.
     */
    public static function norm($query)
    {
        $text = mb_strtolower(trim((string) $query), 'UTF-8');
        $text = str_replace('ё', 'е', $text);
        // Оставляем буквы, цифры, пробел, дефис, плюс и точку (версии, «c++»,
        // «node.js»): остальное — шум пунктуации.
        $text = preg_replace('/[^\p{L}\p{N}\s.+#-]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);

        return trim(mb_substr(trim((string) $text), 0, 200, 'UTF-8'), " .-");
    }

    /**
     * Записать поиск. Вызывается из шлюза и поиска на сайте.
     *
     * @param string $query   как написал пользователь/агент
     * @param string $kind    agent|catalog
     * @param int    $servers сколько карточек нашлось
     * @param int    $tools   сколько инструментов платформы нашлось
     * @param string $token_hash sha256 ключа агента (может быть пустым)
     */
    public static function log($query, $kind = 'agent', $servers = 0, $tools = 0, $token_hash = '')
    {
        if ( ! in_array($kind, ['agent', 'catalog'], TRUE))
        {
            $kind = 'agent';
        }

        $query = trim(mb_substr(trim((string) $query), 0, 200, 'UTF-8'));
        $norm = self::norm($query);

        if ($norm === '' OR mb_strlen($norm, 'UTF-8') < self::MIN_NORM_LEN)
        {
            return FALSE;
        }

        try
        {
            DB::query(Database::INSERT,
                'INSERT INTO nj_676_search_log (kind, query, norm, servers_found, tools_found, token_hash)
                 VALUES (:kind, :query, :norm, :servers, :tools, :hash)')
                ->param(':kind', $kind)
                ->param(':query', $query)
                ->param(':norm', $norm)
                ->param(':servers', max(0, (int) $servers))
                ->param(':tools', max(0, (int) $tools))
                ->param(':hash', mb_substr((string) $token_hash, 0, 64, 'UTF-8'))
                ->execute();
        }
        catch (Throwable $e)
        {
            // Журнал — вспомогательный: поиск агента важнее статистики.
            Log_Plain::event(Log::WARNING, 'nj676: спрос не записан: :e', [':e' => $e->getMessage()]);

            return FALSE;
        }

        // Пустой ответ агента — прямой повод добрать каталог. Только для
        // агентов: анонимный поиск на сайте тоже сигнал, но запускать по
        // нему модель может любой бот, а по ключу агента — нет.
        if ($kind === 'agent' AND (int) $servers === 0 AND (int) $tools === 0)
        {
            self::maybe_queue($norm);
        }

        return TRUE;
    }

    /**
     * Поставить автосборку по запросу, которому не нашлось ответа.
     *
     * Ограничения — не формальность: каждый прогон тратит GitHub-лимиты и
     * (когда включена модель) деньги. Поэтому:
     *   * только при NJ676_AUTO_DISCOVER=1, выключить — NJ676_DEMAND_QUEUE=0;
     *   * не чаще NJ676_DEMAND_MIN_SEC (по умолчанию 30 минут);
     *   * не больше NJ676_DEMAND_MAX_DAY (6) авто-прогонов в сутки;
     *   * по одной теме не чаще REPEAT_DAYS дней: второй прогон «погоды» за
     *     неделю не найдёт ничего нового, зато сожжёт время воркера.
     *
     * @return bool поставлен ли прогон
     */
    public static function maybe_queue($norm)
    {
        $norm = self::norm($norm);

        if ($norm === '' OR mb_strlen($norm, 'UTF-8') < self::MIN_NORM_LEN)
        {
            return FALSE;
        }
        if ((string) getenv('NJ676_AUTO_DISCOVER') !== '1')
        {
            return FALSE;
        }
        if ((string) getenv('NJ676_DEMAND_QUEUE') === '0')
        {
            return FALSE;
        }

        try
        {
            $cooldown = (int) getenv('NJ676_DEMAND_MIN_SEC');
            $cooldown = $cooldown > 0 ? $cooldown : 1800;

            $recent = self::_one(
                "SELECT id FROM nj_676_runs
                  WHERE user_id = 0 AND theme <> '' AND started_at > now() - make_interval(secs => :sec)
                  ORDER BY id DESC LIMIT 1",
                [':sec' => $cooldown]
            );
            if ($recent)
            {
                return FALSE;
            }

            $per_day = (int) getenv('NJ676_DEMAND_MAX_DAY');
            $per_day = $per_day > 0 ? $per_day : 6;

            $today = self::_one(
                "SELECT count(*) AS c FROM nj_676_runs
                  WHERE user_id = 0 AND theme <> '' AND started_at > now() - interval '1 day'"
            );
            if ((int) ($today['c'] ?? 0) >= $per_day)
            {
                return FALSE;
            }

            // Такую тему уже собирали — второй раз не надо.
            if (self::_discovered_recently($norm))
            {
                return FALSE;
            }

            return self::_start_run($norm, (int) getenv('NJ676_DEMAND_COUNT')) ? TRUE : FALSE;
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'nj676: автосборка по спросу не поставлена: :e',
                [':e' => $e->getMessage()]);

            return FALSE;
        }
    }

    /**
     * Тема для плановой автосборки: самый частый запрос без ответа.
     *
     * Именно этот метод вызывается из ночного пинка /mcphub/api/tick: каталог
     * добирается по реальному спросу, а не «что попалось на GitHub».
     *
     * @return string нормализованный запрос или '' (нет спроса)
     */
    public static function next_theme()
    {
        $rows = self::unresolved(self::WINDOW_DAYS, 5);

        foreach ($rows as $row)
        {
            if (self::_discovered_recently((string) $row['norm']))
            {
                continue;
            }

            return (string) $row['norm'];
        }

        return '';
    }

    /**
     * Запросы, по которым ничего не нашлось (или нашлось меньше одной карточки).
     *
     * Учитываем оба вида результата: пустой ответ агента и ответ, где есть
     * только инструменты платформы (сервер каталога не понадобился) — второй
     * тоже сигнал «а MCP-сервера под это нет».
     *
     * @return array [['norm'=>…, 'sample'=>…, 'searches'=>…, 'last_seen'=>…], …]
     */
    public static function unresolved($days = self::WINDOW_DAYS, $limit = 20)
    {
        $days = max(1, min(365, (int) $days));
        $limit = max(1, min(100, (int) $limit));

        try
        {
            return self::_all(
                "SELECT norm,
                        max(query)        AS sample,
                        count(*)          AS searches,
                        max(created_at)   AS last_seen,
                        max(servers_found) AS best_servers
                   FROM nj_676_search_log
                  WHERE created_at > now() - make_interval(days => :days)
                    AND norm <> ''
                  GROUP BY norm
                 HAVING max(servers_found) = 0
                  ORDER BY count(*) DESC, max(created_at) DESC
                  LIMIT ".$limit,
                [':days' => $days]
            );
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'nj676: спрос не прочитан: :e', [':e' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Спрос для витрины админки: по частоте, с честной картиной результата.
     *
     * @return array [['norm','sample','searches','empty','best','last_seen','agents'], …]
     */
    public static function top($days = self::WINDOW_DAYS, $limit = self::TOP_LIMIT)
    {
        $days = max(1, min(365, (int) $days));
        $limit = max(1, min(50, (int) $limit));

        try
        {
            return self::_all(
                "SELECT norm,
                        max(query)  AS sample,
                        count(*)    AS searches,
                        count(*) FILTER (WHERE servers_found = 0) AS empty,
                        max(servers_found) AS best,
                        max(created_at) AS last_seen,
                        count(DISTINCT token_hash) FILTER (WHERE token_hash <> '') AS agents
                   FROM nj_676_search_log
                  WHERE created_at > now() - make_interval(days => :days)
                    AND norm <> ''
                  GROUP BY norm
                  ORDER BY count(*) DESC, max(created_at) DESC
                  LIMIT ".$limit,
                [':days' => $days]
            );
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'nj676: спрос не прочитан: :e', [':e' => $e->getMessage()]);

            return [];
        }
    }

    /** Счётчики для шапки карточки спроса. */
    public static function stats()
    {
        $empty = ['searches' => 0, 'empty' => 0, 'queries' => 0, 'agents' => 0];

        try
        {
            $row = self::_one(
                "SELECT count(*) AS searches,
                        count(*) FILTER (WHERE servers_found = 0 AND tools_found = 0) AS empty,
                        count(DISTINCT norm) AS queries,
                        count(DISTINCT token_hash) FILTER (WHERE token_hash <> '') AS agents
                   FROM nj_676_search_log
                  WHERE created_at > now() - interval '7 days'"
            );
        }
        catch (Throwable $e)
        {
            return $empty;
        }

        if ( ! $row)
        {
            return $empty;
        }

        return [
            'searches' => (int) ($row['searches'] ?? 0),
            'empty' => (int) ($row['empty'] ?? 0),
            'queries' => (int) ($row['queries'] ?? 0),
            'agents' => (int) ($row['agents'] ?? 0),
        ];
    }

    /** Удалить старые записи журнала (вызывается ночным пинком). */
    public static function prune()
    {
        $days = (int) getenv('NJ676_DEMAND_KEEP_DAYS');
        $days = $days > 0 ? min(3650, $days) : 180;

        try
        {
            DB::query(Database::DELETE,
                "DELETE FROM nj_676_search_log WHERE created_at < now() - make_interval(days => :days)")
                ->param(':days', $days)
                ->execute();

            return TRUE;
        }
        catch (Throwable $e)
        {
            return FALSE;
        }
    }

    // ---------------------------------------------------------------------
    // Внутреннее
    // ---------------------------------------------------------------------

    /** Была ли автосборка по этой теме за REPEAT_DAYS дней. */
    protected static function _discovered_recently($norm)
    {
        try
        {
            $row = self::_one(
                "SELECT id FROM nj_676_runs
                  WHERE lower(trim(theme)) = :theme
                    AND started_at > now() - make_interval(days => :days)
                  ORDER BY id DESC LIMIT 1",
                [':theme' => (string) $norm, ':days' => self::REPEAT_DAYS]
            );
        }
        catch (Throwable $e)
        {
            // Не знаем — считаем, что не собирали: пропустить спрос хуже,
            // чем один лишний прогон (его всё равно ограничивает кулдаун).
            return FALSE;
        }

        return (bool) $row;
    }

    /**
     * Создать прогон и поставить задачу воркеру.
     *
     * @return int|false id прогона
     */
    protected static function _start_run($theme, $count)
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return FALSE;
        }

        $count = $count > 0 ? $count : 6;
        $count = max(1, min(Service_Nj676_McpHub::MAX_DISCOVER, $count));

        $run = Service_Nj676_McpHub::create_run($theme, $count, 0);

        if (empty($run['id']))
        {
            return FALSE;
        }

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj676.discover', [
                'run_id' => (int) $run['id'],
                'theme' => (string) $theme,
                'limit' => $count,
            ], [
                'module' => 'nj676',
                'timeout_sec' => 900,
                'created_by' => 0,
                'priority' => 110,
            ]);
            Service_Nj676_McpHub::set_run_job((int) $run['id'], (int) $job_id);
        }
        catch (Throwable $e)
        {
            Service_Nj676_McpHub::finish_run((int) $run['id'], [
                'status' => 'fail',
                'error' => 'Очередь недоступна: '.$e->getMessage(),
            ]);

            return FALSE;
        }

        Log_Plain::event(Log::INFO, 'nj676: автосборка по спросу «:t» (прогон #:id)',
            [':t' => (string) $theme, ':id' => (int) $run['id']]);

        return (int) $run['id'];
    }

    protected static function _one($sql, array $params = [])
    {
        $rows = self::_all($sql, $params);

        return $rows ? $rows[0] : NULL;
    }

    protected static function _all($sql, array $params = [])
    {
        $query = DB::query(Database::SELECT, $sql);

        foreach ($params as $key => $value)
        {
            $query->param($key, $value);
        }

        return $query->execute()->as_array();
    }
}
