<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Контроль качества» (nj639_qc): /qc
 *
 * Ручной режим (фото изделия), правила с вариантами, выходная JSON-схема,
 * источники авторежима (камера/FTP/очередь/вебхук) и история прогонов.
 */
class Controller_Nj639_Qc extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Контроль качества изделий: VLM сверяет кадр с эталоном по вашему правилу');
        $this->set_description(
            'Задайте эталонные изображения, правило поиска брака и выходную JSON-схему ответа. Проверяйте фото изделия вручную или автоматически: IP-камера (Snapshot/RTSP/ONVIF), FTP-папка, очередь (Kafka/RabbitMQ/MQTT), вебхук от датчика. VLM анализирует кадр и возвращает вердикт, проверенный схемой.');
        $this->set_keywords('контроль качества брак дефект изделие эталон vlm камера rtsp onvif ftp kafka rabbitmq mqtt json схема верификация снимок дефектоскопия');

    }

    public function action_index()
    {
        $service = class_exists('Service_Nj639_Qc') ? 'Service_Nj639_Qc' : NULL;
        $logged  = Gate::logged_in();
        $uid     = (string) Visitor::uid();

        $this->template->content = View::factory('639/index', [
            // Гость видит плашку входа вместо формы (владелец, 08.10.2026):
            // кадр уходит в vision-модель, и очередь не должна доставаться
            // анонимам. Правила и источники ему тоже не показываем.
            'logged'     => $logged,
            'rules'      => ($service AND $logged) ? Service_Nj639_Qc::rules_of($uid) : [],
            'sources'    => ($service AND $logged) ? Service_Nj639_Qc::sources_of($uid) : [],
            'recent'     => ($service AND $logged) ? Service_Nj639_Qc::recent_runs($uid, 12) : [],
            'kinds'      => $service ? Service_Nj639_Qc::source_kinds() : [],
            'triggers'   => $service ? Service_Nj639_Qc::source_triggers() : [],
            'rate_text'  => $service ? Service_Nj639_Qc::rate_limit_text() : '5 операций в минуту',
            'max_img_mb' => $service ? (string) Service_Nj639_Qc::cfg('max_image_mb', 15) : '15',
            'csrf'       => Security::token(),
        ]);
    }
}
