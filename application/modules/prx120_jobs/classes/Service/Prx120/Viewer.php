<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Кто смотрит страницу модуля — для «тонких» status-endpoint'ов (/radar/api/status,
 * /leads/api/status и т.п.).
 *
 * Зачем отдельный класс: эти endpoint'ы живут в Api-контроллерах, которые
 * наследуют голый Controller (у них нет шаблона, меню и Prx102_Base), поэтому
 * $this->_current_user и $this->can() там НЕ существуют: обращение к ним давало
 * 500 на поллинге — страница модуля молча теряла статус-ряд, и «Готово» не
 * появлялось. Здесь та же логика ролей, что в Prx102_Base::before():
 * роль admin из $user->roles.
 */
class Service_Prx120_Viewer {

    /**
     * Контекст зрителя: [user_id, is_admin].
     *
     * Никогда не бросает исключение: поллинг статуса обязан отвечать JSON'ом
     * даже при сбое авторизации (иначе статус-ряд «залипает»).
     *
     * @return array [int $user_id, bool $is_admin]
     */
    public static function context()
    {
        $uid      = 0;
        $is_admin = FALSE;

        try
        {
            if ( ! class_exists('Auth') OR ! Auth::instance()->logged_in('login'))
            {
                return [0, FALSE];
            }

            $user = Auth::instance()->get_user();

            if ($user)
            {
                $uid = (int) $user->id;

                foreach ($user->roles->find_all() as $role)
                {
                    if ((string) $role->name === 'admin')
                    {
                        $is_admin = TRUE;
                        break;
                    }
                }
            }
        }
        catch (Throwable $e)
        {
            // нет сессии/таблиц ролей — считаем гостем: статус-ряд это переживёт
            Kohana::$log->add(Log::WARNING, 'prx120: viewer: :e', [':e' => $e->getMessage()]);
        }

        return [$uid, $is_admin];
    }

    /**
     * Безопасный текст этапа с кликабельными доменами.
     *
     * Воркер рапортует этапы строками («Чтение источников 4/6 — neywa.ru»), и
     * владелец справедливо заметил: «какие-то источники он же нашёл — результат
     * есть». Ссылка на источник делает этот результат полезным: по ней можно
     * пойти, даже если сбор был остановлен.
     *
     * Сначала экранируем текст целиком (XSS закрыт), потом — уже в безопасной
     * строке — оборачиваем найденные домены в <a>. Шаблон строгий: только
     * буквы/цифры/дефис и точка + известная зона, поэтому сущности вида &quot;
     * под него не подпадают.
     *
     * @param string $text сообщение этапа
     * @return string готовый HTML
     */
    public static function step_html($text)
    {
        $safe = HTML::chars((string) $text);

        $pattern = '~(?:https?://)?(?:[a-z0-9-]+\.)+(?:ru|com|net|org|io|info|su|biz|pro|tech|site|online|рф)(?:/[^\s<>"]*)?~iu';

        return preg_replace_callback($pattern, function ($m) {
            $url = $m[0];

            if ( ! preg_match('~^https?://~i', $url))
            {
                $url = 'https://'.$url;
            }

            return '<a href="'.HTML::chars($url).'" target="_blank" rel="noopener nofollow" '
                .'class="text-blue-600 hover:underline dark:text-blue-400">'.$m[0].'</a>';
        }, $safe);
    }
}
