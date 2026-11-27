<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API воркера prx399 (каналы doc.merge.delta / doc.merge.audit).
 *
 * Контракт (как у prx396/prx392):
 *   GET  /prx399/api/health                  — ok/module/статистика
 *   GET  /prx399/api/delta_input/<file_id>   — задание (или done=TRUE)
 *   POST /prx399/api/delta_result/<file_id>  — {pages, text_sha256, ast|ops, …}
 *   GET  /prx399/api/file/<file_id>          — PDF пакета (application/pdf, из Storage::)
 *   GET  /prx399/api/audit_input/<id>        — собранное дерево + контекст
 *   POST /prx399/api/audit_result/<id>       — {summary, risks[]}
 *
 * Авторизация — X-Worker-Token (PRX399_SITE_TOKEN → WORKER_SITE_TOKEN →
 * WORKER_API_TOKEN); сессии браузера здесь не участвуют. Токен проверяется
 * ДО любого действия; ошибки — JSON с кодом, без деталей окружения.
 */
class Controller_Prx399_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Cache-Control', 'no-store');

        // Токен — ДО любого действия (кроме health). Отказ подменяет действие,
        // а не делает exit: раньше exit обрывал запрос до отправки ответа, и
        // клиент без токена получал 200 с пустым телом — воркер с неверным
        // токеном принимал это за доставленный результат.
        if ($this->request->action() !== 'health' AND ! $this->_authorized())
        {
            $this->request->action('unauthorized');
        }
    }

    public function action_unauthorized()
    {
        $this->_json(['ok' => FALSE, 'error' => 'unauthorized'], 401);
    }

    public function action_index()
    {
        $this->_json(['ok' => FALSE, 'error' => 'unknown_action'], 404);
    }

    public function action_health()
    {
        $counts = Model_Prx399_Contract::counts_by_status();

        $this->_json([
            'ok' => TRUE,
            'module' => 'prx399',
            'contracts' => (int) ($counts['total'] ?? 0),
            'running' => (int) ($counts['running'] ?? 0),
        ]);
    }

    public function action_delta_input()
    {
        $pipeline = new Service_Prx399_Pipeline;
        $this->_run(function ($id) use ($pipeline)
        {
            return $pipeline->delta_input((int) $id);
        });
    }

    public function action_delta_result()
    {
        $pipeline = new Service_Prx399_Pipeline;
        $this->_run(function ($id, array $body) use ($pipeline)
        {
            return $pipeline->delta_result((int) $id, $body);
        }, TRUE);
    }

    public function action_audit_input()
    {
        $pipeline = new Service_Prx399_Pipeline;
        $this->_run(function ($id) use ($pipeline)
        {
            return $pipeline->audit_input((int) $id);
        });
    }

    public function action_audit_result()
    {
        $pipeline = new Service_Prx399_Pipeline;
        $this->_run(function ($id, array $body) use ($pipeline)
        {
            return $pipeline->audit_result((int) $id, $body);
        }, TRUE);
    }

    /** Отдача PDF пакета воркеру: stream + строгий content-type. */
    public function action_file()
    {
        $file = Model_Prx399_File::get((int) $this->request->param('id'));
        if ( ! $file)
        {
            $this->_json(['ok' => FALSE, 'error' => 'not_found'], 404);

            return;
        }
        $bytes = NULL;
        $path = '';
        if ((int) ($file['file_id'] ?? 0) > 0)
        {
            try
            {
                $limit = (int) Kohana::$config->load('prx399_docmerge')->get('max_pdf_bytes');
                $bytes = Storage::read_owned((int) $file['file_id'], Service_Prx399_Contracts::MODULE, $limit);
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::ERROR, 'prx399 file=:id stage=read error_type=:type', [':id' => (int) $file['id'], ':type' => get_class($e)]);
            }
        }
        elseif ((string) ($file['storage_path'] ?? '') !== '')
        {
            // Запись до перехода на Storage:: (миграция 399_004).
            $path = Storage_Path::absolute((string) $file['storage_path']);
        }
        if ($bytes === NULL AND ($path === '' OR ! is_file($path)))
        {
            $this->_json(['ok' => FALSE, 'error' => 'file_unavailable'], 404);

            return;
        }

        if (ob_get_level())
        {
            ob_end_clean();
        }
        $this->response->headers('Content-Type', 'application/pdf');
        $this->response->headers('Content-Disposition', 'attachment; filename="prx399-'.$file['id'].'.pdf"');
        $this->response->headers('X-Content-Type-Options', 'nosniff');
        $this->response->send_headers();
        if ($bytes !== NULL)
        {
            echo $bytes;
        }
        else
        {
            readfile($path);
        }
        exit;
    }

    // ------------------------------------------------------------ helpers

    private function _authorized(): bool
    {
        $token = getenv('PRX399_SITE_TOKEN') ?: (getenv('WORKER_SITE_TOKEN') ?: getenv('WORKER_API_TOKEN'));

        return $token !== FALSE && (string) $token !== '' && Prx_Gate::worker_request($this->request, (string) $token);
    }

    private function _run(callable $action, $with_body = FALSE)
    {
        try
        {
            $body = [];
            if ($with_body)
            {
                $raw = (string) $this->request->body();
                if (strlen($raw) > 32 * 1024 * 1024)
                {
                    throw new Prx399_Error('result_too_large', 413);
                }
                $body = json_decode($raw, TRUE);
                if ( ! is_array($body))
                {
                    throw new Prx399_Error('invalid_body', 400);
                }
            }
            $this->_json(['ok' => TRUE] + (array) $action((int) $this->request->param('id'), $body));
        }
        catch (Prx399_Error $e)
        {
            $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], $e->getCode());
        }
        catch (InvalidArgumentException $e)
        {
            $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 400);
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx399 api error_type=:type', [':type' => get_class($e)]);
            $this->_json(['ok' => FALSE, 'error' => 'service_unavailable'], 503);
        }
    }

    private function _json(array $data, $status = 200)
    {
        $this->response->status((int) $status)
            ->headers('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
