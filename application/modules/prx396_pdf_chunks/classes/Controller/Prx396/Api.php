<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Машинный API: POST /api/v1/pdf-chunks, GET .../<id>, GET .../<id>/result.
 *
 * Ключ, scope и лимит проверяет Controller_Prx150_Machine. Владелец задания —
 * целочисленный ID ключа prx150, поэтому чужое задание не видно даже по угаданному ID.
 */
class Controller_Prx396_Api extends Controller_Prx150_Machine
{
    protected const SCOPE = Service_Prx396_Extraction::SCOPE;

    public function action_index(): void
    {
        $this->respond(function () {
            $id = (int) $this->request->param('id');
            if ($id)
            {
                $this->require_method(Request::GET);
                $this->_json_response((new Service_Prx396_Extraction)->status($this->principal(), $id));
                return;
            }

            $this->require_method(Request::POST);
            Service_Prx396_Limits::assert_request_size((int) $this->request->headers('Content-Length'));
            $service = new Service_Prx396_Extraction;
            $batch_id = $service->submit(
                $this->principal(),
                (string) $this->request->headers('Idempotency-Key'),
                $_FILES['file'] ?? NULL
            );
            $base = '/api/v1/pdf-chunks/'.$batch_id;
            $this->_json_response([
                'batch_id' => $batch_id,
                'status_url' => $base,
                'result_url' => $base.'/result',
            ], 202);
        });
    }

    public function action_result(): void
    {
        $this->respond(function () {
            $this->require_method(Request::GET);
            $id = (int) $this->request->param('id');
            $service = new Service_Prx396_Extraction;
            $result = $service->result_parts($this->principal(), $id);
            $this->stream_result($id, $result['parts']);
        });
    }

    /** @param array<int,array{file_id:int,text_bytes:int}> $parts */
    private function stream_result(int $id, array $parts): void
    {
        $this->auto_render = FALSE;
        if (ob_get_level()) ob_end_clean();

        $filename = 'pdf-chunks-'.$id.'.txt';
        $this->response->headers('Content-Type', 'text/plain; charset=utf-8');
        $this->response->headers('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $this->response->headers('Cache-Control', 'no-store');
        $this->response->headers('X-Content-Type-Options', 'nosniff');
        $this->response->send_headers();

        foreach ($parts as $part)
        {
            $text = Storage::read_owned((int) $part['file_id'], Service_Prx396_Extraction::MODULE,
                Service_Prx396_Limits::get('max_text_bytes'));
            if ($text === NULL) break;
            echo $text;
            if (ob_get_level()) ob_flush();
            flush();
        }
        exit;
    }

    private function require_method(string $method): void
    {
        if ($this->request->method() !== $method)
        {
            $this->response->headers('Allow', $method);
            throw new Prx396_Error('method_not_allowed', 405);
        }
    }
}
