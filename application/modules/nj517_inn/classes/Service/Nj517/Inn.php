<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Проверка ИНН и запрос открытых сведений ФНС (NJ-517).
 *
 * Контрольные цифры сверяются единственным алгоритмом платформы
 * Service_Nj594_Orgs::inn_valid(). Проверка в браузере повторяет его локально;
 * в сеть ИНН уходит только после отдельного нажатия «Проверить организацию в ФНС».
 * Для 12-значного ИНН удалённый запрос не выполняется: это идентификатор физлица/ИП.
 */
class Service_Nj517_Inn {

    /** Локальная проверка формата и контрольных цифр; ничего не сохраняет. */
    public static function validate($value)
    {
        if ( ! is_scalar($value))
        {
            return self::_err('bad_format', 'Введите ИНН цифрами.');
        }

        $raw = trim((string) $value);
        $cfg = Kohana::$config->load('nj517_inn');
        $max_length = max(10, (int) $cfg->get('max_input_length', 32));

        if (mb_strlen($raw, 'UTF-8') > $max_length)
        {
            return self::_err('bad_format', 'Слишком длинный ввод. Укажите только ИНН.');
        }

        if ($raw === '')
        {
            return self::_err('empty', 'Введите ИНН.');
        }

        // Разрешаем только цифры, пробелы и дефисы — буквы и прочий текст не
        // удаляем молча, чтобы случайно не признать другой ввод валидным.
        if ( ! preg_match('/^[0-9\s\x{00A0}\x{202F}\x{2010}-\x{2015}-]+$/u', $raw))
        {
            return self::_err('bad_format', 'Оставьте в поле только цифры, пробелы или дефисы.');
        }

        $inn = preg_replace('/[^0-9]/', '', $raw);
        $length = strlen($inn);

        if ( ! in_array($length, [10, 12], TRUE))
        {
            return self::_err('bad_length', 'ИНН должен содержать 10 цифр для организации или 12 цифр для физлица/ИП.');
        }

        if ( ! class_exists('Service_Nj594_Orgs') OR ! Service_Nj594_Orgs::inn_valid($inn))
        {
            return self::_err('bad_checksum', 'Контрольные цифры не сходятся. Проверьте номер по документу.');
        }

        $type = $length === 10 ? 'organization' : 'person';

        return [
            'valid'      => TRUE,
            'inn'        => $inn,
            'digits'     => $length,
            'type'       => $type,
            'type_label' => $length === 10 ? 'Юридическое лицо' : 'Физлицо или ИП',
        ];
    }

    /**
     * Явный онлайн-запрос для 10-значного ИНН организации.
     * Использует лимиты, журнал и кэш ФНС модуля-владельца nj594_orgregistry.
     */
    public static function lookup($value)
    {
        $check = self::validate($value);

        if (isset($check['error']))
        {
            return $check;
        }

        $remote_digits = max(10, (int) Kohana::$config->load('nj517_inn')->get('remote_lookup_digits', 10));

        if ($check['digits'] !== $remote_digits OR $check['type'] !== 'organization')
        {
            return self::_err('personal_inn', 'Онлайн-поиск доступен только для 10-значного ИНН организации. 12-значный номер проверяется только в браузере.');
        }

        if ( ! class_exists('Service_Nj594_Orgs'))
        {
            return self::_err('unavailable', 'Справочник организаций временно недоступен.');
        }

        $result = Service_Nj594_Orgs::refresh($check['inn']);

        if ( ! is_array($result) OR empty($result['card']) OR ! is_array($result['card']))
        {
            if ( ! empty($result['limited']))
            {
                return self::_err('rate_limited', (string) ($result['error'] ?? 'Лимит запросов к ФНС достигнут. Попробуйте позже.'));
            }

            return self::_err('not_confirmed', (string) ($result['error'] ?? 'ФНС не подтвердила организацию. Попробуйте позже или сверьте номер.'));
        }

        $card = Service_Nj594_Orgs::card($result['card']);
        $card_inn = preg_replace('/\D/', '', (string) ($card['inn'] ?? ''));

        // Защита от показа не той карточки при неожиданном ответе источника.
        if ($card_inn !== $check['inn'])
        {
            return self::_err('mismatch', 'ФНС вернула карточку с другим ИНН; результат не показан.');
        }

        return ['check' => $check, 'card' => $card];
    }

    protected static function _err($code, $message)
    {
        return ['error' => $code, 'message' => $message];
    }
}
