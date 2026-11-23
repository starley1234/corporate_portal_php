<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx208_events — события портала: журнал, подписки.
 *
 * Доступ — администратор (acl `prx208_events.*`). Журнал только читается:
 * событие публикует код (Prx_Event::publish / Event::fire), а не форма.
 * Формы подписок — POST + CSRF (Prx_PostGuard в Controller_Prx100_Base).
 */
class Controller_Prx208_Events extends Controller_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('prx208_events.view'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('События');
        $this->set_module_nav([
            ['title' => 'Журнал', 'href' => '/prx208_events', 'icon' => 'fas fa-bolt'],
            ['title' => 'Подписки', 'href' => '/prx208_events/list_subscribers', 'icon' => 'fas fa-user-tag'],
            ['title' => 'Уведомления', 'href' => '/prx597/admin', 'icon' => 'fas fa-bell'],
            ['title' => 'Хвост ошибок', 'href' => '/prx100/errors', 'icon' => 'fas fa-heart-crack'],
        ]);
    }

    /** Журнал + сводка. Старый адрес /list_events ведёт сюда же. */
    public function action_index()
    {
        $filters = [
            'prefix'    => trim((string) $this->request->query('prefix')),
            'q'         => trim((string) $this->request->query('q')),
            'initiator' => (int) $this->request->query('initiator'),
            'page'      => max(1, (int) $this->request->query('page')),
            'per'       => (int) $this->request->query('per'),
        ];

        $this->template->content = View::factory('prx208/journal', [
            'summary'   => Service_Prx208_Journal::summary(),
            'switches'  => Service_Prx208_Journal::switches(),
            'prefixes'  => Service_Prx208_Journal::prefixes(),
            'titles'    => Service_Prx208_Journal::module_titles(),
            'result'    => Service_Prx208_Journal::rows($filters),
            'filters'   => $filters,
        ]);
    }

    public function action_list_events()
    {
        $this->action_index();
    }

    /** Карточка события: что, кто, когда и кто на это подписан. */
    public function action_view_event()
    {
        $event = Service_Prx208_Journal::detail((int) $this->request->param('id'));

        if ($event === NULL)
        {
            throw HTTP_Exception::factory(404, 'Событие не найдено');
        }

        $this->set_title('Событие '.$event['event_name']);
        $this->template->content = View::factory('prx208/event/detail', ['event' => $event]);
    }

    /** Список подписок. */
    public function action_list_subscribers()
    {
        if ( ! $this->can('prx208_events.list_subscribers'))
        {
            throw new HTTP_Exception_403('Доступ запрещен');
        }

        $this->set_title('Подписки на события');

        $query_params = $this->request->query();
        $per_page = isset($query_params['per_page']) ? max(1, min(200, (int) $query_params['per_page'])) : 20;
        $search   = isset($query_params['search']) ? trim((string) $query_params['search']) : '';

        $subscribers_model = ORM::factory('Prx208_Subscriber');

        if ($search !== '')
        {
            $subscribers_model->where_open()
                ->where('event_name', 'LIKE', '%'.$search.'%')
                ->or_where('module_id', 'LIKE', '%'.$search.'%')
                ->where_close();
        }

        $total_items = $subscribers_model->reset(FALSE)->count_all();

        $pagination = Pagination::factory(array(
            'total_items'    => $total_items,
            'items_per_page' => $per_page,
            'current_page'   => array('source' => 'query_string', 'key' => 'page'),
        ));

        $subscribers = $subscribers_model
            ->order_by('id', 'DESC')
            ->limit($pagination->items_per_page)
            ->offset($pagination->offset)
            ->find_all();

        $bus_config = Kohana::$config->load('prx208_events')->get('event_bus', array());

        $this->template->content = View::factory('prx208/subscriber/list')
            ->set('subscribers', $subscribers)
            ->set('pagination', $pagination)
            ->set('search', $search)
            ->set('csrf', Security::token())
            ->set('db_subscribers_enabled', ! empty($bus_config['load_db_subscribers']))
            ->set('flash', $this->_flash());
    }

    /** Создание/редактирование подписки. */
    public function action_manage_subscription()
    {
        if ( ! $this->can('prx208_events.manage_subscription'))
        {
            throw new HTTP_Exception_403('Доступ запрещен');
        }

        $id = (int) $this->request->param('id');
        $subscriber = ORM::factory('Prx208_Subscriber', $id);

        if ($id > 0 AND ! $subscriber->loaded())
        {
            throw new HTTP_Exception_404('Подписка не найдена');
        }

        $this->set_title($subscriber->loaded() ? 'Подписка #'.$id : 'Новая подписка');
        $errors = array();
        $bus_config = Kohana::$config->load('prx208_events')->get('event_bus', array());

        if ($this->request->method() === Request::POST)
        {
            $this->_check_subscription_post();
            $post = $this->request->post();
            foreach (array('event_name', 'module_id', 'callback_method', 'description') as $field)
            {
                if (isset($post[$field]) AND ! is_string($post[$field]))
                {
                    throw new HTTP_Exception_400('Некорректное поле формы');
                }
                $post[$field] = trim($post[$field] ?? '');
            }
            foreach (array('is_active', 'is_notification') as $field)
            {
                if (isset($post[$field]) AND $post[$field] !== '1')
                {
                    throw new HTTP_Exception_400('Некорректный флаг формы');
                }
                // Неотмеченный checkbox отсутствует в POST, в том числе при редактировании.
                $post[$field] = isset($post[$field]);
            }

            try
            {
                $subscriber->values($post, array('event_name', 'module_id', 'callback_method', 'is_active', 'is_notification', 'description'));

                if ( ! $subscriber->loaded() && $subscriber->is_duplicate($subscriber->event_name, $subscriber->module_id, $subscriber->callback_method))
                {
                    $errors['event_name'] = __('A subscription with these details already exists.');
                }
                else
                {
                    $subscriber->save();
                    Event::fire('prx208_events.subscription_saved', array('subscriber_id' => (int) $subscriber->id));
                    Session::instance()->set('success_message', 'Подписка сохранена.');
                    $this->redirect(Route::url('prx208_events', array('action' => 'list_subscribers')));
                }
            }
            catch (ORM_Validation_Exception $e)
            {
                $errors = $e->errors('models');
            }
        }

        $this->template->content = View::factory('prx208/subscriber/form')
            ->set('subscriber', $subscriber)
            ->set('errors', $errors)
            ->set('csrf', Security::token())
            ->set('db_subscribers_enabled', ! empty($bus_config['load_db_subscribers']));
    }

    /** Удаление подписки — POST + CSRF. */
    public function action_delete_subscription()
    {
        if ( ! $this->can('prx208_events.delete_subscription'))
        {
            throw new HTTP_Exception_403('Доступ запрещен');
        }

        $this->_check_subscription_post();
        $subscriber = ORM::factory('Prx208_Subscriber', (int) $this->request->param('id'));

        if ($subscriber->loaded())
        {
            $subscriber_id = (int) $subscriber->id;
            $subscriber->delete();
            Event::fire('prx208_events.subscription_deleted', array('subscriber_id' => $subscriber_id));
            Session::instance()->set('success_message', 'Подписка удалена.');
        }
        else
        {
            Session::instance()->set('error_message', 'Подписка не найдена.');
        }

        $this->redirect(Route::url('prx208_events', array('action' => 'list_subscribers')));
    }

    /** POST + CSRF для форм подписок (до любых изменений). */
    protected function _check_subscription_post()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw (new HTTP_Exception_405('Нужен POST'))->allowed('POST');
        }
        $csrf = $this->request->post('csrf');
        if ( ! is_string($csrf) OR ! Security::check($csrf))
        {
            throw new HTTP_Exception_403('Сессия устарела, обновите страницу');
        }
    }

    /** Flash из сессии (старые ключи success_message/error_message) → partials/flash. */
    protected function _flash(): array
    {
        $session = Session::instance();
        $ok = (string) $session->get_once('success_message', '');
        $err = (string) $session->get_once('error_message', '');

        if ($err !== '')
        {
            return ['type' => 'err', 'text' => $err, 'items' => []];
        }
        if ($ok !== '')
        {
            return ['type' => 'ok', 'text' => $ok, 'items' => []];
        }

        return [];
    }
}
