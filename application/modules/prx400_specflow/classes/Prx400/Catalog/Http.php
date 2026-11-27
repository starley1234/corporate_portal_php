<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Внешний справочник НСИ по REST (MDM/1С/SAP-шлюз) — только чтение.
 *
 * Контракт (README модуля, «Внешний НСИ»):
 *   GET {url}/search?q=<текст>&limit=<N> → {"items": [{code, name, unit, okei_code?, keywords?}]}
 *   GET {url}/items/<code>              → {code, name, unit, …} | 404
 * Авторизация — Authorization: Bearer PRX400_CATALOG_TOKEN (если задан).
 *
 * Сбой источника — доменная ошибка catalog_unavailable (503), а не «пустой
 * справочник»: разбор не должен молча пометить все позиции «новыми».
 * Ответы кешируются на время запроса (одна партия строк — один разбор) и в
 * БД (prx_400_catalog_cache): свежий ответ — cache_ttl секунд, при сбое
 * источника — ответ не старше stale_ttl (stale-if-error); сколько раз так
 * выручил кеш, отдаёт take_stale() — разбор помечается catalog_stale.
 */
final class Prx400_Catalog_Http implements Prx400_Catalog_Provider
{
    private const MAX_RESPONSE_BYTES = 2097152;

    /** @var array<string, array> */
    private array $memo = [];
    private string $url;
    private string $token;
    private int $timeout_ms;
    private int $cache_ttl;
    private int $stale_ttl;
    private int $stale = 0;
    /** @var callable|null тестовый транспорт: fn(string $url): array{0: int, 1: string} */
    private $transport;

    public function __construct(array $config, ?callable $transport = NULL)
    {
        $this->url = rtrim((string) ($config['url'] ?? ''), '/');
        $this->token = (string) ($config['token'] ?? '');
        $this->timeout_ms = max(200, (int) ($config['timeout_ms'] ?? 3000));
        $this->cache_ttl = max(0, (int) ($config['cache_ttl'] ?? 0));
        $this->stale_ttl = max(0, (int) ($config['stale_ttl'] ?? 0));
        $this->transport = $transport;
        if ( ! preg_match('~\Ahttps?://[^\s/?#]+~i', $this->url)) throw new Prx400_Error('catalog_config', 503);
    }

    public function name(): string
    {
        return 'http';
    }

    public function writable(): bool
    {
        return FALSE;
    }

    public function count(): ?int
    {
        return NULL;
    }

    public function batch(): callable
    {
        $limit = Service_Prx400_Limits::match()['candidates'];

        return fn (string $query): array => $this->search($query, $limit);
    }

    public function search(string $query, int $limit): array
    {
        $query = mb_substr(trim($query), 0, 500);
        if ($query === '') return [];
        $limit = max(1, min(200, $limit));
        $key = 's:'.$limit.':'.$query;
        if ( ! isset($this->memo[$key]))
        {
            $this->memo[$key] = $this->cached($key, function () use ($query, $limit): array {
                [$status, $body] = $this->get('/search?'.http_build_query(['q' => $query, 'limit' => $limit]));
                if ($status !== 200) throw new Prx400_Error('catalog_unavailable', 503);
                $items = is_array($body['items'] ?? NULL) ? $body['items'] : NULL;
                if ($items === NULL) throw new Prx400_Error('catalog_unavailable', 503);

                return ['items' => array_slice(array_values(array_filter(array_map([self::class, 'item'], $items))), 0, $limit)];
            })['items'];
        }

        return $this->memo[$key];
    }

    public function by_code(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') return NULL;
        $key = 'c:'.$code;
        if ( ! array_key_exists($key, $this->memo))
        {
            $this->memo[$key] = $this->cached($key, function () use ($code): array {
                [$status, $body] = $this->get('/items/'.rawurlencode($code));
                if ($status === 404) return ['item' => NULL];
                if ($status !== 200 OR ! is_array($body)) throw new Prx400_Error('catalog_unavailable', 503);
                $item = self::item($body);

                return ['item' => $item !== NULL && $item['code'] === $code ? $item : NULL];
            })['item'];
        }

        return $this->memo[$key];
    }

