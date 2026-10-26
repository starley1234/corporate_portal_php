<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Генератор изображений» (nj641_imagegen).
 *
 *   POST /imagegen/api/run            — поставить генерацию в очередь (nj641.generate);
 *   GET  /imagegen/api/result/<id>    — статус/результат прогона (картинка base64);
 *   GET  /imagegen/api/runs           — последние прогоны;
 *   GET  /imagegen/api/vram           — параметры проверки видеопамяти бекенда;
 *   GET  /imagegen/api/status/<id>    — лёгкий статус-ряд прогона (этап, таймер);
 *   POST /imagegen/api/delete/<id>    — удалить свой прогон (строка + файл);
 *   GET  /imagegen/file/<имя>         — картинка прогона (файл из storage/nj641);
 *   POST /imagegen/api/clear          — очистка кэша бекенда (X-Worker-Token);
 *   POST /imagegen/api/review/<id>    — модерация картинки (nj641.admin);
 *   POST /imagegen/api/review-many    — пакетная модерация (nj641.admin);
 *   GET  /imagegen/api/library        — библиотека одобренных изображений (для сервисов);
 *   POST /imagegen/api/worker-result  — приём результата от воркера (X-Worker-Token);
 *   POST /imagegen/api/worker-upload  — приём ФАЙЛА картинки от воркера (multipart).
 */
class Controller_Nj641_Api extends Controller {

    public $auto_render = FALSE;

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

