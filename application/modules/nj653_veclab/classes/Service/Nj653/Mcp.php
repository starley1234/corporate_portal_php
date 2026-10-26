<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-653 для агентов: обработчики MCP-инструментов «Лаборатории векторов».
 *
 * Зачем это модели. У языковой модели нет своей памяти смысла: она не умеет
 * сказать «вот эти две фразы про одно и то же» иначе как на глаз. Здесь у неё
 * появляется настоящее векторное пространство — общее с картинками: можно
 * положить тексты в коллекцию и искать по ней словами, можно измерить
 * близость двух формулировок числом.
 *
 * Асинхронность честная. Вектор считает воркер (канал `nj653.embed`), а не
 * HTTP-запрос, поэтому инструменты «положить», «поискать» и «сравнить»
 * возвращают `run_id` и просят через несколько секунд позвать
 * `nj653_result`. Так же устроен nj550_jobs — агенты этот рисунок уже знают.
 *
 * Коллекция у агентов общая и отдельная от людей: владелец строк —
 * `visitor = 'mcp'`. Чужие (посетительские) коллекции через MCP не видны:
 * там лежат картинки живых людей.
 */
class Service_Nj653_Mcp
{
    /** Владелец строк коллекции, созданных агентами. */
    const VISITOR = 'mcp';

    // ------------------------------------------------------------- чтение

    /**
     * Паспорт сервиса: жив ли, какая модель, какая размерность, что умеет.
     */
    public static function status(array $args)
    {
        $stats = Service_Nj653_Store::stats();
        $probe = Service_Nj653_Store::probe_last();
        $shape = $probe ? Service_Nj653_Veclab::shape($probe) : [];

        $out = [
            'service'     => 'Лаборатория векторов N2J.ru (мультимодальные эмбеддинги)',
            'page'        => '/veclab',
            'ready'       => (bool) $stats['ready'],
            'pgvector'    => (bool) $stats['pgvector'],
            'dims'        => (int) $stats['dims'],
            'model'       => (string) $stats['model'],
            'items_total' => (int) $stats['total'],
            'items_mcp'   => Service_Nj653_Store::items_count(self::VISITOR),
            'items_max'   => (int) self::limit('items_max', 60),
        ];

        if ( ! $out['ready'])
        {
            $out['note'] = 'Хранилище векторов ещё не создано — инструменты записи и поиска ответят ошибкой.';

            return $out;
        }

        if ($shape)
        {
            $out['last_probe'] = [
                'at'       => (string) ($probe['finished_at'] ?? ''),
                'dialect'  => (string) ($shape['dialect_title'] ?? ''),
                'elapsed_ms' => (int) ($shape['elapsed_ms'] ?? 0),
                'verdict'  => (string) ($shape['verdict']['title'] ?? ''),
                'cross_modal' => (bool) ($shape['verdict']['cross'] ?? FALSE),
            ];
        }
        else
        {
            $out['last_probe'] = NULL;
            $out['note'] = 'Модель ещё ни разу не проверялась: позови nj653_probe, если нужен её паспорт.';
        }

        return $out;
    }

    /** Что лежит в коллекции агентов. */
    public static function collection(array $args)
    {
        $limit = max(1, min(60, (int) Arr::get($args, 'limit', 20)));
        $out   = [];

        foreach (Service_Nj653_Store::items_mine(self::VISITOR, $limit) as $item)
        {
            $out[] = [
                'id'      => (int) $item['id'],
                'label'   => (string) $item['label'],
                'kind'    => (string) $item['kind'],
                'content' => mb_substr((string) ($item['content'] ?? ''), 0, 300),
                'about'   => Service_Nj653_Veclab::about($item['meta'] ?? NULL),
                'added'   => (string) ($item['created_at'] ?? ''),
            ];
        }

        return [
            'count' => count($out),
            'total' => Service_Nj653_Store::items_count(self::VISITOR),
            'items' => $out,
            'hint'  => $out ? 'Искать по ним: nj653_search.'
                : 'Коллекция агентов пуста — положи в неё тексты инструментом nj653_remember.',
        ];
    }

