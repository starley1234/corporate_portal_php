<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Второй шаг входа (prx599): одноразовый TOTP-код (6 цифр) из приложения.
 * Раньше жил в Controller_Auth приложения; вынесен в модуль, чтобы ядро не
 * знало о втором факторе (docs/SKELETON-ADR.md Д2/Д7). Пейлоад
 * prx599_pending (см. init.php) живёт 10 минут в сессии, не более 5 попыток.
 */
class Controller_Prx599_2fa extends Controller_Prx102_Base
{
    public function action_index()
    {
        if (Auth::instance()->logged_in())
        {
            throw HTTP_Exception::factory(302)->location('/');
        }

        $session = Session::instance();
        $pending = $session->get('prx599_pending');

        if ( ! is_array($pending) OR empty($pending['uid'])
            OR (time() - (int) Arr::get($pending, 'ts', 0)) > 600)
        {
            $session->delete('prx599_pending');
            throw HTTP_Exception::factory(302)->location('/auth/login');
        }

        $errors = array();

        if ($this->request->method() === Request::POST)
        {
            if (! Security::check((string) $this->request->post('csrf')))
            {
                throw HTTP_Exception::factory(403, 'Сессия формы устарела');
            }
            if ((int) Arr::get($pending, 'tries', 0) >= 5)
            {
                $session->delete('prx599_pending');
                throw HTTP_Exception::factory(302)->location('/auth/login');
            }

            $pending['ts'] = time();

            if (Service_Prx599_Account::totp_verify(
                    (string) Arr::get(Service_Prx599_Account::totp_row((int) $pending['uid']) ?: [], 'secret'),
                    (string) $this->request->post('code')))
            {
                $user = ORM::factory('User', (int) $pending['uid']);

                if ($user->loaded())
                {
                    Auth::instance()->force_login($user);
                    $session->delete('prx599_pending');

                    try
                    {
                        Service_Prx599_Account::after_login((int) $user->id, '2fa');
                    }
                    catch (Throwable $e)
                    {
                        Kohana::$log->add(Log::WARNING, 'prx599: after_login: :e', [':e' => $e->getMessage()]);
                    }

                    throw HTTP_Exception::factory(302)->location((string) Arr::get($pending, 'redirect', '/'));
                }
            }

            $pending['tries'] = (int) Arr::get($pending, 'tries', 0) + 1;
            $session->set('prx599_pending', $pending);
            $errors[] = 'Код не подошёл — введите актуальный из приложения-аутентификатора.';
        }

        $this->template->title = 'Двухфакторный вход';

        $this->template->content = View::factory('prx599/2fa')
            ->bind('errors', $errors)
            ->set('csrf', Security::token());
    }
}
