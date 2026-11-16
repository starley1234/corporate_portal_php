<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prl394_Replies extends Controller_Prl39x_Base
{
    public function action_index()
    {
        $this->_require('prl394.view');
        $this->set_title('Ответы на письма');
        $this->set_description('Черновики по шаблонам, утверждение и ручная отправка');
        $this->_nav('/replies');

        $this->template->content = View::factory('394/index', [
            'flash' => $this->_flash(),
            'csrf' => $this->_csrf(),
            'stats' => Service_Prl394_Drafts::stats(),
            'drafts' => Service_Prl394_Drafts::drafts(100),
            'templates' => Service_Prl394_Drafts::templates(),
            'can_draft' => $this->can('prl394.draft'),
            'can_approve' => $this->can('prl394.approve'),
            'can_send' => $this->can('prl394.send'),
        ]);
    }

    public function action_document()
    {
        $this->_require('prl394.view');
        $document_id = (int) $this->request->param('id');
        $document = Service_Prl391_Documents::document($document_id, $this->_actor());
        if ( ! $document)
        {
            throw HTTP_Exception::factory(404, 'Документ не найден');
        }

        $this->set_title('Ответ: '.$document['reg_no']);
        $this->set_description($document['subject']);
        $this->_nav('/replies');

        $this->template->content = View::factory('394/document', [
            'flash' => $this->_flash(),
            'csrf' => $this->_csrf(),
            'doc' => $document,
            'drafts' => Service_Prl394_Drafts::drafts_for_document($document_id),
            'templates' => Service_Prl394_Drafts::templates(),
            'can_draft' => $this->can('prl394.draft'),
            'can_approve' => $this->can('prl394.approve'),
            'can_send' => $this->can('prl394.send'),
            'email_enabled' => (bool) Service_Prl394_Drafts::config('email_enabled', FALSE),
        ]);
    }

    public function action_create()
    {
        $this->_require('prl394.draft');
        $document_id = (int) $this->request->param('id');

        $this->_post(function () use ($document_id) {
            $template_id = (int) $this->request->post('template_id');
            $draft_id = Service_Prl394_Drafts::queue_draft($document_id, $template_id ?: NULL, $this->_actor());

            return 'Черновик #'.$draft_id.' поставлен в очередь';
        }, '/replies/document/'.$document_id);
    }

    public function action_save()
    {
        $this->_require('prl394.draft');
        $draft_id = (int) $this->request->param('id');

        $this->_post(function () use ($draft_id) {
            Service_Prl394_Drafts::save_body($draft_id, (string) $this->request->post('body'), $this->_actor());

            return 'Черновик сохранён';
        }, $this->_back('/replies'));
    }

    public function action_approve()
    {
        $this->_require('prl394.approve');
        $draft_id = (int) $this->request->param('id');

        $this->_post(function () use ($draft_id) {
            Service_Prl394_Drafts::approve($draft_id, $this->_actor());

            return 'Черновик утверждён — теперь его можно отправить';
        }, $this->_back('/replies'));
    }

    public function action_reject()
    {
        $this->_require('prl394.approve');
        $draft_id = (int) $this->request->param('id');

        $this->_post(function () use ($draft_id) {
            Service_Prl394_Drafts::reject($draft_id, $this->_actor());

            return 'Черновик отклонён';
        }, $this->_back('/replies'));
    }

    public function action_send()
    {
        $this->_require('prl394.send');
        $draft_id = (int) $this->request->param('id');

        $this->_post(function () use ($draft_id) {
            Service_Prl394_Drafts::send($draft_id, $this->_actor());

            return 'Письмо отправлено корреспонденту';
        }, $this->_back('/replies'));
    }
}
