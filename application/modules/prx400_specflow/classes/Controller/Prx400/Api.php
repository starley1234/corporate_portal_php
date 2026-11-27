<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Внешнее API prx400 (ключ prx150; scope prx400.extract — загрузка и чтение,
 * prx400.review — работа с позициями). Полный контракт — README модуля и
 * страница /prx400_specflow/api-docs.
 *
 *   GET    /api/v1/spec-extractions                    список (status, q, from, to, page, per)
 *   POST   /api/v1/spec-extractions                    multipart file | files[] [, parse_mode, template_id]
 *   GET    /api/v1/spec-extractions/<id>               статус и результат
 *   DELETE /api/v1/spec-extractions/<id>               удалить                       [review]
 *   GET    /api/v1/spec-extractions/<id>/export        format=json|csv|xlsx|aggregate, bom=as_is|per_parent
 *   GET    /api/v1/spec-extractions/<id>/files/<n>     исходный PDF n
 *   GET    /api/v1/spec-extractions/<id>/rows          filter=all|disputed|ok|review|new|error|verified
 *   POST   /api/v1/spec-extractions/<id>/rows          добавить строку               [review]
 *   PATCH  /api/v1/spec-extractions/<id>/rows/<row>    правка полей и цен            [review]
 *   DELETE /api/v1/spec-extractions/<id>/rows/<row>    удалить строку                [review]
 *   POST   …/rows/<row>/verify {verified?}             подтвердить                    [review]
 *   POST   …/rows/<row>/code {code} | DELETE           код НСИ / снять ручной код    [review]
 *   POST   …/rows/<row>/split {quantities: [..]}       разделить по количествам      [review]
 *   POST   /api/v1/spec-extractions/<id>/verify-bulk   {min_score?}                  [review]
 *   POST   /api/v1/spec-extractions/<id>/relaunch      {parse_mode?, template_id?}   [review после разбора]
 *   POST   /api/v1/spec-extractions/<id>/finish        {accept_issues?}              [review]
 *   GET|PUT|DELETE /api/v1/spec-extractions/webhook    подписка на события
 *   GET    /api/v1/spec-catalog?q=&limit=              поиск в НСИ
 *
 * Тело — JSON (Content-Type: application/json) или форма. Ключ видит только
 * свои спецификации; чужая — 404. Ключ/scope/лимит/журнал — respond().
 */
class Controller_Prx400_Api extends Controller_Prx150_Machine
{
    protected const SCOPE = Service_Prx400_Specs::SCOPE;
    /** Поля тела, где допустим список скаляров. */
    private const LIST_FIELDS = ['quantities', 'events'];

    public function action_index(): void
    {
        $this->respond(function () {
            $this->guard_query();
            $id = (int) $this->request->param('id');
            $section = (string) $this->request->param('section');
            if ($id <= 0)
            {
                $this->collection();
                return;
            }
            switch ($section)
            {
                case '': $this->spec($id); return;
                case 'rows': $this->rows($id); return;
                case 'export': $this->export($id); return;
                case 'files': $this->file($id); return;
                case 'verify-bulk':
                    $this->require_method(Request::POST);
                    $this->_json_response((new Service_Prx400_Rows)->bulk_verify($this->principal(), $id, $this->input()['min_score'] ?? NULL));
                    return;
                case 'relaunch':
                    $this->require_method(Request::POST);
                    $input = $this->input();
                    (new Service_Prx400_Specs)->launch($this->principal(), $id, [
                        'parse_mode' => $input['parse_mode'] ?? NULL, 'template_id' => $input['template_id'] ?? NULL,
                    ]);
                    $this->_json_response(['spec_id' => $id, 'status' => 'queued', 'status_url' => self::url($id)], 202);
                    return;
                case 'finish':
                    $this->require_method(Request::POST);
                    (new Service_Prx400_Specs)->finish($this->principal(), $id, self::flag($this->input()['accept_issues'] ?? FALSE));
                    $this->_json_response(['spec_id' => $id, 'status' => 'done', 'status_url' => self::url($id)]);
                    return;
            }
            throw new Prx400_Error('not_found', 404);
        });
    }

