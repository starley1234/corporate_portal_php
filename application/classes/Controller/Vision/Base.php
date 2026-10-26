<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Общий JSON API сервисов «фото → vision-модель (VLM)».
 *
 * Четыре точки одинаковы у всех таких сервисов, и различаются они ровно тремя
 * вещами: канал очереди, таблица прогонов и то, как разобрать ответ модели.
 * Поэтому здесь — конвейер, а в модуле — только `_vision()` с этими тремя
 * ответами (см. `Controller_Nj650_Api`).
 *
 *   POST  <база>/photo          кадр (data URL или base64) → задача в очередь
 *   GET   <база>/status?id=N    статус прогона (контракт живого статус-ряда)
 *   POST  <база>/photo_file     кадр воркеру (X-Worker-Token)
 *   POST  <база>/worker_result  приёмка результата (X-Worker-Token)
 *
 * Почему кадр не едет в payload задачи и почему воркер забирает его отдельным
 * запросом — объяснено в шапке `Vision`.
 */
abstract class Controller_Vision_Base extends Controller
{
    public $auto_render = FALSE;

    /** @var array тело запроса (JSON или форма) */
    protected $_body = [];

    /**
     * Описание сервиса — единственное, что пишет модуль.
     *
     * Обязательные ключи:
     *   module   — nj650_receipt (каталог кадров, module задачи, логи);
     *   table    — nj_650_runs (таблица прогонов модуля);
     *   channel  — nj650.receipt (канал очереди);
     *   title    — «Чек» (для сообщений человеку);
     * Необязательные:
     *   photo_mb      — предел кадра, МБ (8);
     *   per_hour      — сколько распознаваний с одного IP в час (12);
     *   timeout_sec   — предел задачи (300; должен быть БОЛЬШЕ VLM_TIMEOUT);
     *   auth          — TRUE: создание прогона только для зарегистрированных
     *                   (решение владельца 06.10.2026 «как опросы» — аноним не
     *                   занимает очередь и время vision-модели; nj650/651/652).
     *                   /status и страница разбора остаются при своём доступе;
     *   payload       — доп. поля задачи (например, профиль посетителя);
     *   shape         — callable(array $payload): array — разбор ответа воркера
     *                   в вид, который рисует страница;
     *   preview_save  — callable($run_id, $bytes): bool — необязательная личная
     *                   миниатюра, создаётся до удаления исходного кадра.
     *
     * @return array
     */
    abstract protected function _vision();

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
    }

    // --------------------------------------------------------------- точки

    /** Приём кадра: кладём файлом, ставим задачу, отдаём id прогона. */
    public function action_photo()
    {
        $cfg = $this->_vision();

        // Создание прогона — только для зарегистрированных (решение владельца
        // 06.10.2026, «как опросы»): аноним не занимает очередь и время
        // vision-модели. Включает модуль ключом 'auth' в _vision(); гость
        // получает 401 и адрес входа, как у /nj530/api/shorten.
        if ( ! empty($cfg['auth']) AND ! $this->_logged_in())
        {
            return $this->_respond([
                'ok'    => FALSE,
                'error' => 'Разбор фото доступен после входа',
                'login' => '/auth/login',
            ], 401);
        }

        if ( ! Vision::ready())
        {
            return $this->_fail('Распознавание по фото пока недоступно: на сервере не подключена '
                .'vision-модель (VLM). Загляните позже — страница и примеры работают без неё.', 200);
        }

        if ( ! Vision::table_exists($cfg['table']))
        {
            return $this->_fail('Хранилище прогонов ещё не создано: выполните миграции модуля.', 503);
        }

        $bytes = Vision::decode((string) ($this->_body['image'] ?? ''));

        if ($bytes === '')
        {
            return $this->_fail('Прикрепите фото.');
        }

        if ( ! Vision::looks_like_image($bytes))
        {
            return $this->_fail('Это не похоже на изображение (JPEG, PNG или WebP).');
        }

        $max_mb = (int) ($cfg['photo_mb'] ?? 8);

        if (strlen($bytes) > $max_mb * 1024 * 1024)
        {
            return $this->_fail('Фото больше '.$max_mb.' МБ. Сожмите его или снимите кадр поменьше.');
        }

        $ip       = class_exists('Service_Nj100_Ratelimit') ? Service_Nj100_Ratelimit::client_ip() : '';
        $per_hour = (int) ($cfg['per_hour'] ?? 12);

        if (Vision::runs_last_hour($cfg['table'], $ip) >= $per_hour)
        {
            return $this->_fail('Лимит распознаваний: не больше '.$per_hour.' в час с одного адреса.', 429);
        }

        $run_id = Vision::run_create($cfg['table'], [
            'user_id'   => $this->_user_id(),
            'visitor'   => $this->_visitor(),
            'client_ip' => $ip,
            'title'     => (string) ($this->_body['title'] ?? ''),
        ]);

        if ( ! $run_id)
        {
            return $this->_fail('Не удалось создать прогон', 500);
        }

        if (Vision::photo_save($cfg['module'], $run_id, $bytes) === '')
        {
            Vision::run_apply($cfg['table'], $run_id, [
                'status' => 'error',
                'error'  => 'Не удалось сохранить фото на сервере',
            ]);

            return $this->_fail('Не удалось сохранить фото — попробуйте ещё раз.', 500);
        }

        // Некоторые сервисы сохраняют маленькую личную миниатюру для истории.
        // Ошибка миниатюры не должна останавливать сам разбор фото.
        if (isset($cfg['preview_save']) AND is_callable($cfg['preview_save']))
        {
            try
            {
                if ( ! call_user_func($cfg['preview_save'], $run_id, $bytes))
                {
                    Log_Plain::event(Log::WARNING, ':m: миниатюра прогона :run не сохранена',
                        [':m' => $cfg['module'], ':run' => $run_id]);
                }
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::WARNING, ':m: создание миниатюры прогона :run упало: :e',
                    [':m' => $cfg['module'], ':run' => $run_id, ':e' => $e->getMessage()]);
            }
        }

        // Заодно подчищаем кадры задач, которые умерли вместе с воркером.
        Vision::photo_gc($cfg['module']);

        try
        {
            $job_id = Service_Nj120_JobQueue::push($cfg['channel'], [
                'run_id'     => $run_id,
                'photo'      => TRUE,
                'media_type' => Vision::mime($bytes),
            ] + (array) ($cfg['payload'] ?? []), [
                'module'       => $cfg['module'],
                // Больше предела ожидания модели в воркере (+ запас на разбор
                // ответа и доставку): иначе задачу снимут ровно в момент
                // ответа модели, и на сайт не уедет ни результат, ни ошибка.
                'timeout_sec'  => (int) ($cfg['timeout_sec'] ?? 300),
                'max_attempts' => 2,
            ]);

            Vision::run_set_job($cfg['table'], $run_id, $job_id);

            Log_Plain::event(Log::INFO, ':m: фото принято — прогон :run, задача :job, :kb КБ',
                [':m' => $cfg['module'], ':run' => $run_id, ':job' => $job_id,
                 ':kb' => (int) ceil(strlen($bytes) / 1024)]);
        }
        catch (Throwable $e)
        {
            Vision::run_apply($cfg['table'], $run_id, [
                'status' => 'error',
                'error'  => 'Не удалось поставить задачу в очередь: '.$e->getMessage(),
            ]);
            Vision::photo_drop($cfg['module'], $run_id);

            return $this->_fail('Очередь недоступна: '.$e->getMessage(), 503);
        }

        return $this->_respond([
            'ok'      => TRUE,
            'run_id'  => $run_id,
            'message' => 'Фото ушло на распознавание — обычно это меньше минуты.',
        ]);
    }

    /** Статус прогона: свой статус + контракт общего статус-ряда платформы. */
    public function action_status()
    {
        $cfg = $this->_vision();
        $id  = (int) ($this->_body['id'] ?? 0);

        if ($id <= 0)
        {
            return $this->_fail('Нужен id прогона');
        }

        // Сначала проверяем владельца и только затем вызываем run_watch:
        // он обслуживает задачу очереди и не должен работать по чужому id.
        $visitor = $this->_visitor();
        $user_id = $this->_user_id();
        $run     = Vision::run_get($cfg['table'], $id);

        if ( ! $run OR ! Vision::run_owned($run, $visitor, $user_id))
        {
            return $this->_fail('Прогон не найден', 404);
        }

        $watch = Vision::run_watch($cfg['table'], $id, (string) $cfg['channel']);
        $run   = $watch['run'];

        if ( ! $run)
        {
            return $this->_fail('Прогон не найден', 404);
        }

        $live = Vision::run_live($run);

        return $this->_respond([
            'ok'          => TRUE,
            'run_id'      => $id,
            'status'      => (string) $run['status'],
            'result'      => $this->_shape((array) $run['result']),
            'error'       => (string) $run['error'],
            'hint'        => (string) $watch['hint'],
            // контракт статус-ряда (partials/live_status_js)
            'status_live' => (string) $live['status'],
            'stage'       => (string) $live['stage'],
            'stages'      => (array) $live['stages'],
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'note'        => $live['note'] !== '' ? (string) $live['note'] : (string) $watch['hint'],
            'attempts'    => (int) $live['attempts'],
        ]);
    }

    /** Кадр прогона воркеру (он работает с другого хоста, пути ему не помогут). */
    public function action_photo_file()
    {
        if ( ! Vision::worker_token_ok())
        {
            return $this->_fail('Неверный токен воркера', 403);
        }

        $cfg = $this->_vision();
        $id  = (int) ($this->_body['run_id'] ?? 0);

        if ($id <= 0)
        {
            return $this->_fail('Нужен run_id', 400);
        }

        list($bytes, $mime) = Vision::photo_bytes($cfg['module'], $id);

        if ($bytes === NULL)
        {
            Log_Plain::event(Log::WARNING, ':m: кадр прогона :run не найден',
                [':m' => $cfg['module'], ':run' => $id]);

            return $this->_fail('Кадр прогона не найден', 404);
        }

        return $this->_respond([
            'ok'         => TRUE,
            'image_b64'  => base64_encode($bytes),
            'media_type' => $mime,
            'kb'         => (int) ceil(strlen($bytes) / 1024),
        ]);
    }

    /** Приёмка результата воркера. */
    public function action_worker_result()
    {
        if ( ! Vision::worker_token_ok())
        {
            Log_Plain::event(Log::WARNING, 'vision: приёмка результата — неверный токен воркера');

            return $this->_fail('Неверный токен воркера', 403);
        }

        $cfg = $this->_vision();
        $id  = (int) ($this->_body['run_id'] ?? 0);

        if ($id <= 0 OR ! Vision::run_get($cfg['table'], $id))
        {
            return $this->_fail('Прогон не найден', 404);
        }

        try
        {
            $status = Vision::run_apply($cfg['table'], $id, $this->_body);
        }
        catch (Throwable $e)
        {
            $reason = get_class($e).': '.$e->getMessage();

            Log_Plain::event(Log::ERROR,
                ':m: приёмка прогона :run упала — прогон останется queued: :e',
                [':m' => $cfg['module'], ':run' => $id, ':e' => $reason],
                [':m' => $cfg['module'], ':run' => $id, ':e' => $reason, 'exception' => $e]);

            return $this->_fail('приёмка результата упала: '.mb_substr($reason, 0, 300), 500);
        }

        // Результат на сайте — кадр больше не нужен (повтор идёт новым фото).
        if ($status === 'done')
        {
            Vision::photo_drop($cfg['module'], $id);
        }

        Log_Plain::event(Log::INFO, ':m: результат прогона :run принят (:status)',
            [':m' => $cfg['module'], ':run' => $id, ':status' => $status]);

        return $this->_respond(['ok' => TRUE, 'status' => $status]);
    }

    // ----------------------------------------------------------- помощники

    /** Разбор результата для страницы: по умолчанию — как есть. */
    protected function _shape(array $result)
    {
        $cfg = $this->_vision();

        if (isset($cfg['shape']) AND is_callable($cfg['shape']))
        {
            return (array) call_user_func($cfg['shape'], $result);
        }

        return $result;
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

    /** Залогинен ли посетитель: право создания — просто роль login, как у опросов. */
    protected function _logged_in()
    {
        return class_exists('Auth') AND Auth::instance()->logged_in();
    }

    protected function _user_id()
    {
        return class_exists('Visitor') ? Visitor::user_id() : NULL;
    }
}
