<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Дизайн страницы» (nj590): /design — создание прогона и мои прогоны,
 * /design/<code> — прогон (варианты оформления, выбор), file — HTML-вариант.
 */
class Controller_Nj590_Design extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Дизайн страницы — варианты оформления от ИИ');
        $this->set_description('Укажите адрес страницы — ИИ изучит её и предложит несколько вариантов улучшенного дизайна: композиция, палитра, типографика. Выбирайте лучший вариант.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        // верхнее меню модуля (чипы md+, на малых экранах — в левой панели)
        $this->set_module_nav([]); // чипы раздела даёт header_nav (дубли убраны)

        // Страницы не кэшируем: инлайновый JS поллинга, кэш = сломанный интерфейс
        $this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
        $this->response->headers('Pragma', 'no-cache');
    }

    /** /design: GET — форма + мои прогоны; POST — создание прогона. */
    public function action_index()
    {
        // Маршрут 'design' объявлен без <action> — POST приходит в index (как в nj580)
        if ($this->request->method() === Request::POST)
        {
            $this->_create();

            return;
        }

        $this->template->content = View::factory('590/index', [
            'my'          => $this->_user_id ? Service_Nj590_Design::list_user($this->_user_id) : [],
            'limits'      => Service_Nj590_Design::limits(),
            'logged'      => $this->_user_id !== NULL,
            'saved_model' => Auth::instance()->logged_in('admin') ? trim((string) Cookie::get('nj590_model', '')) : '',
            'csrf'        => Security::token(),
            'flash'       => (string) Arr::get($_GET, 'flash', ''),
        ]);
    }

    /** POST /design — создать прогон. */
    protected function _create()
    {
        if ($this->_user_id === NULL)
        {
            $this->redirect('auth/login?return_url=design');

            return;
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('design?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

            return;
        }

        // выбор модели — только администратору; непустое значение помним в куках
        $model = '';

        if (Auth::instance()->logged_in('admin'))
        {
            $model = trim((string) Arr::get($_POST, 'model', ''));

            if ($model !== '')
            {
                if (preg_match('~^[A-Za-z0-9_.:/-]{2,120}$~', $model))
                {
                    Cookie::set('nj590_model', $model, 86400 * 180);
                }
                else
                {
                    $model = '';
                }
            }
            elseif (Cookie::get('nj590_model', '') !== '')
            {
                Cookie::delete('nj590_model');
            }
        }

        $res = Service_Nj590_Design::create(
            $this->_user_id,
            (string) Arr::get($_POST, 'url', ''),
            (string) Arr::get($_POST, 'wishes', ''),
            (int) Arr::get($_POST, 'variants', 3),
            $model
        );

        if (isset($res['error']))
        {
            $this->redirect('design?flash='.urlencode((string) $res['error']));

            return;
        }

        $this->redirect('design/'.$res['code']);
    }

    /** GET /design/<code> — прогон: статус, варианты, выбор. */
    public function action_view()
    {
        $code = (string) $this->request->param('code');
        $run  = Service_Nj590_Design::by_code($code);

        if ($run === NULL)
        {
            throw HTTP_Exception::factory(404, 'Прогон не найден');
        }

        $run      = Service_Nj590_Design::reconcile($run);
        $is_owner = ($this->_user_id !== NULL AND (int) $run['user_id'] === $this->_user_id);

        $this->template->content = View::factory('590/view', [
            'run'      => $run,
            'variants' => Service_Nj590_Design::variants((int) $run['id']),
            'is_owner' => $is_owner,
            'csrf'     => Security::token(),
            'flash'    => (string) Arr::get($_GET, 'flash', ''),
        ]);
    }

    /** POST /design/<code>/pick — выбрать вариант. */
    public function action_pick()
    {
        $code = (string) $this->request->param('code');
        $run  = Service_Nj590_Design::by_code($code);
        $is_owner = ($run AND $this->_user_id !== NULL AND (int) $run['user_id'] === $this->_user_id);

        if ( ! $is_owner AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Вариант выбирает владелец прогона');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('design/'.$code.'?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

            return;
        }

        $no  = (int) Arr::get($_POST, 'no', 0);
        $msg = Service_Nj590_Design::pick((int) $run['id'], $no)
            ? 'Вариант №'.$no.' выбран — он подсвечен в списке'
            : 'Вариант №'.$no.' не найден — выберите из списка';

        $this->redirect('design/'.$code.'?flash='.urlencode($msg));
    }

    /** POST /design/<code>/cancel — остановить свой прогон (владелец или управление). */
    public function action_cancel()
    {
        $code = (string) $this->request->param('code');
        $run  = Service_Nj590_Design::by_code($code);
        $is_owner = ($run AND $this->_user_id !== NULL AND (int) $run['user_id'] === $this->_user_id);

        if ( ! $is_owner AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Прогон останавливает владелец');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('design/'.$code.'?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

            return;
        }

        Service_Nj590_Design::cancel_run((int) $run['id']);
        $this->redirect('design/'.$code.'?flash='.urlencode('Прогон остановлен — воркер завершится на ближайшей контрольной точке'));
    }

    /** GET /design/<code>/file/<id> — HTML-вариант (inline, для iframe-превью). */
    public function action_file()
    {
        $variant = Service_Nj590_Design::variant((int) $this->request->param('id'));

        if ($variant === NULL)
        {
            throw HTTP_Exception::factory(404, 'Вариант не найден');
        }

        $run      = Service_Nj590_Design::by_id((int) $variant['run_id']);
        $is_owner = ($this->_user_id !== NULL AND $run AND (int) $run['user_id'] === $this->_user_id);
        $is_admin = Auth::instance()->logged_in('admin');
        $by_code  = ($run !== NULL AND (string) $this->request->param('code') === (string) $run['code']);

        if ($run === NULL OR ( ! $by_code AND ! $is_owner AND ! $is_admin))
        {
            throw HTTP_Exception::factory(403, 'Нет доступа к файлу');
        }

        $path = Service_Nj590_Design::file_path($variant['path']);

        if ((string) $variant['path'] === '' OR ! is_file($path))
        {
            throw HTTP_Exception::factory(404, 'Файл отсутствует на диске');
        }

        $this->auto_render = FALSE;

        $this->response->headers('Content-Type', 'text/html; charset=utf-8')
            ->headers('Content-Length', (string) filesize($path))
            ->headers('X-Content-Type-Options', 'nosniff')
            ->headers('Cache-Control', 'private, max-age=300');

        $this->response->body(file_get_contents($path));
    }
}
