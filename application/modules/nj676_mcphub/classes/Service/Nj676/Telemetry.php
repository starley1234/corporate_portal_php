<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Телеметрия шлюза (nj676_mcphub): вызовы, отзывы, надёжность.
 *
 * Зачем отдельный класс. Раньше «качеством» каталога считались звёзды GitHub,
 * а у шлюза не оставалось следов собственных вызовов: агент получал ошибку и
 * уходил, сервер с двумя неудачами из трёх оставался в топе, а сказать «этот
 * сервер врёт» было нечем.
 *
 * Что здесь:
 *   * `record()` — каждый вызов через шлюз получает `call_id`; в журнал идёт
 *     сервер, инструмент, успех/ошибка, задержка и КОД ошибки (stable codes,
 *     а не текст);
 *   * `stats_by_server()` — успешность по Wilson lower bound: «1 вызов, 1
 *     успех» не должно обгонять «120 вызовов, 96 %»; плюс p95 задержки;
 *   * `feedback()` — отзыв принимается только по реальному `call_id`, один раз
 *     на вызов: накрутить отзывы без вызовов нельзя;
 *   * `prune()` — журнал вызовов и отзывы чистятся по возрасту.
 *
 * Все методы мягкие: телеметрия не имеет права ломать вызов инструмента.
 * Ошибки только пишутся в лог.
 */
class Service_Nj676_Telemetry
{
    /** Сколько дней хранить журнал вызовов (NJ676_CALLS_KEEP_DAYS). */
    const KEEP_DAYS = 365;
    /** Сколько дней учитывать в рейтинге надёжности. */
    const WINDOW_DAYS = 30;
    /** Допустимые оценки: -1 (вниз), 1 (вверх), 1..5 звёзд. */
    const OUTCOMES = ['success', 'wrong_data', 'slow', 'auth_error', 'crash'];

