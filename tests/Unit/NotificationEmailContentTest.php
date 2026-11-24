<?php
use PHPUnit\Framework\TestCase;

/** Оформление писем без БД и транспорта; публичный фасад остаётся совместимым. */
class NotificationEmailContentTest extends TestCase
{
    private $old_url;
    protected function setUp(): void { $this->old_url = getenv('APP_URL'); putenv('APP_URL=https://portal.example.test/'); }
    protected function tearDown(): void { $this->old_url === FALSE ? putenv('APP_URL') : putenv('APP_URL='.$this->old_url); }

    public function test_shell_escapes_title_but_preserves_trusted_inner_html(): void
    {
        $html = Service_Prx597_Notify::email_shell('<Тема> "x"', '<p><b>Тело</b></p>');
        $this->assertStringContainsString('&lt;Тема&gt; &quot;x&quot;', $html);
        $this->assertStringContainsString('<p><b>Тело</b></p>', $html);
        $this->assertStringContainsString('https://portal.example.test/prx597', $html);
        $this->assertStringNotContainsString('Отключить письма', $html);
        $this->assertSame($html, Service_Prx597_EmailContent::shell('<Тема> "x"', '<p><b>Тело</b></p>'));
    }
    public function test_digest_escapes_content_sanitizes_links_and_preserves_empty_link_behavior(): void
    {
        $rows = [
            ['title' => '<script>bad</script>', 'body' => "<b>Текст</b>\n  ещё", 'href' => 'javascript:alert(1)', 'kind' => 'job_failed'],
            ['title' => 'Готово', 'body' => '', 'href' => '/tasks/ABC', 'kind' => 'job_done'],
            ['title' => 'Без ссылки', 'body' => '', 'href' => '', 'kind' => 'system'],
        ];
        $before = $rows;
        $html = Service_Prx597_Notify::digest_html($rows, 4);
        $this->assertSame($before, $rows);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('&lt;b&gt;Текст&lt;/b&gt; ещё', $html);
        $this->assertStringContainsString('https://portal.example.test/tasks/abc', $html);
        $this->assertStringContainsString('#dc2626', $html);
        $this->assertStringContainsString('#059669', $html);
        $this->assertStringContainsString('#2563eb', $html);
        $this->assertStringContainsString('И ещё уведомлений: <b>4</b>', $html);
        $this->assertSame(2, substr_count($html, 'Открыть &rarr;'));
        $this->assertStringNotContainsString('Отключить письма', $html);
    }
    public function test_base_url_fallback_and_empty_digest_do_not_recurse(): void
    {
        putenv('APP_URL');
        $this->assertSame('http://localhost:8080', Service_Prx597_EmailContent::base_url());
        $html = Service_Prx597_Notify::digest_html([]);
        $this->assertStringContainsString('http://localhost:8080/prx597', $html);
        $this->assertStringNotContainsString('И ещё уведомлений:', $html);
    }
}