    /** Подписка webhook ключа. */
    public function action_webhook(): void
    {
        $this->respond(function () {
            $this->guard_query();
            switch ($this->request->method())
            {
                case Request::GET:
                    $this->_json_response(Service_Prx400_Hooks::describe($this->principal()));
                    return;
                case Request::PUT:
                case Request::POST:
                    $input = $this->input();
                    $this->_json_response(Service_Prx400_Hooks::subscribe($this->principal(), (string) ($input['url'] ?? ''), $input['events'] ?? NULL));
                    return;
                case Request::DELETE:
                    Service_Prx400_Hooks::unsubscribe($this->principal());
                    $this->_json_response(['url' => NULL]);
                    return;
            }
            $this->method_not_allowed([Request::GET, Request::PUT, Request::DELETE]);
        });
    }

    /** Поиск в справочнике НСИ (ручной выбор кода интегратором). */
    public function action_catalog(): void
    {
        $this->respond(function () {
            $this->guard_query();
            $this->require_method(Request::GET);
            $provider = Service_Prx400_Catalog::provider();
            $this->_json_response([
                'provider' => $provider->name(),
                'items' => Service_Prx400_Catalog::search($this->principal(), (string) $this->request->query('q'), (int) ($this->request->query('limit') ?: 20)),
            ]);
        });
    }

    // ------------------------------------------------------------------

    private function collection(): void
    {
        $service = new Service_Prx400_Specs;
        if ($this->request->method() === Request::GET)
        {
            $list = $service->listing($this->principal(), (array) $this->request->query());
            $this->_json_response([
                'items' => array_map([Service_Prx400_Specs::class, 'list_item'], $list['rows']),
                'total' => $list['total'], 'page' => $list['page'], 'per' => $list['per'], 'pages' => $list['pages'],
            ]);
            return;
        }
        $this->require_method(Request::POST);
        Service_Prx400_Limits::assert_request_size((int) $this->request->headers('Content-Length'));
        $spec_id = $service->submit(
            $this->principal(),
            (string) $this->request->headers('Idempotency-Key'),
            $_FILES['files'] ?? ($_FILES['file'] ?? NULL),
            [
                'parse_mode' => $this->request->post('parse_mode'),
                'template_id' => $this->request->post('template_id'),
            ]
        );
        $this->_json_response([
            'spec_id' => $spec_id,
            'status' => 'queued',
            'status_url' => self::url($spec_id),
            'duplicates' => $service->duplicates($this->principal(), $spec_id),
        ], 202);
    }

    private function spec(int $id): void
    {
        $service = new Service_Prx400_Specs;
        if ($this->request->method() === Request::DELETE)
        {
            $service->delete($this->principal(), $id);
            $this->response->status(204);
            return;
        }
        $this->require_method(Request::GET);
        $this->_json_response($service->status($this->principal(), $id));
    }

    private function rows(int $id): void
    {
        $rows = new Service_Prx400_Rows;
        $row_id = (int) $this->request->param('row');
        $op = (string) $this->request->param('op');
        $method = $this->request->method();

        if ($row_id <= 0)
        {
            if ($method === Request::GET)
            {
                $this->_json_response($rows->listing($this->principal(), $id, (string) ($this->request->query('filter') ?: 'all')));
                return;
            }
            $this->require_method(Request::POST);
            $this->_json_response($rows->add($this->principal(), $id, $this->input()), 201);
            return;
        }

        Service_Prx400_Rows::require_in_spec($id, $row_id);
        $principal = $this->principal();
        $input = $op !== '' || $method !== Request::GET ? $this->input() : [];
        switch ($op.':'.$method)
        {
            case ':'.Request::GET:
                $this->_json_response(Service_Prx400_Rows::position_of($principal, $row_id));
                return;
            case ':PATCH':
            case ':'.Request::POST:
                $rows->edit($principal, $row_id, $input);
                break;
            case ':'.Request::DELETE:
                $rows->delete($principal, $row_id);
                $this->response->status(204);
                return;
            case 'verify:'.Request::POST:
                $rows->verify($principal, $row_id, array_key_exists('verified', $input) ? self::flag($input['verified']) : TRUE);
                break;
            case 'code:'.Request::POST:
            case 'code:'.Request::PUT:
                $rows->assign_code($principal, $row_id, (string) ($input['code'] ?? ''));
                break;
            case 'code:'.Request::DELETE:
                $rows->clear_code($principal, $row_id);
                break;
            case 'split:'.Request::POST:
                $parts = $input['quantities'] ?? NULL;
                if ( ! is_array($parts)) throw new Prx400_Error('split_invalid', 400);
                $this->_json_response($rows->split($principal, $row_id, $parts), 201);
                return;
            default:
                throw new Prx400_Error('method_not_allowed', 405); // op ограничен маршрутом
        }
        $this->_json_response(Service_Prx400_Rows::position_of($principal, $row_id));
    }

