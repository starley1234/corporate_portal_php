<?php

use PHPUnit\Framework\TestCase;
/**
 * Регресс-тест Service_Prx597_Notify::_base_url() (prx597_notifications).
 *
 * Исторический баг: при пустом APP_URL метод звал сам себя (`self::_base_url()`)
 * без изменения состояния — бесконечная рекурсия. Cron `Prx603:Kick` с
 * pending-уведомлениями доходил до `_base_url()` через email_shell()/digest_html()
 * и раздувал память процесса, пока ядро не убивало его по OOM (~2.4 ГБ total-vm
 * на сервере с 1 ГБ RAM). Этот тест фиксирует контракт: пустой APP_URL обязан
 * давать детерминированный фолбэк, а не рекурсию.
 *
 * Специально НЕ вызываем публичные email-обёртки и digest-сборку — они тянут
 * БД и symfony/mailer. Тестируем точечный источник рекурсии через отражение.
 */
class NotifyBaseUrlTest extends TestCase {

    /** Сохранить/восстановить APP_URL вокруг проверки. */
    protected function _with_app_url($value, callable $fn)
    {
        $prev = getenv('APP_URL');

        if ($value === NULL)
        {
            putenv('APP_URL'); // убрать переменную
        }
        else
        {
            putenv('APP_URL='.$value);
        }

        try
        {
            return $fn();
        }
        finally
        {
            if ($prev === FALSE)
            {
                putenv('APP_URL');
            }
            else
            {
                putenv('APP_URL='.$prev);
            }
        }
    }

    /** Вызвать protected static _base_url(). */
    protected function _base_url()
    {
        $m = new ReflectionMethod('Service_Prx597_Notify', '_base_url');

        return $m->invoke(NULL);
    }

    public function test_base_url_with_app_url_set()
    {
        $this->_with_app_url('http://localhost:8080/', function () {
            // завершающий слэш rtrim-ится
            $this->assertSame('http://localhost:8080', $this->_base_url());
        });
    }

    public function test_base_url_with_other_host()
    {
        $this->_with_app_url('http://example.com', function () {
            $this->assertSame('http://example.com', $this->_base_url());
        });
    }

    public function test_base_url_empty_falls_back_to_prod()
    {
        // Критичный случай OOM: APP_URL не задан (старое .env без строки).
        // Должен вернуться фолбэк, а не рухнуть рекурсией/Fatal error.
        $this->_with_app_url(NULL, function () {
            $this->assertSame('http://localhost:8080', $this->_base_url());
        });
    }

    public function test_base_url_whitespace_falls_back_to_prod()
    {
        // getenv вернёт непустую строку из пробелов → rtrim -> '' -> фолбэк
        $this->_with_app_url('   ', function () {
            $this->assertSame('http://localhost:8080', $this->_base_url());
        });
    }
}