    /** Результат любого прогона: поиска, сравнения, добавления, проверки. */
    public static function result(array $args)
    {
        $id  = (int) Arr::get($args, 'run_id', 0);
        $run = $id > 0 ? Vision::run_get(Service_Nj653_Store::RUNS, $id) : NULL;

        if ( ! $run OR (string) $run['visitor'] !== self::VISITOR)
        {
            return ['error' => 'Прогон не найден. Через MCP видны только прогоны, запущенные инструментами nj653_*.'];
        }

        $status = (string) $run['status'];

        if ( ! in_array($status, ['done', 'error', 'cancelled'], TRUE))
        {
            // Дожим общим механизмом: если воркер уже закрыл задачу, прогон
            // подтянется прямо сейчас, и агенту не придётся ждать лишний круг.
            $watch  = Vision::run_watch(Service_Nj653_Store::RUNS, $id, 'nj653.embed');
            $run    = $watch['run'];
            $status = (string) $run['status'];
        }

        if ($status === 'error' OR $status === 'cancelled')
        {
            return ['run_id' => $id, 'status' => $status,
                'error' => (string) $run['error'] ?: 'Прогон не удался.'];
        }

        if ($status !== 'done')
        {
            return ['run_id' => $id, 'status' => $status,
                'hint' => 'Ещё считается. Подожди 3–5 секунд и позови nj653_result снова.'];
        }

        $shape = Service_Nj653_Veclab::shape((array) $run['result']);
        $out   = [
            'run_id'     => $id,
            'status'     => 'done',
            'mode'       => (string) $shape['mode'],
            'model'      => (string) ($shape['model'] ?? ''),
            'dims'       => (int) ($shape['dims'] ?? 0),
            'elapsed_ms' => (int) ($shape['elapsed_ms'] ?? 0),
        ];

        if ($shape['mode'] === 'search')
        {
            $out['hits'] = [];

            foreach ((array) ($shape['hits'] ?? []) as $hit)
            {
                $out['hits'][] = [
                    'id'         => (int) $hit['id'],
                    'label'      => (string) $hit['label'],
                    'kind'       => (string) $hit['kind'],
                    'cosine'     => (float) $hit['score']['value'],
                    'similarity' => (string) $hit['score']['title'],
                ];
            }

            $out['hint'] = $out['hits']
                ? 'Числа — косинусная близость: 1,0 это одно и то же, около нуля — ничего общего.'
                : 'Ничего не нашлось: в коллекции агентов пока нет подходящих элементов.';
        }
        elseif ($shape['mode'] === 'compare')
        {
            $out['cosine']     = (float) ($shape['pair']['value'] ?? 0);
            $out['similarity'] = (string) ($shape['pair']['title'] ?? '');
        }
        elseif ($shape['mode'] === 'add')
        {
            $out['added']      = (int) ($shape['added'] ?? 0);
            $out['duplicates'] = (int) ($shape['duplicates'] ?? 0);
        }
        else
        {
            $out['verdict'] = (string) ($shape['verdict']['title'] ?? '');
            $out['dialect'] = (string) ($shape['dialect_title'] ?? '');
            $out['checks']  = (array) ($shape['checks'] ?? []);
        }

        return $out;
    }

    // -------------------------------------------------------------- запуск

    /** Положить текст в коллекцию агентов. */
    public static function remember(array $args)
    {
        $text = trim((string) Arr::get($args, 'text', ''));
        $text = mb_substr($text, 0, (int) self::limit('text_max', 2000));

        if ($text === '')
        {
            return ['error' => 'Нужен текст: что именно запомнить.'];
        }

        $max = (int) self::limit('items_max', 60);

        if (Service_Nj653_Store::items_count(self::VISITOR) >= $max)
        {
            return ['error' => 'В коллекции агентов уже '.$max.' элементов — это предел демонстрации.'];
        }

        $print = Service_Nj653_Store::fingerprint($text);

        if (Service_Nj653_Store::fingerprint_exists(self::VISITOR, $print))
        {
            return ['error' => 'Такой текст уже лежит в коллекции — второй раз считать его вектор незачем.'];
        }

        $label = trim((string) Arr::get($args, 'label', '')) ?: $text;

        $pending = [
            'slot'        => 1,
            'kind'        => 'text',
            'content'     => $text,
            'preview'     => '',
            'label'       => mb_substr($label, 0, 200),
            'fingerprint' => $print,
            'meta'        => [],
        ];

        return self::start('add', [$pending], [
            ['slot' => 1, 'text' => $text, 'has_image' => FALSE],
        ], 'Текст ушёл к модели. Через 3–5 секунд позови nj653_result с этим run_id.');
    }

