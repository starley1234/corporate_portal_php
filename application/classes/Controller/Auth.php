<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Точка переопределения логина (приложение, не frozen-модуль).
 *
 * После верных логина/пароля ядро спрашивает точку расширения
 * account.login_stage2 (docs/SKELETON-ADR.md Д2/Д7): модуль второго фактора
 * возвращает URL второго шага (и сам готовит пейлоад сессии), иначе NULL —
 * вход завершается как раньше, плюс журнал через account.after_login.
 * Второй шаг (ввод TOTP) живёт в модуле: Controller_Prx599_2fa.
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
            // AUTH_REQUIRE_EMAIL_CONFIRMATION=0 в .env — подтверждение почты не требуется
            $require_confirm = getenv('AUTH_REQUIRE_EMAIL_CONFIRMATION') !== '0';
            if (! Security::check((string) $this->request->post('csrf')))
            {
                throw HTTP_Exception::factory(403, 'Сессия формы устарела');
            }
            if (Auth::instance()->login($this->request->post('username'), $this->request->post('password'), (bool)$this->request->post('remember')))
            {
                $user = Auth::instance()->get_user();
                if (isset($user->active) AND ! Model_Prx102_User::is_active($user->active))
                {
                    Auth::instance()->logout();
                    $errors[] = 'Аккаунт деактивирован администратором.';
                }
                elseif ($require_confirm AND $user->email_confirmed != 1)
                {
                    Auth::instance()->logout();
                    $errors[] = 'Пожалуйста, подтвердите ваш email.';
                }
                else
                {
                    // Д2/Д7: вторым фактором управляет модуль — ядро получает
                    // URL второго шага или NULL (вход по паролю завершён).
                    $stage2 = Prx_Hooks::call('account.login_stage2',
                        (int) $user->id,
                        (bool) $this->request->post('remember'),
                        (string) Session::instance()->get('login_redirect', '/'));

                    if ($stage2 !== NULL)
                    {
                        throw HTTP_Exception::factory(302)->location($stage2);
                    }

                    // журнал входа + регистрация активной сессии (точка расширения)
                    try
                    {
                        Prx_Hooks::call('account.after_login', (int) $user->id, 'password');
                    }
                    catch (Throwable $e)
                    {
                        Kohana::$log->add(Log::WARNING, 'account.after_login: :e', [':e' => $e->getMessage()]);
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
}
