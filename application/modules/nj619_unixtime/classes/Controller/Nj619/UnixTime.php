<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Unix-время» (модуль NJ-619): /unixtime — timestamp ↔ дата.
 */
class Controller_Nj619_UnixTime extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Unix timestamp конвертер онлайн — timestamp в дату и обратно');
        $this->set_description(
            'Перевести Unix timestamp (секунды или миллисекунды) в дату и обратно. Текущее время, часовые пояса, строка ISO 8601 — мгновенно и без регистрации.');
        $this->set_keywords('timestamp unix unixtime конвертер дата время iso лета обращения онлайн');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $ts    = isset($query['ts']) ? $query['ts'] : '';
        $tz    = isset($query['tz']) ? $query['tz'] : 'Europe/Moscow';

        $result = $ts !== '' ? Service_Nj619_UnixTime::to_date($ts, $tz) : NULL;

        $this->template->content = View::factory('619/index', [
            'ts'        => (string) $ts,
            'tz'        => (string) $tz,
            'now'       => Service_Nj619_UnixTime::now(),
            'timezones' => Service_Nj619_UnixTime::timezones(),
            'result'    => $result,
        ]);
    }
}
