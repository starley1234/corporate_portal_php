<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты модуля nj594_orgregistry («Справочник организаций»).
 * Собираются хабом nj160_mcp и отдаются агентам по /mcp/api/rpc.
 */

return [
    'tools' => [
        [
            'name'        => 'nj594_org_search',
            'description' => 'Поиск организации в справочнике N2J.ru по ОГРН/ОГРНИП, ИНН или названию. Возвращает список карточек: ОГРН, ИНН, название, адрес, статус.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'q'     => ['type' => 'string', 'description' => 'ОГРН/ОГРНИП, ИНН или часть названия организации'],
                    'limit' => ['type' => 'integer', 'description' => 'сколько карточек вернуть (1-20, по умолчанию 10)'],
                ],
                'required' => ['q'],
            ],
            'handler' => 'Service_Nj594_Orgs::mcp_search',
        ],
        [
            'name'        => 'nj594_org_card',
            'description' => 'Полная карточка организации из справочника N2J.ru: реквизиты ЕГРЮЛ/ЕГРИП (ОГРН, ИНН, КПП, ОКВЭД, адрес, статус, руководитель) и число связанных материалов модулей.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'ogrn' => ['type' => 'string', 'description' => 'ОГРН/ОГРНИП или ИНН организации'],
                ],
                'required' => ['ogrn'],
            ],
            'handler' => 'Service_Nj594_Orgs::mcp_card',
        ],
    ],
];
