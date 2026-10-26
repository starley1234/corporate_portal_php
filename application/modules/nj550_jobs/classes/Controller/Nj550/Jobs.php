<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Работа 2 Оффер» (NJ-550): /jobs — форма, /jobs/<code> — статус и результат.
 *
 * Контроллер тонкий: CSRF, вызовы Service_Nj550_Jobs, редиректы.
 * Пользователь не требуется: идентификация — Visitor::uid() (ubid-кука).
 */
class Controller_Nj550_Jobs extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Поиск вакансий в городе по направлению — Работа 2 Оффер');
        $this->set_description(
            'Введите город и направление — ИИ найдёт актуальные вакансии на сайтах работы и карьерных страницах компаний, уберёт мусор и подытожит. Без регистрации.');

        // Меню — из каталога nj-модулей
    }
    /**
     * GET /jobs — форма (с предзаполнением из кук и недавних поисков);
     * POST /jobs — создать поиск и перейти на /jobs/<code>.
     */
    public function action_index()
    {
        if ($this->request->method() === Request::POST)
        {
            if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
            {
                throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
            }

            $result = Service_Nj550_Jobs::create(
                Arr::get($_POST, 'city', ''),
                Arr::get($_POST, 'direction', '')
            );

            if (isset($result['code']))
            {
                HTTP::redirect('/jobs/'.$result['code'].( ! empty($result['dedup']) ? '?cached=1' : '?created=1'));
            }

            $this->_form(Arr::get($_POST, 'city', ''), Arr::get($_POST, 'direction', ''), $result['error']);
            return;
        }

        $this->_form();
    }

    /**
     * GET /jobs/<code> — «ищем…» или результат.
     */
    public function action_view()
    {
        $row = Service_Nj550_Jobs::by_code($this->request->param('code'));

        if ($row === NULL)
        {
            throw HTTP_Exception::factory(404, 'Поиск не найден');
        }

        // воркер http-режима мог оставить результат в очереди — дозапишем
        $row = Service_Nj550_Jobs::reconcile($row);

        $this->template->content = View::factory('550/result', [
            'row'      => $row,
            'results'  => $row['status'] === 'done' ? Service_Nj550_Jobs::results((int) $row['id']) : [],
            'meta'     => $row['meta'] ? json_decode((string) $row['meta'], TRUE) : NULL,
            'just_created' => ! empty($_GET['created']),
            'csrf'     => Security::token(),
        ]);
    }

    protected function _form($city_val = '', $direction_val = '', $error = NULL)
    {
        // предзаполнение: значения после ошибки → иначе куки предпочтений
        $prefs = Service_Nj550_Jobs::prefs();

        if ($city_val === '')
        {
            $city_val = $prefs['city'];
        }

        if ($direction_val === '')
        {
            $direction_val = $prefs['direction'];
        }

        $this->template->content = View::factory('550/form', [
            'city_val'      => $city_val,
            'direction_val' => $direction_val,
            'cities'        => (array) Kohana::$config->load('nj550_jobs.cities'),
            'recent'        => Service_Nj550_Jobs::recent(),
            'error'         => $error,
        ]);
    }
}
