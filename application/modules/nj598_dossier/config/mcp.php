<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты nj598_dossier («AI Досье на работодателя»).
 */

return [
    'tools' => [
        [
            'name'        => 'nj598_dossier_status',
            'description' => 'Статус AI-досье на работодателя N2J.ru по коду: статус разведки, прогресс, модель, сводка результата (готово — кратко, флаги).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'код досье (dos…) со страницы /dossier/<code>'],
                ],
                'required'   => ['code'],
            ],
            'handler'     => 'Service_Nj598_Dossier::mcp_status',
        ],
    ],
];
