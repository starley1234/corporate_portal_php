<?php

use PHPUnit\Framework\TestCase;

/**
 * Контракт шлюза MCP Hub: инструменты, коды ошибок, готовность к вызову.
 *
 * Почему отдельный тест. 23.10.2026 агент в реальном тесте спотыкался на трёх
 * вещах, которые видны только в ответе шлюза, а не в схеме:
 *
 *   1. `only_ready:true` возвращал сервер, которому нужен Bearer-ключ, —
 *      «готовность» была отдельным словом в описании, а не полем;
 *   2. чтобы узнать аргументы инструмента, агент зря дёргал `hub_call_tool`;
 *   3. ошибки приходили русской прозой, по которой нельзя ветвиться.
 *
 * Тест фиксирует контракт: переименование поля или потеря `dry_run` ломают
 * слабые модели, которые читают именно `structuredContent`.
 */
class McpGatewayTest extends TestCase {

    public function test_root_tools_are_the_documented_list()
    {
        $names = array_map(static function ($tool) { return $tool['name']; },
            Service_Nj676_Gateway::tool_list());

        $this->assertSame([
            'hub_search_tools', 'hub_ready', 'hub_describe_tool', 'hub_call_tool',
            'hub_feedback', 'hub_status', 'hub_configure',
        ], $names);
        $this->assertSame(Service_Nj676_Gateway::ROOT_TOOLS, $names);
    }

    public function test_ready_listing_needs_no_query()
    {
        // Агенту нужен ответ «что доступно», а не обязательный выдуманный
        // запрос: hub_ready — тот же поиск без query.
        $by_name = [];
        foreach (Service_Nj676_Gateway::tool_list() as $tool)
        {
            $by_name[$tool['name']] = $tool;
        }

        $this->assertArrayHasKey('hub_ready', $by_name);
        $this->assertArrayNotHasKey('query', $by_name['hub_ready']['inputSchema']['properties']);

        // hub_search_tools тоже больше не требует query.
        $this->assertSame([], $by_name['hub_search_tools']['inputSchema']['required']);
        $this->assertArrayHasKey('sort', $by_name['hub_search_tools']['inputSchema']['properties']);
        $this->assertArrayHasKey('ignore', $by_name['hub_search_tools']['inputSchema']['properties']);
    }

    public function test_invalid_endpoint_is_broken_not_ready()
    {
        $row = [
            'transport' => 'streamable_http', 'remote_url' => 'https://example.test/mcp',
            'auth_mode' => 'none', 'health_status' => 'unverified',
            'url_valid' => FALSE, 'url_note' => 'адрес не проходит проверку: нужен https',
            'tools' => [],
        ];

        $readiness = Service_Nj676_McpHub::readiness($row);

        $this->assertSame('broken', $readiness['readiness']);
        $this->assertFalse($readiness['ready']);
        $this->assertNotSame('', $readiness['note']);
    }

    public function test_unreadable_tools_list_is_degraded()
    {
        $row = [
            'transport' => 'streamable_http', 'remote_url' => 'https://example.test/mcp',
            'auth_mode' => 'none', 'health_status' => 'operational',
            'health_checked_at' => date('Y-m-d H:i:s'), 'url_valid' => TRUE,
            'tools' => [],
        ];

        $this->assertSame('degraded', Service_Nj676_McpHub::readiness($row)['readiness']);
    }

    public function test_schema_valid_is_never_a_string()
    {
        $this->assertFalse(Service_Nj676_McpHub::as_bool('f'));
        $this->assertTrue(Service_Nj676_McpHub::as_bool('t'));
        $this->assertNull(Service_Nj676_McpHub::as_bool(NULL));
        $this->assertFalse(Service_Nj676_McpHub::as_bool(0));
    }

