<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx396_Ui extends Controller_Base
{
    public function before()
    {
        parent::before();
        if ( ! $this->can('prx396.ui')) throw HTTP_Exception::factory(403, 'Большие PDF по частям доступны администратору');
        $this->response->headers('Cache-Control', 'no-store');
        $this->set_title('Большие PDF по частям');
    }

    public function action_index()
    {
        if ($this->request->param('op') === 'download')
        {
            $this->download((int) $this->request->param('id'));
            return;
        }

        $id = (int) $this->request->param('id');
        $method = $this->request->method();
        if ($method !== Request::GET && ($id || $method !== Request::POST))
        {
            throw (HTTP_Exception::factory(405))->allowed($id ? 'GET' : 'GET, POST');
        }
        $actor = $this->actor();
        $batch = NULL;
        $error = NULL;
        try {
            if ($method === Request::POST)
            {
                Service_Prx396_Limits::assert_request_size((int) $this->request->headers('Content-Length'));
                $service = new Service_Prx396_Extraction;
                $key = bin2hex(random_bytes(16));
                $batch_id = $service->submit($actor, $key, $_FILES['file'] ?? NULL);
                HTTP::redirect('/prx396_pdf_chunks/'.$batch_id, 303);
            }
            if ($id)
            {
                $batch = (new Service_Prx396_Extraction)->status($actor, $id);
            }
        } catch (HTTP_Exception $e) {
            throw $e;
        } catch (Prx396_Error $e) {
            $error = $e->getMessage();
            $this->response->status($e->getCode());
        } catch (Throwable $e) {
            Log_Plain::event(Log::ERROR, 'prx396 ui batch_id=:id error_type=:type', [':id' => $id, ':type' => get_class($e)]);
            $this->response->status(503);
            $error = 'service_unavailable';
        }

        $progress = ($batch && ! empty($batch['job_id']))
            ? Service_Prx120_JobQueue::instance()->status_of((int) $batch['job_id']) : [];
        $this->add_script('/assets/js/prx396-status.js?v=1', TRUE);
        $this->template->content = View::factory('prx396/index', [
            'id' => $id, 'batch' => $batch, 'error' => $error, 'progress' => $progress,
            'limits' => Service_Prx396_Limits::all(),
            'rate_limit' => (int) Kohana::$config->load('prx150_api_hub.default_rate_limit'),
            'csrf' => Security::token(), 'can_monitor' => $this->can('prx120.monitor'),
        ]);
    }

    private function download(int $id): void
    {
        $this->auto_render = FALSE;
        $actor = $this->actor();
        try {
            $service = new Service_Prx396_Extraction;
            $result = $service->result_parts($actor, $id);
            if (ob_get_level()) ob_end_clean();
            $filename = 'pdf-chunks-'.$id.'.txt';
            $this->response->headers('Content-Type', 'text/plain; charset=utf-8');
            $this->response->headers('Content-Disposition', 'attachment; filename="'.$filename.'"');
            $this->response->headers('Cache-Control', 'no-store');
            $this->response->headers('X-Content-Type-Options', 'nosniff');
            $this->response->send_headers();
            foreach ($result['parts'] as $part)
            {
                $text = Storage::read_owned((int) $part['file_id'], Service_Prx396_Extraction::MODULE,
                    Service_Prx396_Limits::get('max_text_bytes'));
                if ($text === NULL) break;
                echo $text;
                if (ob_get_level()) ob_flush();
                flush();
            }
            exit;
        } catch (Prx396_Error $e) {
            $status = $e->getCode();
            if ($status === 404) throw HTTP_Exception::factory(404);
            $this->response->status($status);
            $this->response->headers('Content-Type', 'text/plain; charset=utf-8');
            $this->response->body($e->getMessage());
        } catch (Throwable $e) {
            Log_Plain::event(Log::ERROR, 'prx396 ui_download batch_id=:id error_type=:type', [':id' => $id, ':type' => get_class($e)]);
            throw HTTP_Exception::factory(503);
        }
    }
}
