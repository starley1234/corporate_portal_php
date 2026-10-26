<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Админка «Лаборатории LLM» (NJ-645): /nj645/admin — модерация корпуса и
 * обслуживание накопленных знаний.
 *
 * Доступ — только роль admin. Все действия строго POST + CSRF, после действия
 * редирект 303 с flash-сообщением (правило платформы: GET ничего не меняет).
 */
class Controller_Nj645_Admin extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Лаборатория LLM · админ');
        $this->set_module_nav([
            ['title' => 'Лаборатория', 'href' => '/llmlab', 'icon' => 'fas fa-flask'],
            ['title' => 'Модуль · админ', 'href' => '/nj645/admin', 'icon' => 'fas fa-shield-halved', 'roles' => ['admin']],
        ]);
    }

    public function action_index()
    {
        Service_Nj645_Seed::ensure();

        $this->template->content = View::factory('645/admin', [
            'stats'      => Service_Nj645_Store::stats(),
            'pending'    => Service_Nj645_Store::corpus_list('pending', 40),
            'approved'   => Service_Nj645_Store::corpus_list('approved', 20),
            'rejected'   => Service_Nj645_Store::corpus_list('rejected', 10),
            'prompts'    => Service_Nj645_Store::prompts_list(NULL, 100, NULL),
            'confusions' => Service_Nj645_Store::quiz_confusions(10),
            'hardest'    => Service_Nj645_Store::guess_hardest(8),
            'flash'      => (string) $this->request->query('flash'),
            'csrf'       => Security::token(),
        ]);
    }

    /** Одобрить / отклонить / вернуть в очередь текст корпуса. */
    public function action_moderate()
    {
        $id     = (int) $this->request->param('id');
        $status = (string) Arr::get($_POST, 'status', 'approved');

        if ( ! in_array($status, ['approved', 'rejected', 'pending'], TRUE))
        {
            $status = 'pending';
        }

        $this->_guard();

        $result = Service_Nj645_Store::corpus_moderate($id, $status);

        HTTP::redirect('/nj645/admin?flash='.urlencode(
            $status === 'approved'
                ? 'Текст одобрен и добавлен в n-граммную модель.'
                : ($status === 'rejected' ? 'Текст отклонён.' : 'Текст возвращён в очередь.')
        ), 303);
    }

    /** Пересобрать n-граммы с нуля по одобренным текстам. */
    public function action_rebuild()
    {
        $this->_guard();

        $learned = Service_Nj645_Store::rebuild_ngrams();

        HTTP::redirect('/nj645/admin?flash='.urlencode('Модель пересобрана: записей n-грамм — '.$learned), 303);
    }

    /** Опубликовать / снять промпт. */
    public function action_prompt_status()
    {
        $id     = (int) $this->request->param('id');
        $status = (string) Arr::get($_POST, 'status', 'published');

        if ( ! in_array($status, ['published', 'hidden'], TRUE))
        {
            $status = 'published';
        }

        $this->_guard();

        DB::update('nj_645_prompts')
            ->set(['status' => $status, 'updated_at' => DB::expr('now()')])
            ->where('id', '=', $id)
            ->execute();

        HTTP::redirect('/nj645/admin?flash='.urlencode($status === 'published' ? 'Промпт опубликован.' : 'Промпт скрыт.'), 303);
    }

    /** Обрезать семантическую карту до свежих точек. */
    public function action_trim_vectors()
    {
        $this->_guard();

        Service_Nj645_Store::vectors_trim((int) Service_Nj645_Store::cfg('limits.vectors_keep', 400));

        HTTP::redirect('/nj645/admin?flash='.urlencode('Карта обрезана до свежих точек.'), 303);
    }

    protected function _guard()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }
    }
}
