<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx395_Ui extends Controller_Base
{
    public function before()
    {
        parent::before();
        if ( ! $this->can('prx395.ui')) throw HTTP_Exception::factory(403, 'Распознавание PDF доступно администратору');
        $this->response->headers('Cache-Control', 'no-store');
        $this->set_title('Распознавание PDF');
    }

    public function action_index()
    {
        $id = (int) $this->request->param('id');
        $method = $this->request->method();
        if ($method !== Request::GET && ($id || $method !== Request::POST))
        {
            throw (HTTP_Exception::factory(405))->allowed($id ? 'GET' : 'GET, POST');
        }
        // Владелец заданий админки — отрицательный ID пользователя: положительные
        // заняты ключами prx150, поэтому чужое задание не видно даже по ID.
        $client = -(int) $this->_current_user->id;
        $job = NULL;
        $error = NULL;
        try {
            if ($method === Request::POST)
            {
                $csrf = $this->request->post('csrf');
                if ( ! is_string($csrf) || ! Security::check($csrf)) throw HTTP_Exception::factory(403);
                if ((int) $this->request->headers('Content-Length') > Service_Prx395_Extraction::MAX_PDF + 65536)
                    throw new Prx395_Error('file_too_large', 413);
                $service = new Service_Prx395_Extraction;
                $key = bin2hex(random_bytes(16));
                $job_id = $this->request->post('sample') === '1'
                    ? $service->submit_sample($client, $key)
                    : $service->submit($client, $key, $_FILES['file'] ?? NULL);
                HTTP::redirect('/prx395_document_extract/'.$job_id, 303);
            }
            if ($id)
            {
                $job = (new Service_Prx395_Extraction)->status($client, $id);
            }
        } catch (HTTP_Exception $e) {
            throw $e;
        } catch (Prx395_Error $e) {
            $error = $e->getMessage();
            $this->response->status($e->getCode());
        } catch (Throwable $e) {
            Log_Plain::event(Log::ERROR, 'prx395 ui job_id=:id error_type=:type', [':id' => $id, ':type' => get_class($e)]);
            $this->response->status(503);
            $error = 'service_unavailable';
        }
        if (in_array($job['status'] ?? '', ['queued', 'running'], TRUE))
            $this->add_script('/assets/js/prx395-status.js', TRUE);
        $this->template->content = View::factory('prx395/index', [
            'id' => $id, 'job' => $job, 'error' => $error,
            'csrf' => Security::token(), 'can_monitor' => $this->can('prx120.monitor'),
        ]);
    }
}
