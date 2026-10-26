<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj609_watchdog: /watchdog/admin — обзор для администратора «Сторожа»: все контрагенты,
 * свежие события, счётчики и здоровье воркера (канал nj609.watchdog).
 * Действия — AJAX (JSON), без перезагрузки страницы.
 */
class Controller_Nj609_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj609.admin') AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Сторож контрагентов · управление');

        Service_Nj609_Watchdog::ensure_tables();
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
                $ok = Service_Nj609_Watchdog::admin_delete_subject((string) $this->request->post('code'));
                $flash = $ok ? 'Контрагент снят с наблюдения' : 'Контрагент не найден';
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

            Session::instance()->set('nj609_admin_flash', $flash);
            HTTP::redirect('/watchdog/admin', 303);
        }

        $ov = Service_Nj609_Watchdog::admin_overview();

        $this->template->content = View::factory('609/admin', [
            'subjects' => $ov['subjects'],
            'events'   => $ov['events'],
            'counts'   => $ov['counts'],
            'jobs'     => Service_Nj120_JobQueue::instance()->channel_jobs('nj609.watchdog'),
            'flash'    => (string) Session::instance()->get_once('nj609_admin_flash', ''),
            'csrf'     => Security::token(),
        ]);
    }
}
