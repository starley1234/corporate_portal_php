<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Идемпотентность внешних операций: один ключ — один результат.
 *
 * Семантика 1:1 как у prx395: тот же ключ и то же тело → тот же ответ;
 * другое тело → `conflict`; истёкший TTL → `expired`. Ключи живут в
 * PostgreSQL, поэтому работают при любом числе PHP-процессов.
 *
 * Низкоуровневый сценарий — когда работа должна быть в одной транзакции
 * с ключом (например, постановка задачи в prx120):
 *
 *   $idem = Prx_Idempotency::begin('prx395.extract', (string) $client_id, $key, $sha, $db);
 *   if ($idem['state'] !== Prx_Idempotency::STATE_NEW) { ... conflict/expired/replay }
 *   $job_id = Service_Prx120_JobQueue::push(...);       // та же транзакция
 *   Prx_Idempotency::finish($idem['id'], ['job_id' => $job_id], $db);
 *
 * begin() открывает транзакцию и берёт advisory-lock; finish() пишет
 * результат и подтверждает транзакцию; abort() откатывает. Открытая
 * транзакция возвращается ТОЛЬКО при state = new — в остальных случаях
 * begin() откатывает сам.
 *
 * $db передаёт вызывающий: он обязан быть тем же соединением, что и у
 * остальных записей транзакции, иначе они окажутся вне ключа.
 *
 * Для простых случаев есть run(): begin → операция → finish.
 */
final class Prx_Idempotency
{
    public const TABLE = 'prx_idempotency_keys';

    /** `[A-Za-z0-9._:-]{1,128}` — как Idempotency-Key у потребителя. */
    public const KEY_PATTERN = '\A[A-Za-z0-9._:-]{1,128}\z';

    public const STATE_NEW = 'new';
    public const STATE_REPLAY = 'replay';
    public const STATE_CONFLICT = 'conflict';
    public const STATE_EXPIRED = 'expired';

    /** TTL ключа, часов: `PRX_IDEMPOTENCY_TTL_HOURS`, по умолчанию 24. */
    public static function ttl_hours(): int
    {
        return max(1, (int) (getenv('PRX_IDEMPOTENCY_TTL_HOURS') ?: 24));
    }

    /**
     * Занять ключ. Открытая транзакция — только при state = new.
     *
     * @param  string $request_hash sha256 тела запроса (файла); само тело не хранится
     * @return array{state: string, id: int|null, result: array|null}
     */
    public static function begin(
        string $scope,
        string $owner,
        string $key,
        string $request_hash,
        ?Database $db = NULL
    ): array {
        $db = $db ?? Database::instance();

        if ( ! preg_match('/'.self::KEY_PATTERN.'/', $key))
        {
            throw new InvalidArgumentException('Недопустимый ключ идемпотентности');
        }

        if ( ! preg_match('/\A[a-f0-9]{64}\z/', $request_hash))
        {
            throw new InvalidArgumentException('Нужен sha256 запроса');
        }

        if ( ! $db->begin())
        {
            throw new RuntimeException('Не начата транзакция идемпотентности');
        }

        try
        {
            // Один ключ — один владелец: сериализуем конкурентные повторы.
            $lock = $db->quote($scope.'|'.$owner.'|'.$key);
            $db->query(Database::SELECT, 'SELECT pg_advisory_xact_lock(hashtextextended('.$lock.', 0))', FALSE);

            $row = $db->query(Database::SELECT, 'SELECT id, request_hash, result, status, '
                .'(expires_at <= now())::int AS expired FROM '.self::TABLE
                .' WHERE scope = '.$db->quote($scope)
                .' AND owner_key = '.$db->quote($owner)
                .' AND idem_key = '.$db->quote($key).' FOR UPDATE', FALSE)->current();

            if ( ! $row)
            {
                $row = $db->query(Database::SELECT, 'INSERT INTO '.self::TABLE
                    .' (scope, owner_key, idem_key, request_hash, status, expires_at) VALUES ('
                    .$db->quote($scope).', '.$db->quote($owner).', '.$db->quote($key).', '
                    .$db->quote($request_hash).", 'new', now() + interval '"
                    .self::ttl_hours()." hours') RETURNING id", FALSE)->current();

                return ['state' => self::STATE_NEW, 'id' => (int) $row['id'], 'result' => NULL];
            }

            $id = (int) $row['id'];

            if ((int) $row['expired'] === 1)
            {
                $db->rollback();
                return ['state' => self::STATE_EXPIRED, 'id' => $id, 'result' => NULL];
            }

            if ( ! hash_equals(trim((string) $row['request_hash']), $request_hash))
            {
                $db->rollback();
                return ['state' => self::STATE_CONFLICT, 'id' => $id, 'result' => NULL];
            }

            if ($row['status'] === 'done' AND $row['result'] !== NULL)
            {
                $result = json_decode((string) $row['result'], TRUE);
                $db->rollback();
                return ['state' => self::STATE_REPLAY, 'id' => $id, 'result' => is_array($result) ? $result : NULL];
            }

            // status = new после сбоя предыдущего запроса: замещаем, ключ наш.
            return ['state' => self::STATE_NEW, 'id' => $id, 'result' => NULL];
        }
        catch (Throwable $e)
        {
            $db->rollback();
            throw $e;
        }
    }

    /** Записать результат и подтвердить транзакцию, открытую в begin(). */
    public static function finish(int $id, array $result, ?Database $db = NULL): void
    {
        $db = $db ?? Database::instance();
        $updated = (int) $db->query(Database::UPDATE, 'UPDATE '.self::TABLE
            .' SET result = '.$db->quote(json_encode($result, JSON_UNESCAPED_UNICODE)).'::jsonb, '
            ."status = 'done' WHERE id = ".(int) $id, FALSE);

        if ($updated < 1)
        {
            $db->rollback();
            throw new RuntimeException('Идемпотентная запись не найдена: #'.$id);
        }

        if ( ! $db->commit())
        {
            throw new RuntimeException('Не подтверждена транзакция идемпотентности');
        }
    }

    /** Откатить транзакцию, открытую в begin(). Без транзакции — ничего не делает. */
    public static function abort(?Database $db = NULL): void
    {
        ($db ?? Database::instance())->rollback();
    }

    /**
     * Удобный сценарий: begin → операция → finish. Возвращает результат —
     * новый или сохранённый. `conflict`/`expired` — исключение с кодом состояния.
     *
     * @param callable(int): array $operation
     */
    public static function run(
        string $scope,
        string $owner,
        string $key,
        string $request_hash,
        callable $operation,
        ?Database $db = NULL
    ): array {
        $db = $db ?? Database::instance();
        $idem = self::begin($scope, $owner, $key, $request_hash, $db);

        if ($idem['state'] !== self::STATE_NEW)
        {
            if ($idem['state'] === self::STATE_REPLAY)
            {
                return (array) $idem['result'];
            }

            throw new Prx_Idempotency_Exception($idem['state']);
        }

        try
        {
            $result = (array) $operation((int) $idem['id']);
            self::finish((int) $idem['id'], $result, $db);
            return $result;
        }
        catch (Throwable $e)
        {
            self::abort($db);
            throw $e;
        }
    }
}
