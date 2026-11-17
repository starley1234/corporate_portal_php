<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API prx392 для воркера `doc.analyze`.
 *
 *   GET  /prx392/api/input/<run_id>   — задание для модели
 *   POST /prx392/api/result/<run_id>  — {result: {...}, tokens, duration_ms}
 *
 * Авторизация и разбор JSON-тела — в Controller_Prx39x_Api.
 */
class Controller_Prx392_Api extends Controller_Prx39x_Api
{
    protected $public_actions = ['health', 'input', 'result'];

    protected function _token_prefix()
    {
        return 'PRX392';
    }

    public function action_health()
    {
        if ($this->_halt) return;

        return $this->_json(['ok' => TRUE, 'module' => 'prx392', 'stats' => Service_Prx392_Analysis::stats()]);
    }

    public function action_input()
    {
        if ($this->_halt) return;

        try
        {
            $input = Service_Prx392_Analysis::input_for_worker((int) $this->request->param('id'));

            return $this->_json(['ok' => TRUE] + $input);
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
            $suggestion = Service_Prx392_Analysis::store_result(
                (int) $this->request->param('id'),
                $this->_json_body()
            );

            return $this->_json(['ok' => TRUE, 'suggestion_id' => $suggestion['id'] ?? NULL]);
        }
        catch (Exception $e)
        {
            return $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 422);
        }
    }
}
