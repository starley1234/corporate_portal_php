<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Webhook-триггер «Контроль качества» (nj639_qc): /qc/webhook/<token>
 *
 * Внешний аппаратный триггер (датчик/ПЛК/scanner) GET/POST-ом по секретному
 * URL запускает прогон привязанного правила: кадр будет захвачен воркером
 * из конфигурации источника (камера/FTP/…), либо передан здесь (file/data URL).
 */
class Controller_Nj639_Webhook extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_fire()
    {
        $token = (string) $this->request->param('token');

        if ($token === '' OR ! class_exists('Service_Nj639_Qc'))
        {
            return $this->_json(404, ['ok' => FALSE, 'error' => 'Не найдено']);
        }

        $src = Service_Nj639_Qc::source_by_webhook($token);

        if ( ! $src)
        {
            return $this->_json(404, ['ok' => FALSE, 'error' => 'Неизвестный ключ webhook']);
        }

        if ( ! $src['enabled'])
        {
            return $this->_json(403, ['ok' => FALSE, 'error' => 'Источник выключен']);
        }

        // кадр можно передать прямо в событии (file — multipart, или data_url)
        $capture = [];

        if (isset($_FILES['file']) AND is_array($_FILES['file']) AND (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)
        {
            $bytes = @file_get_contents($_FILES['file']['tmp_name']);

            if ($bytes !== FALSE AND Service_Nj639_Qc::looks_like_image($bytes))
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

        $res = Service_Nj639_Qc::source_fire([
            'source_id' => (int) $src['id'],
            'trigger'   => 'webhook',
            'capture'   => $capture,
        ]);

        if ( ! ($res['ok'] ?? FALSE))
        {
            return $this->_json(400, ['ok' => FALSE, 'error' => $res['error'] ?? 'Не удалось запустить прогон']);
        }

        return $this->_json(200, [
            'ok'     => TRUE,
            'run_id' => (int) $res['run_id'],
            'poll'   => '/qc/api/result/'.(int) $res['run_id'],
        ]);
    }

    protected function _json($status, array $data)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