    /**
     * Записать вызов и выдать `call_id`.
     *
     * @param  string      $slug    slug сервера каталога ('' для сервиса N2J)
     * @param  string      $tool    имя инструмента
     * @param  bool        $ok      успешно ли
     * @param  int         $ms      задержка
     * @param  string      $code    stable code ошибки ('' при успехе)
     * @param  string|null $hash    sha256 ключа агента
     * @return string      call_id (пустая строка, если запись не удалась)
     */
    public static function record($slug, $tool, $ok, $ms, $code = '', $hash = NULL)
    {
        $call_id = self::_new_call_id();

        try
        {
            DB::query(Database::INSERT,
                'INSERT INTO nj_676_calls (call_id, token_hash, server_slug, tool, ok, latency_ms, error_code)
                 VALUES (:call_id, :hash, :slug, :tool, :ok, :ms, :code)')
                ->param(':call_id', $call_id)
                ->param(':hash', mb_substr((string) $hash, 0, 64, 'UTF-8'))
                ->param(':slug', mb_substr((string) $slug, 0, 120, 'UTF-8'))
                ->param(':tool', mb_substr((string) $tool, 0, 160, 'UTF-8'))
                ->param(':ok', $ok ? TRUE : FALSE)
                ->param(':ms', max(0, (int) $ms))
                ->param(':code', mb_substr((string) $code, 0, 32, 'UTF-8'))
                ->execute();
        }
        catch (Throwable $e)
        {
            // Без журнала вызов всё равно должен вернуть результат агенту;
            // `call_id` остаётся валидным только для отзыва — отзыв тогда
            // отклонится как «неизвестный вызов», и это честно.
            Log_Plain::event(Log::WARNING, 'nj676: вызов не записан в телеметрию: :e',
                [':e' => $e->getMessage()]);
        }

        return $call_id;
    }

    /**
     * Надёжность по серверам каталога за окно WINDOW_DAYS.
     *
     * @param  array $slugs какие серверы интересуют (пусто — все)
     * @return array slug => ['calls','ok','rate','wilson','p95','last_at','feedback','up','down']
     */
    public static function stats_by_server(array $slugs = [])
    {
        $out = [];
        $params = [':days' => self::WINDOW_DAYS];
        $where = '';

        if ($slugs !== [])
        {
            $names = [];
            foreach (array_values($slugs) as $index => $slug)
            {
                $key = ':s'.$index;
                $names[] = $key;
                $params[$key] = (string) $slug;
            }
            $where = ' AND server_slug IN ('.implode(', ', $names).')';
        }

        try
        {
            $rows = self::_all(
                // Ошибки аргументов — вина агента, а не сервера: клиент прислал
                // неверный запрос, сервер корректно отказал. В знаменателе
                // успешности им не место (тест 24.10.2026: две опечатки агента
                // уронили open-meteo до 60% и «сбоит»).
                "SELECT server_slug,
                        count(*) FILTER (WHERE error_code NOT IN ('INVALID_ARGUMENT')) AS calls,
                        count(*) FILTER (WHERE ok) AS ok_calls,
                        COALESCE(percentile_disc(0.95) WITHIN GROUP (ORDER BY latency_ms), 0) AS p95,
                        max(created_at) AS last_at
                   FROM nj_676_calls
                  WHERE created_at > now() - make_interval(days => :days)".$where."
                  GROUP BY server_slug",
                $params
            );
        }
        catch (Throwable $e)
        {
            return [];
        }

        foreach ($rows as $row)
        {
            $calls = (int) ($row['calls'] ?? 0);
            $ok_calls = (int) ($row['ok_calls'] ?? 0);

            $out[(string) ($row['server_slug'] ?? '')] = [
                'calls' => $calls,
                'ok' => $ok_calls,
                'rate' => $calls > 0 ? round($ok_calls / $calls, 3) : 0.0,
                'wilson' => self::wilson($ok_calls, $calls),
                'p95' => (int) ($row['p95'] ?? 0),
                'last_at' => (string) ($row['last_at'] ?? ''),
                'feedback' => 0, 'up' => 0, 'down' => 0,
            ];
        }

        // Отзывы добавляем отдельным запросом: они реже вызовов, и их не должно
        // быть видно в знаменателе успешности (иначе один «палец вниз» весил
        // бы как проваленный вызов).
        try
        {
            $feedback = self::_all(
                "SELECT c.server_slug,
                        count(*) AS total,
                        count(*) FILTER (WHERE f.rating > 0) AS up,
                        count(*) FILTER (WHERE f.rating < 0) AS down
                   FROM nj_676_feedback f
                   JOIN nj_676_calls c ON c.call_id = f.call_id
                  WHERE f.created_at > now() - make_interval(days => :days)".$where."
                  GROUP BY c.server_slug",
                $params
            );
        }
        catch (Throwable $e)
        {
            $feedback = [];
        }

        foreach ($feedback as $row)
        {
            $slug = (string) ($row['server_slug'] ?? '');
            if ($slug === '')
            {
                continue;
            }

            if ( ! isset($out[$slug]))
            {
                $out[$slug] = [
                    'calls' => 0, 'ok' => 0, 'rate' => 0.0, 'wilson' => 0.0,
                    'p95' => 0, 'last_at' => '', 'feedback' => 0, 'up' => 0, 'down' => 0,
                ];
            }

            $out[$slug]['feedback'] = (int) ($row['total'] ?? 0);
            $out[$slug]['up'] = (int) ($row['up'] ?? 0);
            $out[$slug]['down'] = (int) ($row['down'] ?? 0);
        }

        foreach ($out as $slug => &$stat_row)
        {
            $stat_row['agent_rating'] = self::compute_agent_rating(
                (int) ($stat_row['up'] ?? 0),
                (int) ($stat_row['down'] ?? 0),
                (int) ($stat_row['calls'] ?? 0),
                (float) ($stat_row['rate'] ?? 0.0)
            );
        }
        unset($stat_row);

        return $out;
    }

    /**
     * Рейтинг сервера (1.00–5.00) на основании отзывов агентов с байесовским сглаживанием.
     *
     * Положительный отзыв (`up`) весит 5.0, отрицательный (`down`) — 1.0;
     * априорное ожидание (вес 1.5 голоса) учитывает успешность живых вызовов,
     * чтобы один отзыв не давал 5.00/5.00 вровень с десятью отзывами.
     * Если отзывов нет (`up + down = 0`), возвращает NULL.
     *
     * @param  int        $up
     * @param  int        $down
     * @param  int        $calls
     * @param  float|null $success_rate  0..1
     * @return float|null
     */
    public static function compute_agent_rating($up, $down, $calls = 0, $success_rate = NULL)
    {
        $up = max(0, (int) $up);
        $down = max(0, (int) $down);
        $total = $up + $down;

        if ($total <= 0)
        {
            return NULL;
        }

        $prior_weight = 1.5;
        $prior_rating = ($calls > 0 AND $success_rate !== NULL)
            ? max(2.0, min(4.5, 2.5 + 2.0 * (float) $success_rate))
            : 3.75;

        $score = (($up * 5.0) + ($down * 1.0) + ($prior_weight * $prior_rating)) / ($total + $prior_weight);

        return round(max(1.0, min(5.0, $score)), 2);
    }

    /**
     * Нижняя граница доверительного интервала Уилсона: надёжность без обмана
     * малой выборкой. 1/1 даёт 0.21, 115/120 — 0.92.
     */
    public static function wilson($ok, $total, $z = 1.96)
    {
        $total = (int) $total;
        if ($total <= 0)
        {
            return 0.0;
        }

        $ok = max(0, min($total, (int) $ok));
        $p = $ok / $total;
        $z2 = $z * $z;
        $denom = 1 + $z2 / $total;
        $centre = $p + $z2 / (2 * $total);
        $margin = $z * sqrt(($p * (1 - $p) + $z2 / (4 * $total)) / $total);

        return round(max(0.0, ($centre - $margin) / $denom), 3);
    }

    /**
     * Отзыв агента по вызову.
     *
     * @param  string      $call_id
     * @param  string      $rating  up|down|1..5
     * @param  string      $outcome success|wrong_data|slow|auth_error|crash|''
     * @param  array       $tags
     * @param  string      $comment
     * @param  string|null $hash    sha256 ключа (для антинакрутки: один голос на вызов)
     * @return array{ok:bool,code:string,message:string}
     */
    public static function feedback($call_id, $rating, $outcome = '', array $tags = [], $comment = '', $hash = NULL)
    {
        $call_id = trim((string) $call_id);

        if ($call_id === '' OR ! preg_match('/^call_[A-Za-z0-9]{6,36}$/', $call_id))
        {
            return ['ok' => FALSE, 'code' => 'INVALID_ARGUMENT',
                'message' => 'Для записи отзыва передайте call_id (call_…) из ответа hub_call_tool и rating (up/down). Для чтения отзывов передайте {"server":"<slug>","limit":10}.'];
        }

        $raw = mb_strtolower(trim((string) $rating));
        $numeric = 0;

        if (in_array($raw, ['up', 'good', 'yes', 'ok'], TRUE))
        {
            $numeric = 1;
        }
        elseif (in_array($raw, ['down', 'bad', 'no'], TRUE))
        {
            $numeric = -1;
        }
        elseif (preg_match('/^-?[0-9]+$/', $raw))
        {
            // 1..5 — звёзды: 3 и выше «за», ниже — «против».
            $stars = (int) $raw;
            $numeric = $stars >= 3 ? 1 : -1;
        }
        else
        {
            return ['ok' => FALSE, 'code' => 'INVALID_ARGUMENT',
                'message' => 'rating: up, down или 1..5.'];
        }

        $outcome = mb_strtolower(trim((string) $outcome));
        if ($outcome !== '' AND ! in_array($outcome, self::OUTCOMES, TRUE))
        {
            return ['ok' => FALSE, 'code' => 'INVALID_ARGUMENT',
                'message' => 'outcome: '.implode(', ', self::OUTCOMES).'.'];
        }

        $clean_tags = [];
        foreach (array_slice((array) $tags, 0, 8) as $tag)
        {
            $tag = mb_substr(trim((string) $tag), 0, 40, 'UTF-8');
            if ($tag !== '')
            {
                $clean_tags[] = $tag;
            }
        }

        try
        {
            $call = DB::query(Database::SELECT,
                'SELECT call_id, token_hash, server_slug, tool FROM nj_676_calls WHERE call_id = :id LIMIT 1')
                ->param(':id', $call_id)
                ->execute()->current();
        }
        catch (Throwable $e)
        {
            return ['ok' => FALSE, 'code' => 'UPSTREAM_ERROR', 'message' => 'Журнал вызовов недоступен.'];
        }

        if ( ! $call)
        {
            // Это ошибка В АРГУМЕНТЕ, а не «инструмент не найден»: агент,
            // читающий code, иначе ищет пропавший инструмент вместо опечатки
            // в call_id (замечание теста 24.10.2026).
            return ['ok' => FALSE, 'code' => 'INVALID_ARGUMENT', 'field' => 'call_id',
                'message' => 'Вызова с таким call_id нет: отзыв принимается только по реальному вызову через hub_call_tool.'];
        }

        // Чужой вызов: ключ агента не должен оценивать вызовы другого ключа,
        // иначе накрутка становится тривиальной.
        if ( ! empty($call['token_hash']) AND ! empty($hash) AND (string) $call['token_hash'] !== (string) $hash)
        {
            return ['ok' => FALSE, 'code' => 'AUTH_REQUIRED',
                'message' => 'Этот call_id принадлежит другому ключу.'];
        }

        // Повторный отзыв по тому же вызову: проверяем заранее. `execute()`
        // у INSERT ... ON CONFLICT DO NOTHING не сообщает «0 строк», поэтому
        // счётчик затронутых строк тут врёт — нужен явный SELECT.
        $duplicate = FALSE;
        try
        {
            $duplicate = (bool) DB::query(Database::SELECT,
                'SELECT 1 AS exists FROM nj_676_feedback WHERE call_id = :id LIMIT 1')
                ->param(':id', $call_id)
                ->execute()->current();
        }
        catch (Throwable $e)
        {
            $duplicate = FALSE;
        }

        if ( ! $duplicate)
        {
            try
            {
                DB::query(Database::INSERT,
                    "INSERT INTO nj_676_feedback (call_id, rating, outcome, tags, comment, token_hash)
                     VALUES (:id, :rating, :outcome, CAST(:tags AS jsonb), :comment, :hash)
                     ON CONFLICT (call_id) DO NOTHING")
                    ->param(':id', $call_id)
                    ->param(':rating', $numeric)
                    ->param(':outcome', $outcome)
                    ->param(':tags', json_encode(array_values($clean_tags), JSON_UNESCAPED_UNICODE))
                    ->param(':comment', mb_substr(trim((string) $comment), 0, 1000, 'UTF-8'))
                    ->param(':hash', mb_substr((string) $hash, 0, 64, 'UTF-8'))
                    ->execute();
            }
            catch (Throwable $e)
            {
                return ['ok' => FALSE, 'code' => 'UPSTREAM_ERROR', 'message' => 'Отзыв не записался: '.$e->getMessage()];
            }
        }

        // У сервисов платформы slug пуст: «сервер «» / nj594_org_search» ничего
        // не объясняет ни агенту, ни человеку в отчёте (тест 24.10.2026).
        $slug = trim((string) $call['server_slug']);
        $tool = trim((string) $call['tool']);
        $target = $slug === '' ? 'N2J platform' : '«'.$slug.'»';

        if ( ! empty($duplicate))
        {
            $message = 'Отзыв по этому вызову уже есть — повторно он не учитывается: '.$target
                .($tool !== '' ? ' / '.$tool : '').'.';
        }
        else
        {
            $message = 'Спасибо! Отзыв учтён в надёжности: '.$target
                .($tool !== '' ? ' / '.$tool : '').'.';

            if ($slug !== '')
            {
                try
                {
                    $delta = $numeric > 0 ? 2 : -5;
                    $summary = self::feedback_summary($slug);
                    $agent_rating = $summary['agent_rating'] !== NULL ? (float) $summary['agent_rating'] : 0.0;
                    $reviews_count = (int) ($summary['total'] ?? 0);

                    DB::query(Database::UPDATE,
                        'UPDATE nj_676_servers
                            SET trust = LEAST(100, GREATEST(0, (CASE WHEN trust <= 0 THEN 50 ELSE trust END) + :delta)),
                                agent_rating = :agent_rating,
                                agent_reviews_count = :reviews_count
                          WHERE slug = :slug')
                        ->param(':delta', $delta)
                        ->param(':agent_rating', $agent_rating)
                        ->param(':reviews_count', $reviews_count)
                        ->param(':slug', $slug)
                        ->execute();
                }
                catch (Throwable $e)
                {
                    // Телеметрия не должна ронять ответ отзыва при сбое обновления trust.
                }

                $message .= ' Сервер учтён в рейтинге каталога.';
            }
        }

        return ['ok' => TRUE, 'code' => '', 'rating' => $numeric,
            'server_slug' => $slug,
            'server_label' => $slug === '' ? 'N2J platform' : $slug,
            'tool' => $tool,
            'duplicate' => ! empty($duplicate),
            'message' => $message];
    }

    /**
     * Последние отзывы агентов по серверу (или по всему каталогу, если slug пуст).
     *
     * Нужны в hub_describe_tool, hub_feedback (режим чтения) и REST /mcphub/api/feedback,
     * чтобы агенты видели не только агрегат trust, но и конкретные комментарии/теги.
     *
     * @param  string $slug
     * @param  int    $limit
     * @return array<int,array<string,mixed>>
     */
    public static function recent_feedback($slug = '', $limit = 10)
    {
        $slug = trim((string) $slug);
        $limit = max(1, min(50, (int) $limit));
        $params = [':limit' => $limit];
        $where = "c.server_slug <> ''";

        if ($slug !== '')
        {
            $where = 'c.server_slug = :slug';
            $params[':slug'] = $slug;
        }

        try
        {
            $rows = self::_all(
                'SELECT f.call_id, c.server_slug, c.tool, f.rating, f.outcome, f.tags, f.comment, f.created_at
                   FROM nj_676_feedback f
                   JOIN nj_676_calls c ON c.call_id = f.call_id
                  WHERE '.$where.'
                  ORDER BY f.created_at DESC
                  LIMIT :limit',
                $params
            );
        }
        catch (Throwable $e)
        {
            return [];
        }

        $out = [];
        foreach ($rows as $row)
        {
            $raw_tags = $row['tags'] ?? [];
            if (is_string($raw_tags))
            {
                $decoded = json_decode($raw_tags, TRUE);
                $raw_tags = is_array($decoded) ? $decoded : [];
            }

            $tags = [];
            foreach ((array) $raw_tags as $t)
            {
                $t = trim((string) $t);
                if ($t !== '')
                {
                    $tags[] = $t;
                }
            }

            $num = (int) ($row['rating'] ?? 0);
            $out[] = [
                'call_id' => (string) ($row['call_id'] ?? ''),
                'server' => (string) ($row['server_slug'] ?? ''),
                'tool' => (string) ($row['tool'] ?? ''),
                'rating' => $num > 0 ? 'up' : 'down',
                'rating_value' => $num,
                'outcome' => (string) ($row['outcome'] ?? ''),
                'tags' => $tags,
                'comment' => (string) ($row['comment'] ?? ''),
                'at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Сводка отзывов агентов по серверу (или всему каталогу, если slug пуст).
     *
     * @param  string $slug
     * @return array{total:int,up:int,down:int,positive_rate:float|null}
     */
    public static function feedback_summary($slug = '')
    {
        $slug = trim((string) $slug);
        $params = [];
        $where = "c.server_slug <> ''";

        if ($slug !== '')
        {
            $where = 'c.server_slug = :slug';
            $params[':slug'] = $slug;
        }

        try
        {
            $row = self::_one(
                'SELECT count(*) AS total,
                        count(*) FILTER (WHERE f.rating > 0) AS up,
                        count(*) FILTER (WHERE f.rating < 0) AS down
                   FROM nj_676_feedback f
                   JOIN nj_676_calls c ON c.call_id = f.call_id
                  WHERE '.$where,
                $params
            );
        }
        catch (Throwable $e)
        {
            $row = NULL;
        }

        $total = (int) ($row['total'] ?? 0);
        $up = (int) ($row['up'] ?? 0);
        $down = (int) ($row['down'] ?? 0);

        return [
            'total' => $total,
            'up' => $up,
            'down' => $down,
            'positive_rate' => $total > 0 ? round($up / $total, 3) : NULL,
            'agent_rating' => self::compute_agent_rating($up, $down),
        ];
    }

    /**
     * Серверы, которые уже падали у ЭТОГО агента.
     *
     * «Не показывай то, что у меня не работает» — сигнал сильнее звёзд: агент
     * тратит вызов на сервер, который у него дважды подряд упал. Считаем по
     * журналу вызовов ключа, без ручных списков.
     *
     * @return array список slug-ов (пусто, если ключ анонимный)
     */
    public static function failing_servers($hash, $days = 7, $min_errors = 2)
    {
        $hash = trim((string) $hash);

        if ($hash === '' OR strpos($hash, 'anon:') === 0)
        {
            return [];
        }

        $days = max(1, min(90, (int) $days));
        $min_errors = max(1, min(10, (int) $min_errors));

        try
        {
            $rows = DB::query(Database::SELECT,
                "SELECT server_slug
                   FROM nj_676_calls
                  WHERE token_hash = :hash
                    AND server_slug <> ''
                    AND created_at > now() - (:days || ' days')::interval
                  GROUP BY server_slug
                 HAVING count(*) FILTER (WHERE ok = FALSE AND error_code <> 'INVALID_ARGUMENT') >= :min_errors
                    AND count(*) FILTER (WHERE ok = TRUE) = 0
                  ORDER BY count(*) DESC
                  LIMIT 20")
                ->param(':hash', $hash)
                ->param(':days', (string) $days)
                ->param(':min_errors', $min_errors)
                ->execute()->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }

        $out = [];
        foreach ($rows as $row)
        {
            $slug = trim((string) ($row['server_slug'] ?? ''));

            if ($slug !== '')
            {
                $out[] = $slug;
            }
        }

        return $out;
    }

    /**
     * Сколько разных агентов пожаловались на сервер.
     *
     * Считаем уникальные ключи, а не отзывы: один агент, повторивший «down»
     * десять раз, не должен уронить сервер. Это же число — условие авто-демота.
     */
    public static function negative_agents($slug, $days = 7)
    {
        $days = max(1, min(90, (int) $days));

        try
        {
            $row = self::_one(
                "SELECT count(DISTINCT c.token_hash) AS agents
                   FROM nj_676_feedback f
                   JOIN nj_676_calls c ON c.call_id = f.call_id
                  WHERE c.server_slug = :slug AND f.rating < 0
                    AND c.token_hash <> ''
                    AND f.created_at > now() - make_interval(days => :days)",
                [':slug' => (string) $slug, ':days' => $days]
            );
        }
        catch (Throwable $e)
        {
            return 0;
        }

        return (int) ($row['agents'] ?? 0);
    }

    /** Счётчики для hub_status и админки. */
    public static function stats()
    {
        $out = ['calls' => 0, 'ok' => 0, 'feedback' => 0, 'up' => 0, 'down' => 0];

        try
        {
            $row = self::_one(
                "SELECT count(*) AS calls, count(*) FILTER (WHERE ok) AS ok_calls
                   FROM nj_676_calls WHERE created_at > now() - interval '7 days'");
            $fb = self::_one(
                "SELECT count(*) AS total,
                        count(*) FILTER (WHERE rating > 0) AS up,
                        count(*) FILTER (WHERE rating < 0) AS down
                   FROM nj_676_feedback WHERE created_at > now() - interval '30 days'");
        }
        catch (Throwable $e)
        {
            return $out;
        }

        return [
            'calls' => (int) ($row['calls'] ?? 0),
            'ok' => (int) ($row['ok_calls'] ?? 0),
            'feedback' => (int) ($fb['total'] ?? 0),
            'up' => (int) ($fb['up'] ?? 0),
            'down' => (int) ($fb['down'] ?? 0),
        ];
    }

    /** Удалить старые вызовы и отзывы (вызывается ночным пинком). */
    public static function prune()
    {
        $days = (int) getenv('NJ676_CALLS_KEEP_DAYS');
        $days = $days > 0 ? min(3650, $days) : self::KEEP_DAYS;

        try
        {
            DB::query(Database::DELETE,
                'DELETE FROM nj_676_calls WHERE created_at < now() - make_interval(days => :days)')
                ->param(':days', $days)
                ->execute();
            DB::query(Database::DELETE,
                'DELETE FROM nj_676_feedback WHERE created_at < now() - make_interval(days => :days)')
                ->param(':days', $days)
                ->execute();
        }
        catch (Throwable $e)
        {
            return FALSE;
        }

        return TRUE;
    }

    protected static function _new_call_id()
    {
        // call_ + 20 hex: короткий, читаемый, не угадывается (80 бит).
        return 'call_'.bin2hex(random_bytes(10));
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
