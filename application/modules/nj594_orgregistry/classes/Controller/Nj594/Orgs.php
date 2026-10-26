<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Справочник организаций (nj594): /orgs — поиск и список, /orgs/<ogrn> — карточка.
 * Данные открытые (ЕГРЮЛ/ЕГРИП ФНС) — страницы доступны гостям.
 */
class Controller_Nj594_Orgs extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        // верхнее меню модуля не задаём: чипы раздела «Справочник» + «Управление»
        // уже собирает nj110 header_nav() из манифеста — дубли недопустимы

        Service_Nj594_Orgs::seed(); // посев демо-данных при первом открытии
    }

    /** GET /orgs?q= — список и поиск. */
    public function action_index()
    {
        $q     = trim((string) $this->request->query('q'));
        $rows  = Service_Nj594_Orgs::search($q, 30);
        $cnt   = Service_Nj594_Orgs::counts();

        $this->set_title('Справочник организаций и ИП — реквизиты из ЕГРЮЛ/ЕГРИП');
        $this->set_description('Единый справочник организаций платформы: реквизиты из ЕГРЮЛ/ЕГРИП (ФНС), привязка материалов модулей к организации. Поиск по ОГРН, ИНН и названию.');

        // Если справочник ничего не нашёл — сразу предлагаем живой поиск в
        // интернете (ФНС для номеров, разведка nj635 для названий/доменов).
        $lookup = NULL;

        if ($q !== '' AND empty($rows))
        {
            $lookup = Service_Nj594_Orgs::internet_lookup($q);
            $lookup['csrf'] = Security::token();
        }

        $this->template->content = View::factory('594/index', [
            'q'        => $q,
            'rows'     => $rows,
            'counts'   => $cnt,
            'lookup'   => $lookup,
            'is_admin' => Auth::instance()->logged_in('admin'),
        ]);
    }

    /**
     * POST /orgs/lookup — «найти организацию в интернете», если её нет в
     * справочнике. Сразу запускает существующие механизмы: номер (ОГРН/ИНН) →
     * живой запрос ФНС с сохранением карточки; название/домен → сквозная
     * разведка nj635 (создаёт проект и уводит на карту шагов).
     */
    public function action_lookup()
    {
        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            $this->redirect('orgs?flash='.urlencode('Сессия устарела — повторите поиск'));

            return;
        }

        $q = trim((string) $this->request->post('q'));

        if ($q === '')
        {
            $this->redirect('orgs');

            return;
        }

        $info = Service_Nj594_Orgs::internet_lookup($q);

        if ($info === NULL)
        {
            $this->redirect('orgs?q='.rawurlencode($q));

            return;
        }

        // Номер → сразу спрашиваем ФНС и сохраняем найденное в справочник.
        if ( ! empty($info['is_num']) AND ! empty($info['num']))
        {
            $res = Service_Nj594_Orgs::refresh($info['num']);

            if (isset($res['card']['ogrn']))
            {
                $this->redirect('orgs/'.(string) $res['card']['ogrn']);
            }

            $this->redirect('orgs?q='.rawurlencode($q).'&flash='.urlencode((string) ($res['error'] ?? 'ФНС не вернула карточку')));

            return;
        }

        // Название/домен → «Разведка контрагента» (сквозной поиск в интернете).
        if (class_exists('Service_Nj635_Scout'))
        {
            $this->redirect('scout?q='.rawurlencode($q));
        }

        $this->redirect('orgs?q='.rawurlencode($q));
    }

    /** GET /orgs/<ogrn> — карточка организации. */
    public function action_view()
    {
        $ogrn = preg_replace('/\D/', '', (string) $this->request->param('ogrn'));
        $org  = Service_Nj594_Orgs::by_ogrn($ogrn);

        if ($org === NULL)
        {
            throw HTTP_Exception::factory(404, 'Организация не найдена в справочнике');
        }

        $is_admin = Auth::instance()->logged_in('admin');

        $this->set_title(($org['short_name'] ?: $org['full_name'] ?: $org['ogrn']).' — Справочник организаций');
        $this->set_description('Карточка организации: реквизиты ЕГРЮЛ/ЕГРИП (ОГРН '.$org['ogrn'].', ИНН '.$org['inn'].'), адрес, ОКВЭД, статус и связанные материалы платформы.');

        // Онтология (nj607): организация из справочника попадает в общий словарь
        // сервисов (ленивая регистрация: по одной карточке за просмотр).
        if (class_exists('Service_Nj607_Ontology'))
        {
            try
            {
                Service_Nj607_Ontology::remember(
                    (string) ($org['short_name'] ?: $org['full_name']), [
                    'kind'   => 'org',
                    'ogrn'   => (string) $org['ogrn'],
                    'inn'    => (string) $org['inn'],
                    'module' => 'org',
                    'code'   => (string) $org['ogrn'],
                    'path'   => '/orgs/'.rawurlencode((string) $org['ogrn']),
                    'label'  => 'Организация (ЕГРЮЛ)',
                    'score'  => 90,
                    'source' => 'nj594',
                ]);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj594: онтология организации: :e', [':e' => $e->getMessage()]);
            }
        }

        // «Проверка компании» (nj635): сигналы по реестру и вакансии компании —
        // та же логика, что на публичной странице /check, без второго источника правды.
        $check = ['signals' => [], 'vacancies' => ['total' => 0, 'active' => 0, 'items' => []]];

        if (class_exists('Service_Nj635_Card'))
        {
            try
            {
                $keys = Service_Nj635_Card::vacancy_keys($org);

                if ($keys AND class_exists('Service_Nj642_Vacancies'))
                {
                    $check['vacancies'] = Service_Nj642_Vacancies::for_company_keys($keys, 5);
                }

                $check['signals'] = Service_Nj635_Card::signals($org, $check['vacancies']);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj594: проверка организации: :e', [':e' => $e->getMessage()]);
            }
        }

        $this->template->content = View::factory('594/view', [
            'o'        => $org,
            'links'    => Service_Nj594_Orgs::links_for_org((int) $org['id']),
            'check'    => $check,
            'is_admin' => $is_admin,
            'csrf'     => Security::token(),
        ]);
    }
}
