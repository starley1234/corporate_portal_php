<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Кодировщик» (модуль NJ-616): /convert — Base64 и URL encode/decode.
 */
class Controller_Nj616_Convert extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Base64 и URL кодировщик онлайн — кодировать и декодировать');
        $this->set_description(
            'Бесплатно кодировать и раскодировать текст: Base64 (UTF-8) и URL-кодирование. Живое преобразование в браузере, JSON API — для интеграций и ИИ-агентов.');
        $this->set_keywords('base64 кодировать декодировать url encode decode онлайн конвертер');

    }

    public function action_index()
    {
        $query  = $this->request->query();
        $text   = isset($query['q']) ? $query['q'] : '';
        $mode   = isset($query['m']) ? $query['m'] : 'b64_encode';
        $result = $text !== '' ? Service_Nj616_Convert::convert($text, $mode) : NULL;

        $this->template->content = View::factory('616/index', [
            'text'   => (string) $text,
            'mode'   => (string) $mode,
            'modes'  => Service_Nj616_Convert::modes(),
            'result' => $result,
        ]);
    }
}
