<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Аккаунт и безопасность (prx599): /account/security — TOTP, активные сессии,
 * журнал входов, «выйти везде». Только для вошедших.
 */
class Controller_Prx599_Security extends Controller_Prx100_Base {

    protected function _uid()
    {
        return $this->_current_user ? (int) $this->_current_user->id : NULL;
    }

    public function before()
    {
        parent::before();

        if ( ! $this->_current_user)
        {
            HTTP::redirect('/auth/login', 302);
        }


        Service_Prx599_Account::ensure_tables();

        // active-сессию держим свежей; убитую («выйти везде») отправляем на logout
        if ( ! Service_Prx599_Account::session_valid($this->_uid()))
        {
            HTTP::redirect('/auth/logout', 302);
        }

        Service_Prx599_Account::touch_session($this->_uid());
    }

    /** GET /account/security; POST — действия begin_totp|enable_totp|disable_totp|end_session|logout_everywhere. */
    public function action_index()
    {
        $flash = '';

        if ($this->request->method() === Request::POST)
        {
            $act = (string) $this->request->post('act');

            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            else
            {
                try
                {
                    $flash = $this->_handle($act);
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::WARNING, 'prx599: act :a: :e', [':a' => $act, ':e' => $e->getMessage()]);
                    $flash = 'Не получилось: '.$e->getMessage();
                }
            }

            HTTP::redirect('/account/security?flash='.urlencode($flash), 303);
        }

        $uid  = $this->_uid();
        $totp = Service_Prx599_Account::totp_row($uid);
        $pending_secret = '';

        // секрет показываем только в момент настройки (enabled_at NULL)
        if ($totp !== NULL AND $totp['enabled_at'] === NULL)
        {
            $pending_secret = (string) $totp['secret'];
        }

        $this->set_title('Аккаунт и безопасность — 2FA, сессии, журнал входов');

        $this->template->content = View::factory('599/security', [
            'totp_enabled'   => Service_Prx599_Account::totp_enabled($uid),
            'totp_setup'     => $totp !== NULL AND $totp['enabled_at'] === NULL,
            'setup_secret'   => $pending_secret,
            'setup_uri'      => $pending_secret !== '' ? Service_Prx599_Account::otpauth_uri($pending_secret, (string) $this->_current_user->username) : '',
            'sessions'       => Service_Prx599_Account::sessions($uid),
            'logins'         => Service_Prx599_Account::logins($uid),
            'current_sid'    => (string) Session::instance()->id(),
            'flash'          => isset($_GET['flash']) ? (string) $_GET['flash'] : $flash,
            'csrf'           => Security::token(),
        ]);
    }

    protected function _handle($act)
    {
        $uid = $this->_uid();

        switch ($act)
        {
            case 'begin_totp':
                Service_Prx599_Account::totp_begin($uid);

                return 'Секрет создан — отсканируйте QR и введите код из приложения';

            case 'enable_totp':
                $res = Service_Prx599_Account::totp_enable($uid, (string) $this->request->post('code'));

                return isset($res['ok']) ? 'Двухфакторный вход включён' : (string) $res['error'];

            case 'disable_totp':
                Service_Prx599_Account::totp_disable($uid);

                return 'Двухфакторный вход выключен';

            case 'end_session':
                $sid = (string) $this->request->post('sid');

                if ($sid === (string) Session::instance()->id())
                {
                    return 'Текущую сессию завершайте через «Выйти»';
                }

                Service_Prx599_Account::end_session($uid, $sid);

                return 'Сессия завершена';

            case 'logout_everywhere':
                Service_Prx599_Account::logout_everywhere($uid);

                // текущую сессию тоже гасим — на страницу вернуться нельзя
                Session::instance()->delete('prx599_pending');
                Auth::instance()->logout();
                HTTP::redirect('/', 302);
                exit;

            default:
                return 'Неизвестное действие';
        }
    }
}
