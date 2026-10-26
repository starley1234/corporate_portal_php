<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj613 admin: /nj613/admin — подписки и последние сводки.
 */
class Controller_Nj613_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj613.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Дайджест · управление');
    }

    public function action_index()
    {
        $ov = Service_Nj613_Digest::admin_overview();

        $this->template->content = View::factory('613/admin', [
            'digests' => $ov['digests'],
            'subs'    => $ov['subs'] ? (array) $ov['subs'] : ['on_cnt' => 0, 'all_cnt' => 0],
        ]);
    }

}
