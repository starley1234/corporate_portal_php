<?php defined('SYSPATH') or die('No direct script access.');

/** Пределы prx400 из config/prx400_specflow.php (единственная точка чтения). */
final class Service_Prx400_Limits
{
    private const CONFIG = 'prx400_specflow';
    private const MULTIPART_OVERHEAD_BYTES = 65536;

    public static function get(string $name): int
    {
        return (int) Kohana::$config->load(self::CONFIG)->get($name);
    }

    /** @return string[] */
    public static function parse_modes(): array
    {
        return (array) Kohana::$config->load(self::CONFIG)->get('parse_modes');
    }

    public static function default_mode(): string
    {
        return (string) Kohana::$config->load(self::CONFIG)->get('parse_mode_default');
    }

    /** @return array{auto_accept: float, manual_review: float, top_k: int, full_scan_max: int, candidates: int} */
    public static function match(): array
    {
        $cfg = (array) Kohana::$config->load(self::CONFIG)->get('match');

        return [
            'auto_accept'   => (float) $cfg['auto_accept'],
            'manual_review' => (float) $cfg['manual_review'],
            'top_k'         => (int) $cfg['top_k'],
            'full_scan_max' => (int) $cfg['full_scan_max'],
            'candidates'    => (int) $cfg['candidates'],
        ];
    }

    /** @return array{provider: string, url: string, token: string, timeout_ms: int} */
    public static function catalog(): array
    {
        return (array) Kohana::$config->load(self::CONFIG)->get('catalog');
    }

    /** @return array{timeout_ms: int, max_attempts: int, allow_http: bool, allow_private: bool} */
    public static function hooks(): array
    {
        return (array) Kohana::$config->load(self::CONFIG)->get('hooks');
    }

    public static function bulk_verify_min(): float
    {
        return (float) Kohana::$config->load(self::CONFIG)->get('bulk_verify_min');
    }

    /** Грубая отсечка тела запроса до разбора multipart (как prx397): все файлы спецификации. */
    public static function assert_request_size(int $bytes): void
    {
        if ($bytes > max(self::get('max_pdf_bytes'), self::get('max_total_bytes')) + self::MULTIPART_OVERHEAD_BYTES)
        {
            throw new Prx400_Error('file_too_large', 413);
        }
    }

    /** То же для формы импорта справочника НСИ. */
    public static function assert_catalog_request_size(int $bytes): void
    {
        if ($bytes > self::get('catalog_import_max_bytes') + self::MULTIPART_OVERHEAD_BYTES)
        {
            throw new Prx400_Error('catalog_file_too_large', 413);
        }
    }

    /** Подпись лимита для форм: «20 МиБ». */
    public static function pdf_label(string $name = 'max_pdf_bytes'): string
    {
        $bytes = self::get($name);

        return $bytes % (1024 ** 2) === 0
            ? number_format($bytes / 1024 ** 2, 0, ',', ' ').' МиБ'
            : number_format($bytes, 0, ',', ' ').' Б';
    }
}
