<?php
use PHPUnit\Framework\TestCase;

/** 403 отвечает клиенту на его языке: JSON для API и fetch, HTML для браузера. */
class HttpErrorFormatTest extends TestCase
{
    private function req(string $uri, ?string $accept = NULL): Request
    {
        $request = new Request($uri);
        if ($accept !== NULL) $request->headers('Accept', $accept);
        return $request;
    }

    public function test_api_addresses_get_json(): void
    {
        foreach (['prx110/api/favorites', 'api/v1/spec-extractions/5', 'prx212_api/upload', 'prx120/api'] as $uri) {
            $this->assertTrue(HTTP_Exception_403::wants_json($this->req($uri)), $uri);
        }
    }

    public function test_pages_get_html_unless_client_asks_for_json(): void
    {
        foreach (['services', 'prx400_specflow', 'apiary', 'prx110/apis', 'capital/report'] as $uri) {
            $this->assertFalse(HTTP_Exception_403::wants_json($this->req($uri)), $uri);
        }
        $this->assertFalse(HTTP_Exception_403::wants_json($this->req('services', 'text/html,application/json;q=0.9')));
        $this->assertTrue(HTTP_Exception_403::wants_json($this->req('services', 'application/json')));
        $this->assertFalse(HTTP_Exception_403::wants_json(NULL));
    }

    public function test_json_body_keeps_reason(): void
    {
        $e = HTTP_Exception::factory(403, 'Сессия устарела');
        $e->request($this->req('prx110/api/favorites'));
        $response = $e->get_response();

        $this->assertSame(403, $response->status());
        $this->assertStringStartsWith('application/json', (string) $response->headers('Content-Type'));
        $this->assertSame(['ok' => FALSE, 'error' => 'forbidden', 'message' => 'Сессия устарела'], json_decode($response->body(), TRUE));
    }
}
