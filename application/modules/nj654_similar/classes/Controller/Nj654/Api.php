<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Служебный JSON API «Похожих картинок» (NJ-654).
 *
 *   GET  /similar/api/status?q=<id>     готов ли поиск (страница ждёт его)
 *   GET  /similar/api/status?items=1    сколько элементов банка ещё считается
 *   POST /similar/api/photo_file        картинка банка для воркера
 *   POST /similar/api/worker_result     приёмка векторов
 *
 * Два последних адреса — только по `X-Worker-Token` (Vision::worker_token_ok).
 * UI-методов здесь нет: страницы работают обычными формами.
 */
class Controller_Nj654_Api extends Controller
{
    public $auto_render = FALSE;

    /** @var array тело запроса (JSON или форма) */
    protected $_in = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw     = (string) $this->request->body();
        $decoded = $raw !== '' ? json_decode($raw, TRUE) : NULL;

        $this->_in = is_array($decoded) ? $decoded
            : ($this->request->post() ?: (array) $this->request->query());

        // Замок: сервис работает только по бесплатному аккаунту (владелец,
        // 08.10.2026). Воркерские точки (кадр банка и приёмка векторов) ходят
        // по X-Worker-Token и проверяются сами — их пропускаем.
        if ( ! Gate::logged_in() AND ! Gate::worker_action($this->request->action()))
        {
            throw HTTP_Exception::factory(401, 'Похожие картинки работают только по бесплатному аккаунту');
        }
    }

    /** Состояние: ждёт ли страница чего-нибудь. */
    public function action_status()
    {
        $id = (int) ($this->_in['q'] ?? 0);

        if ($id > 0)
        {
            $query = Service_Nj654_Bank::query_owned($id, $this->_visitor(), $this->_user_id());

            if ( ! $query)
            {
                return $this->_out(['ok' => FALSE, 'error' => 'поиск не найден'], 404);
            }

            $stored = (string) $query['status'];
            $status = [
                'queued' => 'queued', 'running' => 'running',
                'ready' => 'done', 'error' => 'failed',
            ][$stored] ?? 'failed';
            $started = strtotime((string) ($query['created_at'] ?? 'now')) ?: time();
            $elapsed = (int) ($query['elapsed_ms'] ?? 0) > 0
                ? (int) floor((int) $query['elapsed_ms'] / 1000)
                : max(0, time() - $started);

            $hits = (array) $query['hits'];

            return $this->_out([
                'ok'           => TRUE,
                'status'       => $status,
                'query_status' => $stored,
                'stage'        => $stored === 'queued'
                    ? 'Запрос ждёт свободного обработчика…'
                    : ($stored === 'running' ? 'Сравниваем смысл запроса с картинками банка…' : ''),
                'stages'       => [],
                'elapsed_sec'  => $elapsed,
                'silent_sec'   => NULL,
                // Число находок — и для счётчика «найдено: N», и для старых
                // читателей поля `hits`; сами карточки нужны движку, который
                // достраивает плитки без перезагрузки страницы.
                'found'        => count($hits),
                'hits'         => count($hits),
                'cards'        => $stored === 'ready' ? Service_Nj654_Bank::cards($hits) : [],
                'error'        => (string) $query['error'],
            ]);
        }

        return $this->_out([
            'ok'      => TRUE,
            'waiting' => Service_Nj654_Bank::items_count(['status' => 'queued']),
            'total'   => Service_Nj654_Bank::items_count(),
        ]);
    }

    /** Картинка элемента или запроса для воркера: base64. */
    public function action_photo_file()
    {
        if ( ! Vision::worker_token_ok())
        {
            return $this->_out(['ok' => FALSE, 'error' => 'нужен рабочий токен'], 403);
        }

        $kind = (string) ($this->_in['kind'] ?? 'item');
        $id   = (int) ($this->_in['id'] ?? 0);
        $row  = $kind === 'query' ? Service_Nj654_Bank::query($id) : Service_Nj654_Bank::item($id);

        if ( ! $row OR (string) $row['file'] === '')
        {
            return $this->_out(['ok' => FALSE, 'error' => 'картинки нет'], 404);
        }

        list($b64, $mime) = Service_Nj654_Bank::file_b64((string) $row['file']);

        if ($b64 === '')
        {
            return $this->_out(['ok' => FALSE, 'error' => 'файл потерян'], 404);
        }

        return $this->_out([
            'ok'         => TRUE,
            'image_b64'  => $b64,
            'media_type' => $mime,
            'kb'         => (int) round(strlen($b64) * 3 / 4 / 1024),
        ]);
    }

    /** Приёмка результата воркера. */
    public function action_worker_result()
    {
        if ( ! Vision::worker_token_ok())
        {
            return $this->_out(['ok' => FALSE, 'error' => 'нужен рабочий токен'], 403);
        }

        try
        {
            $done = Service_Nj654_Bank::accept($this->_in);
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'nj654: приёмка не удалась: :e', [':e' => $e->getMessage()]);

            return $this->_out(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }

        return $this->_out(['ok' => (bool) $done['ok'], 'note' => (string) $done['note']]);
    }

    protected function _visitor()
    {
        return class_exists('Visitor') ? (string) Visitor::uid() : '';
    }

    protected function _user_id()
    {
        return class_exists('Visitor') ? Visitor::user_id() : NULL;
    }

    protected function _out(array $data, $status = 200)
    {
        $this->response->status($status)
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $this->response;
    }
}
