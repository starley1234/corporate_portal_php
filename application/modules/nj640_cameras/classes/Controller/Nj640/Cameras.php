<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Камеры цеха» (nj640_cameras): /cameras
 *
 * Сценарии (трудовая дисциплина / СИЗ / загрузка оборудования), камеры
 * (источник кадра + расписание + часы работы), журнал событий с
 * подтверждением/ложной тревогой и ручная проверка кадра.
 */
class Controller_Nj640_Cameras extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Камеры цеха: VLM-контроль дисциплины, СИЗ и загрузки оборудования');
        $this->set_description(
            'Подключите камеру цеха или офиса и выберите сценарий контроля: трудовая дисциплина, средства индивидуальной защиты (каска, жилет, очки) или загрузка оборудования. Кадр снимается по триггеру (таймер/вебхук), анализируется VLM и проверяется JSON-схемой; нарушения попадают в журнал событий с уведомлениями, подтверждением и ложными тревогами.');
        $this->set_keywords('камера видеонаблюдение цех офис дисциплина сиз каска жилет безопасность загрузка оборудования станок простой vlm журнал событий уведомления снимок rtsp onvif');

    }

    public function action_index()
    {
        $service = class_exists('Service_Nj640_Cameras') ? 'Service_Nj640_Cameras' : NULL;
        $logged  = Gate::logged_in();
        $uid     = (string) Visitor::uid();

        $this->template->content = View::factory('640/index', [
            // Гость видит плашку входа вместо формы (владелец, 08.10.2026):
            // кадр уходит в vision-модель, а события камер — данные клиента.
            'logged'     => $logged,
            'scenes'     => ($service AND $logged) ? Service_Nj640_Cameras::scenes_of($uid) : [],
            'cameras'    => ($service AND $logged) ? Service_Nj640_Cameras::cameras_of($uid) : [],
            'events'     => ($service AND $logged) ? Service_Nj640_Cameras::events_of($uid, 20) : [],
            'recent'     => ($service AND $logged) ? Service_Nj640_Cameras::recent_runs($uid, 12) : [],
            'topics'     => $service ? Service_Nj640_Cameras::topics() : [],
            'kinds'      => $service ? Service_Nj640_Cameras::camera_kinds() : [],
            'triggers'   => $service ? Service_Nj640_Cameras::camera_triggers() : [],
            'rate_text'  => $service ? Service_Nj640_Cameras::rate_limit_text() : '5 операций в минуту',
            'max_img_mb' => $service ? (string) Service_Nj640_Cameras::cfg('max_image_mb', 15) : '15',
            'csrf'       => Security::token(),
        ]);
    }
}
