<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Базовый контроллер машинного API.
 *
 * Сервис-модуль наследует его, объявляет SCOPE и оборачивает действие в respond():
 *
 *   class Controller_Prx450_Api extends Controller_Prx150_Machine {
 *       protected const SCOPE = 'prx450.parse';
 *
 *       public function action_parse(): void {
 *           $this->respond(function () { ... });
 *       }
 *   }
 *
 * respond() проверяет ключ, scope и лимит, ловит Prx_DomainError, пишет
 * использование в finally и ставит X-Request-Id. Сессия и CSRF не нужны:
 * Контроллер явно объявляет машинный канал для Prx_PostGuard.
 */
abstract class Controller_Prx150_Machine extends Controller_Base
{
    public $auto_render = FALSE;
    protected $auth_required = FALSE;

    protected function machine_request(): bool
    {
        return TRUE; // respond() всегда проверяет ключ и scope до действия.
    }

    /** Scope, который требует этот контроллер; переопределяется в модуле. */
    protected const SCOPE = '';

    /** @var array|null строка ключа после проверки */
    protected $api_client = NULL;

    /**
     * Машинный principal: id ключа, имя, организация, scope.
     *
     * Отдаётся сервису вместо массива ролей: у внешнего потребителя нет ни
     * сессии, ни ACL сотрудника. Доступен только внутри respond().
     */
    public function principal(): Prx150_Principal
    {
        if ($this->api_client === NULL) throw new LogicException('Machine principal is available only inside respond()');
        return Service_Prx150_Keys::principal($this->api_client);
    }

    /**
     * Выполнить действие с проверкой ключа и журналом использования.
     *
     * Ожидается, что действие само отвечает JSON через _json_response().
     * Исключение Prx_DomainError (в т.ч. Prx150_Error) превращается в его
     * HTTP-статус и код; всё остальное — 503 service_unavailable без деталей.
     */
    protected function respond(callable $action): void
    {
        $this->api_client = NULL;
        $this->response->headers('Cache-Control', 'no-store');
        $start = microtime(TRUE);
        $status = 500;
        $client = NULL;

        try
        {
            $client = Service_Prx150_Keys::authenticate($this->request);
            if ( ! empty($client['via_previous']))
            {
                // Прежний ключ после перевыпуска (RFC 9745 / RFC 8594): работает
                // до Sunset, потребителю пора заменить ключ.
                $this->response->headers('Deprecation', 'true');
                $this->response->headers('Sunset', gmdate('D, d M Y H:i:s', strtotime((string) $client['prev_valid_until'])).' GMT');
                $this->response->headers('X-Prx-Key-Notice', 'rotated: replace the key before Sunset');
            }
            Service_Prx150_Keys::require_scope($client, static::SCOPE);
            Service_Prx150_Keys::rate_limit($client, static::SCOPE);
            $this->api_client = $client;

            $action();
            $status = (int) $this->response->status();
        }
        catch (Prx_DomainError $e)
        {
            $status = $e->getCode();

            if ($e->getMessage() === 'unauthorized')
            {
                $this->response->headers('WWW-Authenticate', 'Bearer realm="PRX API"');
            }

            $this->_json_response($e->payload(), $status);
        }
        catch (Throwable $e)
        {
            $status = 503;
            Kohana::$log->add(Log::ERROR, 'prx150: :method :uri error_type=:type', [
                ':method' => $this->request->method(),
                ':uri'    => $this->request->uri(),
                ':type'   => get_class($e),
            ]);
            $this->_json_response(['error' => ['code' => 'service_unavailable']], 503);
        }
        finally
        {
            $this->api_client = NULL;
            if ($client !== NULL)
            {
                $usage_id = Service_Prx150_Usage::log(
                    $client,
                    static::SCOPE,
                    $this->request,
                    $status,
                    microtime(TRUE) - $start,
                    strlen($this->response->body())
                );

                if ($usage_id > 0)
                {
                    $this->response->headers('X-Request-Id', (string) $usage_id);
                }
            }
        }
    }

}
