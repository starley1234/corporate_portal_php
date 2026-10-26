<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API PDF-инструментов (NJ-634).
 *
 *   POST     /pdf/api/info        multipart: file=... 
 *   POST     /pdf/api/text        multipart: file=...
 *   POST     /pdf/api/txt2pdf     json: {title, text}
 *   POST     /pdf/api/split       multipart: file=..., first=1, last=3
 *   POST     /pdf/api/merge       multipart: file=..., pages=1,3,2
 *   POST     /pdf/api/mergefiles  multipart: files[]=...  (объединение файлов)
 *   GET/POST /pdf/api/run/<id>    текст извлечённого прогона
 *
 * Ограничения: один файл на запрос; только PDF по магии; не шифрованные;
 * rate limit по IP в БД; без exec/shell. Ответы бинарные (PDF) — как
 * application/pdf со скачиванием, остальное — JSON.
 */
class Controller_Nj634_Api extends Controller {

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

    public function action_info()
    {
        $f = Service_Nj634_Pdf::read_upload();

        if ( ! $f['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $f['error']], 400);
        }

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj634_Pdf::info($f['bytes']);
        $this->_record('info', $f['name'], $result);

        $status = isset($result['ok']) && $result['ok'] ? 200 : 400;

        return $this->_respond(['ok' => isset($result['ok']) && $result['ok']] + $result, $status);
    }

    public function action_text()
    {
        $f = Service_Nj634_Pdf::read_upload();

        if ( ! $f['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $f['error']], 400);
        }

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj634_Pdf::text($f['bytes']);
        $this->_record('text', $f['name'], $result);

        $status = isset($result['ok']) && $result['ok'] ? 200 : 400;

        return $this->_respond(['ok' => isset($result['ok']) && $result['ok']] + $result, $status);
    }

    public function action_txt2pdf()
    {
        $title = (string) ($this->_body['title'] ?? '');
        $text  = (string) ($this->_body['text'] ?? $this->_body['content'] ?? '');

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj634_Pdf::txt2pdf($text, $title);
        $this->_record('txt2pdf', ($title !== '' ? $title : 'document').'.pdf', $result);

        if (isset($result['ok']) && $result['ok'])
        {
            return $this->_pdf_download($result['pdf'], ($title !== '' ? $title : 'document').'.pdf');
        }

        return $this->_respond(['ok' => FALSE, 'error' => $result['error'] ?? 'Ошибка генерации.'], 400);
    }

    public function action_split()
    {
        $f = Service_Nj634_Pdf::read_upload();

        if ( ! $f['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $f['error']], 400);
        }

        $first = (int) ($this->_body['first'] ?? $this->_body['start'] ?? 1);
        $last  = (int) ($this->_body['last'] ?? $this->_body['end'] ?? $first);

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        if ($first < 1 OR $last < 1 OR $last < $first)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Диапазон страниц: first 1..N и last ≥ first.'], 400);
        }

        $result = Service_Nj634_Pdf::split($f['bytes'], $first, $last);
        $this->_record('split', $f['name'], $result);

        if (isset($result['ok']) && $result['ok'])
        {
            $name = pathinfo($f['name'], PATHINFO_FILENAME).'_'.$first.'-'.$last.'.pdf';

            return $this->_pdf_download($result['pdf'], $name);
        }

        return $this->_respond(['ok' => FALSE, 'error' => $result['error'] ?? 'Не удалось вырезать страницы.'], 400);
    }

    public function action_merge()
    {
        $f = Service_Nj634_Pdf::read_upload();

        if ( ! $f['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $f['error']], 400);
        }

        $pages_raw = (string) ($this->_body['pages'] ?? '');

        // набор «1,3,2» или JSON-массив
        $pages = [];

        if (preg_match('/^\s*\[/', $pages_raw))
        {
            $decoded = json_decode($pages_raw, TRUE);
            $pages = is_array($decoded) ? array_map('intval', $decoded) : [];
        }
        else
        {
            foreach (explode(',', $pages_raw) as $p)
            {
                $p = trim($p);

                if ($p !== '')
                {
                    $pages[] = (int) $p;
                }
            }
        }

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $result = Service_Nj634_Pdf::merge($f['bytes'], $pages);
        $this->_record('merge', $f['name'], $result);

        if (isset($result['ok']) && $result['ok'])
        {
            return $this->_pdf_download($result['pdf'], pathinfo($f['name'], PATHINFO_FILENAME).'_merged.pdf');
        }

        return $this->_respond(['ok' => FALSE, 'error' => $result['error'] ?? 'Не удалось перекомпоновать страницы.'], 400);
    }

    /** Объединить несколько файлов (files[]): все страницы по порядку. */
    public function action_mergefiles()
    {
        $up = Service_Nj634_Pdf::read_uploads();

        if ( ! $up['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $up['error']], 400);
        }

        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $bytes_list = array_map(function ($f) { return $f['bytes']; }, $up['files']);
        $result = Service_Nj634_Pdf::merge_files($bytes_list);

        $names = implode('+', array_map(function ($f) { return $f['name']; }, $up['files']));
        $this->_record('mergefiles', mb_substr($names, 0, 255), $result);

        if (isset($result['ok']) && $result['ok'])
        {
            return $this->_pdf_download($result['pdf'], 'merged.pdf');
        }

        return $this->_respond(['ok' => FALSE, 'error' => $result['error'] ?? 'Не удалось объединить файлы.'], 400);
    }

    /** Текст извлечённого прогона (для «скопировать»). */
    public function action_run()
    {
        $run_id = (int) ($this->request->param('id') ?: ($this->_body['id'] ?? 0));
        $run = Service_Nj634_Pdf::run_text($run_id);

        if ($run === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Прогон не найден.'], 404);
        }

        return $this->_respond(['ok' => TRUE] + $run);
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

    protected function _record($kind, $name, array $result): void
    {
        try
        {
            Service_Nj634_Pdf::record_run(
                $kind,
                $name,
                $result,
                isset($result['ok']) && $result['ok'] ? 'ok' : 'error',
                ! empty($result['error']) ? $result['error'] : NULL
            );
        }
        catch (Throwable $e)
        {
            // история не должна ронять основную операцию
            Kohana::$log->add(Log::ERROR, 'nj634: не удалось записать прогон: :e', [':e' => $e->getMessage()]);
        }
    }

    protected function _rate_ok()
    {
        return class_exists('Service_Nj634_Pdf')
            ? Service_Nj634_Pdf::rate_ok(Service_Nj634_Pdf::client_ip())
            : TRUE;
    }

    protected function _rate_error()
    {
        return 'Слишком много операций с этого адреса. Лимит: '
            .Service_Nj634_Pdf::rate_limit_text().' — попробуйте позже.';
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
