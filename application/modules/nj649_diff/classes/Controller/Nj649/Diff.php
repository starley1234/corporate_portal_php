<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Сравнение текстов» (модуль NJ-649): /diff
 *
 * Тексты приходят формой POST (они длинные, в адрес не помещаются) и сразу же
 * возвращаются в поля — сравнение целиком серверное, страница работает без
 * JavaScript. Ничего не сохраняется: ни текстов, ни результатов.
 */
class Controller_Nj649_Diff extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Сравнение текстов онлайн: найти отличия двух версий');
        $this->set_description(
            'Сравните две версии текста или документа построчно: отличия подсвечены по словам, есть сводка изменений и готовый unified diff. Без регистрации, тексты не сохраняются.');
        $this->set_keywords(
            'сравнение текстов онлайн найти отличия двух текстов diff сравнить документы построчно');
    }

    public function action_index()
    {
        $post = (array) $this->request->post();
        $sent = ($this->request->method() === Request::POST);

        $left  = (string) Arr::get($post, 'left', '');
        $right = (string) Arr::get($post, 'right', '');

        // Галочки: при первом заходе включены «игнорировать пробелы» и подсветка слов
        $opts = $sent
            ? [
                'case'  => ! empty($post['case']),
                'space' => ! empty($post['space']),
                'empty' => ! empty($post['empty']),
                'words' => ! empty($post['words']),
            ]
            : ['case' => FALSE, 'space' => TRUE, 'empty' => FALSE, 'words' => TRUE];

        $result = ($sent AND ($left !== '' OR $right !== ''))
            ? Service_Nj649_Diff::compare($left, $right, $opts)
            : NULL;

        $this->template->content = View::factory('649/index', [
            // Показ одних изменений — дело вёрстки, в сравнение не входит
            'only'    => ($sent AND ! empty($post['only'])),
            'left'    => $left,
            'right'   => $right,
            'opts'    => $opts,
            'choices' => Service_Nj649_Diff::options(),
            'result'  => $result,
            'csrf'    => Security::token(),
        ]);
    }
}
