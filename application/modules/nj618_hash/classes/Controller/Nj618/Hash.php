<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Хеш-инструмент» (модуль NJ-618): /hash
 */
class Controller_Nj618_Hash extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Хеширование MD5, SHA-1, SHA-256 онлайн');
        $this->set_description(
            'Посчитать MD5, SHA-1, SHA-256/384/512 для строки или файла онлайн. Локально в браузере + JSON API для интеграций и ИИ-агентов.');
        $this->set_keywords('md5 sha1 sha256 sha384 sha512 хеш hash онлайн файл контрольная сумма');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $text  = isset($query['q']) ? $query['q'] : '';
        $algo  = isset($query['alg']) ? $query['alg'] : 'sha256';
        $upper = ! empty($query['upper']);

        $result = $text !== '' ? Service_Nj618_Hash::hash_text($text, $algo, $upper) : NULL;

        $this->template->content = View::factory('618/index', [
            'text'   => (string) $text,
            'algo'   => (string) $algo,
            'upper'  => $upper,
            'algos'  => Service_Nj618_Hash::algos(),
            'result' => $result,
        ]);
    }
}
