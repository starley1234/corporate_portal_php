<?php defined('SYSPATH') or die('No direct script access.');

// Порядок задаёт каскадную ФС и порядок миграций. События — до очереди.
return [
    'encrypt' => MODPATH.'encrypt',
    'auth' => MODPATH.'auth',
    'cache' => MODPATH.'cache',
    'minion' => MODPATH.'minion',
    'database' => MODPATH.'database',
    'orm' => MODPATH.'orm',
    'pagination' => MODPATH.'pagination',
    'PostgreSQL' => APPPATH.'modules/PostgreSQL',
    'llm' => APPPATH.'modules/llm',
    'prl100_base' => APPPATH.'modules/prl100_base',
    'prl111_migrations' => APPPATH.'modules/prl111_migrations',
    'prl100_platform' => APPPATH.'modules/prl100_platform',
    'prl100_search' => APPPATH.'modules/prl100_search',
    'prl110_catalog' => APPPATH.'modules/prl110_catalog',
    'prl140_design_lab' => APPPATH.'modules/prl140_design_lab',
    'prl208_events' => APPPATH.'modules/prl208_events',
    'prl120_jobs' => APPPATH.'modules/prl120_jobs',
    'prl115_semantic' => APPPATH.'modules/prl115_semantic',
    'prl212_file_storage' => APPPATH.'modules/prl212_file_storage',
    'prl218_module_manager' => APPPATH.'modules/prl218_module_manager',
    'prl300_employee' => APPPATH.'modules/prl300_employee',
    // Документооборот prl390–prl394: приём → реестр → ИИ-разбор → сроки → ответы.
    // Порядок важен: prl390 создаёт конверт, prl391 — карточку, остальные
    // подписываются на события предыдущих.
    'prl390_intake' => APPPATH.'modules/prl390_intake',
    'prl391_documents' => APPPATH.'modules/prl391_documents',
    'prl392_analysis' => APPPATH.'modules/prl392_analysis',
    'prl393_workflow' => APPPATH.'modules/prl393_workflow',
    'prl394_drafts' => APPPATH.'modules/prl394_drafts',
    'prl560_knowledge' => APPPATH.'modules/prl560_knowledge',
    'prl595_useradmin' => APPPATH.'modules/prl595_useradmin',
    'prl597_notifications' => APPPATH.'modules/prl597_notifications',
    'prl599_account' => APPPATH.'modules/prl599_account',
    'cookie_consent' => APPPATH.'modules/cookie_consent',
];
