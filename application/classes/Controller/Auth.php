<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Точка переопределения логина (приложение, не frozen-модуль).
 *
 * prx599_account: после верных логина/пароля, если у пользователя включена
 * двухфакторка (TOTP), завершаем парольный этап и показываем /auth/2fa —
 * ввод одноразового кода из приложения-аутентификатора. Без 2FA — как
 * раньше, плюс запись в журнал входов и регистрация активной сессии.
 * Логика родителя сохранена один-в-один (вью, Яндекс-кнопка, гейт
 * email_confirmed).
 *
 * 06.10.2026: возврат после входа. Ссылки модулей вида
 * /auth/login?return_url=<страница> теперь работают явно (раньше параметр
 * никто не читал, и возврат держался только на Referer): пользователь
 * попадает обратно на страницу, где увидел замочек «Сервис для
 * зарегистрированных». Referer остаётся запасным путём.
 */
class Controller_Auth extends Controller_Prx102_Auth {

    /**
     * Нормализация цели возврата: принимаем только локальный путь — без
     * схемы (https://…) и без ведущих '//' (протокольный адрес), иначе
     * пусто. Защита от открытого редиректа чужими ссылками.
     */
    public static function return_redirect($value)
    {
        $value = trim((string) $value);

        if ($value === '' OR $value[0] === '\\' OR strpbrk($value, "\r\n\t") !== FALSE)
        {
            return '';
        }

        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $value) OR strpos($value, '//') === 0)
        {
            return '';
        }

        return $value[0] === '/' ? $value : '/'.$value;
    }

    public function action_register()
    {
        throw HTTP_Exception::factory(404, 'Учётные записи создаёт администратор');
    }

    public function action_login()
    {
        if (Auth::instance()->logged_in())
        {
            throw HTTP_Exception::factory(302)->location(Route::url('default', array('controller' => 'welcome')));
        }

        $errors = array();

        // Куда вернуть после входа: явный ?return_url= из ссылки надёжнее
        // Referer (его режут строгие настройки приватности и открытие
        // «войти» в новой вкладке); Referer — запасной путь для обычных
        // ссылок на /auth/login. Пустой return_url ничего не затирает —
        // цель из GET доживает до повторного POST формы с ошибкой.
        $return_url = self::return_redirect($this->request->query('return_url'));

        if ($return_url !== '')
        {
            Session::instance()->set('login_redirect', $return_url);
        }
        else
        {
            $referrer = $this->request->referrer();

            $referrer = self::return_redirect($referrer);
            if ($referrer && strpos($referrer, 'auth/login') === false)
            {
                Session::instance()->set('login_redirect', $referrer);
            }
        }

        if ($this->request->method() === Request::POST)
        {
            if (! Security::check((string) $this->request->post('csrf')))
            {
                throw HTTP_Exception::factory(403, 'Сессия формы устарела');
            }
            if (Auth::instance()->login($this->request->post('username'), $this->request->post('password'), (bool)$this->request->post('remember')))
            {
                $user = Auth::instance()->get_user();
                if ($user->email_confirmed != 1)
                {
                    Auth::instance()->logout();
                    $errors[] = 'Пожалуйста, подтвердите ваш email.';
                }
                elseif (Service_Prx599_Account::totp_enabled((int) $user->id))
                {
                    // --- prx599: двухфакторка включена — второй шаг ---
                    Auth::instance()->logout();

                    Session::instance()->set('prx599_pending', [
                        'uid'      => (int) $user->id,
                        'remember' => (bool) $this->request->post('remember'),
                        'redirect' => (string) Session::instance()->get('login_redirect', '/'),
                        'tries'    => 0,
                        'ts'       => time(),
                    ]);

                    throw HTTP_Exception::factory(302)->location('/auth/2fa');
                }
                else
                {
                    // --- prx599: журнал входа + регистрация активной сессии ---
                    try
                    {
                        Service_Prx599_Account::after_login((int) $user->id, 'password');
                    }
                    catch (Throwable $e)
                    {
                        Kohana::$log->add(Log::WARNING, 'prx599: after_login: :e', [':e' => $e->getMessage()]);
                    }

                    $redirect_url = Session::instance()->get_once('login_redirect', '/');
                    throw HTTP_Exception::factory(302)->location($redirect_url);
                }
            }
            else
            {
                $errors[] = 'Неверное имя пользователя или пароль.';
            }
        }

        // Вход по Яндекс.OAuth убран 28.09.2026 вместе с модулем yandexauth:
        // модуль не был зарегистрирован в bootstrap (то есть ветка никогда не
        // исполнялась), а его конфиг указывал на домен другого проекта.
        $this->template->title = 'Вход';
        $this->template->content = View::factory('auth/login')
            ->bind('errors', $errors);
    }

    /**
     * prx599: второй шаг входа — одноразовый код TOTP (6 цифр) из приложения.
     * Пейлоад живёт 10 минут в сессии, не более 5 попыток.
     */
    public function action_2fa()
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
