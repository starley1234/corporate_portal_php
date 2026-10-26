<?php

use PHPUnit\Framework\TestCase;

/**
 * Контракт MCP-схем: `tools/list` обязан быть валидным JSON Schema.
 *
 * Почему это отдельный тест, а не проверка «массив непустой». 23.10.2026
 * владелец подключил шлюз к OpenCode v2.1.4 и получил:
 *
 *   ✗ mcphub failed: Invalid result for tools/list:
 *     [{ "path": ["tools", 2, "inputSchema", "properties"],
 *        "message": "Invalid input: expected record, received array" }]
 *
 * Причина — `'properties' => []` в конфиге инструмента без аргументов: PHP
 * кодирует пустой массив как `[]`, а спецификация MCP (JSON Schema) требует
 * ОБЪЕКТ («карта имя → схема»). Клиент со строгой валидацией отвергает ВЕСЬ
 * ответ, и агент не видит ни одного инструмента — даже те, чьи схемы в порядке.
 *
 * Тест проверяет то, что реально уходит клиенту: json_encode, затем строгий
 * разбор. Проверять PHP-массив бессмысленно — `[]` и `{}` в нём неотличимы.
 */
class McpSchemaTest extends TestCase {

    /** Ключи, значение которых по спецификации — объект (record), а не список. */
    private static $maps = ['properties', 'patternProperties', '$defs', 'definitions'];

    /**
     * Рекурсивно собрать пути, где «карта» закодирована массивом.
     *
     * Работаем по декодированному БЕЗ assoc JSON: только так видно разницу
     * между `[]` и `{}`.
     */
    private function offenders($value, $path = '')
    {
        $found = [];

        if (is_object($value))
        {
            foreach (get_object_vars($value) as $key => $sub)
            {
                $here = $path === '' ? $key : $path.'.'.$key;

                if (in_array($key, self::$maps, TRUE) AND is_array($sub))
                {
                    $found[] = $here.' = [] (нужен объект {})';
                }

                $found = array_merge($found, $this->offenders($sub, $here));
            }
        }
        elseif (is_array($value))
        {
            foreach ($value as $index => $sub)
            {
                $found = array_merge($found, $this->offenders($sub, $path.'['.$index.']'));
            }
        }

        return $found;
    }

    public function test_gateway_tools_list_is_valid_json_schema()
    {
        $json = json_encode(['tools' => Service_Nj676_Gateway::tool_list()], JSON_UNESCAPED_UNICODE);
        $this->assertTrue(is_string($json), 'tools/list не кодируется в JSON');

        $decoded = json_decode($json);
        $this->assertSame([], $this->offenders($decoded), 'tools/list шлюза нарушает JSON Schema');

        // Корневые инструменты — контракт, на который рассчитывают агенты.
        // Список живёт в одной константе: контроллер и тесты не расходятся.
        $names = array_map(static function ($tool) { return $tool['name']; }, Service_Nj676_Gateway::tool_list());
        $this->assertSame(Service_Nj676_Gateway::ROOT_TOOLS, $names);
        $this->assertContains('hub_describe_tool', $names);
        $this->assertContains('hub_feedback', $names);
        $this->assertSame(count($names), count(array_unique($names)), 'имена инструментов повторяются');
    }

    public function test_tool_without_arguments_keeps_object_properties()
    {
        $tools = Service_Nj676_Gateway::tool_list();
        $status = NULL;

        foreach ($tools as $tool)
        {
            if ($tool['name'] === 'hub_status')
            {
                $status = $tool;
            }
        }

        $this->assertNotNull($status, 'hub_status пропал из tools/list');
        $this->assertStringContainsString('"properties":{}', json_encode($status['inputSchema']));
        $this->assertStringNotContainsString('"properties":[]', json_encode($status['inputSchema']));
    }

    public function test_platform_tools_list_is_valid_json_schema()
    {
        // /mcp (nj160) собирает схемы из конфигов модулей — там тоже встречаются
        // инструменты без аргументов. Один такой валит tools/list целиком.
        $json = json_encode(['tools' => Service_Nj160_Registry::mcp_list(TRUE)], JSON_UNESCAPED_UNICODE);
        $decoded = json_decode($json);

        $this->assertSame([], $this->offenders($decoded), 'tools/list платформы нарушает JSON Schema');
    }

    public function test_remote_tool_schemas_survive_jsonb_round_trip()
    {
        // Схемы удалённых серверов живут в jsonb: `{}` читается в PHP-массив и
        // при повторном кодировании снова стал бы `[]`. Проверяем оба пути.
        $tools = [[
            'name' => 'ping',
            'description' => 'Без аргументов',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ]];

        $json = json_encode(Service_Nj676_McpHub::schemas_as_objects($tools));
        $this->assertStringContainsString('"properties":{}', (string) $json);

        // Непустые карты и вложенность не должны пострадать.
        $nested = [[
            'name' => 'get_weather',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'city' => ['type' => 'string', 'description' => 'Город'],
                    'opts' => ['type' => 'object', 'properties' => []],
                ],
                'required' => ['city'],
            ],
        ]];

        $decoded = json_decode(json_encode(Service_Nj676_McpHub::schemas_as_objects($nested)));
        $this->assertSame([], $this->offenders($decoded));
        $this->assertSame(['city'], (array) $decoded[0]->inputSchema->required);
        $this->assertTrue(isset($decoded[0]->inputSchema->properties->city));
    }
}
