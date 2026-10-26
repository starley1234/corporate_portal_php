<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj604 админка /nj604/admin: добавить новость вручную, скрыть/показать/удалить.
 */
class Controller_Nj604_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj604.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Новости сайта · управление');

        Service_Nj604_News::ensure_tables();
    }

    public function action_index()
    {
        $flash = '';

        if ($this->request->method() === Request::POST)
        {
            $act = (string) $this->request->post('act');
            $id  = (int) $this->request->post('id');

            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            elseif ($act === 'add')
            {
                $res = Service_Nj604_News::add_manual(
                    (string) $this->request->post('title'),
                    (string) $this->request->post('body'),
                    (string) $this->request->post('href'),
                    (string) $this->request->post('tag'));
                $flash = isset($res['error']) ? $res['error'] : 'Новость опубликована';
            }
            elseif ($act === 'sync')
            {
                $r = Service_Nj604_News::sync_from_git();
                $flash = $r === 1 ? 'Релиз-новость добавлена из git-лога'
                    : ($r === 0 ? 'Новых коммитов нет' : 'git недоступен из-под php-fpm (exec)');
            }
            elseif ($act === 'hide' AND $id > 0)
            {
                Service_Nj604_News::set_published($id, 0);
                $flash = 'Новость скрыта';
            }
            elseif ($act === 'show' AND $id > 0)
            {
                Service_Nj604_News::set_published($id, 1);
                $flash = 'Новость снова видна';
            }
            elseif ($act === 'delete' AND $id > 0)
            {
                Service_Nj604_News::remove($id);
                $flash = 'Новость удалена';
            }
            else
            {
                $flash = 'Неизвестное действие';
            }

            HTTP::redirect('/nj604/admin?flash='.urlencode($flash), 303);
        }

        $this->template->content = View::factory('604/admin', [
            'items' => Service_Nj604_News::admin_list(),
            'head'  => Service_Nj604_News::git_head(),
            'flash' => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
            'csrf'  => Security::token(),
        ]);
    }
}
