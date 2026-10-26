<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj605_radar: /radar/admin — обзор для администратора «Радара заказов»: все радары,
 * свежие находки, счётчики и здоровье воркера (канал nj605.radar).
 * Действия — AJAX (JSON), без перезагрузки страницы.
 */
class Controller_Nj605_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj605.admin') AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Радар заказов · управление');

        Service_Nj605_Radar::ensure_tables();
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
                $ok = Service_Nj605_Radar::admin_delete_watch((string) $this->request->post('code'));
                $flash = $ok ? 'Радар удалён вместе с находками' : 'Радар не найден';
            }
            elseif (Security::check((string) $this->request->post('csrf'))
                AND (string) $this->request->post('act') === 'toggle')
            {
                $enabled = (string) $this->request->post('enabled') !== '0';
                $res = Service_Nj605_Radar::set_enabled((string) $this->request->post('code'),
                    NULL, $enabled, TRUE);

                $flash = isset($res['error']) ? $res['error']
                    : ($enabled ? 'Радар включен' : 'Радар на паузе');
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

            Session::instance()->set('nj605_admin_flash', $flash);
            HTTP::redirect('/radar/admin', 303);
        }

        $ov = Service_Nj605_Radar::admin_overview();

        $this->template->content = View::factory('605/admin', [
            'watches' => $ov['watches'],
            'items'   => $ov['items'],
            'counts'  => $ov['counts'],
            'jobs'    => Service_Nj120_JobQueue::instance()->channel_jobs('nj605.radar'),
            'flash'   => (string) Session::instance()->get_once('nj605_admin_flash', ''),
            'csrf'    => Security::token(),
        ]);
    }
}
