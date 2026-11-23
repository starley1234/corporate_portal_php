<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx150_api_hub — ключи, использование и документация внешнего API.
 *
 * Три вкладки: ключи (список + создание), использование (журнал + фильтры),
 * документация (как получить ключ и звать сервис). Полный ключ показывается
 * один раз после создания; в списке — только префикс.
 */
class Controller_Prx150_Admin extends Controller_Prx100_Base
{
    const PER_PAGE = 30;

    public function before()
    {
        parent::before();

        if ( ! $this->can('prx150.admin'))
        {
            throw HTTP_Exception::factory(403, 'API-доступ — только для администратора');
        }

        $this->set_title('API-доступ — администрирование');
        $this->set_module_nav([
            ['title' => 'API-доступ', 'href' => '/prx150_api_hub', 'icon' => 'fas fa-key',
             'active_paths' => ['/prx150_api_hub/usage', '/prx150_api_hub/docs']],
            ['title' => 'Администрирование', 'href' => '/admin', 'icon' => 'fas fa-gear'],
            ['title' => 'Логи платформы', 'href' => '/prx100/logs', 'icon' => 'fas fa-terminal'],
        ]);
    }

    public function action_index()
    {
        $tab = (string) $this->request->query('tab');

        if ($tab === 'usage')
        {
            $this->action_usage();
            return;
        }

        if ($tab === 'docs')
        {
            $this->action_docs();
            return;
        }

        $flash = $this->_flash();

        try
        {
            $keys = Prx_Db::select(
                'SELECT c.*, '
                .'(SELECT count(*) FROM prx_150_api_usage u '
                .' WHERE u.client_id = c.id AND u.created_at > now() - interval \'24 hours\') AS requests_24h '
                .'FROM prx_150_api_clients c ORDER BY c.id DESC'
            );
        }
        catch (Throwable $e)
        {
            $keys = [];
            $flash = ['type' => 'err', 'text' => 'Таблицы API-доступа ещё нет. Выполните `make migrate` (миграция prx_150_001).'];
        }

        $this->template->content = View::factory('150/index', [
            'tab'           => 'keys',
            'keys'          => $keys,
            'scopes'        => Service_Prx150_Services::scopes(),
            'organizations' => Service_Prx150_Keys::organizations(),
            'created_key'   => Session::instance()->get_once('prx150_key_once', NULL),
            'csrf'          => Security::token(),
            'flash'         => $flash,
            'rate_limit'    => (int) Kohana::$config->load('prx150_api_hub')->get('default_rate_limit'),
            'ttl_days'      => (int) Kohana::$config->load('prx150_api_hub')->get('key_ttl_days'),
        ]);
    }

