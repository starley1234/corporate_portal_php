<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx392_Analysis extends Controller_Prx39x_Base
{
    public function action_index()
    {
        $this->_require('prx392.view');
        $this->set_title('ИИ-разбор входящих');
        $this->set_description('Предложения по карточкам: тип, реквизиты, риск и краткое содержание');
        $this->_nav('/analysis');

        $this->template->content = View::factory('392/index', [
            'flash' => $this->_flash(),
            'csrf' => $this->_csrf(),
            'stats' => Service_Prx392_Analysis::stats(),
            'proposals' => Service_Prx392_Analysis::proposals(50),
            'runs' => Service_Prx392_Analysis::runs(30),
            'profiles' => Service_Prx392_Analysis::profiles(),
            'can_run' => $this->can('prx392.run'),
        ]);
    }

    public function action_run()
    {
        $this->_require('prx392.run');
        $document_id = (int) $this->request->param('id');

        $this->_post(function () use ($document_id) {
            $run_id = Service_Prx392_Analysis::queue_for_document($document_id, $this->_actor());

            return 'Разбор поставлен в очередь (прогон #'.$run_id.')';
        }, '/documents/'.$document_id);
    }

    public function action_accept()
    {
        $this->_require('prx392.run');
        $suggestion_id = (int) $this->request->param('id');
        $suggestion = Service_Prx392_Analysis::suggestion($suggestion_id);
        if ( ! $suggestion)
        {
            throw HTTP_Exception::factory(404, 'Предложение не найдено');
        }
        $document_id = (int) $suggestion['document_id'];

        $this->_post(function () use ($suggestion_id) {
            $overrides = ['overwrite' => (bool) $this->request->post('overwrite')];
            if ($this->request->post('type') !== NULL)
            {
                $overrides['type'] = (string) $this->request->post('type');
            }
            if ($this->request->post('summary') !== NULL)
            {
                $overrides['summary'] = (string) $this->request->post('summary');
            }
            $fields = $this->request->post('fields');
            if (is_array($fields))
            {
                $overrides['fields'] = array_map('strval', $fields);
            }

            $res = Service_Prx392_Analysis::accept($suggestion_id, $overrides, $this->_actor());

            return $res['changed']
                ? 'Применено полей: '.$res['changed'].($res['skipped'] ? '; уже заполнено: '.implode(', ', $res['skipped']) : '')
                : 'Новых значений нет — карточка уже заполнена';
        }, '/documents/'.$document_id);
    }

    public function action_reject()
    {
        $this->_require('prx392.run');
        $suggestion_id = (int) $this->request->param('id');
        $suggestion = Service_Prx392_Analysis::suggestion($suggestion_id);
        if ( ! $suggestion)
        {
            throw HTTP_Exception::factory(404, 'Предложение не найдено');
        }
        $document_id = (int) $suggestion['document_id'];

        $this->_post(function () use ($suggestion_id) {
            Service_Prx392_Analysis::reject($suggestion_id, $this->_actor());

            return 'Предложение отклонено';
        }, '/documents/'.$document_id);
    }
}
