<?php defined('SYSPATH') or die('No direct script access.');

/**
 * UI prx399_docmerge. Права: просмотр prx399.view, действия prx399.run
 * (deny by default, ключи объявлены в application/config/acl.php).
 * Все POST проверяют CSRF; сценарии — в Service_Prx399_*, SQL — в моделях.
 */
class Controller_Prx399_Ui extends Controller_Base {

    public function before()
    {
        parent::before();
        if ( ! $this->can('prx399.view'))
        {
            throw HTTP_Exception::factory(403, 'Слияние договоров доступно администратору');
        }
        $this->response->headers('Cache-Control', 'no-store');
        $this->set_title('Слияние договоров (DocMergeAI)');
    }

    /** Список пакетов + форма создания («Zero-Config»: только файлы и роль). */
    public function action_index()
    {
        $flash = Session::instance()->get_once('prx399_flash', ['text' => '']);
        $service = new Service_Prx399_Contracts;

        if ($this->request->method() === Request::POST)
        {
            $this->_guard_csrf();
            try
            {
                $contract = $service->create($this->actor(), $this->request->post(), $_FILES);
                Session::instance()->set('prx399_flash', ['type' => 'ok', 'text' => 'Пакет создан. Запустите пайплайн, когда порядок ДС верный.']);
                HTTP::redirect('/prx399_docmerge/contract/'.$contract['id'], 303);
            }
            catch (Prx399_Error $e)
            {
                $flash = ['type' => 'err', 'text' => $this->_say($e->getMessage())];
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::ERROR, 'prx399 create error_type=:type detail=:detail',
                    [':type' => get_class($e), ':detail' => $e->getMessage()]);
                $flash = ['type' => 'err', 'text' => 'Сервис недоступен: '.mb_substr($e->getMessage(), 0, 160)
                    .' (подробности — в журнале).'];
            }
        }

