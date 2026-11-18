<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx390_Inbox extends Controller_Prx39x_Base
{
    public function action_index()
    {
        $this->_require('prx390.view');
        $this->set_title('Входящие документы');
        $this->set_description('Приём PDF из папки, дедупликация и распознавание текста');
        $this->_nav('/inbox');

        $filters = [
            'status' => (string) $this->request->query('status'),
            'q' => (string) $this->request->query('q'),
            'limit' => 50,
        ];
        $stats = Service_Prx390_Intake::stats();
        $channels = Service_Prx390_Intake::channels();
        $inbox = Service_Prx390_Intake::inbox_files();

        $this->template->content = View::factory('390/index', [
            'flash' => $this->_flash(),
            'csrf' => $this->_csrf(),
            'can_manage' => $this->can('prx390.manage'),
            'stats' => $stats,
            'messages' => Service_Prx390_Intake::messages($filters),
            'filters' => $filters,
            'channel' => $channels[0] ?? NULL,
            'inbox_count' => count($inbox),
            'inbox_sample' => array_slice(array_map('basename', $inbox), 0, 8),
            'inbox_dir' => Service_Prx390_Intake::path('inbox_dir'),
        ]);
    }

    public function action_scan()
    {
        $this->_require('prx390.manage');

        $this->_post(function () {
            $report = Service_Prx390_Intake::scan_folder($this->_actor());

            return 'Папка: найдено '.$report['found'].', принято '.$report['accepted']
                .', дублей '.$report['duplicates'].', ошибок '.$report['errors'];
        }, '/inbox');
    }

    public function action_upload()
    {
        $this->_require('prx390.manage');

        $this->_post(function () {
            if (empty($_FILES['file']))
            {
                throw new InvalidArgumentException('Выберите PDF-файл');
            }

            $res = Service_Prx390_Intake::receive_upload($_FILES['file'], $this->_actor());

            return $res['state'] === 'duplicate'
                ? 'Этот файл уже принят (#' . $res['message_id'] . ')'
                : 'Файл принят: входящий #' . $res['message_id'] . ', поставлено в очередь';
        }, '/inbox');
    }

    public function action_retry()
    {
        $this->_require('prx390.manage');
        $id = (int) $this->request->param('id');

        $this->_post(function () use ($id) {
            $res = Service_Prx390_Intake::retry((int) $id, $this->_actor());

            return is_int($res) ? 'Задача распознавания #'.$res.' поставлена' : 'Текст уже готов — документ зарегистрирован';
        }, '/inbox');
    }
}
