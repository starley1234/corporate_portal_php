<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API справочника организаций (nj594):
 *  * GET  /orgs/api/search?q=       — поиск по ОГРН/ИНН/названию (открытые данные);
 *  * GET  /orgs/api/card/<ogrn>     — карточка организации (открытые данные);
 *  * POST /orgs/api/refresh/<ogrn>  — обновить карточку из ФНС (X-Worker-Token);
 *  * POST /orgs/api/import          — пакетный импорт карточек (X-Worker-Token),
 *                                     задел под полный импорт открытых данных
 *                                     ЕГРЮЛ/ЕГРИП воркером (порциями, без больших
 *                                     файлов на диске сайта).
 *
 * Ошибки — ЦЕЛИКОМ в json {error} (с причиной, @ файл:строка), как принято.
 */
class Controller_Nj594_Api extends Controller {

    public function before()
    {
        parent::before();

        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
    }

    /** GET /orgs/api/search?q= — до 20 карточек. */
    public function action_search()
    {
        $q = trim((string) $this->request->query('q'));

        if ($q === '')
        {
            $this->_json(['error' => 'Укажите поисковый запрос: ?q=ОГРН|ИНН|название']);

            return;
        }

        $out = [];

        foreach (Service_Nj594_Orgs::search($q, 20) as $o)
        {
            $out[] = Service_Nj594_Orgs::card($o);
        }

        $this->_json(['query' => $q, 'count' => count($out), 'orgs' => $out]);
    }

    /** GET /orgs/api/card/<ogrn> — карточка по ОГРН/ОГРНИП. */
    public function action_card()
    {
        $ogrn = preg_replace('/\D/', '', (string) $this->request->param('id'));
        $o    = Service_Nj594_Orgs::by_ogrn($ogrn);

        if ($o === NULL)
        {
            $this->_json(['error' => 'Карточка '.$ogrn.' не найдена в справочнике'], 404);

            return;
        }

        $card = Service_Nj594_Orgs::card($o);

        foreach (Service_Nj594_Orgs::links_for_org((int) $o['id'], 100) as $l)
        {
            $card['links'][] = [
                'module' => (string) $l['module'],
                'code'   => (string) $l['item_code'],
                'url'    => (string) $l['item_url'],
                'title'  => (string) $l['title'],
                'kind'   => (string) $l['kind'],
            ];
        }

        $this->_json($card);
    }

    /** POST /orgs/api/refresh/<ogrn> — живой запрос в ФНС и апдейт карточки. */
    public function action_refresh()
    {
        if ( ! $this->_auth())
        {
            $this->_json(['error' => 'Доступ только по токену воркера (X-Worker-Token)'], 403);

            return;
        }

        $ogrn = preg_replace('/\D/', '', (string) $this->request->param('id'));
        $res  = Service_Nj594_Orgs::refresh($ogrn);

        $this->_json($res, isset($res['error']) ? 422 : 200);
    }

    /** POST /orgs/api/import — пакет карточек {orgs: [...]} (до 500 за раз). */
    public function action_import()
    {
        if ( ! $this->_auth())
        {
            $this->_json(['error' => 'Доступ только по токену воркера (X-Worker-Token)'], 403);

            return;
        }

        $j = json_decode((string) $this->request->body(), TRUE);
        $orgs = (is_array($j) AND isset($j['orgs']) AND is_array($j['orgs'])) ? $j['orgs'] : [];

        if ( ! $orgs)
        {
            $this->_json(['error' => 'Пустой пакет: жду JSON {"orgs": [{ogrn, inn, kpp, form, short_name, full_name, address, okved, okved_name, status, reg_date, director}, ...]}'], 422);

            return;
        }

        if (count($orgs) > 500)
        {
            $this->_json(['error' => 'Пакет больше 500 карточек — шлите порциями'], 422);

            return;
        }

        $source = 'import:'.mb_substr(trim((string) ($j['source'] ?? 'worker')), 0, 20);
        $ok = 0;
        $skip = [];

        foreach ($orgs as $f)
        {
            if ( ! is_array($f))
            {
                continue;
            }

            $id = Service_Nj594_Orgs::upsert((string) ($f['ogrn'] ?? ''), $f, $source,
                isset($f['raw']) ? $f['raw'] : NULL);

            if ($id)
            {
                $ok++;
            }
            else
            {
                $skip[] = (string) ($f['ogrn'] ?? '?');
            }
        }

        $this->_json(['ok' => TRUE, 'imported' => $ok, 'skipped' => $skip]);
    }

    /** Токен воркера: X-Worker-Token или ?token= (как в nj120/nj570/nj580). */
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

    protected function _json(array $data, $status = 200)
    {
        $this->response->status($status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
