<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Webhook-триггер камеры «Камеры цеха» (nj640_cameras): /cameras/webhook/<token>
 *
 * Внешний триггер (датчик/ПЛК/турникет) по секретному URL запускает проверку
 * привязанного к камере сценария: кадр будет захвачен воркером из настроек
 * камеры (snapshot/rtsp/onvif/http), либо передан прямо здесь (file/data_url).
 */
class Controller_Nj640_Webhook extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_fire()
    {
        $token = (string) $this->request->param('token');

        if ($token === '' OR ! class_exists('Service_Nj640_Cameras'))
        {
            return $this->_json(404, ['ok' => FALSE, 'error' => 'Не найдено']);
        }

        $cam = Service_Nj640_Cameras::camera_by_webhook($token);

        if ( ! $cam)
        {
            return $this->_json(404, ['ok' => FALSE, 'error' => 'Неизвестный ключ webhook']);
        }

        if ( ! $cam['enabled'])
        {
            return $this->_json(403, ['ok' => FALSE, 'error' => 'Камера выключена']);
        }

        // кадр можно передать прямо в событии (file — multipart, или data_url)
        $capture = [];

        if (isset($_FILES['file']) AND is_array($_FILES['file']) AND (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)
        {
            $bytes = @file_get_contents($_FILES['file']['tmp_name']);

            if ($bytes !== FALSE AND Service_Nj640_Cameras::looks_like_image($bytes))
            {
                $capture[] = ['b64' => base64_encode($bytes), 'media_type' => strtolower((string) ($_FILES['file']['type'] ?? 'image/jpeg'))];
            }
        }
        else
        {
            $data_url = (string) $this->request->post('data_url');

            if ($data_url === '')
            {
                $raw = $this->request->body();
                $decoded = json_decode($raw, TRUE);

                if (is_array($decoded) AND isset($decoded['data_url']))
                {
                    $data_url = (string) $decoded['data_url'];
                }
            }

            if (strpos($data_url, 'data:') === 0)
            {
                $capture[] = $data_url;
            }
        }

        $res = Service_Nj640_Cameras::camera_fire([
            'camera_id' => (int) $cam['id'],
            'trigger'   => 'webhook',
            'capture'   => $capture,
        ]);

        if ( ! ($res['ok'] ?? FALSE))
        {
            return $this->_json(400, ['ok' => FALSE, 'error' => $res['error'] ?? 'Не удалось запустить проверку']);
        }

        return $this->_json(200, [
            'ok'     => TRUE,
            'run_id' => (int) $res['run_id'],
            'poll'   => '/cameras/api/result/'.(int) $res['run_id'],
        ]);
    }

    protected function _json($status, array $data)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
