<?php

use PHPUnit\Framework\TestCase;

/**
 * Политика доступа к результатам Vision: личные записи по аккаунту, анонимные
 * — только по стабильной метке браузера.
 *
 * Запуск: php tests/mini_runner.php VisionOwnership
 */
class VisionOwnershipTest extends TestCase
{
    public function test_account_run_requires_the_same_account()
    {
        $run = ['user_id' => 12, 'visitor' => 'browser-a'];

        $this->assertTrue(Vision::run_owned($run, 'browser-b', 12),
            'Вошедший владелец может открыть прогон с другого устройства');
        $this->assertFalse(Vision::run_owned($run, 'browser-a', NULL),
            'Совпадения visitor недостаточно для аккаунтной записи');
        $this->assertFalse(Vision::run_owned($run, 'browser-a', 13));
    }

    public function test_anonymous_run_requires_the_same_nonempty_visitor()
    {
        $run = ['user_id' => 0, 'visitor' => 'browser-a'];

        $this->assertTrue(Vision::run_owned($run, 'browser-a', NULL));
        $this->assertTrue(Vision::run_owned($run, 'browser-a', 12),
            'Вошедший пользователь сохраняет доступ к своей старой анонимной записи');
        $this->assertFalse(Vision::run_owned($run, 'browser-b', 12));
        $this->assertFalse(Vision::run_owned($run, '', NULL));
    }
}
