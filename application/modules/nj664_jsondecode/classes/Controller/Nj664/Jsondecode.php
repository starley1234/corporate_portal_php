<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Декодер JSON» (модуль NJ-664): /json-decode
 *
 * Работает и без JavaScript: форма уходит POST, ответ рендерится сервером.
 * С JavaScript разбор живой — на каждое нажатие, без перезагрузки.
 * Ничего не сохраняется: ни исходный JSON, ни результат.
 */
class Controller_Nj664_Jsondecode extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Декодер JSON онлайн: раскодировать экранированный JSON');
        $this->set_description(
            'Раскодируйте JSON онлайн: экранированный ({\"a\":1}), из URL (%7B…), из HTML-сущностей или из строки в кавычках. Красивый вывод, сжатие в одну строку, вид глазами PHP (print_r) и готовый код json_decode(). Без регистрации, данные не сохраняются.');
        $this->set_keywords(
            'декодер json онлайн раскодировать json json decode экранированный json url-encoded json онлайн разбор json pretty print php json_decode');
    }

    public function action_index()
    {
        $post = (array) $this->request->post();
        $sent = ($this->request->method() === Request::POST);

        // Ссылка с данными (/?input=…): JSON короткий, поэтому он же может
        // приехать и в адресе. Заполняем поле и разбираем сразу — так ссылкой
        // можно поделиться готовым разбором.
        $input = (string) Arr::get($post, 'input', (string) $this->request->query('input'));
        $mode  = (string) Arr::get($post, 'mode', (string) $this->request->query('mode'));

        if ( ! $sent AND $input !== '')
        {
            $sent = ($this->request->method() === Request::GET);
        }

        if ( ! in_array($mode, ['json', 'min', 'array', 'php'], TRUE))
        {
            $mode = 'json';
        }

        $result = ($sent AND trim($input) !== '')
            ? Service_Nj664_Decode::decode($input)
            : NULL;

        $this->template->content = View::factory('664/index', [
            'input'  => $input,
            'mode'   => $mode,
            'result' => $result,
            'sent'   => $sent,
            'csrf'   => Security::token(),
        ]);
    }
}
