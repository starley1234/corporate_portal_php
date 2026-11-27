<?php defined('SYSPATH') or die('No direct script access.');

/**
 * События итога разбора prx400: review (нужна проверка), done, failed.
 *
 *  - outbox webhook API-ключа (Service_Prx400_Hooks::enqueue) — в той же
 *    транзакции, что и смена статуса (queue() внутри транзакции);
 *  - шина Event::publish('prx400.spec.<status>') после COMMIT (publish()):
 *    колокольчик prx597 сотруднику-владельцу (target_user_id) и подписчики.
 */
final class Service_Prx400_Notify
{
    public const STATUSES = ['review', 'done', 'failed'];

    /** Внутри транзакции смены статуса. */
    public static function queue(int $spec_id, int $owner_id, string $status): void
    {
        if (in_array($status, self::STATUSES, TRUE)) Service_Prx400_Hooks::enqueue($owner_id, $spec_id, $status);
    }

    /** После COMMIT: сбой шины не отменяет записанный результат. */
    public static function publish(int $spec_id, string $status, ?string $error = NULL, ?int $initiator = NULL): void
    {
        if ( ! in_array($status, self::STATUSES, TRUE)) return;
        try {
            $spec = Model_Prx400_Spec::get($spec_id);
            if ($spec === NULL) return;
            $owner = (int) $spec['owner_id'];
            $payload = [
                'spec_id' => $spec_id,
                'status' => $status,
                'error' => $error,
                'title' => (string) $spec['original_name'],
                'href' => '/prx400_specflow/spec/'.$spec_id,
            ];
            if ($owner < 0) $payload['target_user_id'] = -$owner;
            Event::publish('prx400.spec.'.$status, $payload, $initiator);
        } catch (Throwable $e) {
            Log_Plain::event(Log::WARNING, 'prx400 spec=:s stage=notify error_type=:t', [':s' => $spec_id, ':t' => get_class($e)]);
        }
    }
}
