<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Лаборатории векторов» (NJ-653).
 *
 * Для человека:
 *   POST /veclab/api/probe      проверить модель (формат, размерность, смысл)
 *   POST /veclab/api/add        текст и/или картинка → элемент коллекции
 *   POST /veclab/api/search     запрос → ближайшие элементы коллекции
 *   POST /veclab/api/compare    два входа → косинусная близость
 *   GET  /veclab/api/status?id= состояние прогона (контракт статус-ряда)
 *   GET  /veclab/api/items      своя коллекция
 *   GET  /veclab/api/about      паспорт API: модель, размерность, пределы, методы
 *   POST /veclab/api/forget     убрать элемент ({id}) или всю коллекцию ({all:1})
 *
 * Для воркера (только по X-Worker-Token):
 *   POST /veclab/api/photo_file     кадр слота прогона
 *   POST /veclab/api/worker_result  приёмка векторов
 *
 * Векторы считает воркер (канал `nj653.embed`), сайт их только хранит и ищет
 * по ним: долгая работа в HTTP-запросе запрещена (AGENTS §2.6).
 */
class Controller_Nj653_Api extends Controller
{
    public $auto_render = FALSE;

    /** @var array тело запроса (JSON или форма) */
    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw = $this->request->body();

        if ($raw !== '' AND $raw !== NULL)
        {
            $decoded     = json_decode((string) $raw, TRUE);
            $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
        }
        else
        {
            $this->_body = (array) $this->request->post();
        }

        if ( ! $this->_body AND $this->request->query())
        {
            $this->_body = (array) $this->request->query();
        }

