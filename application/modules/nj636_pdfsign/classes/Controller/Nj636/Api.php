<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Подписи и реквизиты PDF» (nj636_pdfsign).
 *
 *   POST /pdf-sign/api/stamp   multipart: file=..., lines=«Должность\nФИО\nДата»,
 *                              pos=bl|br|tl|tr, pages=last|first|all, underline=1
 *   POST /pdf-sign/api/count   multipart: file=...  (сколько визуальных подписей)
 *   POST /pdf-sign/api/strip   multipart: file=...  (убрать визуальные подписи)
 *   GET  /pdf-sign/api/run/<id> — запись истории
 *
 * Ответы бинарные (PDF) как application/pdf, остальное — JSON.
 */
class Controller_Nj636_Api extends Controller {

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

    public function action_stamp()
    {
        $body = (array) $this->request->post();

        if (empty($body) AND $this->request->query())
        {
            $body = (array) $this->request->query();
        }

        $raw_lines = (string) ($body['lines'] ?? '');
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw_lines)), function ($l) {
            return $l !== '';
        }));

        $f = Service_Nj636_Pdfsign::read_upload();

        if ( ! $f['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $f['error']], 400);
        }

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj636_Pdfsign::stamp($f['bytes'], $lines, [
            'pos'       => (string) ($body['pos'] ?? 'bl'),
            'pages'     => (string) ($body['pages'] ?? 'last'),
            'underline' => ! empty($body['underline']),
        ]);

        $this->_record($f['name'], 'stamp', $result);

        if (isset($result['ok']) && $result['ok'])
        {
            $name = pathinfo($f['name'], PATHINFO_FILENAME).'_signed.pdf';

            return $this->_pdf_download($result['pdf'], $name);
        }

        return $this->_respond(['ok' => FALSE, 'error' => $result['error'] ?? 'Не удалось наложить реквизиты.'], 400);
    }

    public function action_count()
    {
        $f = Service_Nj636_Pdfsign::read_upload();

        if ( ! $f['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $f['error']], 400);
        }

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj636_Pdfsign::count($f['bytes']);
        $this->_record($f['name'], 'count', $result);

        return $this->_respond(['ok' => isset($result['ok']) && $result['ok']] + $result);
    }

    public function action_strip()
    {
        $f = Service_Nj636_Pdfsign::read_upload();

        if ( ! $f['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $f['error']], 400);
        }

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj636_Pdfsign::strip($f['bytes']);
        $this->_record($f['name'], 'strip', $result);

        if (isset($result['ok']) && $result['ok'])
        {
            $name = pathinfo($f['name'], PATHINFO_FILENAME).'_clean.pdf';

            return $this->_pdf_download($result['pdf'], $name);
        }

        return $this->_respond(['ok' => FALSE, 'error' => $result['error'] ?? 'Не удалось очистить подписи.'], 400);
    }

    public function action_run()
    {
        $run_id = (int) ($this->request->param('id') ?: ($this->_body['id'] ?? 0));

        foreach (Service_Nj636_Pdfsign::recent_runs(20) as $r)
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

        $this->response->headers('Content-Type', 'application/pdf');
        $this->response->headers('Content-Disposition', 'attachment; filename="'.mb_substr($name, 0, 180).'"');
        $this->response->headers('Content-Length', (string) strlen($bytes));
        $this->response->headers('Cache-Control', 'no-store');
        $this->response->body($bytes);
    }

    // ---------------------------------------------------------- служебное

    protected function _record($name, $kind, array $result): void
    {
        try
        {
            Service_Nj636_Pdfsign::record_run(
                $name,
                $kind,
                $result,
                isset($result['ok']) && $result['ok'] ? 'ok' : 'error',
                ! empty($result['error']) ? $result['error'] : NULL
            );
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj636: не удалось записать прогон: :e', [':e' => $e->getMessage()]);
        }
    }

    protected function _rate_ok()
    {
        return class_exists('Service_Nj636_Pdfsign')
            ? Service_Nj636_Pdfsign::rate_ok(Service_Nj636_Pdfsign::client_ip())
            : TRUE;
    }

    protected function _rate_error()
    {
        return 'Слишком много операций с этого адреса. Лимит: '
            .Service_Nj636_Pdfsign::rate_limit_text().' — попробуйте позже.';
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
