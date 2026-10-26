<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API «Документы организации»:
 *  * /docs/api/status/<code> — статусы пакета и документов (poll из UI);
 *  * /docs/api/doc    — воркер создаёт/обновляет документ (X-Worker-Token);
 *  * /docs/api/upload — воркер выгружает файлы DOCX/PDF/XLSX/ZIP (токен);
 *  * /docs/api/storage — диагностика хранилища (токен).
 */
class Controller_Nj580_Api extends Controller {

    public function before()
    {
        parent::before();

        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
    }

    /** GET /docs/api/status/<code>. */
    public function action_status()
    {
        $code = (string) $this->request->param('code');
        $pkg  = Service_Nj580_Orgdocs::by_code($code);

        if ($pkg === NULL)
        {
            $this->response->body(json_encode(['error' => 'not_found']));

            return;
        }

        // Чтение статуса — по коду-капабилити (страница пакета открыта по коду)
        $pkg  = Service_Nj580_Orgdocs::reconcile($pkg);
        $docs = Service_Nj580_Orgdocs::documents((int) $pkg['id']);

        $queue = class_exists('Service_Nj120_JobQueue') ? Service_Nj120_JobQueue::instance() : NULL;
        $live  = ($queue AND ! empty($pkg['job_id'])) ? $queue->status_of((int) $pkg['job_id']) : [];

        // Авто-остановка застрявшей сборки — здесь, потому что страницу
        // опрашивает именно этот endpoint: (1) нет пульса дольше 4 минут;
        // (2) пульс есть, прогресс застыл (10 минут). Раньше свой баннер сам
        // сдавался через час («похоже, воркер недоступен»), а задача в очереди
        // оставалась processing — пакет висел вечно.
        if ($queue AND ! empty($live['found']) AND ($live['status'] ?? '') === 'running')
        {
            try
            {
                $stopped = FALSE;

                if ($live['silent_sec'] !== NULL AND (int) $live['silent_sec'] > $queue->silent_stop_sec())
                {
                    $stopped = $queue->auto_stop_silent((int) $pkg['job_id']) > 0;
                }

                if ( ! $stopped AND $queue->auto_stop_stalled((int) $pkg['job_id']) > 0)
                {
                    $stopped = TRUE;
                }

                if ($stopped)
                {
                    $pkg  = Service_Nj580_Orgdocs::reconcile(Service_Nj580_Orgdocs::by_code($code));
                    $live = $queue->status_of((int) $pkg['job_id']);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj580: авто-остановка застрявшей сборки: :e', [':e' => $e->getMessage()]);
            }
        }

        $job = ! empty($live['found'])
            ? ['status' => (string) $live['status'], 'elapsed_sec' => (int) $live['elapsed_sec']]
            : NULL;

        // Общий контракт статус-ряда (partials/live_status).
        $live_status = [
            'new'      => 'queued',
            'running'  => 'running',
            'done'     => 'done',
            'error'    => 'failed',
            'canceled' => 'cancelled',
        ][(string) $pkg['status']] ?? 'running';

        $this->response->body(json_encode([
            // контракт статус-ряда
            'ok'          => TRUE,
            'status_live' => $live_status,
            'stage'       => (string) $pkg['progress'] !== '' ? (string) $pkg['progress'] : (string) ($live['stage'] ?? ''),
            'stages'      => (array) ($live['stages'] ?? []),
            'elapsed_sec' => (int) ($live['elapsed_sec'] ?? 0),
            'silent_sec'  => $live['silent_sec'] ?? NULL,
            'found'       => 0,
            'code'      => (string) $pkg['code'],
            'status'    => (string) $pkg['status'],
            'org_name'  => (string) $pkg['org_name'],
            'summary'   => (string) $pkg['summary'],
            'progress'  => (string) $pkg['progress'],
            'error'     => (string) $pkg['error'],
            'job'       => $job,
            'package_files' => array_map(function ($f) {
                return ['id' => (int) $f['id'], 'kind' => (string) $f['kind'], 'size' => (int) $f['size']];
            }, Service_Nj580_Orgdocs::package_files((int) $pkg['id'])),
            'documents' => array_map(function ($d) {
                return [
                    'doc_key' => (string) $d['doc_key'],
                    'ord'     => (int) $d['ord'],
                    'title'   => (string) $d['title'],
                    'status'  => (string) $d['status'],
                    'error'   => (string) $d['error'],
                    'files'   => array_map(function ($f) {
                        return ['id' => (int) $f['id'], 'kind' => (string) $f['kind'], 'size' => (int) $f['size']];
                    }, (array) $d['files']),
                ];
            }, $docs),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** POST /docs/api/doc — документ пакета от воркера (multipart или form). */
    public function action_doc()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $package_id = (int) $this->request->post('package_id');
        $doc_key    = trim((string) $this->request->post('doc_key'));
        $ord        = (int) $this->request->post('ord');
        $title      = trim((string) $this->request->post('title'));
        $status     = (string) $this->request->post('status');
        $error      = trim((string) $this->request->post('error'));
        $content    = trim((string) $this->request->post('content'));

        if ($package_id <= 0 OR $doc_key === '')
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'need package_id и doc_key']));

            return;
        }

        if ($content !== '' AND json_decode($content, TRUE) === NULL)
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'content не JSON']));

            return;
        }

        $res = Service_Nj580_Orgdocs::upsert_document($package_id, $doc_key, $ord, $title, $status, $error, $content);

        if (isset($res['error']))
        {
            $this->response->status(400);
            $this->response->body(json_encode($res));

            return;
        }

        $this->response->body(json_encode(['ok' => TRUE, 'id' => $res['id']]));
    }

    /**
     * POST /docs/api/progress — промежуточный статус сборки от воркера
     * (form: package_id, phase) → баннер страницы пакета в реальном времени.
     */
    public function action_progress()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $package_id = (int) $this->request->post('package_id');
        $phase      = trim((string) $this->request->post('phase'));

        if ($package_id <= 0 OR $phase === '')
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'need package_id и phase']));

            return;
        }

        // воркер может уточнять реквизиты пакета (сырые данные, ОГРН-поиск) —
        // обновляем только заполненные поля
        $fields = [];
        foreach (['org_name', 'industry', 'size', 'structure', 'needs',
                  'ogrn', 'inn', 'kpp', 'director', 'address', 'okved', 'reg_date'] as $field)
        {
            $v = trim((string) $this->request->post($field));

            if ($v !== '')
            {
                $fields[$field] = $v;
            }
        }

        if ($fields)
        {
            Service_Nj580_Orgdocs::apply_worker_update($package_id, $fields);
        }

        // если воркер спросил ОГРН — вернём сохранённую карточку (кэш ЕГРЮЛ):
        // «данные уже известны — искать в интернете не нужно»
        $org = NULL;
        $q_ogrn = trim((string) $this->request->post('q_ogrn'));

        if ($q_ogrn !== '' AND Service_Nj580_Orgdocs::ogrn_valid($q_ogrn))
        {
            $card = Service_Nj580_Orgdocs::org_by_ogrn($q_ogrn);

            if ($card)
            {
                $org = [
                    'ogrn'      => $card['ogrn'],
                    'inn'       => $card['inn'],
                    'full_name' => $card['full_name'],
                    'address'   => $card['address'],
                    'okved'     => $card['okved'],
                    'director'  => $card['director'],
                ];
            }
        }

        $this->response->body(json_encode(['ok' => TRUE, 'org' => $org], JSON_UNESCAPED_UNICODE));
    }

    /**
     * GET /docs/api/org/<ОГРН|ОГРНИП|ИНН> — карточка организации:
     * кэш сервиса → официальный поиск ФНС (ЕГРЮЛ/ЕГРИП, без капчи) → кэш.
     */
    public function action_org()
    {
        $ogrn = (string) $this->request->param('code');

        if ( ! Service_Nj580_Orgdocs::ogrn_valid($ogrn))
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'ОГРН/ОГРНИП/ИНН указан с ошибкой (контрольная цифра не сходится)']));

            return;
        }

        $card = Service_Nj580_Orgdocs::org_by_ogrn($ogrn);

        if ($card === NULL)
        {
            $fns = $this->_fns_card($ogrn);

            if ($fns !== NULL)
            {
                Service_Nj580_Orgdocs::upsert_org($ogrn, $fns, 'fns');
                $card = Service_Nj580_Orgdocs::org_by_ogrn($ogrn);
            }
        }

        $this->response->body(json_encode([
            'found' => $card !== NULL,
            'org'   => $card ? [
                'ogrn'      => $card['ogrn'],
                'inn'       => $card['inn'],
                'full_name' => $card['full_name'],
                'address'   => $card['address'],
                'okved'     => $card['okved'],
                'director'  => $card['director'],
            ] : NULL,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Официальный поиск ФНС (egrul.nalog.ru): GET / (cookie) → POST {query}
     * → {"t": token} → GET /search-result/{token} → строки rows.
     * Возвращает [full_name, inn, kpp, address, reg_date] или NULL.
     */
    protected function _fns_card($num)
    {
        if ( ! function_exists('curl_init'))
        {
            return NULL;
        }

        $jar = sys_get_temp_dir().'/nj580_fns_cookie.jar';
        $base = [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64)',
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_FOLLOWLOCATION => TRUE,
        ];

        $ch = curl_init('https://egrul.nalog.ru/');
        curl_setopt_array($ch, $base);
        curl_exec($ch);
        curl_close($ch);

        $ch = curl_init('https://egrul.nalog.ru/');
        curl_setopt_array($ch, $base + [
            CURLOPT_POST       => TRUE,
            CURLOPT_POSTFIELDS => http_build_query([
                'vyp3CaptchaToken'          => '',
                'page'                      => '',
                'query'                     => $num,
                'region'                    => '',
                'PreventChromeAutocomplete' => '',
            ]),
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        $j     = json_decode((string) $res, TRUE);
        $token = (is_array($j) AND isset($j['t'])) ? (string) $j['t'] : NULL;

        if ( ! $token)
        {
            return NULL;
        }

        usleep(600000);

        for ($i = 0; $i < 5; $i++)
        {
            $ch = curl_init('https://egrul.nalog.ru/search-result/'.rawurlencode($token)
                .'?r='.round(microtime(TRUE) * 1000));
            curl_setopt_array($ch, $base);
            $res = curl_exec($ch);
            curl_close($ch);

            $j   = json_decode((string) $res, TRUE);
            $row = (isset($j['rows'][0]) AND is_array($j['rows'][0])) ? $j['rows'][0] : NULL;

            if ($row !== NULL)
            {
                $card = [];

                foreach (['n' => 'full_name', 'i' => 'inn', 'p' => 'kpp', 'k' => 'kpp',
                          'a' => 'address', 'r' => 'reg_date'] as $src => $dst)
                {
                    $v = trim((string) ($row[$src] ?? ''));

                    if ($v !== '' AND trim((string) ($card[$dst] ?? '')) === '')
                    {
                        $card[$dst] = $dst === 'full_name' || $dst === 'address'
                            ? mb_substr($v, 0, 500)
                            : preg_replace('/\D/', '', $v);
                    }
                }

                return empty($card['full_name']) ? NULL : $card;
            }

            if (isset($j['status']) AND $j['status'] === 'wait')
            {
                usleep(600000);

                continue;
            }

            break;
        }

        return NULL;
    }

    /** POST /docs/api/upload — файл от воркера (multipart: package_id, document_id, kind, file). */
    public function action_upload()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $package_id  = (int) $this->request->post('package_id');
        $document_id = (int) $this->request->post('document_id');
        $kind        = strtolower(trim((string) $this->request->post('kind')));

        if (empty($_FILES['file']['tmp_name']) OR ! is_uploaded_file($_FILES['file']['tmp_name']))
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'no_file']));

            return;
        }

        $bytes = file_get_contents($_FILES['file']['tmp_name']);
        $res   = Service_Nj580_Orgdocs::store_file($package_id, $document_id, $kind, $bytes);

        if (isset($res['error']))
        {
            $this->response->status(400);
            $this->response->body(json_encode($res));

            return;
        }

        $this->response->body(json_encode(['ok' => TRUE, 'id' => $res['id']]));
    }

    /** GET /docs/api/storage — диагностика хранилища (только токен воркера). */
    public function action_storage()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $dir   = Service_Nj580_Orgdocs::storage_dir();
        $files = [];

        if (is_dir($dir))
        {
            foreach (['docx', 'pdf', 'xlsx', 'zip'] as $ext)
            {
                $list = (array) glob($dir.'/*/*.'.$ext);
                usort($list, fn ($a, $b) => filemtime($b) <=> filemtime($a));

                foreach (array_slice($list, 0, 5) as $f)
                {
                    $files[] = ['f' => str_replace($dir.'/', '', $f), 'size' => filesize($f)];
                }
            }
        }

        $this->response->body(json_encode([
            'dir'           => $dir,
            'is_dir'        => is_dir($dir),
            'is_writable'   => is_dir($dir) ? is_writable($dir) : FALSE,
            'writable_root' => is_dir(dirname($dir)) ? is_writable(dirname($dir)) : FALSE,
            'count'         => is_dir($dir) ? count((array) glob($dir.'/*/*')) : 0,
            'recent'        => $files,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Статический токен (как в nj120/nj570): X-Worker-Token или ?token=. */
    protected function _auth()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return FALSE;
        }

        $given = (string) $this->request->headers('x-worker-token');

        if ($given === '')
        {
            $given = (string) $this->request->query('token');
        }

        return hash_equals($token, $given);
    }
}
