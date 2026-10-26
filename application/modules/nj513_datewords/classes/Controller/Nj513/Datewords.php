<?php defined('SYSPATH') or die('No direct script access.');

/** Страница преобразования даты и диапазона для документов. */
class Controller_Nj513_Datewords extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->set_title('Дата прописью онлайн: для договора, приказа и акта');
        $this->set_description('Напишите дату словами в правильном падеже, получите формат «01» сентября 2026 г. или переведите диапазон дат. Бесплатно и без сохранения введённых дат.');
        $this->set_keywords('дата прописью онлайн дата словами первое сентября 2026 договор диапазон дат');
    }

    public function action_index()
    {
        $query = $this->request->query();
        $date = isset($query['date']) && is_scalar($query['date']) ? mb_substr((string) $query['date'], 0, 24) : '';
        $end = isset($query['end']) && is_scalar($query['end']) ? mb_substr((string) $query['end'], 0, 24) : '';
        $result = $date !== '' ? Service_Nj513_Datewords::convert($date) : NULL;
        $range = ($date !== '' && $end !== '') ? Service_Nj513_Datewords::range($date, $end) : NULL;
        if ($result === NULL AND $date !== '') $date = '';
        if ($range === NULL AND $end !== '') $end = '';

        $this->template->content = View::factory('513/index', [
            'date' => $date, 'end' => $end, 'result' => $result, 'range' => $range,
            'today' => date('Y-m-d'),
        ]);
    }
}
