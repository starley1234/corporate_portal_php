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
    'prx102_core' => APPPATH.'modules/prx102_core',
    'prx111_migrations' => APPPATH.'modules/prx111_migrations',
    'prx103_platform' => APPPATH.'modules/prx103_platform',
    'prx100_search' => APPPATH.'modules/prx100_search',
    // Документация архитектуры: YAML -> страница и JSON (make architecture).
    'prx101_architecture' => APPPATH.'modules/prx101_architecture',
    'prx110_catalog' => APPPATH.'modules/prx110_catalog',
    'prx112_portal_plan' => APPPATH.'modules/prx112_portal_plan',
    'prx208_events' => APPPATH.'modules/prx208_events',
    'prx120_jobs' => APPPATH.'modules/prx120_jobs',
    'prx150_api_hub' => APPPATH.'modules/prx150_api_hub',
    'prx115_semantic' => APPPATH.'modules/prx115_semantic',
    'prx212_file_storage' => APPPATH.'modules/prx212_file_storage',
    'prx218_module_manager' => APPPATH.'modules/prx218_module_manager',
    'prx300_employee' => APPPATH.'modules/prx300_employee',
    'prx395_document_extract' => APPPATH.'modules/prx395_document_extract',
    'prx396_pdf_chunks' => APPPATH.'modules/prx396_pdf_chunks',
    'prx397_invoice' => APPPATH.'modules/prx397_invoice',
    'prx398_doc_router' => APPPATH.'modules/prx398_doc_router',
    'prx399_docmerge' => APPPATH.'modules/prx399_docmerge',
    'prx450_document_ocr' => APPPATH.'modules/prx450_document_ocr',
    'prx560_knowledge' => APPPATH.'modules/prx560_knowledge',
    'prx595_useradmin' => APPPATH.'modules/prx595_useradmin',
    'prx597_notifications' => APPPATH.'modules/prx597_notifications',
    'prx599_account' => APPPATH.'modules/prx599_account',
    'cookie_consent' => APPPATH.'modules/cookie_consent',
];

// LDAP — необязательный драйвер; одновременно выбирается в config/auth.php.
if (getenv('PRX_LDAP_ENABLED') === '1')
{
    $modules['ldap_auth'] = APPPATH.'modules/ldap_auth';
}

return $modules;
