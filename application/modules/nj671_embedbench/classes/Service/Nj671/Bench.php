<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-671 «Стенд эмбеддингов» — чистая логика: без базы, без сети.
 *
 * ЗАЧЕМ. Метрики считает воркер (`workers/n2j_workers/embbench.py`), потому что
 * только он видит оба сервера. Здесь — ВТОРАЯ половина: привести ответ воркера
 * к виду, который рисует страница, и перевести коды решения в человеческий
 * текст. Никаких своих порогов «кто лучше»: если страница начнёт считать
 * решение сама, она разойдётся с CLI, и владелец получит два разных ответа на
 * один вопрос.
 *
 * ЧТО ЗДЕСЬ ЕСТЬ:
 *   shape()       — ответ воркера → структура для вьюхи (стороны, строки метрик,
 *                   решение, замечания);
 *   metric_rows() — сравнительная таблица «метрика · текстовый · мультимодальный»
 *                   с подсветкой лучшей стороны;
 *   reason()      — код причины из `decide()` → заголовок, пояснение, тон;
 *   pick_text()   — код выбора → «текстовый путь» / «мультимодальный путь»;
 *   grade()       — словесная оценка числа (сильно / средне / слабо).
 *
 * ЧЕГО ЗДЕСЬ НЕТ и быть не должно: расчёта recall/MRR/косинусов. Второй
 * реализации арифметики на PHP хватило бы, чтобы цифры на странице и в CLI
 * разошлись после первой же правки.
 */
class Service_Nj671_Bench
{
    /** Стороны сравнения: ключ воркера → подпись. */
    const SIDES = [
        'text' => [
            'label' => 'Текстовый путь',
            'env'   => 'EMBEDDING_API_URL',
            'note'  => 'OpenAI-совместимый /v1/embeddings: пачка текстов одним запросом, '
                      .'лёгкая модель. Картинок не видит.',
            'icon'  => 'fas fa-font',
            'tone'  => 'sky',
        ],
        'mm'   => [
            'label' => 'Мультимодальный путь',
            'env'   => 'EMBEDDING_MM_API_URL',
            'note'  => 'Свой сервис (OmniEmbed / Qwen3-VL-Embedding): текст и картинка в '
                      .'одном пространстве. Каждый вход — отдельный запрос.',
            'icon'  => 'fas fa-images',
            'tone'  => 'violet',
        ],
    ];

    /** Метрики сравнительной таблицы: путь в структуре стороны → подпись. */
    const METRICS = [
        ['key' => 'recall1', 'name' => 'Recall@1', 'kind' => 'pct', 'best' => 'high',
         'hint' => 'доля запросов, где первым стоит верное описание'],
        ['key' => 'recall3', 'name' => 'Recall@3', 'kind' => 'pct', 'best' => 'high',
         'hint' => 'доля запросов, где верное описание попало в тройку'],
        ['key' => 'mrr', 'name' => 'MRR', 'kind' => 'num', 'best' => 'high',
         'hint' => 'средняя «высота» верного ответа в выдаче: 1 — всегда первый'],
        ['key' => 'margin', 'name' => 'Своё − чужое', 'kind' => 'cos', 'best' => 'high',
         'hint' => 'насколько косинус к своему выше косинуса к чужому'],
        ['key' => 'repeat', 'name' => 'Повторяемость', 'kind' => 'cos', 'best' => 'high',
         'hint' => 'косинус того же текста, посчитанного дважды'],
        ['key' => 'per_item_ms', 'name' => 'Мс на вход', 'kind' => 'ms', 'best' => 'low',
         'hint' => 'время одного входа с учётом способа батчить'],
        ['key' => 'dims', 'name' => 'Размерность', 'kind' => 'int', 'best' => '',
         'hint' => 'ширина вектора: колонка vector(N) задаётся один раз'],
    ];

