<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Webhook API-ключа prx400: подписка, outbox и доставка.
 *
 *   PUT    /api/v1/spec-extractions/webhook {url, events?} → {url, events, secret}
 *   GET    /api/v1/spec-extractions/webhook               → {url, events, deliveries}
 *   DELETE /api/v1/spec-extractions/webhook
 *
 * Тело POST на адрес клиента — только ссылки, без данных спецификации:
 *   {"id": <delivery>, "event": "spec.done", "spec_id": 42, "status": "done",
 *    "status_url": "/api/v1/spec-extractions/42", "occurred_at": "…"}
 * Заголовки: X-PRX-Event, X-PRX-Delivery, X-PRX-Signature: t=<unix>,v1=<hex>
 * где v1 = HMAC-SHA256(secret, "<t>.<тело>"). 2xx — доставлено; иначе повтор
 * с отсрочкой (1, 5, 15, 60 мин, 3, 6, 12, 24 ч) до hooks.max_attempts.
 *
 * Адрес: https (http — PRX400_HOOK_ALLOW_HTTP=1), публичный IP (частные и
 * служебные сети — PRX400_HOOK_ALLOW_PRIVATE=1); IP фиксируется при
 * отправке (CURLOPT_RESOLVE), редиректы не выполняются.
 */
final class Service_Prx400_Hooks
{
    public const EVENTS = ['spec.review', 'spec.done', 'spec.failed'];
    private const BACKOFF_SEC = [60, 300, 900, 3600, 10800, 21600, 43200, 86400];

    /** @var callable|null тестовый транспорт: fn(string $url, string $ip, array $headers, string $body): int */
    private static $transport = NULL;

    public static function use_transport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    public static function subscribe(Prx_Principal $principal, string $url, $events = NULL): array
    {
        $owner = self::owner($principal);
        $url = trim($url);
        self::check_url($url);
        $events = self::events($events);
        $secret = bin2hex(random_bytes(24));
        Model_Prx400_Hook::save($owner, $url, $secret, implode(',', $events));

        return ['url' => $url, 'events' => $events, 'secret' => $secret];
    }

    public static function describe(Prx_Principal $principal): array
    {
        $owner = self::owner($principal);
        $hook = Model_Prx400_Hook::get($owner);

        return [
            'url' => $hook['url'] ?? NULL,
            'events' => $hook !== NULL ? explode(',', (string) $hook['events']) : [],
            'deliveries' => array_map(static fn (array $d): array => [
                'id' => (int) $d['id'], 'spec_id' => (int) $d['spec_id'], 'event' => (string) $d['event'],
                'status' => (string) $d['status'], 'attempts' => (int) $d['attempts'],
                'last_error' => $d['last_error'], 'created_at' => (string) $d['created_at'],
            ], Model_Prx400_Hook::recent($owner)),
        ];
    }

    public static function unsubscribe(Prx_Principal $principal): void
    {
        Model_Prx400_Hook::delete(self::owner($principal));
    }

    /**
     * Поставить событие в outbox (внутри транзакции смены статуса). Только
     * записи API-ключей с подпиской на это событие.
     */
    public static function enqueue(int $owner_id, int $spec_id, string $status): void
    {
        if ($owner_id <= 0) return;
        $event = 'spec.'.$status;
        if ( ! in_array($event, self::EVENTS, TRUE)) return;
        $hook = Model_Prx400_Hook::get($owner_id);
        if ($hook === NULL OR ! in_array($event, explode(',', (string) $hook['events']), TRUE)) return;
        Model_Prx400_Hook::enqueue($owner_id, $spec_id, $event, [
            'event' => $event, 'spec_id' => $spec_id, 'status' => $status,
            'status_url' => '/api/v1/spec-extractions/'.$spec_id, 'occurred_at' => date('c'),
        ]);
    }

    /** Доставка пачки (prx400:hooks). @return array{sent: int, retry: int, dead: int} */
    public static function deliver_due(int $limit = 50): array
    {
        $report = ['sent' => 0, 'retry' => 0, 'dead' => 0];
        $max = Service_Prx400_Limits::hooks()['max_attempts'];
        foreach (Model_Prx400_Hook::claim_due($limit) as $d)
        {
            $hook = Model_Prx400_Hook::get((int) $d['owner_id']);
            $error = $hook === NULL ? 'unsubscribed' : self::send($hook, $d);
            if ($error === NULL)
            {
                Model_Prx400_Hook::mark_sent((int) $d['id']);
                $report['sent']++;
                continue;
            }
            $attempts = (int) $d['attempts'];
            $retry = $hook !== NULL && $attempts < $max ? self::BACKOFF_SEC[min($attempts - 1, count(self::BACKOFF_SEC) - 1)] : NULL;
            Model_Prx400_Hook::mark_failed((int) $d['id'], $error, $retry);
            $report[$retry === NULL ? 'dead' : 'retry']++;
            Log_Plain::event(Log::WARNING, 'prx400 stage=hook delivery=:d owner=:o error=:e attempt=:a', [
                ':d' => (int) $d['id'], ':o' => (int) $d['owner_id'], ':e' => $error, ':a' => $attempts,
            ]);
        }

        return $report;
    }

