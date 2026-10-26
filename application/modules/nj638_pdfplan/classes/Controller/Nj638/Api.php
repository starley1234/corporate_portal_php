<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Конструктор А4» (nj638_pdfplan).
 *
 *   POST /pdf-plan/api/build   json: {title, subtitle, date, footer, text, columns, size}
 *   GET  /pdf-plan/api/run/<id> — запись истории генерации
 *
 * Ответ бинарного PDF — application/pdf со скачиванием; остальное JSON.
 */
class Controller_Nj638_Api extends Controller {

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

    public function action_build()
    {
        $body = (array) $this->request->post();

        if (empty($body) AND $this->request->query())
        {
            $body = (array) $this->request->query();
        }

        if (empty($body) AND $this->_body)
        {
            $body = $this->_body;
        }

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj638_Pdfplan::build([
            'title'    => (string) ($body['title'] ?? ''),
            'subtitle' => (string) ($body['subtitle'] ?? ''),
            'date'     => (string) ($body['date'] ?? ''),
            'footer'   => (string) ($body['footer'] ?? ''),
            'text'     => (string) ($body['text'] ?? $body['content'] ?? ''),
            'columns'  => (int) ($body['columns'] ?? 1),
            'size'     => (float) ($body['size'] ?? 0),
        ]);

        $this->_record($body['title'] ?? '', $result);

        if (isset($result['ok']) && $result['ok'])
        {
            $title = trim((string) ($body['title'] ?? ''));

            return $this->_pdf_download($result['pdf'],
                ($title !== '' ? mb_substr($title, 0, 60) : 'document').'.pdf');
        }

        return $this->_respond(['ok' => FALSE, 'error' => $result['error'] ?? 'Не удалось собрать документ.'], 400);
    }

    public function action_run()
    {
        $run_id = (int) ($this->request->param('id') ?: ($this->_body['id'] ?? 0));

        foreach (Service_Nj638_Pdfplan::recent_runs(20) as $r)
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
        $name = str_replace(['"', "\n", "\r", '/', '\\'], '', $name);

        $this->response->headers('Content-Type', 'application/pdf');
        $this->response->headers('Content-Disposition', 'attachment; filename="'.mb_substr($name, 0, 180).'"');
        $this->response->headers('Content-Length', (string) strlen($bytes));
        $this->response->headers('Cache-Control', 'no-store');
        $this->response->body($bytes);
    }

    // ---------------------------------------------------------- служебное

    protected function _record($title, array $result): void
    {
        try
        {
            Service_Nj638_Pdfplan::record_run(
                (string) $title,
                $result,
                isset($result['ok']) && $result['ok'] ? 'ok' : 'error',
                ! empty($result['error']) ? $result['error'] : NULL
            );
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj638: не удалось записать прогон: :e', [':e' => $e->getMessage()]);
        }
    }

    protected function _rate_ok()
    {
        return class_exists('Service_Nj638_Pdfplan')
            ? Service_Nj638_Pdfplan::rate_ok(Service_Nj638_Pdfplan::client_ip())
            : TRUE;
    }

    protected function _rate_error()
    {
        return 'Слишком много операций с этого адреса. Лимит: '
            .Service_Nj638_Pdfplan::rate_limit_text().' — попробуйте позже.';
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
