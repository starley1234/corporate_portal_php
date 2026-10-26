<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Лаборатории LLM» (NJ-645).
 *
 * Публичные точки (без авторизации, но с лимитами по посетителю):
 *   POST /llmlab/api/tokenize        текст → токены мини-BPE + оценка и цена
 *   POST /llmlab/api/sample          контекст → распределение следующего токена
 *   POST /llmlab/api/guess           ход игры «человек против модели»
 *   POST /llmlab/api/corpus          текст в корпус (уходит на модерацию)
 *   POST /llmlab/api/similarity      два текста → косинусная близость
 *   POST /llmlab/api/vector_add      текст → точка семантической карты
 *   GET  /llmlab/api/vector_map      последние точки карты
 *   POST /llmlab/api/prompt_check    разбор промпта по чек-листу
 *   POST /llmlab/api/prompt_add      промпт в библиотеку
 *   POST /llmlab/api/prompt_vote     голос за промпт
 *   POST /llmlab/api/run             прогон реальной LLM (очередь nj120)
 *   GET  /llmlab/api/run_status      статус и ответы прогонов
 *   GET  /llmlab/api/quiz            вопросы квиза по глоссарию
 *   POST /llmlab/api/quiz_answer     ответ квиза (накопление статистики)
 *
 * Служебная точка воркера:
 *   POST /llmlab/api/worker_result   результат LLM-прогона (X-Worker-Token)
 */
class Controller_Nj645_Api extends Controller
{
    public $auto_render = FALSE;

    /** @var array тело запроса */
    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw = $this->request->body();

        if ($raw)
        {
            $decoded = json_decode($raw, TRUE);
            $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
        }
        else
        {
            $this->_body = (array) $this->request->post();
        }

