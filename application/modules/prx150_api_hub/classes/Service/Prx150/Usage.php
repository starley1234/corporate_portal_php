<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Журнал использования внешнего API: одна строка на запрос.
 *
 * Пишем в `finally` даже при ошибке — иначе 4xx/5xx выпадут из мониторинга.
 * Сбой журнала не должен ломать ответ потребителя: ловим и пишем в лог.
 * IP храним только HMAC-хешем и только при заданном ключе.
 */
class Service_Prx150_Usage
{
    /** @return int id записи или 0, если журнал недоступен */
    public static function log(
        array $client,
        string $scope,
        Request $request,
        int $status,
        float $duration,
        int $response_bytes
    ): int {
        try
        {
            $config = Kohana::$config->load('prx150_api_hub');
            $ip = trim((string) $request->client_ip());
            $key = (string) $config->get('ip_hash_key');
            $ip_hash = ($key !== '' AND $ip !== '' AND $ip !== '0.0.0.0')
                ? hash_hmac('sha256', $ip, $key) : NULL;
            $path = mb_substr('/'.ltrim($request->uri(), '/'), 0, 512);
            $request_bytes = (int) $request->headers('Content-Length');

            [$id] = Prx_Db::insert(
                'INSERT INTO prx_150_api_usage '
                .'(client_id, scope, method, path, status, duration_ms, request_bytes, response_bytes, ip_hash) '
                .'VALUES (:client, :scope, :method, :path, :status, :ms, :req, :res, :ip) RETURNING id',
                [
                    ':client' => (int) $client['id'],
                    ':scope'  => $scope,
                    ':method' => mb_strtoupper(mb_substr((string) $request->method(), 0, 8)),
                    ':path'   => $path,
                    ':status' => $status,
                    ':ms'     => max(0, (int) round($duration * 1000)),
                    ':req'    => max(0, $request_bytes),
                    ':res'    => max(0, $response_bytes),
                    ':ip'     => $ip_hash,
                ]
            );

            Prx_Db::execute(
                'UPDATE prx_150_api_clients '
                .'SET last_used_at = now(), last_used_ip_hash = :ip WHERE id = :id',
                [':id' => (int) $client['id'], ':ip' => $ip_hash]
            );

            return (int) $id;
        }
        catch (Throwable $e)
        {
            if (Kohana::$log)
            {
                Kohana::$log->add(Log::WARNING, 'prx150: usage log failed: :e', [':e' => $e->getMessage()]);
            }

            return 0;
        }
    }
}
