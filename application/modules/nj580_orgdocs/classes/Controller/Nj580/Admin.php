<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление «Документов организации»: пакеты и учёт ресурсов воркера.
 * Действия (остановить/удалить/вернуть) НЕ делают redirect — сразу рендерят
 * обновлённый список со статус-строкой: результат виден в том же ответе.
 */
class Controller_Nj580_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Документы · управление — пакеты и ресурсы');

        // то же верхнее меню, что у остальных разделов модуля (/docs, /docs/wizard)
        $this->set_module_nav([
            ['title' => 'Мастер пакета',   'href' => '/docs/wizard', 'icon' => 'fas fa-list-check'],
            ['title' => 'Подробная форма', 'href' => '/docs/custom', 'icon' => 'fas fa-sliders'],
        ]);

    }

    public function action_index()
    {
        $this->_render_admin();
    }

    /** POST /nj580/admin/cancel/<id> — остановить/отменить сборку пакета. */
    public function action_cancel()
    {
        $res = $this->_check_csrf() ?: Service_Nj580_Orgdocs::cancel((int) $this->request->param('id'));
        $this->_render_admin($this->_flash_of($res, 'Сборка пакета отменена'));
    }

    /** POST /nj580/admin/delete/<id> — удаление пакета (mode=soft|hard|restore). */
    public function action_delete()
    {
        $mode = (string) $this->request->post('mode');

        if ($mode === 'hard')
        {
            $res = $this->_check_csrf() ?: Service_Nj580_Orgdocs::admin_delete((int) $this->request->param('id'), TRUE);
        }
        elseif ($mode === 'restore')
        {
            $res = $this->_check_csrf() ?: Service_Nj580_Orgdocs::admin_restore((int) $this->request->param('id'));
        }
        else
        {
            $res = $this->_check_csrf() ?: Service_Nj580_Orgdocs::admin_delete((int) $this->request->param('id'), FALSE);
        }

        $this->_render_admin($this->_flash_of($res, 'Готово'));
    }

    /** CSRF форм администратора: сбой не роняет запрос (вкладка могла быть открыта давно). */
    protected function _check_csrf()
    {
        if (Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            return NULL;
        }

        return ['error' => 'Страница списка была открыта давно — обновите её (F5) и нажмите кнопку ещё раз'];
    }

    protected function _flash_of(array $res, $ok_text)
    {
        return isset($res['error'])
            ? ['text' => 'Не удалось: '.$res['error'], 'type' => 'err']
            : ['text' => (string) Arr::get($res, 'ok', $ok_text), 'type' => 'ok'];
    }

    /** Список пакетов + учёт; $flash — статус последнего действия. */
    protected function _render_admin(?array $flash = NULL)
    {
        $this->template->content = View::factory('580/admin', [
            'packages' => Service_Nj580_Orgdocs::admin_list(50),
            'usage'    => Service_Nj580_Orgdocs::usage_summary(),
            'recent'   => Service_Nj580_Orgdocs::usage_recent(30),
            'flash'    => $flash,
        ]);
    }
}
