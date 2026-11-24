<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx398_Ui extends Controller_Base
{
    public function before()
    {
        parent::before();
        if ( ! $this->can('prx398.ui')) throw HTTP_Exception::factory(403, 'Маршрутизация документов доступна администратору');
        $this->response->headers('Cache-Control', 'no-store');
        $this->set_title('Маршрутизация документов');
    }

    public function action_index()
    {
        $id = (int) $this->request->param('id');
        $op = (string) $this->request->query('op');
        if ($op === 'status' && $id) { $this->status_json($id); return; }
        if ($op === 'example') { $this->example_pdf(); return; }

        $method = $this->request->method();
        if ($method !== Request::GET && ($id || $method !== Request::POST))
            throw (HTTP_Exception::factory(405))->allowed($id ? 'GET' : 'GET, POST');

        $actor = $this->actor();
        $row = NULL; $error = NULL;
        try {
            if ($method === Request::POST) {
                Service_Prx398_Limits::assert_request_size((int) $this->request->headers('Content-Length'));
                $key = bin2hex(random_bytes(16));
                $job_id = (new Service_Prx398_Route)->submit($actor, $key, $_FILES['file'] ?? NULL);
                HTTP::redirect('/prx398_doc_router/'.$job_id, 303);
            }
            if ($id) $row = (new Service_Prx398_Route)->status($actor, $id);
        } catch (HTTP_Exception $e) { throw $e; }
        catch (Prx398_Error $e) { $error = $e->getMessage(); $this->response->status($e->getCode()); }
        catch (Throwable $e) {
            Log_Plain::event(Log::ERROR, 'prx398 ui id=:id error_type=:type', [':id'=>$id, ':type'=>get_class($e)]);
            $this->response->status(503); $error = 'service_unavailable';
        }

        $this->add_script('/assets/js/prx398-status.js?v=1', TRUE);
        $this->template->content = View::factory('prx398/index', [
            'id' => $id, 'row' => $row, 'error' => $error,
            'limits' => Service_Prx398_Limits::all(),
            'csrf' => Security::token(), 'can_monitor' => $this->can('prx120.monitor'),
        ]);
    }

    private function status_json(int $id): void
    {
        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        try {
            $row = (new Service_Prx398_Route)->status($this->actor(), $id);
            $this->response->body(json_encode($row, JSON_UNESCAPED_UNICODE));
        } catch (Prx398_Error $e) {
            $this->response->status($e->getCode());
            $this->response->body(json_encode(['error'=>['code'=>$e->getMessage()]], JSON_UNESCAPED_UNICODE));
        }
    }

    private function example_pdf(): void
    {
        $this->auto_render = FALSE;
        $content = "BT /F1 10 Tf 50 800 Td (Dogovor N 15 ot 10.10.2026) Tj 0 -20 Td (Storony: OOO Romashka i OOO Vasilek) Tj 0 -20 Td (Summa: 150000 RUB) Tj 0 -20 Td (Predmet: okazanie uslug) Tj ET";
        $objects = [];
        $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
        $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
        $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n";
        $objects[] = "4 0 obj << /Length ".strlen($content)." >> stream\n".$content."\nendstream endobj\n";
        $objects[] = "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";
        $pdf = "%PDF-1.4\n"; $offsets = [];
        foreach ($objects as $o) { $offsets[] = strlen($pdf); $pdf .= $o; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) $pdf .= sprintf("%010d 00000 n \n", $off);
        $pdf .= "trailer << /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
        $this->response->headers('Content-Type', 'application/pdf');
        $this->response->headers('Content-Disposition', 'attachment; filename="doc-route-example.pdf"');
        $this->response->body($pdf);
    }
}
