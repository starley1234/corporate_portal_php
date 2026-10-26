<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты модуля nj580_orgdocs («Документы организации»).
 * Собираются хабом nj160_mcp и отдаются агентам по /mcp/api/rpc.
 */

return [
    'tools' => [
        [
            'name'        => 'nj580_package_status',
            'description' => 'Статус пакета документов организации N2J.ru по коду: организация, статусы документов, доступные форматы (DOCX/PDF/XLSX). Без содержимого документов.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'код пакета, напр. org1a2b3c4d'],
                ],
                'required' => ['code'],
            ],
            'handler' => 'Service_Nj580_Orgdocs::mcp_pkg',
        ],
    ],
];
