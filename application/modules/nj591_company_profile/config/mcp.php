<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты модуля nj591_company_profile («Профиль компании»).
 * Собираются хабом nj160_mcp и отдаются агентам по /mcp/api/rpc.
 */

return [
    'tools' => [
        [
            'name'        => 'nj591_profile_status',
            'description' => 'Профиль компании N2J.ru по коду: сфера деятельности, примерный размер, контакты, стек технологий, ЛПР. Данные собираются воркером с сайта компании; отсутствующие поля честно пусты.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'код профиля, напр. cmp1a2b3c4d'],
                ],
                'required' => ['code'],
            ],
            'handler' => 'Service_Nj591_Company::mcp_profile',
        ],
    ],
];
