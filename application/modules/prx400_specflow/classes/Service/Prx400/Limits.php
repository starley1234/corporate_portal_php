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

    /** Грубая отсечка тела запроса до разбора multipart (как prx397). */
    public static function assert_request_size(int $bytes): void
    {
        if ($bytes > self::get('max_pdf_bytes') + self::MULTIPART_OVERHEAD_BYTES)
        {
            throw new Prx400_Error('file_too_large', 413);
        }
    }

    /** Подпись лимита для форм: «20 МиБ». */
    public static function pdf_label(): string
    {
        $bytes = self::get('max_pdf_bytes');

        return $bytes % (1024 ** 2) === 0
            ? number_format($bytes / 1024 ** 2, 0, ',', ' ').' МиБ'
            : number_format($bytes, 0, ',', ' ').' Б';
    }
}
