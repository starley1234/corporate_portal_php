<?php defined('SYSPATH') or die('No direct script access.');

// Порядок задаёт каскадную ФС и порядок миграций. События — до очереди.
$modules = [
    'encrypt' => MODPATH.'encrypt',
    'auth' => MODPATH.'auth',
    'cache' => MODPATH.'cache',
    'minion' => MODPATH.'minion',
    'database' => MODPATH.'database',
    'orm' => MODPATH.'orm',
    'pagination' => MODPATH.'pagination',
    'PostgreSQL' => APPPATH.'modules/PostgreSQL',
    'llm' => APPPATH.'modules/llm',
    'prx100_base' => APPPATH.'modules/prx100_base',
    'prx111_migrations' => APPPATH.'modules/prx111_migrations',
    'prx100_platform' => APPPATH.'modules/prx100_platform',
    'prx100_search' => APPPATH.'modules/prx100_search',
    // Документация архитектуры: YAML -> страница и JSON (make architecture).
    'prx101_architecture' => APPPATH.'modules/prx101_architecture',
    'prx110_catalog' => APPPATH.'modules/prx110_catalog',
    'prx140_design_lab' => APPPATH.'modules/prx140_design_lab',
    'prx208_events' => APPPATH.'modules/prx208_events',
    'prx120_jobs' => APPPATH.'modules/prx120_jobs',
    'prx115_semantic' => APPPATH.'modules/prx115_semantic',
    'prx212_file_storage' => APPPATH.'modules/prx212_file_storage',
    'prx218_module_manager' => APPPATH.'modules/prx218_module_manager',
    'prx300_employee' => APPPATH.'modules/prx300_employee',
    'prx395_document_extract' => APPPATH.'modules/prx395_document_extract',
    'prx560_knowledge' => APPPATH.'modules/prx560_knowledge',
    'prx595_useradmin' => APPPATH.'modules/prx595_useradmin',
    'prx597_notifications' => APPPATH.'modules/prx597_notifications',
    'prx599_account' => APPPATH.'modules/prx599_account',
    'cookie_consent' => APPPATH.'modules/cookie_consent',
];

// Старый контур доступен только на стенде по явному разрешению.
if (getenv('PRX_DOCFLOW_ENABLED') === '1')
{
    $modules += [
        'prx390_intake' => APPPATH.'modules/prx390_intake',
        'prx391_documents' => APPPATH.'modules/prx391_documents',
        'prx392_analysis' => APPPATH.'modules/prx392_analysis',
        'prx393_workflow' => APPPATH.'modules/prx393_workflow',
    ];
}
return $modules;
