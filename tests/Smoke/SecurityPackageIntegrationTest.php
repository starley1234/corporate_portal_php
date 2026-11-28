<?php
use PHPUnit\Framework\TestCase;

/**
 * Пакет «Безопасность»: перевыпуск ключей prx150, журнал администратора
 * prx103, антивирус prx212 (фейковый clamd на unix-сокете). Всё — в
 * транзакции, которая откатывается.
 */
class SecurityPackageIntegrationTest extends TestCase
{
    private function auth(?string $token): array
    {
        $request = Request::factory('x');
        if ($token !== NULL) $request->headers('Authorization', 'Bearer '.$token);
        return Service_Prx150_Keys::authenticate($request);
    }

    private function auth_code(string $token): string
    {
        try { $this->auth($token); return 'ok'; }
        catch (Prx150_Error $e) { return $e->getMessage(); }
    }

    public function test_key_rotation_keeps_client_and_honours_grace_period(): void
    {
        $db = Database::instance();
        $db->begin();
        try
        {
            [$id, $first] = Service_Prx150_Keys::create('rotate-test', ['prx395.extract'], 600, NULL, 0);

            [$second, $until] = Service_Prx150_Keys::rotate($id, 24);
            $this->assertNotSame($first, $second);
            $this->assertNotNull($until);
            $this->assertGreaterThan(time() + 23 * 3600, strtotime($until));

            $old = $this->auth($first);
            $this->assertSame($id, (int) $old['id'], 'прежний ключ — тот же клиент');
            $this->assertTrue($old['via_previous']);
            $new = $this->auth($second);
            $this->assertSame($id, (int) $new['id']);
            $this->assertFalse($new['via_previous']);

            // Ответ на прежний ключ предупреждает потребителя заголовками.
            $response = Request::factory('api/v1/document-extractions/999999999')->method(Request::GET)
                ->headers('Authorization', 'Bearer '.$first)->execute();
            $this->assertSame('true', $response->headers('Deprecation'));
            $this->assertTrue(str_ends_with((string) $response->headers('Sunset'), ' GMT'), 'Sunset — HTTP-дата');
            $fresh = Request::factory('api/v1/document-extractions/999999999')->method(Request::GET)
                ->headers('Authorization', 'Bearer '.$second)->execute();
            $this->assertNull($fresh->headers('Deprecation'));

            // Без льготного срока прежний перестаёт работать сразу; первый — тоже.
            [$third, $none] = Service_Prx150_Keys::rotate($id, 0);
            $this->assertNull($none);
            $this->assertSame('unauthorized', $this->auth_code($second));
            $this->assertSame('unauthorized', $this->auth_code($first));
            $this->assertSame('ok', $this->auth_code($third));

            // Срок льготы истёк — прежний не принимается.
            [$fourth] = Service_Prx150_Keys::rotate($id, 1);
            Prx_Db::execute("UPDATE prx_150_api_clients SET prev_valid_until = now() - interval '1 minute' WHERE id = :id", [':id' => $id]);
            $this->assertSame('unauthorized', $this->auth_code($third));

            $this->assertTrue(Service_Prx150_Keys::revoke($id));
            $this->assertSame('key_revoked', $this->auth_code($fourth));

            try { Service_Prx150_Keys::rotate($id, 24); $this->fail('отозванный ключ не перевыпускается'); }
            catch (Prx150_Error $e) { $this->assertSame('key_revoked', $e->getMessage()); }

            [$exp_id] = Service_Prx150_Keys::create('expiring-test', ['prx395.extract'], 600, date('Y-m-d', time() + 3 * 86400), 0);
            $this->assertContains($exp_id, array_map('intval', array_column(Service_Prx150_Keys::expiring(14), 'id')));
            $this->assertNotContains($exp_id, array_map('intval', array_column(Service_Prx150_Keys::expiring(1), 'id')));
        }
        finally
        {
            $db->rollback();
        }
    }

    public function test_audit_log_redacts_secrets_and_is_append_only(): void
    {
        Service_Prx103_Audit::reset_cache();
        $db = Database::instance();
        $db->begin();
        try
        {
            $category = 'test_'.bin2hex(random_bytes(3));
            $id = Audit::log($category, 'user_updated', 42, [
                'password' => 'secret-value', 'password_changed' => TRUE,
                'nested' => ['api_token' => 'tok', 'Authorization' => 'Bearer x', 'kept' => 7],
                'roles_added' => ['admin'],
            ]);
            $this->assertGreaterThan(0, $id);

            $rows = Service_Prx103_Audit::search(['category' => $category, 'days' => 1]);
            $this->assertCount(1, $rows);
            $row  = $rows[0];
            $data = json_decode((string) $row['data'], TRUE);
            $this->assertSame('***', $data['password']);
            $this->assertTrue($data['password_changed'], 'флаг «пароль сменён» сохраняется');
            $this->assertSame('***', $data['nested']['api_token']);
            $this->assertSame('***', $data['nested']['Authorization']);
            $this->assertSame(7, $data['nested']['kept']);
            $this->assertSame(['admin'], $data['roles_added']);
            $this->assertSame('42', (string) $row['object_id']);
            $this->assertSame('cli', $row['actor']);
            $this->assertStringNotContainsString('secret-value', (string) $row['data']);

            // Устаревший вызов «данные вместо действия» не теряется.
            $this->assertGreaterThan(0, Audit::log($category, ['to' => 'a@example.test']));
            $this->assertCount(2, Service_Prx103_Audit::search(['category' => $category, 'days' => 1]));
            $this->assertCount(1, Service_Prx103_Audit::search(['category' => $category, 'object_id' => '42', 'days' => 1]));

            $this->expectException(Database_Exception::class);
            Prx_Db::execute('UPDATE prx_103_audit_log SET action = :a WHERE id = :id', [':a' => 'forged', ':id' => $id]);
        }
        finally
        {
            $db->rollback();
        }
    }

