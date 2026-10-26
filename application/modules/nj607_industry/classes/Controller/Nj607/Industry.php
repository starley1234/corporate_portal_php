<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj607_industry: /industry — формы и мои отчёты; /industry/<code> — отчёт.
 */
class Controller_Nj607_Industry extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Карта отрасли и разведка производителей');
        $this->set_description('Кто производит в отрасли, что и как: список игроков с сайтами, сегменты и цены, каналы продаж, возможности входа. Авто-отчёт или ручная разведка под запрос.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        // «Управление» модуля — чипом в шапке (module_nav), а не в рабочей области
        $this->set_module_nav([
            ['title' => 'Управление', 'href' => '/nj607/admin', 'icon' => 'fas fa-lock', 'acl' => 'nj607.admin'],
        ]);

    }

    public function action_index()
    {
        $flash = '';

        if ($this->request->method() === Request::POST AND Security::check((string) $this->request->post('csrf')))
        {
            $act = (string) $this->request->post('act');

            if ($act === 'self')
            {
                $res = Service_Nj607_Industry::create_self($this->_user_id,
                    (string) $this->request->post('industry'), (string) $this->request->post('region'));

                if (isset($res['code']))
                {
                    HTTP::redirect('/industry/'.$res['code'], 302);
                }

                $flash = (string) ($res['error'] ?? '');
            }
            elseif ($act === 'order')
            {
                $res = Service_Nj607_Industry::create_order($this->_user_id, Visitor::uid(),
                    (string) $this->request->post('industry'), (string) $this->request->post('region'),
                    (string) $this->request->post('contact'));

                $flash = isset($res['error']) ? $res['error']
                    : 'Заявка принята — вернёмся с планом разведки и сроками по контакту: '
                    .(string) $this->request->post('contact');
            }
        }

        $this->template->content = View::factory('nj607/index', [
            'reports' => $this->_user_id ? Service_Nj607_Industry::list_of($this->_user_id) : [],
            'flash'   => $flash,
            'csrf'    => Security::token(),
            'logged'  => $this->_user_id !== NULL,
            'is_admin' => $this->_user_id !== NULL AND ($this->can('nj607.admin') OR Auth::instance()->logged_in('admin')),
            // Подсказки полей — из НАШЕЙ базы (онтология), а не автозаполнение
            // браузера: владелец просил «не используй автозаполнение браузером».
            'popular' => Service_Nj607_Ontology::popular(8),
            // Поля можно предзаполнить ссылкой (связь из другого модуля):
            // /industry?industry=…&region=…
            'prefill' => [
                'industry' => trim((string) $this->request->query('industry')),
                'region'   => trim((string) $this->request->query('region')),
            ],
        ]);
    }

    /** Просмотр отчёта: Markdown конвертим простым рендером (без внешних библиотек). */
    public function action_view()
    {
        $r = Service_Nj607_Industry::by_code((string) $this->request->param('code'));

        if ( ! $r)
        {
            throw HTTP_Exception::factory(404, 'Отчёт не найден');
        }

        // Скобки обязательны: OR слабее присваивания — без них анонимный владелец
        // отчёта (по visitor_id) не признавался владельцем и получал 404.
        $is_owner = (($this->_user_id !== NULL AND (int) $r['user_id'] === $this->_user_id)
            OR ($r['visitor_id'] !== '' AND $r['visitor_id'] === Visitor::uid()));
        $is_admin = Auth::instance()->logged_in('admin');

        if ( ! $is_owner AND ! $is_admin)
        {
            throw HTTP_Exception::factory(403, 'Отчёт доступен только владельцу');
        }

        // Разбор отчёта заново — кнопка на странице (владелец или администратор).
        // Нужна после починки разбора или если миграции онтологии применили
        // позже: повторный разбор идемпотентен (upsert сущностей + связи).
        if ($this->request->method() === HTTP_Request::POST
            AND (string) $this->request->post('act') === 'ontology_resync')
        {
            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $this->redirect('industry/'.$r['code'].'?flash='.urlencode('Сессия устарела — разбор не выполнен'));

                return;
            }

            $res = Service_Nj607_Ontology::sync_report((int) $r['id'], (string) $r['code'],
                (string) $r['industry'], (string) $r['region'], (string) $r['report_md']);

            $this->redirect('industry/'.$r['code'].'?flash='.urlencode(
                'Разбор выполнен: сущностей '.(int) $res['entities'].', связей '.(int) $res['links']));

            return;
        }

        // Онтология: у готового отчёта сущности должны быть ВСЕГДА — если отчёт
        // собран до появления разбора (или разбор не удался), добираем один раз
        // при просмотре. Отчёт при этом не меняется.
        if ($r['status'] === 'done' AND trim((string) $r['report_md']) !== ''
            AND Service_Nj607_Ontology::count_of((int) $r['id']) === 0)
        {
            Service_Nj607_Ontology::sync_report((int) $r['id'], (string) $r['code'],
                (string) $r['industry'], (string) $r['region'], (string) $r['report_md']);
        }

        $this->template->content = View::factory('nj607/view', [
            'r'        => $r,
            'is_admin' => $is_admin,
            'is_owner' => $is_owner,
            'entities' => Service_Nj607_Ontology::entities_of((int) $r['id']),
            'ontology' => Service_Nj607_Ontology::diagnose((int) $r['id']),
        ]);
    }

    /**
     * /industry/entity/<slug> — страница СУЩНОСТИ онтологии: что это, в каких
     * отчётах встречается и с какими объектами других модулей связано.
     *
     * Владелец: «сделай из отчёта сущности, которые надо будет связать с другими
     * модулями. Наша цель — связанная онтология».
     */
    public function action_entity()
    {
        $slug = (string) $this->request->param('slug');
        // kind необязателен: одно название встречается и как компания, и как
        // регион — ссылки из модулей передают вид явно (?kind=company)
        $e    = Service_Nj607_Ontology::by_slug($slug, (string) Arr::get($_GET, 'kind', ''));

        if ( ! $e)
        {
            throw HTTP_Exception::factory(404, 'Сущность не найдена');
        }

        // «Обновить связи» — ищем совпадения по нашей базе заново (кнопка
        // администратора на странице сущности; сущности из потоков заводятся
        // без тяжёлого поиска, связи догоняются здесь).
        if (Auth::instance()->logged_in('admin')
            AND $this->request->method() === HTTP_Request::POST
            AND (string) $this->request->post('act') === 'links_refresh')
        {
            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $this->redirect('industry/entity/'.rawurlencode($slug).'?flash='.urlencode('Сессия устарела'));
            }
            else
            {
                $added = Service_Nj607_Ontology::refresh_links($slug, (string) $e['kind']);

                $this->redirect('industry/entity/'.rawurlencode($slug)
                    .'?kind='.rawurlencode((string) $e['kind'])
                    .'&flash='.urlencode('Связи обновлены: добавлено '.$added));
            }

            return;
        }

        $kinds = Service_Nj607_Ontology::kinds();
        $kind  = $kinds[(string) $e['kind']] ?? ['label' => 'Сущности', 'one' => 'Сущность', 'icon' => 'fas fa-tag'];

        $this->set_title(mb_substr((string) $e['title'], 0, 120).' — сущность карты отрасли');
        $this->set_description('Что известно об этом участнике рынка: отчёты, где он встречается, и связанные объекты сервисов N2J.');

        $this->template->content = View::factory('nj607/entity', [
            'e'      => $e,
            'kind'   => $kind,
            'twins'   => Service_Nj607_Ontology::twins_of((string) $e['kind'], (string) $e['slug']),
            'links'   => Service_Nj607_Ontology::links_by_slug((string) $e['kind'], (string) $e['slug']),
            // в каких отчётах встречается сущность — «онтология общая»
            'reports' => Service_Nj607_Ontology::reports_of((int) $e['id']),
            'csrf'   => Security::token(),
            'logged' => $this->_user_id !== NULL,
            'is_admin' => Auth::instance()->logged_in('admin'),
            // «Связать дальше»: готовые запросы к другим модулям с этой сущностью
            'actions' => $this->_entity_actions($e),
        ]);
    }

    /**
     * Куда сущность ведёт в других модулях: радар заказов (спрос по этому
     * продукту/компании), лиды (поставщики), профиль компании.
     * Это не выдуманные связи, а рабочие ссылки с предзаполненным запросом.
     */
    protected function _entity_actions(array $e)
    {
        $title  = trim((string) $e['title']);
        $region = trim((string) $e['region']);
        $out    = [];

        if ($title !== '')
        {
            $out[] = ['/radar?query='.rawurlencode($title), 'fa-satellite-dish', 'Радар заказов по «'.mb_substr($title, 0, 60).'»'];
            $out[] = ['/leads?industry='.rawurlencode($title).($region !== '' ? '&region='.rawurlencode($region) : ''), 'fa-building-user', 'Поток поставщиков (лиды)'];
        }

        if ($e['kind'] === 'company' AND $e['domain'] !== '')
        {
            $out[] = ['/company?q='.rawurlencode((string) $e['domain']), 'fa-id-card', 'Профиль компании по домену'];
        }

        return $out;
    }
}