    /** Сколько ответов с прошлого вызова пришло из устаревшего кеша (сбой источника); счётчик обнуляется. */
    public function take_stale(): int
    {
        $n = $this->stale;
        $this->stale = 0;

        return $n;
    }

    /**
     * Ответ через кеш БД: свежий — без запроса; иначе запрос, а при сбое
     * источника — ответ не старше stale_ttl. Сбой самого кеша (нет таблицы,
     * БД занята) не мешает работе с источником.
     */
    private function cached(string $key, callable $fetch): array
    {
        if ($this->cache_ttl <= 0 AND $this->stale_ttl <= 0) return $fetch();
        $db_key = hash('sha256', $this->url."\0".$key);
        $hit = NULL;
        try {
            $hit = Model_Prx400_CatalogCache::get($db_key);
        } catch (Throwable $e) {
            Log_Plain::event(Log::WARNING, 'prx400 stage=catalog_cache op=get error_type=:t', [':t' => get_class($e)]);
        }
        if ($hit !== NULL AND $hit['age'] < $this->cache_ttl) return $hit['payload'];

        try {
            $payload = $fetch();
        } catch (Prx400_Error $e) {
            if ($e->getMessage() !== 'catalog_unavailable' OR $hit === NULL OR $hit['age'] >= $this->stale_ttl) throw $e;
            $this->stale++;

            return $hit['payload'];
        }
        if ($this->cache_ttl > 0 OR $this->stale_ttl > 0)
        {
            try {
                Model_Prx400_CatalogCache::put($db_key, $payload);
            } catch (Throwable $e) {
                Log_Plain::event(Log::WARNING, 'prx400 stage=catalog_cache op=put error_type=:t', [':t' => get_class($e)]);
            }
        }

        return $payload;
    }

    /** Позиция внешнего ответа → позиция prx400; без кода/наименования — NULL. */
    public static function item($raw): ?array
    {
        if ( ! is_array($raw)) return NULL;
        $text = static fn ($v, int $max): string => is_string($v) || is_int($v) ? mb_substr(trim((string) $v), 0, $max) : '';
        $code = $text($raw['code'] ?? '', 64);
        $name = $text($raw['name'] ?? '', 4000);
        if ($code === '' OR $name === '') return NULL;

        return [
            'id' => 0, 'code' => $code, 'name' => $name,
            'unit' => $text($raw['unit'] ?? '', 16),
            'okei_code' => $text($raw['okei_code'] ?? '', 8),
            'keywords' => $text($raw['keywords'] ?? '', 500),
        ];
    }

    /** @return array{0: int, 1: mixed} HTTP-статус и разобранный JSON (NULL — не JSON) */
    private function get(string $path): array
    {
        $url = $this->url.$path;
        if ($this->transport !== NULL)
        {
            [$status, $raw] = ($this->transport)($url);
            return [(int) $status, json_decode((string) $raw, TRUE)];
        }

        $headers = ['Accept: application/json'];
        if ($this->token !== '') $headers[] = 'Authorization: Bearer '.$this->token;
        $raw = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => $this->timeout_ms,
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, $this->timeout_ms),
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$raw): int {
                $raw .= $chunk;
                return strlen($raw) > self::MAX_RESPONSE_BYTES ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        if ($ok === FALSE OR $errno !== 0)
        {
            Log_Plain::event(Log::WARNING, 'prx400 stage=catalog_http errno=:e status=:s', [':e' => $errno, ':s' => $status]);
            throw new Prx400_Error('catalog_unavailable', 503);
        }

        return [$status, json_decode($raw, TRUE)];
    }
}
