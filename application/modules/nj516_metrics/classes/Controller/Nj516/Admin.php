<?php defined('SYSPATH') or die('No direct script access.');

/** Закрытая админ-панель метрик витрины (/nj516/admin). */
class Controller_Nj516_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj516.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Метрики витрины · просмотры сервисов');
    }

    public function action_index()
    {
        $days = (int) Arr::get($_GET, 'days', 7);
        if ( ! in_array($days, [7, 30, 90], TRUE)) $days = 7;

        $this->template->content = View::factory('516/admin', [
            'stats' => Service_Nj516_Metrics::admin_stats($days),
            'days'  => $days,
        ]);
    }
}
