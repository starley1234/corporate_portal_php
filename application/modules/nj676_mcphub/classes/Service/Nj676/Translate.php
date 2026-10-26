<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Русские названия и описания карточек каталога MCP (nj676).
 *
 * Зачем. Сайт русскоязычный, а имя и описание сервера приходят из GitHub и
 * реестра на английском: «Playwright MCP Server», «Browser automation for AI
 * agents». Русский текст делает каталог понятным без перевода в голове — и
 * человеку, и агенту (он тоже отвечает по-русски).
 *
 * Кто переводит. Не сайт: LLM живёт в воркере (nj120, канал nj676.translate),
 * у сайта нет ни модели, ни права занимать HTTP-запрос чужими секундами.
 * Сайт даёт список карточек без перевода (`candidates()`) и принимает готовые
 * тексты (`apply()`), а очередь задач ставит `queue()`.
 *
 * Оригинал не теряется: `name` / `description` остаются словами автора, перевод
 * лежит рядом (`name_ru` / `description_ru`), а `ru_source` говорит, машинный
 * он или редакционный. Ручной перевод модель никогда не перезаписывает.
 */
class Service_Nj676_Translate
{
    /** Канал очереди nj120, который обслуживает воркер. */
    const CHANNEL = 'nj676.translate';

    /** Сколько карточек отдавать воркеру за один заход (порция модели). */
    const BATCH = 20;

    /**
     * «Перевести всё»: сколько карточек берёт одна задача и сколько задач
     * ставим разом.
     *
     * Одна задача на тысячу карточек — плохая идея: любой сбой модели или
     * таймаут убивает весь прогон, и половина каталога остаётся английской
     * (ровно это случилось 23.10.2026: перевелись 5 карточек и всё). Поэтому
     * «всё» — это цепочка независимых задач по 40 карточек: упала одна,
     * остальные доезжают, а следующая продолжает с того места, где стало пусто.
     */
    const ALL_JOB = 40;

    /** Потолок цепочки: 40 задач × 40 = 1600 карточек за один клик. */
    const ALL_MAX_JOBS = 40;

    /** Ключ кэша с отчётом последнего прогона перевода. */
    const CACHE_KEY = 'nj676_translate_last';

    /**
     * Состояние перевода: сколько всего, сколько переведено, сколько ждёт.
     *
     * `free` — карточки, которые увидит новое нажатие (не взяты задачами),
     * `claimed` — сколько уже в работе по клейму (миграция 013).
     *
     * @return array{cards:int,translated:int,pending:int,free:int,claimed:int,
     *     manual:int,last_at:string,model:string,job_size:int,jobs_needed:int,
     *     covered:int,last_result:?array}
     */
    public static function status()
    {
        $out = [
            'cards' => 0, 'translated' => 0, 'pending' => 0, 'manual' => 0,
            'last_at' => '', 'model' => self::model(),
        ];

        try
        {
            $row = Service_Nj676_McpHub::query_raw(
                "SELECT count(*) AS cards,
                        count(*) FILTER (WHERE name_ru <> '') AS translated,
                        count(*) FILTER (WHERE ru_source = 'manual') AS manual,
                        max(ru_at) AS last_at
                   FROM nj_676_servers
                  WHERE is_published = TRUE"
            )->current();
        }
        catch (Throwable $e)
        {
            // До миграции 011 колонок нет: перевода просто ещё не бывает.
            return $out;
        }

        $row = $row ? (array) $row : [];

        $out['cards'] = (int) ($row['cards'] ?? 0);
        $out['translated'] = (int) ($row['translated'] ?? 0);
        $out['manual'] = (int) ($row['manual'] ?? 0);
        $out['pending'] = max(0, $out['cards'] - $out['translated']);
        $out['last_at'] = (string) ($row['last_at'] ?? '');

        // Сколько карточек уже взято задачами (клейм живой). Свободные — те,
        // что увидит новое нажатие «Перевести всё»: по ним и считаем задачи,
        // иначе повторный клик ставил бы вторую цепочку на те же карточки.
        //
        // «Живой» = за задачей, которая ещё в очереди или в работе. Раньше
        // здесь был просто час от метки, и карточки умершей задачи держали
        // кнопки выключенными (24.10.2026: 25 карточек «в работе», модель
        // простаивает). Сначала отпускаем клеймы мёртвых задач.
        $out['queue'] = self::queue_state();
        self::release_stale_claims($out['queue']);
        $out['queue'] = self::queue_state();

        $out['claimed'] = 0;

        try
        {
            $claimed = Service_Nj676_McpHub::query_raw(
                "SELECT count(*) AS claimed FROM nj_676_servers
                  WHERE is_published = TRUE
                    AND coalesce(name_ru, '') = ''
                    AND ru_claimed_at > now() - interval '60 minutes'"
            )->current();

            $claimed_row = $claimed ? (array) $claimed : [];
            $out['claimed'] = (int) ($claimed_row['claimed'] ?? 0);
        }
        catch (Throwable $e)
        {
            // Миграция 013 ещё не применена — клейма нет, все карточки свободны.
        }

        $out['free'] = max(0, (int) $out['pending'] - (int) $out['claimed']);
        // Сколько карточек уедет в одной задаче и сколько задач нужно на всё:
        // без этих чисел кнопка «Перевести всё» — кот в мешке.
        $job_size = self::job_size();
        $free = (int) $out['free'];
        $out['job_size'] = (int) min($job_size, max(1, $free));
        $out['jobs_needed'] = $free > 0
            ? (int) min(self::ALL_MAX_JOBS, (int) ceil($free / $job_size))
            : 0;
        // Сколько карточек реально накрывает один клик: если каталог больше
        // потолка цепочки, честно говорим «остальное — вторым нажатием».
        $out['covered'] = (int) ($out['jobs_needed'] * $job_size);
        $out['last_result'] = self::last_result();

        // «В работе N, а задач нет» — это не «подождите час», а застрявший
        // клейм. Признак нужен вьюхе, чтобы предложить кнопку «снять клейм»,
        // а не совет ждать.
        $out['stuck'] = (int) $out['claimed'] > 0 && (int) ($out['queue']['active'] ?? 0) === 0;

        return $out;
    }

