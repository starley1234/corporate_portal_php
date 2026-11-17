<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API prx394 для воркера `doc.draft`.
 *
 *   GET  /prx394/api/input/<draft_id>   — задание для модели
 *   POST /prx394/api/result/<draft_id>  — сгенерированный проект ответа
 *
 * Авторизация и разбор JSON-тела — в Controller_Prx39x_Api.
 */
class Controller_Prx394_Api extends Controller_Prx39x_Api
{
    protected $public_actions = ['health', 'input', 'result'];

    protected function _token_prefix()
    {
        return 'PRX394';
    }

    public function action_health()
    {
        if ($this->_halt) return;

        return $this->_json(['ok' => TRUE, 'module' => 'prx394', 'stats' => Service_Prx394_Drafts::stats()]);
    }

    public function action_input()
    {
        if ($this->_halt) return;

        try
        {
            return $this->_json(['ok' => TRUE] + Service_Prx394_Drafts::input_for_worker((int) $this->request->param('id')));
        }
        catch (Exception $e)
        {
            return $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 400);
        }
    }

    public function action_result()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
        {
            return $this->_json(['ok' => FALSE, 'error' => 'Только POST'], 405);
        }

        try
        {
            $draft = Service_Prx394_Drafts::store_result(
                (int) $this->request->param('id'),
                $this->_json_body()
            );

            return $this->_json(['ok' => TRUE, 'draft_id' => $draft['id'], 'status' => $draft['status']]);
        }
        catch (Exception $e)
        {
            return $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 422);
        }
    }
}
