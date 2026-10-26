<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «JWT-декодер» (модуль NJ-620): /jwt
 */
class Controller_Nj620_Jwt extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('JWT декодер онлайн — разобрать и расшифровать токен');
        $this->set_description(
            'Расшифровать JWT-токен: заголовок, payload и claims, срок действия exp/iat/nbf. Всё локально в браузере + JSON API для интеграций и ИИ-агентов.');
        $this->set_keywords('jwt декодер расшифровать токен payload header claims exp iat онлайн');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $token = isset($query['t']) ? $query['t'] : '';

        $result = $token !== '' ? Service_Nj620_Jwt::decode($token) : NULL;

        $this->template->content = View::factory('620/index', [
            'token'  => (string) $token,
            'result' => $result,
        ]);
    }
}
