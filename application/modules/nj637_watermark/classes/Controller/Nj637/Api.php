<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Водяные знаки на PDF» (nj637_watermark).
 *
 *   POST /watermark/api/apply   multipart: file=..., text=..., mode=diagonal|stamp,
 *                               pages=all|first|last|N|N-M, size=.., opacity=..,
 *                               pos=br|bl|tr|tl|center, underline=1
 *   GET  /watermark/api/run/<id>  — запись истории прогона
 *
 * Ответы бинарные (PDF) как application/pdf со скачиванием, остальное — JSON.
 * Без exec/shell; rate limit по IP в БД.
 */
class Controller_Nj637_Api extends Controller {

    public $auto_render = FALSE;

    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw = $this->request->body();

        if ($raw)
        {
            $decoded = json_decode($raw, TRUE);
            $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
        }
        else
        {
            $this->_body = (array) $this->request->post();
        }

        if (empty($this->_body) AND $this->request->query())
        {
            $this->_body = (array) $this->request->query();
        }
    }

    public function action_apply()
    {
        // мультипарт: поля идут в $_POST / $_FILES
        $body = (array) $this->request->post();

        if (empty($body) AND $this->request->query())
        {
            $body = (array) $this->request->query();
        }

        $text = (string) ($body['text'] ?? '');
        $f = Service_Nj637_Watermark::read_upload();

        if ( ! $f['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $f['error']], 400);
        }

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj637_Watermark::apply($f['bytes'], $text, [
            'mode'      => (string) ($body['mode'] ?? 'diagonal'),
            'pos'       => (string) ($body['pos'] ?? 'br'),
            'size'      => (float) ($body['size'] ?? 0),
            'opacity'   => (float) ($body['opacity'] ?? 0),
            'underline' => ! empty($body['underline']),
            'pages'     => (string) ($body['pages'] ?? 'all'),
        ]);

        $this->_record($f['name'], $result);

        if (isset($result['ok']) && $result['ok'])
        {
            $name = pathinfo($f['name'], PATHINFO_FILENAME).'_sign.pdf';

            return $this->_pdf_download($result['pdf'], $name);
        }

        return $this->_respond(['ok' => FALSE, 'error' => $result['error'] ?? 'Не удалось наложить знак.'], 400);
    }

    public function action_run()
    {
        $run_id = (int) ($this->request->param('id') ?: ($this->_body['id'] ?? 0));

        $recent = Service_Nj637_Watermark::recent_runs(20);

        foreach ($recent as $r)
        {
            if ((int) $r['id'] === $run_id)
            {
                return $this->_respond(['ok' => TRUE] + $r);
            }
        }

        return $this->_respond(['ok' => FALSE, 'error' => 'Прогон не найден.'], 404);
    }

    // --------------------------------------------------------- отдача файла

    protected function _pdf_download(string $bytes, string $name): void
    {
        $name = str_replace(['"', "\n", "\r"], '', $name);
        $name = mb_substr($name, 0, 180);

        $this->response->headers('Content-Type', 'application/pdf');
        $this->response->headers('Content-Disposition', 'attachment; filename="'.$name.'"');
        $this->response->headers('Content-Length', (string) strlen($bytes));
        $this->response->headers('Cache-Control', 'no-store');
        $this->response->body($bytes);
    }

    // ---------------------------------------------------------- служебное

    protected function _record($name, array $result): void
    {
        try
        {
            Service_Nj637_Watermark::record_run(
                $name,
                $result,
                isset($result['ok']) && $result['ok'] ? 'ok' : 'error',
                ! empty($result['error']) ? $result['error'] : NULL
            );
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj637: не удалось записать прогон: :e', [':e' => $e->getMessage()]);
        }
    }

    protected function _rate_ok()
    {
        return class_exists('Service_Nj637_Watermark')
            ? Service_Nj637_Watermark::rate_ok(Service_Nj637_Watermark::client_ip())
            : TRUE;
    }

    protected function _rate_error()
    {
        return 'Слишком много операций с этого адреса. Лимит: '
            .Service_Nj637_Watermark::rate_limit_text().' — попробуйте позже.';
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
