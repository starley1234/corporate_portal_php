<?php defined('SYSPATH') or die('No direct script access.');

/**
 * HTTP-пинок «Пиналки»: GET/POST /nj603/kick/<token>.
 * Токен — NJ603_KICK_TOKEN из .env сайта (для cron без CLI:
 * curl -fsS "https://n2j.ru/nj603/kick/<token>").
 * Запускает ОДИН проход по созревшим расписаниям. Ошибки — json 400 с причиной.
 */
class Controller_Nj603_Kick extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;
    }

    public function action_index()
    {
        $token = trim((string) getenv('NJ603_KICK_TOKEN'));
        $given = (string) $this->request->param('token');

        if ($token === '' OR $given === '' OR ! hash_equals($token, $given))
        {
            $this->_json(401, ['error' => 'Токен пиналки не совпал (NJ603_KICK_TOKEN)']);

            return;
        }

        try
        {
            $res = Service_Nj603_Kicker::run();

            $this->_json(200, [
                'ok'     => TRUE,
                'pushed' => (int) $res['pushed'],
                'log'    => (array) $res['log'],
            ]);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj603: kick: :e', [':e' => $e->getMessage()]);
            $this->_json(400, ['error' => 'Пинок не прошёл: '.$e->getMessage()
                .' @ '.basename($e->getFile()).':'.$e->getLine()]);
        }
    }

    protected function _json($status, array $body)
    {
        $this->response->status($status);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode($body, JSON_UNESCAPED_UNICODE));
    }
}
