<?php
/** NJ-674 MCP Guard: локальные статические проверки без сетевых вызовов. */
if ( ! defined('SYSPATH')) { define('SYSPATH', '/app/'); }
require '/app/application/modules/nj674_mcpguard/classes/Service/Nj674/McpGuard.php';
$bad = 0;
function check_mcp($label, $ok) { global $bad; if ( ! $ok) { $bad++; echo "ФЕЙЛ: $label\n"; } }

$sample = [
    'tools' => [[
        'name' => 'execute_shell', 'description' => 'Runs a command',
        'inputSchema' => ['type' => 'object', 'properties' => ['cmd' => ['type' => 'string']], 'additionalProperties' => TRUE],
        'annotations' => ['openWorldHint' => TRUE, 'destructiveHint' => TRUE, 'readOnlyHint' => TRUE],
    ], [
        'name' => 'lookup', 'description' => 'Read only lookup',
        'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'description' => 'id']], 'required' => ['id'], 'additionalProperties' => FALSE],
        'annotations' => ['readOnlyHint' => TRUE],
    ]],
];
$r = Service_Nj674_McpGuard::analyze(json_encode($sample));
$codes = array_column($r['tools'][0]['findings'], 'code');
check_mcp('dangerous name flagged', in_array('powerful_name', $codes, TRUE));
check_mcp('contradictory annotations flagged', in_array('contradictory_annotations', $codes, TRUE));
check_mcp('open world flagged', in_array('open_world', $codes, TRUE));
check_mcp('broad additional properties flagged', in_array('extra_properties', $codes, TRUE));
check_mcp('clean read-only tool gets no findings', $r['tools'][1]['findings'] === []);
check_mcp('response scope explicitly says no network calls', strpos($r['scope'], 'не вызываются') !== FALSE);
check_mcp('bad JSON returns a useful error', Service_Nj674_McpGuard::analyze('{')['error'] !== '');
if ($bad) { exit(1); }
echo "MCP Guard: 7 checks passed\n";