    public function test_russian_word_forms_reach_english_tools()
    {
        // «курс валют» и «криптовалюта» давали 0 при 1007 серверах: каталог
        // англоязычный, а словарь переводил только точные слова.
        $this->assertSame('валют', Service_Nj676_McpHub::stem('валютами'));
        $this->assertSame('курс', Service_Nj676_McpHub::stem('курсами'));

        $terms = Service_Nj676_McpHub::terms('курс валют');
        $this->assertContains('currency', $terms);
        $this->assertContains('exchange', $terms);

        // Предлоги не должны становиться поисковыми словами.
        $this->assertNotContains('и', Service_Nj676_McpHub::terms('время и часовые пояса'));
    }

    public function test_search_filters_accept_readiness_and_install()
    {
        $filters = Service_Nj676_Gateway::search_filters([
            'readiness' => 'ready',
            'install' => 'none',
            'auth' => 'none',
            'max_latency_ms' => 800,
            'transport' => 'streamable_http',
        ], ['token_hash' => NULL]);

        $this->assertSame('ready', $filters['readiness']);
        $this->assertSame('none', $filters['install']);
        $this->assertSame('none', $filters['auth']);
        $this->assertSame(800, $filters['max_latency_ms']);
        $this->assertSame('streamable_http', $filters['transport']);
    }

    public function test_only_ready_means_no_key_no_install()
    {
        // Синоним должен быть буквальным: иначе «только готовое» снова
        // вернёт сервер с обязательным ключом.
        $filters = Service_Nj676_Gateway::search_filters(['only_ready' => TRUE], ['token_hash' => NULL]);

        $this->assertSame('ready', $filters['readiness']);
        $this->assertSame('none', $filters['auth']);
        $this->assertSame('none', $filters['install']);
    }

    public function test_agent_without_install_rights_gets_remote_only()
    {
        $this->assertSame('none',
            Service_Nj676_Gateway::search_filters(['no_install' => TRUE], ['token_hash' => NULL])['install']);
    }

    public function test_call_tool_advertises_dry_run()
    {
        foreach (Service_Nj676_Gateway::tool_list() as $tool)
        {
            if ($tool['name'] !== 'hub_call_tool')
            {
                continue;
            }

            $this->assertArrayHasKey('dry_run', $tool['inputSchema']['properties']);
            $this->assertSame('boolean', $tool['inputSchema']['properties']['dry_run']['type']);

            return;
        }

        $this->fail('hub_call_tool пропал из tools/list');
    }

    public function test_feedback_requires_a_real_call_id()
    {
        foreach (Service_Nj676_Gateway::tool_list() as $tool)
        {
            if ($tool['name'] === 'hub_feedback')
            {
                $this->assertSame(['call_id', 'rating'], $tool['inputSchema']['required']);

                return;
            }
        }

        $this->fail('hub_feedback пропал из tools/list');
    }

    public function test_error_codes_are_stable()
    {
        $this->assertSame('UPSTREAM_TIMEOUT', Service_Nj676_Gateway::upstream_code('таймаут 20 с'));
        $this->assertSame('AUTH_REQUIRED', Service_Nj676_Gateway::upstream_code('HTTP 401 Unauthorized'));
        $this->assertSame('RATE_LIMITED', Service_Nj676_Gateway::upstream_code('HTTP 429'));
        // Сеть и TLS — это не таймаут: повтор не поможет, и агент должен
        // увидеть разницу.
        $this->assertSame('UPSTREAM_ERROR', Service_Nj676_Gateway::upstream_code('сеть: TLS connect error'));
    }

    public function test_latin_and_russian_queries_meet_in_the_catalog()
    {
        // «wikipedia» и «курс валют» возвращали пусто при живом каталоге:
        // синонимы работали только из русского в английский.
        $this->assertContains('вики', Service_Nj676_McpHub::EN_TO_RU['wikipedia']);
        $this->assertContains('wiki', Service_Nj676_McpHub::EN_TO_RU['wikipedia']);
        $this->assertArrayHasKey('валют', Service_Nj676_McpHub::SYNONYMS);
    }
}
