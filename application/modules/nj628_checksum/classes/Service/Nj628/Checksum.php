<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Контрольные цифры (модуль NJ-628) — проверка и расчёт контрольных сумм.
 *
 * Поддерживает алгоритм Луна (банковские карты и IMEI), ИНН (10/12),
 * ОГРН/ОГРНИП, СНИЛС, штрихкоды UPC-A/EAN-13 и CRC32 строки. Используется для
 * проверки реквизитов и чисел перед отправкой в боевые системы.
 */
class Service_Nj628_Checksum {

    /** Допустимые алгоритмы для UI/API. */
    public static function algorithms(): array
    {
        return [
            'luhn'   => ['Луна (банковская карта, IMEI)', '8–19 цифр'],
            'inn'    => ['ИНН (10 или 12 знаков)', 'реквизит юрлица/физлица'],
            'ogrn'   => ['ОГРН / ОГРНИП (13 или 15)', 'реквизит организации'],
            'snils'  => ['СНИЛС (XXX-XXX-XXX YY)', 'номер пенсионного счёта'],
            'ean'    => ['Штрихкод UPC-A / EAN-13', '11–12 / 12–13 цифр'],
            'crc32'  => ['CRC32 строки', 'произвольный текст'],
        ];
    }

    /** Проверка значения по алгоритму. */
    public static function check(string $algorithm, string $value): ?array
    {
        switch ($algorithm)
        {
            case 'luhn':  return self::luhn($value);
            case 'inn':   return self::inn($value);
            case 'ogrn':  return self::ogrn($value);
            case 'snils': return self::snils($value);
            case 'ean':   return self::ean($value);
            case 'crc32': return self::crc32($value);
            default: return NULL;
        }
    }

    /** Алгоритм Луна: javaScript-совместимый результат c валидностью. */
    public static function luhn(string $value): array
    {
        $value = preg_replace('/[\s-]/', '', $value);
        $valid = (bool) preg_match('/^[0-9]{8,19}$/', $value);

        if ($valid)
        {
            $sum = 0;
            $alt = false;

            for ($i = strlen($value) - 1; $i >= 0; $i--)
            {
                $d = (int) $value[$i];

                if ($alt)
                {
                    $d *= 2;
                    if ($d > 9)
                    {
                        $d -= 9;
                    }
                }

                $sum += $d;
                $alt = ! $alt;
            }

            $valid = ($sum % 10 === 0);
        }

        return ['valid' => $valid, 'note' => $valid ? 'Контрольная цифра сходится — номер выглядит корректным.' : 'Номер не прошёл алгоритм Луна: проверьте цифры.'];
    }

    /** ИНН 10/12 знаков. */
    public static function inn(string $value): array
    {
        $inn = preg_replace('/\D/', '', $value);

        $ok = false;
        if (strlen($inn) === 10)
        {
            $ok = self::_inn_check(substr($inn, 0, 9), [2, 4, 10, 3, 5, 9, 4, 6, 8]) === (int) $inn[9];
        }
        elseif (strlen($inn) === 12)
        {
            $ok = self::_inn_check(substr($inn, 0, 10), [7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === (int) $inn[10]
                && self::_inn_check(substr($inn, 0, 11), [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === (int) $inn[11];
        }

        $note = ! preg_match('/^\d{10}$|^\d{12}$/', $inn)
            ? 'ИНН — 10 (юрлицо) или 12 (физлицо/ИП) цифр.'
            : ($ok ? 'Контрольные цифры ИНН сходятся.' : 'Контрольная цифра ИНН не сходится: похоже на опечатку.');

        return ['valid' => $ok, 'note' => $note, 'clean' => $inn];
    }

    protected static function _inn_check(string $digits, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $i => $w)
        {
            $sum += (int) $digits[$i] * $w;
        }

        return $sum % 11 % 10;
    }

    /** ОГРН (13) / ОГРНИП (15). */
    public static function ogrn(string $value): array
    {
        $ogrn = preg_replace('/\D/', '', $value);
        $ok   = false;

        if (strlen($ogrn) === 13)
        {
            $ok = ((int) substr($ogrn, 0, 12) % 11 % 10) === (int) $ogrn[12];
        }
        elseif (strlen($ogrn) === 15)
        {
            $ok = ((int) substr($ogrn, 0, 14) % 13 % 10) === (int) $ogrn[14];
        }

        $note = ! preg_match('/^\d{13}$|^\d{15}$/', $ogrn)
            ? 'ОГРН — 13 цифр, ОГРНИП — 15 цифр.'
            : ($ok ? 'Контрольная цифра сходится.' : 'Контрольная цифра не сходится.');

        return ['valid' => $ok, 'note' => $note, 'clean' => $ogrn];
    }

    /** СНИЛС. */
    public static function snils(string $value): array
    {
        $d = preg_replace('/\D/', '', $value);
        $ok = false;

        if (strlen($d) === 11)
        {
            $sum = 0;

            for ($i = 0; $i < 9; $i++)
            {
                $sum += (int) $d[$i] * (9 - $i);
            }

            $cd = $sum < 100 ? $sum : ($sum === 100 ? 0 : $sum % 101);
            $ok = $cd === (int) substr($d, 9, 2);
        }

        $note = $ok ? 'Контрольное число СНИЛС сходится.' : 'СНИЛС выглядит неверно: проверьте число (XXX-XXX-XXX YY).';

        return ['valid' => $ok, 'note' => $note, 'clean' => substr($d, 0, 3).'-'.substr($d, 3, 3).'-'.substr($d, 6, 3).' '.substr($d, 9)];
    }

    /** Штрихкод UPC-A (12) / EAN-13 (13). */
    public static function ean(string $value): array
    {
        $ean = preg_replace('/\D/', '', $value);
        $ok  = false;
        $kind = '';

        if (strlen($ean) === 12) // UPC-A
        {
            $kind = 'UPC-A (12 цифр)';
            $ok   = self::_ean_check($ean, 12);
        }
        elseif (strlen($ean) === 13) // EAN-13
        {
            $kind = 'EAN-13 (13 цифр)';
            $ok   = self::_ean_check($ean, 13);
        }

        $note = $kind === ''
            ? 'Штрихкод — 12 (UPC-A) или 13 (EAN-13) цифр.'
            : ($ok ? 'Контрольная цифра штрихкода сходится.' : 'Неверная контрольная цифра штрихкода.');

        return ['valid' => $ok, 'note' => $note, 'kind' => $kind];
    }

    protected static function _ean_check(string $ean, int $len): bool
    {
        $check = (int) $ean[$len - 1];
        $sum   = 0;

        for ($i = 0; $i < $len - 1; $i++)
        {
            $d = (int) $ean[$i];
            // EAN/UPC: умножение на 3 для нечётных позиций (слева, 1-based)
            $sum += ($i % 2 === 0) ? $d : $d * 3;
        }

        return (10 - ($sum % 10)) % 10 === $check;
    }

    /** CRC32 строки. */
    public static function crc32(string $value): array
    {
        $hash = sprintf('%08x', crc32($value));
        $num  = (int) hexdec($hash);

        return ['valid' => true, 'crc' => strtoupper($hash), 'dec' => (string) $num, 'note' => 'CRC32 считается без секрета — только для контроля целостности, не для паролей.'];
    }
}
