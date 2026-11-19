<?php defined('SYSPATH') or die('No direct script access.');

/** Машинные маршруты: только явный токен, не cookies и не сессионная ACL. */
abstract class Controller_Prx395_Json extends Controller_Base
{
    public $auto_render = FALSE;
    protected $auth_required = FALSE;

    protected function respond(callable $action): void
    {
        $this->response->headers('Cache-Control', 'no-store');
        try
        {
            $action();
        }
        catch (Prx395_Error $e)
        {
            $this->_json_response(['error' => ['code' => $e->getMessage()]], $e->getCode());
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx395 operation=:action job_id=:id error_type=:type', [
                ':action' => $this->request->action(), ':id' => (int) $this->request->param('id'), ':type' => get_class($e),
            ]);
            $this->_json_response(['error' => ['code' => 'service_unavailable']], 503);
        }
    }

    protected function require_method(string $method): void
    {
        if ($this->request->method() !== $method)
        {
            $this->response->headers('Allow', $method);
            throw new Prx395_Error('method_not_allowed', 405);
        }
    }
}
