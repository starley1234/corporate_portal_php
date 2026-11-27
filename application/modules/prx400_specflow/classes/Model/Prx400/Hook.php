<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_hooks (подписка API-ключа) и prx_400_deliveries (outbox доставки).
 * Запись в outbox — в транзакции смены статуса спецификации; доставка —
 * Service_Prx400_Hooks::deliver_due (bin/console prx400:hooks).
 */
class Model_Prx400_Hook {

    public static function get(int $owner_id): ?array
    {
        return Prx_Db::one('SELECT * FROM prx_400_hooks WHERE owner_id = :o', [':o' => $owner_id]);
    }

    public static function save(int $owner_id, string $url, string $secret, string $events): void
    {
        Prx_Db::execute(
            'INSERT INTO prx_400_hooks (owner_id, url, secret, events) VALUES (:o, :u, :s, :e)
             ON CONFLICT (owner_id) DO UPDATE SET url = EXCLUDED.url, secret = EXCLUDED.secret,
                 events = EXCLUDED.events, updated_at = now()',
            [':o' => $owner_id, ':u' => $url, ':s' => $secret, ':e' => $events]);
    }

    public static function delete(int $owner_id): int
    {
        return Prx_Db::execute('DELETE FROM prx_400_hooks WHERE owner_id = :o', [':o' => $owner_id]);
    }

    public static function enqueue(int $owner_id, int $spec_id, string $event, array $payload): void
    {
        Prx_Db::execute(
            'INSERT INTO prx_400_deliveries (owner_id, spec_id, event, payload) VALUES (:o, :s, :e, CAST(:p AS jsonb))',
            [':o' => $owner_id, ':s' => $spec_id, ':e' => $event,
             ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    /**
     * Забрать пачку к отправке: next_at сдвигается на 5 минут (аренда), чтобы
     * параллельный запуск не отправил то же; SKIP LOCKED — без ожидания.
     */
    public static function claim_due(int $limit): array
    {
        return Prx_Db::select(
            'UPDATE prx_400_deliveries d SET next_at = now() + interval \'5 minutes\', attempts = attempts + 1
              WHERE d.id IN (SELECT id FROM prx_400_deliveries
                              WHERE status = \'pending\' AND next_at <= now()
                              ORDER BY next_at, id LIMIT '.max(1, $limit).' FOR UPDATE SKIP LOCKED)
              RETURNING d.*');
    }

    public static function mark_sent(int $id): void
    {
        Prx_Db::execute('UPDATE prx_400_deliveries SET status = \'sent\', sent_at = now(), last_error = NULL WHERE id = :id', [':id' => $id]);
    }

    public static function mark_failed(int $id, string $error, ?int $retry_in_sec): void
    {
        Prx_Db::execute(
            'UPDATE prx_400_deliveries
                SET last_error = :e,
                    status = CASE WHEN CAST(:retry AS int) IS NULL THEN \'dead\' ELSE \'pending\' END,
                    next_at = now() + (coalesce(CAST(:retry AS int), 0) * interval \'1 second\')
              WHERE id = :id',
            [':id' => $id, ':e' => mb_substr($error, 0, 64), ':retry' => $retry_in_sec]);
    }

    public static function recent(int $owner_id, int $limit = 20): array
    {
        return Prx_Db::select(
            'SELECT id, spec_id, event, status, attempts, last_error, created_at, sent_at, next_at
               FROM prx_400_deliveries WHERE owner_id = :o ORDER BY id DESC LIMIT '.max(1, $limit),
            [':o' => $owner_id]);
    }

    /** Отправленные/мёртвые старше N дней — уборка prx400:purge. */
    public static function purge(int $days): int
    {
        return Prx_Db::execute(
            'DELETE FROM prx_400_deliveries WHERE status IN (\'sent\', \'dead\')
               AND created_at < now() - (CAST(:d AS int) * interval \'1 day\')',
            [':d' => $days]);
    }
}
