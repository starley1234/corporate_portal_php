<?php

use PHPUnit\Framework\TestCase;

/** Контракт nj510_qr; сам QR проверяется JS-тестами с независимым декодером. */
class QrServiceTest extends TestCase {

    public function test_initial_state_is_allowlisted()
    {
        $this->assertSame(['mode' => 'batch', 'type' => 'wifi'], Service_Nj510_Qr::initial_state('batch', 'wifi'));
        $this->assertSame(['mode' => 'single', 'type' => 'url'], Service_Nj510_Qr::initial_state('<script>', '<script>'));
        $this->assertSame(['mode' => 'single', 'type' => 'url'], Service_Nj510_Qr::initial_state([], []));
    }

    public function test_limits_and_safe_defaults()
    {
        $settings = Service_Nj510_Qr::settings();
        $this->assertSame(200, $settings['limits']['batch']);
        $this->assertSame(2097152, $settings['limits']['logo_bytes']);
        $this->assertSame(4, $settings['defaults']['margin']);
        $this->assertSame(16, $settings['defaults']['logo_size']);
        $this->assertSame('#ffffff', $settings['defaults']['background']);
        $this->assertContains($settings['defaults']['size'], $settings['sizes']);
        $this->assertSame(['png', 'svg', 'jpeg'], array_keys($settings['formats']));
    }

    public function test_presets_use_only_supported_shapes()
    {
        $settings = Service_Nj510_Qr::settings();
        foreach ($settings['presets'] as $preset)
        {
            $this->assertArrayHasKey($preset['shape'], $settings['shapes']);
            $this->assertArrayHasKey($preset['eye'], $settings['eyes']);
            $this->assertSame(1, preg_match('/^#[0-9a-f]{6}$/', $preset['foreground']));
            $this->assertSame(1, preg_match('/^#[0-9a-f]{6}$/', $preset['accent']));
        }
    }
}
