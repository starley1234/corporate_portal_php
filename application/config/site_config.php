<?php defined('SYSPATH') or die('No direct script access.');
$url = rtrim((string) (getenv('APP_URL') ?: 'http://localhost:8080'), '/');
return [
    'title' => 'PRL — корпоративный портал',
    'description' => 'Единое рабочее пространство сотрудников: сервисы, знания и коммуникации.',
    'h1' => 'Корпоративный портал',
    'keywords' => 'корпоративный портал, сотрудники, знания, PRL',
    'pagedescription' => 'Сервисы холдинга в едином защищённом пространстве.',
    'site_url' => $url,
    'admin_mail' => getenv('ADMIN_EMAIL') ?: '',
    'domain' => parse_url($url, PHP_URL_HOST),
    'site_name' => 'PRL',
    'site_logo' => '<i class="fas fa-layer-group mr-2"></i>',
];