    /** Создание ключа: POST, CSRF уже проверен Prx_PostGuard. */
    public function action_create()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405)->allowed('POST');
        }

        $scopes = (array) $this->request->post('scopes');
        $expires = trim((string) $this->request->post('expires_at'));

        try
        {
            [$id, $token] = Service_Prx150_Keys::create(
                (string) $this->request->post('name'),
                array_map('strval', $scopes),
                (int) $this->request->post('rate_limit'),
                $expires !== '' ? $expires : NULL,
                (int) $this->_current_user->id,
                (string) $this->request->post('note'),
                (int) $this->request->post('organization_id') ?: NULL
            );

            Session::instance()->set('prx150_key_once', [
                'id' => $id, 'token' => $token, 'name' => (string) $this->request->post('name'),
            ]);
            Session::instance()->set('prx150_flash', ['type' => 'ok', 'text' => 'Ключ создан. Скопируйте его сейчас: повторно он не показывается.']);
        }
        catch (Prx150_Error $e)
        {
            Session::instance()->set('prx150_flash', ['type' => 'err', 'text' => 'Не удалось создать ключ: '.$e->getMessage()]);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'prx150: create key failed: :e', [':e' => $e->getMessage()]);
            Session::instance()->set('prx150_flash', ['type' => 'err', 'text' => 'Не удалось создать ключ: база не готова (`make migrate`).']);
        }

        HTTP::redirect('/prx150_api_hub', 303);
    }

    /** Отзыв ключа: POST, необратимо. */
    public function action_revoke()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405)->allowed('POST');
        }

        $id = (int) $this->request->param('id');

        try
        {
            $ok = Service_Prx150_Keys::revoke($id);
            Session::instance()->set('prx150_flash', [
                'type' => $ok ? 'ok' : 'err',
                'text' => $ok ? 'Ключ отозван.' : 'Ключ не найден или уже отозван.',
            ]);
        }
        catch (Throwable $e)
        {
            Session::instance()->set('prx150_flash', ['type' => 'err', 'text' => 'Не удалось отозвать ключ.']);
        }

        HTTP::redirect('/prx150_api_hub', 303);
    }

    /** Журнал использования: фильтры GET, ссылки можно делиться. */
    public function action_usage()
    {
        $period = (string) $this->request->query('period');
        $period = in_array($period, ['24h', '7d', '30d'], TRUE) ? $period : '24h';
        $client_id = (int) $this->request->query('client_id');
        $scope = trim((string) $this->request->query('scope'));
        $status = (string) $this->request->query('status');
        $status = in_array($status, ['2xx', '4xx', '5xx'], TRUE) ? $status : '';
        $q = mb_substr(trim((string) $this->request->query('q')), 0, 120);
        $per = min(100, max(10, (int) $this->request->query('per') ?: self::PER_PAGE));
        $page = max(1, (int) $this->request->query('page'));

        $where = ['u.created_at > now() - interval :interval'];
        $params = [':interval' => ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'][$period]];

        if ($client_id > 0)
        {
            $where[] = 'u.client_id = :client_id';
            $params[':client_id'] = $client_id;
        }

        if ($scope !== '')
        {
            $where[] = 'u.scope = :scope';
            $params[':scope'] = $scope;
        }

        if ($status !== '')
        {
            $where[] = 'u.status / 100 = :status';
            $params[':status'] = (int) $status[0];
        }

        if ($q !== '')
        {
            $where[] = 'u.path ILIKE :q';
            $params[':q'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        }

        $flash = $this->_flash();
        $sql_where = implode(' AND ', $where);
        $total = 0;
        $rows = [];
        $clients = [];
        $summary = ['total' => 0, 'errors' => 0, 'avg_ms' => 0, 'clients' => 0];

        try
        {
            $total = (int) (Prx_Db::one("SELECT count(*) AS n FROM prx_150_api_usage u WHERE {$sql_where}", $params)['n'] ?? 0);
            $rows = Prx_Db::select(
                "SELECT u.*, c.name AS client_name, c.key_prefix "
                ."FROM prx_150_api_usage u JOIN prx_150_api_clients c ON c.id = u.client_id "
                ."WHERE {$sql_where} ORDER BY u.id DESC LIMIT :limit OFFSET :offset",
                $params + [':limit' => $per, ':offset' => ($page - 1) * $per]
            );
            $summary = Prx_Db::one(
                "SELECT count(*) AS total, "
                ."count(*) FILTER (WHERE u.status >= 400) AS errors, "
                ."coalesce(avg(u.duration_ms), 0) AS avg_ms, "
                ."count(DISTINCT u.client_id) AS clients "
                ."FROM prx_150_api_usage u WHERE {$sql_where}",
                $params
            ) ?: $summary;
            $clients = Prx_Db::select('SELECT id, name, key_prefix FROM prx_150_api_clients ORDER BY name');
        }
        catch (Throwable $e)
        {
            $flash = ['type' => 'err', 'text' => 'Журнал API-доступа ещё не создан. Выполните `make migrate` (миграция prx_150_001).'];
        }

        $this->template->content = View::factory('150/usage', [
            'tab'      => 'usage',
            'rows'     => $rows,
            'clients'  => $clients,
            'scopes'   => Service_Prx150_Services::scopes(),
            'summary'  => $summary,
            'total'    => $total,
            'page'     => $page,
            'per'      => $per,
            'period'   => $period,
            'client_id'=> $client_id,
            'scope'    => $scope,
            'status'   => $status,
            'q'        => $q,
            'flash'    => $flash,
        ]);
    }

    /** Документация для потребителя: что выдать, как звать, какие ошибки. */
    public function action_docs()
    {
        $this->template->content = View::factory('150/docs', [
            'tab'    => 'docs',
            'scopes' => Service_Prx150_Services::scopes(),
            'flash'  => $this->_flash(),
        ]);
    }

    protected function _flash(): array
    {
        $flash = Session::instance()->get_once('prx150_flash', []);

        return is_array($flash) ? $flash : [];
    }
}
