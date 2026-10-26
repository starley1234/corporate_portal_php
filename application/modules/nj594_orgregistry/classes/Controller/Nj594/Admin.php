<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление справочника организаций (nj594): /orgs/admin.
 * Действия НЕ делают redirect — рендерят обновлённый список со статус-строкой
 * (результат виден в том же ответе, как в nj580). Удаление — в 2 клика (armed).
 */
class Controller_Nj594_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Справочник организаций · управление');

        // без module_nav: чипы «Справочник»/«Управление» собирает nj110 header_nav()
        // из манифеста — второй ряд с теми же ссылками = дубли

        Service_Nj594_Orgs::seed();
    }

    public function action_index()
    {
        $this->_render();
    }

    /** POST /orgs/admin/refresh/<id> — обновить карточку из ФНС. */
    public function action_refresh()
    {
        $err = $this->_check_csrf();

        if ($err !== TRUE)
        {
            $this->_render('Сессия устарела — повторите действие', TRUE);

            return;
        }

        $org = $this->_find((int) $this->request->param('id'));

        if ($org === NULL)
        {
            $this->_render('Карточка не найдена', TRUE);

            return;
        }

        $res = Service_Nj594_Orgs::refresh($org['ogrn'], TRUE);
        $this->_render(isset($res['ok'])
            ? 'Карточка '.$org['ogrn'].' обновлена из ФНС'.(isset($res['card']['full_name']) ? ': '.$res['card']['full_name'] : '')
            : (string) ($res['error'] ?? 'Не удалось обновить карточку'), isset($res['error']));
    }

    /** POST /orgs/admin/fetch — найти и загрузить карточку по ОГРН/ИНН из ФНС. */
    public function action_fetch()
    {
        $err = $this->_check_csrf();

        if ($err !== TRUE)
        {
            $this->_render('Сессия устарела — повторите действие', TRUE);

            return;
        }

        $num = preg_replace('/\D/', '', (string) Arr::get($_POST, 'ogrn', ''));

        if ($num === '' OR ( ! Service_Nj594_Orgs::ogrn_valid($num) AND ! Service_Nj594_Orgs::inn_valid($num)))
        {
            $this->_render('Введите корректный ОГРН/ОГРНИП (13/15 цифр) или ИНН (10/12 цифр)', TRUE);

            return;
        }

        $res = Service_Nj594_Orgs::refresh($num, TRUE);
        $this->_render(isset($res['ok'])
            ? 'Карточка загружена из ФНС: '.(string) ($res['card']['full_name'] ?? $num)
            : (string) ($res['error'] ?? 'ФНС не вернула карточку'), isset($res['error']));
    }

    /** POST /orgs/admin/delete/<id> — удалить карточку (связи уйдут каскадом). */
    public function action_delete()
    {
        $err = $this->_check_csrf();

        if ($err !== TRUE)
        {
            $this->_render('Сессия устарела — повторите действие', TRUE);

            return;
        }

        $org = $this->_find((int) $this->request->param('id'));

        if ($org === NULL)
        {
            $this->_render('Карточка не найдена', TRUE);

            return;
        }

        try
        {
            DB::query(NULL, 'DELETE FROM nj_594_orgs WHERE id = '.(int) $org['id'])->execute();
            $this->_render('Карточка '.$org['ogrn'].' ('.$org['short_name'].') удалена вместе со связями');
        }
        catch (Throwable $e)
        {
            $this->_render('Не удалось удалить: '.$e->getMessage(), TRUE);
        }
    }

    /** POST /orgs/admin/findname — найти кандидатов в ФНС по названию. */
    public function action_findname()
    {
        $err = $this->_check_csrf();
        $q   = trim((string) Arr::get($_POST, 'name', ''));
        $candidates = [];

        if ($err !== TRUE)
        {
            $this->_render('Сессия устарела — повторите поиск', TRUE);

            return;
        }

        if ($q === '')
        {
            $this->_render('Введите название организации или его часть', TRUE);

            return;
        }

        $quota      = Service_Nj594_Orgs::fns_quota();
        $candidates = Service_Nj594_Orgs::fns_search($q, 10);
        $msg        = $candidates
            ? 'ФНС вернула кандидатов по запросу «'.$q.'» — проверьте и сохраните нужного'
            : ($quota['ok']
                ? 'По запросу «'.$q.'» ФНС ничего не вернула (сервис занят или написание иное — попробуйте короче/латиницей)'
                : (string) $quota['message']);

        $this->_render($msg, $candidates ? FALSE : TRUE,
            ['name_q' => $q, 'candidates' => $candidates]);
    }

    /** POST /orgs/admin/seed — заполнить демо-данными (если пусто). */
    public function action_seed()
    {
        $err = $this->_check_csrf();

        if ($err !== TRUE)
        {
            $this->_render('Сессия устарела — повторите действие', TRUE);

            return;
        }

        $n = 0;

        foreach (Service_Nj594_Orgs::seed_rows() as $f)
        {
            if (Service_Nj594_Orgs::by_ogrn($f['ogrn']) === NULL)
            {
                $n += Service_Nj594_Orgs::upsert($f['ogrn'], $f, 'seed') ? 1 : 0;
            }
        }

        $this->_render($n ? 'Добавлено демо-карточек: '.$n : 'Все демо-карточки уже в справочнике');
    }

    protected function _find($id)
    {
        $row = Database::instance()->query(Database::SELECT,
            'SELECT * FROM nj_594_orgs WHERE id = '.(int) $id.' LIMIT 1', FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    protected function _check_csrf()
    {
        return Security::check((string) Arr::get($_POST, 'csrf', '')) ? TRUE : 'Сессия устарела';
    }

    protected function _render($flash = '', $flash_err = FALSE)
    {
        $this->template->content = View::factory('594/admin', [
            'rows'      => Service_Nj594_Orgs::all(200),
            'counts'    => Service_Nj594_Orgs::counts(),
            'fns'       => [
                'stats'   => Service_Nj594_Orgs::fns_stats(24),
                'recent'  => Service_Nj594_Orgs::fns_recent(10),
                'quota'   => Service_Nj594_Orgs::fns_quota(),
                'limit'   => Service_Nj594_Orgs::fns_limit_text(),
            ],
            'flash'     => (string) $flash,
            'flash_err' => $flash_err,
            'csrf'      => Security::token(),
        ]);
    }
}
