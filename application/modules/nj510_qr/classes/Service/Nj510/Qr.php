<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Контракт stateless-модуля nj510_qr: типы, ограничения и безопасные стили.
 * Само кодирование — в браузере, чтобы пароли Wi-Fi и контакты не шли в HTTP.
 */
class Service_Nj510_Qr {

    /** Один источник настроек для страницы, JS и проверок. */
    public static function settings(): array
    {
        return [
            'limits' => [
                'batch'         => 200,
                'id_mask_chars' => 64,
                'payload_bytes' => 2000,
                'import_bytes'  => 1048576,
                'logo_bytes'    => 2097152,
                'logo_side'     => 4096,
                'archive_bytes' => 67108864,
            ],
            'sizes'   => [256, 512, 1024, 2048],
            'formats' => ['png' => 'PNG — для экрана', 'svg' => 'SVG — для печати', 'jpeg' => 'JPEG — для фото'],
            'levels'  => ['M' => 'M — обычная', 'Q' => 'Q — повышенная', 'H' => 'H — максимальная'],
            'shapes'  => ['square' => 'Квадраты', 'rounded' => 'Скруглённые', 'dots' => 'Точки'],
            'eyes'    => ['square' => 'Квадратные', 'rounded' => 'Скруглённые'],
            'types'   => [
                'url'   => 'Ссылка',
                'text'  => 'Текст',
                'wifi'  => 'Wi-Fi',
                'vcard' => 'Визитка (контакт)',
                'email' => 'Электронное письмо',
                'phone' => 'Телефон',
                'sms'   => 'SMS-сообщение',
            ],
            'defaults' => [
                'foreground' => '#0f172a',
                'background' => '#ffffff',
                'accent'     => '#2563eb',
                'gradient'   => FALSE,
                'shape'      => 'square',
                'eye'        => 'square',
                'size'       => 512,
                'margin'     => 4,
                'level'      => 'M',
                'format'     => 'png',
                'logo_size'  => 16,
            ],
            'presets' => [
                'classic' => ['title' => 'Классика', 'foreground' => '#0f172a', 'accent' => '#0f172a', 'shape' => 'square',  'eye' => 'square',  'gradient' => FALSE],
                'ocean'   => ['title' => 'Океан',    'foreground' => '#172554', 'accent' => '#2563eb', 'shape' => 'rounded', 'eye' => 'rounded', 'gradient' => TRUE],
                'mint'    => ['title' => 'Мята',     'foreground' => '#064e3b', 'accent' => '#047857', 'shape' => 'dots',    'eye' => 'rounded', 'gradient' => TRUE],
                'violet'  => ['title' => 'Фиолет',   'foreground' => '#4c1d95', 'accent' => '#7c3aed', 'shape' => 'rounded', 'eye' => 'rounded', 'gradient' => TRUE],
            ],
        ];
    }

    /** В URL допустимы только переключатели интерфейса, не содержимое QR. */
    public static function initial_state($mode, $type): array
    {
        $settings = self::settings();

        return [
            'mode' => $mode === 'batch' ? 'batch' : 'single',
            'type' => is_string($type) && isset($settings['types'][$type]) ? $type : 'url',
        ];
    }
}
