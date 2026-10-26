<?php defined('SYSPATH') or die('No direct script access.');

/**
 * IP-калькулятор подсетей (модуль NJ-630) — IPv4/CIDR.
 *
 * По адресу в формате a.b.c.d/префикс считает маску, дикий знак (wildcard),
 * адрес сети, broadcast, первый/последний хост и число адресов. Всё —
 * целочисленная арифметика, без внешних сервисов; диапазон IPv6 не входит.
 */
class Service_Nj630_Ipcalc {

    /** Разбор a.b.c.d[/n] → [int, prefix] или NULL. */
    public static function parse(string $value): ?array
    {
        $value = trim($value);

        if ( ! preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\/(\d{1,2}))?$/', $value, $m))
        {
            return NULL;
        }

        $octets = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];

        foreach ($octets as $o)
        {
            if ($o < 0 OR $o > 255)
            {
                return NULL;
            }
        }

        $prefix = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 24;

        if ($prefix < 0 OR $prefix > 32)
        {
            return NULL;
        }

        $ip = ($octets[0] << 24) | ($octets[1] << 16) | ($octets[2] << 8) | $octets[3];

        return [$ip, $prefix, $octets];
    }

    /** Полный отчёт по IP/prefix. */
    public static function report(string $value): ?array
    {
        $parsed = self::parse($value);

        if ($parsed === NULL)
        {
            return NULL;
        }

        [$ip, $prefix, $octets] = $parsed;

        $mask     = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $wildcard = (~$mask) & 0xFFFFFFFF;
        $network  = $ip & $mask;
        $broadcast = $network | $wildcard;

        $total = $prefix >= 31 ? ($prefix === 32 ? 1 : 2) : (int) (2 ** (32 - $prefix));
        $first = $prefix >= 31 ? $network : $network + 1;
        $last  = $prefix >= 31 ? $broadcast : $broadcast - 1;

        $class = self::_class($octets[0]);
        $is_private = self::_private($network, $mask);
        $is_loopback = $octets[0] === 127;

        return [
            'ip'          => implode('.', $octets),
            'prefix'      => $prefix,
            'mask'        => self::_ip($mask),
            'wildcard'    => self::_ip($wildcard),
            'network'     => self::_ip($network),
            'broadcast'   => self::_ip($broadcast),
            'first_host'  => self::_ip($first),
            'last_host'   => self::_ip($last),
            'total'       => $total,
            'hosts'       => max(0, $total - 2),
            'class'       => $class,
            'is_private'  => $is_private,
            'is_loopback' => $is_loopback,
        ];
    }

    /** Проверка: попадает ли адрес в подсеть. */
    public static function in_subnet(string $ip_value, string $cidr): bool
    {
        $ip = self::parse($ip_value);
        $net = self::parse($cidr);

        if ($ip === NULL OR $net === NULL)
        {
            return false;
        }

        $mask = $net[1] === 0 ? 0 : (0xFFFFFFFF << (32 - $net[1])) & 0xFFFFFFFF;

        return ($ip[0] & $mask) === ($net[0] & $mask);
    }

    protected static function _ip(int $n): string
    {
        return (($n >> 24) & 0xFF).'.'.(($n >> 16) & 0xFF).'.'.(($n >> 8) & 0xFF).'.'.($n & 0xFF);
    }

    protected static function _class(int $first): string
    {
        if ($first < 128)      { return 'A'; }
        if ($first < 192)      { return 'B'; }
        if ($first < 224)      { return 'C'; }
        if ($first < 240)      { return 'D (multicast)'; }

        return 'E (reserved)';
    }

    protected static function _private(int $network, int $mask): bool
    {
        $checks = [
            ['10.0.0.0', 8],
            ['172.16.0.0', 12],
            ['192.168.0.0', 16],
        ];

        foreach ($checks as [$base, $p])
        {
            $b = self::parse($base);
            if ($b !== NULL)
            {
                $m = (0xFFFFFFFF << (32 - $p)) & 0xFFFFFFFF;
                if (($network & $m) === ($b[0] & $m))
                {
                    return true;
                }
            }
        }

        return false;
    }
}
