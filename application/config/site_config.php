<?php defined('SYSPATH') or die('No direct script access.');
$url = rtrim((string) (getenv('APP_URL') ?: 'http://localhost:8080'), '/');
$site_name = trim((string) (getenv('PRX_SITE_NAME') ?: ''));
$site_title = trim((string) (getenv('PRX_SITE_TITLE') ?: ''));

return [
    'title' => $site_title !== '' ? $site_title : 'PRX — платформа ИИ-сервисов',
    'description' => 'Модульная платформа обработки документов и других ИИ-задач для корпоративных систем.',
    'h1' => 'Платформа ИИ-сервисов',
    'keywords' => 'ИИ-сервисы, обработка документов, интеграции, PRX',
    'pagedescription' => 'ИИ-пайплайны для корпоративных систем.',
    'site_url' => $url,
    'admin_mail' => getenv('ADMIN_EMAIL') ?: '',
    'domain' => parse_url($url, PHP_URL_HOST),
    // Бренд из .env (см. .env.example): имя, логотип (HTML/иконка — файл
    // .env редактирует администратор, поэтому разметка там разрешена),
    // палитра акцента и свой цвет акцента поверх палитры.
    'site_name' => $site_name !== '' ? $site_name : 'PRX',
    'site_logo' => getenv('PRX_SITE_LOGO') ?: '<i class="fas fa-layer-group mr-2"></i>',
    // Бренд-акцент общего шаблона: orange, blue или yellow (дизайн-система v6).
    // Не меняет семантические цвета действий и статусов.
    'company_accent' => trim((string) (getenv('PRX_COMPANY_ACCENT') ?: 'orange')),
    // Свой акцент (hex #RGB/#RRGGBB) перекрывает палитру: светлый и,
    // опционально, отдельный для тёмной темы.
    'accent_color' => trim((string) (getenv('PRX_ACCENT_COLOR') ?: '')),
    'accent_color_dark' => trim((string) (getenv('PRX_ACCENT_COLOR_DARK') ?: '')),
];
