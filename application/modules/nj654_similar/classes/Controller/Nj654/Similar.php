<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Похожие картинки» (NJ-654): форма поиска, отдельная страница результата и банк.
 *
 *   GET  /similar              форма поиска: текст, картинка или оба ввода
 *   POST /similar/find         завести поиск → редирект на /similar/result/<id>
 *   GET  /similar/result/<id>  запрос, состояние и найденные совпадения
 *   GET  /similar/collection   банк: массовая загрузка и правка
 *   POST /similar/add          добавить (до bulk_max файлов за раз)
 *   POST /similar/rename       переименовать элемент
 *   POST /similar/delete       убрать отмеченные
 *
 * Формы остаются рабочими без JS; улучшение поиска сразу показывает запрос,
 * пока обычный POST загружает данные. Ответ 303 открывает каноническую страницу
 * результата, а общий live-status следит за воркером уже на ней.
 */
class Controller_Nj654_Similar extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        $this->set_title('Похожие картинки: поиск по смыслу в своём банке изображений');
        $this->set_description(
            'Загрузите картинки в банк и ищите похожие описанием или другой картинкой. '
            .'Поиск идёт по смыслу, а не по имени файла: мультимодальная модель кладёт текст и изображения в одно пространство.');
        $this->set_keywords('поиск похожих изображений по картинке по описанию банк изображений эмбеддинги');

        // Чипы этого ряда стоят и в шапке сайта, и в левой панели: порядок
        // выбран владельцем (08.10.2026) — «Похожие картинки», «Журнал»,
        // «Банк картинок». Манифест каталога при этом отказывается от СВОИХ
        // чипов (`header_chips => 'own'`): иначе рядом с «Похожие картинки»
        // вставало «Похожие картинки · журнал» — дубль того же раздела.
        $this->set_module_nav([
            ['title' => 'Похожие картинки', 'href' => '/similar', 'icon' => 'fas fa-magnifying-glass'],
            ['title' => 'Журнал', 'href' => '/similar/admin', 'icon' => 'fas fa-clock-rotate-left', 'roles' => ['admin']],
            ['title' => 'Банк картинок', 'href' => '/similar/collection', 'icon' => 'fas fa-images'],
        ]);
    }

    /** Поиск: форма и результат на одной странице, без перезагрузки. */
    public function action_index()
    {
        // Замок: банк картинок работает только по бесплатному аккаунту
        // (владелец, 08.10.2026) — иначе чужие картинки ищутся анонимами,
        // а очередь эмбеддингов расходуется кем попало. Гость видит ту же
        // страницу, но с плашкой входа вместо формы и банка.
        if ( ! Gate::logged_in())
        {
            // Гость мог прийти и POST-ом (например, со старой вкладки) — тогда
            // сразу уводим на вход, а не показываем страницу сервиса.
            if ($this->request->method() === Request::POST)
            {
                $this->redirect('auth/login?return_url=similar');
            }

            $this->_render_search(NULL, FALSE);

            return;
        }

        $legacy_id = (int) $this->request->query('q');

        if ($legacy_id > 0)
        {
            $query = Service_Nj654_Bank::query_owned($legacy_id, $this->_visitor(), $this->_user_id());

            if ( ! $query)
            {
                throw HTTP_Exception::factory(404, 'Поиск не найден');
            }

            HTTP::redirect('/similar/result/'.$legacy_id, 303);
        }

        $this->_render_search(NULL, FALSE);
    }

    /** Результат поиска: query, ожидание и находки на отдельной странице. */
    public function action_result()
    {
        // Результаты поиска — рабочая область сервиса: гостя возвращаем на
        // главную страницу модуля, где его встречает плашка входа.
        if ( ! Gate::logged_in())
        {
            HTTP::redirect('/similar', 303);
        }

        $id    = (int) $this->request->param('id');
        $query = $id > 0
            ? Service_Nj654_Bank::query_owned($id, $this->_visitor(), $this->_user_id())
            : NULL;

        if ( ! $query)
        {
            throw HTTP_Exception::factory(404, 'Поиск не найден');
        }

        $this->_render_search($query, TRUE);
    }

    protected function _render_search($query, $result_page)
    {
        $logged = Gate::logged_in();

        $this->template->content = View::factory('654/search', [
            'logged'      => $logged,
            'stats'       => $logged ? Service_Nj654_Bank::stats() : [],
            'query'       => $query,
            'hits'        => (array) ($query['hits'] ?? []),
            'result_page' => (bool) $result_page,
            'top'         => (int) Service_Nj654_Bank::cfg('top_default', 1),
            'max'         => (int) Service_Nj654_Bank::cfg('top_max', 10),
            'mb'          => (int) Service_Nj654_Bank::cfg('photo_mb', 12),
            'csrf'        => Security::token(),
            'flash'       => $this->_flash(),
        ]);
    }

    /** Банк: что лежит, добавление и правка. */
    public function action_collection()
    {
        // Банк — часть сервиса для зарегистрированных: гостя ведём на
        // страницу модуля, где стоит плашка входа.
        if ( ! Gate::logged_in())
        {
            HTTP::redirect('/similar', 303);
        }

        $filter = ['search' => mb_substr(trim((string) $this->request->query('search')), 0, 120)];
        $mine   = (string) $this->request->query('mine') === '1';

        if ($mine)
        {
            $filter['visitor'] = $this->_visitor();
        }

        // Банк на сотню картинок листается, а не вываливается одной простынёй.
        // По умолчанию СПИСОК: в нём сразу видны подписи, статусы и правка;
        // плитка — отдельный режим «посмотреть глазами», включается кнопкой.
        $grid  = (string) $this->request->query('view') === 'grid';
        $per   = $grid ? 48 : 25;
        $total = Service_Nj654_Bank::items_count($filter);
        $pages = max(1, (int) ceil($total / $per));
        $page  = max(1, min($pages, (int) $this->request->query('p')));

        $this->template->content = View::factory('654/collection', [
            'stats'  => Service_Nj654_Bank::stats(),
            'items'  => Service_Nj654_Bank::items($filter, $per, ($page - 1) * $per),
            'total'  => $total,
            'filter' => $filter,
            'mine'   => $mine,
            'grid'   => $grid,
            'page'   => $page,
            'pages'  => $pages,
            // Правка открывается по ссылке ?edit=<id>: отдельная панель вместо
            // раскрывающейся «гармошки» в каждой плитке — она ломала сетку.
            'edit'   => Service_Nj654_Bank::item((int) $this->request->query('edit')),
            'limits' => [
                'bulk'  => (int) Service_Nj654_Bank::cfg('bulk_max', 20),
                'mb'    => (int) Service_Nj654_Bank::cfg('photo_mb', 12),
                'width' => (int) Service_Nj654_Bank::cfg('image_max_width', 1024),
            ],
            'visitor' => $this->_visitor(),
            'admin'   => Auth::instance()->logged_in('admin'),
            'csrf'    => Security::token(),
            'flash'   => $this->_flash(),
        ]);
    }

    // ------------------------------------------------------------ действия

    /**
     * Завести поиск.
     *
     * Два ответа на одно действие:
     *   · fetch движка (X-Requested-With) получает JSON `{ok,id,url}` — страница
     *     остаётся на месте, форма уступает место карточке запроса;
     *   · обычная отправка формы без JS получает прежний 303 на
     *     /similar/result/<id> — путь остаётся рабочим даже без скриптов.
     */
    public function action_find()
    {
        $json = $this->_wants_json();
        $stop = $this->_guard_search($json);

        if ($stop !== NULL)
        {
            return $stop;
        }

        $text  = (string) Arr::get($_POST, 'text', '');
        $image = $this->_files('image', 1);

        if (trim($text) === '' AND ! $image)
        {
            $message = 'Чтобы искать, нужен текст, картинка или и то и другое.';

            if ($json)
            {
                return $this->_json(['ok' => FALSE, 'error' => $message], 422);
            }

            $this->_back('/similar', $message);
        }

        if ($this->_too_often())
        {
            $message = 'Слишком часто: подождите немного.';

            if ($json)
            {
                return $this->_json(['ok' => FALSE, 'error' => $message], 429);
            }

            $this->_back('/similar', $message);
        }

        $id = Service_Nj654_Bank::query_create([
            'text'      => $text,
            'image'     => $image ? $image[0] : [],
            'top'       => (int) Arr::get($_POST, 'top', Service_Nj654_Bank::cfg('top_default', 1)),
            'visitor'   => $this->_visitor(),
            'user_id'   => $this->_user_id(),
            'client_ip' => $this->_ip(),
        ]);

        if ($id <= 0)
        {
            $message = 'Не удалось завести поиск — попробуйте ещё раз.';

            if ($json)
            {
                return $this->_json(['ok' => FALSE, 'error' => $message], 500);
            }

            $this->_back('/similar', $message);
        }

        if ($json)
        {
            return $this->_json([
                'ok'     => TRUE,
                'id'     => $id,
                'url'    => '/similar/result/'.$id,
                'status' => '/similar/api/status?q='.$id,
            ]);
        }

        HTTP::redirect('/similar/result/'.$id, 303);
    }

    /** Добавить в банк: один элемент или сразу пачку файлов. */
    public function action_add()
    {
        $this->_guard();

        $bulk  = (int) Service_Nj654_Bank::cfg('bulk_max', 20);
        $files = $this->_files('images', $bulk);
        $note  = (string) Arr::get($_POST, 'note', '');
        $title = (string) Arr::get($_POST, 'title', '');

        if ( ! $files AND trim($note) === '')
        {
            $this->_back('/similar/collection', 'Выберите файлы или напишите текст — пустое добавлять нечего.');
        }

        $visitor = $this->_visitor();
        $ids     = [];
        $dupes   = 0;

        // Текст без картинки — тоже элемент банка: по нему находятся картинки.
        $batch = $files ?: [[]];

        foreach ($batch as $file)
        {
            $id = Service_Nj654_Bank::add_item([
                // У пачки общей подписи не бывает: иначе двадцать картинок
                // лягут в банк под одним именем. Там подписью станет имя файла.
                'title'       => count($batch) > 1 ? '' : $title,
                'note'        => $note,
                'image'       => $file,
                'visitor'     => $visitor,
                'user_id'     => $this->_user_id(),
                'fingerprint' => Service_Nj654_Bank::fingerprint($note, (string) ($file['sha'] ?? '')),
            ]);

            if ($id > 0)
            {
                $ids[] = $id;
            }
            else
            {
                $dupes++;
            }
        }

        if ($ids)
        {
            Service_Nj654_Bank::queue_items($ids);
        }

        $this->_back('/similar/collection', 'Добавлено: '.count($ids)
            .($dupes > 0 ? ', пропущено как уже имеющееся: '.$dupes : '')
            .'. Векторы считает воркер — это несколько секунд.');
    }

    public function action_rename()
    {
        $this->_guard();

        $ok = Service_Nj654_Bank::rename(
            (int) Arr::get($_POST, 'id', 0),
            (string) Arr::get($_POST, 'title', ''),
            (string) Arr::get($_POST, 'note', ''),
            $this->_visitor(),
            Auth::instance()->logged_in('admin'));

        $this->_back('/similar/collection'.$this->_whence(), $ok
            ? 'Подпись обновлена.'
            : 'Этот элемент добавлен из другого браузера — править его может только администратор.');
    }

    public function action_delete()
    {
        $this->_guard();

        $gone = Service_Nj654_Bank::delete(
            (array) Arr::get($_POST, 'ids', []),
            $this->_visitor(),
            Auth::instance()->logged_in('admin'));

        $this->_back('/similar/collection'.$this->_whence(), $gone > 0
            ? 'Убрано элементов: '.$gone.'.'
            : 'Ничего не убрано: отметьте свои элементы галочками.');
    }

    // ---------------------------------------------------------- помощники

    /**
     * Файлы формы → сохранённые картинки банка.
     *
     * Здесь же происходит уменьшение до `image_max_width`: на диск не должна
     * попасть ни одна фотография в исходном размере.
     */
    protected function _files($field, $limit)
    {
        $out = [];

        if (empty($_FILES[$field]['name']))
        {
            return $out;
        }

        $names = (array) $_FILES[$field]['name'];
        $tmp   = (array) $_FILES[$field]['tmp_name'];
        $errs  = (array) $_FILES[$field]['error'];

        foreach (array_keys($names) as $n)
        {
            if (count($out) >= (int) $limit)
            {
                break;
            }

            if ((int) ($errs[$n] ?? 1) !== UPLOAD_ERR_OK OR ! is_uploaded_file((string) $tmp[$n]))
            {
                continue;
            }

            $bytes = (string) file_get_contents((string) $tmp[$n]);
            $saved = Service_Nj654_Bank::save_image($bytes, (string) $names[$n]);

            if ($saved)
            {
                // Отпечаток считаем по ИСХОДНЫМ байтам: тот же файл, залитый
                // дважды, обязан опознаваться независимо от сжатия.
                $saved['sha'] = $bytes;
                $out[] = $saved;
            }
        }

        return $out;
    }

    protected function _guard()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        // Действия (поиск, добавление, правка) — только для вошедших:
        // redirect уводит гостя на вход и возвращает на страницу модуля.
        if ( ! Gate::logged_in())
        {
            $this->redirect('auth/login?return_url=similar');
        }

        if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }
    }

    /**
     * Проверки POST-поиска.
     *
     * Отдельно от общего `_guard()`: движку без перезагрузки отказ нужен
     * текстом в JSON (его показывают рядом с формой), а обычной форме —
     * прежний редирект/исключение. Возвращает готовый JSON-ответ, если
     * запрос пришёл от JS, и NULL, если всё в порядке.
     */
    protected function _guard_search($json)
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Gate::logged_in())
        {
            if ($json)
            {
                return $this->_json([
                    'ok'    => FALSE,
                    'error' => 'Поиск работает по бесплатному аккаунту — войдите и повторите.',
                    'login' => '/auth/login?return_url=similar',
                ], 401);
            }

            $this->redirect('auth/login?return_url=similar');
        }

        if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            $message = 'Сессия устарела — обновите страницу и повторите.';

            if ($json)
            {
                return $this->_json(['ok' => FALSE, 'error' => $message], 403);
            }

            throw HTTP_Exception::factory(403, $message);
        }

        return NULL;
    }

    /**
     * Пришёл ли запрос из fetch/JS.
     *
     * Смотрим заголовок X-Requested-With (его ставит движок `654/search_js`)
     * и поле ajax в форме: без JS форма уйдёт обычным POST и получит честный
     * 303 с сообщением.
     */
    protected function _wants_json()
    {
        return $this->request->is_ajax()
            OR trim((string) Arr::get($_POST, 'ajax', '')) !== '';
    }

    /** Ответ JSON: страница рендерится только для людей, движку нужен ответ. */
    protected function _json(array $data, $status = 200)
    {
        $this->auto_render = FALSE;
        $this->response->status((int) $status);
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $this->response;
    }

    protected function _too_often()
    {
        return Service_Nj654_Bank::per_hour($this->_ip())
            >= (int) Service_Nj654_Bank::cfg('per_hour', 120);
    }

    /**
     * Сообщение + возврат.
     *
     * Текст едет в сессии, а не в адресной строке: ссылку с «?flash=Убрано
     * элементов: 4» неприятно видеть, она переживает F5 и попадает в закладки.
     */
    protected function _back($where, $message)
    {
        Session::instance()->set('nj654_flash', (string) $message);

        HTTP::redirect($where, 303);
    }

    /**
     * Куда вернуть человека после правки: тот же вид, та же страница, тот же
     * отбор. Иначе на сороковой картинке правка выбрасывает в начало банка.
     */
    protected function _whence()
    {
        $keep = [];

        foreach (['view', 'p', 'search', 'mine'] as $key)
        {
            $value = mb_substr((string) Arr::get($_POST, $key, ''), 0, 120);

            if ($value !== '')
            {
                $keep[$key] = $value;
            }
        }

        return $keep ? '?'.http_build_query($keep) : '';
    }

    protected function _flash()
    {
        return mb_substr((string) Session::instance()->get_once('nj654_flash', ''), 0, 300);
    }

    protected function _visitor()
    {
        return class_exists('Visitor') ? (string) Visitor::uid() : '';
    }

    protected function _user_id()
    {
        return class_exists('Visitor') ? Visitor::user_id() : NULL;
    }

    protected function _ip()
    {
        return class_exists('Service_Nj100_Ratelimit') ? Service_Nj100_Ratelimit::client_ip() : '';
    }
}