        $this->template->content = View::factory('prx399/index', [
            'contracts' => $service->contracts(),
            'limits' => $service->limits(),
            'focus' => $this->_limits()['focus'],
            'flash' => $flash,
            'csrf' => Security::token(),
            'can_run' => $this->can('prx399.run'),
        ]);
    }

    /** Карточка пакета: пайплайн, файлы, карточка сделки, риски, diff-дерево. */
    public function action_contract()
    {
        $id = (int) $this->request->param('id');
        $data = (new Service_Prx399_Contracts)->contract($this->actor(), $id);
        $contract = $data['contract'];

        $tree = isset($contract['doc_tree']) && is_string($contract['doc_tree'])
            ? json_decode($contract['doc_tree'], TRUE) : NULL;
        $card = isset($contract['deal_card']) && is_string($contract['deal_card'])
            ? json_decode($contract['deal_card'], TRUE) : NULL;

        $this->template->content = View::factory('prx399/contract', [
            'contract' => $contract,
            'files' => $data['files'],
            'deltas' => $data['deltas'],
            'risks' => $data['risks'],
            'nodes' => is_array($tree) ? ($tree['nodes'] ?? []) : [],
            'card' => is_array($card) ? $card : NULL,
            'show' => $this->request->query('show') === 'changes' ? 'changes' : 'all',
            'focus' => $this->_limits()['focus'],
            'statuses' => $this->_limits()['statuses'],
            'file_statuses' => $this->_limits()['file_statuses'],
            'csrf' => Security::token(),
            'can_run' => $this->can('prx399.run'),
        ]);
    }

    /** Запуск пайплайна (draft/failed → parsing). */
    public function action_launch()
    {
        $this->_action_post(function ($service, $id)
        {
            $service->launch($this->actor(), $id);

            return ['type' => 'ok', 'text' => 'Пайплайн запущен: распознавание и дельты ДС пошли в очередь воркера.'];
        });
    }

    /** Хронология ДС: swap ординалов соседей. */
    public function action_move()
    {
        $dir = $this->request->post('dir') === 'up' ? 'up' : 'down';
        $this->_action_post(function ($service, $id) use ($dir)
        {
            $service->move_ds($this->actor(), $id, $dir);

            return ['type' => 'ok', 'text' => 'Порядок ДС обновлён.'];
        });
    }

    /** Повтор файла после сбоя воркера. */
    public function action_retry()
    {
        $this->_action_post(function ($service, $id)
        {
            $service->retry_file($this->actor(), $id);

            return ['type' => 'ok', 'text' => 'Файл снова в очереди.'];
        });
    }

    public function action_delete()
    {
        $this->_action_post(function ($service, $id)
        {
            $service->delete($this->actor(), $id);
            Session::instance()->set('prx399_flash', ['type' => 'ok', 'text' => 'Пакет удалён вместе с файлами и результатами.']);
            HTTP::redirect('/prx399_docmerge', 303);
        }, FALSE);
    }

    /** Скачивание актуальной редакции .txt (annulled узлы не выводятся). */
    public function action_export()
    {
        $id = (int) $this->request->param('id');
        $data = (new Service_Prx399_Contracts)->contract($this->actor(), $id);
        $contract = $data['contract'];
        if (empty($contract['doc_tree']))
        {
            throw HTTP_Exception::factory(404, 'Редакция ещё не собрана');
        }

        $tree = json_decode((string) $contract['doc_tree'], TRUE);
        $text = Service_Prx399_Merge::render_text($tree['nodes'] ?? []);
        if (ob_get_level())
        {
            ob_end_clean();
        }
        $this->response->headers('Content-Type', 'text/plain; charset=utf-8');
        $this->response->headers('Content-Disposition', 'attachment; filename="contract-'.$id.'-consolidated.txt"');
        $this->response->headers('Cache-Control', 'no-store');
        $this->response->headers('X-Content-Type-Options', 'nosniff');
        $this->response->send_headers();
        echo $text;
        exit;
    }

    // ------------------------------------------------------------ helpers

    private function _action_post(callable $scenario, $back_to_contract = TRUE)
    {
        $this->_guard_csrf();
        $id = (int) $this->request->param('id');
        try
        {
            $flash = $scenario(new Service_Prx399_Contracts, $id);
            if ($flash !== NULL)
            {
                Session::instance()->set('prx399_flash', $flash);
            }
        }
        catch (Prx399_Error $e)
        {
            Session::instance()->set('prx399_flash', ['type' => 'err', 'text' => $this->_say($e->getMessage())]);
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx399 action error_type=:type', [':type' => get_class($e)]);
            Session::instance()->set('prx399_flash', ['type' => 'err', 'text' => 'Сервис недоступен, попробуйте ещё раз.']);
        }
        HTTP::redirect($back_to_contract ? '/prx399_docmerge/contract/'.$id : '/prx399_docmerge', 303);
    }

    private function _guard_csrf()
    {
        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Сессия формы устарела');
        }
    }

    /** Машинные коды ошибок → человеческие фразы (один словарь на UI и API). */
    private function _say($code)
    {
        $map = [
            'title_too_long' => 'Название слишком длинное.',
            'bad_focus' => 'Неизвестная роль аудита.',
            'base_required' => 'Загрузите один базовый договор (PDF).',
            'too_many_files' => 'Слишком много файлов в пакете.',
            'pdf_required' => 'Файлы пакета — только PDF.',
            'file_too_large' => 'Файл слишком большой.',
            'upload_error' => 'Загрузка не удалась — повторите.',
            'storage_unavailable' => 'Хранилище недоступно: не удалось создать каталог пакета. Проверьте права на storage/ на сервере или задайте PRX399_STORAGE_DIR в .env.',
            'contract_not_found' => 'Пакет не найден.',
            'not_found' => 'Объект не найден.',
            'wrong_state' => 'Действие недоступно в текущем состоянии пайплайна.',
            'bad_focus:' => 'Неизвестная роль аудита.',
        ];
        if (isset($map[$code]))
        {
            return $map[$code];
        }
        if (strpos($code, 'bad_anchor:') === 0)
        {
            return 'Модель ссылается на несуществующий пункт ('.substr($code, 11).') — дельта отклонена.';
        }

        return 'Ошибка: '.$code;
    }

    private function _limits()
    {
        return Kohana::$config->load('prx399_docmerge');
    }
}
