<?php defined('SYSPATH') or die('No direct script access.');

class Service_Prx395_Clients
{
    public static function authenticate(string $header): ?string
    {
        $raw = trim((string) getenv('PRX395_DOCUMENT_EXTRACT_CLIENTS')) ?: '{}';
        $clients = json_decode($raw);
        if ( ! $clients instanceof stdClass)
        {
            throw new Prx395_Error('integration_not_configured', 503);
        }
        $seen = [];
        $matched = NULL;
        $valid_header = preg_match('/\ABearer ([A-Za-z0-9_-]{32,128})\z/i', $header, $parts);
        foreach ($clients as $id => $token)
        {
            if ( ! preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $id)
                || ! is_string($token) || ! preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $token)
                || isset($seen[$token]) || $token === (string) getenv('WORKER_API_TOKEN'))
            {
                throw new Prx395_Error('integration_not_configured', 503);
            }
            $seen[$token] = TRUE;
            if ($valid_header && hash_equals($token, $parts[1])) $matched = $id;
        }
        return $matched;
    }
}