    /** Поиск по коллекции агентов обычными словами. */
    public static function search(array $args)
    {
        $query = trim((string) Arr::get($args, 'query', ''));
        $query = mb_substr($query, 0, (int) self::limit('text_max', 2000));

        if ($query === '')
        {
            return ['error' => 'Нужен запрос: что искать.'];
        }

        if (Service_Nj653_Store::items_count(self::VISITOR) < 1)
        {
            return ['error' => 'Коллекция агентов пуста: сначала положи в неё тексты инструментом nj653_remember.'];
        }

        $top = max(1, min(20, (int) Arr::get($args, 'top', (int) self::limit('top', 6))));

        return self::start('search', [[
            'slot'    => 1,
            'kind'    => 'text',
            'content' => $query,
            'preview' => '',
            'label'   => mb_substr($query, 0, 200),
            'top'     => $top,
        ]], [[
            'slot'        => 1,
            'text'        => $query,
            'has_image'   => FALSE,
            'instruction' => (string) Kohana::$config->load('nj653_veclab.instructions.search'),
        ]], 'Запрос ушёл к модели. Через 3–5 секунд позови nj653_result с этим run_id.');
    }

    /** Косинусная близость двух текстов — без записи в коллекцию. */
    public static function compare(array $args)
    {
        $max   = (int) self::limit('text_max', 2000);
        $first = mb_substr(trim((string) Arr::get($args, 'first', '')), 0, $max);
        $other = mb_substr(trim((string) Arr::get($args, 'second', '')), 0, $max);

        if ($first === '' OR $other === '')
        {
            return ['error' => 'Нужны два текста: first и second.'];
        }

        $pending = [];
        $inputs  = [];

        foreach ([1 => $first, 2 => $other] as $slot => $text)
        {
            $pending[] = [
                'slot'    => $slot,
                'kind'    => 'text',
                'content' => $text,
                'preview' => '',
                'label'   => mb_substr($text, 0, 200),
            ];
            $inputs[] = ['slot' => $slot, 'text' => $text, 'has_image' => FALSE];
        }

        return self::start('compare', $pending, $inputs,
            'Пара ушла к модели. Через 3–5 секунд позови nj653_result с этим run_id.');
    }

    /** Проверка модели: формат запроса, размерность, понимает ли смысл. */
    public static function probe(array $args)
    {
        return self::start('probe', [], [],
            'Проверка запущена. Она дольше обычного (несколько проб), подожди 10–20 секунд и позови nj653_result.');
    }

    // ---------------------------------------------------------- помощники

    /**
     * Общий запуск прогона: строка в `nj_653_runs` + задача в очередь.
     *
     * Кадров здесь нет намеренно: через MCP приходит только текст, картинку
     * агенту прикладывать нечем, а base64 в payload очереди запрещён.
     */
    protected static function start($mode, array $pending, array $inputs, $hint = '')
    {
        if ( ! Service_Nj653_Store::table_ready(Service_Nj653_Store::RUNS))
        {
            return ['error' => 'Хранилище лаборатории не создано: нужны миграции модуля nj653_veclab.'];
        }

        $run_id = Service_Nj653_Store::run_create($mode, $pending, [
            'visitor' => self::VISITOR,
            'title'   => Service_Nj653_Veclab::mode_label($mode).' (MCP)',
        ]);

        if ( ! $run_id)
        {
            return ['error' => 'Не удалось создать прогон.'];
        }

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj653.embed', [
                'run_id' => $run_id,
                'mode'   => $mode,
                'inputs' => array_values($inputs),
            ], [
                'module'       => Service_Nj653_Store::MODULE,
                'timeout_sec'  => (int) self::limit('timeout_sec', 240),
                'max_attempts' => 2,
            ]);

            Vision::run_set_job(Service_Nj653_Store::RUNS, $run_id, $job_id);
        }
        catch (Throwable $e)
        {
            Vision::run_apply(Service_Nj653_Store::RUNS, $run_id, [
                'status' => 'error',
                'mode'   => $mode,
                'error'  => 'Очередь недоступна: '.$e->getMessage(),
            ]);

            return ['error' => 'Очередь задач недоступна: '.$e->getMessage()];
        }

        return [
            'run_id' => $run_id,
            'mode'   => $mode,
            'status' => 'queued',
            'hint'   => $hint !== '' ? $hint : 'Результат — через nj653_result с этим run_id.',
        ];
    }

    protected static function limit($key, $default)
    {
        $value = Kohana::$config->load('nj653_veclab.limits.'.$key);

        return ($value === NULL OR $value === '') ? $default : $value;
    }
}