    public function test_antivirus_rejects_infected_files_and_respects_mode(): void
    {
        // Разбор ответа clamd.
        $this->assertFalse(Service_Prx212_Antivirus::parse('stream: OK')['infected']);
        $found = Service_Prx212_Antivirus::parse('stream: Win.Test.EICAR_HDB-1 FOUND');
        $this->assertTrue($found['infected']);
        $this->assertSame('Win.Test.EICAR_HDB-1', $found['signature']);
        try { Service_Prx212_Antivirus::parse('INSTREAM size limit exceeded. ERROR'); $this->fail('ERROR — исключение'); }
        catch (Prx212_Antivirus_Exception $e) { $this->assertStringContainsString('size limit', $e->getMessage()); }

        $tmp  = sys_get_temp_dir().'/prx212-av-'.bin2hex(random_bytes(6));
        mkdir($tmp, 0700);
        $sock = $tmp.'/clamd.sock';
        $proc = proc_open(['python3', DOCROOT.'../tests/Support/fake_clamd.py', $sock], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($proc), 'фейковый clamd запущен');
        $this->assertSame('ready', trim((string) fgets($pipes[1])));

        $config = Kohana::$config->load('prx212_storage');
        $saved  = $config->get('antivirus');
        Service_Prx103_Audit::reset_cache();
        $db = Database::instance();
        $db->begin();

        try
        {
            $clean = $tmp.'/clean.txt';
            $bad   = $tmp.'/bad.txt';
            file_put_contents($clean, str_repeat('чистый текст ', 20000)); // > одной порции
            file_put_contents($bad, 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');

            $this->assertFalse(Service_Prx212_Antivirus::scan_file($clean, 'unix://'.$sock, 5, 4096)['infected']);
            $this->assertTrue(Service_Prx212_Antivirus::scan_file($bad, 'unix://'.$sock)['infected']);

            // off — не проверяет вовсе; soft — clamd недоступен → принять; required → отклонить.
            $config->set('antivirus', ['mode' => 'off', 'address' => 'unix://'.$tmp.'/none.sock']);
            Service_Prx212_Antivirus::check($bad, 'bad.txt');
            $config->set('antivirus', ['mode' => 'soft', 'address' => 'unix://'.$tmp.'/none.sock', 'timeout' => 1]);
            Service_Prx212_Antivirus::check($clean, 'clean.txt');
            $config->set('antivirus', ['mode' => 'required', 'address' => 'unix://'.$tmp.'/none.sock', 'timeout' => 1]);
            try { Service_Prx212_Antivirus::check($clean, 'clean.txt'); $this->fail('required без clamd — отказ'); }
            catch (Prx212_Antivirus_Exception $e) { $this->assertStringContainsString('недоступен', $e->getMessage()); }

            // Через фасад: заражённый файл не записан, событие — в журнале.
            $config->set('antivirus', ['mode' => 'soft', 'address' => 'unix://'.$sock, 'timeout' => 5]);
            $backend = (int) Prx_Db::one("INSERT INTO prx_212_storage_backends (name, driver, config_data, is_active)
                VALUES (:n, 'local', :c, 1) RETURNING id", [
                ':n' => 'av-test-'.bin2hex(random_bytes(4)),
                ':c' => json_encode(['root_path' => $tmp.'/files'], JSON_UNESCAPED_SLASHES),
            ])['id'];
            $before = (int) Prx_Db::one('SELECT count(*) AS n FROM prx_212_files')['n'];
            $service = Service_Prx212_Storage::instance();

            try
            {
                $service->save(['tmp_name' => $bad, 'name' => 'invoice.pdf', 'size' => filesize($bad), 'type' => 'text/plain'],
                    ['backend_id' => $backend, 'module' => 'av_test']);
                $this->fail('заражённый файл должен быть отклонён');
            }
            catch (Prx212_Infected_Exception $e)
            {
                $this->assertStringContainsString('Eicar-Test-Signature', $e->getMessage());
            }
            $this->assertSame($before, (int) Prx_Db::one('SELECT count(*) AS n FROM prx_212_files')['n']);

            $events = Service_Prx103_Audit::search(['category' => 'security', 'q' => 'file_infected', 'days' => 1], 5);
            $this->assertNotEmpty($events);
            $data = json_decode((string) $events[0]['data'], TRUE);
            $this->assertSame('invoice.pdf', $data['file']);
            $this->assertSame('av_test', $data['module']);

            $ok_id = $service->save(['tmp_name' => $clean, 'name' => 'clean.txt', 'size' => filesize($clean), 'type' => 'text/plain'],
                ['backend_id' => $backend, 'module' => 'av_test']);
            $this->assertGreaterThan(0, (int) $ok_id);
        }
        finally
        {
            $db->rollback();
            $config->set('antivirus', $saved);
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf '.escapeshellarg($tmp));
        }
    }
}
