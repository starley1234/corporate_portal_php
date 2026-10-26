<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Отзыв 2 Ответ» (NJ-540): /review — форма и создание, /review/<code> — статус и результат.
 *
 * Контроллер тонкий: CSRF, вызовы Service_Nj540_Reply, редиректы.
 */
class Controller_Nj540_Review extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Ответ на отзыв клиента с помощью ИИ — Review 2 Reply');
        $this->set_description(
            'Вставьте отзыв клиента — получите готовый публичный ответ: анализ тональности, нужный тон, готовый текст за полминуты. Без регистрации.');

        // Меню — из каталога nj-модулей
    }

    /**
     * GET /review — форма; POST /review — создать и перейти на /review/<code>.
     */
    public function action_index()
    {
        // Замок: сервис работает только по бесплатному аккаунту (владелец,
        // 08.10.2026) — ответ пишет LLM, и очередь не должна доставаться
        // анонимам. Страница с формой остаётся, но гость видит на ней плашку
        // входа; страница результата по коду (ею делятся) работает как раньше.
        if ( ! Gate::logged_in())
        {
            if ($this->request->method() === Request::POST)
            {
                $this->redirect('auth/login?return_url=review');

                return;
            }

            $this->_form();

            return;
        }

        if ($this->request->method() === Request::POST)
        {
            if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
            {
                throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
            }

            $result = Service_Nj540_Reply::create(
                Arr::get($_POST, 'review', ''),
                Arr::get($_POST, 'shop', ''),
                Arr::get($_POST, 'tone', 'friendly'),
                Service_Nj540_Reply::client_ip()
            );

            if (isset($result['code']))
            {
                HTTP::redirect('/review/'.$result['code'].'?created=1');
            }

            // ошибка — показываем форму с сообщением и введёнными данными
            $this->_form(Arr::get($_POST, 'review', ''), Arr::get($_POST, 'shop', ''),
                Arr::get($_POST, 'tone', 'friendly'), $result['error']);
            return;
        }

        $this->_form();
    }

    /**
     * GET /review/<code> — «готовим ответ…» или результат.
     */
    public function action_view()
    {
        $row = Service_Nj540_Reply::by_code($this->request->param('code'));

        if ($row === NULL)
        {
            throw HTTP_Exception::factory(404, 'Отзыв не найден');
        }

        // воркер http-режима мог оставить результат в очереди — дозапишем
        $row = Service_Nj540_Reply::reconcile($row);

        $this->template->content = View::factory('540/result', [
            'row'        => $row,
            'analysis'   => $row['analysis'] ? json_decode((string) $row['analysis'], TRUE) : NULL,
            'meta'       => $row['meta'] ? json_decode((string) $row['meta'], TRUE) : NULL,
            'tones'      => Service_Nj540_Reply::tones(),
            'just_created' => ! empty($_GET['created']),
            'regen_error' => ! empty($_GET['regen_error']) ? (string) $_GET['regen_error'] : NULL,
            'csrf'       => Security::token(),
        ]);
    }

    protected function _form($review_val = '', $shop_val = '', $tone_val = 'friendly', $error = NULL)
    {
        $this->template->content = View::factory('540/form', [
            'logged'     => Gate::logged_in(),
            'review_val' => $review_val,
            'shop_val'   => $shop_val,
            'tone_val'   => array_key_exists($tone_val, Service_Nj540_Reply::tones()) ? $tone_val : 'friendly',
            'tones'      => Service_Nj540_Reply::tones(),
            'min_len'    => Service_Nj540_Reply::limits()['min'],
            'max_len'    => Service_Nj540_Reply::limits()['max'],
            'error'      => $error,
        ]);
    }
}
