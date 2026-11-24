<?php

use PHPUnit\Framework\TestCase;

/**
 * Идемпотентность: контракт без базы + smoke на PostgreSQL.
 *
 * Запуск: php tests/Support/mini_runner.php PrxIdempotency
 * Для smoke нужна применённая миграция prx_110_005; без базы тест пропускается.
 */
class PrxIdempotencyTest extends TestCase
{
    public function test_key_charset_matches_the_documented_one(): void
    {
        $this->assertSame(1, preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', 'erp-order-42-v1'));
        $this->assertSame(1, preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', 'a'));
        $this->assertSame(1, preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', str_repeat('x', 128)));
        $this->assertSame(0, preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', ''));
        $this->assertSame(0, preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', 'с пробелом'));
        $this->assertSame(0, preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', str_repeat('x', 129)));
        $this->assertSame(0, preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', "line\nbreak"));
    }

    public function test_bad_input_is_rejected_before_any_query(): void
    {
        $db = Database::instance();

        foreach ([['scope', 'owner', 'bad key!', str_repeat('a', 64)], ['scope', 'owner', 'k', 'not-a-hash']] as $args)
        {
            try {
                Prx_Idempotency::begin($args[0], $args[1], $args[2], $args[3], $db);
                $this->fail('Ожидалось исключение до обращения к базе.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_replay_conflict_and_expired_on_postgres(): void
    {
        if ( ! $this->postgres_ready())
        {
            $this->markTestSkipped('Нет PostgreSQL: нужен стенд с миграцией prx_110_005.');
        }

        $db = Database::instance();
        $key = 'test-'.bin2hex(random_bytes(6));
        $hash = hash('sha256', 'body-v1');

        $first = Prx_Idempotency::begin('prx.test', 'phpunit', $key, $hash, $db);
        $this->assertSame(Prx_Idempotency::STATE_NEW, $first['state']);
        Prx_Idempotency::finish((int) $first['id'], ['job_id' => 42], $db);

        // Тот же ключ и то же тело — тот же результат, без повторного выполнения.
        $replay = Prx_Idempotency::begin('prx.test', 'phpunit', $key, $hash, $db);
        $this->assertSame(Prx_Idempotency::STATE_REPLAY, $replay['state']);
        $this->assertSame(['job_id' => 42], $replay['result']);

        // То же тело, другой ключ — новая запись.
        $other = Prx_Idempotency::begin('prx.test', 'phpunit', $key.'-2', $hash, $db);
        $this->assertSame(Prx_Idempotency::STATE_NEW, $other['state']);
        Prx_Idempotency::abort($db);

        // Другое тело с тем же ключом — конфликт.
        $conflict = Prx_Idempotency::begin('prx.test', 'phpunit', $key, hash('sha256', 'body-v2'), $db);
        $this->assertSame(Prx_Idempotency::STATE_CONFLICT, $conflict['state']);

        // Другой владелец — свой ключ.
        $foreign = Prx_Idempotency::begin('prx.test', 'someone-else', $key, $hash, $db);
        $this->assertSame(Prx_Idempotency::STATE_NEW, $foreign['state']);
        Prx_Idempotency::abort($db);

        // Истёкший TTL — expired, а не replay.
        $db->query(Database::UPDATE, 'UPDATE '.Prx_Idempotency::TABLE
            .' SET expires_at = now() - interval \'1 second\' WHERE id = '.(int) $first['id'], FALSE);
        $expired = Prx_Idempotency::begin('prx.test', 'phpunit', $key, $hash, $db);
        $this->assertSame(Prx_Idempotency::STATE_EXPIRED, $expired['state']);

        $db->query(Database::DELETE, 'DELETE FROM '.Prx_Idempotency::TABLE.' WHERE scope = \'prx.test\'', FALSE);
    }

    private function postgres_ready(): bool
    {
        try {
            Database::instance()->query(Database::SELECT, 'SELECT 1 FROM '.Prx_Idempotency::TABLE.' LIMIT 1', FALSE);
            return TRUE;
        } catch (Throwable $e) {
            return FALSE;
        }
    }
}
