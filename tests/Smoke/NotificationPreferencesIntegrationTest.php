<?php
use PHPUnit\Framework\TestCase;

/** Совместимость фасада настроек после извлечения Preferences. */
class NotificationPreferencesIntegrationTest extends TestCase
{
    private $db;
    private $user;
    protected function setUp(): void
    {
        $this->db = Database::instance();
        $this->db->begin();
        $suffix = bin2hex(random_bytes(6));
        $this->user = (int) DB::query(Database::SELECT,
            "INSERT INTO users (username, email, password) VALUES (:name, :email, 'not-a-login') RETURNING id")
            ->parameters([':name' => 'prefs-'.$suffix, ':email' => $suffix.'@example.test'])->execute()->get('id');
    }
    protected function tearDown(): void { $this->db->rollback(); }

    public function test_defaults_and_partial_save_keep_opt_in_and_public_contract(): void
    {
        $defaults = Service_Prx597_Notify::prefs_default($this->user);
        $this->assertSame($defaults, Service_Prx597_Notify::prefs($this->user));
        $this->assertTrue($defaults['in_app_enabled']);
        foreach (['email_enabled', 'telegram_enabled', 'max_enabled', 'on_done', 'on_failed'] as $field)
            $this->assertFalse($defaults[$field]);
        $this->assertSame(Service_Prx597_Preferences::FIELDS, Service_Prx597_Notify::PREF_FIELDS);
        // Первая вставка явно сохраняет значения всех полей: этот тест не меняет DDL defaults.
        Service_Prx597_Notify::save_prefs($this->user, $defaults);
        $saved = Service_Prx597_Notify::save_prefs($this->user, ['quiet_from' => '22', 'locale' => ' cs ', 'unknown' => TRUE]);
        $this->assertSame(22, $saved['quiet_from']);
        $this->assertSame('cs', $saved['locale']);
        $this->assertFalse($saved['email_enabled']);
        $this->assertTrue($saved['in_app_enabled']);
        $this->assertArrayNotHasKey('unknown', $saved);
        $this->assertSame($saved, Service_Prx597_Notify::save_prefs($this->user, []));
        $saved = Service_Prx597_Notify::save_prefs($this->user, ['digest_mode' => 'invalid']);
        $this->assertSame('off', $saved['digest_mode']);
        $this->assertSame(22, $saved['quiet_from']);
    }

    public function test_pg_false_values_and_form_normalization_are_unchanged(): void
    {
        Service_Prx597_Notify::save_prefs($this->user, Service_Prx597_Notify::prefs_default($this->user));
        $saved = Service_Prx597_Notify::save_prefs($this->user, ['email_enabled' => TRUE, 'on_failed' => TRUE, 'digest_mode' => 'daily']);
        $this->assertTrue($saved['email_enabled']);
        $this->assertTrue($saved['on_failed']);
        $this->assertSame('daily', $saved['digest_mode']);
        $saved = Service_Prx597_Notify::save_prefs($this->user, ['email_enabled' => FALSE, 'on_failed' => FALSE]);
        $this->assertFalse($saved['email_enabled']);
        $this->assertFalse($saved['on_failed']);
        $this->assertSame('daily', $saved['digest_mode']);
        $fields = Service_Prx597_Notify::user_pref_fields(['in_app_enabled' => 'f', 'quiet_from' => '9', 'locale' => ' ru ', 'unknown' => TRUE]);
        $this->assertSame(['in_app_enabled' => FALSE, 'quiet_from' => 9, 'locale' => 'ru'], $fields);
        $this->assertSame([], Service_Prx597_Notify::user_pref_fields([]));
    }
}