        // Замок: генератор работает только по бесплатному аккаунту (владелец,
        // 08.10.2026) — генерация занимает очередь и видеопамять сервера.
        // Воркерские точки (приём файла и результата, очистка кэша бекенда)
        // ходят по X-Worker-Token и проверяют его сами — их пропускаем.
        if ( ! Gate::logged_in() AND ! Gate::worker_action($this->request->action()))
        {
            throw HTTP_Exception::factory(401, 'Генератор изображений работает только по бесплатному аккаунту');
        }
    }

    public function action_index()
    {
        $action = $this->request->param('action');
        $method = 'action_'.str_replace('-', '_', (string) $action);

        if ($action !== NULL AND method_exists($this, $method))
        {
            $this->{$method}();
        }
        else
        {
            $this->_respond(['ok' => FALSE, 'error' => 'Неизвестное действие API.'], 404);
        }
    }

    // ------------------------------------------------------------ генерация

    public function action_run()
    {
        if ($this->request->method() !== 'POST')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
        }

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $uid = (string) Visitor::uid();

        $res = Service_Nj641_Imagegen::enqueue($uid, $this->_body);

        if (isset($res['error']))
        {
            return $this->_respond(['ok' => FALSE, 'error' => $res['error']], 422);
        }

        return $this->_respond(['ok' => TRUE, 'run_id' => $res['run_id']]);
    }

    public function action_result()
    {
        $run_id = (int) $this->request->param('id');

        $run = Service_Nj641_Imagegen::result_of($run_id);

        if ($run === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Прогон не найден.'], 404);
        }

        $image_info = $run['image_info'];
        if (is_string($image_info) AND $image_info !== '')
        {
            $decoded = json_decode($image_info, TRUE);
            $image_info = is_array($decoded) ? $decoded : NULL;
        }

        $url = Service_Nj641_Imagegen::image_url($run);

        return $this->_respond([
            'ok'     => TRUE,
            'id'     => (int) $run['id'],
            'mode'   => $run['mode'],
            'prompt' => $run['prompt'],
            'width'  => (int) $run['width'],
            'height' => (int) $run['height'],
            'seed'   => (int) $run['seed'],
            'status' => $run['status'],
            'model'  => $run['model'],
            'duration_ms' => (int) $run['duration_ms'],
            // Картинка теперь файлом: URL вместо мегабайтов base64 в JSON.
            // base64 остаётся только у старых прогонов (в базе уже лежит).
            'image_url'   => $url,
            'image_b64'   => ($url === '' AND $run['status'] === Service_Nj641_Imagegen::STATUS_DONE)
                ? (string) $run['image_b64'] : '',
            'image_info'  => $image_info,
            'stage'       => (string) ($run['stage'] ?? ''),
            'elapsed_sec' => (int) ($run['elapsed_sec'] ?? 0),
            'error'  => $run['error'],
        ], 200);
    }

    /**
     * GET /imagegen/api/status/<id> — лёгкий статус-ряд прогона.
     *
     * Отдельно от /result: страница опрашивает его каждые 2.5 с, и таскать в
     * ответе картинку незачем — только статус, этап воркера и таймер (контракт
     * статус-ряда /trends: stage / stages / elapsed_sec / silent_sec / error).
     */
    public function action_status()
    {
        $run = Service_Nj641_Imagegen::result_of((int) $this->request->param('id'));

        if ($run === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Прогон не найден.'], 404);
        }

        $live = Service_Nj641_Imagegen::live_of((int) ($run['job_id'] ?? 0));

        return $this->_respond([
            'ok'          => TRUE,
            'id'          => (int) $run['id'],
            'status'      => (string) $run['status'],
            'stage'       => (string) ($run['stage'] ?? ($live['stage'] ?? '')),
            'stages'      => (array) ($run['stages'] ?? ($live['stages'] ?? [])),
            'elapsed_sec' => (int) ($run['elapsed_sec'] ?? ($live['elapsed_sec'] ?? 0)),
            'silent_sec'  => $run['silent_sec'] ?? ($live['silent_sec'] ?? NULL),
            'image_url'   => Service_Nj641_Imagegen::image_url($run),
            'error'       => (string) ($run['error'] ?? ''),
        ]);
    }

    /**
     * GET /imagegen/file/<имя> — картинка прогона.
     *
     * Файл лежит в public_html/storage/nj641 и отдаётся по имени со случайным
     * хвостом (капабилити-ссылка, как /p/<код> у опросов): угадать чужой файл
     * нельзя, а имя проверяется строгим шаблоном — выйти из каталога нечем.
     */
    public function action_file()
    {
        $name = (string) $this->request->param('id');
        $path = Service_Nj641_Imagegen::file_path($name);

        if ($path === NULL)
        {
            return $this->response->status(404)->body('Картинка не найдена');
        }

        $this->response->headers('Content-Type', Service_Nj641_Imagegen::file_media_type($path));
        $this->response->headers('Content-Length', (string) filesize($path));
        // Имя файла уникально и не переиспользуется — кэшируем «навсегда».
        $this->response->headers('Cache-Control', 'public, max-age=31536000, immutable');
        $this->response->headers('X-Content-Type-Options', 'nosniff');
        $this->response->body((string) file_get_contents($path));
    }

    /** POST /imagegen/api/cancel/<id> — остановить свой прогон вручную. */
    public function action_cancel()
    {
        if ($this->request->method() !== 'POST')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
        }

        if ( ! Security::check((string) ($this->_body['csrf'] ?? '')))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела — обновите страницу.'], 419);
        }

        $ok = Service_Nj641_Imagegen::cancel_run((int) $this->request->param('id'), (string) Visitor::uid());

        return $this->_respond($ok
            ? ['ok' => TRUE]
            : ['ok' => FALSE, 'error' => 'Прогон уже завершён.'], $ok ? 200 : 409);
    }

    /** POST /imagegen/api/delete/<id> — удалить свой прогон вместе с файлом. */
    public function action_delete()
    {
        if ($this->request->method() !== 'POST')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
        }

        if ( ! Security::check((string) ($this->_body['csrf'] ?? '')))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела — обновите страницу.'], 419);
        }

        // Модерация: администратор может убрать чужое изображение со страницы
        // «Изображения». Для остальных — как раньше, только свой прогон.
        $ok = Service_Nj641_Imagegen::delete_run(
            (int) $this->request->param('id'),
            (string) Visitor::uid(),
            Service_Nj641_Imagegen::can_moderate());

        return $this->_respond($ok
            ? ['ok' => TRUE]
            : ['ok' => FALSE, 'error' => 'Прогон не найден.'], $ok ? 200 : 404);
    }

    /**
     * POST /imagegen/api/review/<id> — модерация изображения: «в библиотеку»
     * (approved), «отклонить» (rejected) или «вернуть на проверку» (pending).
     *
     * Доступ — право nj641.admin (по умолчанию роль admin): модерация чужих
     * картинок не должна быть доступна каждому посетителю.
     */
    public function action_review()
    {
        if ($this->request->method() !== 'POST')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
        }

        if ( ! Service_Nj641_Imagegen::can_moderate())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Только для администратора.'], 403);
        }

        if ( ! Security::check((string) ($this->_body['csrf'] ?? '')))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела — обновите страницу.'], 419);
        }

        $run_id = (int) $this->request->param('id');
        $status = (string) ($this->_body['status'] ?? '');
        $ok     = Service_Nj641_Imagegen::review_run($run_id, $status, (string) Visitor::uid());

        // Отдельный случай: колонок модерации нет (миграция 002 не применена) —
        // «не найдено» вводило бы в заблуждение, показываем причину.
        $note = Service_Nj641_Imagegen::library_note();

        return $this->_respond([
            'ok'     => $ok,
            'id'     => $run_id,
            'status' => $status,
            'counts' => Service_Nj641_Imagegen::library_counts(),
            'flash'  => $ok ? self::_review_text($status)
                            : ($note !== '' ? 'Модерация недоступна: колонки ещё не созданы.' : 'Изображение не найдено.'),
        ], $ok ? 200 : 404);
    }

    /**
     * POST /imagegen/api/review-many — пакетная модерация выбранных картинок.
     * Тело: {ids: [1,2,3], status: approved|rejected|pending, csrf}
     */
    public function action_review_many()
    {
        if ($this->request->method() !== 'POST')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
        }

        if ( ! Service_Nj641_Imagegen::can_moderate())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Только для администратора.'], 403);
        }

        if ( ! Security::check((string) ($this->_body['csrf'] ?? '')))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела — обновите страницу.'], 419);
        }

        $status  = (string) ($this->_body['status'] ?? '');
        $changed = Service_Nj641_Imagegen::review_many((array) ($this->_body['ids'] ?? []), $status, (string) Visitor::uid());

        if ($changed === 0 AND Service_Nj641_Imagegen::library_note() !== '')
        {
            return $this->_respond([
                'ok'     => FALSE,
                'error'  => 'Модерация недоступна: колонки модерации ещё не созданы — примените миграции модуля 641.',
                'counts' => Service_Nj641_Imagegen::library_counts(),
            ], 503);
        }

        return $this->_respond([
            'ok'      => TRUE,
            'changed' => $changed,
            'status'  => $status,
            'counts'  => Service_Nj641_Imagegen::library_counts(),
            'flash'   => $changed > 0
                ? $changed.' '.self::_plural($changed, ['изображение', 'изображения', 'изображений']).' — '.self::_review_text($status)
                : 'Ничего не изменилось: выберите изображения.',
        ]);
    }

    /**
     * GET /imagegen/api/library — библиотека одобренных изображений.
     *
     * Для других сервисов: картинки, прошедшие модерацию. Лимит — до 60 за раз,
     * rate limit как у остальных публичных ручек.
     */
    public function action_library()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_rate_error();
        }

        $limit = (int) $this->request->query('limit');

        if ($limit <= 0 OR $limit > 60)
        {
            $limit = 12;
        }

        return $this->_respond([
            'ok'    => TRUE,
            'items' => Service_Nj641_Imagegen::library_pick($limit, 0, (string) $this->request->query('q')),
        ]);
    }

    /** Человеческая подпись статуса модерации (для flash-строки интерфейса). */
    protected static function _review_text($status)
    {
        if ($status === Service_Nj641_Imagegen::REVIEW_APPROVED)
        {
            return 'в библиотеке';
        }

        if ($status === Service_Nj641_Imagegen::REVIEW_REJECTED)
        {
            return 'отклонено';
        }

        return 'вернули на проверку';
    }

    /** Русская форма слова по числу: 1 изображение, 2 изображения, 5 изображений. */
    protected static function _plural($n, array $forms)
    {
        $n = (int) $n;

        if ($n % 10 === 1 AND $n % 100 !== 11)
        {
            return $forms[0];
        }

        if ($n % 10 >= 2 AND $n % 10 <= 4 AND ($n % 100 < 10 OR $n % 100 >= 20))
        {
            return $forms[1];
        }

        return $forms[2];
    }

    /**
     * POST /imagegen/api/worker-upload — приём ФАЙЛА картинки от воркера.
     *
     * Почему отдельно от worker-result: base64-тело картинки 1024×1024 (2-4 МБ)
     * не проходит через лимиты nginx/PHP на размер запроса, и результат
     * «терялся» — прогон оставался queued, страница висела. multipart-загрузка
     * файла на треть легче и пишется в storage напрямую.
     */
    public function action_worker_upload()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Токен воркера не совпал'], 403);
        }

        $run_id = (int) ($_POST['run_id'] ?? $this->_body['run_id'] ?? 0);

        if ($run_id <= 0)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id'], 422);
        }

        $file = $_FILES['image'] ?? NULL;

        if ( ! is_array($file) OR (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Жду файл image'], 422);
        }

        if ((int) $file['size'] > 32 * 1024 * 1024)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Файл больше 32 МБ'], 413);
        }

        $bytes = @file_get_contents((string) $file['tmp_name']);

        if ($bytes === FALSE OR ! Service_Nj641_Imagegen::looks_like_image($bytes))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Файл не похож на изображение'], 422);
        }

        $stored = Service_Nj641_Imagegen::store_bytes($run_id, $bytes, (string) ($_POST['media_type'] ?? ''));

        if ($stored === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Не удалось сохранить файл (права на storage?)'], 500);
        }

        Service_Nj641_Imagegen::apply_result($run_id, [
            'status'      => 'done',
            'image_file'  => $stored,
            'image_info'  => ['media_type' => $stored['media_type'] ?? 'image/png'],
            'model'       => (string) ($_POST['model'] ?? ''),
            'duration_ms' => (int) ($_POST['duration_ms'] ?? 0),
            'source'      => 'upload',
        ]);

        return $this->_respond(['ok' => TRUE, 'url' => (string) ($stored['url'] ?? '')]);
    }

    public function action_runs()
    {
        $uid = (string) Visitor::uid();

        return $this->_respond([
            'ok'   => TRUE,
            'runs' => Service_Nj641_Imagegen::recent_runs($uid, 20),
        ]);
    }

    public function action_vram()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $vram = Service_Nj641_Imagegen::vram_settings();

        if ( ! $vram['check'])
        {
            return $this->_respond(['ok' => TRUE, 'check' => FALSE]);
        }

        // Адрес внешнего image-API сайт «подсматривает» сам (NJ641_IMAGE_API):
        // только для показа занятости бекенда; наружу секреты не уходят.
        $base = self::_backend_base();

        $proxy = ['check' => TRUE, 'wait' => $vram['wait'],
                  'min_free_mb' => $vram['min_free_mb'],
                  'wait_sec' => $vram['wait_sec'], 'poll_sec' => $vram['poll_sec']];

        if ($base === '')
        {
            return $this->_respond(['ok' => TRUE, 'check' => TRUE, 'backend' => NULL] + $proxy);
        }

        // OpenAPI сервера изображений: GET /vram (публичный, без тела).
        $info = self::_backend_json($base.'/vram', 'GET', NULL, 5);

        if ($info['ok'])
        {
            return $this->_respond(['ok' => TRUE, 'check' => TRUE, 'backend' => $info['data']] + $proxy);
        }

        return $this->_respond(['ok' => TRUE, 'check' => TRUE, 'backend' => NULL, 'backend_error' => $info['error']] + $proxy);
    }

    public function action_clear()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Токен воркера не совпал'], 403);
        }

        // Очистку кэша делает воркер (канал nj641.clear); здесь только ставим задачу.
        try
        {
            Service_Nj120_JobQueue::push(Service_Nj641_Imagegen::CHANNEL_CLEAR, [
                'run_id' => 0,
            ], ['max_attempts' => 1, 'module' => 'nj641', 'timeout_sec' => 300]);
        }
        catch (Exception $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Очередь недоступна.'], 502);
        }

        return $this->_respond(['ok' => TRUE, 'queued' => TRUE]);
    }

    public function action_worker_result()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Токен воркера не совпал'], 403);
        }

        $run_id = (int) ($this->_body['run_id'] ?? 0);

        if ($run_id <= 0)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id'], 422);
        }

        $res = (isset($this->_body['result']) AND is_array($this->_body['result']))
            ? $this->_body['result'] : $this->_body;

        // Путь доставки виден в image_info: при разборе «где потерялось» это
        // первое, что нужно знать.
        if (empty($res['source']))
        {
            $res['source'] = 'json';
        }

        Service_Nj641_Imagegen::apply_result($run_id, $res);

        return $this->_respond(['ok' => TRUE]);
    }

    // ------------------------------------------------------------ служебное

    protected function _token_ok()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return Kohana::$environment === Kohana::DEVELOPMENT;
        }

        return hash_equals($token, trim((string) $this->request->headers('x-worker-token')));
    }

    protected function _rate_ok()
    {
        return class_exists('Service_Nj641_Imagegen')
            ? Service_Nj641_Imagegen::rate_ok(Service_Nj641_Imagegen::client_ip())
            : TRUE;
    }

    protected function _rate_error()
    {
        return 'Слишком много генераций с этого адреса. Лимит: '
            .(class_exists('Service_Nj641_Imagegen') ? Service_Nj641_Imagegen::rate_limit_text() : '5 генераций в минуту')
            .' — попробуйте позже.';
    }

    /**
     * База внешнего image-API из .env → чистый URL.
     *
     * Админ пишет адрес по-разному: в кавычках, без схемы («127.0.0.1:8000»)
     * или сразу полным эндпоинтом. Локальному серверу нужен http, а знакомый
     * хвост эндпоинта отрезаем, чтобы /vram не превратился в /vram/vram.
     * Секрет наружу не уходит — этот URL используется только сервером.
     */
    protected static function _backend_base()
    {
        $base = trim((string) getenv('NJ641_IMAGE_API'), " \t\n\r\0\x0B\"'");

        if ($base === '')
        {
            return '';
        }

        $base = rtrim($base, '/');

        if ( ! preg_match('~^https?://~i', $base))
        {
            $host = strtolower((string) parse_url('http://'.$base, PHP_URL_HOST));
            $local = preg_match('~^(localhost|127\.|0\.0\.0\.0|\[::1\]|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)~', $host);
            $base = ($local ? 'http://' : 'https://').$base;
        }

        foreach (['/v1/images/generations', '/v1/images/edits', '/v1/images',
                  '/v1/chat/completions', '/v1/models', '/vram', '/clear', '/v1'] as $tail)
        {
            if ($tail !== '' AND substr($base, -strlen($tail)) === $tail)
            {
                $base = substr($base, 0, -strlen($tail));
                break;
            }
        }

        return rtrim($base, '/');
    }

    /**
     * JSON-запрос к внешнему image-API (нативный cURL). URL берётся только из
     * конфигурации админки NJ641_IMAGE_API (.env) — пользовательский ввод в
     * адрес не попадает (SSRF-вектора нет); тело — фиксированный JSON.
     */
    protected static function _backend_json($url, $method = 'GET', ?array $json = NULL, $timeout = 10)
    {
        if ( ! function_exists('curl_init'))
        {
            return ['ok' => FALSE, 'error' => 'cURL недоступен.'];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $timeout);

        // image-API закрыт HTTPBearer. На сайте токен обычно не задан (он нужен
        // воркеру), но если задан — показываем занятость бекенда под ним.
        $headers = [];
        $token = trim((string) (getenv('NJ641_API_TOKEN') ?: getenv('NJ641_IMAGE_TOKEN')));

        if ($token !== '')
        {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        if ($method === 'POST')
        {
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json === NULL ? '' : json_encode($json));
            $headers[] = 'Content-Type: application/json';
        }

        if ($headers)
        {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === FALSE)
        {
            return ['ok' => FALSE, 'error' => 'Сервер изображений не ответил: '.$err];
        }

        $decoded = json_decode($body, TRUE);

        if ( ! is_array($decoded))
        {
            return ['ok' => FALSE, 'error' => 'Нечитаемый ответ сервера изображений.'];
        }

        return ['ok' => TRUE, 'data' => $decoded];
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
