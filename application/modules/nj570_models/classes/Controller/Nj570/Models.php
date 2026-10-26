<?php defined('SYSPATH') or die('No direct script access.');

/**
 * 3D-конструктор («Sketch 2 Solid»): /3d — создание и каталог,
 * /3d/<code> — работа с моделью (просмотр 3D, ревизии, правки, ползунки).
 */
class Controller_Nj570_Models extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('3D-модель по тексту и фото — Sketch 2 Solid');
        $this->set_description('Опишите конструкцию текстом или приложите эскиз — ИИ напишет код OpenSCAD, воркер соберёт STL. Ревизии, правки, параметрические ползунки, просмотр 3D в браузере.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        // Страницы конструктора не кэшируем: JS вьюера/поллинга инлайновый,
        // закэшированная старая копия страницы = «сломанный» интерфейс.
        $this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
        $this->response->headers('Pragma', 'no-cache');
    }

    /** /3d: GET — конструктор + мои модели + публичный каталог; POST — создание. */
    public function action_index()
    {
        // Маршрут '3d' объявлен без <action> — все POST-ы приходят в index
        // (как в nj520_poll). Отдельного action_create не существует.
        if ($this->request->method() === Request::POST)
        {
            $this->_create();

            return;
        }

        $q = trim((string) $this->request->query('q'));

        $this->template->content = View::factory('570/index', [
            'my'      => $this->_user_id ? Service_Nj570_Models::list_user($this->_user_id) : [],
            'public'  => Service_Nj570_Models::list_public($q),
            'q'       => $q,
            'logged'  => $this->_user_id !== NULL,
            'csrf'    => Security::token(),
            'error'   => NULL,
        ]);
    }

    /** Создание модели (POST /3d обрабатывается в action_index). */
    protected function _create()
    {
        if ($this->_user_id === NULL)
        {
            throw HTTP_Exception::factory(403, 'Создание моделей — только для зарегистрированных');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        $image = $this->_upload_image_b64();

        $res = Service_Nj570_Models::create($this->_user_id,
            trim((string) Arr::get($_POST, 'prompt', '')),
            $image,
            [
                'iterations' => (int) Arr::get($_POST, 'iterations', 3),
                'use_search' => ! empty($_POST['use_search']),
                'clarify'    => ! empty($_POST['clarify']),
            ]);

        if (isset($res['error']))
        {
            $this->template->content = View::factory('570/index', [
                'my'     => Service_Nj570_Models::list_user($this->_user_id),
                'public' => Service_Nj570_Models::list_public(''),
                'q'      => '',
                'logged' => TRUE,
                'csrf'   => Security::token(),
                'error'  => $res['error'],
            ]);

            return;
        }

        HTTP::redirect('/3d/'.$res['code'], 303);
    }

    /** GET /3d/<code> — страница модели. */
    public function action_view()
    {
        $code  = (string) $this->request->param('code');
        $model = Service_Nj570_Models::by_code($code);

        if ($model === NULL)
        {
            throw HTTP_Exception::factory(404, 'Модель не найдена');
        }

        $is_owner = ($this->_user_id !== NULL AND (int) $model['user_id'] === $this->_user_id);
        $is_admin = Auth::instance()->logged_in('admin');

        // Просмотр — по коду-капабилити (как /p/<код> у опросов): кто знает код,
        // тот может смотреть. Правки/публикация — только владелец, модерация — управление.
        $revisions = Service_Nj570_Models::revisions((int) $model['id']);
        $my_score  = 0;

        if ($this->_user_id !== NULL)
        {
            $db = Database::instance();
            $r  = $db->query(Database::SELECT,
                'SELECT score FROM nj_570_ratings WHERE model_id = '.(int) $model['id'].' AND user_id = '.$this->_user_id,
                FALSE)->current();

            $my_score = $r ? (int) $r['score'] : 0;
        }

        $this->set_title('3D-модель '.($model['title'] ?: $model['code']).' — Sketch 2 Solid');

        $this->template->content = View::factory('570/view', [
            'model'     => $model,
            'revisions' => $revisions,
            'is_owner'  => $is_owner,
            'is_admin'  => $is_admin,
            'uid'       => $this->_user_id,
            'rating'    => Service_Nj570_Models::rating((int) $model['id']),
            'my_score'  => $my_score,
            'csrf'      => Security::token(),
        ]);
    }

    /** POST /3d/<code>/edit — правка текстом/изображением (новая ревизия). */
    public function action_edit()
    {
        $this->_post('edit', function (array $model) {
            return Service_Nj570_Models::edit($this->_user_id, $model['code'],
                trim((string) Arr::get($_POST, 'prompt', '')),
                $this->_upload_image_b64());
        });
    }

    /** POST /3d/<code>/answers — ответы на уточняющие вопросы. */
    public function action_answers()
    {
        $this->_post('answers', function (array $model) {
            return Service_Nj570_Models::answer_clarify($this->_user_id, $model['code'],
                (array) Arr::get($_POST, 'a', []));
        });
    }

    /** POST /3d/<code>/rerender — пересборка STL по ползункам. */
    public function action_rerender()
    {
        $this->_post('rerender', function (array $model) {
            return Service_Nj570_Models::rerender($this->_user_id, $model['code'],
                (array) Arr::get($_POST, 'p', []));
        });
    }

    /** POST /3d/<code>/rate — оценка. */
    public function action_rate()
    {
        $this->_post('rate', function (array $model) {
            return Service_Nj570_Models::rate($this->_user_id, (int) $model['id'],
                (int) Arr::get($_POST, 'score', 0), (string) Arr::get($_POST, 'comment', ''));
        });
    }

    /** POST /3d/<code>/publish — на модерацию. */
    public function action_publish()
    {
        $this->_post('publish', function (array $model) {
            return Service_Nj570_Models::publish($this->_user_id, $model['code']);
        });
    }

    protected function _post($what, Closure $fn)
    {
        if ($this->_user_id === NULL)
        {
            throw HTTP_Exception::factory(403, 'Нужна авторизация');
        }

        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        $model = Service_Nj570_Models::by_code((string) $this->request->param('code'));

        if ($model === NULL)
        {
            throw HTTP_Exception::factory(404, 'Модель не найдена');
        }

        $res = $fn($model);
        $msg = 'Готово';

        if (isset($res['error']))
        {
            $msg = $res['error'];
        }
        elseif ($what === 'edit')
        {
            $msg = 'Правка поставлена в очередь — следите за ревизиями';
        }
        elseif ($what === 'rerender')
        {
            $msg = 'Сборка с новыми параметрами запущена';
        }
        elseif ($what === 'publish')
        {
            $msg = 'Модель отправлена на модерацию';
        }

        HTTP::redirect('/3d/'.$model['code'].'?flash='.urlencode($msg), 303);
    }

    /** GET /3d/<code>/file/<id> — файл конкретной модели (капабилити = код). */
    public function action_file()
    {
        $file = Service_Nj570_Models::file((int) $this->request->param('id'));

        if ($file === NULL)
        {
            throw HTTP_Exception::factory(404, 'Файл не найден');
        }

        $model = Service_Nj570_Models::by_id((int) $file['model_id']);
        $is_owner = ($this->_user_id !== NULL AND $model AND (int) $model['user_id'] === $this->_user_id);
        $is_admin = Auth::instance()->logged_in('admin');
        $by_code  = ($model !== NULL AND (string) $this->request->param('code') === (string) $model['code']);

        if ($model === NULL OR ( ! $by_code AND $model['status'] !== 'published' AND ! $is_owner AND ! $is_admin))
        {
            throw HTTP_Exception::factory(403, 'Нет доступа к файлу');
        }

        $path = Service_Nj570_Models::file_path($file['path']);

        if ( ! is_file($path))
        {
            throw HTTP_Exception::factory(404, 'Файл отсутствует на диске');
        }

        $this->auto_render = FALSE;

        $this->response->headers('Content-Type', $file['kind'] === 'stl' ? 'model/stl' : 'image/png')
            ->headers('Content-Length', (string) filesize($path))
            ->headers('Content-Disposition', 'inline; filename="model.'.$file['kind'].'"')
            ->headers('Cache-Control', 'private, max-age=3600')
            ->body(file_get_contents($path));
    }

    /** Изображение из формы → base64 уменьшенной копии (PNG/JPEG до 512px). */
    protected function _upload_image_b64()
    {
        if (empty($_FILES['image']['tmp_name']) OR ! is_uploaded_file($_FILES['image']['tmp_name']))
        {
            return '';
        }

        if ($_FILES['image']['size'] > 12 * 1024 * 1024)
        {
            return '';
        }

        $info = @getimagesize($_FILES['image']['tmp_name']);

        if ( ! $info OR ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], TRUE))
        {
            return '';
        }

        $raw = file_get_contents($_FILES['image']['tmp_name']);
        $b64 = base64_encode($raw);

        // Если GD есть — уменьшаем до image_max_width; иначе отдаём как есть
        // (финальное уменьшение до 512 всё равно делает воркер перед vision).
        if (function_exists('imagecreatefromstring'))
        {
            $img = @imagecreatefromstring($raw);

            if ($img !== FALSE)
            {
                $max = (int) Kohana::$config->load('nj570_models.image_max_width', 512);

                if (imagesx($img) > $max)
                {
                    $h   = (int) round(imagesy($img) * $max / imagesx($img));
                    $dst = imagecreatetruecolor($max, $h);
                    imagecopyresampled($dst, $img, 0, 0, 0, 0, $max, $h, imagesx($img), imagesy($img));
                    imagedestroy($img);
                    $img = $dst;
                }

                ob_start();
                imagepng($img, NULL, 6);
                $b64 = base64_encode((string) ob_get_clean());
                imagedestroy($img);
            }
        }

        return strlen($b64) <= (int) Kohana::$config->load('nj570_models.image_max_bytes', 700000) ? $b64 : '';
    }
}
