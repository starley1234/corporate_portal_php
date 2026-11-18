<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx391_Documents extends Controller_Prx39x_Base
{
    public function action_index()
    {
        $this->_require('prx391.view');
        $this->set_title('Реестр входящих документов');
        $this->set_description('Карточки, реквизиты, корреспонденты и сроки');
        $this->_nav('/documents');

        $filters = [
            'q' => trim((string) $this->request->query('q')),
            'status' => (string) $this->request->query('status'),
            'type' => (string) $this->request->query('type'),
            'page' => max(1, (int) $this->request->query('page')),
        ];

        $list = Service_Prx391_Documents::list_documents($filters, $this->_actor());

        $this->template->content = View::factory('391/index', [
            'flash' => $this->_flash(),
            'csrf' => $this->_csrf(),
            'can_edit' => $this->can('prx391.edit'),
            'stats' => Service_Prx391_Documents::stats($this->_actor()),
            'types' => Service_Prx391_Documents::types(),
            'rows' => $list['rows'],
            'total' => $list['total'],
            'page' => $list['page'],
            'pages' => $list['pages'],
            'filters' => $filters,
            'history' => Service_Prx391_Documents::recent_history(12, $this->_actor()),
            'ai_stats' => class_exists('Service_Prx392_Analysis') ? Service_Prx392_Analysis::stats() : [],
            'sla_stats' => class_exists('Service_Prx393_Workflow') ? Service_Prx393_Workflow::stats() : [],
        ]);
    }

    public function action_show()
    {
        $this->_require('prx391.view');
        $id = (int) $this->request->param('id');
        $document = Service_Prx391_Documents::document($id, $this->_actor());
        if ( ! $document)
        {
            throw HTTP_Exception::factory(404, 'Документ не найден');
        }

        $this->set_title('Документ '.$document['reg_no']);
        $this->set_description($document['subject']);
        $this->_nav('/documents');

        $analysis = NULL;
        $runs = [];
        $deadline = NULL;
        $drafts = [];
        if (class_exists('Service_Prx392_Analysis'))
        {
            $analysis = Service_Prx392_Analysis::latest_suggestion($id);
            $runs = Service_Prx392_Analysis::runs_for_document($id, 5);
        }
        if (class_exists('Service_Prx393_Workflow'))
        {
            $deadline = Service_Prx393_Workflow::deadline_for($id);
        }
        if (class_exists('Service_Prx394_Drafts'))
        {
            $drafts = Service_Prx394_Drafts::drafts_for_document($id);
        }

        $this->template->content = View::factory('391/show', [
            'flash' => $this->_flash(),
            'csrf' => $this->_csrf(),
            'doc' => $document,
            'types' => Service_Prx391_Documents::types(),
            'correspondents' => Service_Prx391_Documents::correspondents(),
            'statuses' => Service_Prx391_Documents::statuses(),
            'priorities' => Service_Prx391_Documents::priorities(),
            'sensitivities' => Service_Prx391_Documents::sensitivities(),
            'can_edit' => $this->can('prx391.edit'),
            'can_run_ai' => $this->can('prx392.run'),
            'can_route' => $this->can('prx393.manage'),
            'can_draft' => $this->can('prx394.draft'),
            'analysis' => $analysis,
            'runs' => $runs,
            'deadline' => $deadline,
            'drafts' => $drafts,
        ]);
    }

    public function action_save()
    {
        $this->_require('prx391.edit');
        $id = (int) $this->request->param('id');

        $this->_post(function () use ($id) {
            $input = [];
            foreach (['subject', 'sender_name', 'sender_email', 'sender_inn', 'outbound_no',
                'outbound_date', 'contract_no', 'amount', 'summary', 'priority', 'sensitivity', 'status'] as $field)
            {
                if ($this->request->post($field) !== NULL)
                {
                    $input[$field] = (string) $this->request->post($field);
                }
            }
            foreach (['type_id', 'correspondent_id'] as $field)
            {
                if ($this->request->post($field) !== NULL)
                {
                    $input[$field] = (int) $this->request->post($field);
                }
            }

            $res = Service_Prx391_Documents::save_card($id, $input, $this->_actor());

            return $res['changed'] ? 'Сохранено полей: '.$res['changed'] : 'Изменений нет';
        }, '/documents/'.$id);
    }

    public function action_close()
    {
        $this->_require('prx391.edit');
        $id = (int) $this->request->param('id');

        $this->_post(function () use ($id) {
            Service_Prx391_Documents::close($id, $this->_actor());

            return 'Документ закрыт';
        }, '/documents/'.$id);
    }

    public function action_reopen()
    {
        $this->_require('prx391.edit');
        $id = (int) $this->request->param('id');

        $this->_post(function () use ($id) {
            Service_Prx391_Documents::reopen($id, $this->_actor());

            return 'Документ возвращён в работу';
        }, '/documents/'.$id);
    }

    public function action_download()
    {
        $this->_require('prx391.view');
        $file_id = (int) $this->request->param('id');
        $file = Service_Prx390_Intake::file($file_id);
        if ( ! $file)
        {
            throw HTTP_Exception::factory(404, 'Файл не найден');
        }

        $message = Service_Prx390_Intake::message((int) $file['message_id']);
        $document_id = $message['document_id'] ?? 0;
        if ( ! $document_id OR ! Service_Prx391_Documents::document((int) $document_id, $this->_actor()))
        {
            throw HTTP_Exception::factory(403, 'Нет доступа к файлу');
        }

        $path = Service_Prx212_Storage::instance()->getFilePath($file_id);
        if ( ! $path OR ! is_file($path))
        {
            throw HTTP_Exception::factory(404, 'Физический файл не найден');
        }

        $this->auto_render = FALSE;
        $this->response
            ->status(200)
            ->headers('Content-Type', $file['mime'] ?: 'application/pdf')
            ->headers('Content-Disposition', 'attachment; filename="'.basename($file['filename']).'"')
            ->headers('Content-Length', (string) filesize($path))
            ->body(file_get_contents($path));

        return $this->response;
    }
}