    private function export(int $id): void
    {
        $this->require_method(Request::GET);
        [$spec, $rows] = (new Service_Prx400_Specs)->export($this->principal(), $id);
        $format = (string) ($this->request->query('format') ?: 'json');
        $bom = (string) ($this->request->query('bom') ?: 'as_is');
        switch ($format)
        {
            case 'json':
                $this->_json_response(Service_Prx400_Export::json($spec, $rows));
                return;
            case 'aggregate':
                $this->_json_response(['spec_id' => $id, 'bom' => $bom, 'items' => Service_Prx400_Export::aggregate($rows, $bom)]);
                return;
            case 'csv':
                $this->binary('text/csv; charset=utf-8', 'spec-'.$id.'.csv', Service_Prx400_Export::csv($spec, $rows));
                return;
            case 'xlsx':
                $this->binary('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'spec-'.$id.'.xlsx',
                    Service_Prx400_Export::xlsx($spec, $rows, $bom));
                return;
        }
        throw new Prx400_Error('invalid_format', 400);
    }

    private function file(int $id): void
    {
        $this->require_method(Request::GET);
        $n = max(1, (int) $this->request->param('row'));
        $this->binary('application/pdf', 'spec-'.$id.'-'.$n.'.pdf', (new Service_Prx400_Specs)->pdf($this->principal(), $id, $n));
    }

    private function binary(string $type, string $name, string $body): void
    {
        $this->response->status(200);
        $this->response->headers('Content-Type', $type);
        $this->response->headers('Content-Disposition', 'attachment; filename="'.$name.'"');
        $this->response->headers('X-Content-Type-Options', 'nosniff');
        $this->response->body($body);
    }

    /** Тело запроса: JSON-объект или поля формы. */
    private function input(): array
    {
        $type = strtolower((string) $this->request->headers('Content-Type'));
        if (str_starts_with($type, 'application/json'))
        {
            $raw = (string) $this->request->body();
            if (strlen($raw) > 1048576) throw new Prx400_Error('request_too_large', 413);
            if (trim($raw) === '') return [];
            $data = json_decode($raw, TRUE);
            if ( ! is_array($data) OR array_is_list($data) && $data !== []) throw new Prx400_Error('invalid_json', 400);

            return Prx400_Input::scalars($data, self::LIST_FIELDS);
        }

        return Prx400_Input::scalars((array) $this->request->post(), self::LIST_FIELDS);
    }

    /** Параметры строки запроса — только скаляры (?q[]=… → 400, а не 500). */
    private function guard_query(): void
    {
        Prx400_Input::scalars((array) $this->request->query());
    }

    private static function flag($value): bool
    {
        return $value === TRUE || $value === 1 || (is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], TRUE));
    }

    private static function url(int $id): string
    {
        return '/api/v1/spec-extractions/'.$id;
    }

    private function require_method(string $method): void
    {
        if ($this->request->method() !== $method) $this->method_not_allowed([$method]);
    }

    private function method_not_allowed(array $methods): void
    {
        $this->response->headers('Allow', implode(', ', $methods));
        throw new Prx400_Error('method_not_allowed', 405);
    }
}
