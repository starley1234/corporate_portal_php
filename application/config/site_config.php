<?php defined('SYSPATH') or die('No direct script access.');
$url = rtrim((string) (getenv('APP_URL') ?: 'http://localhost:8080'), '/');
return [
    'title' => 'PRX — платформа ИИ-сервисов',
    'description' => 'Модульная платформа обработки документов и других ИИ-задач для корпоративных систем.',
    'h1' => 'Платформа ИИ-сервисов',
    'keywords' => 'ИИ-сервисы, обработка документов, интеграции, PRX',
    'pagedescription' => 'ИИ-пайплайны для корпоративных систем.',
    'site_url' => $url,
    'admin_mail' => getenv('ADMIN_EMAIL') ?: '',
    'domain' => parse_url($url, PHP_URL_HOST),
    'site_name' => 'PRX',
    'site_logo' => '<i class="fas fa-layer-group mr-2"></i>',
    // Бренд-акцент общего шаблона: orange, blue или yellow (дизайн-система v6).
    // Не меняет семантические цвета действий и статусов.
    'company_accent' => 'orange',
];
