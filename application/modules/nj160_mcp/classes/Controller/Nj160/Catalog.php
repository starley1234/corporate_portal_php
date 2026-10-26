<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj160: GET /mcp/api/catalog — каталог инструментов ПРОСТЫМ ТЕКСТОМ для
 * ключа из запроса (Bearer или ?token=). Для слабых агентов/сетей: не нужен
 * JSON-RPC-хендшейк — один GET, человекочитаемые строки:
 *
 *   имя — описание; обязательные аргументы: a, b
 *
 * Фильтры те же, что у RPC: уровень доступа ключа + настройки хаба.
 */
class Controller_Nj160_Catalog extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();

        $given = (string) $this->request->headers('authorization');
        $plain = '';

        if (preg_match('/^Bearer\s+(.+)$/i', $given, $m))
        {
            $plain = trim($m[1]);
        }
        else
        {
            $plain = trim((string) $this->request->query('token'));
        }

        $user_id = NULL;
        $is_admin = FALSE;

        if ($plain !== '' AND class_exists('Service_Nj160_Tokens'))
        {
            [$ok, $user_id] = Service_Nj160_Tokens::authenticate($plain);

            if ($ok)
            {
                $is_admin = Service_Nj160_Tokens::is_admin($user_id);
            }
        }

        if ($user_id === NULL)
        {
            // legacy-серверный ключ — тоже каталог (уровень админа)
            $token = trim((string) getenv('MCP_TOKEN'));
            $dev = Kohana::$environment === Kohana::DEVELOPMENT;

            if (($token !== '' AND hash_equals($token, $plain)) OR ($token === '' AND $dev))
            {
                $is_admin = TRUE;
            }
            else
            {
                $this->response->status(403)
                    ->headers('Content-Type', 'text/plain; charset=utf-8')
                    ->body('Недействительный токен — каталог недоступен');

                return;
            }
        }

        $lines = ['Каталог MCP-инструментов N2J.ru (доступно вам: '.count(Service_Nj160_Registry::tools_for($is_admin)).')', ''];

        foreach (Service_Nj160_Registry::tools_for($is_admin) as $name => $t)
        {
            $req = [];
            foreach ((array) ($t['inputSchema']['required'] ?? []) as $rn)
            {
                $req[] = (string) $rn;
            }

            $lines[] = $name.' — '.(string) ($t['description'] ?? '')
                .($req ? '; обязательные аргументы: '.implode(', ', $req) : '');
        }

        $lines[] = '';
        $lines[] = 'Поиск под задачу: POST /mcp/api/rpc {"method":"assistant.find","params":{"q":"что нужно"}}';
        $lines[] = 'Вызов через роутер: POST /mcp/api/rpc {"method":"assistant.call","params":{"tool":"имя","arguments":{…}}}';

        $this->response->headers('Content-Type', 'text/plain; charset=utf-8')
            ->body(implode("\n", $lines));
    }

}
