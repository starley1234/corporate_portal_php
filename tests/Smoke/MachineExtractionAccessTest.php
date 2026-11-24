<?php
use PHPUnit\Framework\TestCase;

/** Настоящие ключи, обработчик API, policy, журнал и очередь на временной PostgreSQL. */
class MachineExtractionAccessTest extends TestCase
{
    private function issuer()
    {
        $user = ORM::factory('User');
        $suffix = bin2hex(random_bytes(6));
        $user->username = 'machine-'.$suffix;
        $user->email = $suffix.'@example.test';
        $user->password = bin2hex(random_bytes(16));
        $user->create();
        return $user;
    }

    /** Прямой вызов action: тестируется respond(), не сетевой сервер и не UI-bootstrap. */
    private function request(int $job, ?string $token): Response
    {
        $request = Request::factory('api/v1/document-extractions/'.$job)->method(Request::GET);
        if ($token !== NULL) $request->headers('Authorization', 'Bearer '.$token);
        $response = Response::factory();
        $controller = new Controller_Prx395_Api($request, $response);
        $controller->action_extraction();
        $this->assertSame('no-store', $response->headers('Cache-Control'));
        return $response;
    }

    public function test_keys_and_ui_have_separate_owners_and_api_enforces_credentials(): void
    {
        $db = Database::instance();
        $config = Kohana::$config->load('prx150_api_hub');
        $previous_key = $config->get('ip_hash_key');
        $previous_ip = Request::$client_ip;
        $config->set('ip_hash_key', 'test-only-hmac-key');
        Request::$client_ip = '192.0.2.5';
        $db->begin();
        try
        {
            $issuer = $this->issuer();
            [$id, $token] = Service_Prx150_Keys::create('owner', ['prx395.extract'], 600, NULL, (int) $issuer->id);
            [$other, $other_token] = Service_Prx150_Keys::create('other', ['prx395.extract'], 600, NULL, (int) $issuer->id);
            [$wrong, $wrong_token] = Service_Prx150_Keys::create('wrong scope', ['prx450.parse'], 600, NULL, (int) $issuer->id);
            $job = Service_Prx120_JobQueue::push('document.extract', [], ['module' => 'prx395']);
            $key = str_repeat('a', 64);
            $db->query(Database::INSERT, 'INSERT INTO prx_395_extractions (job_id, api_client_id, request_key, file_sha256, file_id) VALUES ('
                .(int) $job.','.$id.','.$db->quote($key).','.$db->quote($key).',1)', FALSE);

            $response = $this->request($job, NULL);
            $this->assertSame(401, $response->status());
            $this->assertSame('unauthorized', json_decode($response->body(), TRUE)['error']['code']);
            $this->assertSame(403, $this->request($job, $wrong_token)->status());
            $this->assertSame(404, $this->request($job, $other_token)->status());
            $response = $this->request($job, $token);
            $this->assertSame(200, $response->status(), $response->body());
            $this->assertSame('queued', json_decode($response->body(), TRUE)['status']);
            $usage_id = (int) $response->headers('X-Request-Id');
            $this->assertGreaterThan(0, $usage_id);
            $logged = $db->query(Database::SELECT, 'SELECT ip_hash, client_id, status FROM prx_150_api_usage WHERE id = '.$usage_id, FALSE)->current();
            $this->assertSame(hash_hmac('sha256', '192.0.2.5', 'test-only-hmac-key'), $logged['ip_hash']);
            $this->assertSame($id, (int) $logged['client_id']);
            $this->assertSame(200, (int) $logged['status']);

            $service = new Service_Prx395_Extraction;
            $actor = new Prx_Actor((int) $issuer->id, ['admin']);
            try {
                $service->status($actor, $job);
                $this->fail('UI admin cannot read API-owned job');
            } catch (Prx395_Error $e) { $this->assertSame(404, $e->getCode()); }
            $db->query(Database::UPDATE, 'UPDATE prx_395_extractions SET api_client_id = '.(-$actor->id()).' WHERE job_id = '.(int) $job, FALSE);
            $this->assertSame('queued', $service->status($actor, $job)['status']);
            $this->assertSame(404, $this->request($job, $token)->status());

            $db->query(Database::UPDATE, 'UPDATE prx_395_extractions SET expires_at = now() - interval \'1 second\' WHERE job_id = '.(int) $job, FALSE);
            try {
                $service->status($actor, $job);
                $this->fail('Expired job is hidden');
            } catch (Prx395_Error $e) { $this->assertSame(404, $e->getCode()); }

            $this->assertTrue(Service_Prx150_Keys::revoke($id));
            $response = $this->request($job, $token);
            $this->assertSame(403, $response->status());
            $this->assertSame('key_revoked', json_decode($response->body(), TRUE)['error']['code']);
            $db->query(Database::UPDATE, 'UPDATE prx_150_api_clients SET expires_at = now() - interval \'1 second\' WHERE id = '.$other, FALSE);
            $response = $this->request($job, $other_token);
            $this->assertSame(403, $response->status());
            $this->assertSame('key_expired', json_decode($response->body(), TRUE)['error']['code']);

            // Один разрешённый запрос исчерпывает мягкий лимит 1/мин.
            [$limited, $limited_token] = Service_Prx150_Keys::create('limited', ['prx395.extract'], 1, NULL, (int) $issuer->id);
            $this->assertSame(404, $this->request($job, $limited_token)->status());
            $this->assertSame(429, $this->request($job, $limited_token)->status());
            $usage = $db->query(Database::SELECT, 'SELECT status FROM prx_150_api_usage WHERE client_id = '.$limited.' ORDER BY id', FALSE)->as_array();
            $this->assertSame([404, 429], array_map('intval', array_column($usage, 'status')));
        }
        finally
        {
            $db->rollback();
            $config->set('ip_hash_key', $previous_key);
            Request::$client_ip = $previous_ip;
        }
    }
}