        if (empty($this->_body) AND $this->request->query())
        {
            $this->_body = (array) $this->request->query();
        }
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $this->response;
    }

    protected function _fail($message, $status = 400)
    {
        return $this->_respond(['ok' => FALSE, 'error' => (string) $message], $status);
    }

    protected function _visitor()
    {
        return class_exists('Visitor') ? (string) Visitor::uid() : '';
    }

    protected function _user_id()
    {
        return class_exists('Visitor') ? Visitor::user_id() : NULL;
    }

    protected function _text($key, $max)
    {
        $text = (string) ($this->_body[$key] ?? '');
        $text = Service_Nj645_Lab::normalize_text($text);

        return mb_substr($text, 0, (int) $max);
    }

    protected function _int($key, $default = 0)
    {
        return isset($this->_body[$key]) ? (int) $this->_body[$key] : (int) $default;
    }

    protected function _float($key, $default = 0.0)
    {
        return isset($this->_body[$key]) ? (float) $this->_body[$key] : (float) $default;
    }

    /** Проверка токена воркера для служебных точек. */
    protected function _check_worker()
    {
        $token = (string) ($_SERVER['HTTP_X_WORKER_TOKEN'] ?? '');
        $want  = (string) getenv('WORKER_API_TOKEN');

        return ($want !== '' AND $token !== '' AND hash_equals($want, $token));
    }

    // ------------------------------------------------------------- токенизация

    public function action_tokenize()
    {
        $text = $this->_text('text', (int) Service_Nj645_Store::cfg('limits.text_max', 12000));

        if ($text === '')
        {
            return $this->_fail('Пустой текст');
        }

        $vocab = Service_Nj645_Store::vocabulary();
        $tokens = Service_Nj645_Lab::tokenize($text, $vocab);
        $est    = Service_Nj645_Lab::estimate_tokens($text);
        $lang   = Service_Nj645_Lab::detect_lang($text);

        $models = (array) Service_Nj645_Store::cfg('models', []);
        $answer = (int) Service_Nj645_Store::cfg('answer_tokens', 400);
        $costs  = [];

        foreach ($models as $key => $model)
        {
            $costs[] = Service_Nj645_Lab::cost($est['total'], $answer, $key, $models);
        }

        // Анонимный суточный агрегат: тексты не сохраняем, только объёмы.
        try
        {
            Service_Nj645_Store::tokenstat_add($lang, 'demo', mb_strlen($text), $tokens['count']);
        }
        catch (Throwable $e)
        {
            // агрегат не критичен для ответа
        }

        return $this->_respond([
            'ok'       => TRUE,
            'tokens'   => array_slice($tokens['tokens'], 0, 400),
            'count'    => $tokens['count'],
            'truncated' => $tokens['count'] > 400,
            'estimate' => $est,
            'lang'     => $lang,
            'vocab'    => ['words' => count($vocab['words']), 'pieces' => count($vocab['pieces'])],
            'costs'    => array_values(array_filter($costs)),
        ]);
    }

    // ----------------------------------------------------------- сэмплирование

    /** Контекст → токены контекста (последние два) для n-граммного запроса. */
    protected function _context_tokens($context)
    {
        $tokens = Service_Nj645_Lab::ngram_tokens($context);

        return array_slice($tokens, -2);
    }

    public function action_sample()
    {
        $context = $this->_text('context', (int) Service_Nj645_Store::cfg('limits.context_max', 240));

        if ($context === '')
        {
            return $this->_fail('Введите фразу, которую продолжит модель');
        }

        $tokens = $this->_context_tokens($context);

        if ( ! $tokens)
        {
            return $this->_fail('В контексте нет слов');
        }

        $ctx1 = $tokens[count($tokens) - 1];
        $ctx2 = count($tokens) > 1 ? $tokens[count($tokens) - 2].chr(31).$ctx1 : $ctx1.chr(31).chr(31);

        $rows = Service_Nj645_Store::ngram_rows($ctx2, $ctx1, 40);

        if ( ! $rows)
        {
            return $this->_respond([
                'ok'    => FALSE,
                'error' => 'В корпусе ещё нет такого контекста. Попробуйте другую фразу или добавьте текст в корпус — модель учится на нём.',
                'known_contexts' => (int) Service_Nj645_Store::stats()['ngrams'],
            ], 200);
        }

        $temperature = max(0.0, min(2.0, $this->_float('temperature', 0.7)));
        $top_k       = max(0, min(100, $this->_int('top_k', 0)));
        $top_p       = max(0.1, min(1.0, $this->_float('top_p', 1.0)));
        $seed        = $this->_int('seed', crc32($context));

        $distribution = Service_Nj645_Lab::distribution($rows, $temperature, $top_k, $top_p, 12);
        $sampled      = Service_Nj645_Lab::sample($distribution, $seed);

        return $this->_respond([
            'ok'          => TRUE,
            'context'     => $context,
            'items'       => $distribution['items'],
            'entropy'     => $distribution['entropy'],
            'candidates'  => $distribution['total'],
            'sampled'     => $sampled ? $sampled['token'] : '',
            'sampled_prob' => $sampled ? round($sampled['prob'], 4) : 0,
            'greedy'      => ! empty($distribution['greedy']),
            'params'      => ['temperature' => $temperature, 'top_k' => $top_k, 'top_p' => $top_p],
        ]);
    }

    public function action_guess()
    {
        $context = $this->_text('context', 240);
        $guess   = mb_substr((string) ($this->_body['guess'] ?? ''), 0, 80);

        if ($context === '' OR $guess === '')
        {
            return $this->_fail('Нужны контекст и ваш вариант');
        }

        $tokens = $this->_context_tokens($context);

        if ( ! $tokens)
        {
            return $this->_fail('В контексте нет слов');
        }

        $ctx1 = $tokens[count($tokens) - 1];
        $ctx2 = count($tokens) > 1 ? $tokens[count($tokens) - 2].chr(31).$ctx1 : $ctx1.chr(31).chr(31);

        $rows = Service_Nj645_Store::ngram_rows($ctx2, $ctx1, 20);

        if ( ! $rows)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Такого контекста в корпусе ещё нет — попробуйте другой'], 200);
        }

        // Модель играет детерминированно: одна и та же фраза — один и тот же токен.
        $distribution = Service_Nj645_Lab::distribution($rows, 0.7, 0, 1.0, 20);
        $model        = Service_Nj645_Lab::sample($distribution, crc32(mb_strtolower($context)));

        $model_token = $model ? (string) $model['token'] : '';
        $guess_norm  = mb_strtolower(trim($guess));
        $correct     = ($guess_norm !== '' AND $guess_norm === mb_strtolower($model_token));

        try
        {
            Service_Nj645_Store::guess_add(
                $this->_visitor(), $this->_user_id(), $context, $guess, $model_token,
                $model ? (float) $model['prob'] : 0.0, $correct, 0.7);
        }
        catch (Throwable $e)
        {
            // статистика не должна ломать игру
        }

        return $this->_respond([
            'ok'          => TRUE,
            'correct'     => $correct,
            'model_token' => $model_token,
            'model_prob'  => $model ? round((float) $model['prob'], 4) : 0,
            'alternatives' => array_slice(array_map(function ($item) {
                return ['token' => $item['token'], 'prob' => round($item['prob'], 4)];
            }, $distribution['items']), 0, 6),
            'stats'       => Service_Nj645_Store::guess_stats(),
        ]);
    }

    // ------------------------------------------------------------------ корпус

    public function action_corpus()
    {
        $text = $this->_text('text', (int) Service_Nj645_Store::cfg('limits.corpus_max', 6000));
        $min  = (int) Service_Nj645_Store::cfg('limits.corpus_min', 120);

        if (mb_strlen($text) < $min)
        {
            return $this->_fail('Текст короче '.$min.' символов — по нему нечему учиться. Добавьте связный абзац.');
        }

        if (empty($this->_body['consent']))
        {
            return $this->_fail('Нужно подтвердить, что текст можно использовать для обучения демонстраций');
        }

        $visitor = $this->_visitor();
        $per_day = (int) Service_Nj645_Store::cfg('limits.corpus_per_day', 10);

        if (Service_Nj645_Store::corpus_today($visitor) >= $per_day)
        {
            return $this->_fail('На сегодня хватит: не больше '.$per_day.' текстов в сутки. Приходите завтра.');
        }

        // Тип текста приводим в переменную: обращение к $this->_body['kind']
        // прямо в тернарнике падало с «Undefined array key», когда поле не
        // пришло (Kohana превращает warning в исключение — ответ 500).
        $kind = (string) ($this->_body['kind'] ?? 'prose');

        if ( ! in_array($kind, ['prose', 'code', 'dialog'], TRUE))
        {
            $kind = 'prose';
        }

        $result = Service_Nj645_Store::corpus_add(
            $text,
            $kind,
            Service_Nj645_Lab::detect_lang($text),
            (string) ($this->_body['title'] ?? ''),
            $visitor,
            $this->_user_id()
        );

        if ($result['status'] === 'known')
        {
            return $this->_respond(['ok' => TRUE, 'status' => 'known',
                'message' => 'Такой текст уже есть в корпусе — статистика не изменится, но спасибо.'], 200);
        }

        if ($result['status'] !== 'stored')
        {
            return $this->_fail($result['error'] ?: 'Не удалось сохранить текст', 500);
        }

        $est = Service_Nj645_Lab::estimate_tokens($text);

        return $this->_respond([
            'ok'      => TRUE,
            'status'  => 'pending',
            'id'      => $result['id'],
            'chars'   => mb_strlen($text),
            'tokens'  => $est['total'],
            'message' => 'Спасибо! Текст ждёт модерации: после проверки он увеличит n-граммную модель (и словарь мини-BPE).',
        ]);
    }

    // -------------------------------------------------------------- семантика

    /** Вектор текста: серверные эмбеддинги платформы или локальный хеш. */
    protected function _vector($text)
    {
        $dims = (int) Service_Nj645_Store::cfg('limits.vector_dims', 128);

        if (Service_Nj645_Lab::llm_available() AND class_exists('Llm'))
        {
            try
            {
                $vector = Llm::get_embedding($text);

                if (is_array($vector) AND count($vector) > 8)
                {
                    return ['vector' => array_map('floatval', $vector), 'source' => 'embedding'];
                }
            }
            catch (Throwable $e)
            {
                // нет эмбеддинг-сервера — работаем локально
            }
        }

        return ['vector' => Service_Nj645_Lab::local_embed($text, $dims), 'source' => 'local'];
    }

    public function action_similarity()
    {
        $a = $this->_text('a', 2000);
        $b = $this->_text('b', 2000);

        if ($a === '' OR $b === '')
        {
            return $this->_fail('Нужны два текста');
        }

        $va = $this->_vector($a);
        $vb = $this->_vector($b);

        // Векторы разной длины (сервер эмбеддингов против локального) сравнивать
        // нельзя: считаем оба локально, если источники разные.
        if ($va['source'] !== $vb['source'])
        {
            $dims = (int) Service_Nj645_Store::cfg('limits.vector_dims', 128);
            $va   = ['vector' => Service_Nj645_Lab::local_embed($a, $dims), 'source' => 'local'];
            $vb   = ['vector' => Service_Nj645_Lab::local_embed($b, $dims), 'source' => 'local'];
        }

        $cosine = Service_Nj645_Lab::cosine($va['vector'], $vb['vector']);

        return $this->_respond([
            'ok'        => TRUE,
            'cosine'    => $cosine,
            'label'     => Service_Nj645_Lab::similarity_label($cosine),
            'source'    => $va['source'],
            'spark_a'   => $this->_spark($va['vector']),
            'spark_b'   => $this->_spark($vb['vector']),
        ]);
    }

    public function action_vector_add()
    {
        $text = $this->_text('text', 2000);

        if ($text === '')
        {
            return $this->_fail('Введите текст');
        }

        $vec  = $this->_vector($text);
        $keep = (int) Service_Nj645_Store::cfg('limits.vectors_keep', 400);

        $points = Service_Nj645_Store::vectors_map($keep);
        $nearest = Service_Nj645_Lab::nearest($vec['vector'], $points,
            (int) Service_Nj645_Store::cfg('limits.neighbors', 8));

        $neighbors = array_slice(array_map(function ($p) {
            return [
                'id'         => (int) $p['id'],
                'label'      => (string) $p['label'],
                'text'       => (string) $p['text'],
                'similarity' => (float) $p['similarity'],
            ];
        }, $nearest), 0, 8);

        $id = Service_Nj645_Store::vectors_add(
            $this->_visitor(), (string) ($this->_body['label'] ?? ''), $text, $vec['vector'],
            $vec['source'], $neighbors);

        try
        {
            Service_Nj645_Store::vectors_trim($keep);
        }
        catch (Throwable $e)
        {
            // обрезка карты не критична
        }

        return $this->_respond([
            'ok'        => TRUE,
            'id'        => $id,
            'source'    => $vec['source'],
            'dims'      => count($vec['vector']),
            'neighbors' => $neighbors,
            'total'     => Service_Nj645_Store::vectors_count(),
        ]);
    }

    public function action_vector_map()
    {
        $points = Service_Nj645_Store::vectors_map(40);
        $out    = [];
        $vectors = [];

        foreach ($points as $point)
        {
            $vec = json_decode((string) $point['vector'], TRUE);
            $vec = is_array($vec) ? array_map('floatval', $vec) : [];

            if ( ! $vec)
            {
                continue;
            }

            $vectors[(int) $point['id']] = $vec;
        }

        // Проекция на две оси с наибольшим разбросом: честная «плоская картинка»
        // многомерного пространства без внешних библиотек.
        $axes = $this->_axes($vectors);

        foreach ($points as $point)
        {
            $id  = (int) $point['id'];
            $vec = $vectors[$id] ?? NULL;

            if ($vec === NULL)
            {
                continue;
            }

            $out[] = [
                'id'    => $id,
                'label' => (string) $point['label'] !== '' ? (string) $point['label'] : mb_substr((string) $point['text'], 0, 60),
                'source' => (string) $point['source'],
                'x'     => round($this->_dot_axis($vec, $axes['x']), 4),
                'y'     => round($this->_dot_axis($vec, $axes['y']), 4),
            ];
        }

        return $this->_respond(['ok' => TRUE, 'points' => $out, 'total' => Service_Nj645_Store::vectors_count()]);
    }

    /** Две оси с наибольшей дисперсией — дешёвое подобие главных компонент. */
    protected function _axes(array $vectors)
    {
        $dims = 0;

        foreach ($vectors as $vec)
        {
            $dims = max($dims, count($vec));
        }

        $variance = array_fill(0, max(1, $dims), 0.0);

        foreach ($vectors as $vec)
        {
            foreach ($vec as $i => $value)
            {
                $variance[$i] += $value * $value;
            }
        }

        arsort($variance);
        $keys = array_keys($variance);

        $x = $keys[0] ?? 0;
        $y = $keys[1] ?? ($keys[0] ?? 0);

        return [
            'x' => array_map(function ($i) use ($x) { return $i === $x ? 1.0 : 0.0; }, range(0, max(0, $dims - 1))),
            'y' => array_map(function ($i) use ($y) { return $i === $y ? 1.0 : 0.0; }, range(0, max(0, $dims - 1))),
        ];
    }

    protected function _dot_axis(array $vec, array $axis)
    {
        $sum = 0.0;
        $n   = min(count($vec), count($axis));

        for ($i = 0; $i < $n; $i++)
        {
            $sum += $vec[$i] * $axis[$i];
        }

        return $sum;
    }

    /** Мини-график вектора: 24 полоски для наглядности (без canvas). */
    protected function _spark(array $vector)
    {
        $out  = [];
        $step = max(1, (int) floor(count($vector) / 24));

        for ($i = 0; $i < count($vector); $i += $step)
        {
            $slice = array_slice($vector, $i, $step);
            $sum   = 0.0;

            foreach ($slice as $value)
            {
                $sum += abs($value);
            }

            $out[] = round($sum / $step, 4);
        }

        return array_slice($out, 0, 24);
    }

    // ----------------------------------------------------------------- промпты

    public function action_prompt_check()
    {
        $text = $this->_text('text', (int) Service_Nj645_Store::cfg('limits.prompt_max', 6000));

        if ($text === '')
        {
            return $this->_fail('Вставьте промпт');
        }

        return $this->_respond(['ok' => TRUE, 'report' => Service_Nj645_Lab::analyze_prompt($text)]);
    }

    public function action_prompt_add()
    {
        $text  = $this->_text('body', 2000);
        $title = mb_substr(trim((string) ($this->_body['title'] ?? '')), 0, 120);

        if (mb_strlen($text) < 40)
        {
            return $this->_fail('Промпт короче 40 символов — библиотеке нечего показать');
        }

        if ($title === '')
        {
            $title = mb_substr($text, 0, 80);
        }

        $slug = 'u-'.substr(hash('sha256', mb_strtolower($text)), 0, 16);

        // Один и тот же промпт не публикуется дважды.
        $exists = Service_Nj645_Store::db()->query(Database::SELECT,
            'SELECT id FROM nj_645_prompts WHERE slug = '.Service_Nj645_Store::db()->quote($slug), FALSE)->current();

        if ($exists)
        {
            return $this->_respond(['ok' => TRUE, 'status' => 'known', 'id' => (int) $exists['id'],
                'message' => 'Такой промпт уже есть в библиотеке.'], 200);
        }

        $goals = ['разбор', 'сжатие', 'данные', 'формат', 'логика', 'качество', 'текст', 'планирование',
            'rag', 'риски', 'обучение', 'промпты', 'другое'];
        $goal  = in_array((string) ($this->_body['goal'] ?? 'другое'), $goals, TRUE)
            ? (string) $this->_body['goal'] : 'другое';

        $tags = array_slice(array_values(array_filter(array_map(function ($tag) {
            return mb_substr(trim((string) $tag), 0, 24);
        }, explode(',', (string) ($this->_body['tags'] ?? ''))), 'strlen')), 0, 5);

        $id = Service_Nj645_Store::prompt_upsert($slug, $title, $goal, $tags, $text, 'user', $this->_user_id());

        if ( ! $id)
        {
            return $this->_fail('Не удалось сохранить промпт', 500);
        }

        return $this->_respond(['ok' => TRUE, 'status' => 'published', 'id' => $id,
            'message' => 'Промпт опубликован в библиотеке — за него можно голосовать.'], 200);
    }

    public function action_prompt_vote()
    {
        $id    = $this->_int('id');
        $value = $this->_int('value', 1);

        if ($id <= 0)
        {
            return $this->_fail('Неизвестный промпт');
        }

        $result = Service_Nj645_Store::prompt_vote($id, $this->_visitor(), $value);

        return $this->_respond(['ok' => TRUE, 'status' => $result['status']]);
    }

    // ------------------------------------------------------------------ прогоны

    public function action_run()
    {
        if ( ! Service_Nj645_Lab::llm_available())
        {
            return $this->_fail('На этом сервере LLM не подключена: демонстрации с реальной моделью недоступны. Попробуйте разбор промпта и «следующий токен» — они работают без внешнего ИИ.', 200);
        }

        $task = $this->_text('task', (int) Service_Nj645_Store::cfg('limits.task_max', 2000));

        if ($task === '')
        {
            return $this->_fail('Опишите задачу для модели');
        }

        $variants = [];

        foreach (['a', 'b'] as $key)
        {
            $prompt = $this->_text('prompt_'.$key, (int) Service_Nj645_Store::cfg('limits.prompt_max', 6000));

            if ($prompt !== '')
            {
                $variants[$key] = [
                    'label'   => mb_substr(trim((string) ($this->_body['label_'.$key] ?? mb_strtoupper($key))), 0, 40),
                    'prompt'  => $prompt,
                    'system'  => $this->_text('system_'.$key, 2000),
                    'temperature' => max(0.0, min(1.5, isset($this->_body['temperature_'.$key]) ? (float) $this->_body['temperature_'.$key] : 0.2)),
                ];
            }
        }

        if ( ! $variants)
        {
            return $this->_fail('Нужен хотя бы один вариант промпта');
        }

        $visitor  = $this->_visitor();
        $per_hour = (int) Service_Nj645_Store::cfg('limits.runs_per_hour', 20);

        if (Service_Nj645_Store::runs_last_hour($visitor) + count($variants) > $per_hour)
        {
            return $this->_fail('Лимит прогонов: не больше '.$per_hour.' в час. Это защита от лишних расходов на модель.', 429);
        }

        $series = substr(hash('sha256', $visitor.'|'.$task.'|'.microtime(TRUE)), 0, 20);
        $ids    = [];

        foreach ($variants as $key => $variant)
        {
            $id = Service_Nj645_Store::run_create([
                'user_id'     => $this->_user_id(),
                'visitor'     => $visitor,
                'mode'        => 'answer',
                'series'      => $series,
                'variant'     => ($key === 'a' ? 'A' : 'B').' · '.$variant['label'],
                'temperature' => $variant['temperature'],
                'system'      => $variant['system'],
                'prompt'      => $variant['prompt'],
                'status'      => 'queued',
            ]);

            try
            {
                $job_id = Service_Nj120_JobQueue::push('nj645.review', [
                    'run_id' => $id,
                    'mode'   => 'answer',
                    'task'   => $task,
                    'system' => $variant['system'],
                    'prompt' => $variant['prompt'],
                    'temperature' => $variant['temperature'],
                ], [
                    'module'      => 'nj645_llmlab',
                    'timeout_sec' => 300,
                    'max_attempts' => 2,
                ]);

                Service_Nj645_Store::run_set_job($id, $job_id);
            }
            catch (Throwable $e)
            {
                Service_Nj645_Store::run_apply($id, ['status' => 'error',
                    'error' => 'Не удалось поставить задачу в очередь: '.$e->getMessage()]);
            }

            $ids[] = ['id' => $id, 'variant' => ($key === 'a' ? 'A' : 'B'), 'label' => $variant['label']];
        }

        return $this->_respond([
            'ok'     => TRUE,
            'series' => $series,
            'runs'   => $ids,
            'message' => count($ids) > 1
                ? 'Два варианта ушли в очередь: ответы появятся здесь через несколько секунд.'
                : 'Прогон ушёл в очередь: ответ появится здесь через несколько секунд.',
        ]);
    }

    public function action_run_status()
    {
        $series = preg_replace('/[^a-z0-9]/', '', (string) ($this->_body['series'] ?? ''));
        $id     = $this->_int('id');

        if ($series === '' AND $id <= 0)
        {
            return $this->_fail('Укажите series или id');
        }

        $visitor = $this->_visitor();
        $rows    = [];

        if ($id > 0)
        {
            $run = Service_Nj645_Store::run_get($id);

            if ( ! $run OR (string) $run['visitor'] !== $visitor)
            {
                return $this->_fail('Прогон не найден', 404);
            }

            $rows[] = $run;
        }
        else
        {
            $rows = Service_Nj645_Store::db()->query(Database::SELECT,
                'SELECT * FROM nj_645_runs WHERE series = '.Service_Nj645_Store::db()->quote($series).
                ' AND visitor = '.Service_Nj645_Store::db()->quote($visitor).' ORDER BY id',
                FALSE)->as_array();

            if ( ! is_array($rows))
            {
                $rows = [];
            }
        }

        $out = [];

        foreach ($rows as $run)
        {
            $out[] = [
                'id'          => (int) $run['id'],
                'variant'     => (string) $run['variant'],
                'model'       => (string) $run['model'],
                'temperature' => (float) $run['temperature'],
                'status'      => (string) $run['status'],
                'stage'       => (string) $run['stage'],
                'answer'      => (string) $run['answer'],
                'error'       => (string) $run['error'],
                'elapsed_ms'  => (int) $run['elapsed_ms'],
                'usage'       => json_decode((string) $run['usage'], TRUE),
                'finished'    => (string) $run['finished_at'] !== '' ? TRUE : FALSE,
            ];
        }

        $done = TRUE;

        foreach ($out as $run)
        {
            if ( ! in_array($run['status'], ['done', 'error', 'cancelled'], TRUE))
            {
                $done = FALSE;
            }
        }

        return $this->_respond(['ok' => TRUE, 'runs' => $out, 'done' => $done]);
    }

    // -------------------------------------------------------------------- квиз

    public function action_quiz()
    {
        Service_Nj645_Seed::ensure();

        $n     = max(3, min(10, $this->_int('n', (int) Service_Nj645_Store::cfg('limits.quiz_len', 5))));
        $terms = Service_Nj645_Store::quiz_pick($n);
        $out   = [];

        foreach ($terms as $term)
        {
            $options = [[
                'id'    => (int) $term['id'],
                'short' => (string) $term['short'],
            ]];

            foreach (Service_Nj645_Store::quiz_distractors((int) $term['id'], (string) $term['topic'], 3) as $d)
            {
                $options[] = ['id' => (int) $d['id'], 'short' => (string) $d['short']];
            }

            // Перемешиваем: правильный вариант не должен всегда быть первым.
            shuffle($options);

            $out[] = [
                'term_id'  => (int) $term['id'],
                'term'     => (string) $term['term'],
                'topic'    => (string) $term['topic'],
                'difficulty' => (int) $term['difficulty'],
                'options'  => $options,
            ];
        }

        return $this->_respond(['ok' => TRUE, 'questions' => $out]);
    }

    public function action_quiz_answer()
    {
        $term_id   = $this->_int('term_id');
        $chosen_id = $this->_int('chosen_id');

        if ($term_id <= 0 OR $chosen_id <= 0)
        {
            return $this->_fail('Нужны term_id и chosen_id');
        }

        $correct = ($term_id === $chosen_id);

        if ($chosen_id > 0 AND $term_id > 0)
        {
            Service_Nj645_Store::quiz_record($term_id, $chosen_id, $correct);
        }

        $term = Service_Nj645_Store::db()->query(Database::SELECT,
            'SELECT term, short, body FROM nj_645_terms WHERE id = '.(int) $term_id, FALSE)->current();

        $stats = Service_Nj645_Store::stats();

        return $this->_respond([
            'ok'      => TRUE,
            'correct' => $correct,
            'term'    => $term ? (string) $term['term'] : '',
            'short'   => $term ? (string) $term['short'] : '',
            'body'    => $term ? (string) $term['body'] : '',
            'accuracy' => $stats['quiz_accuracy'],
            'asked'    => $stats['quiz_asked'],
        ]);
    }

    // ------------------------------------------------------------- служебное

    public function action_stats()
    {
        return $this->_respond([
            'ok'    => TRUE,
            'stats' => Service_Nj645_Store::stats(),
            'guess' => Service_Nj645_Store::guess_stats(),
            'confusions' => Service_Nj645_Store::quiz_confusions(6),
        ]);
    }

    /** Результат прогона от воркера (очередь nj120, канал nj645.review). */
    public function action_worker_result()
    {
        if ( ! $this->_check_worker())
        {
            return $this->_fail('Неверный токен воркера', 403);
        }

        $id = $this->_int('run_id');

        if ($id <= 0)
        {
            return $this->_fail('Нужен run_id');
        }

        if ( ! Service_Nj645_Store::run_get($id))
        {
            return $this->_fail('Прогон не найден', 404);
        }

        $status = Service_Nj645_Store::run_apply($id, [
            'status'     => (string) ($this->_body['status'] ?? 'done'),
            'answer'     => mb_substr((string) ($this->_body['answer'] ?? ''), 0, 20000),
            'stage'      => (string) ($this->_body['stage'] ?? ''),
            'error'      => mb_substr((string) ($this->_body['error'] ?? ''), 0, 1000),
            'usage'      => (array) ($this->_body['usage'] ?? []),
            'elapsed_ms' => $this->_int('elapsed_ms'),
            'model'      => (string) ($this->_body['model'] ?? ''),
        ]);

        return $this->_respond(['ok' => TRUE, 'status' => $status]);
    }
}