    /**
     * Состояние очереди перевода: что реально работает прямо сейчас.
     *
     * Нужно, чтобы отличить «карточки взяты живой задачей» от «карточки висят
     * за задачей, которая давно закончилась». Без этого сайт врал сам себе:
     * клейм живёт по часам, а задача — минуты.
     *
     * @return array{ok:bool,active:int,ids:array<int,int>,jobs:array<int,array<string,mixed>>,last:?array<string,mixed>,error:string}
     */
    public static function queue_state()
    {
        $out = ['ok' => FALSE, 'active' => 0, 'ids' => [], 'jobs' => [], 'last' => NULL, 'error' => ''];

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            $out['error'] = 'Очередь nj120 не подключена';

            return $out;
        }

        try
        {
            $queue = Service_Nj120_JobQueue::instance();
            $rows = $queue->channel_jobs(self::CHANNEL, 6);
        }
        catch (Throwable $e)
        {
            $out['error'] = mb_substr($e->getMessage(), 0, 200);

            return $out;
        }

        $out['ok'] = TRUE;

        foreach ((array) $rows as $index => $row)
        {
            $row = (array) $row;
            $status = (string) ($row['status'] ?? '');
            $payload = $row['payload'] ?? [];
            if (is_string($payload))
            {
                $payload = json_decode($payload, TRUE);
            }

            $job = [
                'id' => (int) ($row['id'] ?? 0),
                'status' => $status,
                'attempts' => (int) ($row['attempts'] ?? 0),
                'max' => (int) ($row['max_attempts'] ?? 0),
                'mode' => (string) (((array) $payload)['mode'] ?? ''),
                'limit' => (int) (((array) $payload)['limit'] ?? 0),
                'worker' => (string) ($row['claimed_by'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'started_at' => (string) ($row['started_at'] ?? ''),
                'finished_at' => (string) ($row['finished_at'] ?? ''),
                'error' => (string) ($row['error'] ?? ''),
            ];

            if ($index === 0)
            {
                $out['last'] = $job;
            }

            $out['jobs'][] = $job;

            if (in_array($status, ['pending', 'processing'], TRUE))
            {
                $out['active']++;
                $out['ids'][] = $job['id'];
            }
        }

        return $out;
    }

    /**
     * Отпустить клеймы карточек, чья задача уже не работает.
     *
     * Главная причина «перевод застрял»: задача взяла карточки и умерла (бюджет
     * времени, таймаут, падение воркера), а клейм живёт 60 минут по часам.
     * Сайт показывал «25 в работе», кнопки были выключены, модель простаивала.
     *
     * Правила:
     *   * клейм с номером задачи отпускаем, если этой задачи нет среди живых;
     *   * старые клеймы без номера (до миграции 015) — по времени: 10 минут,
     *     если задачи канала известны, иначе по прежнему сроку 60 минут;
     *   * если очередь недоступна — не трогаем ничего, кроме совсем старых
     *     клеймов (60 минут): лучше подождать, чем отобрать карточку у живой
     *     задачи и перевести её дважды.
     *
     * @param  array|NULL $state результат queue_state(), чтобы не спрашивать дважды
     * @return int сколько карточек освобождено
     */
    public static function release_stale_claims($state = NULL)
    {
        $state = is_array($state) ? $state : self::queue_state();
        $live = array_map('intval', (array) ($state['ids'] ?? []));

        $where = [];

        if ( ! empty($state['ok']))
        {
            if ($live !== [])
            {
                // Клейм мёртвой задачи: её номер есть, но её нет в очереди.
                // Числа уже приведены к int — вставляем их в SQL как есть.
                $where[] = '(ru_claimed_job IS NOT NULL
                              AND ru_claimed_job NOT IN ('.implode(',', $live).'))';
            }
            else
            {
                // Живых задач нет вовсе: любой клейм старше пары минут —
                // наследство закончившейся задачи.
                $where[] = "ru_claimed_at < now() - interval '2 minutes'";
            }

            // Метки без номера задачи (миграция 015 ещё не применилась или
            // задача писала по старому коду): освобождаем по времени.
            $where[] = "(ru_claimed_job IS NULL
                          AND ru_claimed_at < now() - interval '10 minutes')";
        }
        else
        {
            // Очередь недоступна: не знаем, кто жив, — ждём штатный срок.
            $where[] = "ru_claimed_at < now() - interval '60 minutes'";
        }

