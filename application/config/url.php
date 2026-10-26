<?php defined('SYSPATH') or die('No direct access allowed.');
$host = parse_url((string) (getenv('APP_URL') ?: 'http://localhost:8080'), PHP_URL_HOST);
$hosts = $host ? [preg_quote($host, '~').'(:\d+)?'] : [];
// Только явно заданные внутренние DNS-имена; не regex и не wildcard.
foreach (explode(',', (string) getenv('APP_INTERNAL_HOSTS')) as $internal)
{
    $internal = trim($internal);
    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', $internal))
    {
        $hosts[] = preg_quote($internal, '~').'(:\d+)?';
    }
}
if (Kohana::$environment === Kohana::DEVELOPMENT)
{
    $hosts = array_merge($hosts, ['localhost(:\d+)?', '127\.0\.0\.1(:\d+)?', '.*\.e2b\.app(:\d+)?']);
}
return ['trusted_hosts' => $hosts];
