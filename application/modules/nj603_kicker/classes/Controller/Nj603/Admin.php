<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj603 админка /nj603/admin: расписания из конфига, состояние пинков,
 * «Пнуть сейчас» и «Проверить сейчас» (проход по созревшим).
 */
class Controller_Nj603_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj603.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Пиналка · управление');

        Service_Nj603_Kicker::ensure_tables();
    }

    public function action_index()
    {
        $flash = '';

        if ($this->request->method() === Request::POST)
        {
            $act  = (string) $this->request->post('act');
            $name = (string) $this->request->post('name');

            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            elseif ($act === 'run')
            {
                try
                {
                    $res = Service_Nj603_Kicker::run();
                    $flash = 'Проверка выполнена: расписаний сработало '.(int) $res['pushed'].'. '
                        .implode('; ', (array) $res['log']);
                }
                catch (Throwable $e)
                {
                    $flash = 'Не получилось: '.$e->getMessage();
                }
            }
            elseif ($act === 'poke' AND $name !== '')
            {
                $res = Service_Nj603_Kicker::poke_now($name);
                $flash = isset($res['ok'])
                    ? '«'.$name.'» выполнено: '.(string) ($res['note'] ?? 'ok')
                    : (string) ($res['error'] ?? 'не получилось');
            }
            else
            {
                $flash = 'Неизвестное действие';
            }

            HTTP::redirect('/nj603/admin?flash='.urlencode($flash), 303);
        }

        $schedules = [];
        $state     = Service_Nj603_Kicker::state_map();

        foreach (Service_Nj603_Kicker::schedules() as $name => $sc)
        {
            $st = (array) ($state[$name] ?? []);

            $schedules[] = [
                'name'        => (string) $name,
                'label'       => (string) ($sc['label'] ?? $name),
                'every'       => (string) ($sc['every'] ?? '?'),
                'enabled'     => ! empty($sc['enabled']),
                'url'         => (string) ($sc['url'] ?? ''),
                'last_run'    => (string) ($st['last_run_at'] ?? ''),
                'runs'        => (int) ($st['runs'] ?? 0),
                'last_status' => (string) ($st['last_status'] ?? ''),
                'last_error'  => (string) ($st['last_error'] ?? ''),
            ];
        }

        $this->template->content = View::factory('603/admin', [
            'schedules' => $schedules,
            'token_set' => trim((string) getenv('NJ603_KICK_TOKEN')) !== '',
            'flash'     => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
            'csrf'      => Security::token(),
        ]);
    }
}
