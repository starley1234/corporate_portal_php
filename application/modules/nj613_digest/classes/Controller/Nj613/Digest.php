<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj613: /digest — «Дайджест руководителю»: подписка и мои сводки.
 * Личный инструмент владельца (как /inbox).
 */
class Controller_Nj613_Digest extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для владельца');
        }

        $this->set_title('Дайджест руководителю — неделя вашего бизнеса в пяти предложениях');

        $this->set_module_nav([
            ['title' => 'Управление', 'href' => '/nj613/admin', 'icon' => 'fas fa-lock', 'acl' => 'nj613.admin'],
        ]);

        $this->_user_id = (int) $this->_current_user->id;
    }

    public function action_index()
    {
        $flash = '';

        if ($this->request->method() === Request::POST AND Security::check((string) $this->request->post('csrf')))
        {
            Service_Nj613_Digest::save_sub($this->_user_id,
                (int) $this->request->post('weekday'),
                (string) $this->request->post('enabled') === '1');
            $flash = 'Настройки сохранены — следующий дайджест придёт в выбранный день';
        }

        // Живой статус-ряд (эталон /trends): когда недельный дайджест собирается,
        // это видно; после — в списке остаётся плашка итога.
        $live = Service_Nj613_Digest::live_for($this->_user_id);
        $last = NULL;

        if ($live AND ! in_array((string) $live['status'], ['queued', 'running'], TRUE))
        {
            $last = Service_Nj613_Digest::last_for($this->_user_id);
            $live = NULL;
        }

        $this->template->content = View::factory('613/index', [
            'sub'     => Service_Nj613_Digest::sub($this->_user_id),
            'digests' => Service_Nj613_Digest::mine($this->_user_id),
            'live'    => $live,
            'last'    => $last,
            'flash'   => $flash,
            'csrf'    => Security::token(),
            'has_email' => trim((string) $this->_current_user->email) !== '',
        ]);
    }

}