        // Замок: лаборатория работает только по бесплатному аккаунту
        // (владелец, 08.10.2026). Воркерские точки (кадр прогона и приёмка
        // векторов) ходят по X-Worker-Token и проверяются сами — их пропускаем.
        if ( ! Gate::logged_in() AND ! Gate::worker_action($this->request->action()))
        {
            throw HTTP_Exception::factory(401, 'Лаборатория векторов работает только по бесплатному аккаунту');
        }
    }

    // --------------------------------------------------------- справка API

    /**
     * GET /veclab/api/about — что это за API и что он умеет.
     *
     * Самоописание нужно не для красоты: внешнему клиенту (и агенту через
     * MCP) иначе неоткуда узнать размерность вектора, пределы и то, что
     * ответ асинхронный. Никаких чужих данных здесь нет — только паспорт.
     */
    public function action_about()
    {
        $stats = Service_Nj653_Store::stats();
        $probe = Service_Nj653_Store::probe_last();

        return $this->_respond([
            'ok'      => TRUE,
            'service' => 'Лаборатория векторов N2J.ru: мультимодальные эмбеддинги',
            'page'    => '/veclab',
            'async'   => 'Любой прогон возвращает run_id; результат — GET /veclab/api/status?id=run_id.',
            'model'   => [
                'name'    => (string) $stats['model'],
                'dims'    => (int) $stats['dims'],
                'dialect' => (string) ($probe['dialect'] ?? ($probe['passport']['dialect'] ?? '')),
                'checked_at' => (string) ($probe['finished_at'] ?? ''),
            ],
            'storage' => [
                'ready'    => (bool) $stats['ready'],
                'pgvector' => (bool) $stats['pgvector'],
                'total'    => (int) $stats['total'],
            ],
            'limits'   => (array) Kohana::$config->load('nj653_veclab.limits'),
            'methods'  => [
                'POST /veclab/api/probe'   => 'проверить модель: формат запроса, размерность, понимание смысла',
                'POST /veclab/api/add'     => 'положить элемент: {text, image (data:URL), label, meta} либо {items: […]}',
                'POST /veclab/api/search'  => 'поиск по своей коллекции: {text|image, top}',
                'POST /veclab/api/compare' => 'близость пары: {text1|image1, text2|image2}',
                'GET /veclab/api/status'   => 'состояние прогона: {id}',
                'GET /veclab/api/items'    => 'своя коллекция',
                'POST /veclab/api/forget'  => 'убрать элемент {id} или всю свою коллекцию {all:1}',
                'GET /veclab/api/about'    => 'этот справочник',
            ],
            'mcp'     => [
                'endpoint' => '/mcp/api/rpc',
                'tools'    => ['nj653_status', 'nj653_collection', 'nj653_remember',
                               'nj653_search', 'nj653_compare', 'nj653_result'],
            ],
            'note'    => 'Коллекция личная: элементы привязаны к браузеру (cookie посетителя), '
                .'а через MCP — к общей коллекции агентов.',
        ]);
    }

    // ------------------------------------------------------------ прогоны

    /** Самопроверка модели: ничего прикладывать не надо. */
    public function action_probe()
    {
        return $this->_start('probe', [], []);
    }

    /** Текст и/или картинка → элемент коллекции. */
    public function action_add()
    {
        $visitor = $this->_visitor();
        $max     = (int) $this->_limit('items_max', 60);

        if (Service_Nj653_Store::items_count($visitor) >= $max)
        {
            return $this->_fail('В коллекции уже '.$max.' элементов — это предел демонстрации. '
                .'Уберите лишнее и добавьте заново.', 429);
        }

        $room = $max - Service_Nj653_Store::items_count($visitor);
        $bulk = $this->_bulk();

        if ($bulk !== NULL)
        {
            // Пачка картинок одним прогоном: выбрать десяток файлов и ждать
            // десять раз — не демонстрация, а наказание.
            if ( ! $bulk)
            {
                return $this->_fail('Ни одной картинки не пришло — выберите файлы заново.');
            }

            if (count($bulk) > $room)
            {
                return $this->_fail('В коллекции осталось место на '.$room.' элементов, '
                    .'а выбрано '.count($bulk).'. Уберите лишние или очистите коллекцию.', 429);
            }

            $inputs  = [];
            $pending = [];
            $seen    = [];
            $dupes   = 0;

            foreach (array_values($bulk) as $n => $item)
            {
                list($input, $meta, $error) = $this->_slot(count($inputs) + 1, $item);

                if ($error !== '')
                {
                    return $this->_fail('Картинка '.($n + 1).': '.$error);
                }

                $print = (string) ($meta['fingerprint'] ?? '');

                // Дубли отсеиваем ДО модели: считать вектор того, что уже
                // лежит в коллекции, — потраченная минута и лишняя точка,
                // из-за которой поиск начинает возвращать одно и то же.
                if ($print !== ''
                    AND (isset($seen[$print]) OR Service_Nj653_Store::fingerprint_exists($visitor, $print)))
                {
                    $dupes++;
                    continue;
                }

                $seen[$print]  = TRUE;
                $meta['label'] = $this->_label($item['label'] ?? '', $meta);
                $inputs[]      = $input;
                $pending[]     = $meta;
            }

            if ( ! $inputs)
            {
                return $this->_fail($dupes === 1
                    ? 'Эта картинка уже есть в коллекции — добавлять второй раз нечего.'
                    : 'Все '.$dupes.' картинок уже есть в коллекции — добавлять нечего.', 409);
            }

            return $this->_start('add', $pending, $inputs, ['duplicates' => $dupes]);
        }

        list($input, $pending, $error) = $this->_slot(1);

        if ($error !== '')
        {
            return $this->_fail($error);
        }

        $print = (string) ($pending['fingerprint'] ?? '');

        if ($print !== '' AND Service_Nj653_Store::fingerprint_exists($visitor, $print))
        {
            return $this->_fail('Такой элемент уже есть в коллекции — '
                .'второй раз считать его вектор незачем.', 409);
        }

        $pending['label'] = $this->_label($this->_body['label'] ?? '', $pending);

        return $this->_start('add', [$pending], [$input]);
    }

    /**
     * Пачка входов из тела запроса: items = [{text, image, preview, label}, …].
     *
     * NULL — пачки нет вовсе (обычное добавление одного элемента), пустой
     * массив — пачка пришла, но в ней не оказалось ничего пригодного.
     */
    protected function _bulk()
    {
        $raw = $this->_body['items'] ?? NULL;

        if ( ! is_array($raw) OR ! $raw)
        {
            return is_array($raw) ? [] : NULL;
        }

        $limit = max(1, (int) $this->_limit('bulk_max', 12));
        $out   = [];

        foreach ($raw as $item)
        {
            if ( ! is_array($item))
            {
                continue;
            }

            if (trim((string) ($item['text'] ?? '')) === ''
                AND (string) ($item['image'] ?? '') === '')
            {
                continue;
            }

            $out[] = $item;

            if (count($out) >= $limit)
            {
                break;
            }
        }

        return $out;
    }

    /**
     * Наполнить коллекцию примерами из конфига — одним прогоном.
     *
     * Без этого демонстрация начинается с пустого экрана: искать по смыслу
     * можно только когда есть среди чего искать.
     */
    public function action_seed()
    {
        $visitor = $this->_visitor();
        $samples = (array) Kohana::$config->load('nj653_veclab.samples');
        $max     = (int) $this->_limit('items_max', 60);
        $room    = $max - Service_Nj653_Store::items_count($visitor);

        if ($room < 1)
        {
            return $this->_fail('В коллекции уже '.$max.' элементов — предел демонстрации.', 429);
        }

        $inputs  = [];
        $pending = [];

        foreach (array_slice(array_values($samples), 0, max(1, min(12, $room))) as $text)
        {
            $text = trim((string) $text);

            if ($text === '')
            {
                continue;
            }

            $inputs[]  = ['slot' => 1, 'text' => $text, 'has_image' => FALSE];
            $pending[] = [
                'slot'    => 1,
                'kind'    => 'text',
                'label'   => mb_substr($text, 0, 200),
                'content' => $text,
                'preview' => '',
            ];
        }

        if ( ! $inputs)
        {
            return $this->_fail('Примеры не настроены: пустой раздел samples в конфиге модуля.', 500);
        }

        return $this->_start('add', $pending, $inputs);
    }

    /** Запрос (текст и/или картинка) → ближайшие элементы коллекции. */
    public function action_search()
    {
        $visitor = $this->_visitor();

        if (Service_Nj653_Store::items_count($visitor) < 1)
        {
            return $this->_fail('Коллекция пуста: сначала добавьте в неё хотя бы пару '
                .'картинок или текстов — искать пока не в чем.');
        }

        list($input, $pending, $error) = $this->_slot(1);

        if ($error !== '')
        {
            return $this->_fail($error);
        }

        $top = (int) ($this->_body['top'] ?? $this->_limit('top', 6));
        $input['instruction'] = (string) Kohana::$config->load('nj653_veclab.instructions.search');
        $pending['top'] = max(1, min(20, $top));

        return $this->_start('search', [$pending], [$input]);
    }

    /** Два входа → косинусная близость между ними. */
    public function action_compare()
    {
        $inputs  = [];
        $pending = [];

        foreach ([1, 2] as $slot)
        {
            list($input, $meta, $error) = $this->_slot($slot);

            if ($error !== '')
            {
                return $this->_fail('Вход '.$slot.': '.$error);
            }

            $meta['label']  = $this->_label($this->_body['label'.$slot] ?? '', $meta);
            $inputs[]  = $input;
            $pending[] = $meta;
        }

        return $this->_start('compare', $pending, $inputs);
    }

    /** Состояние прогона + контракт общего живого статус-ряда. */
    public function action_status()
    {
        $id = (int) ($this->_body['id'] ?? 0);

        if ($id <= 0)
        {
            return $this->_fail('Нужен id прогона');
        }

        $run  = Vision::run_get(Service_Nj653_Store::RUNS, $id);
        $hint = '';

        // Владелец проверяется ДО run_watch: чужой id не должен даже запускать
        // обслуживание задачи очереди, не говоря уже о выдаче результата.
        if ( ! $run OR ! $this->_mine($run))
        {
            return $this->_fail('Прогон не найден', 404);
        }

        if ( ! in_array((string) $run['status'], ['done', 'error', 'cancelled'], TRUE))
        {
            // Результат задачи очереди у нас НЕ равен результату прогона: в
            // нём нет находок, пока сайт не обработал векторы.
            $watch = Vision::run_watch(Service_Nj653_Store::RUNS, $id, 'nj653.embed');
            $run   = $watch['run'];
            $hint  = (string) $watch['hint'];
        }

        if ( ! $run)
        {
            return $this->_fail('Прогон не найден', 404);
        }

        $live = Vision::run_live($run);

        return $this->_respond([
            'ok'          => TRUE,
            'run_id'      => $id,
            'mode'        => (string) $run['mode'],
            'status'      => (string) $run['status'],
            'result'      => Service_Nj653_Veclab::shape((array) $run['result']),
            'error'       => (string) $run['error'],
            'hint'        => $hint,
            'status_live' => (string) $live['status'],
            'stage'       => (string) $live['stage'],
            'stages'      => (array) $live['stages'],
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'note'        => $live['note'] !== '' ? (string) $live['note'] : $hint,
            'attempts'    => (int) $live['attempts'],
        ]);
    }

    // ----------------------------------------------------------- коллекция

    /** Своя коллекция — список для страницы. */
    public function action_items()
    {
        $visitor = $this->_visitor();

        return $this->_respond([
            'ok'    => TRUE,
            'items' => Service_Nj653_Veclab::hits(
                Service_Nj653_Store::items_mine($visitor, 60)),
            'total' => Service_Nj653_Store::items_count($visitor),
            'max'   => (int) $this->_limit('items_max', 60),
        ]);
    }

    /** Убрать элемент ({id}) или всю свою коллекцию ({all: 1}). */
    public function action_forget()
    {
        $visitor = $this->_visitor();

        if ($visitor === '')
        {
            return $this->_fail('Коллекция привязана к браузеру, а метки нет: '
                .'включите cookie и обновите страницу.');
        }

        $id   = (int) ($this->_body['id'] ?? 0);
        $all  = ! empty($this->_body['all']);

        if ($id <= 0 AND ! $all)
        {
            return $this->_fail('Нужен id элемента или признак all');
        }

        $gone = Service_Nj653_Store::forget($visitor, $all ? 0 : $id);

        return $this->_respond(['ok' => TRUE, 'removed' => $gone]);
    }

    // -------------------------------------------------------------- воркер

    /** Кадр слота прогона воркеру (он работает с другого хоста). */
    public function action_photo_file()
    {
        if ( ! Vision::worker_token_ok())
        {
            return $this->_fail('Неверный токен воркера', 403);
        }

        $id   = (int) ($this->_body['run_id'] ?? 0);
        $slot = (int) ($this->_body['slot'] ?? 1);

        if ($id <= 0)
        {
            return $this->_fail('Нужен run_id', 400);
        }

        list($bytes, $mime) = Service_Nj653_Store::photo_bytes($id, $slot);

        if ($bytes === NULL)
        {
            Log_Plain::event(Log::WARNING, 'nj653: кадр прогона :run (слот :slot) не найден',
                [':run' => $id, ':slot' => $slot]);

            return $this->_fail('Кадр прогона не найден', 404);
        }

        return $this->_respond([
            'ok'         => TRUE,
            'image_b64'  => base64_encode($bytes),
            'media_type' => $mime,
            'kb'         => (int) ceil(strlen($bytes) / 1024),
        ]);
    }

    /**
     * Приёмка векторов от воркера.
     *
     * Воркер присылает только числа; что с ними делать, решает сайт — он
     * помнит, зачем создавался прогон (режим и входы, см. Store::run_create).
     */
    public function action_worker_result()
    {
        if ( ! Vision::worker_token_ok())
        {
            Log_Plain::event(Log::WARNING, 'nj653: приёмка результата — неверный токен воркера');

            return $this->_fail('Неверный токен воркера', 403);
        }

        $id  = (int) ($this->_body['run_id'] ?? 0);
        $run = $id > 0 ? Vision::run_get(Service_Nj653_Store::RUNS, $id) : NULL;

        if ( ! $run)
        {
            return $this->_fail('Прогон не найден', 404);
        }

        try
        {
            $status = $this->_accept($run, $this->_body);
        }
        catch (Throwable $e)
        {
            $reason = get_class($e).': '.$e->getMessage();

            Log_Plain::event(Log::ERROR,
                'nj653: приёмка прогона :run упала: :e',
                [':run' => $id, ':e' => $reason],
                [':run' => $id, ':e' => $reason, 'exception' => $e]);

            Vision::run_apply(Service_Nj653_Store::RUNS, $id, [
                'status'  => 'error',
                'mode'    => (string) $run['mode'],
                'pending' => Service_Nj653_Store::pending_of($run),
                'error'   => 'Сайт не смог принять векторы: '.mb_substr($e->getMessage(), 0, 300),
            ]);

            return $this->_fail('приёмка результата упала: '.mb_substr($reason, 0, 300), 500);
        }

        // Векторы на сайте — кадры больше не нужны (в коллекции живёт превью).
        Service_Nj653_Store::photo_drop($id);

        Log_Plain::event(Log::INFO, 'nj653: прогон :run (:mode) принят — :status',
            [':run' => $id, ':mode' => (string) $run['mode'], ':status' => $status]);

        return $this->_respond(['ok' => TRUE, 'status' => $status]);
    }

    // ----------------------------------------------------------- помощники

    /**
     * Разобрать результат воркера и записать прогон. Возвращает статус.
     *
     * Здесь же происходит то, ради чего модуль существует: вектор поиска
     * уходит в PostgreSQL и возвращается списком ближайших элементов.
     */
    protected function _accept(array $run, array $body)
    {
        $id   = (int) $run['id'];
        $mode = (string) $run['mode'];
        // Входы сохраняем ДАЖЕ в отказе: у задачи есть вторая попытка, и
        // потерянные подписи/тексты сделали бы её результат безымянным.
        $pending = Service_Nj653_Store::pending_of($run);

        if ((string) ($body['status'] ?? 'done') === 'error')
        {
            return Vision::run_apply(Service_Nj653_Store::RUNS, $id, [
                'status'  => 'error',
                'mode'    => $mode,
                'pending' => $pending,
                'error'   => mb_substr((string) ($body['error'] ?? 'Причина не записана'), 0, 900),
            ]);
        }

        $vectors = [];

        foreach ((array) ($body['vectors'] ?? []) as $vector)
        {
            if (is_array($vector) AND $vector)
            {
                $vectors[] = array_map('floatval', array_values($vector));
            }
        }

        $visitor = (string) $run['visitor'];

        // Общая часть ответа: чем считали и сколько это заняло.
        $result = [
            'mode'       => $mode,
            'model'      => mb_substr((string) ($body['model'] ?? ''), 0, 160),
            'dims'       => (int) ($body['dims'] ?? 0),
            'dialect'    => mb_substr((string) ($body['dialect'] ?? ''), 0, 40),
            'elapsed_ms' => (int) ($body['elapsed_ms'] ?? 0),
            'title'      => mb_substr((string) ($body['title'] ?? ''), 0, 200),
            'kinds'      => (array) ($body['kinds'] ?? []),
        ];

        if ($mode === 'probe')
        {
            // Проверка модели — чистая справка: сохраняем как прислали.
            $result['passport'] = (array) ($body['passport'] ?? []);
            $result['checks']   = (array) ($body['checks'] ?? []);
            $result['cross']    = (array) ($body['cross'] ?? []);
            $result['ok']       = ! empty($body['ok']);
        }
        elseif ($mode === 'compare')
        {
            if (count($vectors) < 2)
            {
                throw new Kohana_Exception('Для сравнения нужно два вектора, пришло :n',
                    [':n' => count($vectors)]);
            }

            $result['cosine'] = round(Service_Nj653_Veclab::cosine($vectors[0], $vectors[1]), 4);
        }
        elseif ($mode === 'search')
        {
            if ( ! $vectors)
            {
                throw new Kohana_Exception('Воркер не прислал вектор запроса');
            }

            $top = (int) ($pending[0]['top'] ?? $this->_limit('top', 6));

            $result['query'] = $pending[0] ?? [];
            $result['hits']  = Service_Nj653_Store::search($vectors[0], $visitor, $top);
            $result['title'] = 'Поиск: '.mb_substr((string) ($pending[0]['content'] ?? 'картинка'), 0, 80);
        }
        else
        {
            if ( ! $vectors)
            {
                throw new Kohana_Exception('Воркер не прислал вектор элемента');
            }

            $ids   = [];
            $dupes = 0;

            foreach ($vectors as $n => $vector)
            {
                $meta = (array) ($pending[$n] ?? []);

                $new = Service_Nj653_Store::item_add([
                    'user_id'     => $run['user_id'],
                    'visitor'     => $visitor,
                    'run_id'      => $id,
                    'label'       => (string) ($meta['label'] ?? ''),
                    'kind'        => (string) ($meta['kind'] ?? 'text'),
                    'content'     => (string) ($meta['content'] ?? ''),
                    'preview'     => (string) ($meta['preview'] ?? ''),
                    'model'       => $result['model'],
                    'fingerprint' => (string) ($meta['fingerprint'] ?? ''),
                    'meta'        => (array) ($meta['meta'] ?? []),
                ], $vector);

                // 0 вернулся из-за уникального индекса: ровно такой элемент
                // у посетителя уже есть (вторая вкладка, повторная отправка).
                if ($new > 0)
                {
                    $ids[] = $new;
                }
                else
                {
                    $dupes++;
                }
            }

            $result['added']      = count($ids);
            $result['duplicates'] = $dupes;
            $result['item_id']    = (int) ($ids[0] ?? 0);
            $result['title']   = mb_substr((string) ($pending[0]['label'] ?? 'Элемент коллекции'), 0, 200);
            // Сразу показываем, рядом с чем он встал: это и есть демонстрация.
            $result['neighbors'] = Service_Nj653_Store::search(
                $vectors[0], $visitor, 4, (int) ($ids[0] ?? 0));
        }

        $result['status'] = 'done';

        return Vision::run_apply(Service_Nj653_Store::RUNS, $id, $result);
    }

    /**
     * Общий старт прогона: проверки → строка в базе → кадры → задача.
     *
     * @param string $mode    probe | add | search | compare
     * @param array  $pending описание входов для сайта (подпись, текст, превью)
     * @param array  $inputs  описание входов для воркера (текст, слот, кадр)
     */
    protected function _start($mode, array $pending, array $inputs, array $extra = [])
    {
        if ( ! Service_Nj653_Store::table_ready(Service_Nj653_Store::RUNS))
        {
            return $this->_fail('Хранилище лаборатории ещё не создано: выполните миграции модуля.', 503);
        }

        $ip       = class_exists('Service_Nj100_Ratelimit') ? Service_Nj100_Ratelimit::client_ip() : '';
        $per_hour = (int) $this->_limit('per_hour', 40);

        if (Vision::runs_last_hour(Service_Nj653_Store::RUNS, $ip) >= $per_hour)
        {
            return $this->_fail('Лимит: не больше '.$per_hour.' обращений к модели в час '
                .'с одного адреса. Подождите немного.', 429);
        }

        $run_id = Service_Nj653_Store::run_create($mode, $pending, [
            'user_id'   => $this->_user_id(),
            'visitor'   => $this->_visitor(),
            'client_ip' => $ip,
            'title'     => Service_Nj653_Veclab::mode_label($mode),
        ]);

        if ( ! $run_id)
        {
            return $this->_fail('Не удалось создать прогон', 500);
        }

        // Кадры кладём файлами: base64 в payload общей очереди — это мегабайты
        // на каждый опрос воркера (урок nj646, см. шапку класса Vision).
        foreach ($inputs as $n => $input)
        {
            unset($inputs[$n]['bytes']);

            if (empty($input['bytes']))
            {
                continue;
            }

            if (Service_Nj653_Store::photo_save($run_id, $input['slot'], $input['bytes']) === '')
            {
                Vision::run_apply(Service_Nj653_Store::RUNS, $run_id, [
                    'status'  => 'error',
                    'mode'    => $mode,
                    'pending' => $pending,
                    'error'   => 'Не удалось сохранить картинку на сервере',
                ]);
                Service_Nj653_Store::photo_drop($run_id);

                return $this->_respond([
                    'ok'     => FALSE,
                    'run_id' => $mode === 'search' ? $run_id : NULL,
                    'error'  => 'Не удалось сохранить картинку — попробуйте ещё раз.',
                ], 500);
            }
        }

        Vision::photo_gc(Service_Nj653_Store::MODULE);

        try
        {
            $job_id = (int) Service_Nj120_JobQueue::push('nj653.embed', [
                'run_id' => $run_id,
                'mode'   => $mode,
                'inputs' => array_values($inputs),
            ], [
                'module'       => Service_Nj653_Store::MODULE,
                'timeout_sec'  => (int) $this->_limit('timeout_sec', 240),
                'max_attempts' => 2,
            ]);

            if ($job_id <= 0)
            {
                throw new RuntimeException('Очередь не вернула ID задачи');
            }

            Vision::run_set_job(Service_Nj653_Store::RUNS, $run_id, $job_id);

            Log_Plain::event(Log::INFO, 'nj653: прогон :run (:mode) поставлен задачей :job',
                [':run' => $run_id, ':mode' => $mode, ':job' => $job_id]);
        }
        catch (Throwable $e)
        {
            Vision::run_apply(Service_Nj653_Store::RUNS, $run_id, [
                'status'  => 'error',
                'mode'    => $mode,
                'pending' => $pending,
                'error'   => 'Не удалось поставить задачу в очередь: '.$e->getMessage(),
            ]);
            Service_Nj653_Store::photo_drop($run_id);

            return $this->_respond([
                'ok'     => FALSE,
                'run_id' => $mode === 'search' ? $run_id : NULL,
                'error'  => 'Очередь недоступна: '.$e->getMessage(),
            ], 503);
        }

        $dupes = (int) ($extra['duplicates'] ?? 0);

        return $this->_respond([
            'ok'         => TRUE,
            'run_id'     => $run_id,
            'mode'       => $mode,
            'duplicates' => $dupes,
            'message'    => 'Запрос ушёл к модели — обычно это несколько секунд.'
                .($dupes > 0 ? ' Пропущено дублей: '.$dupes.'.' : ''),
        ]);
    }

    /**
     * Разобрать один вход запроса: текст и/или картинка.
     *
     * @return array [для воркера, для сайта, ошибка]
     */
    protected function _slot($slot, ?array $item = NULL)
    {
        $slot   = max(1, min(Service_Nj653_Store::SLOT_MAX, (int) $slot));
        $suffix = ($item === NULL AND $slot > 1) ? (string) $slot : '';
        // Пачка приходит списком объектов, одиночный вход — полями text/image
        // с номером слота в имени. Разбор дальше общий.
        $src    = $item === NULL ? $this->_body : $item;
        $suffix = $item === NULL ? $suffix : '';

        $text = trim((string) ($src['text'.$suffix] ?? ''));
        $text = mb_substr($text, 0, (int) $this->_limit('text_max', 2000));

        $raw   = (string) ($src['image'.$suffix] ?? '');
        $bytes = $raw !== '' ? Vision::decode($raw) : '';

        if ($raw !== '' AND $bytes === '')
        {
            return [[], [], 'Картинку не удалось прочитать — приложите её заново.'];
        }

        if ($bytes !== '' AND ! Vision::looks_like_image($bytes))
        {
            return [[], [], 'Это не похоже на изображение (JPEG, PNG или WebP).'];
        }

        $max_mb = (int) $this->_limit('photo_mb', 8);

        if ($bytes !== '' AND strlen($bytes) > $max_mb * 1024 * 1024)
        {
            return [[], [], 'Картинка больше '.$max_mb.' МБ — сожмите её.'];
        }

        if ($text === '' AND $bytes === '')
        {
            return [[], [], 'Нужен текст, картинка или и то и другое.'];
        }

        $kind = ($text !== '' AND $bytes !== '') ? 'both' : ($bytes !== '' ? 'image' : 'text');

        $for_worker = [
            'slot'      => $slot,
            'text'      => $text,
            'has_image' => $bytes !== '',
            'bytes'     => $bytes,
        ];

        $client_meta = $src['meta'.$suffix] ?? [];

        $for_site = [
            'slot'    => $slot,
            'kind'    => $kind,
            'content' => $text,
            'preview' => Service_Nj653_Veclab::safe_preview(
                (string) ($src['preview'.$suffix] ?? '')),
            // Отпечаток считаем здесь, пока кадр ещё цел: после подсчёта
            // вектора он удаляется, и повтор будет уже не с чем сравнить.
            'fingerprint' => Service_Nj653_Store::fingerprint($text, $bytes),
            'meta'        => Service_Nj653_Store::image_meta(
                $bytes, is_array($client_meta) ? $client_meta : []),
        ];

        return [$for_worker, $for_site, ''];
    }

    /** Подпись элемента: своя, иначе — начало текста, иначе «Картинка». */
    protected function _label($given, array $meta)
    {
        $label = trim((string) $given);

        if ($label === '')
        {
            $label = trim((string) ($meta['content'] ?? ''));
        }

        if ($label === '')
        {
            $label = 'Картинка';
        }

        return mb_substr($label, 0, 200);
    }

    protected function _limit($key, $default)
    {
        $value = Kohana::$config->load('nj653_veclab.limits.'.$key);

        return ($value === NULL OR $value === '') ? $default : $value;
    }

    /** Строгая проверка владельца: тот же аккаунт либо тот же браузер. */
    protected function _mine(array $run)
    {
        return Vision::run_owned($run, $this->_visitor(), $this->_user_id());
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
}
