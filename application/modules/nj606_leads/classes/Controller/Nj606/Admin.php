<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj606_leads: /leads/admin — обзор для администратора «Потока заказчиков»: все потоки,
 * свежие лиды, счётчики и здоровье воркера (последние задачи канала
 * nj606.leads). Действия — AJAX (JSON), без перезагрузки страницы.
 */
class Controller_Nj606_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj606.admin') AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Поток заказчиков · управление');

        Service_Nj606_Leads::ensure_tables();
    }

    public function action_index()
    {
        $flash = '';

        if ($this->request->method() === Request::POST)
        {
            $flash = 'Сессия устарела — попробуйте ещё раз';

            if (Security::check((string) $this->request->post('csrf'))
                AND (string) $this->request->post('act') === 'delete')
            {
                $ok = Service_Nj606_Leads::admin_delete_flow((string) $this->request->post('code'));
                $flash = $ok ? 'Поток удалён вместе с лидами' : 'Поток не найден';
            }

            // AJAX: JSON вместо перезагрузки
            if ($this->request->post('ajax') === '1'
                OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest')
            {
                $this->auto_render = FALSE;
                $this->response->headers('Content-Type', 'application/json; charset=utf-8')
                    ->body(json_encode(['ok' => ! str_starts_with($flash, 'Сессия'), 'flash' => $flash],
                        JSON_UNESCAPED_UNICODE));

                return;
            }

            Session::instance()->set('nj606_admin_flash', $flash);
            HTTP::redirect('/leads/admin', 303);
        }

        $ov = Service_Nj606_Leads::admin_overview();

        $this->template->content = View::factory('606/admin', [
            'flows'  => $ov['flows'],
            'leads'  => $ov['leads'],
            'counts' => $ov['counts'],
            'jobs'   => Service_Nj120_JobQueue::instance()->channel_jobs('nj606.leads'),
            'flash'  => (string) Session::instance()->get_once('nj606_admin_flash', ''),
            'csrf'   => Security::token(),
        ]);
    }
}