        // RETURNING: считаем ровно освобождённые строки, а не «сколько задело
        // обновление» — на счётчике строится сообщение «освобождено N».
        $sql = 'UPDATE nj_676_servers
                   SET ru_claimed_at = NULL, ru_claimed_job = NULL
                 WHERE is_published = TRUE
                   AND coalesce(name_ru, \'\') = \'\'
                   AND ru_claimed_at IS NOT NULL
                   AND ('.implode(' OR ', $where).')
             RETURNING slug';

        try
        {
            $result = Service_Nj676_McpHub::query_raw($sql);

            return max(0, (int) $result->count());
        }
        catch (Throwable $e)
        {
            // Колонки ru_claimed_job нет (миграция 015 не применена) — работаем
            // по прежнему правилу: только по времени.
            try
            {
                $result = Service_Nj676_McpHub::query_raw(
                    "UPDATE nj_676_servers SET ru_claimed_at = NULL
                      WHERE is_published = TRUE
                        AND coalesce(name_ru, '') = ''
                        AND ru_claimed_at IS NOT NULL
                        AND ru_claimed_at < now() - interval '60 minutes'
                    RETURNING slug"
                );

                return max(0, (int) $result->count());
            }
            catch (Throwable $e2)
            {
                Log_Plain::event(Log::DEBUG, 'nj676: клеймы перевода не отпущены: :e',
                    [':e' => $e2->getMessage()]);

                return 0;
            }
        }
    }

    /**
     * Снять ВСЕ клеймы перевода — кнопка «Разблокировать».
     *
     * Нужна, когда очередь недоступна или задача умерла так, что сайт не смог
     * это проверить. Явное действие админа: он видит «модель простаивает» и
     * решает сам, а не ждёт час.
     */
    public static function release_all()
    {
        try
        {
            $result = Service_Nj676_McpHub::query_raw(
                "UPDATE nj_676_servers
                    SET ru_claimed_at = NULL, ru_claimed_job = NULL
                  WHERE coalesce(name_ru, '') = ''
                    AND ru_claimed_at IS NOT NULL
              RETURNING slug"
            );

            return max(0, (int) $result->count());
        }
        catch (Throwable $e)
        {
            try
            {
                $result = Service_Nj676_McpHub::query_raw(
                    "UPDATE nj_676_servers SET ru_claimed_at = NULL
                      WHERE coalesce(name_ru, '') = ''
                        AND ru_claimed_at IS NOT NULL
                  RETURNING slug"
                );

                return max(0, (int) $result->count());
            }
            catch (Throwable $e2)
            {
                Log_Plain::event(Log::WARNING, 'nj676: клеймы перевода не сняты: :e',
                    [':e' => $e2->getMessage()]);

                return 0;
            }
        }
    }

