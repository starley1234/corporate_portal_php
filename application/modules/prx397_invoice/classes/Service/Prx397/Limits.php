<?php defined('SYSPATH') or die('No direct script access.');

final class Service_Prx397_Limits
{
    private const CONFIG = 'prx397_invoice';
    private const MULTIPART_OVERHEAD_BYTES = 65536;

    public static function get(string $name): int
    {
        return (int) Kohana::$config->load(self::CONFIG)->get($name);
    }

    public static function all(): array
    {
        $limits = Kohana::$config->load(self::CONFIG)->as_array();
        $limits['labels'] = [
            'max_pdf' => self::bytes($limits['max_pdf_bytes']),
            'max_pages' => self::quantity($limits['max_pages'], ['страница', 'страницы', 'страниц']),
            'max_llm_chars' => number_format($limits['max_llm_chars'], 0, ',', ' ').' симв.',
            'ttl' => self::quantity($limits['result_ttl_hours'], ['час', 'часа', 'часов']),
        ];
        return $limits;
    }

    public static function assert_request_size(int $bytes): void
    {
        if ($bytes > self::get('max_pdf_bytes') + self::MULTIPART_OVERHEAD_BYTES)
            throw new Prx397_Error('file_too_large', 413);
    }

    private static function bytes(int $bytes): string
    {
        foreach ([1024 ** 2 => 'МиБ', 1024 => 'КиБ'] as $unit => $label)
        {
            if ($bytes % $unit === 0) return number_format($bytes / $unit, 0, ',', ' ').' '.$label;
        }
        return number_format($bytes, 0, ',', ' ').' Б';
    }

    private static function quantity(int $n, array $forms): string
    {
        $last = $n % 10;
        $teen = $n % 100 >= 11 && $n % 100 <= 14;
        return $n.' '.$forms[$teen ? 2 : ($last === 1 ? 0 : ($last >= 2 && $last <= 4 ? 1 : 2))];
    }
}