    /** Подпись тела: t=<unix>,v1=HMAC-SHA256(secret, "<t>.<body>"). */
    public static function signature(string $secret, string $body, int $time): string
    {
        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret);
    }

    /**
     * Адрес подписки допустим статически: схема, длина, без учётных данных;
     * IP-литерал — публичный. DNS проверяется при каждой отправке (resolve()).
     */
    public static function check_url(string $url): void
    {
        $cfg = Service_Prx400_Limits::hooks();
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (strlen($url) > 500 OR $host === '' OR isset($parts['user']) OR isset($parts['pass'])
            OR ! ($scheme === 'https' OR ($scheme === 'http' AND ! empty($cfg['allow_http']))))
        {
            throw new Prx400_Error('webhook_url_invalid', 400);
        }
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) AND ! self::ip_allowed($literal)) throw new Prx400_Error('webhook_url_invalid', 400);
    }

    public static function ip_allowed(string $ip): bool
    {
        if ( ! empty(Service_Prx400_Limits::hooks()['allow_private'])) return filter_var($ip, FILTER_VALIDATE_IP) !== FALSE;

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== FALSE;
    }

    /** @return string|null код ошибки доставки; NULL — доставлено */
    private static function send(array $hook, array $d): ?string
    {
        $url = (string) $hook['url'];
        try {
            self::check_url($url);
        } catch (Prx400_Error $e) {
            return 'url_rejected';
        }
        $ip = self::resolve((string) parse_url($url, PHP_URL_HOST));
        if ($ip === NULL) return 'dns_rejected';

        $payload = is_array($d['payload']) ? $d['payload'] : (array) json_decode((string) $d['payload'], TRUE);
        $body = json_encode(['id' => (int) $d['id']] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type: application/json',
            'User-Agent: prx400-webhook/1',
            'X-PRX-Event: '.$d['event'],
            'X-PRX-Delivery: '.(int) $d['id'],
            'X-PRX-Signature: '.self::signature((string) $hook['secret'], $body, time()),
        ];
        $status = self::$transport !== NULL ? (int) (self::$transport)($url, $ip, $headers, $body) : self::post($url, $ip, $headers, $body);

        return $status >= 200 && $status < 300 ? NULL : ($status > 0 ? 'http_'.$status : 'network');
    }

    /** IP хоста (IPv4) — только допустимый; IP-литерал — как есть. */
    private static function resolve(string $host): ?string
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) return self::ip_allowed($host) ? $host : NULL;
        $ips = gethostbynamel($host) ?: [];
        foreach ($ips as $ip)
        {
            if ( ! self::ip_allowed($ip)) return NULL; // хотя бы один частный адрес — отказ (DNS rebinding)
        }

        return $ips[0] ?? NULL;
    }

    private static function post(string $url, string $ip, array $headers, string $body): int
    {
        $parts = parse_url($url);
        $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));
        $timeout = Service_Prx400_Limits::hooks()['timeout_ms'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => TRUE,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RESOLVE => [trim((string) $parts['host'], '[]').':'.$port.':'.$ip],
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeout),
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_MAXFILESIZE => 65536,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return $ok === FALSE ? 0 : $status;
    }

    private static function owner(Prx_Principal $principal): int
    {
        $owner = Policy_Prx400_Spec::ownerId($principal);
        if ($owner <= 0) throw new Prx400_Error('forbidden', 403); // подписка — у API-ключа

        return $owner;
    }

    /** @return string[] */
    private static function events($raw): array
    {
        if ($raw === NULL OR $raw === '' OR $raw === []) return self::EVENTS;
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        $events = array_values(array_unique(array_map(static fn ($e): string => trim((string) $e), $list)));
        if ($events === [] OR array_diff($events, self::EVENTS)) throw new Prx400_Error('webhook_events_invalid', 400);

        return $events;
    }
}