    /** Задачи, для которых стенд даёт отдельный совет. */
    const TASKS = [
        'text_index'  => ['Текстовый поиск и индексы',
                          'nj115 и всё, где материал — только текст: объём большой, картинок нет'],
        'cross_modal' => ['Поиск по картинкам',
                          'nj653, nj654, nj667: текст и изображения в одной коллекции'],
        'one_space'   => ['Одно пространство на всё',
                          'когда не хочется держать две модели и две размерности'],
        'any'         => ['Любая задача',
                          'пока отвечает только один путь — выбирать не из чего'],
    ];

    // ------------------------------------------------------------ конфигурация

    public static function cfg($key, $default = NULL)
    {
        $value = Kohana::$config->load('nj671_embedbench.'.$key);

        return $value === NULL ? $default : $value;
    }

    /** Наборы материала: ключ → [title, note]. */
    public static function corpora()
    {
        return (array) self::cfg('corpora', []);
    }

    /** Известен ли набор (проверяем ДО очереди, а не после прогона). */
    public static function corpus($key)
    {
        $key     = (string) $key;
        $corpora = self::corpora();

        return isset($corpora[$key]) ? (array) $corpora[$key] : NULL;
    }

    /**
     * Последние прогоны стенда (свежие сверху). Своя таблица — читаем сами.
     *
     * Стенд админский, поэтому история общая: у владельца один вопрос «что
     * показал прошлый прогон», а не «что показали мои прогоны».
     */
    public static function history($limit = 20)
    {
        $limit = max(1, min(100, (int) $limit));

        try
        {
            $rows = Vision::db()
                ->query(Database::SELECT,
                    'SELECT id, status, title, result, error, elapsed_ms, created_at
                       FROM nj_671_runs
                      ORDER BY id DESC LIMIT '.$limit, FALSE)
                ->as_array();
        }
        catch (Throwable $e)
        {
            // Таблицы ещё нет (до миграции) — страница обязана открываться.
            return [];
        }

        foreach ($rows as $i => $row)
        {
            $rows[$i]           = (array) $row;
            $rows[$i]['result'] = Vision::json_decode($row['result'] ?? NULL);
        }

        return $rows;
    }

    // -------------------------------------------------------------- приведение

    /**
     * Ответ воркера → структура для страницы.
     *
     * Терпим к неполному ответу: если сторона не пришла вовсе, она остаётся
     * «не настроена» с причиной, а не исчезает из таблицы. Иначе человек
     * решит, что путь просто не сравнивали.
     *
     * @param  array $raw result из nj_671_runs (jsonb)
     * @return array sides|decision|rows|notes|corpus|elapsed_ms|ok|title
     */
    public static function shape(array $raw)
    {
        $raw    = (array) $raw;
        $sides  = [];

        foreach (array_keys(self::SIDES) as $key)
        {
            $sides[$key] = self::side((array) ($raw['engines'][$key] ?? []), $key);
        }

        $agreement = self::num($raw['agreement'] ?? NULL);
        $decision  = self::decision((array) ($raw['decision'] ?? []), $sides);
        // В полном отчёте corpus — словарь, в короткой сводке из очереди —
        // просто ключ набора. Обе формы приводим к словарю.
        $corpus    = is_array($raw['corpus'] ?? NULL) ? (array) $raw['corpus']
                   : ['key' => (string) ($raw['corpus'] ?? '')];

        // ЧТО ИМЕННО ПРИШЛО. Пустой result и «оба пути ответили ошибкой» —
        // разные вещи, и писать про них одно и то же нельзя. Короткая сводка
        // из очереди (её возвращает обработчик канала) — третья история: работа
        // выполнена, но метрик в ней нет. Владелец 22.10.2026 получил на
        // настроенном стенде «Ни один путь не ответил — причина не записана»
        // именно из-за такой подмены, поэтому состояние отдельным полем.
        $engines = (array) ($raw['engines'] ?? []);

        if ($engines !== [])
        {
            $state = 'full';
        }
        elseif ( ! empty($raw['summary_only'])
            OR array_key_exists('winner', $raw)
            OR array_key_exists('text_ok', $raw)
            OR array_key_exists('mm_ok', $raw))
        {
            $state = 'summary';
        }
        else
        {
            $state = 'empty';
        }

        $out = [
            'ok'          => ($sides['text']['ok'] OR $sides['mm']['ok']),
            'state'       => $state,
            'summary'     => [
                'winner'    => (string) ($raw['winner'] ?? ''),
                'text_ok'   => array_key_exists('text_ok', $raw) ? (bool) $raw['text_ok'] : NULL,
                'mm_ok'     => array_key_exists('mm_ok', $raw) ? (bool) $raw['mm_ok'] : NULL,
                'agreement' => self::num($raw['agreement'] ?? NULL),
            ],
            'title'       => trim((string) ($raw['title'] ?? '')),
            'corpus'      => [
                'key'     => (string) ($corpus['key'] ?? ''),
                // Набор из короткой сводки названия не несёт — берём из
                // конфига, иначе страница покажет пустое место вместо темы.
                'title'   => (string) ($corpus['title'] ?? '') !== ''
                    ? (string) $corpus['title']
                    : (string) (self::corpora()[(string) ($corpus['key'] ?? '')]['title'] ?? ''),
                'docs'    => (int) ($corpus['docs'] ?? 0),
                'queries' => (int) ($corpus['queries'] ?? 0),
                'quick'   => ! empty($corpus['quick']),
            ],
            'sides'       => $sides,
            'raw'         => $raw,
            'decision'    => $decision,
            'agreement'   => $agreement,
            'metrics'     => self::metric_rows($sides),
            'rows'        => self::query_rows($sides),
            'notes'       => self::notes($sides, $agreement),
            'elapsed_ms'  => (int) ($raw['elapsed_ms'] ?? 0),
        ];

        return $out;
    }

    /** Одна сторона: числа, подписи, словесные оценки. */
    public static function side(array $raw, $key)
    {
        $cfg    = self::SIDES[$key];
        $ok     = ! empty($raw['ok']);
        $images = (array) ($raw['images'] ?? []);
        // jsonb может прийти неполным (например, отчёт старой версии воркера):
        // приводим разделы к массивам один раз, а не ловим warning на каждом поле.
        $quality = (array) ($raw['quality'] ?? []);
        $latency = (array) ($raw['latency'] ?? []);
        $side   = [
            'key'         => $key,
            'label'       => $cfg['label'],
            'env'         => $cfg['env'],
            'note'        => $cfg['note'],
            'icon'        => $cfg['icon'],
            'tone'        => $cfg['tone'],
            'ok'          => $ok,
            'error'       => trim((string) ($raw['error'] ?? '')),
            'model'       => trim((string) ($raw['model'] ?? '')),
            'base_url'    => trim((string) ($raw['base_url'] ?? '')),
            'dims'        => (int) ($raw['dims'] ?? 0),
            'notes'       => self::strings($raw['notes'] ?? [], 6, 400),
            'recall1'     => self::num($quality['recall1'] ?? NULL),
            'recall3'     => self::num($quality['recall3'] ?? NULL),
            'mrr'         => self::num($quality['mrr'] ?? NULL),
            'related'     => self::num($quality['related'] ?? NULL),
            'unrelated'   => self::num($quality['unrelated'] ?? NULL),
            'margin'      => self::num($quality['margin'] ?? NULL),
            // 4 знака: порог «вектор дрожит» — 0.999, при округлении до двух
            // 0.997 превратилось бы в 1.0 и предупреждение исчезло бы.
            'repeat'      => self::num($raw['repeat'] ?? NULL, 4),
            'per_item_ms' => self::num($latency['per_item_ms'] ?? NULL),
            'total_ms'    => (int) ($latency['total_ms'] ?? 0),
            'images'      => [
                'supported' => ! empty($images['supported']),
                'checked'   => ! empty($images['checked']),
                'ok'        => isset($images['ok']) ? (bool) $images['ok'] : NULL,
                'margin'    => self::num($images['margin'] ?? NULL),
                'note'      => trim((string) ($images['note'] ?? '')),
            ],
            'rows'        => (array) ($raw['rows'] ?? []),
        ];

        if ( ! $ok AND $side['error'] === '')
        {
            $side['error'] = 'путь не ответил — причина не записана';
        }

        $side['grades'] = [
            'recall1' => self::grade('recall1', $side['recall1']),
            'repeat'  => self::grade('repeat', $side['repeat']),
            'images'  => self::grade('images',
                $side['images']['checked'] ? (int) $side['images']['ok'] : NULL,
                $side['images']),
        ];

        return $side;
    }

    /**
     * Сравнительные строки. Лучшая сторона подсвечивается только когда обе
     * работают: «лучше» среди одной работающей — не сравнение.
     */
    public static function metric_rows(array $sides)
    {
        $rows  = [];
        $comparable = ($sides['text']['ok'] AND $sides['mm']['ok']);

        foreach (self::METRICS as $metric)
        {
            $a = $sides['text'][$metric['key']] ?? NULL;
            $b = $sides['mm'][$metric['key']] ?? NULL;
            $best = '';

            if ($comparable AND $a !== NULL AND $b !== NULL AND $metric['best'] !== '')
            {
                if ($metric['best'] === 'high' AND $a != $b)
                {
                    $best = ($a > $b) ? 'text' : 'mm';
                }
                elseif ($metric['best'] === 'low' AND $a != $b)
                {
                    $best = ($a < $b) ? 'text' : 'mm';
                }
            }

            $rows[] = [
                'key'  => $metric['key'],
                'name' => $metric['name'],
                'hint' => $metric['hint'],
                'best' => $best,
                'text' => self::display($metric['kind'], $a, $sides['text']['ok']),
                'mm'   => self::display($metric['kind'], $b, $sides['mm']['ok']),
            ];
        }

        return $rows;
    }

    /**
     * Одна строка на запрос: где каждый путь ошибся.
     *
     * Итоговой цифры человеку мало: «80%» не отвечает на вопрос «а что именно
     * не нашлось». Показываем первые пять запросов, где пути разошлись.
     */
    public static function query_rows(array $sides)
    {
        $text = [];

        foreach ($sides['text']['rows'] as $row)
        {
            $text[(string) ($row['query'] ?? '')] = $row;
        }

        $rows = [];

        foreach ($sides['mm']['rows'] as $row)
        {
            $query = (string) ($row['query'] ?? '');
            $a     = (array) ($text[$query] ?? []);

            if ( ! $query)
            {
                continue;
            }

            $rows[] = [
                'query'     => $query,
                'text_ok'   => ! empty($a['ok']),
                'mm_ok'     => ! empty($row['ok']),
                'text_rank' => (int) ($a['rank'] ?? 0),
                'mm_rank'   => (int) ($row['rank'] ?? 0),
                'text_cos'  => self::num($a['top_cos'] ?? NULL),
                'mm_cos'    => self::num($row['top_cos'] ?? NULL),
            ];
        }

        if ( ! $rows)
        {
            foreach ($text as $query => $row)
            {
                $rows[] = [
                    'query'     => (string) $query,
                    'text_ok'   => ! empty($row['ok']),
                    'mm_ok'     => FALSE,
                    'text_rank' => (int) ($row['rank'] ?? 0),
                    'mm_rank'   => 0,
                    'text_cos'  => self::num($row['top_cos'] ?? NULL),
                    'mm_cos'    => NULL,
                ];
            }
        }

        return array_slice($rows, 0, 6);
    }

    /** Замечания о самом сравнении — то, из-за чего вывод может быть неверным. */
    public static function notes(array $sides, $agreement)
    {
        $notes = [];
        $text  = $sides['text'];
        $mm    = $sides['mm'];

        if ($text['ok'] AND $mm['ok'] AND $text['dims'] AND $mm['dims'])
        {
            if ($text['dims'] === $mm['dims'])
            {
                $notes[] = [
                    'tone'  => 'emerald',
                    'title' => 'Размерности совпали: '.$text['dims'],
                    'text'  => 'Векторы обоих путей можно положить в одну колонку vector('.$text['dims'].'). '
                              .'Но это НЕ значит, что их можно сравнивать между собой: пространства у моделей разные.',
                ];
            }
            else
            {
                $notes[] = [
                    'tone'  => 'rose',
                    'title' => 'Размерности разные: '.$text['dims'].' и '.$mm['dims'],
                    'text'  => 'Это два разных пространства. Смешать векторы в одной таблице нельзя, '
                              .'а косинус между путями не считается. Ширина колонки задаётся один раз при создании таблицы.',
                ];
            }
        }

        if ($agreement !== NULL)
        {
            $notes[] = [
                'tone'  => 'gray',
                'title' => 'Согласие структуры: r = '.self::num($agreement, 3),
                'text'  => 'Корреляция попарных косинусов на одних описаниях. Это не качество: '
                          .'разные модели живут в разных пространствах. Высокая корреляция значит, '
                          .'что модели одинаково понимают «что с чем рядом».',
            ];
        }

        if ($mm['ok'] AND $mm['images']['supported'] AND ! $mm['images']['checked'])
        {
            $notes[] = [
                'tone'  => 'amber',
                'title' => 'Картинки не проверены',
                'text'  => $mm['images']['note'] !== ''
                    ? $mm['images']['note']
                    : 'Проба «картинка ↔ слова» не выполнилась: на воркере нет Pillow '
                      .'или сервер не принял картинку.',
            ];
        }

        foreach (['text' => $text, 'mm' => $mm] as $key => $side)
        {
            if ($side['ok'] AND $side['repeat'] !== NULL AND $side['repeat'] < 0.999)
            {
                $notes[] = [
                    'tone'  => 'amber',
                    'title' => self::SIDES[$key]['label'].': вектор «дрожит»',
                    'text'  => 'Один и тот же текст дважды дал косинус '.self::num($side['repeat'], 4)
                              .'. Для поиска это терпимо, для дедупликации — нет: '
                              .'порог «одно и то же» придётся поднимать.',
                ];
            }
        }

        return $notes;
    }

    // ---------------------------------------------------------------- решение

    /** Коды воркера → то, что читает человек. Логики здесь нет, только слова. */
    public static function decision(array $raw, array $sides)
    {
        $winner = (string) ($raw['winner'] ?? '');

        if ($winner === '')
        {
            $winner = 'none';
        }

        $reasons = [];
        $tasks   = [];

        foreach ((array) ($raw['reasons'] ?? []) as $reason)
        {
            if (is_array($reason))
            {
                $reasons[] = self::reason((string) ($reason['code'] ?? ''), $reason);
            }
        }

        foreach ((array) ($raw['tasks'] ?? []) as $task)
        {
            if ( ! is_array($task))
            {
                continue;
            }

            $key  = (string) ($task['task'] ?? 'any');
            $pick = (string) ($task['pick'] ?? 'none');
            $meta = self::TASKS[$key] ?? self::TASKS['any'];

            $tasks[] = [
                'task'  => $key,
                'title' => $meta[0],
                'note'  => $meta[1],
                'pick'  => $pick,
                'label' => self::pick_text($pick),
                'tone'  => self::pick_tone($pick),
            ];
        }

        return [
            'winner'  => $winner,
            'label'   => self::winner_text($winner),
            'tone'    => self::pick_tone($winner === 'both' ? 'both' : $winner),
            'summary' => trim((string) ($raw['summary'] ?? '')),
            'reasons' => $reasons,
            'tasks'   => $tasks,
        ];
    }

    /** Одна причина: заголовок, пояснение, тон. */
    public static function reason($code, array $r)
    {
        $value = self::num($r['value'] ?? NULL);
        $side  = (string) ($r['side'] ?? '');

        switch ($code)
        {
            case 'only_one':
                return [
                    'code'  => $code,
                    'tone'  => 'amber',
                    'title' => 'Отвечает только '.self::pick_text($side),
                    'note'  => trim((string) ($r['error'] ?? '')) !== ''
                        ? 'Второй путь не ответил: '.trim((string) $r['error'])
                        : 'Второй путь не ответил — сравнивать не с чем.',
                ];

            case 'both_off':
                return [
                    'code'  => $code,
                    'tone'  => 'rose',
                    'title' => 'Ни один путь не ответил',
                    'note'  => 'Проверьте адреса, токены и имена моделей в workers/.env: '
                              .'без ответа обоих стенд ничего не скажет.',
                ];

            case 'mm_better':
                return [
                    'code'  => $code,
                    'tone'  => 'violet',
                    'title' => 'Мультимодальный путь точнее на '.self::pct($value),
                    'note'  => 'На этом материале его recall@1 выше. Прежде чем переводить текстовые '
                              .'индексы — проверьте цену входа: строка «Мс на вход».',
                ];

            case 'text_better':
                return [
                    'code'  => $code,
                    'tone'  => 'sky',
                    'title' => 'Текстовый путь точнее на '.self::pct($value),
                    'note'  => 'Мультимодальная модель проиграла там, где картинок нет вовсе. '
                              .'Менять рабочий текстовый поиск на неё нет причины.',
                ];

            case 'same_quality':
                return [
                    'code'  => $code,
                    'tone'  => 'gray',
                    'title' => 'Качество поиска практически одинаковое',
                    'note'  => 'Разница в пределах шума ('.self::pct($value).'). Значит, решают '
                              .'другие свойства: скорость, картинки, число движущихся частей.',
                ];

            case 'text_faster':
                return [
                    'code'  => $code,
                    'tone'  => 'sky',
                    'title' => 'Текстовый путь быстрее в '.self::num($value, 1).'×',
                    'note'  => 'Время одного входа с учётом пачки. При равном качестве это '
                              .'прямая экономия на объёмных индексах.',
                ];

            case 'images_ok':
                return [
                    'code'  => $code,
                    'tone'  => 'violet',
                    'title' => 'Картинка и слова сходятся',
                    'note'  => 'Своя подпись ближе к картинке, чем чужая (отрыв '
                              .self::num($value, 2).'). Только этот путь умеет искать картинкой.',
                ];

            case 'images_weak':
                return [
                    'code'  => $code,
                    'tone'  => 'amber',
                    'title' => 'Картинка и слова почти не связаны',
                    'note'  => 'Отрыв своей подписи от чужой '.self::num($value, 2).' — это мало. '
                              .'Проверьте, что сервер считает вектор по картинке, а не только по подписи.',
                ];

            case 'text_unstable':
            case 'mm_unstable':
                $label = ($code === 'text_unstable') ? 'Текстовый путь' : 'Мультимодальный путь';

                return [
                    'code'  => $code,
                    'tone'  => 'amber',
                    'title' => $label.': нестабильный вектор',
                    'note'  => 'Тот же текст дважды даёт косинус '.self::num($value, 4).'. '
                              .'Для поиска сойдёт, для «одно и то же или нет» — нет.',
                ];

            case 'dims_differ':
                return [
                    'code'  => $code,
                    'tone'  => 'rose',
                    'title' => 'Разные размерности: '
                              .(int) ($r['text'] ?? 0).' и '.(int) ($r['mm'] ?? 0),
                    'note'  => 'Два пространства нельзя смешивать в одной таблице и нельзя '
                              .'сравнивать косинусом. Выбор пути — это выбор колонки vector(N).',
                ];

            default:
                return [
                    'code'  => $code,
                    'tone'  => 'gray',
                    'title' => $code !== '' ? $code : 'без пояснения',
                    'note'  => '',
                ];
        }
    }

    // --------------------------------------------------------------- подписи

    public static function pick_text($pick)
    {
        switch ((string) $pick)
        {
            case 'text': return 'текстовый путь';
            case 'mm':   return 'мультимодальный путь';
            case 'both': return 'оба пути';
            default:     return 'ни один';
        }
    }

    public static function pick_tone($pick)
    {
        switch ((string) $pick)
        {
            case 'text': return 'sky';
            case 'mm':   return 'violet';
            case 'both': return 'emerald';
            default:     return 'rose';
        }
    }

    public static function winner_text($winner)
    {
        switch ((string) $winner)
        {
            case 'text': return 'Оставить текстовый путь';
            case 'mm':   return 'Переходить на мультимодальный';
            case 'both': return 'Держать оба пути';
            default:     return 'Сравнение не удалось';
        }
    }

    /**
     * Словесная оценка числа.
     *
     * Для картинок своя шкала: `$extra` — блок images, где важнее флаг, чем
     * косинус.
     */
    public static function grade($metric, $value, array $extra = [])
    {
        if ($value === NULL)
        {
            return ['text' => 'нет данных', 'tone' => 'gray'];
        }

        if ($metric === 'images')
        {
            $ok = (bool) ($extra['ok'] ?? FALSE);

            return $ok
                ? ['text' => 'связывает', 'tone' => 'emerald']
                : ['text' => 'не видит', 'tone' => 'amber'];
        }

        if ($metric === 'repeat')
        {
            if ($value >= 0.9995) return ['text' => 'стабильно', 'tone' => 'emerald'];
            if ($value >= 0.99)   return ['text' => 'норма', 'tone' => 'amber'];

            return ['text' => 'дрожит', 'tone' => 'rose'];
        }

        if ($value >= 0.8) return ['text' => 'сильно', 'tone' => 'emerald'];
        if ($value >= 0.5) return ['text' => 'средне', 'tone' => 'amber'];

        return ['text' => 'слабо', 'tone' => 'rose'];
    }

    /** Отображение значения метрики. */
    public static function display($kind, $value, $side_ok = TRUE)
    {
        if ( ! $side_ok)
        {
            return '—';
        }

        if ($value === NULL)
        {
            return '—';
        }

        switch ($kind)
        {
            case 'pct': return self::pct($value);
            case 'ms':  return self::show($value, 1);
            case 'int': return (string) (int) $value;
            case 'cos': return self::show($value, 3);

            default:    return self::num($value, 2);
        }
    }

    public static function pct($value)
    {
        return $value === NULL ? '—' : round($value * 100).'%';
    }

    public static function num($value, $digits = 2)
    {
        if ($value === NULL OR $value === '' OR ! is_numeric($value))
        {
            return NULL;
        }

        return round((float) $value, (int) $digits);
    }

    /** Число для печати (NULL → «—»). */
    public static function show($value, $digits = 2)
    {
        $number = self::num($value, $digits);

        return $number === NULL ? '—' : (string) $number;
    }

    /** Список строк из jsonb: чистим, режем, выбрасываем пустое. */
    public static function strings($value, $limit = 6, $max_len = 400)
    {
        $out = [];

        foreach ((array) $value as $item)
        {
            if (is_array($item))
            {
                continue;
            }

            $text = trim((string) $item);

            if ($text === '')
            {
                continue;
            }

            $out[] = mb_substr($text, 0, (int) $max_len);

            if (count($out) >= (int) $limit)
            {
                break;
            }
        }

        return $out;
    }

    /**
     * Короткая строка для истории прогонов: «80% · 90% → оба пути».
     * Пустой прогон (ещё не считался) даёт пустую строку, а не «0%».
     */
    public static function brief(array $run)
    {
        $result = (array) ($run['result'] ?? []);
        $sides  = (array) ($result['engines'] ?? []);

        if ( ! $sides)
        {
            return '';
        }

        $text = self::num($sides['text']['quality']['recall1'] ?? NULL);
        $mm   = self::num($sides['mm']['quality']['recall1'] ?? NULL);
        $win  = (string) ($result['decision']['winner'] ?? '');

        $parts = [];

        if ($text !== NULL) $parts[] = 'текст '.self::pct($text);
        if ($mm !== NULL)   $parts[] = 'мультимодальный '.self::pct($mm);
        if ($win !== '')    $parts[] = self::pick_text($win);

        return implode(' · ', $parts);
    }
}
