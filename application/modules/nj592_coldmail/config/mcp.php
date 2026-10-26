<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты модуля nj592_coldmail («Холодное письмо»).
 */

return [
    'tools' => [
        [
            'name'        => 'nj592_letter_status',
            'description' => 'Прогон «Холодного письма» N2J.ru по коду: компания, предложение, варианты первого абзаца (тема, абзац, обоснование), источники персонализации.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'код прогона, напр. cld1a2b3c4d'],
                ],
                'required' => ['code'],
            ],
            'handler' => 'Service_Nj592_Coldmail::mcp_letter',
        ],
    ],
];