    /** Модель, которой переводит воркер (для подписи в интерфейсе). */
    public static function model()
    {
        $model = trim((string) getenv('NJ676_TRANSLATE_MODEL'));

        if ($model === '')
        {
            $model = trim((string) getenv('NJ676_MODEL'));
        }

        return $model !== '' ? $model : 'модель воркера по умолчанию';
    }

    /**
     * Карточки без перевода — порция для модели.
     *
     * Сначала те, что чаще показываются и полезнее: с инструментами, звёздами и
     * свежей проверкой. Пустые/архивные карточки в хвосте — переводить их
     * первыми бессмысленно.
     *
     * @return array<int,array{slug:string,name:string,description:string,categories:array<int,string>,tools:array<int,array<string,mixed>>}>
     */
    public static function candidates($limit = self::BATCH, $job_id = 0, $claim = TRUE)
    {
        $limit = max(1, min(100, (int) $limit));
        $job_id = max(0, (int) $job_id);

        $base = "SELECT slug, name, description, categories, tools
                   FROM nj_676_servers
                  WHERE is_published = TRUE
                    AND coalesce(name_ru, '') = ''
                    AND trim(name) <> ''";
        $order = " ORDER BY jsonb_array_length(CASE WHEN jsonb_typeof(tools) = 'array' THEN tools ELSE '[]'::jsonb END) DESC,
                           stars DESC NULLS LAST,
                           health_checked_at DESC NULLS LAST, id ASC";

        // «Только посмотреть, сколько осталось» — без клейма. Раньше воркер в
        // конце прогона спрашивал `?limit=1`, чтобы обновить остаток, и этим
        // забирал карточку в работу; она висела до истечения клейма, хотя
        // переводить её никто не собирался.
        if ( ! $claim)
        {
            try
            {
                return self::_format(Service_Nj676_McpHub::query_raw(
                    $base.$order.' LIMIT '.$limit
                )->as_array());
            }
            catch (Throwable $e)
            {
                return [];
            }
        }

        // Перед выборкой отпускаем клеймы задач, которых больше нет: иначе
        // карточки умершей задачи не достанутся никому (24.10.2026).
        self::release_stale_claims();

        // Клейм: карточку берёт одна задача. Без него «Перевести всё» ставит
        // десятки задач разом, они стартуют почти одновременно, получают один
        // и тот же список «без перевода» и переводят каталог N раз (тот же
        // промах, что 23.10.2026, только дороже по токенам).
        //
        // Одним оператором, чтобы не было щели между «выбрали» и «пометили»:
        // UPDATE ... RETURNING отдаёт ровно те карточки, которые эта задача
        // только что забрала (FOR UPDATE SKIP LOCKED — чтобы соседняя задача
        // на тех же строках не ждала, а шла дальше).
        $claim = TRUE;
        $atomic = TRUE;

        try
        {
            $rows = Service_Nj676_McpHub::query_raw(
                "WITH picked AS (
                    SELECT slug FROM nj_676_servers
                     WHERE is_published = TRUE
                       AND coalesce(name_ru, '') = ''
                       AND trim(name) <> ''
                       AND (ru_claimed_at IS NULL
                            OR ru_claimed_at < now() - interval '60 minutes')"
                    .$order."
                     LIMIT ".$limit."
                     FOR UPDATE SKIP LOCKED
                 ), claimed AS (
                    UPDATE nj_676_servers AS s
                       SET ru_claimed_at = now(),
                           ru_claimed_job = :job
                      FROM picked
                     WHERE s.slug = picked.slug
                 RETURNING s.slug, s.name, s.description, s.categories, s.tools
                 )
                 SELECT slug, name, description, categories, tools
                   FROM claimed
                  ORDER BY jsonb_array_length(CASE WHEN jsonb_typeof(tools) = 'array' THEN tools ELSE '[]'::jsonb END) DESC, name ASC",
                [':job' => $job_id]
            )->as_array();
        }
        catch (Throwable $e)
        {
            $atomic = FALSE;

            // Старый PostgreSQL или миграция 013 ещё не применена — работаем
            // без атомарного клейма: сначала выборка с меткой, затем отдельный
            // UPDATE (щель в миллисекунды, дубли возможны, но перевод идёт).
            try
            {
                $rows = Service_Nj676_McpHub::query_raw(
                    $base." AND (ru_claimed_at IS NULL
                                  OR ru_claimed_at < now() - interval '60 minutes')"
                    .$order.' LIMIT '.$limit
                )->as_array();
            }
            catch (Throwable $e2)
            {
                // Колонки нет вовсе — переводим без клейма.
                $claim = FALSE;

                try
                {
                    $rows = Service_Nj676_McpHub::query_raw($base.$order.' LIMIT '.$limit)->as_array();
                }
                catch (Throwable $e3)
                {
                    return [];
                }
            }
        }

        $out = self::_format($rows);

        // Атомарный запрос пометил карточки сам; в резервном пути метим отдельно.
        if ($atomic === FALSE && $out !== [])
        {
            self::_claim(array_column($out, 'slug'), $job_id);
        }

        return $out;
    }

    /** Строки базы → компактные карточки для модели. */
    protected static function _format(array $rows)
    {
        $out = [];

        foreach ($rows as $row)
        {
            $row = (array) $row;
            $categories = json_decode((string) ($row['categories'] ?? '[]'), TRUE);
            $tools = json_decode((string) ($row['tools'] ?? '[]'), TRUE);

            $out[] = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'categories' => is_array($categories) ? array_values(array_map('strval', $categories)) : [],
                // Только имена и короткие описания: по ним модель понимает,
                // о чём сервер, и не получает килобайты схем.
                'tools' => is_array($tools) ? array_slice(array_map(static function ($tool) {
                    $tool = (array) $tool;

                    return [
                        'name' => mb_substr((string) ($tool['name'] ?? ''), 0, 80),
                        'description' => mb_substr((string) ($tool['description'] ?? ''), 0, 200),
                    ];
                }, $tools), 0, 12) : [],
            ];
        }

        return $out;
    }

    /**
     * Пометить карточки как взятые в работу (клейм на 60 минут).
     *
     * Клейм снимается в `apply()` — сразу после сохранения перевода. Если
     * задача упала, карточки освободятся сами по истечении срока: ничего не
     * «залипает» навсегда, и следующий прогон их снова возьмёт.
     */
    protected static function _claim(array $slugs, $job_id = 0)
    {
        $slugs = array_slice(array_values(array_filter(array_map('strval', $slugs))), 0, 100);
        $params = [];

        foreach ($slugs as $index => $slug)
        {
            $params[':c'.$index] = $slug;
        }

        if ($params === [])
        {
            return;
        }

        try
        {
            Service_Nj676_McpHub::query_raw(
                'UPDATE nj_676_servers SET ru_claimed_at = now(), ru_claimed_job = :job
                  WHERE slug IN ('.implode(',', array_keys($params)).')',
                $params + [':job' => max(0, (int) $job_id)]
            );
        }
        catch (Throwable $e)
        {
            // Клейм — оптимизация, а не условие работы: молча живём без него.
            Log_Plain::event(Log::DEBUG, 'nj676: клейм перевода не поставлен: :e',
                [':e' => $e->getMessage()]);
        }
    }

    /**
     * Отпустить клейм с карточек, которые воркер не осилил.
     *
     * Иначе пропущенная карточка висела бы «в работе» до истечения срока, и
     * повторное нажатие «Перевести всё» её не видело.
     */
    public static function release(array $slugs)
    {
        $params = [];

        foreach (array_slice(array_values(array_filter(array_map('strval', $slugs))), 0, 100) as $index => $slug)
        {
            $params[':r'.$index] = mb_substr($slug, 0, 100);
        }

        if ($params === [])
        {
            return 0;
        }

        try
        {
            Service_Nj676_McpHub::query_raw(
                'UPDATE nj_676_servers
                    SET ru_claimed_at = NULL, ru_claimed_job = NULL
                  WHERE slug IN ('.implode(',', array_keys($params)).')
                    AND coalesce(name_ru, \'\') = \'\'',
                $params
            );
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: клейм перевода не снят: :e',
                [':e' => $e->getMessage()]);

            return 0;
        }

        return count($params);
    }

    /**
     * Принять переводы от воркера.
     *
     * @param  array $items [{slug, name_ru, description_ru}, …]
     * @return array{saved:int,skipped:int}
     */
    public static function apply(array $items)
    {
        $out = ['saved' => 0, 'skipped' => 0, 'slugs' => []];

        foreach (array_slice($items, 0, 100) as $item)
        {
            if ( ! is_array($item))
            {
                $out['skipped']++;
                continue;
            }

            $slug = mb_substr(trim((string) ($item['slug'] ?? '')), 0, 100);
            $name_ru = mb_substr(trim((string) ($item['name_ru'] ?? '')), 0, 160);
            $description_ru = mb_substr(trim((string) ($item['description_ru'] ?? '')), 0, 4000);

            if ($slug === '' OR $name_ru === '')
            {
                $out['skipped']++;
                continue;
            }

            try
            {
                $row = Service_Nj676_McpHub::query_raw(
                    'SELECT slug, name, description, categories, topics, tools, ru_source
                       FROM nj_676_servers WHERE slug = :slug LIMIT 1',
                    [':slug' => $slug]
                )->current();
            }
            catch (Throwable $e)
            {
                $out['skipped']++;
                continue;
            }

            if ( ! $row)
            {
                $out['skipped']++;
                continue;
            }

            $row = (array) $row;

            // Ручной перевод — редакционный актив: модель его не трогает.
            if ((string) ($row['ru_source'] ?? '') === 'manual')
            {
                $out['skipped']++;
                continue;
            }

            $record = [
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'name_ru' => $name_ru,
                'description_ru' => $description_ru,
                'categories' => json_decode((string) ($row['categories'] ?? '[]'), TRUE) ?: [],
                'topics' => json_decode((string) ($row['topics'] ?? '[]'), TRUE) ?: [],
                'tools' => json_decode((string) ($row['tools'] ?? '[]'), TRUE) ?: [],
            ];

            try
            {
                Service_Nj676_McpHub::query_raw(
                    "UPDATE nj_676_servers
                        SET name_ru = :name_ru,
                            description_ru = :description_ru,
                            ru_source = 'llm',
                            ru_at = now(),
                            ru_claimed_at = NULL,
                            ru_claimed_job = NULL,
                            search_text = :search_text
                      WHERE slug = :slug",
                    [
                        ':name_ru' => $name_ru,
                        ':description_ru' => $description_ru,
                        // Поисковый текст пересобираем сразу: иначе русский
                        // запрос не найдёт карточку до следующего сохранения.
                        ':search_text' => Service_Nj676_McpHub::search_text($record),
                        ':slug' => $slug,
                    ]
                );

                $out['saved']++;
                // Сохранённые слаги возвращаем вызывающему: после перевода
                // текст карточки изменился, и её вектор нужно пересчитать
                // (иначе русский запрос ищет по английскому тексту).
                $out['slugs'][] = $slug;
            }
            catch (Throwable $e)
            {
                $out['skipped']++;

                Log_Plain::event(Log::DEBUG, 'nj676: перевод :s не сохранён: :e',
                    [':s' => $slug, ':e' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * Поставить перевод в очередь nj120.
     *
     * @return array{job_id:int,limit:int,error:string}
     */
    public static function queue($limit = self::BATCH, $mode = 'batch')
    {
        $out = ['job_id' => 0, 'limit' => max(1, min(100, (int) $limit)), 'error' => '', 'mode' => $mode];

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            $out['error'] = 'Очередь задач nj120 не подключена.';

            return $out;
        }

        try
        {
            $out['job_id'] = (int) Service_Nj120_JobQueue::push(self::CHANNEL, [
                'limit' => $out['limit'],
                'mode' => $mode,
            ], [
                'module' => 'nj676',
                'priority' => 120,
                // 30 минут: перевод 20 карточек пачками по 5 — это четыре
                // вызова модели, а рассуждающая модель легко тратит 3–4
                // минуты на вызов. Прежние 900 с обрывали задачу на середине
                // (23.10.2026: перевелись 5 карточек и всё).
                'timeout_sec' => 1800,
            ] + self::_model_option());
        }
        catch (Throwable $e)
        {
            $out['error'] = mb_substr($e->getMessage(), 0, 200);

            Log_Plain::event(Log::WARNING, 'nj676: перевод не поставлен в очередь: :e', [':e' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * «Перевести всё»: цепочка независимых задач на весь оставшийся каталог.
     *
     * Каждая задача переводит до `ALL_JOB` карточек и не знает о соседях.
     * Так один сбой не обнуляет работу: упавшая задача видна в мониторе, а
     * следующие продолжают брать карточки с начала списка «без перевода».
     *
     * @return array{jobs:array<int,int>,job:array<string,mixed>,pending:int,count:int,error:string}
     */
    public static function queue_all($per_job = NULL, $max_jobs = self::ALL_MAX_JOBS)
    {
        $per_job = $per_job === NULL ? self::job_size() : (int) $per_job;
        $per_job = max(1, min(100, (int) $per_job));
        $max_jobs = max(1, min(self::ALL_MAX_JOBS, (int) $max_jobs));

        $status = self::status();
        // Ставим задачи только на свободные карточки: взятые другой задачей
        // всё равно не достанутся (клейм), и такая задача была бы пустой.
        $pending = (int) ($status['free'] ?? 0);
        $out = [
            'jobs' => [], 'pending' => $pending, 'count' => 0,
            'total' => (int) ($status['pending'] ?? 0),
            'claimed' => (int) ($status['claimed'] ?? 0),
            'released' => 0,
            'error' => '', 'job' => ['job_id' => 0, 'limit' => $per_job, 'error' => ''],
        ];

        // Все карточки «в работе», а живых задач нет — значит клеймы висят за
        // задачами, которых уже нет (24.10.2026: 25 карточек, пустая очередь,
        // выключенные кнопки). Снимаем их и продолжаем: это не «подождите час».
        if ($pending <= 0 AND (int) $out['total'] > 0
            AND (int) ($status['queue']['active'] ?? 0) === 0)
        {
            $out['released'] = self::release_all();
            $status = self::status();
            $pending = (int) ($status['free'] ?? 0);
            $out['pending'] = $pending;
            $out['claimed'] = (int) ($status['claimed'] ?? 0);
        }

        if ($pending <= 0)
        {
            return $out;
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            $out['error'] = 'Очередь задач nj120 не подключена.';

            return $out;
        }

        $jobs = (int) min($max_jobs, max(1, (int) ceil($pending / $per_job)));
        $model = self::_model_option();

        for ($i = 0; $i < $jobs; $i++)
        {
            try
            {
                $job_id = (int) Service_Nj120_JobQueue::push(self::CHANNEL, [
                    'limit' => $per_job,
                    'mode' => 'all',
                    // Номер в цепочке — только для журнала: задачи независимы.
                    'chain' => $i + 1,
                ], [
                    'module' => 'nj676',
                    'priority' => 120,
                    // Задача на 40 карточек = 8 вызовов модели; 1800 с хватает
                    // с запасом (даже если модель думает по 3 минуты), а сторож
                    // задачи больше не рубит работу на середине.
                    'timeout_sec' => 1800,
                ] + $model);

                if ($job_id > 0)
                {
                    $out['jobs'][] = $job_id;
                }
            }
            catch (Throwable $e)
            {
                $out['error'] = mb_substr($e->getMessage(), 0, 200);

                Log_Plain::event(Log::WARNING, 'nj676: задача перевода не поставлена: :e', [':e' => $e->getMessage()]);

                break;
            }
        }

        $out['count'] = count($out['jobs']);

        if ($out['count'] > 0)
        {
            $out['job'] = ['job_id' => $out['jobs'][0], 'limit' => $per_job, 'error' => ''];
        }

        return $out;
    }

    /**
     * Продолжить перевод после прогона, если остались карточки.
     *
     * Зачем. Задача «Перевести всё» берёт 40 карточек, а бюджет времени
     * кончается раньше — и в очереди не остаётся ничего. Владелец видел
     * «осталось 25» и пустую очередь: работа не идёт, а кнопки выключены
     * (24.10.2026). Здесь сайт сам ставит следующую порцию, если предыдущий
     * прогон реально двигался.
     *
     * Предохранители: продолжаем только когда прогон перевёл хоть что-то,
     * живых задач нет, и не больше 3 продолжений в час. Иначе сбойная модель
     * крутила бы бесконечную цепочку за наши токены.
     *
     * @param int $max_jobs сколько задач ставить на продолжение
     * @return int сколько задач поставлено
     */
    public static function queue_continuation($max_jobs = 3)
    {
        $status = self::status();

        if ((int) ($status['pending'] ?? 0) <= 0)
        {
            return 0;
        }

        // Задачи уже работают — продолжение не нужно, они сами возьмут карточки.
        if ((int) ($status['queue']['active'] ?? 0) > 0)
        {
            return 0;
        }

        try
        {
            $cache = Cache::instance('file');
            $key = 'nj676_translate_chain_'.date('YmdH');
            $count = (int) $cache->get($key, 0);

            if ($count >= 3)
            {
                Log_Plain::event(Log::DEBUG, 'nj676: продолжение перевода отложено (лимит в час)');

                return 0;
            }

            $batch = self::queue_all(NULL, max(1, min(self::ALL_MAX_JOBS, (int) $max_jobs)));

            if ((int) $batch['count'] > 0)
            {
                $cache->set($key, $count + 1, 3 * 3600);
            }

            return (int) $batch['count'];
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: продолжение перевода не поставлено: :e',
                [':e' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Запомнить отчёт последнего прогона перевода (его присылает воркер).
     *
     * Без этого админка показывает только счётчики: «переведено 5 из 1037» — и
     * ни слова о том, что модель ответила не JSON или сайт отбил пачку. Владелец
     * в такой ситуации не может понять, чинить модель или очередь.
     *
     * @param array $stats {translated,skipped,errors,remaining,note}
     */
    public static function remember_result(array $stats)
    {
        $clean = [
            'at' => date('c'),
            'translated' => max(0, (int) ($stats['translated'] ?? 0)),
            'skipped' => max(0, (int) ($stats['skipped'] ?? 0)),
            'remaining' => isset($stats['remaining']) ? max(0, (int) $stats['remaining']) : NULL,
            'note' => mb_substr(trim((string) ($stats['note'] ?? '')), 0, 300),
            'errors' => [],
        ];

        foreach ((array) ($stats['errors'] ?? []) as $error)
        {
            $error = mb_substr(trim((string) $error), 0, 200);

            if ($error !== '')
            {
                $clean['errors'][] = $error;
            }
        }

        $clean['errors'] = array_slice($clean['errors'], 0, 5);

        try
        {
            Cache::instance('file')->set(self::CACHE_KEY, $clean, 2592000);

            // Счётчик прогонов: по нему видно, что задачи вообще доходят до
            // воркера, даже когда переводить уже нечего.
            Cache::instance('file')->set(self::CACHE_KEY.'_count',
                (int) Cache::instance('file')->get(self::CACHE_KEY.'_count', 0) + 1, 2592000);
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: отчёт перевода не сохранён: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Отчёт последнего прогона перевода; NULL — прогонов ещё не было. */
    public static function last_result()
    {
        try
        {
            $cached = Cache::instance('file')->get(self::CACHE_KEY);

            return is_array($cached) ? $cached : NULL;
        }
        catch (Throwable $e)
        {
            return NULL;
        }
    }

    /**
     * Сколько карточек берёт одна задача «Перевести всё».
     *
     * Размер задаётся `NJ676_TRANSLATE_JOB` (10–100): меньше задач — быстрее
     * виден результат, больше — меньше накладных расходов на очередь.
     */
    public static function job_size()
    {
        $size = (int) getenv('NJ676_TRANSLATE_JOB');

        return $size >= 10 ? min(100, $size) : self::ALL_JOB;
    }

    /** Опция модели для очереди: пустая строка не нужна. */
    protected static function _model_option()
    {
        $model = trim((string) getenv('NJ676_TRANSLATE_MODEL'));

        return $model !== '' ? ['model' => $model] : [];
    }
}
